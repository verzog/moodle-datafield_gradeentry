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
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

/**
 * A Database activity field type that records numeric grade values.
 *
 * Teachers grade entries via an inline panel in the browse view; students
 * see their released grade and feedback. Grades sync to the Moodle gradebook
 * via this plugin's grade_manager, which pushes a grade_item the first time
 * a teacher saves a grade.
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
     * Render a placeholder on the entry add/edit form.
     *
     * Grading is teacher-only, so students do not enter a value here; they
     * see a notice that the grade is pending. A hidden input is emitted so
     * mod_data still records this field as part of the submitted entry.
     *
     * @param int        $recordid  Existing record ID, or 0 for new entry.
     * @param mixed|null $formdata  Previously submitted form data (unused).
     * @return string  HTML fragment.
     */
    public function display_add_field($recordid = 0, $formdata = null) {
        $notice = get_string('awaitinggrade', 'datafield_gradeentry');
        return '<div class="gradeentry-pending text-muted small">' . s($notice) . '</div>'
            . '<input type="hidden" name="field_' . (int) $this->field->id . '" value="" />';
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

        $context = context_module::instance($this->cm->id);
        $isteacher = has_capability('datafield/gradeentry:grade', $context);

        $content = $DB->get_record('data_content', ['fieldid' => $this->field->id, 'recordid' => $recordid]);
        $graderaw = ($content && $content->content !== null && $content->content !== '')
            ? (float) $content->content : null;

        $meta = $DB->get_record('datafield_gradeentry_grades', [
            'dataid' => $this->field->dataid,
            'recordid' => $recordid,
        ]);

        $released = $meta ? (bool) $meta->released : false;
        $feedback = $meta ? (string) $meta->feedback : '';

        if ($isteacher) {
            return $this->render_teacher_panel($recordid, $graderaw, $feedback, $released);
        }

        return $this->render_student_view($graderaw, $feedback, $released);
    }

    /**
     * Render the full inline grading panel shown to teachers.
     *
     * @param int        $recordid  Database record ID.
     * @param float|null $graderaw  Current grade value, or null if ungraded.
     * @param string     $feedback  Existing feedback text.
     * @param bool       $released  Whether the grade is visible to the student.
     * @return string  HTML fragment.
     */
    private function render_teacher_panel(int $recordid, ?float $graderaw, string $feedback, bool $released): string {
        $fieldid = $this->field->id;
        $min = s($this->field->param1 ?? '');
        $max = s($this->field->param2 ?? '');
        $decimals = (int) ($this->field->param3 ?? 2);
        $value = ($graderaw !== null) ? number_format($graderaw, $decimals, '.', '') : '';
        $maxlabel = ($max !== '') ? ' / ' . $max : '';

        $releasedtext = get_string('gradedreleased', 'datafield_gradeentry');
        $unreleasedtext = get_string('gradenotreleased', 'datafield_gradeentry');
        $feedbacklabel = get_string('feedbacklabel', 'datafield_gradeentry');
        $gradelabel = get_string('grade', 'datafield_gradeentry');

        $html = '<div class="gradeentry-teacher-panel">';

        $html .= '<div class="gradeentry-input-group d-flex align-items-center gap-2 mb-2">';
        $html .= '<label class="visually-hidden" for="gradeentry-' . $recordid . '-' . $fieldid . '">';
        $html .= $gradelabel . '</label>';
        $html .= '<input type="number" step="any"';
        $html .= ' id="gradeentry-' . $recordid . '-' . $fieldid . '"';
        $html .= ' class="form-control form-control-sm gradeentry-grade-input" style="width:7rem"';
        $html .= ' data-gradeentry-field';
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

        $html .= '<div class="gradeentry-release-group">';
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

        $html .= '</div>';

        return $html;
    }

    /**
     * Render the student-facing grade and feedback view.
     *
     * @param float|null $graderaw  The stored grade, or null if ungraded.
     * @param string     $feedback  Teacher feedback text.
     * @param bool       $released  Whether the grade has been released.
     * @return string  HTML fragment.
     */
    private function render_student_view(?float $graderaw, string $feedback, bool $released): string {
        if (!$released || $graderaw === null) {
            return '<span class="text-muted">' . get_string('gradepending', 'datafield_gradeentry') . '</span>';
        }

        $decimals = (int) ($this->field->param3 ?? 2);
        $formatted = number_format($graderaw, $decimals);
        $max = (string) ($this->field->param2 ?? '');

        if ($max !== '') {
            $formatted .= ' / ' . s($max);
        }

        if (!empty($this->field->param4) && $max !== '' && (float) $max > 0) {
            $pct = number_format(($graderaw / (float) $max) * 100, 1);
            $formatted .= ' (' . $pct . '%)';
        }

        $html = '<div class="gradeentry-student-view">';
        $html .= '<strong>' . $formatted . '</strong>';
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
        $value = s($value);
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
        $name = "df_gradeentry_{$i}";
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
     * mod_data's data_process_submission() treats ANY truthy return from
     * field_validation() as an error message and surfaces it as a
     * notification, blocking the entry save. The base class returns
     * false to signal 'no error' - so this method must return a falsy
     * value, not true.
     *
     * Returns false unconditionally: the add-entry form never accepts a
     * teacher-set value (display_add_field() emits only a hidden empty
     * input), and teacher grading happens via the inline AJAX panel -
     * which has its own bounds checking - not through the standard
     * entry-form submit. There is nothing for this hook to legitimately
     * reject.
     *
     * @param mixed $value  The submitted value (ignored).
     * @return false
     */
    public function field_validation($value) {
        return false;
    }

    /**
     * Persist a grade value to data_content (standard form submission path).
     *
     * @param int    $recordid  Database record ID.
     * @param mixed  $value     Grade value to store.
     * @param string $name      Field name (unused).
     * @return bool
     */
    public function update_content($recordid, $value, $name = '') {
        global $DB;

        // Students submit an empty value via the hidden input in display_add_field();
        // leave any teacher-saved grade in place rather than wiping it. Non-numeric
        // values cannot come from the teacher AJAX path either, so silently ignore.
        if ($value === '' || $value === null || !is_numeric($value)) {
            return true;
        }

        $num = (float) $value;
        $min = (string) ($this->field->param1 ?? '');
        $max = (string) ($this->field->param2 ?? '');
        if (($min !== '' && $num < (float) $min) || ($max !== '' && $num > (float) $max)) {
            // Out-of-range values from non-UI callers (CSV import, etc.) are ignored
            // rather than blowing up the surrounding entry-save transaction.
            return true;
        }

        $content = $DB->get_record('data_content', ['fieldid' => $this->field->id, 'recordid' => $recordid]);
        if ($content) {
            $content->content = $num;
            $DB->update_record('data_content', $content);
        } else {
            $DB->insert_record('data_content', (object) [
                'fieldid' => $this->field->id,
                'recordid' => $recordid,
                'content' => $num,
            ]);
        }
        return true;
    }

    /**
     * Return true when the field contains a non-empty value.
     *
     * Always returns false: this field is never student-supplied, so the
     * 'required field' check must not block student submissions.
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
        ];
    }
}
