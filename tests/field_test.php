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
    /** @var \stdClass The Database activity used as the parent for each field. */
    private \stdClass $dataactivity;

    /**
     * Create a real course + Database activity so data_field_base's
     * constructor can resolve a valid data record.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/data/lib.php');
        require_once($CFG->dirroot . '/mod/data/field/gradeentry/field.class.php');
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->dataactivity = $generator->create_module('data', ['course' => $course->id]);
    }

    /**
     * Insert a fully-populated gradeentry field row directly via $DB.
     *
     * mod_data_generator::create_field() only copies record properties that
     * already exist on the field stub it builds, which means our paramN
     * values get silently dropped. Going straight to $DB->insert_record()
     * guarantees that param1..param4 round-trip into the constructed field.
     *
     * @param  array|null $overrides  Param overrides applied to the field record.
     * @return \data_field_gradeentry
     */
    private function make_field(?array $overrides = null): \data_field_gradeentry {
        global $DB;
        $params = array_merge([
            'dataid'      => $this->dataactivity->id,
            'type'        => 'gradeentry',
            'name'        => 'Test grade',
            'description' => '',
            'required'    => 0,
            'param1'      => '0',
            'param2'      => '100',
            'param3'      => '2',
            'param4'      => '',
            'param5'      => '',
            'param6'      => '',
            'param7'      => '',
            'param8'      => '',
            'param9'      => '',
            'param10'     => '',
        ], $overrides ?? []);
        $id = $DB->insert_record('data_fields', (object) $params);
        $fieldrec = $DB->get_record('data_fields', ['id' => $id], '*', MUST_EXIST);
        return new \data_field_gradeentry($fieldrec, $this->dataactivity);
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
