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
 * Unit tests for cumulative grading of recurring meetings.
 *
 * @package    mod_zoom
 * @copyright  2026 Moodle Zoom plugin contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\grades;

use advanced_testcase;
use backup;
use backup_controller;
use cache;
use cache_store;
use grade_grade;
use grade_item;
use restore_controller;
use stdClass;

/**
 * Tests for cumulative grading of recurring meetings.
 *
 * @covers \mod_zoom\grades\occurrences
 */
final class occurrences_test extends advanced_testcase {
    /** @var stdClass */
    private $course;

    /** @var int one minute before the tests start, so that windows are open */
    private $now;

    /**
     * Set up.
     */
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        require_once($CFG->dirroot . '/mod/zoom/lib.php');
        require_once($CFG->dirroot . '/mod/zoom/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        set_config('firstabletojoin', 15, 'zoom');
        $this->course = $this->getDataGenerator()->create_course();
        $this->now = time();
    }

    /**
     * Build Zoom occurrences as populate_zoom_from_response() returns them.
     *
     * @param array $sessions list of [occurrence id, start, duration in seconds]
     * @return stdClass[]
     */
    private function occurrences(array $sessions) {
        $occurrences = [];
        foreach ($sessions as [$id, $start, $duration]) {
            $occurrences[] = (object) [
                'occurrence_id' => (string) $id,
                'start_time' => $start,
                'duration' => $duration,
                'status' => 'available',
            ];
        }

        return $occurrences;
    }

    /**
     * Create a recurring meeting with a fixed time.
     *
     * @param array $sessions list of [occurrence id, start, duration in seconds]
     * @param string $method grading method
     * @param int $grade points per occurrence
     * @param int|null $since cumulativegradingstart, null to leave the one set on creation
     * @return stdClass zoom record
     */
    private function create_meeting(array $sessions, $method = 'entry', $grade = 100, $since = null) {
        global $DB;

        $zoom = $this->getDataGenerator()->create_module('zoom', [
            'course' => $this->course->id,
            'recurring' => 1,
            'recurrence_type' => ZOOM_RECURRINGTYPE_DAILY,
            'grade' => $grade,
            'grading_method' => $method,
            'start_time' => $sessions ? $sessions[0][1] : $this->now,
            'duration' => 1800,
            'occurrences' => $this->occurrences($sessions),
        ]);

        if ($since !== null) {
            $DB->set_field('zoom', 'cumulativegradingstart', $since, ['id' => $zoom->id]);
        }

        return $DB->get_record('zoom', ['id' => $zoom->id]);
    }

    /**
     * Run the calendar synchronisation with a new list of occurrences from Zoom.
     *
     * @param stdClass $zoom
     * @param array $sessions list of [occurrence id, start, duration in seconds]
     */
    private function sync(stdClass $zoom, array $sessions) {
        global $DB;

        $record = $DB->get_record('zoom', ['id' => $zoom->id]);
        $record->occurrences = $this->occurrences($sessions);
        occurrences::reset_next_occurrence_cache($record);
        zoom_calendar_item_update($record);
    }

    /**
     * Record a join the way zoom_load_meeting() does once its gate let it through.
     *
     * @param stdClass $zoom
     * @param int $userid
     * @param int|null $occurrence the occurrence the gate checked, null to let it look it up now
     */
    private function join(stdClass $zoom, $userid, $occurrence = null) {
        $cache = cache::make_from_params(
            cache_store::MODE_REQUEST,
            'zoom',
            'nextoccurrence',
            [],
            ['simplekeys' => true, 'simpledata' => true]
        );
        $cache->delete($zoom->id);
        if ($occurrence !== null) {
            $cache->set($zoom->id, $occurrence);
        } else {
            // Go through the same gate as zoom_load_meeting(), which fills the request cache grading reads.
            [, $available] = zoom_get_state($zoom);
            $this->assertTrue($available, 'The join gate lets the click through');
        }

        occurrences::record_join($zoom, $userid);
    }

    /**
     * Get the grade item maximum and a user's final grade, after a full regrade.
     *
     * @param stdClass $zoom
     * @param int $userid
     * @return array [grademax, finalgrade or null]
     */
    private function grade(stdClass $zoom, $userid) {
        grade_regrade_final_grades($this->course->id);
        $item = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'zoom',
            'iteminstance' => $zoom->id,
            'itemnumber' => 0,
            'courseid' => $this->course->id,
        ]);
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);

        return [(float) $item->grademax, $grade && $grade->finalgrade !== null ? (float) $grade->finalgrade : null];
    }

    /**
     * Occurrence rows of a meeting.
     *
     * @param stdClass $zoom
     * @return stdClass[]
     */
    private function rows(stdClass $zoom) {
        global $DB;

        return array_values($DB->get_records('zoom_grade_occurrences', ['zoomid' => $zoom->id], 'occurrencetime ASC'));
    }

    /**
     * Enrol a new student.
     *
     * @return stdClass
     */
    private function student() {
        return $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Which activities are graded cumulatively.
     */
    public function test_scope(): void {
        global $DB;

        $sessions = [[1, $this->now + 3600, 1800]];
        $this->assertTrue(occurrences::applies($this->create_meeting($sessions)));

        $zoom = $this->create_meeting($sessions);
        $zoom->recurrence_type = ZOOM_RECURRINGTYPE_NOTIME;
        $this->assertFalse(occurrences::applies($zoom), 'No fixed time');

        $zoom = $this->create_meeting($sessions);
        $zoom->grade = -1;
        $this->assertFalse(occurrences::applies($zoom), 'Scale');

        $zoom = $this->create_meeting($sessions);
        $zoom->grade = 0;
        $this->assertFalse(occurrences::applies($zoom), 'No grade');

        $zoom = $this->create_meeting($sessions);
        $zoom->recurring = 0;
        $this->assertFalse(occurrences::applies($zoom), 'Not recurring');

        $zoom = $this->create_meeting($sessions);
        $DB->set_field('zoom', 'cumulativegradingstart', null, ['id' => $zoom->id]);
        $this->assertFalse(occurrences::applies($DB->get_record('zoom', ['id' => $zoom->id])), 'Existing activity');

        // A partial object, as the edit form gives, is completed from the database.
        $zoom = $this->create_meeting($sessions);
        $this->assertTrue(occurrences::applies((object) ['id' => $zoom->id]));
    }

    /**
     * The staging incident: a session moved three times with a scored join in between, the ids kept.
     */
    public function test_600_regression_same_occurrence_id(): void {
        $student = $this->student();
        // Each move keeps the session within the 15 minute join window, as on staging.
        $start = $this->now + 2 * MINSECS;
        $zoom = $this->create_meeting([[1, $start, 1200]], 'entry', 200);

        $this->join($zoom, $student->id);
        $this->sync($zoom, [[1, $start + 5 * MINSECS, 1200]]);
        $this->join($zoom, $student->id);
        $this->sync($zoom, [[1, $start + 10 * MINSECS, 1200]]);
        $this->join($zoom, $student->id);

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($start + 10 * MINSECS, $rows[0]->occurrencetime);
        $this->assertNull($rows[0]->flaggedforreview);
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $student->id));
    }

    /**
     * The staging incident when Zoom gives the moved session a new occurrence id, as it did on staging.
     */
    public function test_600_regression_new_occurrence_id(): void {
        $student = $this->student();
        $other = $this->student();
        $start = $this->now + 2 * MINSECS;
        $zoom = $this->create_meeting([[$start * 1000, $start, 1200]], 'entry', 200);

        $this->join($zoom, $student->id);
        $second = $start + 5 * MINSECS;
        $this->sync($zoom, [[$second * 1000, $second, 1200]]);
        $this->join($zoom, $student->id);
        $third = $start + 10 * MINSECS;
        $this->sync($zoom, [[$third * 1000, $third, 1200]]);
        $this->join($zoom, $student->id);
        $this->join($zoom, $other->id);

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($third, $rows[0]->occurrencetime);
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $student->id));
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $other->id));
    }

    /**
     * A series edit that renumbers every future session moves only the recorded one.
     */
    public function test_series_edit_moves_only_the_recorded_occurrence(): void {
        $student = $this->student();
        $start = $this->now + 5 * MINSECS;
        $zoom = $this->create_meeting([
            [1, $start, 1800],
            [2, $start + DAYSECS, 1800],
            [3, $start + 2 * DAYSECS, 1800],
        ]);
        $this->join($zoom, $student->id);

        $moved = $start + 10 * MINSECS;
        $this->sync($zoom, [
            [11, $moved, 1800],
            [12, $moved + DAYSECS, 1800],
            [13, $moved + 2 * DAYSECS, 1800],
        ]);

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($moved, $rows[0]->occurrencetime);
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));
    }

    /**
     * A session cancelled while it had a score is flagged and stops counting; it is never moved to the next one.
     */
    public function test_cancelled_scored_occurrence_is_flagged(): void {
        $student = $this->student();
        $start = $this->now + 5 * MINSECS;
        $zoom = $this->create_meeting([[1, $start, 1800], [2, $start + DAYSECS, 1800]]);
        $this->join($zoom, $student->id);
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));

        // Today's session disappears; tomorrow's event already existed, so it is not a replacement.
        $this->sync($zoom, [[2, $start + DAYSECS, 1800]]);

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($start, $rows[0]->occurrencetime);
        $this->assertNotNull($rows[0]->flaggedforreview);
        $this->assertCount(1, occurrences::get_flagged($zoom->id));
        $this->assertEquals([100.0, 0.0], $this->grade($zoom, $student->id));
    }

    /**
     * A session cancelled before anybody was credited leaves nothing behind.
     */
    public function test_cancelled_unscored_occurrence_is_deleted(): void {
        $start = $this->now + 5 * MINSECS;
        $zoom = $this->create_meeting([[1, $start, 1800], [2, $start + DAYSECS, 1800]]);
        occurrences::seed_and_close();
        $this->assertCount(1, $this->rows($zoom));

        $this->sync($zoom, [[2, $start + DAYSECS, 1800]]);
        $this->assertCount(0, $this->rows($zoom));
    }

    /**
     * Move a scored session onto the time of another recorded one.
     *
     * @return array [zoom, occurrence a, occurrence b, student a, student b]
     */
    private function make_collision() {
        $studenta = $this->student();
        $studentb = $this->student();
        $start = $this->now + 5 * MINSECS;
        $later = $start + 5 * MINSECS;
        $zoom = $this->create_meeting([[1, $start, 1800], [2, $later, 1800]]);

        $this->join($zoom, $studenta->id, $start);
        $this->join($zoom, $studentb->id, $later);
        $this->assertCount(2, $this->rows($zoom));

        // Session 1 is moved onto session 2's time, where an occurrence exists already.
        $this->sync($zoom, [[1, $later, 1800], [2, $later, 1800]]);

        [$a, $b] = $this->rows($zoom);
        $this->assertEquals($start, $a->occurrencetime);
        $this->assertNotNull($a->flaggedforreview);
        $this->assertNull($b->flaggedforreview);

        return [$zoom, $a, $b, $studenta, $studentb];
    }

    /**
     * A collision flags the moved occurrence and leaves it out of the maximum and the totals.
     */
    public function test_collision_is_flagged_and_excluded(): void {
        [$zoom, , , $studenta, $studentb] = $this->make_collision();

        $this->assertEquals([100.0, 0.0], $this->grade($zoom, $studenta->id));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $studentb->id));
    }

    /**
     * Resolving a collision by keeping the occurrence as a session of its own.
     */
    public function test_collision_keep(): void {
        [$zoom, $a, , $studenta, $studentb] = $this->make_collision();

        occurrences::resolve_flagged($zoom, $a->id, 'keep');

        $this->assertCount(0, occurrences::get_flagged($zoom->id));
        $this->assertEquals([200.0, 100.0], $this->grade($zoom, $studenta->id));
        $this->assertEquals([200.0, 100.0], $this->grade($zoom, $studentb->id));
    }

    /**
     * Resolving a collision by merging into the other occurrence.
     */
    public function test_collision_merge(): void {
        [$zoom, $a, $b, $studenta, $studentb] = $this->make_collision();

        occurrences::resolve_flagged($zoom, $a->id, 'merge', $b->id);

        $this->assertCount(1, $this->rows($zoom));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $studenta->id));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $studentb->id));
    }

    /**
     * Resolving a collision by discarding the occurrence.
     */
    public function test_collision_discard(): void {
        [$zoom, $a, , $studenta, $studentb] = $this->make_collision();

        occurrences::resolve_flagged($zoom, $a->id, 'discard');

        $this->assertCount(1, $this->rows($zoom));
        $this->assertEquals([100.0, 0.0], $this->grade($zoom, $studenta->id));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $studentb->id));
    }

    /**
     * When Zoom drops a session that has ended, its occurrence is kept and closed, or recorded if missing.
     */
    public function test_ended_event_cleanup_keeps_history(): void {
        $student = $this->student();
        $absent = $this->student();
        $first = $this->now - 3 * HOURSECS;
        $second = $this->now - 2 * HOURSECS;
        $next = $this->now + DAYSECS;
        $zoom = $this->create_meeting(
            [[1, $first, 1800], [2, $second, 1800], [3, $next, 1800]],
            'entry',
            100,
            $this->now - DAYSECS
        );
        $this->join($zoom, $student->id, $first);

        // Zoom no longer returns the two past sessions.
        $this->sync($zoom, [[3, $next, 1800]]);

        $rows = $this->rows($zoom);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNotNull($row->timeclosed);
            $this->assertNull($row->flaggedforreview);
        }

        $this->assertEquals([200.0, 100.0], $this->grade($zoom, $student->id));
        $this->assertEquals([200.0, 0.0], $this->grade($zoom, $absent->id));
    }

    /**
     * A session nobody joined is recorded and closed by the task, and counts with zeros.
     */
    public function test_zero_attendance_session(): void {
        $student = $this->student();
        $first = $this->now - 3 * HOURSECS;
        $second = $this->now - HOURSECS;
        $zoom = $this->create_meeting([[1, $first, 1800], [2, $second, 1800]], 'entry', 100, $this->now - DAYSECS);
        $this->join($zoom, $student->id, $first);

        $result = occurrences::seed_and_close();

        $this->assertEquals(['seeded' => 1, 'closed' => 2], $result);
        $rows = $this->rows($zoom);
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[1]->timeclosed);
        $this->assertEquals([200.0, 100.0], $this->grade($zoom, $student->id));

        // Running again changes nothing.
        $this->assertEquals(['seeded' => 0, 'closed' => 0], occurrences::seed_and_close());
    }

    /**
     * Sessions that started before the activity was graded cumulatively are never recorded.
     */
    public function test_seeding_cutoff(): void {
        $zoom = $this->create_meeting([
            [1, $this->now - 2 * DAYSECS, 1800],
            [2, $this->now - HOURSECS, 1800],
        ], 'entry', 100, $this->now - DAYSECS);

        occurrences::seed_and_close();

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($this->now - HOURSECS, $rows[0]->occurrencetime);
    }

    /**
     * A future session is not recorded before its window opens.
     */
    public function test_no_seeding_before_window(): void {
        $zoom = $this->create_meeting([[1, $this->now + HOURSECS, 1800]]);
        occurrences::seed_and_close();
        $this->assertCount(0, $this->rows($zoom));
    }

    /**
     * The join and the task recording the same session end with one occurrence, in either order.
     */
    public function test_join_and_task_race(): void {
        $student = $this->student();
        $start = $this->now + 5 * MINSECS;

        $zoom = $this->create_meeting([[1, $start, 1800]]);
        occurrences::seed_and_close();
        $this->join($zoom, $student->id);
        $this->assertCount(1, $this->rows($zoom));

        $zoom = $this->create_meeting([[1, $start, 1800]]);
        $this->join($zoom, $student->id);
        occurrences::seed_and_close();
        $this->assertCount(1, $this->rows($zoom));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));
    }

    /**
     * Joining again changes nothing.
     */
    public function test_repeated_joins(): void {
        global $DB;

        $student = $this->student();
        $zoom = $this->create_meeting([[1, $this->now + 5 * MINSECS, 1800]]);
        for ($i = 0; $i < 3; $i++) {
            $this->join($zoom, $student->id);
        }

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals(1, $DB->count_records('zoom_grade_occurrence_users', ['occurrenceid' => $rows[0]->id]));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));
    }

    /**
     * Four sessions of 100 points: totals out of 400.
     */
    public function test_cumulative_totals(): void {
        $students = [$this->student(), $this->student(), $this->student(), $this->student()];
        $times = [];
        $sessions = [];
        for ($i = 0; $i < 4; $i++) {
            $times[$i] = $this->now - (10 - $i) * HOURSECS;
            $sessions[] = [$i + 1, $times[$i], 1800];
        }

        $zoom = $this->create_meeting($sessions, 'entry', 100, $this->now - DAYSECS);

        // The students attend 4, 3, 2 and none of the sessions.
        $attended = [4, 3, 2, 0];
        foreach ($students as $k => $student) {
            for ($i = 0; $i < $attended[$k]; $i++) {
                $this->join($zoom, $student->id, $times[$i]);
            }
        }

        occurrences::seed_and_close();

        foreach ($students as $k => $student) {
            $this->assertEquals([400.0, (float) (100 * $attended[$k])], $this->grade($zoom, $student->id));
        }
    }

    /**
     * A student enrolled from the third session who attends both gets 200 out of 400.
     */
    public function test_late_enrollee(): void {
        global $DB;

        $times = [];
        $sessions = [];
        for ($i = 0; $i < 4; $i++) {
            $times[$i] = $this->now - (10 - $i) * HOURSECS;
            $sessions[] = [$i + 1, $times[$i], 1800];
        }

        $zoom = $this->create_meeting($sessions, 'entry', 100, $this->now - DAYSECS);
        $early = $this->student();
        $this->join($zoom, $early->id, $times[0]);
        $this->join($zoom, $early->id, $times[1]);
        occurrences::seed_and_close($times[1] + 1800 + 1);
        $this->assertCount(2, $this->rows($zoom));

        $late = $this->student();
        $this->join($zoom, $late->id, $times[2]);
        $this->join($zoom, $late->id, $times[3]);
        occurrences::seed_and_close();

        $this->assertEquals([400.0, 200.0], $this->grade($zoom, $late->id));
        $rows = $this->rows($zoom);
        $this->assertFalse($DB->record_exists('zoom_grade_occurrence_users', ['occurrenceid' => $rows[0]->id, 'userid' => $late->id]));
        $this->assertFalse($DB->record_exists('zoom_grade_occurrence_users', ['occurrenceid' => $rows[1]->id, 'userid' => $late->id]));
        $this->assertEquals([400.0, 200.0], $this->grade($zoom, $early->id));
    }

    /**
     * The maximum grows without rescaling the grades stored earlier.
     */
    public function test_growing_maximum_does_not_rescale(): void {
        global $DB;

        $student = $this->student();
        $first = $this->now - 3 * HOURSECS;
        $second = $this->now + 5 * MINSECS;
        $zoom = $this->create_meeting([[1, $first, 1800], [2, $second, 1800]], 'entry', 100, $this->now - DAYSECS);

        $this->join($zoom, $student->id, $first);
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));

        // The second session opens; the student has not joined it.
        occurrences::seed_and_close();
        $this->assertEquals([200.0, 100.0], $this->grade($zoom, $student->id));

        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'zoom', 'iteminstance' => $zoom->id, 'itemnumber' => 0]);
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertEquals(200.0, (float) $grade->rawgrademax);
        $this->assertEquals(100.0, (float) $grade->rawgrade);
    }

    /**
     * Saving the activity or refreshing it never resets the cumulative maximum.
     */
    public function test_save_and_refresh_keep_maximum(): void {
        global $DB;

        $student = $this->student();
        $first = $this->now - 3 * HOURSECS;
        $second = $this->now + 5 * MINSECS;
        $sessions = [[1, $first, 1800], [2, $second, 1800]];
        $zoom = $this->create_meeting($sessions, 'entry', 100, $this->now - DAYSECS);
        $this->join($zoom, $student->id, $first);
        $this->join($zoom, $student->id, $second);
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $student->id));

        // What the edit form passes on save: no cumulativegradingstart.
        $form = $DB->get_record('zoom', ['id' => $zoom->id]);
        unset($form->cumulativegradingstart);
        zoom_grade_item_update($form);
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $student->id));

        zoom_update_grades($DB->get_record('zoom', ['id' => $zoom->id]));
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $student->id));

        $this->sync($zoom, $sessions);
        $this->assertEquals([200.0, 200.0], $this->grade($zoom, $student->id));

        // Changing the points per occurrence rescales the total, as the scores are shares.
        $DB->set_field('zoom', 'grade', 50, ['id' => $zoom->id]);
        zoom_grade_item_update($DB->get_record('zoom', ['id' => $zoom->id]));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));
    }

    /**
     * Add an attendance duration report with its participants and grade it as the task does.
     *
     * @param stdClass $zoom
     * @param string $uuid
     * @param int $start
     * @param int $end
     * @param array $participants list of [userid, join, leave]
     * @return int id of the report
     */
    private function report(stdClass $zoom, $uuid, $start, $end, array $participants) {
        global $DB;

        $detailsid = $DB->insert_record('zoom_meeting_details', (object) [
            'uuid' => $uuid,
            'meeting_id' => $zoom->meeting_id,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'topic' => 'Topic',
            'zoomid' => $zoom->id,
        ]);
        foreach ($participants as $i => [$userid, $join, $leave]) {
            $DB->insert_record('zoom_meeting_participants', (object) [
                'userid' => $userid,
                'zoomuserid' => 'z' . $userid . $uuid . $i,
                'uuid' => 'p' . $userid . $uuid . $i,
                'join_time' => $join,
                'leave_time' => $leave,
                'duration' => $leave - $join,
                'name' => 'User ' . $userid,
                'detailsid' => $detailsid,
            ]);
        }

        $task = new \mod_zoom\task\get_meeting_reports();
        ob_start();
        $task->grading_participant_upon_duration($DB->get_record('zoom', ['id' => $zoom->id]), $detailsid);
        ob_end_clean();

        return $detailsid;
    }

    /**
     * A late report of the same session corrects the 0 an absent student was given.
     */
    public function test_duration_late_report_corrects_zero(): void {
        $a = $this->student();
        $b = $this->student();
        $zoom = $this->create_meeting([[1, $this->now - 3 * HOURSECS, 3600]], 'period');
        $start = $this->now - 3 * HOURSECS;

        $this->report($zoom, 'r1', $start, $start + 3600, [[$a->id, $start, $start + 3600]]);
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $a->id));
        $this->assertEquals([100.0, 0.0], $this->grade($zoom, $b->id));

        // The host restarted the meeting; its report arrives later and has student b for half of it.
        $this->report($zoom, 'r2', $start + 3600 + 5 * MINSECS, $start + 7200 + 5 * MINSECS, [
            [$b->id, $start + 3600 + 5 * MINSECS, $start + 5400 + 5 * MINSECS],
        ]);

        $this->assertCount(1, $this->rows($zoom));
        $this->assertEquals([100.0, 50.0], $this->grade($zoom, $a->id));
        $this->assertEquals([100.0, 25.0], $this->grade($zoom, $b->id));
    }

    /**
     * Overlapping reports of one session are combined, not counted twice.
     */
    public function test_duration_reports_are_unioned(): void {
        $a = $this->student();
        $start = $this->now - 5 * HOURSECS;
        $zoom = $this->create_meeting([[1, $start, 3600]], 'period');

        $this->report($zoom, 'r1', $start, $start + 2400, [[$a->id, $start, $start + 2400]]);
        $this->report($zoom, 'r2', $start + 1200, $start + 3600, [[$a->id, $start + 1200, $start + 3600]]);

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($start, $rows[0]->reportstart);
        $this->assertEquals($start + 3600, $rows[0]->reportend);
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $a->id));
    }

    /**
     * Reports arriving out of order still belong to the same occurrence, and separate sessions stay separate.
     */
    public function test_duration_reports_out_of_order(): void {
        $a = $this->student();
        $start = $this->now - DAYSECS;
        $zoom = $this->create_meeting([[1, $start, 3600]], 'period');

        $this->report($zoom, 'late', $start + 2400, $start + 3600, [[$a->id, $start + 2400, $start + 3600]]);
        $this->report($zoom, 'early', $start, $start + 1800, [[$a->id, $start, $start + 1800]]);

        $rows = $this->rows($zoom);
        $this->assertCount(1, $rows);
        $this->assertEquals($start + 2400, $rows[0]->occurrencetime, 'The occurrence keeps the time it was created with');
        $this->assertEquals($start, $rows[0]->reportstart);
        // Attended 30 + 20 of the 50 minutes the reports cover.
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $a->id));

        // The next day's session is a separate occurrence.
        $this->report($zoom, 'next', $start + DAYSECS - HOURSECS, $start + DAYSECS, []);
        $this->assertCount(2, $this->rows($zoom));
        $this->assertEquals([200.0, 100.0], $this->grade($zoom, $a->id));
    }

    /**
     * Attendance duration does not depend on calendar events, so a report after its event is gone still grades.
     */
    public function test_duration_report_without_calendar_event(): void {
        global $DB;

        $a = $this->student();
        $start = $this->now - 3 * HOURSECS;
        $zoom = $this->create_meeting([[1, $start, 3600]], 'period');
        $DB->delete_records('event', ['modulename' => 'zoom', 'instance' => $zoom->id]);

        $this->report($zoom, 'r1', $start, $start + 3600, [[$a->id, $start, $start + 1800]]);

        $this->assertCount(1, $this->rows($zoom));
        $this->assertEquals([100.0, 50.0], $this->grade($zoom, $a->id));
    }

    /**
     * Activities that are not graded cumulatively keep the upstream behaviour.
     */
    public function test_other_activities_keep_upstream_behaviour(): void {
        global $DB;

        $student = $this->student();
        $start = $this->now + 5 * MINSECS;

        // An activity that existed before the upgrade.
        $zoom = $this->create_meeting([[1, $start, 1800]], 'entry', 100);
        $DB->set_field('zoom', 'cumulativegradingstart', null, ['id' => $zoom->id]);
        $zoom = $DB->get_record('zoom', ['id' => $zoom->id]);
        $this->sync($zoom, [[2, $start + 5 * MINSECS, 1800]]);
        occurrences::seed_and_close();
        zoom_grade_item_update($zoom, ['userid' => $student->id, 'rawgrade' => 100]);
        $this->assertCount(0, $this->rows($zoom));
        $this->assertEquals([100.0, 100.0], $this->grade($zoom, $student->id));

        // A recurring meeting without a fixed time.
        $notime = $this->create_meeting([], 'entry', 100);
        $DB->set_field('zoom', 'recurrence_type', ZOOM_RECURRINGTYPE_NOTIME, ['id' => $notime->id]);
        occurrences::seed_and_close();
        $this->assertCount(0, $this->rows($notime));

        // A recurring meeting graded with a scale.
        $scale = $this->getDataGenerator()->create_scale();
        $scaled = $this->create_meeting([[1, $start, 1800]], 'entry', -$scale->id);
        occurrences::seed_and_close();
        $this->assertCount(0, $this->rows($scaled));

        // A recurring meeting without a grade.
        $ungraded = $this->create_meeting([[1, $start, 1800]], 'entry', 0);
        occurrences::seed_and_close();
        $this->assertCount(0, $this->rows($ungraded));

        // A meeting that is not recurring keeps its maximum at the activity grade.
        $single = $this->getDataGenerator()->create_module('zoom', [
            'course' => $this->course->id,
            'grade' => 60,
            'start_time' => $start,
        ]);
        zoom_grade_item_update($DB->get_record('zoom', ['id' => $single->id]), ['userid' => $student->id, 'rawgrade' => 60]);
        $this->assertEquals([60.0, 60.0], $this->grade($single, $student->id));
        $this->assertCount(0, $this->rows($single));
    }

    /**
     * The duration grading of a meeting that is not recurring is left exactly as upstream.
     *
     * Upstream means to only ever raise a grade, but it reads the old grades with grade_get_grades()
     * without user ids, which returns none, so the last report wins. That is kept as it is.
     */
    public function test_non_recurring_duration_unchanged(): void {
        global $DB;

        $a = $this->student();
        $start = $this->now - 3 * HOURSECS;
        $zoom = $this->getDataGenerator()->create_module('zoom', [
            'course' => $this->course->id,
            'grade' => 100,
            'grading_method' => 'period',
            'start_time' => $start,
            'duration' => 3600,
        ]);
        $zoom = $DB->get_record('zoom', ['id' => $zoom->id]);

        $this->report($zoom, 'n1', $start, $start + 3600, [[$a->id, $start, $start + 3600]]);
        $this->report($zoom, 'n2', $start, $start + 3600, [[$a->id, $start, $start + 600]]);

        $this->assertCount(0, $this->rows($zoom));
        [$max, $grade] = $this->grade($zoom, $a->id);
        $this->assertEquals(100.0, $max);
        $this->assertEqualsWithDelta(100 * 600 / 3600, $grade, 0.001);
    }

    /**
     * Reset and delete remove the occurrences, so a recalculation cannot bring grades back.
     */
    public function test_reset_and_delete(): void {
        global $DB;

        $student = $this->student();
        $zoom = $this->create_meeting([[1, $this->now + 5 * MINSECS, 1800]]);
        $this->join($zoom, $student->id);
        $this->assertCount(1, $this->rows($zoom));

        zoom_reset_userdata((object) ['courseid' => $this->course->id, 'reset_zoom_all' => 1]);
        $this->assertCount(0, $this->rows($zoom));
        occurrences::recalculate($zoom);
        $this->assertEquals([100.0, null], $this->grade($zoom, $student->id));

        $this->join($zoom, $student->id);
        $this->assertCount(1, $this->rows($zoom));
        $cm = get_coursemodule_from_instance('zoom', $zoom->id);
        course_delete_module($cm->id);
        $this->assertEquals(0, $DB->count_records('zoom_grade_occurrences', ['zoomid' => $zoom->id]));
        $this->assertEquals(0, $DB->count_records('zoom_grade_occurrence_users'));
    }

    /**
     * Back up and restore the course, with or without user data.
     *
     * @param bool $users
     * @return int id of the new course
     */
    private function backup_and_restore($users) {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $this->course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        $folder = 'zoomtest' . ($users ? 'users' : 'nousers');
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $folder);

        $newcourseid = \restore_dbops::create_new_course('Restored', 'R' . (int) $users, $this->course->category);
        $rc = new restore_controller(
            $folder,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value($users);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * Backup and restore with user data carry the occurrences and rebuild the grades.
     */
    public function test_backup_restore_with_users(): void {
        global $DB;

        $student = $this->student();
        $first = $this->now - 3 * HOURSECS;
        $zoom = $this->create_meeting(
            [[1, $first, 1800], [2, $this->now + 5 * MINSECS, 1800]],
            'entry',
            100,
            $this->now - DAYSECS
        );
        $this->join($zoom, $student->id, $first);
        occurrences::seed_and_close();

        $newcourseid = $this->backup_and_restore(true);

        $newzoom = $DB->get_record('zoom', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEmpty($newzoom->cumulativegradingstart);
        $this->assertEquals(2, $DB->count_records('zoom_grade_occurrences', ['zoomid' => $newzoom->id]));

        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'zoom', 'iteminstance' => $newzoom->id, 'itemnumber' => 0]);
        $this->assertEquals(200.0, (float) $item->grademax);
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $student->id]);
        $this->assertEquals(100.0, (float) $grade->finalgrade);
    }

    /**
     * Backup and restore without user data start from no occurrences and one occurrence's points.
     */
    public function test_backup_restore_without_users(): void {
        global $DB;

        $student = $this->student();
        $zoom = $this->create_meeting([[1, $this->now + 5 * MINSECS, 1800]], 'entry', 100);
        $this->join($zoom, $student->id);

        $newcourseid = $this->backup_and_restore(false);

        $newzoom = $DB->get_record('zoom', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals(0, $DB->count_records('zoom_grade_occurrences', ['zoomid' => $newzoom->id]));
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'zoom', 'iteminstance' => $newzoom->id, 'itemnumber' => 0]);
        $this->assertEquals(100.0, (float) $item->grademax);
    }
}
