<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for datafield_gradeentry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    {@link https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later}
 */

/**
 * Run the datafield_gradeentry upgrade steps.
 *
 * @param  int $oldversion  The version we are upgrading from.
 * @return bool
 */
function xmldb_datafield_gradeentry_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025120810) {
        // 1. Ensure the new table exists (install.xml covers fresh installs;
        // this branch creates it for sites upgrading from a version that
        // didn't have it).
        $table = new xmldb_table('datafield_gradeentry_grades');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(
                __DIR__ . '/install.xml',
                'datafield_gradeentry_grades'
            );
        }

        // 2. Migrate rows from the old local_datagrading_grades table if
        // it's still installed alongside us. Idempotent: skips any row
        // whose (dataid, recordid) is already present in the new table.
        // This matters because the previous 2025120810 upgrade aborted
        // after this INSERT but before the savepoint, leaving rows
        // copied but $oldversion still below 2025120810, so the same
        // step runs again on the retry.
        $oldtable = new xmldb_table('local_datagrading_grades');
        if ($dbman->table_exists($oldtable)) {
            $DB->execute(
                'INSERT INTO {datafield_gradeentry_grades}
                    (dataid, recordid, userid, graderid, feedback, feedbackformat,
                     released, timecreated, timemodified)
                 SELECT old.dataid, old.recordid, old.userid, old.graderid,
                        old.feedback, old.feedbackformat,
                        old.released, old.timecreated, old.timemodified
                   FROM {local_datagrading_grades} old
                  WHERE NOT EXISTS (
                      SELECT 1
                        FROM {datafield_gradeentry_grades} new
                       WHERE new.dataid = old.dataid
                         AND new.recordid = old.recordid
                  )'
            );
        }

        // 3. Migrate role overrides on the old capabilities to the new ones
        // so existing teacher-grader assignments are preserved across the
        // rename. Bind the capability strings as parameters so the colons
        // in 'local/datagrading:grade' etc. aren't parsed as :named
        // placeholders by fix_sql_params().
        $DB->execute(
            "UPDATE {role_capabilities}
                SET capability = REPLACE(capability, ?, ?)
              WHERE capability IN (?, ?)",
            [
                'local/datagrading:',
                'datafield/gradeentry:',
                'local/datagrading:grade',
                'local/datagrading:viewgrades',
            ]
        );

        // Note: existing gradebook items don't need migrating. They were
        // created by grade_update() with itemtype='mod', itemmodule='data',
        // iteminstance=$data->id - the parent Database activity - and we
        // still call grade_update() with the same triple. The first arg to
        // grade_update() (was 'local/datagrading', now 'mod/data/field/gradeentry')
        // is a log marker only and isn't persisted on grade_items.

        upgrade_plugin_savepoint(true, 2025120810, 'datafield', 'gradeentry');
    }

    if ($oldversion < 2026010100) {
        $table = new xmldb_table('datafield_gradeentry_grades');

        // Add submission_status column.
        $field = new xmldb_field('submission_status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'notsubmitted');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add requireresubmission column.
        $field = new xmldb_field('requireresubmission', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add rubric_scores column.
        $field = new xmldb_field('rubric_scores', XMLDB_TYPE_TEXT, null, null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026010100, 'datafield', 'gradeentry');
    }

    return true;
}
