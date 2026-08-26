<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_zoom\privacy;

use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_zoom\privacy\provider;

/**
 * Privacy provider tests class.
 *
 * @package    mod_zoom
 * @copyright  2022 Catalyst IT Australia Pty Ltd
 * @author     2022 Ghaly Marc-Alexandre <marc-alexandreghaly@catalyst-ca.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \mod_zoom\privacy\provider
 */
final class mod_zoom_provider_test extends provider_testcase {
    /** @var object The zoom instance object. */
    protected $zoominstance;

    /** @var object The course object. */
    protected $course;

    /** @var object The student object. */
    protected $student;

    /** @var object The second student object. */
    protected $student2;

    /** @var object The course module object.*/
    protected $cm;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $params = [
            'course' => $course->id,
            'name' => 'First Zoom Activity',
            'showpreview' => 0,
        ];

        $plugingenerator = $generator->get_plugin_generator('mod_zoom');
        // The zoom activity.
        $zoom = $plugingenerator->create_instance($params);
        // Create a student enrolled in zoom activity.
        $student = $generator->create_user();
        $student2 = $generator->create_user();
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $generator->enrol_user($student->id, $course->id, $studentrole->id);
        $generator->enrol_user($student2->id, $course->id, $studentrole->id);
        // Fill all related data tables.
        $meeting = (object) [
            'id' => 12345,
            'meeting_id' => 12345,
            'topic' => 'Some meeting',
            'start_time' => 1646769060,
            'end_time' => 1646715600,
            'uuid' => 'someuuid',
            'duration' => 60,
            'participants' => 3,
            'zoomid' => $zoom->id,
        ];

        $zmid = $DB->insert_record('zoom_meeting_details', $meeting, true);
        $participant = (object) [
            'zoomuserid' => 9999,
            'userid' => $student->id,
            'join_time' => 1646769061,
            'leave_time' => 1646769062,
            'duration' => 60,
            'name' => 'Michell',
            'detailsid' => $zmid,
        ];
        $participant2 = (object) [
            'zoomuserid' => 9999,
            'userid' => $student2->id,
            'join_time' => 1646769061,
            'leave_time' => 1646769062,
            'duration' => 60,
            'name' => 'John',
            'detailsid' => $zmid,
        ];
        $zmparticipantsid = $DB->insert_record('zoom_meeting_participants', $participant, true);
        $zmparticipantsid2 = $DB->insert_record('zoom_meeting_participants', $participant2, true);
        $meetingrecording = (object) [
            'zoomid' => $zoom->id,
            'meetinguuid' => 'meetinguuid',
            'zoomrecordingid' => 'zoomrecordingid',
            'name' => 'a zoom recording name',
            'externalurl' => 'www.dummyurl.com',
            'recordingtype' => 'recordingtype',
            'recordingstart' => 1646769061,
            'showrecording' => 1,
        ];
        $meetingrecordingid = $DB->insert_record('zoom_meeting_recordings', $meetingrecording, true);
        $meetingrecordingsview = (object) [
            'recordingsid' => $meetingrecordingid,
            'userid' => $student->id,
            'viewed' => 1,
        ];
        $meetingrecordingsview2 = (object) [
            'recordingsid' => $meetingrecordingid,
            'userid' => $student2->id,
            'viewed' => 1,
        ];
        $DB->insert_record('zoom_meeting_recordings_view', $meetingrecordingsview, true);
        $DB->insert_record('zoom_meeting_recordings_view', $meetingrecordingsview2, true);

        $cm = get_coursemodule_from_instance('zoom', $zoom->id);

        $this->zoominstance = $zoom;
        $this->course = $course;
        $this->student = $student;
        $this->student2 = $student2;
        $this->cm = $cm;
    }

    /**
     * Test for provider::get_metadata().
     * @covers ::get_metadata
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_zoom');
        $newcollection = provider::get_metadata($collection);
        $itemcollection = $newcollection->get_collection();

        $this->assertCount(5, $itemcollection);
        $table = reset($itemcollection);
        $table2 = $itemcollection[1];
        $table3 = $itemcollection[2];
        $table4 = $itemcollection[3];
        $this->assertEquals('zoom_meeting_participants', $table->get_name());
        $this->assertEquals('zoom_meeting_details', $table2->get_name());
        $this->assertEquals('zoom_meeting_recordings_view', $table3->get_name());
        $this->assertEquals('zoom_breakout_participants', $table4->get_name());

        $privacyfields1 = $table->get_privacy_fields();
        $this->assertArrayHasKey('name', $privacyfields1);
        $this->assertArrayHasKey('user_email', $privacyfields1);
        $this->assertArrayHasKey('join_time', $privacyfields1);
        $this->assertArrayHasKey('leave_time', $privacyfields1);
        $this->assertArrayHasKey('duration', $privacyfields1);

        $this->assertEquals('privacy:metadata:zoom_meeting_participants', $table->get_summary());

        $privacyfields2 = $table2->get_privacy_fields();
        $this->assertArrayHasKey('topic', $privacyfields2);

        $this->assertEquals('privacy:metadata:zoom_meeting_details', $table2->get_summary());

        $privacyfields3 = $table3->get_privacy_fields();
        $this->assertArrayHasKey('userid', $privacyfields3);

        $this->assertEquals('privacy:metadata:zoom_meeting_view', $table3->get_summary());

        $privacyfields4 = $table4->get_privacy_fields();
        $this->assertArrayHasKey('userid', $privacyfields4);

        $this->assertEquals('privacy:metadata:zoom_breakout_participants', $table4->get_summary());
    }

    /**
     * Test for provider::get_contexts_for_userid().
     * @covers ::get_contexts_for_userid
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid($this->student->id);
        $this->assertCount(1, $contextlist);
        $contextforuser = $contextlist->current();
        $cmcontext = context_module::instance($this->cm->id);
        $this->assertEquals($cmcontext->id, $contextforuser->id);

        $contextlist2 = provider::get_contexts_for_userid($this->student2->id);
        $this->assertCount(1, $contextlist2);
        $contextforuser2 = $contextlist2->current();
        $cmcontext2 = context_module::instance($this->cm->id);
        $this->assertEquals($cmcontext2->id, $contextforuser2->id);
    }

    /**
     * Test for provider::get_users_in_context().
     * @covers ::get_users_in_context
     */
    public function test_get_users_in_context(): void {
        $cmcontext = context_module::instance($this->cm->id);

        $userlist = new userlist($cmcontext, 'mod_zoom');
        provider::get_users_in_context($userlist);

        $this->assertEquals([$this->student->id, $this->student2->id], $userlist->get_userids());
    }

    /**
     * Test for provider::export_user_data().
     * @covers ::export_user_data
     */
    public function test_export_user_data(): void {
        $cmcontext = context_module::instance($this->cm->id);

        // Export all of the data for the context.
        $this->export_context_data_for_user($this->student->id, $cmcontext, 'mod_zoom');
        $writer = writer::with_context($cmcontext);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Test for provider::delete_data_for_all_users_in_context().
     * @covers ::delete_data_for_all_users_in_context
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $zoommeetingcount = $DB->count_records('zoom_meeting_details');
        $this->assertEquals(1, $zoommeetingcount);

        $zmparticipants = $DB->count_records('zoom_meeting_participants');
        $this->assertEquals(2, $zmparticipants);

        $zmrecordingcount = $DB->count_records('zoom_meeting_recordings');
        $this->assertEquals(1, $zmrecordingcount);

        $zmrecordingviewcount = $DB->count_records('zoom_meeting_recordings_view');
        $this->assertEquals(2, $zmrecordingviewcount);

        // Delete data based on context.
        $cmcontext = context_module::instance($this->cm->id);
        provider::delete_data_for_all_users_in_context($cmcontext);

        $newzoommeetingcount = $DB->count_records('zoom_meeting_details');
        $this->assertEquals(0, $newzoommeetingcount);

        $newzmparticipants = $DB->count_records('zoom_meeting_participants');
        $this->assertEquals(0, $newzmparticipants);

        $newzmrecordingcount = $DB->count_records('zoom_meeting_recordings');
        $this->assertEquals(0, $newzmrecordingcount);

        $newzmrecordingviewcount = $DB->count_records('zoom_meeting_recordings_view');
        $this->assertEquals(0, $newzmrecordingviewcount);
    }

    /**
     * Test for provider::delete_data_for_user().
     * @covers ::delete_data_for_user
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $zmparticipants = $DB->count_records('zoom_meeting_participants');
        $this->assertEquals(2, $zmparticipants);

        $zmrecordingviewcount = $DB->count_records('zoom_meeting_recordings_view');
        $this->assertEquals(2, $zmrecordingviewcount);
        // Delete data based on specific context.
        $context = context_module::instance($this->cm->id);
        $contextlist = new approved_contextlist($this->student, 'mod_zoom', [$context->id]);

        provider::delete_data_for_user($contextlist);

        $newzmparticipants = $DB->count_records('zoom_meeting_participants');
        $this->assertEquals(1, $newzmparticipants);

        $newzmrecordingviewcount = $DB->count_records('zoom_meeting_recordings_view');
        $this->assertEquals(1, $newzmrecordingviewcount);
    }

    /**
     * Test for provider::delete_data_for_users().
     * @covers ::delete_data_for_users
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $zmparticipants = $DB->count_records('zoom_meeting_participants');
        $this->assertEquals(2, $zmparticipants);

        $zmrecordingviewcount = $DB->count_records('zoom_meeting_recordings_view');
        $this->assertEquals(2, $zmrecordingviewcount);
        // Delete data based on specific context.
        $context = context_module::instance($this->cm->id);
        $approveduserlist = new approved_userlist(
            $context,
            'zoom',
            [$this->student->id, $this->student2->id]
        );
        provider::delete_data_for_users($approveduserlist);

        $newzmparticipants = $DB->count_records('zoom_meeting_participants');
        $this->assertEquals(0, $newzmparticipants);

        $newzmrecordingviewcount = $DB->count_records('zoom_meeting_recordings_view');
        $this->assertEquals(0, $newzmrecordingviewcount);
    }
}
