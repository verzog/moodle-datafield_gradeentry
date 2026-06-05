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
    /** @var string Submission status: entry not yet acted on by the student. */
    const STATUS_NOTSUBMITTED = 'notsubmitted';
    /** @var string Submission status: student saved as draft, not ready to grade. */
    const STATUS_DRAFT = 'draft';
    /** @var string Submission status: student submitted for grading. */
    const STATUS_SUBMITTED = 'submitted';
    /** @var string Submission status: teacher has requested a resubmission. */
    const STATUS_RESUBMIT = 'resubmit';

    /** @var string Grading method: plain numeric value. */
    const METHOD_NUMERIC = 'numeric';
    /** @var string Grading method: Moodle scale (1-based index). */
    const METHOD_SCALE = 'scale';
    /** @var string Grading method: rubric with criteria and scored levels. */
    const METHOD_RUBRIC = 'rubric';

    /**
     * Save a grade for one database entry and sync to the Moodle gradebook.
     *
     * The grade value itself is already persisted in mdl_data_content by the
     * calling web-service layer. This method writes the grade metadata row
     * (feedback, released flag, grader) and fires the gradebook update.
     *
     * @param int         $cmid         Course-module ID of the database activity.
     * @param int         $recordid     ID of the data_records row.
     * @param float       $grade        Numeric grade value (or scale index for scale grading).
     * @param string      $feedback     Teacher feedback (may be empty).
     * @param int         $graderid     User ID of the teacher saving the grade.
     * @param string|null $rubricscores JSON-encoded per-criterion rubric scores (optional).
     * @param int         $scaleid      Moodle scale ID when grading method is scale; 0 otherwise.
     */
    public static function save(
        int $cmid,
        int $recordid,
        float $grade,
        string $feedback,
        int $graderid,
        ?string $rubricscores = null,
        int $scaleid = 0
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

        self::push_to_gradebook($dataid, $courseid, $studentid, $grade, $maxgrade, $scaleid);
    }

    /**
     * Clear the grade for a single data record and remove it from the gradebook.
     *
     * @param int $cmid      Course-module ID of the database activity.
     * @param int $recordid  Data record ID.
     * @param int $fieldid   ID of the gradeentry field (needed to read scale/maxgrade config).
     */
    public static function delete(int $cmid, int $recordid, int $fieldid): void {
        global $DB, $CFG;

        [$dataid, $courseid, $studentid] = self::resolve_record_context($cmid, $recordid);

        $existing = $DB->get_record('datafield_gradeentry_grades', ['dataid' => $dataid, 'recordid' => $recordid]);

        if ($existing) {
            // Reset only the grading fields; preserve submission_status and
            // requireresubmission because this table is their only storage.
            $existing->graderid       = null;
            $existing->feedback       = '';
            $existing->feedbackformat = FORMAT_MOODLE;
            $existing->released       = 0;
            $existing->rubric_scores  = null;
            $existing->timemodified   = time();
            $DB->update_record('datafield_gradeentry_grades', $existing);
        }
        // If no row exists there is nothing to clear; skip the DB write.

        require_once($CFG->dirroot . '/mod/data/field/gradeentry/lib.php');

        // Use the field's actual grading configuration so the gradebook item
        // type (GRADE_TYPE_SCALE vs GRADE_TYPE_VALUE) is not altered by this call.
        $field   = $DB->get_record('data_fields', ['id' => $fieldid, 'dataid' => $dataid], 'param2,param5,param6', MUST_EXIST);
        $method  = (string) ($field->param5 ?? self::METHOD_NUMERIC);
        $scaleid = ($method === self::METHOD_SCALE) ? (int) ($field->param6 ?? 0) : 0;
        $maxgrade = ($scaleid > 0) ? 0.0 : (float) ($field->param2 !== '' && $field->param2 !== null ? $field->param2 : 100);

        $data = $DB->get_record('data', ['id' => $dataid], 'id, name, course', MUST_EXIST);
        $data->_maxgrade = $maxgrade;
        $data->_scaleid  = $scaleid;

        // rawgrade = null removes the gradebook entry for this student.
        $gradeobject = (object) ['userid' => $studentid, 'rawgrade' => null];
        \datafield_gradeentry_grade_item_update($data, $gradeobject);
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
     * Creates the metadata row when it doesn't yet exist so that entries
     * graded before the status feature was added are handled correctly.
     * When clearing the flag, resets submission_status to 'submitted' so
     * the badge and student view reflect the corrected state.
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
            $existing->submission_status = $require ? self::STATUS_RESUBMIT : self::STATUS_SUBMITTED;
            $existing->timemodified = $now;
            $DB->update_record('datafield_gradeentry_grades', $existing);
        } else {
            // No metadata row yet (entry pre-dates the status feature). Look up
            // the student from data_records so we can create the row correctly.
            $record = $DB->get_record('data_records', ['id' => $recordid, 'dataid' => $dataid], 'userid', MUST_EXIST);
            $DB->insert_record('datafield_gradeentry_grades', (object) [
                'dataid' => $dataid,
                'recordid' => $recordid,
                'userid' => (int) $record->userid,
                'graderid' => null,
                'feedback' => '',
                'feedbackformat' => FORMAT_MOODLE,
                'released' => 0,
                'submission_status' => $require ? self::STATUS_RESUBMIT : self::STATUS_NOTSUBMITTED,
                'requireresubmission' => $require ? 1 : 0,
                'rubric_scores' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
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
     * @param float $grade    Numeric value or 1-based scale index.
     * @param float $maxgrade Maximum grade (ignored when $scaleid > 0).
     * @param int   $scaleid  Moodle scale ID; > 0 causes a GRADE_TYPE_SCALE item to be used.
     */
    private static function push_to_gradebook(
        int $dataid,
        int $courseid,
        int $userid,
        float $grade,
        float $maxgrade,
        int $scaleid = 0
    ): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/data/field/gradeentry/lib.php');

        $data = $DB->get_record('data', ['id' => $dataid], 'id, name, course', MUST_EXIST);
        $data->_maxgrade = $maxgrade;
        $data->_scaleid = $scaleid;

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
