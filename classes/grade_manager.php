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

    /** Submission status constants. */
    const STATUS_NOTSUBMITTED = 'notsubmitted';
    const STATUS_DRAFT        = 'draft';
    const STATUS_SUBMITTED    = 'submitted';
    const STATUS_RESUBMIT     = 'resubmit';

    /** Grading method constants. */
    const METHOD_NUMERIC = 'numeric';
    const METHOD_SCALE   = 'scale';
    const METHOD_RUBRIC  = 'rubric';

    /**
     * Save a grade for one database entry and sync to the Moodle gradebook.
     *
     * The grade value itself is already persisted in mdl_data_content by the
     * calling web-service layer. This method writes the grade metadata row
     * (feedback, released flag, grader) and fires the gradebook update.
     *
     * @param int         $cmid         Course-module ID of the database activity.
     * @param int         $recordid     ID of the data_records row.
     * @param float       $grade        Numeric grade value.
     * @param string      $feedback     Teacher feedback (may be empty).
     * @param int         $graderid     User ID of the teacher saving the grade.
     * @param string|null $rubricscores JSON-encoded per-criterion rubric scores (optional).
     */
    public static function save(
        int $cmid,
        int $recordid,
        float $grade,
        string $feedback,
        int $graderid,
        ?string $rubricscores = null
    ): void {
        global $DB;

        [$dataid, $courseid, $studentid, $maxgrade] = self::resolve_record_context($cmid, $recordid);

        $now = time();

        $existing = $DB->get_record('datafield_gradeentry_grades', ['dataid' => $dataid, 'recordid' => $recordid]);

        if ($existing) {
            $existing->graderid           = $graderid;
            $existing->feedback           = $feedback;
            $existing->feedbackformat     = FORMAT_MOODLE;
            $existing->requireresubmission = 0;
            $existing->timemodified       = $now;
            if ($rubricscores !== null) {
                $existing->rubric_scores = $rubricscores;
            }
            $DB->update_record('datafield_gradeentry_grades', $existing);
        } else {
            $row = (object) [
                'dataid'               => $dataid,
                'recordid'             => $recordid,
                'userid'               => $studentid,
                'graderid'             => $graderid,
                'feedback'             => $feedback,
                'feedbackformat'       => FORMAT_MOODLE,
                'released'             => 0,
                'submission_status'    => self::STATUS_SUBMITTED,
                'requireresubmission'  => 0,
                'rubric_scores'        => $rubricscores,
                'timecreated'          => $now,
                'timemodified'         => $now,
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
     * @return int  Number of rows matching $released after the operation.
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
     * Update the submission status for a student's entry.
     *
     * Creates the metadata row if it doesn't yet exist (e.g. student sets
     * draft status before a teacher has graded).
     *
     * @param int    $dataid    Database activity ID.
     * @param int    $recordid  Data record ID.
     * @param int    $userid    Student user ID.
     * @param string $status    One of the STATUS_* constants.
     */
    public static function update_submission_status(
        int $dataid,
        int $recordid,
        int $userid,
        string $status
    ): void {
        global $DB;

        $allowed = [self::STATUS_NOTSUBMITTED, self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_RESUBMIT];
        if (!in_array($status, $allowed, true)) {
            throw new \coding_exception('Invalid submission status: ' . $status);
        }

        $now = time();
        $existing = $DB->get_record('datafield_gradeentry_grades', ['dataid' => $dataid, 'recordid' => $recordid]);

        if ($existing) {
            $existing->submission_status = $status;
            // Clear resubmission flag when student resubmits.
            if ($status === self::STATUS_SUBMITTED) {
                $existing->requireresubmission = 0;
            }
            $existing->timemodified = $now;
            $DB->update_record('datafield_gradeentry_grades', $existing);
        } else {
            $DB->insert_record('datafield_gradeentry_grades', (object) [
                'dataid'               => $dataid,
                'recordid'             => $recordid,
                'userid'               => $userid,
                'graderid'             => null,
                'feedback'             => '',
                'feedbackformat'       => FORMAT_MOODLE,
                'released'             => 0,
                'submission_status'    => $status,
                'requireresubmission'  => 0,
                'rubric_scores'        => null,
                'timecreated'          => $now,
                'timemodified'         => $now,
            ]);
        }
    }

    /**
     * Set or clear the "require resubmission" flag on an entry.
     *
     * @param int  $dataid    Database activity ID.
     * @param int  $recordid  Data record ID.
     * @param bool $require   True to require resubmission, false to clear.
     */
    public static function set_require_resubmission(int $dataid, int $recordid, bool $require): void {
        global $DB;

        $now = time();
        $existing = $DB->get_record('datafield_gradeentry_grades', ['dataid' => $dataid, 'recordid' => $recordid]);

        if ($existing) {
            $existing->requireresubmission = $require ? 1 : 0;
            if ($require) {
                $existing->submission_status = self::STATUS_RESUBMIT;
            }
            $existing->timemodified = $now;
            $DB->update_record('datafield_gradeentry_grades', $existing);
        }
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
     * Parse the items of a Moodle scale into an ordered array.
     *
     * @param  int $scaleid  Scale ID from mdl_scale.
     * @return string[]  Ordered scale items (index 0 = first item).
     */
    public static function get_scale_items(int $scaleid): array {
        global $DB;
        $scale = $DB->get_record('scale', ['id' => $scaleid], 'scale', IGNORE_MISSING);
        if (!$scale) {
            return [];
        }
        return array_map('trim', explode(',', $scale->scale));
    }

    /**
     * Return the numeric grade value for a scale item index.
     *
     * Moodle stores scale grades as 1-based integers matching the item position.
     *
     * @param  int   $scaleid  Scale ID.
     * @param  int   $index    1-based position in the scale.
     * @return float
     */
    public static function scale_index_to_grade(int $scaleid, int $index): float {
        return (float) $index;
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
