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
 * Unit tests for datafield_gradeentry\grade_manager.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace datafield_gradeentry;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for grade storage, release, and progress counting in grade_manager.
 *
 * Grade metadata is stored in data_content.content1 (JSON) alongside the grade
 * value in data_content.content, so these tests seed and assert on that table.
 */
#[CoversClass(\datafield_gradeentry\grade_manager::class)]
final class grade_manager_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Database activity instance. */
    private \stdClass $data;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \stdClass Teacher user. */
    private \stdClass $teacher;

    /** @var \stdClass Student user. */
    private \stdClass $student;

    /** @var int The gradeentry field ID. */
    private int $fieldid;

    /**
     * Create course, users, enrolments, a database activity and a gradeentry field.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        $this->course = $generator->create_course();
        $this->teacher = $generator->create_user();
        $this->student = $generator->create_user();

        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($this->student->id, $this->course->id, 'student');

        $this->data = $generator->create_module('data', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('data', $this->data->id, $this->course->id);
        $this->fieldid = $this->create_field();
    }

    /**
     * Insert a gradeentry field on the Database activity.
     *
     * @return int  New field ID.
     */
    private function create_field(): int {
        global $DB;
        return (int) $DB->insert_record('data_fields', (object) [
            'dataid'      => $this->data->id,
            'type'        => 'gradeentry',
            'name'        => 'Grade',
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
        ]);
    }

    /**
     * Insert a minimal data_records row and return its ID.
     *
     * @param int $userid  The student user ID.
     * @return int  New record ID.
     */
    private function create_record(int $userid): int {
        global $DB;
        return (int) $DB->insert_record('data_records', (object) [
            'userid'       => $userid,
            'dataid'       => $this->data->id,
            'groupid'      => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'approved'     => 1,
        ]);
    }

    /**
     * Create a data record plus a graded content1 metadata blob.
     *
     * @param  int $userid    The student user ID.
     * @param  int $released  Released flag to store (0 or 1).
     * @return int  New record ID.
     */
    private function create_graded_record(int $userid, int $released = 0): int {
        global $DB;
        $rid = $this->create_record($userid);
        $meta = array_merge(grade_manager::metadata_defaults(), [
            'graded'            => 1,
            'released'          => $released,
            'submission_status' => grade_manager::STATUS_SUBMITTED,
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);
        $DB->insert_record('data_content', (object) [
            'fieldid'  => $this->fieldid,
            'recordid' => $rid,
            'content'  => 75,
            'content1' => json_encode($meta),
        ]);
        return $rid;
    }

    /**
     * Progress returns zero counts when there are no entries.
     */
    public function test_progress_returns_zero_when_no_entries(): void {
        $result = grade_manager::progress($this->data->id);
        $this->assertSame(0, $result['graded']);
        $this->assertSame(0, $result['total']);
    }

    /**
     * Progress correctly counts total entries and graded entries separately.
     */
    public function test_progress_counts_entries_and_graded(): void {
        $this->create_graded_record($this->student->id);
        $this->create_record($this->student->id);

        $result = grade_manager::progress($this->data->id);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['graded']);
    }

    /**
     * Release marks the specified entry as released in content1.
     */
    public function test_release_marks_entries_released(): void {
        $rid = $this->create_graded_record($this->student->id, 0);

        grade_manager::release($this->data->id, [$rid]);

        $meta = grade_manager::get_metadata($this->fieldid, $rid);
        $this->assertSame(1, (int) $meta['released']);
    }

    /**
     * Releasing all entries marks every graded entry as released.
     */
    public function test_release_all_marks_all_graded_entries(): void {
        $rids = [];
        for ($i = 0; $i < 3; $i++) {
            $rids[] = $this->create_graded_record($this->student->id, 0);
        }

        $count = grade_manager::release($this->data->id);

        $this->assertSame(3, $count);
        foreach ($rids as $rid) {
            $meta = grade_manager::get_metadata($this->fieldid, $rid);
            $this->assertSame(1, (int) $meta['released']);
        }
    }

    /**
     * Updating submission status creates and persists the content1 blob.
     */
    public function test_update_submission_status_persists(): void {
        $rid = $this->create_record($this->student->id);

        grade_manager::update_submission_status(
            (int) $this->data->id,
            $rid,
            (int) $this->student->id,
            grade_manager::STATUS_DRAFT
        );

        $meta = grade_manager::get_metadata($this->fieldid, $rid);
        $this->assertSame(grade_manager::STATUS_DRAFT, $meta['submission_status']);
        $this->assertTrue(grade_manager::has_metadata($this->fieldid, $rid));
    }
}
