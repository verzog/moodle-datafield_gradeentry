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
 * via the companion local_datagrading plugin.
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
     * Hide this field from student add/edit forms — grading is teacher-only.
     *
     * @param int        $recordid  Existing record ID, or 0 for new entry.
     * @param mixed|null $formdata  Previously submitted form data.
     * @return string  Always empty; the field is never shown on the add form.
     */
    public function display_add_field($recordid = 0, $formdata = null) {
        return '';
    }

    /**
     * Render the field for the browse (list/single) view.
     *
     * Teachers see an inline grading panel (grade input, feedback textarea,
     * release toggle) wired up by local_datagrading/inline_grader.
     * Students see their released grade and feedback, or a pending notice.
     *
     * @param int    $recordid  Database record ID.
     * @param string $template  Template context (unused).
     * @return string  HTML fragment.
     */
    public function display_browse_field($recordid, $template) {
        global $DB;

        $context = context_module::instance($this->cm->id);
        $isteacher = has_capability('local/datagrading:grade', $context);

        $content = $DB->get_record('data_content', ['fieldid' => $this->field->id, 'recordid' => $recordid]);
        $graderaw = ($content && $content->content !== null && $content->content !== '')
            ? (float) $content->content : null;

        $meta = null;
        if (\core_component::get_component_directory('local_datagrading')) {
            $meta = $DB->get_record('local_datagrading_grades', [
                'dataid' => $this->field->dataid,
                'recordid' => $recordid,
            ]);
        }

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

        $releasedtext = get_string('gradedreleased', 'local_datagrading');
        $unreleasedtext = get_string('gradenotreleased', 'local_datagrading');
        $feedbacklabel = get_string('feedbacklabel', 'local_datagrading');
        $gradelabel = get_string('grade', 'local_datagrading');

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
            return '<span class="text-muted">' . get_string('gradepending', 'local_datagrading') . '</span>';
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
     * @return array{string, array}  [sql, params].
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
     * Validate a submitted grade value against the field's configured bounds.
     *
     * @param mixed $value  The submitted value.
     * @return true|string  True on success, an error string on failure.
     */
    public function field_validation($value) {
        if ($value === '' || $value === null) {
            return true;
        }
        if (!is_numeric($value)) {
            return get_string('errornumeric', 'datafield_gradeentry');
        }
        $num = (float) $value;
        $min = (string) ($this->field->param1 ?? '');
        $max = (string) ($this->field->param2 ?? '');
        if ($min !== '' && $num < (float) $min) {
            return get_string('erroroutofrange', 'datafield_gradeentry', ['min' => $min, 'max' => $max]);
        }
        if ($max !== '' && $num > (float) $max) {
            return get_string('erroroutofrange', 'datafield_gradeentry', ['min' => $min, 'max' => $max]);
        }
        return true;
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

        $msg = $this->field_validation($value);
        if ($msg !== true) {
            throw new moodle_exception('erroroutofrange', 'datafield_gradeentry');
        }

        $content = $DB->get_record('data_content', ['fieldid' => $this->field->id, 'recordid' => $recordid]);
        if ($content) {
            $content->content = ($value !== '') ? (float) $value : null;
            $DB->update_record('data_content', $content);
        } else {
            $DB->insert_record('data_content', (object) [
                'fieldid' => $this->field->id,
                'recordid' => $recordid,
                'content' => ($value !== '') ? (float) $value : null,
            ]);
        }
        return true;
    }

    /**
     * Return true when the field contains a non-empty value.
     *
     * @param mixed  $value  Field value.
     * @param string $name   Field name (unused).
     * @return bool
     */
    public function notemptyfield($value, $name) {
        return ($value !== '' && $value !== null);
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
     * @return array<string,string>  Map of param key to display label.
     */
    public function get_field_params() {
        return [
            'param1' => get_string('mingrade', 'datafield_gradeentry'),
            'param2' => get_string('maxgrade', 'datafield_gradeentry'),
            'param3' => get_string('decimals', 'datafield_gradeentry'),
            'param4' => get_string('showaspercentage', 'datafield_gradeentry'),
        ];
    }
}
