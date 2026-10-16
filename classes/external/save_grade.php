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
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
 *  - rubric:  rubricscores JSON holds per-criterion selections; the total is computed server-side
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
            'grade'        => new external_value(
                PARAM_FLOAT,
                'Numeric grade value (or scale index for scale grading); null clears the grade',
                VALUE_DEFAULT,
                null
            ),
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
        global $DB;

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
            // Clear the grade value and grading metadata but keep the
            // submission status stored in content1 (handled by delete()).
            grade_manager::delete($cmid, $recordid, $fieldid);
            $progress = grade_manager::progress($cm->instance);
            return ['success' => true, 'graded' => $progress['graded'], 'total' => $progress['total']];
        }

        $method = (string) ($field->param5 ?? grade_manager::METHOD_NUMERIC);

        // Validate and normalise the grade for the field's grading method before
        // any DB writes, so a bad payload never leaves a half-saved entry.
        $rubricjson = null;
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
        } else if ($method === grade_manager::METHOD_RUBRIC) {
            // The total is computed here from the validated per-criterion
            // scores; the client-supplied total is not trusted.
            $criteria = grade_manager::get_rubric_criteria($field);
            if (!$criteria) {
                throw new \invalid_parameter_exception('No rubric criteria configured for this field.');
            }
            $decoded = json_decode($rubricscores, true);
            if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== count($criteria)) {
                throw new \invalid_parameter_exception(
                    'rubricscores must be a JSON array with one entry per criterion (' . count($criteria) . ' expected).'
                );
            }
            $scores = [];
            foreach ($criteria as $cidx => $criterion) {
                $levels      = $criterion['levels'] ?? [];
                $validscores = array_map(static fn($l) => (float) ($l['score'] ?? 0), $levels);
                $score       = is_numeric($decoded[$cidx]) ? (float) $decoded[$cidx] : null;
                if ($score === null || !in_array($score, $validscores, true)) {
                    throw new \invalid_parameter_exception(
                        'Score for criterion ' . $cidx . ' is not a valid level score.'
                    );
                }
                $scores[] = $score;
            }
            $grade = array_sum($scores);
            $rubricjson = json_encode($scores);
        } else {
            // Numeric: apply the min/max bounds from the field config. A blank
            // bound means no limit on that side.
            $min = $field->param1 ?? '';
            $max = $field->param2 ?? '';
            if ((is_numeric($min) && $grade < (float) $min) || (is_numeric($max) && $grade > (float) $max)) {
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

        grade_manager::save($cmid, $fieldid, $recordid, $grade, $feedback, $rubricjson);

        [, $maxgrade] = grade_manager::grade_item_settings($field);
        $event = \datafield_gradeentry\event\entry_graded::create([
            'context'       => $context,
            'objectid'      => $recordid,
            'relateduserid' => $record->userid,
            'other'         => [
                'grade'    => $grade,
                'maxgrade' => $maxgrade,
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
