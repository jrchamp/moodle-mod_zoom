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
 * Task: get_meeting_reports
 *
 * @package    mod_zoom
 * @copyright  2018 UC Regents
 * @author     Kubilay Agi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom/locallib.php');

use context_course;
use core\message\message;
use core\task\scheduled_task;
use core_user;
use dml_exception;
use Exception;
use html_writer;
use mod_zoom\not_found_exception;
use mod_zoom\retry_failed_exception;
use mod_zoom\webservice_exception;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Scheduled task to get the meeting participants for each .
 */
class get_meeting_reports extends scheduled_task {
    /**
     * Percentage in which we want similar_text to reach before we consider
     * using its results.
     */
    private const SIMILARNAME_THRESHOLD = 60;

    /**
     * Used to determine if debugging is turned on or off for outputting messages.
     * @var bool
     */
    public $debuggingenabled = false;

    /**
     * The mod_zoom\webservice instance used to query for data. Can be stubbed
     * for unit testing.
     * @var mod_zoom\webservice
     */
    public $service = null;

    /**
     * Sort meetings by end time.
     * @param array $a One meeting/webinar object array to compare.
     * @param array $b Another meeting/webinar object array to compare.
     */
    private function cmp($a, $b) {
        if ($a->end_time == $b->end_time) {
            return 0;
        }

        return ($a->end_time < $b->end_time) ? -1 : 1;
    }

    /**
     * Gets the meeting IDs from the queue, retrieve the information for each
     * meeting, then remove the meeting from the queue.
     *
     * @param string $paramstart    If passed, will find meetings starting on given date. Format is YYYY-MM-DD.
     * @param string $paramend      If passed, will find meetings ending on given date. Format is YYYY-MM-DD.
     * @param array $hostuuids      If passed, will find only meetings for given array of host uuids.
     */
    public function execute($paramstart = null, $paramend = null, $hostuuids = null) {
        try {
            $this->service = zoom_webservice();
        } catch (moodle_exception $exception) {
            mtrace('Skipping task - ', $exception->getMessage());
            return;
        }

        // See if we cannot make anymore API calls.
        $retryafter = get_config('zoom', 'retry-after');
        if (!empty($retryafter) && time() < $retryafter) {
            mtrace('Out of API calls, retry after ' . userdate($retryafter, get_string('strftimedaydatetime', 'core_langconfig')));
            return;
        }

        $this->debuggingenabled = debugging();

        // If running as a task, then record when we last left off if
        // interrupted or finish.
        $runningastask = true;

        if (!empty($hostuuids)) {
            $runningastask = false;
        }

        if (!empty($paramstart)) {
            $starttime = strtotime($paramstart);
            $runningastask = false;
        } else {
            $starttime = get_config('zoom', 'last_call_made_at');
        }

        if (empty($starttime)) {
            // Zoom only provides data from 30 days ago.
            $starttime = strtotime('-30 days');
        }

        if (!empty($paramend)) {
            $endtime = strtotime($paramend);
            $runningastask = false;
        }

        if (empty($endtime)) {
            $endtime = time();
        }

        // Zoom requires this format when passing the to and from arguments.
        // Zoom just returns all the meetings from the day range instead of
        // actual time range specified.
        $start = gmdate('Y-m-d', $starttime);
        $end = gmdate('Y-m-d', $endtime);

        mtrace(sprintf('Finding meetings between %s to %s', $start, $end));

        $recordedallmeetings = true;

        $dashboardscopes = [
            'dashboard_meetings:read:admin',
            'dashboard_webinars:read:admin',
            'dashboard:read:list_meetings:admin',
            'dashboard:read:list_webinars:admin',
        ];

        $reportscopes = [
            'report:read:admin',
            'report:read:list_users:admin',
        ];

        // Can only query on $hostuuids using Report API.
        if (empty($hostuuids) && $this->service->has_scope($dashboardscopes)) {
            $allmeetings = $this->get_meetings_via_dashboard($start, $end);
        } else if ($this->service->has_scope($reportscopes)) {
            $allmeetings = $this->get_meetings_via_reports($start, $end, $hostuuids);
        } else {
            mtrace('Skipping task - missing OAuth scopes required for reports');
            return;
        }

        // Sort all meetings based on end_time so that we know where to pick
        // up again if we run out of API calls.
        $allmeetings = array_map([$this, 'normalize_meeting'], $allmeetings);
        usort($allmeetings, [$this, 'cmp']);

        mtrace("Processing " . count($allmeetings) . " meetings");

        foreach ($allmeetings as $meeting) {
            // Only process meetings if they happened after the time we left off.
            $meetingtime = ($meeting->end_time == intval($meeting->end_time)) ? $meeting->end_time : strtotime($meeting->end_time);
            if ($runningastask && $meetingtime <= $starttime) {
                continue;
            }

            try {
                if (!$this->process_meeting_reports($meeting)) {
                    // If returned false, then ran out of API calls or got
                    // unrecoverable error. Try to pick up where we left off.
                    if ($runningastask) {
                        // Only want to resume if we were processing all reports.
                        $recordedallmeetings = false;
                        set_config('last_call_made_at', $meetingtime - 1, 'zoom');
                    }

                    break;
                }
            } catch (Exception $e) {
                mtrace($e->getMessage());
                mtrace($e->getTraceAsString());
                // Some unknown error, need to handle it so we can record
                // where we left off.
                if ($runningastask) {
                    $recordedallmeetings = false;
                    set_config('last_call_made_at', $meetingtime - 1, 'zoom');
                    break;
                }
            }
        }

        if ($recordedallmeetings && $runningastask) {
            // All finished, so save the time that we set end time for the initial query.
            set_config('last_call_made_at', $endtime, 'zoom');
        }
    }

    /**
     * Formats participants array as a record for the database.
     *
     * @param stdClass $participant Unformatted array received from web service API call.
     * @param int $detailsid The id to link to the zoom_meeting_details table.
     * @param array $names Array that contains mappings of user's moodle ID to the user's name.
     * @param array $emails Array that contains mappings of user's moodle ID to the user's email.
     * @return array Formatted array that is ready to be inserted into the database table.
     */
    public function format_participant($participant, $detailsid, $names, $emails) {
        global $DB;
        $moodleuser = null;
        $moodleuserid = null;
        $name = null;

        // Consolidate fields.
        $participant->name = $participant->name ?? $participant->user_name ?? '';
        $participant->id = $participant->id ?? $participant->participant_user_id ?? '';
        $participant->user_email = $participant->user_email ?? $participant->email ?? '';

        // Cleanup the name. For some reason # gets into the name instead of a comma.
        $participant->name = str_replace('#', ',', $participant->name);

        // Extract the ID and name from the participant's name if it is in the format "(id)Name".
        if (preg_match('/^\((\d+)\)(.+)$/', $participant->name, $matches)) {
            $moodleuserid = $matches[1];
            $name = trim($matches[2]);
        } else {
            $name = $participant->name;
        }

        // Try to see if we successfully queried for this user and found a Moodle id before.
        if (!empty($participant->id)) {
            // Sometimes uuid is blank from Zoom.
            $participantmatches = $DB->get_records(
                'zoom_meeting_participants',
                ['uuid' => $participant->id],
                null,
                'id, userid, name'
            );

            if (!empty($participantmatches)) {
                // Found some previous matches. Find first one with userid set.
                foreach ($participantmatches as $participantmatch) {
                    if (!empty($participantmatch->userid)) {
                        $moodleuserid = $participantmatch->userid;
                        $name = $participantmatch->name;
                        break;
                    }
                }
            }
        }

        // Did not find a previous match.
        if (empty($moodleuserid)) {
            if (!empty($participant->user_email) && ($moodleuserid = array_search(strtoupper($participant->user_email), $emails))) {
                // Found email from list of enrolled users.
                $name = $names[$moodleuserid];
            } else if (!empty($participant->name) && ($moodleuserid = array_search(strtoupper($participant->name), $names))) {
                // Found name from list of enrolled users.
                $name = $names[$moodleuserid];
            } else if (
                !empty($participant->user_email)
                && ($moodleuser = $DB->get_record('user', [
                    'email' => $participant->user_email,
                    'deleted' => 0,
                    'suspended' => 0,
                ], '*', IGNORE_MULTIPLE))
            ) {
                // This is the case where someone attends the meeting, but is not enrolled in the class.
                $moodleuserid = $moodleuser->id;
                $name = strtoupper(fullname($moodleuser));
            } else if (!empty($participant->name) && ($moodleuserid = $this->match_name($participant->name, $names))) {
                // Found name by using fuzzy text search.
                $name = $names[$moodleuserid];
            } else {
                // Did not find any matches, so use what is given by Zoom.
                $name = $participant->name;
                $moodleuserid = null;
            }
        }

        if ($participant->user_email === '') {
            if (!empty($moodleuserid)) {
                $participant->user_email = $DB->get_field('user', 'email', ['id' => $moodleuserid]);
            } else {
                $participant->user_email = null;
            }
        }

        if ($participant->id === '') {
            $participant->id = null;
        }

        return [
            'name' => $name,
            'userid' => $moodleuserid,
            'detailsid' => $detailsid,
            'zoomuserid' => $participant->user_id,
            'uuid' => $participant->id,
            'user_email' => $participant->user_email,
            'join_time' => strtotime($participant->join_time),
            'leave_time' => strtotime($participant->leave_time),
            'duration' => $participant->duration,
        ];
    }

    /**
     * Get enrollment for given course.
     *
     * @param int $courseid
     * @return array    Returns an array of names and emails.
     */
    public function get_enrollments($courseid) {
        // Loop through each user to generate name->uids mapping.
        $coursecontext = context_course::instance($courseid);
        $enrolled = get_enrolled_users($coursecontext);
        $names = [];
        $emails = [];
        foreach ($enrolled as $user) {
            $name = strtoupper(fullname($user));
            $names[$user->id] = $name;
            $emails[$user->id] = strtoupper(zoom_get_api_identifier($user));
        }

        return [$names, $emails];
    }

    /**
     * Get meetings first by querying for active hostuuids for given time
     * period. Then find meetings that host have given in given time period.
     *
     * This is the older method of querying for meetings. It has been superseded
     * by the Dashboard API. However, that API is only available for Business
     * accounts and higher. The Reports API is available for Pro user and up.
     *
     * This method is kept for those users that have Pro accounts and using
     * this plugin.
     *
     * @param string $start    If passed, will find meetings starting on given date. Format is YYYY-MM-DD.
     * @param string $end      If passed, will find meetings ending on given date. Format is YYYY-MM-DD.
     * @param array $hostuuids If passed, will find only meetings for given array of host uuids.
     *
     * @return array
     */
    public function get_meetings_via_reports($start, $end, $hostuuids) {
        global $DB;
        mtrace('Using Reports API');
        if (empty($hostuuids)) {
            $this->debugmsg('Empty hostuuids, querying all hosts');
            // Get all hosts.
            $activehostsuuids = $this->service->get_active_hosts_uuids($start, $end);
        } else {
            $this->debugmsg('Hostuuids passed');
            // Else we just want a specific hosts.
            $activehostsuuids = $hostuuids;
        }

        $allmeetings = [];
        $localhosts = $DB->get_records_menu('zoom', null, '', 'id, host_id');

        mtrace("Processing " . count($activehostsuuids) . " active host uuids");

        foreach ($activehostsuuids as $activehostsuuid) {
            // This API call returns information about meetings and webinars,
            // don't need extra functionality for webinars.
            $usersmeetings = [];
            if (in_array($activehostsuuid, $localhosts)) {
                $this->debugmsg('Getting meetings for host uuid ' . $activehostsuuid);
                try {
                    $usersmeetings = $this->service->get_user_report($activehostsuuid, $start, $end);
                } catch (not_found_exception $e) {
                    // Zoom API returned user not found for a user it said had,
                    // meetings. Have to skip user.
                    $this->debugmsg("Skipping $activehostsuuid because user does not exist on Zoom");
                    continue;
                } catch (retry_failed_exception $e) {
                    // Hit API limit, so cannot continue.
                    mtrace($e->response . ': ' . $e->zoomerrorcode);
                    return;
                }
            } else {
                // Ignore hosts who hosted meetings outside of integration.
                continue;
            }

            $this->debugmsg(sprintf('Found %d meetings for user', count($usersmeetings)));
            foreach ($usersmeetings as $usermeeting) {
                $allmeetings[] = $usermeeting;
            }
        }

        return $allmeetings;
    }

    /**
     * Get meetings and webinars using Dashboard API.
     *
     * @param string $start    If passed, will find meetings starting on given date. Format is YYYY-MM-DD.
     * @param string $end      If passed, will find meetings ending on given date. Format is YYYY-MM-DD.
     *
     * @return array
     */
    public function get_meetings_via_dashboard($start, $end) {
        mtrace('Using Dashboard API');

        $meetingscopes = [
            'dashboard_meetings:read:admin',
            'dashboard:read:list_meetings:admin',
        ];

        $webinarscopes = [
            'dashboard_webinars:read:admin',
            'dashboard:read:list_webinars:admin',
        ];

        $meetings = [];
        if ($this->service->has_scope($meetingscopes)) {
            $meetings = $this->service->get_meetings($start, $end);
        }

        $webinars = [];
        if ($this->service->has_scope($webinarscopes)) {
            $webinars = $this->service->get_webinars($start, $end);
        }

        $allmeetings = array_merge($meetings, $webinars);

        return $allmeetings;
    }

    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('getmeetingreports', 'mod_zoom');
    }

    /**
     * Tries to match a given name to the roster using two different fuzzy text
     * matching algorithms and if they match, then returns the match.
     *
     * @param string $nametomatch
     * @param array $rosternames    Needs to be an array larger than 3 for any
     *                              meaningful results.
     *
     * @return int  Returns id for $rosternames. Returns false if no match found.
     */
    private function match_name($nametomatch, $rosternames) {
        if (count($rosternames) < 3) {
            return false;
        }

        $nametomatch = strtoupper($nametomatch);
        $similartextscores = [];
        $levenshteinscores = [];
        foreach ($rosternames as $name) {
            similar_text($nametomatch, $name, $percentage);
            if ($percentage > self::SIMILARNAME_THRESHOLD) {
                $similartextscores[$name] = $percentage;
                $levenshteinscores[$name] = levenshtein($nametomatch, $name);
            }
        }

        // If we did not find any quality matches, then return false.
        if (empty($similartextscores)) {
            return false;
        }

        // Simlar text has better matches with higher numbers.
        arsort($similartextscores);
        reset($similartextscores);  // Make sure key gets first element.
        $stmatch = key($similartextscores);

        // Levenshtein has better matches with lower numbers.
        asort($levenshteinscores);
        reset($levenshteinscores);  // Make sure key gets first element.
        $lmatch = key($levenshteinscores);

        // If both matches, then we can be rather sure that it is the same user.
        if ($stmatch == $lmatch) {
            $moodleuserid = array_search($stmatch, $rosternames);
            return $moodleuserid;
        } else {
            return false;
        }
    }

    /**
     * Outputs finer grained debugging messaging if debug mode is on.
     *
     * @param string $msg
     */
    public function debugmsg($msg) {
        if ($this->debuggingenabled) {
            mtrace($msg);
        }
    }

    /**
     * Saves meeting details and participants for reporting.
     *
     * @param object $meeting    Normalized meeting object
     * @return boolean
     */
    public function process_meeting_reports($meeting) {
        global $DB;

        $this->debugmsg(sprintf(
            'Processing meeting %s|%s that occurred at %s',
            $meeting->meeting_id,
            $meeting->uuid,
            $meeting->start_time
        ));

        // If meeting doesn't exist in the zoom database, the instance is
        // deleted, and we don't need reports for these.
        if (!($zoomrecord = $DB->get_record('zoom', ['meeting_id' => $meeting->meeting_id], '*', IGNORE_MULTIPLE))) {
            mtrace('Meeting does not exist locally; skipping');
            return true;
        }

        $meeting->zoomid = $zoomrecord->id;

        // Insert or update meeting details.
        if (!($DB->record_exists('zoom_meeting_details', ['uuid' => $meeting->uuid]))) {
            $this->debugmsg('Inserting zoom_meeting_details');
            $detailsid = $DB->insert_record('zoom_meeting_details', $meeting);
        } else {
            // Details entry already exists, so update it.
            $this->debugmsg('Updating zoom_meeting_details');
            $detailsid = $DB->get_field('zoom_meeting_details', 'id', ['uuid' => $meeting->uuid]);
            $meeting->id = $detailsid;
            $DB->update_record('zoom_meeting_details', $meeting);
        }

        try {
            $participants = $this->service->get_meeting_participants($meeting->uuid, $zoomrecord->webinar);
        } catch (not_found_exception $e) {
            mtrace(sprintf('Warning: Cannot find meeting %s|%s; skipping', $meeting->meeting_id, $meeting->uuid));
            return true;    // Not really a show stopping error.
        } catch (webservice_exception $e) {
            mtrace($e->response . ': ' . $e->zoomerrorcode);
            return false;
        }

        // Loop through each user to generate name->uids mapping.
        [$names, $emails] = $this->get_enrollments($zoomrecord->course);

        $this->debugmsg(sprintf('Processing %d participants', count($participants)));

        // Now try to insert new participant records.
        // There is no unique key, so we make sure each record's data is distinct.
        try {
            $transaction = $DB->start_delegated_transaction();

            $count = $DB->count_records('zoom_meeting_participants', ['detailsid' => $detailsid]);
            if (!empty($count)) {
                $this->debugmsg(sprintf('Existing participant records: %d', $count));
                // No need to delete old records, we don't insert matching records.
            }

            // To prevent sending notifications every time the task ran check if there is inserted new records.
            $recordupdated = false;
            foreach ($participants as $rawparticipant) {
                $this->debugmsg(sprintf(
                    'Working on %s (user_id: %d, uuid: %s)',
                    $rawparticipant->name,
                    $rawparticipant->user_id,
                    $rawparticipant->id
                ));
                $participant = $this->format_participant($rawparticipant, $detailsid, $names, $emails);

                // These conditions are enough.
                $conditions = [
                    'name' => $participant['name'],
                    'userid' => $participant['userid'],
                    'detailsid' => $participant['detailsid'],
                    'zoomuserid' => $participant['zoomuserid'],
                    'join_time' => $participant['join_time'],
                    'leave_time' => $participant['leave_time'],
                ];

                // Check if the record already exists.
                if ($record = $DB->get_record('zoom_meeting_participants', $conditions)) {
                    // The exact record already exists, so do nothing.
                    $this->debugmsg('Record already exists ' . $record->id);
                } else {
                    // Insert all new records.
                    $recordid = $DB->insert_record('zoom_meeting_participants', $participant, true);
                    // At least one new record inserted.
                    $recordupdated = true;
                    $this->debugmsg('Inserted record ' . $recordid);
                }
            }

            // If there are new records and the grading method is attendance duration.
            // Check the grading method settings.
            if (!empty($zoomrecord->grading_method)) {
                $gradingmethod = $zoomrecord->grading_method;
            } else if ($defaultgrading = get_config('gradingmethod', 'zoom')) {
                $gradingmethod = $defaultgrading;
            } else {
                $gradingmethod = 'entry';
            }

            if ($recordupdated && $gradingmethod === 'period') {
                // Grade users according to their duration in the meeting.
                $this->grading_participant_upon_duration($zoomrecord, $detailsid);
            }

            $transaction->allow_commit();
        } catch (dml_exception $exception) {
            $transaction->rollback($exception);
            mtrace('ERROR: Cannot insert zoom_meeting_participants: ' . $exception->getMessage());
            return false;
        }

        $this->debugmsg('Finished updating meeting report');
        return true;
    }

    /**
     * Update the grades of users according to their duration in the meeting.
     * @param object $zoomrecord
     * @param int $detailsid
     * @return void
     */
    public function grading_participant_upon_duration($zoomrecord, $detailsid) {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');
        $courseid = $zoomrecord->course;
        $context = context_course::instance($courseid);
        // Get grade list for items.
        $gradelist = grade_get_grades($courseid, 'mod', 'zoom', $zoomrecord->id);

        // Is this meeting is not gradable, return.
        if (empty($gradelist->items)) {
            return;
        }

        $gradeitem = $gradelist->items[0];
        $itemid = $gradeitem->id;
        $grademax = $gradeitem->grademax;
        $oldgrades = $gradeitem->grades;

        // After check and testing, these timings are the actual meeting timings returned from zoom
        // ... (i.e.when the host start and end the meeting).
        // Not like those on 'zoom' table which represent the settings from zoom activity.
        $meetingtime = $DB->get_record('zoom_meeting_details', ['id' => $detailsid], 'start_time, end_time');

        // An occurrence of a cumulatively graded meeting adds to the grade of the earlier ones. All the
        // reports of the occurrence count together, as a meeting that was restarted has more than one.
        $cumulative = \mod_zoom\grades\occurrences::applies($zoomrecord);
        if ($cumulative) {
            $occurrence = \mod_zoom\grades\occurrences::get_report_occurrence($zoomrecord, $detailsid);
            if (!$occurrence) {
                return;
            }

            $grademax = $zoomrecord->grade;
            $reports = \mod_zoom\grades\occurrences::get_occurrence_reports($zoomrecord, $occurrence);
            $meetingduration = \mod_zoom\grades\occurrences::get_reports_duration($reports);
            if ($meetingduration <= 0) {
                return;
            }

            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($reports));
            $records = $DB->get_records_select('zoom_meeting_participants', "detailsid $insql", $inparams, 'join_time ASC');
            $scores = [];
        } else {
            if (empty($zoomrecord->recurring)) {
                $end = min($meetingtime->end_time, $zoomrecord->start_time + $zoomrecord->duration);
                $start = max($meetingtime->start_time, $zoomrecord->start_time);
                $meetingduration = $end - $start;
            } else {
                $meetingduration = $meetingtime->end_time - $meetingtime->start_time;
            }

            // Get the required records again.
            $records = $DB->get_records('zoom_meeting_participants', ['detailsid' => $detailsid], 'join_time ASC');
        }
        // Initialize the data arrays, indexing them later with userids.
        $durations = [];
        $join = [];
        $leave = [];
        // Looping the data to calculate the duration of each user.
        foreach ($records as $record) {
            $userid = $record->userid;
            if (empty($userid)) {
                if (is_numeric($record->name)) {
                    // In case the participant name looks like an integer, we need to avoid a conflict.
                    $userid = '~' . $record->name . '~';
                } else {
                    $userid = $record->name;
                }
            }

            // Check if there is old duration stored for this user.
            if (!empty($durations[$userid])) {
                $old = new stdClass();
                $old->duration = $durations[$userid];
                $old->join_time = $join[$userid];
                $old->leave_time = $leave[$userid];
                // Calculating the overlap time.
                $overlap = $this->get_participant_overlap_time($old, $record);

                // Set the new data for next use.
                $leave[$userid] = max($old->leave_time, $record->leave_time);
                $join[$userid] = min($old->join_time, $record->join_time);
                $durations[$userid] = $old->duration + $record->duration - $overlap;
            } else {
                $leave[$userid] = $record->leave_time;
                $join[$userid] = $record->join_time;
                $durations[$userid] = $record->duration;
            }
        }

        // Used to count the number of users being graded.
        $graded = 0;
        $alreadygraded = 0;

        // Array of unidentified users that need to be graded manually.
        $needgrade = [];

        // Array of found user ids.
        $found = [];

        // Array of non-enrolled users.
        $notenrolled = [];

        // Now check the duration for each user and grade them according to it.
        foreach ($durations as $userid => $userduration) {
            // Setup the grade according to the duration.
            $newgrade = min($userduration * $grademax / $meetingduration, $grademax);

            // Double check that this is a Moodle user.
            if (is_integer($userid) && (isset($found[$userid]) || $DB->record_exists('user', ['id' => $userid]))) {
                // Successfully found this user in Moodle.
                if (!isset($found[$userid])) {
                    $found[$userid] = true;
                }

                $oldgrade = null;
                if (isset($oldgrades[$userid])) {
                    $oldgrade = $oldgrades[$userid]->grade;
                }

                // Check if the user is enrolled before assign the grade.
                if ($cumulative && is_enrolled($context, $userid)) {
                    // The score replaces the one the user had for the occurrence, so a late report corrects it.
                    $scores[$userid] = $newgrade / $grademax;
                    $graded++;
                    $this->debugmsg('occurrence score updated for user with id: ' . $userid
                                    . ', duration =' . $userduration
                                    . ', occurrence duration =' . $meetingduration
                                    . ', User grade:' . $newgrade);
                } else if (is_enrolled($context, $userid)) {
                    // Compare with the old grade and only update if the new grade is higher.
                    // Use number_format because the old stored grade only contains 5 decimals.
                    if (empty($oldgrade) || $oldgrade < number_format($newgrade, 5)) {
                        $gradegrade = [
                            'rawgrade' => $newgrade,
                            'userid' => $userid,
                            'usermodified' => $userid,
                            'dategraded' => '',
                            'feedbackformat' => '',
                            'feedback' => '',
                        ];

                        zoom_grade_item_update($zoomrecord, $gradegrade);
                        $graded++;
                        $this->debugmsg('grade updated for user with id: ' . $userid
                                        . ', duration =' . $userduration
                                        . ', maxgrade =' . $grademax
                                        . ', meeting duration =' . $meetingduration
                                        . ', User grade:' . $newgrade);
                    } else {
                        $alreadygraded++;
                        $this->debugmsg('User already has a higher grade. Old grade: ' . $oldgrade
                                        . ', New grade: ' . $newgrade);
                    }
                } else {
                    $notenrolled[$userid] = fullname(core_user::get_user($userid));
                }
            } else {
                // This means that this user was not identified.
                // Provide information about participants that need to be graded manually.
                $a = [
                    'userid' => $userid,
                    'grade' => $newgrade,
                ];
                $needgrade[] = get_string('nonrecognizedusergrade', 'mod_zoom', $a);
            }
        }

        if ($cumulative) {
            \mod_zoom\grades\occurrences::record_report_scores($zoomrecord, $occurrence, $scores);
        }

        // Get the list of users who clicked join meeting and were not recognized by the participant report.
        $allusers = $this->get_users_clicked_join($zoomrecord);
        $notfound = [];
        foreach ($allusers as $userid) {
            if (!isset($found[$userid])) {
                $notfound[$userid] = fullname(core_user::get_user($userid));
            }
        }

        // Try not to spam the instructors, only notify them when grades have changed.
        if ($graded > 0) {
            // Sending a notification to teachers in this course about grades, and users that need to be graded manually.
            $notifydata = [
                'graded' => $graded,
                'alreadygraded' => $alreadygraded,
                'needgrade' => $needgrade,
                'courseid' => $courseid,
                'zoomid' => $zoomrecord->id,
                'itemid' => $itemid,
                'name' => $zoomrecord->name,
                'notfound' => $notfound,
                'notenrolled' => $notenrolled,
            ];
            $this->notify_teachers($notifydata);
        }
    }

    /**
     * Calculate the overlap time for a participant.
     *
     * @param object $record1 Record data 1.
     * @param object $record2 Record data 2.
     * @return int the overlap time
     */
    public function get_participant_overlap_time($record1, $record2) {
        // Determine which record starts first.
        if ($record1->join_time < $record2->join_time) {
            $old = $record1;
            $new = $record2;
        } else {
            $old = $record2;
            $new = $record1;
        }

        $oldjoin = (int) $old->join_time;
        $oldleave = (int) $old->leave_time;
        $newjoin = (int) $new->join_time;
        $newleave = (int) $new->leave_time;

        // There are three possible cases.
        if ($newjoin >= $oldleave) {
            // First case - No overlap.
            // Example: old(join: 15:00 leave: 15:30), new(join: 15:35 leave: 15:50).
            // No overlap.
            $overlap = 0;
        } else if ($newleave > $oldleave) {
            // Second case - Partial overlap.
            // Example: new(join: 15:15 leave: 15:45), old(join: 15:00 leave: 15:30).
            // 15 min overlap.
            $overlap = $oldleave - $newjoin;
        } else {
            // Third case - Complete overlap.
            // Example:  new(join: 15:15 leave: 15:29), old(join: 15:00 leave: 15:30).
            // 14 min overlap (new duration).
            $overlap = $new->duration;
        }

        return $overlap;
    }

    /**
     * Sending a notification to all teachers in the course notify them about grading
     * also send the names of the users needing a manual grading.
     * return array of messages ids and false if there is no users in this course
     * with the capability of edit grades.
     *
     * @param array $data
     * @return array|bool
     */
    public function notify_teachers($data) {
        // Number of users graded automatically.
        $graded = $data['graded'];
        // Number of users already graded.
        $alreadygraded = $data['alreadygraded'];
        // Number of users need to be graded.
        $needgradenumber = count($data['needgrade']);
        // List of users need grading.
        $needgrade = '';
        if (!empty($data['needgrade'])) {
            $safeneedgrade = array_map('s', $data['needgrade']);
            $needgrade = get_string('grading_needgrade', 'mod_zoom') . implode('<br>', $safeneedgrade) . "\n";
        }

        $zoomid = $data['zoomid'];
        $itemid = $data['itemid'];
        $name = $data['name'];
        $courseid = $data['courseid'];
        $context = context_course::instance($courseid);
        // Get teachers in the course (actually those with the ability to edit grades).
        $teachers = get_enrolled_users($context, 'moodle/grade:edit', 0, 'u.*', null, 0, 0, true);

        // Grading item url.
        $gurl = new moodle_url(
            '/grade/report/singleview/index.php',
            [
                'id' => $courseid,
                'item' => 'grade',
                'itemid' => $itemid,
            ]
        );
        $gradeurl = html_writer::link($gurl, get_string('gradinglink', 'mod_zoom'));

        // Zoom instance url.
        $zurl = new moodle_url('/mod/zoom/view.php', ['id' => $zoomid]);
        $zoomurl = html_writer::link($zurl, $name);

        // Data object used in lang strings.
        $a = (object) [
            'name' => $name,
            'graded' => $graded,
            'alreadygraded' => $alreadygraded,
            'needgrade' => $needgrade,
            'number' => $needgradenumber,
            'gradeurl' => $gradeurl,
            'zoomurl' => $zoomurl,
            'notfound' => '',
            'notenrolled' => '',
        ];
        // Get the list of users clicked join meeting but not graded or reconized.
        // This helps the teacher to grade them manually.
        $notfound = $data['notfound'];
        if (!empty($notfound)) {
            $a->notfound = get_string('grading_notfound', 'mod_zoom');
            foreach ($notfound as $userid => $fullname) {
                $params = ['item' => 'user', 'id' => $courseid, 'userid' => $userid];
                $url = new moodle_url('/grade/report/singleview/index.php', $params);
                $userurl = html_writer::link($url, $fullname . ' (' . $userid . ')');
                $a->notfound .= '<br> ' . $userurl;
            }
        }

        $notenrolled = $data['notenrolled'];
        if (!empty($notenrolled)) {
            $a->notenrolled = get_string('grading_notenrolled', 'mod_zoom');
            foreach ($notenrolled as $userid => $fullname) {
                $userurl = new moodle_url('/user/profile.php', ['id' => $userid]);
                $profile = html_writer::link($userurl, $fullname);
                $a->notenrolled .= '<br>' . $profile;
            }
        }

        // Prepare the message.
        $message = new message();
        $message->component = 'mod_zoom';
        $message->name = 'teacher_notification'; // The notification name from message.php.
        $message->userfrom = core_user::get_noreply_user();

        $message->subject = get_string('gradingmessagesubject', 'mod_zoom', $a);

        $messagebody = get_string('gradingmessagebody', 'mod_zoom', $a);
        $message->fullmessage = $messagebody;

        $message->fullmessageformat = FORMAT_MARKDOWN;
        $message->fullmessagehtml = "<p>$messagebody</p>";
        $message->smallmessage = get_string('gradingsmallmeassage', 'mod_zoom', $a);
        $message->notification = 1;
        $message->contexturl = $gurl; // This link redirect the teacher to the page of item's grades.
        $message->contexturlname = get_string('gradinglink', 'mod_zoom');
        // Email content.
        $content = ['*' => ['header' => $message->subject, 'footer' => '']];
        $message->set_additional_content('email', $content);
        $messageids = [];
        if (!empty($teachers)) {
            foreach ($teachers as $teacher) {
                $message->userto = $teacher;
                // Actually send the message for each teacher.
                $messageids[] = message_send($message);
            }
        } else {
            return false;
        }

        return $messageids;
    }

    /**
     * The meeting object from the Dashboard API differs from the Report API, so
     * normalize the meeting object to conform to what is expected it the
     * database.
     *
     * @param object $meeting
     * @return object   Normalized meeting object
     */
    public function normalize_meeting($meeting) {
        $normalizedmeeting = new stdClass();

        // Returned meeting object will not be using Zoom's id, because it is a
        // primary key in our own tables.
        $normalizedmeeting->meeting_id = $meeting->id;

        // Convert times to Unixtimestamps.
        $normalizedmeeting->start_time = strtotime($meeting->start_time);
        $normalizedmeeting->end_time = strtotime($meeting->end_time);

        // Copy values that are named the same.
        $normalizedmeeting->uuid = $meeting->uuid;
        $normalizedmeeting->topic = $meeting->topic;

        // Dashboard API has duration as H:M:S while report has it in seconds.
        $timeparts = explode(':', $meeting->duration);

        // Convert duration into seconds.
        if (count($timeparts) === 1) {
            // Time is already in seconds.
            $normalizedmeeting->duration = intval($meeting->duration);
        } else if (count($timeparts) === 2) {
            // Time is in MM:SS format.
            $normalizedmeeting->duration = 60 * $timeparts[0] + $timeparts[1];
        } else {
            // Time is in HH:MM:SS format.
            $normalizedmeeting->duration = 3600 * $timeparts[0] + 60 * $timeparts[1] + $timeparts[2];
        }

        // Copy values that are named differently.
        $normalizedmeeting->participants_count = $meeting->participants ?? $meeting->participants_count;

        // Dashboard API does not have total_minutes.
        $normalizedmeeting->total_minutes = $meeting->total_minutes ?? null;

        return $normalizedmeeting;
    }

    /**
     * Get list of all users clicked (join meeting) in a given zoom instance.
     * @param object $zoomrecord
     * @return array<int>
     */
    public function get_users_clicked_join($zoomrecord) {
        global $DB;
        $logmanager = get_log_manager();
        if (!$readers = $logmanager->get_readers('core\log\sql_reader')) {
            // Should be using 2.8, use old class.
            $readers = $logmanager->get_readers('core\log\sql_select_reader');
        }

        $reader = array_pop($readers);
        if ($reader === null) {
            return [];
        }

        $params = [
            'courseid' => $zoomrecord->course,
            'objectid' => $zoomrecord->id,
        ];
        $selectwhere = "eventname = '\\\\mod_zoom\\\\event\\\\join_meeting_button_clicked'
            AND courseid = :courseid
            AND objectid = :objectid";
        $events = $reader->get_events_select($selectwhere, $params, 'userid ASC', 0, 0);

        $userids = [];
        foreach ($events as $event) {
            if (
                $event->other['meetingid'] === $zoomrecord->meeting_id &&
                !in_array($event->userid, $userids, true)
            ) {
                $userids[] = $event->userid;
            }
        }

        return $userids;
    }
}
