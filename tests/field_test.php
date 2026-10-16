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
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace datafield_gradeentry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the grade entry field's form-submit hooks and browse view.
 *
 * The form-submit hooks on this field type are intentionally permissive:
 * students only choose a submission status (display_add_field() emits a
 * draft/submitted radio group) and teachers grade via the inline AJAX panel -
 * which has its own bounds checking - not through the standard entry-form
 * submit. update_content() ignores a numeric value from anyone who cannot grade.
 *
 * mod_data's data_process_submission() treats any truthy return from
 * field_validation() as an error message and blocks the entry save, and
 * notemptyfield() is the input to the required-field check. Both must
 * therefore return false unconditionally so a Grade entry field cannot
 * block a student submission.
 */
#[CoversClass(\data_field_gradeentry::class)]
final class field_test extends \advanced_testcase {
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
     * field_validation() must always return a falsy value so it never blocks
     * a student entry save, regardless of what mod_data hands in for this
     * field's submitted value.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function permissive_validation_provider(): array {
        return [
            'empty string'        => [''],
            'null'                => [null],
            'integer in range'    => ['75'],
            'decimal in range'    => ['99.5'],
            'min boundary'        => ['0'],
            'max boundary'        => ['100'],
            'below minimum'       => ['-1'],
            'above maximum'       => ['101'],
            'non-numeric string'  => ['abc'],
            'field-name fallback' => ['Test grade'],
        ];
    }

    /**
     * field_validation() returns false for every input mod_data could hand it.
     *
     * @param mixed $value Submitted value (ignored by the hook).
     */
    #[DataProvider('permissive_validation_provider')]
    public function test_field_validation_always_passes($value): void {
        $field = $this->make_field(['param1' => '0', 'param2' => '100']);
        $this->assertFalse($field->field_validation($value));
    }

    /**
     * notemptyfield() always returns false so the required-field check can
     * never reject a student submission for this field type.
     */
    public function test_notemptyfield_always_returns_false(): void {
        $field = $this->make_field();
        $this->assertFalse($field->notemptyfield('50', ''));
        $this->assertFalse($field->notemptyfield('', ''));
        $this->assertFalse($field->notemptyfield(null, ''));
    }

    /**
     * Create an enrolled user and a data record they own.
     *
     * @param  string $role  Role shortname to enrol the user with.
     * @return array  [user, record ID].
     */
    private function make_user_with_record(string $role = 'student'): array {
        global $DB;
        $user = $this->getDataGenerator()->create_and_enrol(get_course($this->dataactivity->course), $role);
        $rid = (int) $DB->insert_record('data_records', (object) [
            'userid'       => $user->id,
            'dataid'       => $this->dataactivity->id,
            'groupid'      => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'approved'     => 1,
        ]);
        return [$user, $rid];
    }

    /**
     * A student saving their own entry cannot set a grade by posting a number.
     */
    public function test_update_content_ignores_numeric_grade_from_student(): void {
        global $DB;
        $field = $this->make_field();
        [$student, $rid] = $this->make_user_with_record();
        $this->setUser($student);

        $field->update_content($rid, '100');

        $this->assertFalse($DB->get_field('data_content', 'content', ['fieldid' => $field->field->id, 'recordid' => $rid]));
    }

    /**
     * A grader can still set a numeric grade through update_content() (CSV import).
     */
    public function test_update_content_stores_numeric_grade_from_teacher(): void {
        global $DB;
        $field = $this->make_field();
        [, $rid] = $this->make_user_with_record();
        [$teacher] = $this->make_user_with_record('editingteacher');
        $this->setUser($teacher);

        $field->update_content($rid, '42');

        $this->assertEquals(42, $DB->get_field('data_content', 'content', ['fieldid' => $field->field->id, 'recordid' => $rid]));
    }

    /**
     * Released grades and feedback are shown to the entry's author only.
     */
    public function test_browse_field_hides_grade_from_other_students(): void {
        global $DB;
        $field = $this->make_field();
        [$author, $rid] = $this->make_user_with_record();
        [$classmate] = $this->make_user_with_record();

        $meta = array_merge(grade_manager::metadata_defaults(), [
            'graded'   => 1,
            'released' => 1,
            'feedback' => 'Private feedback',
        ]);
        $DB->insert_record('data_content', (object) [
            'fieldid'  => $field->field->id,
            'recordid' => $rid,
            'content'  => 88,
            'content1' => json_encode($meta),
        ]);

        $this->setUser($author);
        $this->assertStringContainsString('Private feedback', $field->display_browse_field($rid, ''));

        $this->setUser($classmate);
        $this->assertSame('', $field->display_browse_field($rid, ''));
    }
}
