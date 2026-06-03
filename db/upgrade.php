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
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

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
        // it's still installed alongside us.
        $oldtable = new xmldb_table('local_datagrading_grades');
        if ($dbman->table_exists($oldtable)) {
            $DB->execute(
                'INSERT INTO {datafield_gradeentry_grades}
                    (dataid, recordid, userid, graderid, feedback, feedbackformat,
                     released, timecreated, timemodified)
                 SELECT dataid, recordid, userid, graderid, feedback, feedbackformat,
                        released, timecreated, timemodified
                   FROM {local_datagrading_grades}'
            );
        }

        // 3. Re-point existing gradebook items so they survive the rename of
        // the grade source string (was 'local/datagrading').
        $DB->set_field('grade_items', 'source', 'mod/data/field/gradeentry',
            ['source' => 'local/datagrading']);

        // 4. Migrate role overrides on the old capabilities to the new ones
        // so existing teacher-grader assignments are preserved across the
        // rename. Uses REPLACE() rather than a join because the role_capabilities
        // table only holds the string capability name.
        $DB->execute(
            "UPDATE {role_capabilities}
                SET capability = REPLACE(capability,
                    'local/datagrading:', 'datafield/gradeentry:')
              WHERE capability IN
                  ('local/datagrading:grade','local/datagrading:viewgrades')"
        );

        upgrade_plugin_savepoint(true, 2025120810, 'datafield', 'gradeentry');
    }

    return true;
}
