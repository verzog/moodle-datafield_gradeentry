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
 * Grade metadata (feedback, graded flag, release state, submission status, rubric
 * scores) is stored as a JSON blob in data_content.content1, alongside the
 * grade value in data_content.content. Keeping everything in mod_data's own
 * data_content table means the plugin's data is covered by mod_data's backup,
 * restore and course-copy without a datafield backup subplugin (which mod_data
 * does not support).
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

    /** @var int Maximum number of students recomputed per gradebook sync query. */
    const SYNC_BATCH_SIZE = 500;

    /** @var string Grading method: plain numeric value. */
    const METHOD_NUMERIC = 'numeric';
    /** @var string Grading method: Moodle scale (1-based index). */
    const METHOD_SCALE = 'scale';
    /** @var string Grading method: rubric with criteria and scored levels. */
    const METHOD_RUBRIC = 'rubric';

    /**
     * Return the default grade-metadata array for an ungraded entry.
     *
     * @return array
     */
    public static function metadata_defaults(): array {
        return [
            'graded'              => 0,
            'feedback'            => '',
            'feedbackformat'      => FORMAT_MOODLE,
            'released'            => 0,
            'submission_status'   => self::STATUS_NOTSUBMITTED,
            'requireresubmission' => 0,
            'rubric_scores'       => null,
            'timecreated'         => 0,
            'timemodified'        => 0,
        ];
    }

    /**
     * Resolve the gradeentry field id for a Database activity.
     *
     * Only one Grade entry field per activity is supported, so the first match
     * is returned.
     *
     * @param  int $dataid  Database activity ID.
     * @return int|null  The field ID, or null if the activity has no gradeentry field.
     */
    public static function get_field_id(int $dataid): ?int {
        global $DB;
        $id = $DB->get_field(
            'data_fields',
            'id',
            ['dataid' => $dataid, 'type' => 'gradeentry'],
            IGNORE_MULTIPLE
        );
        return $id ? (int) $id : null;
    }

    /**
     * Read the grade metadata for one entry from data_content.content1.
     *
     * @param  int $fieldid   Grade entry field ID.
     * @param  int $recordid  Data record ID.
     * @return array  Metadata array (defaults merged in for missing keys).
     */
    public static function get_metadata(int $fieldid, int $recordid): array {
        global $DB;
        $json = $DB->get_field('data_content', 'content1', ['fieldid' => $fieldid, 'recordid' => $recordid]);
        return self::decode_metadata($json);
    }

    /**
     * Decode a content1 JSON blob into a metadata array, applying defaults.
     *
     * @param  mixed $json  Raw content1 value (string, null, or false).
     * @return array
     */
    private static function decode_metadata($json): array {
        if ($json === false || $json === null || $json === '') {
            return self::metadata_defaults();
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return self::metadata_defaults();
        }
        return array_merge(self::metadata_defaults(), $decoded);
    }

    /**
     * Return true when a metadata blob already exists for this entry.
     *
     * @param  int $fieldid   Grade entry field ID.
     * @param  int $recordid  Data record ID.
     * @return bool
     */
    public static function has_metadata(int $fieldid, int $recordid): bool {
        global $DB;
        $json = $DB->get_field('data_content', 'content1', ['fieldid' => $fieldid, 'recordid' => $recordid]);
        return self::has_metadata_json($json);
    }

    /**
     * Return true when a raw content1 value holds a metadata blob.
     *
     * @param  mixed $json  Raw content1 value (string, null, or false).
     * @return bool
     */
    private static function has_metadata_json($json): bool {
        return $json !== false && $json !== null && $json !== ''
            && is_array(json_decode((string) $json, true));
    }

    /**
     * Persist a metadata array into data_content.content1 for one entry.
     *
     * Creates the content row (with an empty grade value) when it does not
     * yet exist, e.g. a student sets a draft status before being graded.
     *
     * @param int   $fieldid   Grade entry field ID.
     * @param int   $recordid  Data record ID.
     * @param array $meta       Metadata array to store.
     */
    private static function set_metadata(int $fieldid, int $recordid, array $meta): void {
        global $DB;
        $json = json_encode($meta);
        $content = $DB->get_record('data_content', ['fieldid' => $fieldid, 'recordid' => $recordid]);
        if ($content) {
            $content->content1 = $json;
            $DB->update_record('data_content', $content);
        } else {
            $DB->insert_record('data_content', (object) [
                'fieldid'  => $fieldid,
                'recordid' => $recordid,
                'content'  => null,
                'content1' => $json,
            ]);
        }
    }

    /**
     * Return the gradebook item settings for a Grade entry field.
     *
     * Scale fields use the field's scale. Rubric fields use the sum of each
     * criterion's highest level score as the maximum. Numeric fields use the
     * configured maximum grade, or 100 when no maximum is set.
     *
     * @param  \stdClass $field  The data_fields row (needs param2, param5, param6 and param7).
     * @return array  [scaleid, maxgrade]; scaleid is 0 for non-scale fields.
     */
    public static function grade_item_settings(\stdClass $field): array {
        $method = (string) ($field->param5 ?? self::METHOD_NUMERIC);

        if ($method === self::METHOD_SCALE) {
            return [(int) ($field->param6 ?? 0), 0.0];
        }

        if ($method === self::METHOD_RUBRIC) {
            $total = 0.0;
            foreach (self::get_rubric_criteria($field) as $criterion) {
                $scores = array_map(static fn($level) => (float) ($level['score'] ?? 0), $criterion['levels'] ?? []);
                $total += $scores ? max($scores) : 0.0;
            }
            if ($total > 0) {
                return [0, $total];
            }
        }

        $max = $field->param2 ?? '';
        return [0, is_numeric($max) && (float) $max > 0 ? (float) $max : 100.0];
    }

    /**
     * Decode a field's rubric criteria JSON (param7).
     *
     * @param  \stdClass $field  The data_fields row.
     * @return array  List of criteria, each with a name and a list of levels; empty when not configured.
     */
    public static function get_rubric_criteria(\stdClass $field): array {
        $json = (string) ($field->param7 ?? '');
        if ($json === '') {
            return [];
        }
        $criteria = json_decode($json, true);
        return is_array($criteria) ? array_values($criteria) : [];
    }

    /**
     * Save the grading metadata for one database entry.
     *
     * The grade value itself is already persisted in data_content.content by the
     * calling web-service layer. This method writes the grade metadata blob
     * (feedback, graded flag) into content1 and re-syncs the student's
     * gradebook grade, which only ever reflects a released entry; an
     * unreleased grade reaches the gradebook when release() is called.
     *
     * @param int         $cmid         Course-module ID of the database activity.
     * @param int         $fieldid      Grade entry field ID.
     * @param int         $recordid     ID of the data_records row.
     * @param float       $grade        Numeric grade value (or scale index for scale grading).
     * @param string      $feedback     Teacher feedback (may be empty).
     * @param string|null $rubricscores JSON-encoded per-criterion rubric scores (optional).
     */
    public static function save(
        int $cmid,
        int $fieldid,
        int $recordid,
        float $grade,
        string $feedback,
        ?string $rubricscores = null
    ): void {
        [$dataid, , $studentid] = self::resolve_record_context($cmid, $recordid);

        $now = time();
        $isnew = !self::has_metadata($fieldid, $recordid);
        $meta = self::get_metadata($fieldid, $recordid);

        // Mark as graded. The grader's identity is recorded on the gradebook
        // item and the entry_graded event, not in content1 (mod_data would not
        // remap a stored user id on restore to another site).
        $meta['graded']              = 1;
        $meta['feedback']            = $feedback;
        $meta['feedbackformat']      = FORMAT_MOODLE;
        $meta['requireresubmission'] = 0;
        $meta['timemodified']        = $now;
        if ($rubricscores !== null) {
            $meta['rubric_scores'] = $rubricscores;
        }
        if ($isnew) {
            $meta['released']          = 0;
            $meta['submission_status'] = self::STATUS_SUBMITTED;
            $meta['timecreated']       = $now;
        }

        self::set_metadata($fieldid, $recordid, $meta);

        self::sync_gradebook($dataid, $fieldid, [$studentid]);
    }

    /**
     * Clear the grade for a single data record and remove it from the gradebook.
     *
     * The grade value in content is cleared but the content row is kept so the
     * submission status and resubmission flag stored in content1 are preserved.
     *
     * @param int $cmid      Course-module ID of the database activity.
     * @param int $recordid  Data record ID.
     * @param int $fieldid   ID of the gradeentry field.
     */
    public static function delete(int $cmid, int $recordid, int $fieldid): void {
        global $DB;

        [$dataid, , $studentid] = self::resolve_record_context($cmid, $recordid);

        // Clear the grade value but keep the content row so the submission
        // status stored in content1 survives.
        $content = $DB->get_record('data_content', ['fieldid' => $fieldid, 'recordid' => $recordid]);
        if ($content) {
            $content->content = null;

            // Reset only the grading fields; preserve submission_status and
            // requireresubmission because content1 is their only storage.
            $meta = self::decode_metadata($content->content1);
            $meta['graded']         = 0;
            $meta['feedback']       = '';
            $meta['feedbackformat'] = FORMAT_MOODLE;
            $meta['released']       = 0;
            $meta['rubric_scores']  = null;
            $meta['timemodified']   = time();
            $content->content1 = json_encode($meta);
            $DB->update_record('data_content', $content);
        }

        // Falls back to another released entry, or removes the student's grade.
        self::sync_gradebook($dataid, $fieldid, [$studentid]);
    }

    /**
     * Set the released state of one or more entries and sync the gradebook.
     *
     * Afterwards each affected student's gradebook grade is recomputed, so
     * students only ever see released grades there.
     *
     * @param int        $dataid     Database activity ID.
     * @param int[]|null $recordids  Specific record IDs, or null to operate on all graded entries.
     * @param bool       $released   Target state - true to release to the student, false to unrelease.
     * @return int  Number of entries updated to the target state.
     */
    public static function release(int $dataid, ?array $recordids = null, bool $released = true): int {
        global $DB;

        $fieldid = self::get_field_id($dataid);
        if ($fieldid === null) {
            return 0;
        }

        $params = ['fieldid' => $fieldid, 'dataid' => $dataid];
        $where = '';
        if ($recordids !== null) {
            if (!$recordids) {
                return 0;
            }
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $recordids), SQL_PARAMS_NAMED, 'rid');
            $where = " AND dc.recordid {$insql}";
            $params += $inparams;
        }

        $rows = $DB->get_records_sql(
            "SELECT dc.id, dc.content1, dr.userid
               FROM {data_content} dc
               JOIN {data_records} dr ON dr.id = dc.recordid AND dr.dataid = :dataid
              WHERE dc.fieldid = :fieldid{$where}",
            $params
        );

        $now = time();
        $count = 0;
        $userids = [];
        foreach ($rows as $row) {
            if (!self::has_metadata_json($row->content1)) {
                continue;
            }
            $meta = self::decode_metadata($row->content1);
            // Release-all only touches graded entries; an explicit list may
            // also include ungraded entries that already have metadata.
            if ($recordids === null && empty($meta['graded'])) {
                continue;
            }
            $meta['released']     = $released ? 1 : 0;
            $meta['timemodified'] = $now;
            $DB->set_field('data_content', 'content1', json_encode($meta), ['id' => $row->id]);
            $count++;

            if (!empty($meta['graded'])) {
                $userids[(int) $row->userid] = (int) $row->userid;
            }
        }

        if ($userids) {
            self::sync_gradebook($dataid, $fieldid, array_values($userids));
        }

        return $count;
    }

    /**
     * Update the submission status for a student's entry.
     *
     * Creates the metadata blob if it doesn't yet exist (e.g. student sets
     * draft status before a teacher has graded).
     *
     * @param int    $dataid    Database activity ID.
     * @param int    $recordid  Data record ID.
     * @param int    $userid    Student user ID (implied by the data record; kept for API compatibility).
     * @param string $status    One of the STATUS_* constants.
     */
    public static function update_submission_status(
        int $dataid,
        int $recordid,
        int $userid,
        string $status
    ): void {
        unset($userid); // The owner is implied by the data record; not stored in content1.

        $allowed = [self::STATUS_NOTSUBMITTED, self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_RESUBMIT];
        if (!in_array($status, $allowed, true)) {
            throw new \coding_exception('Invalid submission status: ' . $status);
        }

        $fieldid = self::get_field_id($dataid);
        if ($fieldid === null) {
            throw new \coding_exception('No gradeentry field for database activity ' . $dataid);
        }

        $now = time();
        $isnew = !self::has_metadata($fieldid, $recordid);
        $meta = self::get_metadata($fieldid, $recordid);

        $meta['submission_status'] = $status;
        // Clear resubmission flag when student resubmits.
        if ($status === self::STATUS_SUBMITTED) {
            $meta['requireresubmission'] = 0;
        }
        $meta['timemodified'] = $now;
        if ($isnew) {
            $meta['timecreated'] = $now;
        }

        self::set_metadata($fieldid, $recordid, $meta);
    }

    /**
     * Set or clear the "require resubmission" flag on an entry.
     *
     * Creates the metadata blob when it doesn't yet exist so that entries
     * graded before the status feature was added are handled correctly.
     * When clearing the flag, resets submission_status to 'submitted' so
     * the badge and student view reflect the corrected state.
     *
     * @param int  $dataid    Database activity ID.
     * @param int  $recordid  Data record ID.
     * @param bool $require   True to require resubmission, false to clear.
     */
    public static function set_require_resubmission(int $dataid, int $recordid, bool $require): void {
        $fieldid = self::get_field_id($dataid);
        if ($fieldid === null) {
            throw new \coding_exception('No gradeentry field for database activity ' . $dataid);
        }

        $now = time();
        $isnew = !self::has_metadata($fieldid, $recordid);
        $meta = self::get_metadata($fieldid, $recordid);

        $meta['requireresubmission'] = $require ? 1 : 0;
        if ($isnew) {
            $meta['submission_status'] = $require ? self::STATUS_RESUBMIT : self::STATUS_NOTSUBMITTED;
            $meta['timecreated'] = $now;
        } else {
            $meta['submission_status'] = $require ? self::STATUS_RESUBMIT : self::STATUS_SUBMITTED;
        }
        $meta['timemodified'] = $now;

        self::set_metadata($fieldid, $recordid, $meta);
    }

    /**
     * Return graded and total entry counts for a database activity.
     *
     * @param  int $dataid  Database activity ID.
     * @return array{graded: int, total: int}
     */
    public static function progress(int $dataid): array {
        global $DB;

        $total = (int) $DB->count_records('data_records', ['dataid' => $dataid]);

        $graded = 0;
        $fieldid = self::get_field_id($dataid);
        if ($fieldid !== null) {
            // Count in SQL so browse-page renders do not scale with entry count.
            // json_encode escapes quotes inside string values, so the literal
            // '"graded":1' only ever matches the graded flag, never feedback text.
            $like = $DB->sql_like('content1', ':needle');
            $graded = (int) $DB->count_records_select(
                'data_content',
                "fieldid = :fieldid AND {$like}",
                ['fieldid' => $fieldid, 'needle' => '%"graded":1%']
            );
        }

        return ['graded' => $graded, 'total' => $total];
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
     * Bring an activity's gradebook back in line with the release state.
     *
     * Recomputes the grade of every student who has a graded entry, so each
     * holds their latest released grade or none. Activities without a gradebook
     * item are left alone, so this never creates one.
     *
     * Must not be called during an upgrade: the gradebook API loads course
     * module info, which Moodle refuses while an upgrade is running. The
     * upgrade queues the resync_gradebook ad hoc task instead.
     *
     * @param int $dataid  Database activity ID.
     */
    public static function resync_activity(int $dataid): void {
        global $DB;

        $fieldid = self::get_field_id($dataid);
        $data = $DB->get_record('data', ['id' => $dataid], 'id, course');
        if ($fieldid === null || !$data) {
            return;
        }

        $hasitem = $DB->record_exists('grade_items', [
            'courseid'     => $data->course,
            'itemtype'     => 'mod',
            'itemmodule'   => 'data',
            'iteminstance' => $dataid,
            'itemnumber'   => 0,
        ]);
        if (!$hasitem) {
            return;
        }

        $rows = $DB->get_records_sql(
            "SELECT dc.id, dc.content1, dr.userid
               FROM {data_content} dc
               JOIN {data_records} dr ON dr.id = dc.recordid AND dr.dataid = :dataid
              WHERE dc.fieldid = :fieldid",
            ['fieldid' => $fieldid, 'dataid' => $dataid]
        );
        $userids = [];
        foreach ($rows as $row) {
            if (!empty(self::decode_metadata($row->content1)['graded'])) {
                $userids[(int) $row->userid] = (int) $row->userid;
            }
        }

        if ($userids) {
            self::sync_gradebook($dataid, $fieldid, array_values($userids));
        }
    }

    /**
     * Recompute and push the gradebook grade for each of the given students.
     *
     * The gradebook holds one grade per student for the activity, but a student
     * can have several entries. Their grade is that of their most recent
     * (highest record id) graded and released entry, or none when no entry is
     * released, so changing one entry never clobbers another entry's grade.
     *
     * @param int   $dataid   Database activity ID.
     * @param int   $fieldid  Grade entry field ID.
     * @param int[] $userids  Students whose grade should be recomputed.
     */
    private static function sync_gradebook(int $dataid, int $fieldid, array $userids): void {
        global $DB;

        // Batch the users so the IN (...) list stays well under database
        // parameter limits (e.g. 2,100 on SQL Server) on large activities.
        $userids = array_values(array_unique(array_map('intval', $userids)));
        foreach (array_chunk($userids, self::SYNC_BATCH_SIZE) as $batch) {
            $grades = array_fill_keys($batch, null);
            [$insql, $params] = $DB->get_in_or_equal($batch, SQL_PARAMS_NAMED, 'uid');
            $params += ['fieldid' => $fieldid, 'dataid' => $dataid];
            $rows = $DB->get_records_sql(
                "SELECT dc.id, dc.content, dc.content1, dr.userid
                   FROM {data_content} dc
                   JOIN {data_records} dr ON dr.id = dc.recordid AND dr.dataid = :dataid
                  WHERE dc.fieldid = :fieldid AND dr.userid {$insql}
               ORDER BY dr.id ASC",
                $params
            );
            foreach ($rows as $row) {
                $meta = self::decode_metadata($row->content1);
                if (!empty($meta['graded']) && !empty($meta['released']) && $row->content !== null && $row->content !== '') {
                    $grades[(int) $row->userid] = (float) $row->content;
                }
            }

            self::push_to_gradebook($dataid, $fieldid, $grades);
        }
    }

    /**
     * Push grades for one or more students to the Moodle gradebook.
     *
     * @param int   $dataid   Database activity ID.
     * @param int   $fieldid  Grade entry field ID (its settings decide the grade item type and maximum).
     * @param array $grades   Map of user ID to grade (numeric value or 1-based scale index); null removes the grade.
     */
    private static function push_to_gradebook(int $dataid, int $fieldid, array $grades): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/data/field/gradeentry/lib.php');

        $field = $DB->get_record('data_fields', ['id' => $fieldid, 'dataid' => $dataid], '*', MUST_EXIST);
        [$scaleid, $maxgrade] = self::grade_item_settings($field);

        $data = $DB->get_record('data', ['id' => $dataid], 'id, name, course', MUST_EXIST);
        $data->_maxgrade = $maxgrade;
        $data->_scaleid = $scaleid;

        $gradeobjects = [];
        foreach ($grades as $userid => $grade) {
            $gradeobjects[$userid] = (object) ['userid' => (int) $userid, 'rawgrade' => $grade];
        }

        \datafield_gradeentry_grade_item_update($data, $gradeobjects);
    }

    /**
     * Resolve context information for a given course-module and data record.
     *
     * @param  int $cmid      Course-module ID.
     * @param  int $recordid  Data record ID.
     * @return array  [dataid, courseid, studentuserid].
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

        return [(int) $cm->instance, (int) $cm->course, (int) $record->userid];
    }
}
