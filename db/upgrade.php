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

    if ($oldversion < 2026070100) {
        // Move grade metadata out of the plugin's own datafield_gradeentry_grades
        // table and into data_content.content1 (as a JSON blob) so it is covered
        // by mod_data's backup, restore and course-copy. The grade value itself
        // already lives in data_content.content.
        $oldtable = new xmldb_table('datafield_gradeentry_grades');
        if ($dbman->table_exists($oldtable)) {
            $rs = $DB->get_recordset('datafield_gradeentry_grades');
            foreach ($rs as $row) {
                // Resolve the gradeentry field for this activity (one per activity).
                $fieldid = $DB->get_field(
                    'data_fields',
                    'id',
                    ['dataid' => $row->dataid, 'type' => 'gradeentry'],
                    IGNORE_MULTIPLE
                );
                if (!$fieldid) {
                    continue;
                }

                $meta = json_encode([
                    'graderid'            => isset($row->graderid) && $row->graderid !== null ? (int) $row->graderid : null,
                    'feedback'            => isset($row->feedback) ? (string) $row->feedback : '',
                    'feedbackformat'      => isset($row->feedbackformat) ? (int) $row->feedbackformat : FORMAT_MOODLE,
                    'released'            => isset($row->released) ? (int) $row->released : 0,
                    'submission_status'   => isset($row->submission_status) ? (string) $row->submission_status : 'notsubmitted',
                    'requireresubmission' => isset($row->requireresubmission) ? (int) $row->requireresubmission : 0,
                    'rubric_scores'       => $row->rubric_scores ?? null,
                    'timecreated'         => isset($row->timecreated) ? (int) $row->timecreated : 0,
                    'timemodified'        => isset($row->timemodified) ? (int) $row->timemodified : 0,
                ]);

                $content = $DB->get_record('data_content', ['fieldid' => $fieldid, 'recordid' => $row->recordid]);
                if ($content) {
                    $content->content1 = $meta;
                    $DB->update_record('data_content', $content);
                } else {
                    $DB->insert_record('data_content', (object) [
                        'fieldid'  => $fieldid,
                        'recordid' => $row->recordid,
                        'content'  => null,
                        'content1' => $meta,
                    ]);
                }
            }
            $rs->close();

            $dbman->drop_table($oldtable);
        }

        upgrade_plugin_savepoint(true, 2026070100, 'datafield', 'gradeentry');
    }

    return true;
}
