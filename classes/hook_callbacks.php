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

namespace datafield_gradeentry;

/**
 * Hook callbacks for datafield_gradeentry.
 *
 * @package    datafield_gradeentry
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
        if (!has_capability('datafield/gradeentry:grade', $context)) {
            return;
        }

        $dataid = $PAGE->cm->instance;

        // Only render the grading UI when this Database activity actually has
        // a Grade entry field. Without this guard the progress bar and
        // release-all button would appear on every Database activity, where
        // they have no work to do and the release action would target an
        // empty grade metadata set.
        if (!$DB->record_exists('data_fields', ['dataid' => $dataid, 'type' => 'gradeentry'])) {
            return;
        }

        $total = $DB->count_records('data_records', ['dataid' => $dataid]);
        if ($total === 0) {
            return;
        }

        $graded = $DB->count_records_select(
            'datafield_gradeentry_grades',
            'dataid = :dataid AND graderid IS NOT NULL',
            ['dataid' => $dataid]
        );

        $PAGE->requires->js_call_amd('datafield_gradeentry/inline_grader', 'init', [
            $PAGE->cm->id,
            $context->id,
        ]);

        $html  = \html_writer::start_div(
            'datafield-gradeentry-progress alert alert-info d-flex align-items-center gap-3',
            [
                'id'          => 'datafield-gradeentry-progress',
                'data-cmid'   => $PAGE->cm->id,
                'data-graded' => $graded,
                'data-total'  => $total,
            ]
        );
        $html .= \html_writer::tag(
            'span',
            get_string('gradingprogress', 'datafield_gradeentry', ['graded' => $graded, 'total' => $total]),
            ['id' => 'datafield-gradeentry-progress-text', 'class' => 'me-auto']
        );
        $html .= \html_writer::tag(
            'button',
            get_string('releaseall', 'datafield_gradeentry'),
            [
                'id'        => 'datafield-gradeentry-release-all',
                'class'     => 'btn btn-sm btn-secondary',
                'data-cmid' => $PAGE->cm->id,
            ]
        );
        $html .= \html_writer::end_div();

        $hook->add_html($html);
    }
}
