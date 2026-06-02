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

namespace datafield_gradeentry\tests;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/data/field/gradeentry/field.class.php');

/**
 * Unit tests for data_field_gradeentry.
 *
 * @covers \data_field_gradeentry
 */
class field_test extends \advanced_testcase {

    /** @var \stdClass Field stub. */
    private \stdClass $fieldrecord;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->fieldrecord = (object) [
            'id'       => 1,
            'dataid'   => 1,
            'type'     => 'gradeentry',
            'name'     => 'Test grade',
            'description' => '',
            'required' => 0,
            'param1'   => '0',   // min
            'param2'   => '100', // max
            'param3'   => '2',   // decimals
            'param4'   => '',    // show as percentage
        ];
    }

    /**
     * Helper to instantiate the field with a stub field record.
     */
    private function make_field(\stdClass $overrides = null): \data_field_gradeentry {
        $rec = clone $this->fieldrecord;
        if ($overrides) {
            foreach ((array) $overrides as $k => $v) {
                $rec->$k = $v;
            }
        }
        // data_field_base constructor accepts (field, data, cm).
        return new \data_field_gradeentry($rec);
    }

    public function test_valid_integer(): void {
        $field = $this->make_field();
        $this->assertTrue($field->field_validation('75') === true);
    }

    public function test_valid_decimal(): void {
        $field = $this->make_field();
        $this->assertTrue($field->field_validation('99.5') === true);
    }

    public function test_empty_value_is_valid(): void {
        $field = $this->make_field();
        $this->assertTrue($field->field_validation('') === true);
    }

    public function test_non_numeric_returns_error(): void {
        $field = $this->make_field();
        $result = $field->field_validation('abc');
        $this->assertIsString($result);
        $this->assertStringContainsString('numeric', $result);
    }

    public function test_below_minimum_returns_error(): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $result = $field->field_validation('-1');
        $this->assertIsString($result);
    }

    public function test_above_maximum_returns_error(): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $result = $field->field_validation('101');
        $this->assertIsString($result);
    }

    public function test_at_boundary_values(): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $this->assertTrue($field->field_validation('0') === true);
        $this->assertTrue($field->field_validation('100') === true);
    }

    public function test_notemptyfield(): void {
        $field = $this->make_field();
        $this->assertTrue($field->notemptyfield('50', ''));
        $this->assertFalse($field->notemptyfield('', ''));
        $this->assertFalse($field->notemptyfield(null, ''));
    }
}
