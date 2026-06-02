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

namespace local_datagrading;

/**
 * Hook callbacks for local_datagrading.
 *
 * @package    local_datagrading
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Inject the grading progress bar and AMD module into Database activity pages for teachers.
     *
     * @param  \core\hook\output\before_footer_html_generation $hook  The footer-generation hook.
     */
    public static function before_footer_html_generation(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $DB, $PAGE;

        if (!$PAGE->cm || $PAGE->cm->modname !== 'data') {
            return;
        }

        $context = $PAGE->context;
        if (!has_capability('local/datagrading:grade', $context)) {
            return;
        }

        $dataid = $PAGE->cm->instance;

        $total = $DB->count_records('data_records', ['dataid' => $dataid]);
        if ($total === 0) {
            return;
        }

        $graded = $DB->count_records_select(
            'local_datagrading_grades',
            'dataid = :dataid AND graderid IS NOT NULL',
            ['dataid' => $dataid]
        );

        $PAGE->requires->js_call_amd('local_datagrading/inline_grader', 'init', [
            $PAGE->cm->id,
            $context->id,
        ]);

        $html  = \html_writer::start_div(
            'local-datagrading-progress alert alert-info d-flex align-items-center gap-3',
            [
                'id'          => 'local-datagrading-progress',
                'data-cmid'   => $PAGE->cm->id,
                'data-graded' => $graded,
                'data-total'  => $total,
            ]
        );
        $html .= \html_writer::tag(
            'span',
            get_string('gradingprogress', 'local_datagrading', ['graded' => $graded, 'total' => $total]),
            ['id' => 'local-datagrading-progress-text', 'class' => 'me-auto']
        );
        $html .= \html_writer::tag(
            'button',
            get_string('releaseall', 'local_datagrading'),
            [
                'id'        => 'local-datagrading-release-all',
                'class'     => 'btn btn-sm btn-secondary',
                'data-cmid' => $PAGE->cm->id,
            ]
        );
        $html .= \html_writer::end_div();

        $hook->add_html($html);
    }
}
