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
 * Unit tests for the datafield_gradeentry privacy provider.
 *
 * @package    datafield_gradeentry
 * @copyright  2025 onwards, Vernon Spain/Educheckout
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace datafield_gradeentry\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use datafield_gradeentry\grade_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the metadata description, export and deletion of the privacy provider.
 *
 * The plugin stores everything in mod_data's data_content row: the grade in
 * content and grading metadata (feedback, release state, submission status,
 * rubric scores) as JSON in content1. mod_data drives export and deletion via
 * the datafield_provider methods exercised here.
 */
#[CoversClass(\datafield_gradeentry\privacy\provider::class)]
final class provider_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Database activity instance. */
    private \stdClass $data;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \stdClass Student user. */
    private \stdClass $student;

    /** @var int The gradeentry field ID. */
    private int $fieldid;

    /**
     * Create a course, a student, a database activity and a gradeentry field.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();

        $this->course = $generator->create_course();
        $this->student = $generator->create_user();
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
     * Insert a data_records row and a graded content/content1 pair for it.
     *
     * @return array{0: int, 1: int}  The new record ID and content ID.
     */
    private function create_graded_entry(): array {
        global $DB;
        $recordid = (int) $DB->insert_record('data_records', (object) [
            'userid'       => $this->student->id,
            'dataid'       => $this->data->id,
            'groupid'      => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'approved'     => 1,
        ]);

        $meta = array_merge(grade_manager::metadata_defaults(), [
            'graded'            => 1,
            'feedback'          => 'Good work, but check your citations.',
            'released'          => 1,
            'submission_status' => grade_manager::STATUS_SUBMITTED,
            'rubric_scores'     => json_encode(['clarity' => 3, 'accuracy' => 2]),
            'timecreated'       => time(),
            'timemodified'      => time(),
        ]);

        $contentid = (int) $DB->insert_record('data_content', (object) [
            'fieldid'  => $this->fieldid,
            'recordid' => $recordid,
            'content'  => '75',
            'content1' => json_encode($meta),
        ]);

        return [$recordid, $contentid];
    }

    /**
     * get_metadata describes the data_content table and its two columns.
     */
    public function test_get_metadata_describes_data_content(): void {
        $collection = provider::get_metadata(new collection('datafield_gradeentry'));

        $items = $collection->get_collection();
        $this->assertCount(1, $items);

        $table = reset($items);
        $this->assertSame('data_content', $table->get_name());

        $fields = $table->get_privacy_fields();
        $this->assertArrayHasKey('content', $fields);
        $this->assertArrayHasKey('content1', $fields);
    }

    /**
     * export_data_content writes readable grading fields and drops raw JSON.
     */
    public function test_export_data_content_writes_readable_metadata(): void {
        global $DB;
        [$recordid, $contentid] = $this->create_graded_entry();

        $context = \context_module::instance($this->cm->id);
        $recordobj = $DB->get_record('data_records', ['id' => $recordid]);
        $fieldobj = $DB->get_record('data_fields', ['id' => $this->fieldid]);
        $contentobj = $DB->get_record('data_content', ['id' => $contentid]);

        // Mimic the value object mod_data hands the field, pre-filled with the raw row.
        $defaultvalue = (object) [
            'field'    => $fieldobj->name,
            'content'  => $contentobj->content,
            'content1' => $contentobj->content1,
        ];

        provider::export_data_content($context, $recordobj, $fieldobj, $contentobj, $defaultvalue);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $exported = $writer->get_data([$recordid, $contentid]);
        $this->assertSame('75', $exported->grade);
        $this->assertSame(transform::yesno(1), $exported->graded);
        $this->assertSame('Good work, but check your citations.', $exported->feedback);
        $this->assertSame(transform::yesno(1), $exported->released);
        $this->assertSame(grade_manager::STATUS_SUBMITTED, $exported->submissionstatus);
        $this->assertSame(transform::yesno(0), $exported->requireresubmission);
        $this->assertSame(json_encode(['clarity' => 3, 'accuracy' => 2]), $exported->rubricscores);

        // Stored timestamps are exported as readable dates, not raw epochs.
        $this->assertObjectHasProperty('timecreated', $exported);
        $this->assertObjectHasProperty('timemodified', $exported);

        // The raw metadata JSON must not leak into the export.
        $this->assertObjectNotHasProperty('content1', $exported);
    }

    /**
     * An ungraded entry exports with default (empty) grading metadata.
     */
    public function test_export_data_content_for_ungraded_entry(): void {
        global $DB;
        $recordid = (int) $DB->insert_record('data_records', (object) [
            'userid'       => $this->student->id,
            'dataid'       => $this->data->id,
            'groupid'      => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'approved'     => 1,
        ]);
        $contentid = (int) $DB->insert_record('data_content', (object) [
            'fieldid'  => $this->fieldid,
            'recordid' => $recordid,
            'content'  => '',
            'content1' => null,
        ]);

        $context = \context_module::instance($this->cm->id);
        $recordobj = $DB->get_record('data_records', ['id' => $recordid]);
        $fieldobj = $DB->get_record('data_fields', ['id' => $this->fieldid]);
        $contentobj = $DB->get_record('data_content', ['id' => $contentid]);
        $defaultvalue = (object) ['content1' => null];

        provider::export_data_content($context, $recordobj, $fieldobj, $contentobj, $defaultvalue);

        $exported = writer::with_context($context)->get_data([$recordid, $contentid]);
        $this->assertSame('', $exported->feedback);
        $this->assertSame(transform::yesno(0), $exported->released);
        $this->assertSame(grade_manager::STATUS_NOTSUBMITTED, $exported->submissionstatus);
        // No rubric scores stored, so none exported.
        $this->assertObjectNotHasProperty('rubricscores', $exported);
        $this->assertObjectNotHasProperty('content1', $exported);
    }

    /**
     * delete_data_content is a no-op: mod_data removes the data_content row.
     */
    public function test_delete_data_content_is_noop(): void {
        global $DB;
        [$recordid, $contentid] = $this->create_graded_entry();

        $context = \context_module::instance($this->cm->id);
        $recordobj = $DB->get_record('data_records', ['id' => $recordid]);
        $fieldobj = $DB->get_record('data_fields', ['id' => $this->fieldid]);
        $contentobj = $DB->get_record('data_content', ['id' => $contentid]);

        provider::delete_data_content($context, $recordobj, $fieldobj, $contentobj);

        // The provider leaves the row alone; mod_data owns its removal.
        $this->assertTrue($DB->record_exists('data_content', ['id' => $contentid]));
    }
}
