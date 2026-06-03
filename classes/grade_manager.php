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
 * Grade storage and gradebook synchronisation manager for datafield_gradeentry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace datafield_gradeentry;

/**
 * Manages grade storage, retrieval, and gradebook synchronisation.
 */
class grade_manager {
    /**
     * Save a grade for one database entry and sync to the Moodle gradebook.
     *
     * The grade value itself is already persisted in mdl_data_content by the
     * calling web-service layer. This method writes the grade metadata row
     * (feedback, released flag, grader) and fires the gradebook update.
     *
     * @param int    $cmid      Course-module ID of the database activity.
     * @param int    $recordid  ID of the data_records row.
     * @param float  $grade     Numeric grade value.
     * @param string $feedback  Teacher feedback (may be empty).
     * @param int    $graderid  User ID of the teacher saving the grade.
     */
    public static function save(int $cmid, int $recordid, float $grade, string $feedback, int $graderid): void {
        global $DB;

        [$dataid, $courseid, $studentid, $maxgrade] = self::resolve_record_context($cmid, $recordid);

        $now = time();

        $existing = $DB->get_record('datafield_gradeentry_grades', ['dataid' => $dataid, 'recordid' => $recordid]);

        if ($existing) {
            $existing->graderid = $graderid;
            $existing->feedback = $feedback;
            $existing->feedbackformat = FORMAT_MOODLE;
            $existing->timemodified = $now;
            $DB->update_record('datafield_gradeentry_grades', $existing);
        } else {
            $row = (object) [
                'dataid' => $dataid,
                'recordid' => $recordid,
                'userid' => $studentid,
                'graderid' => $graderid,
                'feedback' => $feedback,
                'feedbackformat' => FORMAT_MOODLE,
                'released' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('datafield_gradeentry_grades', $row);
        }

        self::push_to_gradebook($dataid, $courseid, $studentid, $grade, $maxgrade);
    }

    /**
     * Set the released state of one or more entries.
     *
     * @param int        $dataid     Database activity ID.
     * @param int[]|null $recordids  Specific record IDs, or null to operate on all graded entries.
     * @param bool       $released   Target state - true to release to the student, false to unrelease.
     * @return int  Number of rows matching $released after the operation
     *              (the count the caller can show as "n released" / "n unreleased").
     */
    public static function release(int $dataid, ?array $recordids = null, bool $released = true): int {
        global $DB;

        $now = time();
        $flag = $released ? 1 : 0;

        if ($recordids !== null) {
            $count = 0;
            foreach ($recordids as $rid) {
                $count += (int) $DB->set_field(
                    'datafield_gradeentry_grades',
                    'released',
                    $flag,
                    ['dataid' => $dataid, 'recordid' => (int) $rid]
                );
            }
            return $count;
        }

        $DB->execute(
            'UPDATE {datafield_gradeentry_grades}
                SET released = :flag, timemodified = :now
              WHERE dataid = :dataid AND graderid IS NOT NULL',
            ['flag' => $flag, 'now' => $now, 'dataid' => $dataid]
        );

        return $DB->count_records('datafield_gradeentry_grades', ['dataid' => $dataid, 'released' => $flag]);
    }

    /**
     * Return graded and total entry counts for a database activity.
     *
     * @param  int $dataid  Database activity ID.
     * @return array{graded: int, total: int}
     */
    public static function progress(int $dataid): array {
        global $DB;

        $total = $DB->count_records('data_records', ['dataid' => $dataid]);
        $graded = $DB->count_records_select(
            'datafield_gradeentry_grades',
            'dataid = :dataid AND graderid IS NOT NULL',
            ['dataid' => $dataid]
        );

        return ['graded' => (int) $graded, 'total' => (int) $total];
    }

    /**
     * Push one student's grade to the Moodle gradebook.
     *
     * @param int   $dataid
     * @param int   $courseid
     * @param int   $userid
     * @param float $grade
     * @param float $maxgrade
     */
    private static function push_to_gradebook(
        int $dataid,
        int $courseid,
        int $userid,
        float $grade,
        float $maxgrade
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/data/field/gradeentry/lib.php');

        $data = $DB->get_record('data', ['id' => $dataid], 'id, name, course', MUST_EXIST);
        $data->_maxgrade = $maxgrade;

        $gradeobject = (object) [
            'userid' => $userid,
            'rawgrade' => $grade,
        ];

        \datafield_gradeentry_grade_item_update($data, $gradeobject);
    }

    /**
     * Resolve context information for a given course-module and data record.
     *
     * @param  int $cmid      Course-module ID.
     * @param  int $recordid  Data record ID.
     * @return array  [dataid, courseid, studentuserid, maxgrade].
     */
    private static function resolve_record_context(int $cmid, int $recordid): array {
        global $DB;

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, MUST_EXIST);
        $record = $DB->get_record(
            'data_records',
            ['id' => $recordid, 'dataid' => $cm->instance],
            'id, dataid, userid',
            MUST_EXIST
        );

        $maxgrade = (float) ($DB->get_field_select(
            'data_fields',
            'param2',
            'dataid = :dataid AND type = :type AND '
                . $DB->sql_isnotempty('data_fields', 'param2', false, false),
            ['dataid' => $cm->instance, 'type' => 'gradeentry'],
            IGNORE_MISSING
        ) ?? 100);

        return [(int) $cm->instance, (int) $cm->course, (int) $record->userid, $maxgrade];
    }
}
