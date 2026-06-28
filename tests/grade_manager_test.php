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

    /**
     * Create course, users, enrolments, and a database activity before each test.
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
    }

    /**
     * Insert a minimal data_records row and return its ID.
     *
     * @param int $userid  The student user ID.
     * @return int  New record ID.
     */
    private function create_record(int $userid): int {
        global $DB;
        return $DB->insert_record('data_records', (object) [
            'userid' => $userid,
            'dataid' => $this->data->id,
            'groupid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'approved' => 1,
        ]);
    }

    /**
     * Progress returns zero counts when there are no entries.
     */
    public function test_progress_returns_zero_when_no_entries(): void {
        $result = \datafield_gradeentry\grade_manager::progress($this->data->id);
        $this->assertSame(0, $result['graded']);
        $this->assertSame(0, $result['total']);
    }

    /**
     * Progress correctly counts total entries and graded entries separately.
     */
    public function test_progress_counts_entries_and_graded(): void {
        global $DB;

        $rid1 = $this->create_record($this->student->id);
        $this->create_record($this->student->id);

        $DB->insert_record('datafield_gradeentry_grades', (object) [
            'dataid' => $this->data->id,
            'recordid' => $rid1,
            'userid' => $this->student->id,
            'graderid' => $this->teacher->id,
            'feedback' => '',
            'feedbackformat' => FORMAT_MOODLE,
            'released' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = \datafield_gradeentry\grade_manager::progress($this->data->id);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['graded']);
    }

    /**
     * Release marks the specified entry as released in the database.
     */
    public function test_release_marks_entries_released(): void {
        global $DB;

        $rid = $this->create_record($this->student->id);
        $DB->insert_record('datafield_gradeentry_grades', (object) [
            'dataid' => $this->data->id,
            'recordid' => $rid,
            'userid' => $this->student->id,
            'graderid' => $this->teacher->id,
            'feedback' => '',
            'feedbackformat' => FORMAT_MOODLE,
            'released' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        \datafield_gradeentry\grade_manager::release($this->data->id, [$rid]);

        $this->assertEquals(1, $DB->get_field('datafield_gradeentry_grades', 'released', ['recordid' => $rid]));
    }

    /**
     * Releasing all entries marks every graded entry as released.
     */
    public function test_release_all_marks_all_graded_entries(): void {
        global $DB;

        for ($i = 0; $i < 3; $i++) {
            $rid = $this->create_record($this->student->id);
            $DB->insert_record('datafield_gradeentry_grades', (object) [
                'dataid' => $this->data->id,
                'recordid' => $rid,
                'userid' => $this->student->id,
                'graderid' => $this->teacher->id,
                'feedback' => '',
                'feedbackformat' => FORMAT_MOODLE,
                'released' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        \datafield_gradeentry\grade_manager::release($this->data->id);

        $count = $DB->count_records(
            'datafield_gradeentry_grades',
            ['dataid' => $this->data->id, 'released' => 1]
        );
        $this->assertSame(3, $count);
    }
}
