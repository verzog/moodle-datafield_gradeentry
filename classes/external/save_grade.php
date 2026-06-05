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
 * External function to save a grade for a single database entry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use datafield_gradeentry\grade_manager;

/**
 * Saves a grade value and optional feedback for a single database activity entry.
 *
 * Supports three grading methods determined by the field's param5:
 *  - numeric: standard number input (existing behaviour)
 *  - scale:   grade is a 1-based integer index into the Moodle scale items
 *  - rubric:  grade is the computed total; rubric_scores JSON holds per-criterion selections
 */
class save_grade extends external_api {
    /**
     * Declare the expected input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'Course-module ID of the database activity'),
            'recordid'     => new external_value(PARAM_INT, 'ID of the data_records row to grade'),
            'fieldid'      => new external_value(PARAM_INT, 'ID of the gradeentry field'),
            'grade'        => new external_value(PARAM_FLOAT, 'Numeric grade value (or scale index for scale grading); null clears the grade', VALUE_DEFAULT, null),
            'feedback'     => new external_value(PARAM_TEXT, 'Teacher feedback', VALUE_DEFAULT, ''),
            'rubricscores' => new external_value(PARAM_RAW, 'JSON rubric criterion scores', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save the grade and sync to the gradebook.
     *
     * @param int        $cmid
     * @param int        $recordid
     * @param int        $fieldid
     * @param float|null $grade     null clears the grade
     * @param string     $feedback
     * @param string     $rubricscores
     * @return array{success: bool, graded: int, total: int}
     */
    public static function execute(
        int $cmid,
        int $recordid,
        int $fieldid,
        ?float $grade = null,
        string $feedback = '',
        string $rubricscores = ''
    ): array {
        global $DB, $USER;

        [
            'cmid'         => $cmid,
            'recordid'     => $recordid,
            'fieldid'      => $fieldid,
            'grade'        => $grade,
            'feedback'     => $feedback,
            'rubricscores' => $rubricscores,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid'         => $cmid,
            'recordid'     => $recordid,
            'fieldid'      => $fieldid,
            'grade'        => $grade,
            'feedback'     => $feedback,
            'rubricscores' => $rubricscores,
        ]);

        $cm = get_coursemodule_from_id('data', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('datafield/gradeentry:grade', $context);

        $field = $DB->get_record(
            'data_fields',
            ['id' => $fieldid, 'dataid' => $cm->instance, 'type' => 'gradeentry'],
            '*',
            MUST_EXIST
        );

        $record = $DB->get_record(
            'data_records',
            ['id' => $recordid, 'dataid' => $cm->instance],
            'id, userid',
            MUST_EXIST
        );

        if ($grade === null) {
            $DB->delete_records('data_content', ['fieldid' => $fieldid, 'recordid' => $recordid]);
            grade_manager::delete($cmid, $recordid, $fieldid);
            $progress = grade_manager::progress($cm->instance);
            return ['success' => true, 'graded' => $progress['graded'], 'total' => $progress['total']];
        }

        $method = (string) ($field->param5 ?? grade_manager::METHOD_NUMERIC);

        // Validate and normalise grade based on grading method.
        if ($method === grade_manager::METHOD_SCALE) {
            $scaleid = (int) ($field->param6 ?? 0);
            if ($scaleid <= 0) {
                throw new \invalid_parameter_exception('No scale configured for this field.');
            }
            $items = grade_manager::get_scale_items($scaleid);
            $index = (int) $grade;
            if ($index < 1 || $index > count($items)) {
                throw new \invalid_parameter_exception('Scale index is outside the valid range.');
            }
            // Store 1-based index matching Moodle's scale grade storage.
            $grade = (float) $index;
        } else {
            // Numeric and rubric: apply min/max bounds from field config.
            $min = (float) ($field->param1 !== '' ? $field->param1 : PHP_INT_MIN);
            $max = (float) ($field->param2 !== '' ? $field->param2 : PHP_INT_MAX);
            if ($grade < $min || $grade > $max) {
                throw new \invalid_parameter_exception('Grade value is outside the allowed range.');
            }
        }

        $existing = $DB->get_record('data_content', ['fieldid' => $fieldid, 'recordid' => $recordid]);
        if ($existing) {
            $existing->content = $grade;
            $DB->update_record('data_content', $existing);
        } else {
            $DB->insert_record('data_content', (object) [
                'fieldid'  => $fieldid,
                'recordid' => $recordid,
                'content'  => $grade,
            ]);
        }

        $rubricjson = ($method === grade_manager::METHOD_RUBRIC && $rubricscores !== '')
            ? $rubricscores
            : null;

        $scaleid = ($method === grade_manager::METHOD_SCALE) ? (int) ($field->param6 ?? 0) : 0;

        grade_manager::save($cmid, $recordid, $grade, $feedback, (int) $USER->id, $rubricjson, $scaleid);

        $event = \datafield_gradeentry\event\entry_graded::create([
            'context'       => $context,
            'objectid'      => $recordid,
            'relateduserid' => $record->userid,
            'other'         => [
                'grade'    => $grade,
                'maxgrade' => (float) ($field->param2 ?: 100),
            ],
        ]);
        $event->trigger();

        $progress = grade_manager::progress($cm->instance);

        return [
            'success' => true,
            'graded'  => $progress['graded'],
            'total'   => $progress['total'],
        ];
    }

    /**
     * Declare the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if the grade was saved successfully'),
            'graded'  => new external_value(PARAM_INT, 'Number of graded entries so far'),
            'total'   => new external_value(PARAM_INT, 'Total number of entries'),
        ]);
    }
}
