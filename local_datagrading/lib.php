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
 * @package    local_datagrading
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Inject the grading progress bar and AMD module into Database activity pages for teachers.
 *
 * @return string  HTML to append before </body>.
 */
function local_datagrading_before_footer(): string {
    global $DB, $PAGE, $USER;

    if (!$PAGE->cm || $PAGE->cm->modname !== 'data') {
        return '';
    }

    $context = $PAGE->context;
    if (!has_capability('local/datagrading:grade', $context)) {
        return '';
    }

    $dataid = $PAGE->cm->instance;

    // Count total entries and how many have been graded.
    $total  = $DB->count_records('data_records', ['dataid' => $dataid]);
    $graded = $DB->count_records_select(
        'local_datagrading_grades',
        'dataid = :dataid AND graderid IS NOT NULL',
        ['dataid' => $dataid]
    );

    if ($total === 0) {
        return '';
    }

    $PAGE->requires->js_call_amd('local_datagrading/inline_grader', 'init', [
        $PAGE->cm->id,
        $context->id,
    ]);

    $progresshtml = \html_writer::start_div(
        'local-datagrading-progress alert alert-info d-flex align-items-center gap-3',
        [
            'id'           => 'local-datagrading-progress',
            'data-cmid'    => $PAGE->cm->id,
            'data-graded'  => $graded,
            'data-total'   => $total,
        ]
    );
    $progresshtml .= \html_writer::tag(
        'span',
        get_string('gradingprogress', 'local_datagrading', ['graded' => $graded, 'total' => $total]),
        ['id' => 'local-datagrading-progress-text', 'class' => 'me-auto']
    );
    $progresshtml .= \html_writer::tag(
        'button',
        get_string('releaseall', 'local_datagrading'),
        [
            'id'           => 'local-datagrading-release-all',
            'class'        => 'btn btn-sm btn-secondary',
            'data-cmid'    => $PAGE->cm->id,
        ]
    );
    $progresshtml .= \html_writer::end_div();

    return $progresshtml;
}

/**
 * Register the grade item for a database activity.
 *
 * Called by grade_update() the first time a grade is saved; also suitable
 * for direct calls during plugin setup.
 *
 * @param  stdClass $data  Record from mdl_data.
 * @param  mixed    $grades  Optional grade(s) to write at the same time.
 * @return int  GRADE_UPDATE_OK or error constant.
 */
function local_datagrading_grade_item_update(stdClass $data, $grades = null): int {
    $params = [
        'itemname'  => $data->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => (float) ($data->_maxgrade ?? 100),
        'grademin'  => 0,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('local/datagrading', $data->course, 'mod', 'data', $data->id, 0, $grades, $params);
}
