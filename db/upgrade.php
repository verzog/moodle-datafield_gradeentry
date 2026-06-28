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
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        // Ensure the grade metadata table exists. install.xml covers fresh
        // installs; this branch creates it for sites upgrading from a version
        // that did not yet have it.
        $table = new xmldb_table('datafield_gradeentry_grades');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(
                __DIR__ . '/install.xml',
                'datafield_gradeentry_grades'
            );
        }

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
