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
 * Unit tests for data_field_gradeentry.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\tests;

/**
 * Unit tests for the grade entry field validation logic.
 *
 * @covers \data_field_gradeentry
 */
class field_test extends \advanced_testcase {
    /** @var \stdClass Stub field record shared across tests. */
    private \stdClass $fieldrecord;

    /**
     * Set up a minimal field record stub before each test.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/data/field/gradeentry/field.class.php');
        $this->resetAfterTest();

        $this->fieldrecord = (object) [
            'id' => 1,
            'dataid' => 1,
            'type' => 'gradeentry',
            'name' => 'Test grade',
            'description' => '',
            'required' => 0,
            'param1' => '0',
            'param2' => '100',
            'param3' => '2',
            'param4' => '',
        ];
    }

    /**
     * Build a field instance, optionally overriding field record properties.
     *
     * @param \stdClass|null $overrides  Key-value pairs to override.
     * @return \data_field_gradeentry
     */
    private function make_field(\stdClass $overrides = null): \data_field_gradeentry {
        $rec = clone $this->fieldrecord;
        if ($overrides) {
            foreach ((array) $overrides as $k => $v) {
                $rec->$k = $v;
            }
        }
        return new \data_field_gradeentry($rec);
    }

    /**
     * A valid integer grade passes validation.
     */
    public function test_valid_integer(): void {
        $field = $this->make_field();
        $this->assertTrue($field->field_validation('75') === true);
    }

    /**
     * A valid decimal grade passes validation.
     */
    public function test_valid_decimal(): void {
        $field = $this->make_field();
        $this->assertTrue($field->field_validation('99.5') === true);
    }

    /**
     * An empty value is treated as valid (field is optional).
     */
    public function test_empty_value_is_valid(): void {
        $field = $this->make_field();
        $this->assertTrue($field->field_validation('') === true);
    }

    /**
     * A non-numeric string returns an error message.
     */
    public function test_non_numeric_returns_error(): void {
        $field = $this->make_field();
        $result = $field->field_validation('abc');
        $this->assertIsString($result);
        $this->assertStringContainsString('numeric', $result);
    }

    /**
     * A value below the configured minimum returns an error.
     */
    public function test_below_minimum_returns_error(): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $result = $field->field_validation('-1');
        $this->assertIsString($result);
    }

    /**
     * A value above the configured maximum returns an error.
     */
    public function test_above_maximum_returns_error(): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $result = $field->field_validation('101');
        $this->assertIsString($result);
    }

    /**
     * Values exactly at the boundary (min and max) pass validation.
     */
    public function test_at_boundary_values(): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $this->assertTrue($field->field_validation('0') === true);
        $this->assertTrue($field->field_validation('100') === true);
    }

    /**
     * Non-empty values return true from notemptyfield; empty values return false.
     */
    public function test_notemptyfield(): void {
        $field = $this->make_field();
        $this->assertTrue($field->notemptyfield('50', ''));
        $this->assertFalse($field->notemptyfield('', ''));
        $this->assertFalse($field->notemptyfield(null, ''));
    }
}
