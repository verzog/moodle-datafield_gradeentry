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
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class data_field_gradeentry extends data_field_base {

    /** @var string Field type name. */
    public $type = 'gradeentry';

    /**
     * Whether this field supports import/export.
     * @return bool
     */
    public function supports_import() {
        return true;
    }

    /**
     * Render the field editing form elements for the field definition UI.
     */
    public function display_add_field($recordid = 0, $formdata = null) {
        global $DB, $OUTPUT;

        $value = '';
        if ($formdata) {
            $fieldname = 'field_' . $this->field->id;
            $value = $formdata->$fieldname ?? '';
        } else if ($recordid) {
            $content = $DB->get_record('data_content', [
                'fieldid' => $this->field->id,
                'recordid' => $recordid,
            ]);
            $value = $content ? $content->content : '';
        }

        $value = s($value);
        $fieldid = 'field_' . $this->field->id;

        $html  = '<div>';
        $html .= '<label for="' . $fieldid . '">';
        $html .= $this->field->name;
        if ($this->field->required) {
            $html .= ' <abbr class="initialism text-danger" title="' . get_string('required') . '">*</abbr>';
        }
        $html .= '</label>';
        $html .= '<input type="number" step="any" ';
        $html .= 'class="form-control" ';
        $html .= 'name="' . $fieldid . '" ';
        $html .= 'id="' . $fieldid . '" ';
        $html .= 'value="' . $value . '" ';

        $min = (string) $this->field->param1;
        $max = (string) $this->field->param2;
        if ($min !== '') {
            $html .= 'min="' . s($min) . '" ';
        }
        if ($max !== '') {
            $html .= 'max="' . s($max) . '" ';
        }
        $html .= '/>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the field for search UI.
     */
    public function display_search_field($value = '') {
        $fieldid = 'f_' . $this->field->id;
        $value   = s($value);

        return '<label for="' . $fieldid . '">' . $this->field->name . '</label>'
            . '<input type="number" step="any" class="form-control" '
            . 'name="' . $fieldid . '" id="' . $fieldid . '" value="' . $value . '" />';
    }

    /**
     * Pull the search value from the submitted form.
     */
    public function parse_search_field($defaults = null) {
        $param = 'f_' . $this->field->id;
        if (isset($defaults[$param])) {
            return $defaults[$param];
        }
        return optional_param($param, '', PARAM_RAW);
    }

    /**
     * Generate SQL WHERE clause for this field.
     */
    public function generate_sql($tablealias, $value) {
        global $DB;
        static $i = 0;
        $i++;
        $name = "df_gradeentry_{$i}";
        $value = (float) $value;
        return [
            " ({$tablealias}.fieldid = {$this->field->id} AND {$tablealias}.content = :{$name}) ",
            [$name => $value],
        ];
    }

    /**
     * Validate the user-submitted content for this field.
     */
    public function field_validation($value) {
        if ($value === '' || $value === null) {
            return true;
        }
        if (!is_numeric($value)) {
            return get_string('errornumeric', 'datafield_gradeentry');
        }
        $num = (float) $value;
        $min = (string) $this->field->param1;
        $max = (string) $this->field->param2;
        if ($min !== '' && $num < (float) $min) {
            return get_string('erroroutofrange', 'datafield_gradeentry', ['min' => $min, 'max' => $max]);
        }
        if ($max !== '' && $num > (float) $max) {
            return get_string('erroroutofrange', 'datafield_gradeentry', ['min' => $min, 'max' => $max]);
        }
        return true;
    }

    /**
     * Save submitted content for this field.
     */
    public function update_content($recordid, $value, $name = '') {
        global $DB;

        $msg = $this->field_validation($value);
        if ($msg !== true) {
            throw new moodle_exception('erroroutofrange', 'datafield_gradeentry');
        }

        $content = $DB->get_record('data_content', [
            'fieldid'  => $this->field->id,
            'recordid' => $recordid,
        ]);

        if ($content) {
            $content->content = ($value !== '') ? (float) $value : null;
            $DB->update_record('data_content', $content);
        } else {
            $content            = new stdClass();
            $content->fieldid   = $this->field->id;
            $content->recordid  = $recordid;
            $content->content   = ($value !== '') ? (float) $value : null;
            $DB->insert_record('data_content', $content);
        }
        return true;
    }

    /**
     * Display the stored content for this field.
     */
    public function display_browse_field($recordid, $template) {
        global $DB;

        $content = $DB->get_record('data_content', [
            'fieldid'  => $this->field->id,
            'recordid' => $recordid,
        ]);

        if (!$content || $content->content === null || $content->content === '') {
            return '';
        }

        $value   = (float) $content->content;
        $decimals = (int) ($this->field->param3 ?? 2);
        $formatted = number_format($value, $decimals);

        // Show as percentage if configured and max grade is set.
        if (!empty($this->field->param4) && $this->field->param2 !== '') {
            $max = (float) $this->field->param2;
            if ($max > 0) {
                $pct = number_format(($value / $max) * 100, 1);
                $formatted .= ' (' . $pct . '%)';
            }
        }

        return $formatted;
    }

    /**
     * Whether this field has data for the given record.
     */
    public function notemptyfield($value, $name) {
        return ($value !== '' && $value !== null);
    }

    /**
     * Text suitable for export.
     */
    public function export_text_value($record) {
        return $record->content ?? '';
    }

    /**
     * Render the field definition form (used in mod/data/field.php).
     */
    public function display_add_field_definition() {
        // Intentionally empty; field.php handles this via standard params.
    }

    /**
     * Return the options for the field definition edit form.
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
