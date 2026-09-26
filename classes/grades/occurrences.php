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
 * Cumulative grading of the occurrences of recurring meetings.
 *
 * @package    mod_zoom
 * @copyright  2026 Moodle Zoom plugin contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\grades;

use cache;
use cache_store;
use core\lock\lock_config;
use core_availability\info_module;
use dml_write_exception;
use stdClass;

/**
 * Cumulative grading of the occurrences of recurring meetings.
 *
 * An activity graded this way has one grade item. Each occurrence is worth the grade of the
 * activity, a user's grade is the sum of what they earned in every occurrence, and the maximum
 * is the grade of the activity times the number of recorded occurrences, the same for everyone.
 *
 * Upon entry, an occurrence is the calendar event the join button was opened for, and is also
 * recorded by a scheduled task once its window opens so that a session nobody joined still counts.
 * Attendance duration, an occurrence is a cluster of meeting reports that overlap in time, so it
 * does not depend on calendar events at all.
 *
 * Scores are stored as a share of the occurrence grade, from 0 to 1. No row for a user means the
 * occurrence did not apply to them; a row with 0 means they were absent.
 */
class occurrences {
    /**
     * Whether a zoom instance is graded cumulatively.
     *
     * Only recurring meetings with a fixed time, graded with points, and created after cumulative
     * grading was installed qualify. Everything else keeps the upstream behaviour.
     *
     * @param stdClass $zoom instance object, possibly without all fields
     * @return bool
     */
    public static function applies(stdClass $zoom) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/zoom/locallib.php');

        // Form data and other partial objects may lack fields, so fill them from the database.
        $fields = ['recurring', 'recurrence_type', 'grade', 'cumulativegradingstart'];
        foreach ($fields as $field) {
            if (!property_exists($zoom, $field)) {
                if (empty($zoom->id)) {
                    return false;
                }

                $record = $DB->get_record('zoom', ['id' => $zoom->id], implode(',', $fields));
                if (!$record) {
                    return false;
                }

                foreach ($fields as $name) {
                    if (!property_exists($zoom, $name)) {
                        $zoom->$name = $record->$name;
                    }
                }

                break;
            }
        }

        return !empty($zoom->recurring)
            && $zoom->recurrence_type != ZOOM_RECURRINGTYPE_NOTIME
            && isset($zoom->grade) && $zoom->grade > 0
            && !empty($zoom->cumulativegradingstart);
    }

    /**
     * The grading method of a zoom instance, with the same fallbacks as upstream.
     *
     * @param stdClass $zoom instance object
     * @return string 'entry' or 'period'
     */
    public static function get_grading_method(stdClass $zoom) {
        if (!empty($zoom->grading_method)) {
            return $zoom->grading_method;
        } else if ($defaultgrading = get_config('gradingmethod', 'zoom')) {
            return $defaultgrading;
        }

        return 'entry';
    }

    /**
     * Give a user full credit for the occurrence the join button was opened for.
     *
     * The occurrence is the one zoom_load_meeting() has just checked the join against, which
     * zoom_get_next_occurrence() returns again from its request cache.
     *
     * @param stdClass $zoom instance object
     * @param int $userid user who clicked the join button
     * @return void
     */
    public static function record_join(stdClass $zoom, $userid) {
        global $DB;

        self::require_libraries();

        $occurrencetime = (int) zoom_get_next_occurrence($zoom);
        if ($occurrencetime <= 0) {
            // The join gate refuses this case before grading is reached.
            debugging('No occurrence to record a join of zoom ' . $zoom->id . ' against', DEBUG_DEVELOPER);
            return;
        }

        $lock = self::lock($zoom);
        try {
            $occurrence = self::get_or_create_occurrence($zoom, $occurrencetime);

            // A user is credited once per occurrence, so joining again changes nothing.
            $score = $DB->get_record('zoom_grade_occurrence_users', ['occurrenceid' => $occurrence->id, 'userid' => $userid]);
            if ($score && $score->score >= 1) {
                return;
            }

            try {
                self::set_score($occurrence, $userid, 1, $score);
            } catch (dml_write_exception $e) {
                // The same user joined twice at once and the other request credited them.
                return;
            }

            self::write_grades($zoom);
        } finally {
            self::release($lock);
        }
    }

    /**
     * Get the attendance duration occurrence a meeting report belongs to, recording it if needed.
     *
     * A report belongs to the earliest occurrence whose report interval, widened by the time users
     * may join early, overlaps it. The occurrence interval grows to cover the report. A report
     * that belongs to none starts a new occurrence at its start time.
     *
     * @param stdClass $zoom instance object
     * @param int $detailsid id of the zoom_meeting_details record
     * @return stdClass|null record of the zoom_grade_occurrences table, null if the report is missing
     */
    public static function get_report_occurrence(stdClass $zoom, $detailsid) {
        global $DB;

        $report = $DB->get_record('zoom_meeting_details', ['id' => $detailsid], 'id, start_time, end_time');
        if (!$report) {
            return null;
        }

        $start = (int) $report->start_time;
        $end = max($start, (int) $report->end_time);

        $lock = self::lock($zoom);
        try {
            $occurrence = self::find_report_occurrence($zoom, $start, $end);
            if ($occurrence) {
                if ($start < $occurrence->reportstart || $end > $occurrence->reportend) {
                    $occurrence->reportstart = min($occurrence->reportstart, $start);
                    $occurrence->reportend = max($occurrence->reportend, $end);
                    $DB->update_record('zoom_grade_occurrences', (object) [
                        'id' => $occurrence->id,
                        'reportstart' => $occurrence->reportstart,
                        'reportend' => $occurrence->reportend,
                    ]);
                }

                return $occurrence;
            }

            $occurrence = self::get_or_create_occurrence($zoom, $start, ['reportstart' => $start, 'reportend' => $end]);
            if ($occurrence->reportstart === null) {
                // An upon entry occurrence already has this time; it now covers the report too.
                $occurrence->reportstart = $start;
                $occurrence->reportend = $end;
                $DB->update_record('zoom_grade_occurrences', $occurrence);
            }

            return $occurrence;
        } finally {
            self::release($lock);
        }
    }

    /**
     * Get the meeting reports of an attendance duration occurrence.
     *
     * These are the reports the clustering rule assigns to the occurrence, so a report is never
     * counted in two occurrences.
     *
     * @param stdClass $zoom instance object
     * @param stdClass $occurrence record of the zoom_grade_occurrences table
     * @return stdClass[] records of the zoom_meeting_details table, keyed by id
     */
    public static function get_occurrence_reports(stdClass $zoom, stdClass $occurrence) {
        global $DB;

        $tolerance = self::get_tolerance();
        $candidates = $DB->get_records_select(
            'zoom_meeting_details',
            'zoomid = ? AND start_time <= ? AND end_time >= ?',
            [$zoom->id, $occurrence->reportend + $tolerance, $occurrence->reportstart - $tolerance],
            'start_time ASC',
            'id, start_time, end_time'
        );

        $reports = [];
        foreach ($candidates as $report) {
            $owner = self::find_report_occurrence($zoom, (int) $report->start_time, (int) $report->end_time);
            if ($owner && $owner->id == $occurrence->id) {
                $reports[$report->id] = $report;
            }
        }

        return $reports;
    }

    /**
     * Get how long a set of meeting reports lasted, counting the time they overlap only once.
     *
     * @param stdClass[] $reports records with start_time and end_time
     * @return int
     */
    public static function get_reports_duration(array $reports) {
        $intervals = [];
        foreach ($reports as $report) {
            $intervals[] = [(int) $report->start_time, (int) $report->end_time];
        }

        return self::union_length($intervals);
    }

    /**
     * Store the scores of an attendance duration occurrence, close it, and rewrite the grades.
     *
     * The scores replace any the users had, including the 0 of an absence, so a report that
     * arrives late corrects the occurrence.
     *
     * @param stdClass $zoom instance object
     * @param stdClass $occurrence record of the zoom_grade_occurrences table
     * @param float[] $scores share of the grade each user earned, from 0 to 1, keyed by user id
     * @return void
     */
    public static function record_report_scores(stdClass $zoom, stdClass $occurrence, array $scores) {
        global $DB;

        self::require_libraries();

        $lock = self::lock($zoom);
        try {
            $occurrence = $DB->get_record('zoom_grade_occurrences', ['id' => $occurrence->id], '*', MUST_EXIST);
            foreach ($scores as $userid => $score) {
                self::set_score($occurrence, $userid, min(1, max(0, $score)));
            }

            // The meeting has ended, so the users who did not attend it are given 0.
            if (empty($occurrence->timeclosed)) {
                self::close_occurrence($zoom, $occurrence);
            }

            self::write_grades($zoom);
        } finally {
            self::release($lock);
        }
    }

    /**
     * Record and close the upon entry occurrences of every cumulative activity.
     *
     * An occurrence is recorded once the window of its calendar event has opened, even if nobody
     * joins, and closed once the event has ended. Events that started before the activity was
     * created with cumulative grading are never recorded.
     *
     * @param int|null $now current time, now if null
     * @return array numbers of occurrences recorded and closed
     */
    public static function seed_and_close($now = null) {
        global $DB;

        self::require_libraries();

        $now = $now ?? time();
        $tolerance = self::get_tolerance();
        $seeded = 0;
        $closed = 0;

        $zooms = $DB->get_records_select(
            'zoom',
            'recurring = 1 AND recurrence_type <> ? AND grade > 0 AND cumulativegradingstart IS NOT NULL',
            [ZOOM_RECURRINGTYPE_NOTIME]
        );
        foreach ($zooms as $zoom) {
            if (!self::applies($zoom) || self::get_grading_method($zoom) !== 'entry') {
                continue;
            }

            $events = $DB->get_records_select(
                'event',
                'modulename = ? AND instance = ? AND timestart <= ? AND timestart >= ?',
                ['zoom', $zoom->id, $now + $tolerance, $zoom->cumulativegradingstart],
                'timestart ASC',
                'id, timestart, timeduration'
            );

            $lock = self::lock($zoom);
            try {
                $changed = false;
                $ends = [];
                foreach ($events as $event) {
                    $ends[(int) $event->timestart] = (int) $event->timestart + (int) $event->timeduration;
                    if (!$DB->record_exists('zoom_grade_occurrences', ['zoomid' => $zoom->id, 'occurrencetime' => $event->timestart])) {
                        self::get_or_create_occurrence($zoom, (int) $event->timestart);
                        $seeded++;
                        $changed = true;
                    }
                }

                $open = $DB->get_records_select(
                    'zoom_grade_occurrences',
                    'zoomid = ? AND timeclosed IS NULL AND flaggedforreview IS NULL AND reportstart IS NULL',
                    [$zoom->id]
                );
                foreach ($open as $occurrence) {
                    $end = $ends[(int) $occurrence->occurrencetime] ?? self::get_event_end($zoom, $occurrence->occurrencetime);
                    if ($end < $now) {
                        self::close_occurrence($zoom, $occurrence);
                        $closed++;
                        $changed = true;
                    }
                }

                if ($changed) {
                    self::write_grades($zoom);
                }
            } finally {
                self::release($lock);
            }
        }

        return ['seeded' => $seeded, 'closed' => $closed];
    }

    /**
     * Keep the upon entry occurrences in step with a rewrite of the calendar events.
     *
     * Called by zoom_calendar_item_update() once it has updated, deleted and created the events.
     * An event that has ended and disappears is history: its occurrence is kept, or recorded, and
     * closed. An event that has not ended moves its occurrence along: to its new time when the same
     * Zoom occurrence was moved, or otherwise to the next occurrence if that event was just created.
     * An occurrence with scores that cannot be moved is flagged for review and stops counting; one
     * without scores is deleted.
     *
     * @param stdClass $zoom instance object
     * @param array $moved events whose start changed: arrays with oldstart, oldend and newstart
     * @param array $removed deleted events: arrays with start and end
     * @param int[] $created start times of the events created by this rewrite
     * @param int|null $now current time, now if null
     * @return void
     */
    public static function calendar_updated(stdClass $zoom, array $moved, array $removed, array $created, $now = null) {
        global $DB;

        if ((empty($moved) && empty($removed)) || !self::applies($zoom)) {
            return;
        }

        self::require_libraries();

        $now = $now ?? time();
        $isentry = self::get_grading_method($zoom) === 'entry';
        $changed = false;

        $lock = self::lock($zoom);
        try {
            $orphans = [];

            foreach ($moved as $move) {
                if ($move['oldend'] < $now) {
                    // An occurrence that has ended stays where it happened.
                    $changed = self::keep_history($zoom, $move['oldstart'], $isentry) || $changed;
                    continue;
                }

                $occurrence = self::get_entry_occurrence($zoom, $move['oldstart']);
                if ($occurrence) {
                    self::move_occurrence($zoom, $occurrence, $move['newstart']);
                    $changed = true;
                }
            }

            foreach ($removed as $event) {
                if ($event['end'] < $now) {
                    $changed = self::keep_history($zoom, $event['start'], $isentry) || $changed;
                    continue;
                }

                $occurrence = self::get_entry_occurrence($zoom, $event['start']);
                if ($occurrence) {
                    $orphans[$occurrence->occurrencetime] = $occurrence;
                }
            }

            if (!empty($orphans)) {
                ksort($orphans);

                // The events have just been rewritten, so the cached next occurrence may be stale.
                self::reset_next_occurrence_cache($zoom);
                $target = (int) zoom_get_next_occurrence($zoom);
                if (!in_array($target, array_map('intval', $created), true)) {
                    // Only an event created by this rewrite can replace a removed one.
                    $target = null;
                }

                foreach ($orphans as $occurrence) {
                    self::move_occurrence($zoom, $occurrence, $target);
                    $target = null;
                }

                $changed = true;
            }

            if ($changed) {
                self::write_grades($zoom);
            }
        } finally {
            self::release($lock);
        }
    }

    /**
     * Resolve an occurrence flagged for review.
     *
     * @param stdClass $zoom instance object
     * @param int $occurrenceid id of the flagged occurrence
     * @param string $action 'keep' to count it as a session of its own, 'merge' to give each user
     *                       the higher of their two scores in the target occurrence and delete it,
     *                       or 'discard' to delete it with its scores
     * @param int|null $targetid id of the occurrence to merge into
     * @return void
     */
    public static function resolve_flagged(stdClass $zoom, $occurrenceid, $action, $targetid = null) {
        global $DB;

        self::require_libraries();

        $lock = self::lock($zoom);
        try {
            $occurrence = $DB->get_record(
                'zoom_grade_occurrences',
                ['id' => $occurrenceid, 'zoomid' => $zoom->id],
                '*',
                MUST_EXIST
            );
            if (empty($occurrence->flaggedforreview)) {
                throw new \moodle_exception('occurrencenotflagged', 'mod_zoom');
            }

            switch ($action) {
                case 'keep':
                    $DB->set_field('zoom_grade_occurrences', 'flaggedforreview', null, ['id' => $occurrence->id]);
                    break;

                case 'merge':
                    $target = $DB->get_record('zoom_grade_occurrences', ['id' => $targetid, 'zoomid' => $zoom->id], '*', MUST_EXIST);
                    if ($target->id == $occurrence->id || !empty($target->flaggedforreview)) {
                        throw new \moodle_exception('occurrenceinvalidtarget', 'mod_zoom');
                    }

                    $scores = $DB->get_records('zoom_grade_occurrence_users', ['occurrenceid' => $occurrence->id]);
                    foreach ($scores as $score) {
                        $existing = $DB->get_record(
                            'zoom_grade_occurrence_users',
                            ['occurrenceid' => $target->id, 'userid' => $score->userid]
                        );
                        if (!$existing || $existing->score < $score->score) {
                            self::set_score($target, $score->userid, $score->score, $existing);
                        }
                    }

                    self::delete_occurrence($occurrence->id);
                    break;

                case 'discard':
                    self::delete_occurrence($occurrence->id);
                    break;

                default:
                    throw new \coding_exception('Unknown action ' . $action);
            }

            self::write_grades($zoom);
        } finally {
            self::release($lock);
        }
    }

    /**
     * Get the occurrences of an instance that are flagged for review.
     *
     * @param int $zoomid id of the zoom instance
     * @return stdClass[] records of the zoom_grade_occurrences table, keyed by id
     */
    public static function get_flagged($zoomid) {
        global $DB;

        return $DB->get_records_select(
            'zoom_grade_occurrences',
            'zoomid = ? AND flaggedforreview IS NOT NULL',
            [$zoomid],
            'occurrencetime ASC'
        );
    }

    /**
     * Get the maximum of the activity grade: the occurrence grade times the counted occurrences.
     *
     * Occurrences flagged for review are not counted. Before the first occurrence the maximum is
     * the grade of one occurrence.
     *
     * @param stdClass $zoom instance object
     * @return float
     */
    public static function get_grademax(stdClass $zoom) {
        global $DB;

        $count = empty($zoom->id) ? 0 : $DB->count_records_select(
            'zoom_grade_occurrences',
            'zoomid = ? AND flaggedforreview IS NULL',
            [$zoom->id]
        );

        return (float) $zoom->grade * max(1, $count);
    }

    /**
     * Write the activity grade of every user, and its maximum, from the occurrence scores.
     *
     * @param stdClass $zoom instance object
     * @return void
     */
    public static function recalculate(stdClass $zoom) {
        self::require_libraries();

        $lock = self::lock($zoom);
        try {
            self::write_grades($zoom);
        } finally {
            self::release($lock);
        }
    }

    /**
     * Delete the occurrences of zoom instances, with the scores of their users.
     *
     * @param string $select SQL fragment selecting ids from the zoom table
     * @param array $params parameters of the fragment
     * @return void
     */
    public static function delete_for_zooms($select, array $params) {
        global $DB;

        $DB->delete_records_select(
            'zoom_grade_occurrence_users',
            "occurrenceid IN (SELECT id FROM {zoom_grade_occurrences} WHERE zoomid IN ($select))",
            $params
        );
        $DB->delete_records_select('zoom_grade_occurrences', "zoomid IN ($select)", $params);
    }

    /**
     * Forget the next occurrence zoom_get_next_occurrence() cached for this request.
     *
     * @param stdClass $zoom instance object
     * @return void
     */
    public static function reset_next_occurrence_cache(stdClass $zoom) {
        $cache = cache::make_from_params(
            cache_store::MODE_REQUEST,
            'zoom',
            'nextoccurrence',
            [],
            ['simplekeys' => true, 'simpledata' => true]
        );
        $cache->delete($zoom->id);
    }

    /**
     * Get the attendance duration occurrence a report interval belongs to.
     *
     * @param stdClass $zoom instance object
     * @param int $start start of the report
     * @param int $end end of the report
     * @return stdClass|null record of the zoom_grade_occurrences table
     */
    protected static function find_report_occurrence(stdClass $zoom, $start, $end) {
        global $DB;

        $tolerance = self::get_tolerance();
        $matches = $DB->get_records_select(
            'zoom_grade_occurrences',
            'zoomid = ? AND reportstart IS NOT NULL AND reportstart - ? <= ? AND reportend + ? >= ?',
            [$zoom->id, $tolerance, $end, $tolerance, $start],
            'occurrencetime ASC',
            '*',
            0,
            1
        );

        return $matches ? reset($matches) : null;
    }

    /**
     * Get the upon entry occurrence recorded at a time, unless it is flagged or already closed.
     *
     * @param stdClass $zoom instance object
     * @param int $occurrencetime start of the occurrence
     * @return stdClass|null record of the zoom_grade_occurrences table
     */
    protected static function get_entry_occurrence(stdClass $zoom, $occurrencetime) {
        global $DB;

        $occurrence = $DB->get_record('zoom_grade_occurrences', ['zoomid' => $zoom->id, 'occurrencetime' => $occurrencetime]);
        if (!$occurrence || $occurrence->reportstart !== null || !empty($occurrence->flaggedforreview)
                || !empty($occurrence->timeclosed)) {
            return null;
        }

        return $occurrence;
    }

    /**
     * Keep, or record, the occurrence of an event that has ended, and close it.
     *
     * Must be called while holding the lock of the instance.
     *
     * @param stdClass $zoom instance object
     * @param int $occurrencetime start of the event
     * @param bool $isentry whether the instance is graded upon entry
     * @return bool whether anything changed
     */
    protected static function keep_history(stdClass $zoom, $occurrencetime, $isentry) {
        global $DB;

        $occurrence = $DB->get_record('zoom_grade_occurrences', ['zoomid' => $zoom->id, 'occurrencetime' => $occurrencetime]);
        if (!$occurrence) {
            if (!$isentry || $occurrencetime < $zoom->cumulativegradingstart) {
                return false;
            }

            $occurrence = self::get_or_create_occurrence($zoom, $occurrencetime);
        }

        if (empty($occurrence->timeclosed) && empty($occurrence->flaggedforreview)) {
            self::close_occurrence($zoom, $occurrence);
        }

        return true;
    }

    /**
     * Move an upon entry occurrence to a new time, or deal with it when that is not possible.
     *
     * Must be called while holding the lock of the instance.
     *
     * @param stdClass $zoom instance object
     * @param stdClass $occurrence record of the zoom_grade_occurrences table
     * @param int|null $newtime the time to move it to, null if there is none
     * @return void
     */
    protected static function move_occurrence(stdClass $zoom, stdClass $occurrence, $newtime) {
        global $DB;

        $free = $newtime !== null
            && !$DB->record_exists('zoom_grade_occurrences', ['zoomid' => $zoom->id, 'occurrencetime' => $newtime]);
        if ($free) {
            $DB->set_field('zoom_grade_occurrences', 'occurrencetime', $newtime, ['id' => $occurrence->id]);
            return;
        }

        if ($DB->record_exists('zoom_grade_occurrence_users', ['occurrenceid' => $occurrence->id])) {
            // Never merge or drop credit automatically: a teacher decides, and until then it does not count.
            $DB->set_field('zoom_grade_occurrences', 'flaggedforreview', time(), ['id' => $occurrence->id]);
        } else {
            self::delete_occurrence($occurrence->id);
        }
    }

    /**
     * Get the end of the calendar event an upon entry occurrence belongs to.
     *
     * @param stdClass $zoom instance object
     * @param int $occurrencetime start of the occurrence
     * @return int
     */
    protected static function get_event_end(stdClass $zoom, $occurrencetime) {
        global $DB;

        $event = $DB->get_record_select(
            'event',
            'modulename = ? AND instance = ? AND timestart = ?',
            ['zoom', $zoom->id, $occurrencetime],
            'id, timestart, timeduration',
            IGNORE_MULTIPLE
        );
        if ($event) {
            return (int) $event->timestart + (int) $event->timeduration;
        }

        return (int) $occurrencetime + (int) $zoom->duration;
    }

    /**
     * Get an occurrence, recording it if it is new.
     *
     * The unique index on the instance and time decides which of two concurrent callers records it.
     *
     * @param stdClass $zoom instance object
     * @param int $occurrencetime start of the occurrence
     * @param array $fields other fields of a new occurrence
     * @return stdClass record of the zoom_grade_occurrences table
     */
    protected static function get_or_create_occurrence(stdClass $zoom, $occurrencetime, array $fields = []) {
        global $DB;

        $conditions = ['zoomid' => $zoom->id, 'occurrencetime' => $occurrencetime];
        $occurrence = $DB->get_record('zoom_grade_occurrences', $conditions);
        if ($occurrence) {
            return $occurrence;
        }

        $occurrence = (object) ($conditions + $fields + [
            'reportstart' => null,
            'reportend' => null,
            'timecreated' => time(),
            'timeclosed' => null,
            'flaggedforreview' => null,
        ]);

        try {
            $occurrence->id = $DB->insert_record('zoom_grade_occurrences', $occurrence);
        } catch (dml_write_exception $e) {
            // Recorded by a concurrent request in the meantime.
            $occurrence = $DB->get_record('zoom_grade_occurrences', $conditions, '*', MUST_EXIST);
        }

        return $occurrence;
    }

    /**
     * Store the score of a user in an occurrence.
     *
     * @param stdClass $occurrence record of the zoom_grade_occurrences table
     * @param int $userid user
     * @param float $score share of the grade the user earned, from 0 to 1
     * @param stdClass|false|null $existing the current record of the score, null to look it up
     * @return void
     */
    protected static function set_score(stdClass $occurrence, $userid, $score, $existing = null) {
        global $DB;

        if ($existing === null) {
            $existing = $DB->get_record('zoom_grade_occurrence_users', ['occurrenceid' => $occurrence->id, 'userid' => $userid]);
        }

        $now = time();
        if ($existing) {
            if (grade_floats_different($existing->score, $score)) {
                $DB->update_record('zoom_grade_occurrence_users', (object) [
                    'id' => $existing->id,
                    'score' => $score,
                    'timemodified' => $now,
                ]);
            }
        } else {
            $DB->insert_record('zoom_grade_occurrence_users', (object) [
                'occurrenceid' => $occurrence->id,
                'userid' => $userid,
                'score' => $score,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Close an occurrence, giving 0 to every user who can be graded now and has no score for it.
     *
     * Must be called while holding the lock of the instance.
     *
     * @param stdClass $zoom instance object
     * @param stdClass $occurrence record of the zoom_grade_occurrences table
     * @return void
     */
    protected static function close_occurrence(stdClass $zoom, stdClass $occurrence) {
        global $DB;

        $scored = array_flip($DB->get_fieldset_select(
            'zoom_grade_occurrence_users',
            'userid',
            'occurrenceid = ?',
            [$occurrence->id]
        ));
        foreach (array_keys(self::get_gradable_users($zoom)) as $userid) {
            if (!isset($scored[$userid])) {
                self::set_score($occurrence, $userid, 0, false);
            }
        }

        $occurrence->timeclosed = time();
        $DB->set_field('zoom_grade_occurrences', 'timeclosed', $occurrence->timeclosed, ['id' => $occurrence->id]);
    }

    /**
     * Delete an occurrence with the scores of its users.
     *
     * @param int $occurrenceid id of the occurrence
     * @return void
     */
    protected static function delete_occurrence($occurrenceid) {
        global $DB;

        $DB->delete_records('zoom_grade_occurrence_users', ['occurrenceid' => $occurrenceid]);
        $DB->delete_records('zoom_grade_occurrences', ['id' => $occurrenceid]);
    }

    /**
     * Write the activity grade of every user, and its maximum, from the occurrence scores.
     *
     * The maximum and all grades go to the gradebook in one call, which updates the grade item
     * first. Every grade is then stored against the new maximum, so the gradebook does not rescale
     * a grade given against the old one. A user who has a grade but no occurrence scores is passed
     * without a raw grade, which keeps their grade and updates its maximum.
     *
     * Must be called while holding the lock of the instance.
     *
     * @param stdClass $zoom instance object
     * @return void
     */
    protected static function write_grades(stdClass $zoom) {
        global $DB;

        $zoom = $DB->get_record('zoom', ['id' => $zoom->id], '*', MUST_EXIST);

        $sql = "SELECT u.userid,
                       SUM(CASE WHEN o.flaggedforreview IS NULL THEN u.score ELSE 0 END) AS score
                  FROM {zoom_grade_occurrence_users} u
                  JOIN {zoom_grade_occurrences} o ON o.id = u.occurrenceid
                 WHERE o.zoomid = ?
              GROUP BY u.userid";
        $grades = [];
        foreach ($DB->get_records_sql($sql, [$zoom->id]) as $total) {
            $grades[$total->userid] = [
                'userid' => $total->userid,
                'rawgrade' => $zoom->grade * $total->score,
            ];
        }

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'zoom',
            'iteminstance' => $zoom->id,
            'itemnumber' => 0,
            'courseid' => $zoom->course,
        ]);
        if ($gradeitem) {
            foreach (\grade_grade::fetch_all(['itemid' => $gradeitem->id]) ?: [] as $gradegrade) {
                if (!isset($grades[$gradegrade->userid])) {
                    $grades[$gradegrade->userid] = ['userid' => $gradegrade->userid];
                }
            }
        }

        $item = [
            'itemname' => clean_param($zoom->name, PARAM_NOTAGS),
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => self::get_grademax($zoom),
            'grademin' => 0,
        ];

        grade_update('mod/zoom', $zoom->course, 'mod', 'zoom', $zoom->id, 0, $grades ?: null, $item);
    }

    /**
     * Get the users who can be graded in a zoom instance.
     *
     * These are the users the gradebook grades, with an active enrolment, who can access the activity.
     *
     * @param stdClass $zoom instance object
     * @return stdClass[] users, keyed by id
     */
    protected static function get_gradable_users(stdClass $zoom) {
        $course = empty($zoom->course) ? null : $zoom->course;
        if (!$course) {
            return [];
        }

        // The gradebook lists its users only once its grades are up to date.
        if (grade_needs_regrade_final_grades($course)) {
            grade_regrade_final_grades($course);
        }

        $users = get_gradable_users($course, null, true);
        if (empty($users)) {
            return [];
        }

        $cm = get_fast_modinfo($course)->instances['zoom'][$zoom->id] ?? null;
        if (!$cm) {
            return [];
        }

        return (new info_module($cm))->filter_user_list($users);
    }

    /**
     * Get the length of the union of a list of intervals.
     *
     * @param array $intervals pairs of start and end times
     * @return int
     */
    protected static function union_length(array $intervals) {
        usort($intervals, function ($a, $b) {
            return $a[0] <=> $b[0];
        });

        $total = 0;
        $coveredto = null;
        foreach ($intervals as [$start, $end]) {
            if ($coveredto !== null) {
                $start = max($start, $coveredto);
            }

            if ($end > $start) {
                $total += $end - $start;
            }

            $coveredto = $coveredto === null ? $end : max($coveredto, $end);
        }

        return $total;
    }

    /**
     * How long before an occurrence users may join, which is also how far apart two reports of the
     * same attendance duration occurrence may be.
     *
     * @return int seconds
     */
    protected static function get_tolerance() {
        return (int) get_config('zoom', 'firstabletojoin') * MINSECS;
    }

    /**
     * Load the libraries this class relies on.
     *
     * @return void
     */
    protected static function require_libraries() {
        global $CFG;

        require_once($CFG->dirroot . '/mod/zoom/locallib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
    }

    /**
     * Take the lock that serialises the grading of the occurrences of an instance.
     *
     * @param stdClass $zoom instance object
     * @return \core\lock\lock|false
     */
    protected static function lock(stdClass $zoom) {
        $lock = lock_config::get_lock_factory('mod_zoom_occurrences')->get_lock('zoom' . $zoom->id, 10);
        if (!$lock) {
            debugging('Could not lock the occurrences of zoom ' . $zoom->id, DEBUG_DEVELOPER);
        }

        return $lock;
    }

    /**
     * Release a lock taken by lock().
     *
     * @param \core\lock\lock|false $lock
     * @return void
     */
    protected static function release($lock) {
        if ($lock) {
            $lock->release();
        }
    }
}
