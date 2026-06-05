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
 * Grade entry field class for the Moodle Database activity.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    {@link https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later}
 */

use datafield_gradeentry\grade_manager;

/**
 * A Database activity field type that records numeric grade values.
 *
 * Teachers grade entries via an inline panel in the browse view; students
 * see their released grade and feedback. Grades sync to the Moodle gradebook
 * via this plugin's grade_manager, which pushes a grade_item the first time
 * a teacher saves a grade.
 *
 * Field parameter slots:
 *  param1 - Minimum grade (numeric)
 *  param2 - Maximum grade (numeric)
 *  param3 - Decimal places to display
 *  param4 - Show as percentage (boolean)
 *  param5 - Grading method: 'numeric' (default), 'scale', 'rubric'
 *  param6 - Scale ID (when param5 = 'scale')
 *  param7 - Rubric criteria JSON (when param5 = 'rubric')
 */
class data_field_gradeentry extends data_field_base {
    /** @var string Field type identifier. */
    public $type = 'gradeentry';

    /**
     * Declare support for CSV import.
     *
     * @return bool
     */
    public function supports_import() {
        return true;
    }

    /**
     * Render the submission status selector on the entry add/edit form.
     *
     * Students choose whether to save as a draft or submit for grading.
     * The value of the radio group is submitted as field_{fieldid} and
     * routed to update_content(), which persists the status.
     *
     * @param int        $recordid  Existing record ID, or 0 for new entry.
     * @param mixed|null $formdata  Previously submitted form data (unused).
     * @return string  HTML fragment.
     */
    public function display_add_field($recordid = 0, $formdata = null) {
        global $DB;

        // Determine the current status so we can pre-select the right radio.
        $currentstatus = grade_manager::STATUS_DRAFT;
        if ($recordid > 0) {
            $meta = $DB->get_record('datafield_gradeentry_grades', [
                'dataid'   => $this->field->dataid,
                'recordid' => $recordid,
            ]);
            if ($meta) {
                $currentstatus = $meta->submission_status;
                // If teacher requires resubmission, pre-select "submitted" so
                // the student's next save counts as a fresh submission.
                if ($meta->requireresubmission) {
                    $currentstatus = grade_manager::STATUS_SUBMITTED;
                }
            }
        }

        $fieldname = 'field_' . (int) $this->field->id;
        $draftlabel  = get_string('saveasdraft', 'datafield_gradeentry');
        $submitlabel = get_string('submitforgrading', 'datafield_gradeentry');

        $html  = '<div class="gradeentry-submission-control mb-2">';
        $html .= '<div class="gradeentry-status-label small text-muted mb-1">'
            . get_string('submissionstatus', 'datafield_gradeentry') . '</div>';

        $html .= '<div class="d-flex gap-3">';

        // Draft radio.
        $draftchecked = ($currentstatus === grade_manager::STATUS_DRAFT) ? ' checked' : '';
        $html .= '<div class="form-check">';
        $html .= '<input type="radio" class="form-check-input"';
        $html .= ' id="gradeentry-draft-' . (int) $this->field->id . '"';
        $html .= ' name="' . $fieldname . '" value="draft"' . $draftchecked . ' />';
        $html .= '<label class="form-check-label" for="gradeentry-draft-' . (int) $this->field->id . '">';
        $html .= s($draftlabel) . '</label>';
        $html .= '</div>';

        // Submit radio.
        $submitchecked = ($currentstatus === grade_manager::STATUS_SUBMITTED) ? ' checked' : '';
        $html .= '<div class="form-check">';
        $html .= '<input type="radio" class="form-check-input"';
        $html .= ' id="gradeentry-submit-' . (int) $this->field->id . '"';
        $html .= ' name="' . $fieldname . '" value="submitted"' . $submitchecked . ' />';
        $html .= '<label class="form-check-label" for="gradeentry-submit-' . (int) $this->field->id . '">';
        $html .= s($submitlabel) . '</label>';
        $html .= '</div>';

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the field for the browse (list/single) view.
     *
     * @param int    $recordid  Database record ID.
     * @param string $template  Template context (unused).
     * @return string  HTML fragment.
     */
    public function display_browse_field($recordid, $template) {
        global $DB;

        $context  = context_module::instance($this->cm->id);
        $isteacher = has_capability('datafield/gradeentry:grade', $context);

        $content  = $DB->get_record('data_content', ['fieldid' => $this->field->id, 'recordid' => $recordid]);
        $graderaw = ($content && $content->content !== null && $content->content !== '')
            ? (float) $content->content : null;

        $meta = $DB->get_record('datafield_gradeentry_grades', [
            'dataid'   => $this->field->dataid,
            'recordid' => $recordid,
        ]);

        $released   = $meta ? (bool) $meta->released : false;
        $feedback   = $meta ? (string) $meta->feedback : '';
        $status     = $meta ? (string) $meta->submission_status : grade_manager::STATUS_NOTSUBMITTED;
        $resubmit   = $meta ? (bool) $meta->requireresubmission : false;
        $rubricscores = ($meta && $meta->rubric_scores) ? $meta->rubric_scores : null;

        if ($isteacher) {
            return $this->render_teacher_panel($recordid, $graderaw, $feedback, $released, $status, $resubmit, $rubricscores);
        }

        return $this->render_student_view($graderaw, $feedback, $released, $status, $resubmit, $rubricscores);
    }

    /**
     * Render the full inline grading panel shown to teachers.
     *
     * @param int         $recordid     Database record ID.
     * @param float|null  $graderaw     Current grade value, or null if ungraded.
     * @param string      $feedback     Existing feedback text.
     * @param bool        $released     Whether the grade is visible to the student.
     * @param string      $status       Student's current submission status.
     * @param bool        $resubmit     Whether resubmission is currently required.
     * @param string|null $rubricscores JSON per-criterion scores, or null.
     * @return string  HTML fragment.
     */
    private function render_teacher_panel(
        int $recordid,
        ?float $graderaw,
        string $feedback,
        bool $released,
        string $status,
        bool $resubmit,
        ?string $rubricscores
    ): string {
        $fieldid = $this->field->id;
        $method  = (string) ($this->field->param5 ?? grade_manager::METHOD_NUMERIC);

        $html = '<div class="gradeentry-teacher-panel">';

        // Submission status badge.
        $html .= $this->render_submission_status_badge($recordid, $status);

        // Grade input: varies by method.
        if ($method === grade_manager::METHOD_SCALE) {
            $html .= $this->render_scale_input($recordid, $fieldid, $graderaw);
        } else if ($method === grade_manager::METHOD_RUBRIC) {
            $html .= $this->render_rubric_panel($recordid, $fieldid, $graderaw, $rubricscores);
        } else {
            $html .= $this->render_numeric_input($recordid, $fieldid, $graderaw);
        }

        // Feedback textarea.
        $feedbacklabel = get_string('feedbacklabel', 'datafield_gradeentry');
        $html .= '<div class="gradeentry-feedback-group mb-2">';
        $html .= '<label class="form-label small mb-1" for="gradeentry-feedback-' . $recordid . '">';
        $html .= $feedbacklabel . '</label>';
        $html .= '<textarea';
        $html .= ' id="gradeentry-feedback-' . $recordid . '"';
        $html .= ' class="form-control form-control-sm"';
        $html .= ' rows="2"';
        $html .= ' data-gradeentry-feedback';
        $html .= ' data-recordid="' . $recordid . '"';
        $html .= '>' . s($feedback) . '</textarea>';
        $html .= '</div>';

        // Release checkbox.
        $releasedtext   = get_string('gradedreleased', 'datafield_gradeentry');
        $unreleasedtext = get_string('gradenotreleased', 'datafield_gradeentry');
        $html .= '<div class="gradeentry-release-group mb-2">';
        $html .= '<div class="form-check form-check-inline">';
        $html .= '<input type="checkbox" class="form-check-input"';
        $html .= ' id="gradeentry-release-' . $recordid . '"';
        $html .= ' data-gradeentry-release';
        $html .= ' data-recordid="' . $recordid . '"';
        if ($released) {
            $html .= ' checked';
        }
        $html .= ' />';
        $html .= '<label class="form-check-label small gradeentry-release-label"';
        $html .= ' for="gradeentry-release-' . $recordid . '"';
        $html .= ' data-released-text="' . s($releasedtext) . '"';
        $html .= ' data-unreleased-text="' . s($unreleasedtext) . '">';
        $html .= $released ? $releasedtext : $unreleasedtext;
        $html .= '</label>';
        $html .= '</div>';
        $html .= '</div>';

        // Require resubmission control.
        $html .= $this->render_resubmission_control($recordid, $resubmit);

        $html .= '</div>';

        return $html;
    }

    /**
     * Render the numeric grade input (original grading method).
     *
     * @param int        $recordid  Database record ID.
     * @param int        $fieldid   Grade entry field ID.
     * @param float|null $graderaw  Current grade, or null if ungraded.
     * @return string  HTML fragment.
     */
    private function render_numeric_input(int $recordid, int $fieldid, ?float $graderaw): string {
        $min      = s($this->field->param1 ?? '');
        $max      = s($this->field->param2 ?? '');
        $decimals = (int) ($this->field->param3 ?? 2);
        $value    = ($graderaw !== null) ? number_format($graderaw, $decimals, '.', '') : '';
        $maxlabel = ($max !== '') ? ' / ' . $max : '';

        $gradelabel = get_string('grade', 'datafield_gradeentry');

        $html  = '<div class="gradeentry-input-group d-flex align-items-center gap-2 mb-2">';
        $html .= '<label class="visually-hidden" for="gradeentry-' . $recordid . '-' . $fieldid . '">';
        $html .= $gradelabel . '</label>';
        $html .= '<input type="number" step="any"';
        $html .= ' id="gradeentry-' . $recordid . '-' . $fieldid . '"';
        $html .= ' class="form-control form-control-sm gradeentry-grade-input" style="width:7rem"';
        $html .= ' data-gradeentry-field';
        $html .= ' data-grading-method="numeric"';
        $html .= ' data-recordid="' . $recordid . '"';
        $html .= ' data-fieldid="' . $fieldid . '"';
        $html .= ' value="' . $value . '"';
        if ($min !== '') {
            $html .= ' min="' . $min . '"';
        }
        if ($max !== '') {
            $html .= ' max="' . $max . '"';
        }
        $html .= ' />';
        if ($maxlabel !== '') {
            $html .= '<span class="text-muted small">' . s($maxlabel) . '</span>';
        }
        $html .= '<span class="gradeentry-status small text-muted"';
        $html .= ' data-gradeentry-status data-recordid="' . $recordid . '" aria-live="polite"></span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a scale dropdown for scale grading method.
     *
     * @param int        $recordid  Database record ID.
     * @param int        $fieldid   Grade entry field ID.
     * @param float|null $graderaw  Current grade as 1-based scale index, or null.
     * @return string  HTML fragment.
     */
    private function render_scale_input(int $recordid, int $fieldid, ?float $graderaw): string {
        $scaleid = (int) ($this->field->param6 ?? 0);
        $items   = $scaleid > 0 ? grade_manager::get_scale_items($scaleid) : [];
        $current = ($graderaw !== null) ? (int) $graderaw : 0;

        $gradelabel = get_string('grade', 'datafield_gradeentry');

        $html  = '<div class="gradeentry-input-group d-flex align-items-center gap-2 mb-2">';
        $html .= '<label class="visually-hidden" for="gradeentry-' . $recordid . '-' . $fieldid . '">';
        $html .= $gradelabel . '</label>';
        $html .= '<select';
        $html .= ' id="gradeentry-' . $recordid . '-' . $fieldid . '"';
        $html .= ' class="form-select form-select-sm gradeentry-grade-input" style="width:auto"';
        $html .= ' data-gradeentry-field';
        $html .= ' data-grading-method="scale"';
        $html .= ' data-recordid="' . $recordid . '"';
        $html .= ' data-fieldid="' . $fieldid . '"';
        $html .= '>';
        $html .= '<option value="">— ' . get_string('grade', 'datafield_gradeentry') . ' —</option>';
        foreach ($items as $idx => $item) {
            $value    = $idx + 1; // 1-based.
            $selected = ($current === $value) ? ' selected' : '';
            $html .= '<option value="' . $value . '"' . $selected . '>' . s($item) . '</option>';
        }
        $html .= '</select>';
        $html .= '<span class="gradeentry-status small text-muted"';
        $html .= ' data-gradeentry-status data-recordid="' . $recordid . '" aria-live="polite"></span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a rubric grading panel with criteria and level buttons.
     *
     * @param int         $recordid     Database record ID.
     * @param int         $fieldid      Grade entry field ID.
     * @param float|null  $graderaw     Current total rubric score, or null.
     * @param string|null $rubricscores JSON per-criterion scores, or null.
     * @return string  HTML fragment.
     */
    private function render_rubric_panel(
        int $recordid,
        int $fieldid,
        ?float $graderaw,
        ?string $rubricscores
    ): string {
        $criteriajson = (string) ($this->field->param7 ?? '');
        $criteria = [];
        if ($criteriajson !== '') {
            $criteria = json_decode($criteriajson, true) ?? [];
        }

        if (empty($criteria)) {
            return '<div class="alert alert-warning small p-2">'
                . 'Rubric criteria are not configured for this field.'
                . '</div>';
        }

        $savedscores = [];
        if ($rubricscores) {
            $savedscores = json_decode($rubricscores, true) ?? [];
        }

        $html  = '<div class="gradeentry-rubric-panel mb-2"';
        $html .= ' data-gradeentry-rubric';
        $html .= ' data-recordid="' . $recordid . '"';
        $html .= ' data-fieldid="' . $fieldid . '">';

        // Hidden input holds the computed total (read by triggerSave in JS).
        $html .= '<input type="hidden"';
        $html .= ' data-gradeentry-field';
        $html .= ' data-grading-method="rubric"';
        $html .= ' data-recordid="' . $recordid . '"';
        $html .= ' data-fieldid="' . $fieldid . '"';
        $html .= ' value="' . ($graderaw !== null ? $graderaw : '') . '" />';

        foreach ($criteria as $cidx => $criterion) {
            $criterionname = s($criterion['name'] ?? 'Criterion ' . ($cidx + 1));
            $levels = $criterion['levels'] ?? [];
            $savedscore = isset($savedscores[$cidx]) ? (float) $savedscores[$cidx] : null;

            $html .= '<div class="gradeentry-criterion mb-2" data-criterion-index="' . $cidx . '">';
            $html .= '<div class="small fw-semibold mb-1">' . $criterionname . '</div>';
            $html .= '<div class="d-flex flex-wrap gap-1">';

            foreach ($levels as $level) {
                $score = (float) ($level['score'] ?? 0);
                $desc = s($level['desc'] ?? '');
                $isselected = ($savedscore !== null && (float) $savedscore === $score);
                $btnextra = $isselected ? ' btn-primary active' : ' btn-outline-secondary';
                $btnclass = 'btn btn-sm gradeentry-rubric-level' . $btnextra;

                $html .= '<button type="button"';
                $html .= ' class="' . $btnclass . '"';
                $html .= ' data-score="' . $score . '"';
                $html .= ' title="' . $desc . '">';
                $html .= $desc . ' (' . $score . ')';
                $html .= '</button>';
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '<div class="d-flex align-items-center gap-2 mt-1">';
        $html .= '<span class="small text-muted gradeentry-rubric-total">';
        $html .= get_string('rubrictotal', 'datafield_gradeentry', $graderaw ?? 0);
        $html .= '</span>';
        $html .= '<span class="gradeentry-status small text-muted"';
        $html .= ' data-gradeentry-status data-recordid="' . $recordid . '" aria-live="polite"></span>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a submission status badge for the teacher panel.
     *
     * @param int    $recordid  Database record ID.
     * @param string $status    One of the grade_manager::STATUS_* constants.
     * @return string  HTML fragment.
     */
    private function render_submission_status_badge(int $recordid, string $status): string {
        $badges = [
            grade_manager::STATUS_NOTSUBMITTED => ['text-bg-secondary', 'submissionnotsubmitted'],
            grade_manager::STATUS_DRAFT => ['text-bg-warning', 'submissiondraft'],
            grade_manager::STATUS_SUBMITTED => ['text-bg-success', 'submissionsubmitted'],
            grade_manager::STATUS_RESUBMIT => ['text-bg-danger', 'submissionresubmit'],
        ];

        $config = $badges[$status] ?? $badges[grade_manager::STATUS_NOTSUBMITTED];
        $label  = get_string($config[1], 'datafield_gradeentry');

        return '<div class="mb-2">'
            . '<span class="badge ' . $config[0] . ' gradeentry-submission-badge"'
            . ' data-gradeentry-submission-badge data-recordid="' . $recordid . '">'
            . s($label)
            . '</span>'
            . '</div>';
    }

    /**
     * Render the require-resubmission control for the teacher panel.
     *
     * @param int  $recordid  Database record ID.
     * @param bool $resubmit  Whether resubmission is currently required.
     * @return string  HTML fragment.
     */
    private function render_resubmission_control(int $recordid, bool $resubmit): string {
        $requirelabel = get_string('requireresubmission', 'datafield_gradeentry');
        $confirmtext  = get_string('requireresubmission_confirm', 'datafield_gradeentry');

        $html  = '<div class="gradeentry-resubmission-group mt-2">';
        $html .= '<div class="form-check form-check-inline">';
        $html .= '<input type="checkbox" class="form-check-input"';
        $html .= ' id="gradeentry-resubmit-' . $recordid . '"';
        $html .= ' data-gradeentry-resubmit';
        $html .= ' data-recordid="' . $recordid . '"';
        $html .= ' data-confirm="' . s($confirmtext) . '"';
        if ($resubmit) {
            $html .= ' checked';
        }
        $html .= ' />';
        $html .= '<label class="form-check-label small text-warning-emphasis"';
        $html .= ' for="gradeentry-resubmit-' . $recordid . '">';
        $html .= s($requirelabel);
        $html .= '</label>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the student-facing grade and feedback view.
     *
     * @param float|null $graderaw  The stored grade, or null if ungraded.
     * @param string     $feedback  Teacher feedback text.
     * @param bool       $released  Whether the grade has been released.
     * @param string     $status    Student's current submission status.
     * @param bool       $resubmit  Whether teacher requires resubmission.
     * @return string  HTML fragment.
     */
    private function render_student_view(
        ?float $graderaw,
        string $feedback,
        bool $released,
        string $status,
        bool $resubmit,
        ?string $rubricscores = null
    ): string {
        $html = '<div class="gradeentry-student-view">';

        // Resubmission required banner takes priority.
        if ($resubmit) {
            $html .= '<div class="alert alert-warning py-2 px-3 mb-2 small">';
            $html .= get_string('resubmissionrequired_student', 'datafield_gradeentry');
            $html .= '</div>';
        }

        // Submission status line.
        $statusstrings = [
            grade_manager::STATUS_NOTSUBMITTED => 'submissionnotsubmitted',
            grade_manager::STATUS_DRAFT        => 'submissiondraft',
            grade_manager::STATUS_SUBMITTED    => 'submissionsubmitted',
            grade_manager::STATUS_RESUBMIT     => 'submissionresubmit',
        ];
        $statuskey = $statusstrings[$status] ?? 'submissionnotsubmitted';
        $html .= '<div class="text-muted small mb-1">'
            . get_string('submissionstatus', 'datafield_gradeentry') . ': '
            . '<strong>' . get_string($statuskey, 'datafield_gradeentry') . '</strong>'
            . '</div>';

        // Grade display.
        if (!$released || $graderaw === null) {
            $html .= '<span class="text-muted">' . get_string('gradepending', 'datafield_gradeentry') . '</span>';
            $html .= '</div>';
            return $html;
        }

        $method   = (string) ($this->field->param5 ?? grade_manager::METHOD_NUMERIC);
        $decimals = (int) ($this->field->param3 ?? 2);

        if ($method === grade_manager::METHOD_SCALE) {
            $scaleid = (int) ($this->field->param6 ?? 0);
            $items   = $scaleid > 0 ? grade_manager::get_scale_items($scaleid) : [];
            $idx     = (int) $graderaw - 1;
            $formatted = isset($items[$idx]) ? s($items[$idx]) : (string) $graderaw;
        } else {
            $formatted = number_format($graderaw, $decimals);
            $max       = (string) ($this->field->param2 ?? '');
            if ($max !== '') {
                $formatted .= ' / ' . s($max);
            }
            if (!empty($this->field->param4) && $max !== '' && (float) $max > 0) {
                $pct = number_format(($graderaw / (float) $max) * 100, 1);
                $formatted .= ' (' . $pct . '%)';
            }
        }

        $html .= '<strong>' . $formatted . '</strong>';

        // Rubric breakdown: show per-criterion scores when available.
        if ($method === grade_manager::METHOD_RUBRIC && $rubricscores !== null) {
            $criteriajson = (string) ($this->field->param7 ?? '');
            $criteria     = $criteriajson !== '' ? (json_decode($criteriajson, true) ?? []) : [];
            $savedscores  = json_decode($rubricscores, true) ?? [];

            if (!empty($criteria)) {
                $html .= '<dl class="gradeentry-rubric-breakdown mt-2 mb-1 small">';
                foreach ($criteria as $cidx => $criterion) {
                    $criterionname = s($criterion['name'] ?? 'Criterion ' . ($cidx + 1));
                    $levels        = $criterion['levels'] ?? [];
                    $savedscore    = isset($savedscores[$cidx]) ? (float) $savedscores[$cidx] : null;

                    // Find the matching level description for the saved score.
                    $leveldesc = '';
                    foreach ($levels as $level) {
                        if ($savedscore !== null && (float) ($level['score'] ?? 0) === $savedscore) {
                            $leveldesc = s($level['desc'] ?? '');
                            break;
                        }
                    }

                    $html .= '<div class="gradeentry-rubric-criterion-row d-flex gap-2 mb-1">';
                    $html .= '<dt class="fw-semibold mb-0">' . $criterionname . '</dt>';
                    $html .= '<dd class="mb-0 text-muted">';
                    if ($savedscore !== null) {
                        $html .= ($leveldesc !== '' ? $leveldesc . ' ' : '') . '(' . $savedscore . ')';
                    } else {
                        $html .= '—';
                    }
                    $html .= '</dd>';
                    $html .= '</div>';
                }
                $html .= '</dl>';
            }
        }

        if ($feedback !== '') {
            $html .= '<p class="mt-1 text-muted small">' . format_text($feedback, FORMAT_MOODLE) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render the search input for this field.
     *
     * @param mixed $value  Current search value.
     * @return string  HTML fragment.
     */
    public function display_search_field($value = '') {
        $fieldid = 'f_' . $this->field->id;
        $value   = s($value);
        return '<label for="' . $fieldid . '">' . $this->field->name . '</label>'
            . '<input type="number" step="any" class="form-control" '
            . 'name="' . $fieldid . '" id="' . $fieldid . '" value="' . $value . '" />';
    }

    /**
     * Extract the submitted search value from form data or request parameters.
     *
     * @param array|null $defaults  Pre-populated defaults.
     * @return mixed  The search value.
     */
    public function parse_search_field($defaults = null) {
        $param = 'f_' . $this->field->id;
        if (isset($defaults[$param])) {
            return $defaults[$param];
        }
        return optional_param($param, '', PARAM_RAW);
    }

    /**
     * Build a SQL WHERE fragment for searching this field.
     *
     * @param string $tablealias  Alias for the data_content table.
     * @param mixed  $value       The search value.
     * @return array  [sql, params].
     */
    public function generate_sql($tablealias, $value) {
        global $DB;
        static $i = 0;
        $i++;
        $name  = "df_gradeentry_{$i}";
        $value = (string) (float) $value;
        return [
            " ({$tablealias}.fieldid = {$this->field->id}"
                . " AND " . $DB->sql_compare_text("{$tablealias}.content") . " = :{$name}) ",
            [$name => $value],
        ];
    }

    /**
     * Form-submit validation hook.
     *
     * Returns false unconditionally: grade values are set by teachers via
     * the inline AJAX panel, and submission status values ('draft',
     * 'submitted') are handled without error in update_content().
     *
     * @param mixed $value  The submitted value (ignored).
     * @return false
     */
    public function field_validation($value) {
        return false;
    }

    /**
     * Persist the submitted field value.
     *
     * When the submitted value is 'draft' or 'submitted', update the
     * submission status row. Numeric values are stored as grade content
     * (CSV import / teacher grading path). Empty and non-numeric values
     * that are not status strings are silently ignored.
     *
     * @param int    $recordid  Database record ID.
     * @param mixed  $value     Submitted value.
     * @param string $name      Field name (unused).
     * @return bool
     */
    public function update_content($recordid, $value, $name = '') {
        global $DB, $USER;

        $strvalue = (string) $value;

        // Student submission status submitted via the radio buttons.
        if ($strvalue === 'draft' || $strvalue === 'submitted') {
            $record = $DB->get_record('data_records', ['id' => $recordid], 'id, dataid, userid', MUST_EXIST);
            grade_manager::update_submission_status(
                (int) $record->dataid,
                $recordid,
                (int) $record->userid,
                $strvalue
            );
            return true;
        }

        // Numeric grade from CSV import or other non-AJAX callers.
        if ($value === '' || $value === null || !is_numeric($value)) {
            return true;
        }

        $num = (float) $value;
        $min = (string) ($this->field->param1 ?? '');
        $max = (string) ($this->field->param2 ?? '');
        if (($min !== '' && $num < (float) $min) || ($max !== '' && $num > (float) $max)) {
            return true;
        }

        $content = $DB->get_record('data_content', ['fieldid' => $this->field->id, 'recordid' => $recordid]);
        if ($content) {
            $content->content = $num;
            $DB->update_record('data_content', $content);
        } else {
            $DB->insert_record('data_content', (object) [
                'fieldid'  => $this->field->id,
                'recordid' => $recordid,
                'content'  => $num,
            ]);
        }
        return true;
    }

    /**
     * Return true when the field contains a non-empty value.
     *
     * Always returns false so the 'required field' check never blocks
     * student submissions (grading is teacher-driven).
     *
     * @param mixed  $value  Field value (ignored).
     * @param string $name   Field name (unused).
     * @return bool
     */
    public function notemptyfield($value, $name) {
        return false;
    }

    /**
     * Return a plain-text representation suitable for CSV export.
     *
     * @param stdClass $record  Content record from data_content.
     * @return string
     */
    public function export_text_value($record) {
        return $record->content ?? '';
    }

    /**
     * Return human-readable labels for each param slot used by this field.
     *
     * @return array  Map of param key to display label.
     */
    public function get_field_params(): array {
        return [
            'param1' => get_string('mingrade', 'datafield_gradeentry'),
            'param2' => get_string('maxgrade', 'datafield_gradeentry'),
            'param3' => get_string('decimals', 'datafield_gradeentry'),
            'param4' => get_string('showaspercentage', 'datafield_gradeentry'),
            'param5' => get_string('gradingmethod', 'datafield_gradeentry'),
            'param6' => get_string('scaleid', 'datafield_gradeentry'),
            'param7' => get_string('rubriccriteria', 'datafield_gradeentry'),
        ];
    }
}
