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
 * @package    local_datagrading
 * @copyright  2025 onwards, Australian developers
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_datagrading\tests;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \local_datagrading\grade_manager.
 *
 * @covers \local_datagrading\grade_manager
 */
class grade_manager_test extends \advanced_testcase {

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

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        $this->course  = $generator->create_course();
        $this->teacher = $generator->create_user();
        $this->student = $generator->create_user();

        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($this->student->id, $this->course->id, 'student');

        $this->data = $generator->create_module('data', ['course' => $this->course->id]);
        $this->cm   = get_coursemodule_from_instance('data', $this->data->id, $this->course->id);
    }

    /**
     * Directly insert a data_records row and return its ID.
     */
    private function create_record(int $userid): int {
        global $DB;
        return $DB->insert_record('data_records', (object) [
            'userid'    => $userid,
            'dataid'    => $this->data->id,
            'groupid'   => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'approved'  => 1,
        ]);
    }

    public function test_progress_returns_zero_when_no_entries(): void {
        $result = \local_datagrading\grade_manager::progress($this->data->id);
        $this->assertSame(0, $result['graded']);
        $this->assertSame(0, $result['total']);
    }

    public function test_progress_counts_entries_and_graded(): void {
        global $DB;

        $rid1 = $this->create_record($this->student->id);
        $rid2 = $this->create_record($this->student->id);

        // Mark first entry as graded.
        $DB->insert_record('local_datagrading_grades', (object) [
            'dataid'        => $this->data->id,
            'recordid'      => $rid1,
            'userid'        => $this->student->id,
            'graderid'      => $this->teacher->id,
            'feedback'      => '',
            'feedbackformat' => FORMAT_MOODLE,
            'released'      => 0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $result = \local_datagrading\grade_manager::progress($this->data->id);
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['graded']);
    }

    public function test_release_marks_entries_released(): void {
        global $DB;

        $rid = $this->create_record($this->student->id);
        $DB->insert_record('local_datagrading_grades', (object) [
            'dataid'        => $this->data->id,
            'recordid'      => $rid,
            'userid'        => $this->student->id,
            'graderid'      => $this->teacher->id,
            'feedback'      => '',
            'feedbackformat' => FORMAT_MOODLE,
            'released'      => 0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        \local_datagrading\grade_manager::release($this->data->id, [$rid]);

        $this->assertEquals(1, $DB->get_field('local_datagrading_grades', 'released', ['recordid' => $rid]));
    }

    public function test_release_all_marks_all_graded_entries(): void {
        global $DB;

        $rids = [];
        for ($i = 0; $i < 3; $i++) {
            $rid    = $this->create_record($this->student->id);
            $rids[] = $rid;
            $DB->insert_record('local_datagrading_grades', (object) [
                'dataid'        => $this->data->id,
                'recordid'      => $rid,
                'userid'        => $this->student->id,
                'graderid'      => $this->teacher->id,
                'feedback'      => '',
                'feedbackformat' => FORMAT_MOODLE,
                'released'      => 0,
                'timecreated'   => time(),
                'timemodified'  => time(),
            ]);
        }

        \local_datagrading\grade_manager::release($this->data->id);

        $count = $DB->count_records('local_datagrading_grades', ['dataid' => $this->data->id, 'released' => 1]);
        $this->assertSame(3, $count);
    }
}
