<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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

/**
 * Unit tests for get_meeting_reports task class.
 *
 * @package    mod_zoom
 * @copyright  2019 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom;

use advanced_testcase;
use context_course;
use html_writer;
use moodle_url;
use stdClass;

/**
 * PHPunit testcase class.
 * @covers \mod_zoom\task\get_meeting_reports
 */
final class get_meeting_reports_test extends advanced_testcase {
    /**
     * Scheduled task object.
     * @var \mod_zoom\task\get_meeting_reports
     */
    private $meetingtask;

    /**
     * Fake data to return for mocked get_meeting_participants() call.
     * @var array
     */
    private $mockparticipantsdata;

    /**
     * Fake data from get_meeting_participants().
     * @var object
     */
    private $zoomdata;

    /**
     * Mocks the mod_zoom\webservice->get_meeting_participants() call, so we
     * don't actually call the real Zoom API.
     *
     * @param string $meetinguuid The meeting or webinar's UUID.
     * @param bool $webinar Whether the meeting or webinar whose information you want is a webinar.
     * @return array    The specified meeting participants array for given meetinguuid.
     */
    public function mock_get_meeting_participants($meetinguuid, $webinar) {
        return $this->mockparticipantsdata[$meetinguuid] ?? null;
    }

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();

        $this->resetAfterTest(true);

        $this->meetingtask = new \mod_zoom\task\get_meeting_reports();

        $data = [
            'id' => 'ARANDOMSTRINGFORUUID',
            'user_id' => 123456789,
            'name' => 'SMITH, JOE',
            'user_email' => 'joe@test.com',
            'join_time' => '2019-01-01T00:00:00Z',
            'leave_time' => '2019-01-01T00:01:00Z',
            'duration' => 60,
        ];
        $this->zoomdata = (object) $data;
    }

    /**
     * Make sure that format_participant() filters bad data from Zoom.
     */
    public function test_format_participant_filtering(): void {
        // Sometimes Zoom has a # instead of comma in the name.
        $this->zoomdata->name = 'SMITH# JOE';
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, [], []);
        $this->assertEquals('SMITH, JOE', $participant['name']);
    }

    /**
     * Make sure that format_participant() can match Moodle users.
     */
    public function test_format_participant_matching(): void {
        global $DB;
        return;

        // 1) If user does not match, verify that we are using data from Zoom.
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, [], []);
        $this->assertEquals($this->zoomdata->name, $participant['name']);
        $this->assertEquals($this->zoomdata->user_email, $participant['user_email']);
        $this->assertNull($participant['userid']);

        // 2) Try to match view via system email.

        // Add user's email to Moodle system.
        $user = $this->getDataGenerator()->create_user(
            ['email' => $this->zoomdata->user_email]
        );

        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, [], []);
        $this->assertEquals($user->id, $participant['userid']);
        $this->assertEquals(strtoupper(fullname($user)), $participant['name']);
        $this->assertEquals($user->email, $participant['user_email']);

        // 3) Try to match view via enrolled name.

        // Change user's name to make sure we are matching on name.
        $user->firstname = 'Firstname';
        $user->lastname = 'Lastname';
        $DB->update_record('user', $user);
        // Set to blank so previous test does not trigger.
        $this->zoomdata->user_email = '';

        // Create course and enroll user.
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        [$names, $emails] = $this->meetingtask->get_enrollments($course->id);

        // Before Zoom data is changed, should return nothing.
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertNull($participant['userid']);

        // Change Zoom data and now user should be found.
        $this->zoomdata->name = strtoupper(fullname($user));
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEquals($user->id, $participant['userid']);
        $this->assertEquals($names[$participant['userid']], $participant['name']);
        // Email should match what Zoom gives us.
        $this->assertEquals($this->zoomdata->user_email, $participant['user_email']);

        // 4) Try to match view via enrolled email.

        // Change user's email to make sure we are matching on email.
        $user->email = 'smith@test.com';
        $DB->update_record('user', $user);
        // Change name so previous test does not trigger.
        $this->zoomdata->name = 'Something Else';
        // Since email changed, update enrolled user data.
        [$names, $emails] = $this->meetingtask->get_enrollments($course->id);

        // Before Zoom data is changed, should return nothing.
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertNull($participant['userid']);

        // Change Zoom data and now user should be found.
        $this->zoomdata->user_email = $user->email;
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEquals($user->id, $participant['userid']);
        $this->assertEquals($names[$participant['userid']], $participant['name']);
        // Email should match what Zoom gives us.
        $this->assertEquals($this->zoomdata->user_email, $participant['user_email']);

        // 5) Try to match user via id (uuid).

        // Insert previously generated $participant data, but with UUID set.
        $participant['uuid'] = $this->zoomdata->id;
        // Set userid to a given value so we know we got a match.
        $participant['userid'] = 999;
        $recordid = $DB->insert_record('zoom_meeting_participants', $participant);

        // Should return the found entry in zoom_meeting_participants.
        $newparticipant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEquals($participant['uuid'], $newparticipant['uuid']);
        $this->assertEquals(999, $newparticipant['userid']);
        $this->assertEquals($participant['name'], $newparticipant['name']);
        // Email should match what Zoom gives us.
        $this->assertEquals($this->zoomdata->user_email, $newparticipant['user_email']);
    }

    /**
     * Make sure that format_participant() can match Moodle users more
     * aggressively on name.
     */
    public function test_format_participant_name_matching(): void {
        // Enroll a bunch of users. Note: names were generated by
        // https://www.behindthename.com/random/ and any similarity to anyone
        // real or ficitional is concidence and not intentional.
        $users[0] = $this->getDataGenerator()->create_user([
            'lastname' => 'VAN ANTWERPEN',
            'firstname' => 'LORETO ZAHIRA',
        ]);
        $users[1] = $this->getDataGenerator()->create_user([
            'lastname' => 'POWER',
            'firstname' => 'TEIMURAZI ELLI',
        ]);
        $users[2] = $this->getDataGenerator()->create_user([
            'lastname' => 'LITTLE',
            'firstname' => 'BASEMATH ALIZA',
        ]);
        $users[3] = $this->getDataGenerator()->create_user([
            'lastname' => 'MUTTON',
            'firstname' => 'RADOVAN BRIANNA',
        ]);
        $users[4] = $this->getDataGenerator()->create_user([
            'lastname' => 'MUTTON',
            'firstname' => 'BRUNO EVGENIJA',
        ]);
        $course = $this->getDataGenerator()->create_course();
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }

        [$names, $emails] = $this->meetingtask->get_enrollments($course->id);

        // 1) Make sure we match someone with middle name missing.
        $users[0]->firstname = 'LORETO';
        $this->zoomdata->name = fullname($users[0]);
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEquals($users[0]->id, $participant['userid']);

        // 2) Make sure that name matches even if there are no spaces.
        $users[1]->firstname = str_replace(' ', '', $users[1]->firstname);
        $this->zoomdata->name = fullname($users[1]);
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEquals($users[1]->id, $participant['userid']);

        // 3) Make sure that name matches even if we have different ordering.
        $this->zoomdata->name = 'MUTTON, RADOVAN BRIANNA';
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEquals($users[3]->id, $participant['userid']);

        // 4) Make sure we do not match users if just last name is the same.
        $users[2]->firstname = 'JOSH';
        $this->zoomdata->name = fullname($users[2]);
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEmpty($participant['userid']);

        // 5) Make sure we do not match users if name is not similar to anything.
        $users[4]->firstname = 'JOSH';
        $users[4]->lastname = 'SMITH';
        $this->zoomdata->name = fullname($users[4]);
        $participant = $this->meetingtask->format_participant($this->zoomdata, 1, $names, $emails);
        $this->assertEmpty($participant['userid']);
    }

    /**
     * Tests that we can handle when the Zoom API sometimes returns invalid
     * userids in the report/meeting/participants call.
     */
    public function test_invalid_userids(): void {
        global $DB, $SITE;

        // Make sure we start with nothing.
        $this->assertEquals(0, $DB->count_records('zoom_meeting_details'));
        $this->assertEquals(0, $DB->count_records('zoom_meeting_participants'));
        $this->mockparticipantsdata = [];

        // First mock the webservice object, so we can inject the return values
        // for get_meeting_participants.
        $mockwwebservice = $this->createMock('\mod_zoom\webservice');

        // What we want get_meeting_participants to return.
        $participant1 = new stdClass();
        // Sometimes Zoom returns timestamps appended to user_ids.
        $participant1->id = '';
        $participant1->user_id = '02020-04-01 15:02:01:040';
        $participant1->name = 'John Smith';
        $participant1->user_email = 'john@test.com';
        $participant1->join_time = '2020-04-01T15:02:01Z';
        $participant1->leave_time = '2020-04-01T15:02:01Z';
        $participant1->duration = 0;
        $this->mockparticipantsdata['someuuid'][] = $participant1;
        // Have another participant with normal data.
        $participant2 = new stdClass();
        $participant2->id = '';
        $participant2->user_id = 123;
        $participant2->name = 'Jane Smith';
        $participant2->user_email = 'jane@test.com';
        $participant2->join_time = '2020-04-01T15:00:00Z';
        $participant2->leave_time = '2020-04-01T15:10:00Z';
        $participant2->duration = 10 * 60;
        $this->mockparticipantsdata['someuuid'][] = $participant2;

        // Make get_meeting_participants() return our results array.
        $mockwwebservice->method('get_meeting_participants')
            ->will($this->returnCallback([$this, 'mock_get_meeting_participants']));

        $this->assertEquals(
            $this->mockparticipantsdata['someuuid'],
            $mockwwebservice->get_meeting_participants('someuuid', false)
        );

        // Now fake the meeting details.
        $meeting = new stdClass();
        $meeting->id = 12345;
        $meeting->topic = 'Some meeting';
        $meeting->start_time = '2020-04-01T15:00:00Z';
        $meeting->end_time = '2020-04-01T16:00:00Z';
        $meeting->uuid = 'someuuid';
        $meeting->duration = 60 * 60;
        $meeting->participants = 3;

        // Insert stub data for zoom table.
        $DB->insert_record('zoom', [
            'course' => $SITE->id,
            'meeting_id' => $meeting->id,
            'name' => 'Zoom',
            'exists_on_zoom' => ZOOM_MEETING_EXISTS,
        ]);

        // Run task process_meeting_reports() and should insert participants.
        $this->meetingtask->service = $mockwwebservice;
        $meeting = $this->meetingtask->normalize_meeting($meeting);
        $this->assertTrue($this->meetingtask->process_meeting_reports($meeting));

        // Make sure that only one details is added and two participants.
        $this->assertEquals(1, $DB->count_records('zoom_meeting_details'));
        $this->assertEquals(2, $DB->count_records('zoom_meeting_participants'));

        // Add in one more participant, make sure we update details and added
        // one more participant.
        $participant3 = new stdClass();
        $participant3->id = 'someuseruuid';
        $participant3->user_id = 234;
        $participant3->name = 'Joe Smith';
        $participant3->user_email = 'joe@test.com';
        $participant3->join_time = '2020-04-01T15:05:00Z';
        $participant3->leave_time = '2020-04-01T15:35:00Z';
        $participant3->duration = 30 * 60;
        $this->mockparticipantsdata['someuuid'][] = $participant3;
        $this->assertTrue($this->meetingtask->process_meeting_reports($meeting));
        $this->assertEquals(1, $DB->count_records('zoom_meeting_details'));
        $this->assertEquals(3, $DB->count_records('zoom_meeting_participants'));
    }

    /**
     * Tests that normalize_meeting() can handle different meeting records from
     * Dashboard API versus the Report API.
     */
    public function test_normalize_meeting(): void {
        $dashboardmeeting = [
            'uuid' => 'sfsdfsdfc6122222d',
            'id' => 1000000,
            'topic' => 'Awesome meeting',
            'host' => 'John Doe',
            'email' => 'test@email.com',
            'user_type' => 2,
            'start_time' => '2019-07-14T09:05:19.754Z',
            'end_time' => '2019-07-14T10:26:37.754Z',
            'duration' => '01:21:18',
            'participants' => 4,
            'has_pstn' => false,
            'has_voip' => false,
            'has_3rd_party_audio' => false,
            'has_video' => false,
            'has_screen_share' => false,
            'has_recording' => false,
            'has_sip' => false,
        ];
        $meeting = $this->meetingtask->normalize_meeting((object) $dashboardmeeting);

        $this->assertEquals($dashboardmeeting['uuid'], $meeting->uuid);
        $this->assertFalse(isset($meeting->id));
        $this->assertEquals($dashboardmeeting['id'], $meeting->meeting_id);
        $this->assertEquals($dashboardmeeting['topic'], $meeting->topic);
        $this->assertIsInt($meeting->start_time);
        $this->assertIsInt($meeting->end_time);
        $this->assertEquals($meeting->duration, 1 * 3600 + 21 * 60 + 18);
        $this->assertEquals($dashboardmeeting['participants'], $meeting->participants_count);
        $this->assertNull($meeting->total_minutes);

        // Try duration under an hour.
        $dashboardmeeting['duration'] = '10:01';
        $meeting = $this->meetingtask->normalize_meeting((object) $dashboardmeeting);
        $this->assertEquals($meeting->duration, 10 * 60 + 1);

        $reportmeeting = [
            'uuid' => 'sfsdfsdfc6122222d',
            'id' => 1000000,
            'type' => 2,
            'topic' => 'Awesome meeting',
            'user_name' => 'John Doe',
            'user_email' => 'test@email.com',
            'start_time' => '2019-07-14T09:05:19.754Z',
            'end_time' => '2019-07-14T09:16:19.754Z',
            'duration' => 11 * 60,
            'total_minutes' => 11,
            'participants_count' => 4,
        ];

        $meeting = $this->meetingtask->normalize_meeting((object) $reportmeeting);

        $this->assertEquals($reportmeeting['uuid'], $meeting->uuid);
        $this->assertFalse(isset($meeting->id));
        $this->assertEquals($reportmeeting['id'], $meeting->meeting_id);
        $this->assertEquals($reportmeeting['topic'], $meeting->topic);
        $this->assertIsInt($meeting->start_time);
        $this->assertIsInt($meeting->end_time);
        $this->assertEquals($reportmeeting['participants_count'], $meeting->participants_count);
        $this->assertEquals($reportmeeting['total_minutes'], $meeting->total_minutes);
    }

    /**
     * Testing the grading method according to users duration in a meeting.
     * @return void
     */
    public function test_grading_method(): void {
        global $DB;
        $this->setAdminUser();
        // Make sure we start with nothing.
        // Deleting all records from previous tests.
        if ($DB->count_records('zoom_meeting_details') > 0) {
            $DB->delete_records('zoom_meeting_details');
        }

        if ($DB->count_records('zoom_meeting_participants') > 0) {
            $DB->delete_records('zoom_meeting_participants');
        }

        // Generate fake course.
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        // Check that this teacher has the required capability to receive notification.
        $context = context_course::instance($course->id);
        $graders = get_users_by_capability($context, 'moodle/grade:edit');
        $this->assertEquals(1, count($graders));
        $firstkey = key($graders);
        $this->assertEquals($graders[$firstkey]->id, $teacher->id);
        // Now fake the meeting details.
        $meeting = new stdClass();
        $meeting->id = 456123;
        $meeting->topic = 'Some meeting';
        $meeting->start_time = '2020-04-01T15:00:00Z';
        $meeting->end_time = '2020-04-01T17:00:00Z';
        $meeting->uuid = 'someuuid123';
        $meeting->duration = 120 * 60; // In seconds.
        $meeting->participants = 4;

        // Create a new zoom instance.
        $params = [
            'course' => $course->id,
            'meeting_id' => $meeting->id,
            'grade' => 60,
            'name' => 'Zoom',
            'exists_on_zoom' => ZOOM_MEETING_EXISTS,
            'start_time' => strtotime('2020-04-01T15:00:00Z'),
            'duration' => 120 * 60, // In seconds.
        ];

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_zoom');
        $instance = $generator->create_instance($params);
        $id = $instance->id;
        $cmid = $instance->cmid;
        // Normalize the meeting.
        $meeting = $this->meetingtask->normalize_meeting($meeting);
        $meeting->zoomid = $id;

        $detailsid = $DB->insert_record('zoom_meeting_details', $meeting);

        $zoomrecord = $DB->get_record('zoom', ['id' => $id]);
        // Create users and corresponding meeting participants.
        $rawparticipants = [];
        $participants = [];
        // Enroll a bunch of users. Note: names were generated by
        // https://www.behindthename.com/random/ and any similarity to anyone
        // real or fictional is coincidence and not intentional.
        $users[0] = $this->getDataGenerator()->create_user([
            'lastname' => 'Arytis',
            'firstname' => 'Oitaa',
        ]);

        $users[1] = $this->getDataGenerator()->create_user([
            'lastname' => 'Chouxuong',
            'firstname' => 'Khah',
        ]);
        $users[2] = $this->getDataGenerator()->create_user([
            'lastname' => 'Spialdiouniem',
            'firstname' => 'Basem',
        ]);
        $users[3] = $this->getDataGenerator()->create_user([
            'lastname' => 'Padhzinnuj',
            'firstname' => 'Nibba',
        ]);
        $users[4] = $this->getDataGenerator()->create_user([
            'lastname' => 'Apea',
            'firstname' => 'Ziqit',
        ]);

        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }
        [$names, $emails] = $this->meetingtask->get_enrollments($course->id);

        // Create a participant with 5 min overlap.
        // Total time 35 min, total grade 17.5 .
        $rawparticipants[1] = (object) [
            'id' => 32132165,
            'user_id' => 4456,
            'name' => 'Oitaa Arytis',
            'user_email' => '',
            'join_time' => '2023-05-01T15:05:00Z',
            'leave_time' => '2023-05-01T15:35:00Z',
            'duration' => 30 * 60,
        ];
        $participants[1] = (object) $this->meetingtask->format_participant($rawparticipants[1], $detailsid, $names, $emails);
        $rawparticipants[2] = (object) [
            'id' => 32132165,
            'user_id' => 4456,
            'name' => 'Oitaa Arytis',
            'user_email' => '',
            'join_time' => '2023-05-01T15:30:00Z',
            'leave_time' => '2023-05-01T15:40:00Z',
            'duration' => 10 * 60,
        ];
        $participants[2] = (object) $this->meetingtask->format_participant($rawparticipants[2], $detailsid, $names, $emails);
        $overlap = $this->meetingtask->get_participant_overlap_time($participants[1], $participants[2]);
        $this->assertEquals(5 * 60, $overlap);
        // Also check for the same result if the data inverted.
        $overlap = $this->meetingtask->get_participant_overlap_time($participants[2], $participants[1]);
        $this->assertEquals(5 * 60, $overlap);

        // Create a participant with 30 min overlap.
        // Total duration 60 min. expect a mark of 30 .
        $rawparticipants[3] = (object) [
            'id' => '',
            'user_id' => 1234,
            'name' => 'Chouxuong Khah',
            'user_email' => '',
            'join_time' => '2023-05-01T15:00:00Z',
            'leave_time' => '2023-05-01T16:00:00Z',
            'duration' => 60 * 60,
        ];
        $participants[3] = (object) $this->meetingtask->format_participant($rawparticipants[3], $detailsid, $names, $emails);
        $rawparticipants[4] = (object) [
            'id' => '',
            'user_id' => 1234,
            'name' => 'Chouxuong Khah',
            'user_email' => '',
            'join_time' => '2023-05-01T15:30:00Z',
            'leave_time' => '2023-05-01T16:00:00Z',
            'duration' => 30 * 60,
        ];
        $participants[4] = (object) $this->meetingtask->format_participant($rawparticipants[4], $detailsid, $names, $emails);
        $overlap = $this->meetingtask->get_participant_overlap_time($participants[3], $participants[4]);
        $this->assertEquals(30 * 60, $overlap);
        // Also check for the same result if the data inverted.
        $overlap = $this->meetingtask->get_participant_overlap_time($participants[4], $participants[3]);
        $this->assertEquals(30 * 60, $overlap);

        // Another user with no overlaping.
        // Total duration 60 min. Expect mark 30 .
        $rawparticipants[5] = (object) [
            'id' => '',
            'user_id' => 564312,
            'name' => 'Spialdiouniem Basem',
            'user_email' => '',
            'join_time' => '2023-05-01T15:10:00Z',
            'leave_time' => '2023-05-01T16:00:00Z',
            'duration' => 50 * 60,
        ];
        $participants[5] = (object) $this->meetingtask->format_participant($rawparticipants[5], $detailsid, $names, $emails);
        $rawparticipants[6] = (object) [
            'id' => '',
            'user_id' => 564312,
            'name' => 'Spialdiouniem Basem',
            'user_email' => '',
            'join_time' => '2023-05-01T16:30:00Z',
            'leave_time' => '2023-05-01T16:40:00Z',
            'duration' => 10 * 60,
        ];
        $participants[6] = (object) $this->meetingtask->format_participant($rawparticipants[6], $detailsid, $names, $emails);

        $overlap = $this->meetingtask->get_participant_overlap_time($participants[5], $participants[6]);
        $this->assertEquals(0, $overlap);
        // Also check for the same result if the data inverted.
        $overlap = $this->meetingtask->get_participant_overlap_time($participants[6], $participants[5]);
        $this->assertEquals(0, $overlap);

        // Adding another participant.
        // Total duration 90 min, expect mark 45 .
        $rawparticipants[7] = (object) [
            'id' => '',
            'user_id' => 789453,
            'name' => 'Padhzinnuj Nibba',
            'user_email' => '',
            'join_time' => '2023-05-01T15:30:00Z',
            'leave_time' => '2023-05-01T17:00:00Z',
            'duration' => 90 * 60,
        ];

        // Adding a participant at which matching names will fail.
        // His duration is 110 min, this grant him a grade of 55.
        $rawparticipants[8] = (object) [
            'id' => '',
            'user_id' => 168452,
            'name' => 'Farouk',
            'user_email' => '',
            'join_time' => '2023-05-01T15:10:00Z',
            'leave_time' => '2023-05-01T17:00:00Z',
            'duration' => 110 * 60,
        ];
        $this->mockparticipantsdata['someuuid123'] = $rawparticipants;
        // First mock the webservice object, so we can inject the return values
        // for get_meeting_participants.
        $mockwwebservice = $this->createMock('\mod_zoom\webservice');
        $this->meetingtask->service = $mockwwebservice;
        // Make get_meeting_participants() return our results array.
        $mockwwebservice->method('get_meeting_participants')
            ->will($this->returnCallback([$this, 'mock_get_meeting_participants']));

        $this->assertEquals(
            $this->mockparticipantsdata['someuuid123'],
            $mockwwebservice->get_meeting_participants('someuuid123', false)
        );

        // Now let's test the grades.
        $DB->set_field('zoom', 'grading_method', 'period', ['id' => $zoomrecord->id]);

        // Prepare messages.
        $this->preventResetByRollback(); // Messaging does not like transactions...
        $sink = $this->redirectMessages();
        // Process meeting reports should call the function grading_participant_upon_duration
        // and insert grades.
        $this->assertTrue($this->meetingtask->process_meeting_reports($meeting));
        $this->assertEquals(1, $DB->count_records('zoom_meeting_details'));
        $this->assertEquals(8, $DB->count_records('zoom_meeting_participants'));

        $usersids = [];
        foreach ($users as $user) {
            $usersids[] = $user->id;
        }
        // Get the gradelist for all users created.
        $gradelist = grade_get_grades($course->id, 'mod', 'zoom', $zoomrecord->id, $usersids);

        $gradelistitems = $gradelist->items;
        $grades = $gradelistitems[0]->grades;
        // Check grades of first user.
        $grade = $grades[$users[0]->id]->grade;
        $this->assertEquals(17.5, $grade);
        // Check grades of second user.
        $grade = $grades[$users[1]->id]->grade;
        $this->assertEquals(30, $grade);
        // Check grades of third user.
        $grade = $grades[$users[2]->id]->grade;
        $this->assertEquals(30, $grade);
        // Check grades for fourth user.
        $grade = $grades[$users[3]->id]->grade;
        $this->assertEquals(45, $grade);
        // This user didn't enter the meeting.
        $grade = $grades[$users[4]->id]->grade;
        $this->assertEquals(null, $grade);
        // Let's check the teacher notification if it is ok?
        $messages = $sink->get_messages();
        // Only one teacher, means only one message.
        $this->assertEquals(1, count($messages));
        // Verify that it has been sent to the teacher.
        $this->assertEquals($teacher->id, $messages[0]->useridto);
        // Check the content of the message.
        // Grading item url.
        $gurl = new moodle_url(
            '/grade/report/singleview/index.php',
            [
                'id' => $course->id,
                'item' => 'grade',
                'itemid' => $gradelistitems[0]->id,
            ]
        );
        $gradeurl = html_writer::link($gurl, get_string('gradinglink', 'mod_zoom'));

        // Zoom instance url.
        $zurl = new moodle_url('/mod/zoom/view.php', ['id' => $cmid]);
        $zoomurl = html_writer::link($zurl, $zoomrecord->name);
        // The user need grading.
        $needgradestr = get_string('grading_needgrade', 'mod_zoom');
        $needgrade[] = '(Name: Farouk, grade: 55)';
        $needgrade = $needgradestr . implode('<br>', $needgrade) . "\n";

        $a = (object) [
            'name' => $zoomrecord->name,
            'graded' => 4,
            'alreadygraded' => 0,
            'needgrade' => $needgrade,
            'number' => 1,
            'gradeurl' => $gradeurl,
            'zoomurl' => $zoomurl,
            'notfound' => '',
            'notenrolled' => '',
        ];
        $messagecontent = get_string('gradingmessagebody', 'mod_zoom', $a);
        $this->assertStringContainsString($messagecontent, $messages[0]->fullmessage);

        // Redo the process again to be sure that no grades have been changed.
        $this->assertTrue($this->meetingtask->process_meeting_reports($meeting));
        $this->assertEquals(1, $DB->count_records('zoom_meeting_details'));
        $this->assertEquals(8, $DB->count_records('zoom_meeting_participants'));
        $gradelist = grade_get_grades($course->id, 'mod', 'zoom', $zoomrecord->id, $usersids);
        $gradelistitems = $gradelist->items;
        $grades = $gradelistitems[0]->grades;
        // Check grade for first user.
        $grade = $grades[$users[0]->id]->grade;
        $this->assertEquals(17.5, $grade);
        // Check grade for second user.
        $grade = $grades[$users[1]->id]->grade;
        $this->assertEquals(30, $grade);
        // Check grade for third user.
        $grade = $grades[$users[2]->id]->grade;
        $this->assertEquals(30, $grade);
        // Check grade for fourth user.
        $grade = $grades[$users[3]->id]->grade;
        $this->assertEquals(45, $grade);
        // This user didn't enter the meeting.
        $grade = $grades[$users[4]->id]->grade;
        $this->assertEquals(null, $grade);

        // Let's check if the teacher notification is ok.
        $messages = $sink->get_messages();
        // No new messages as there has not been an update for participants.
        $this->assertEquals(1, count($messages));
    }
}
