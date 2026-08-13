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
 * Library of interface functions and constants for module zoom
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 *
 * All the zoom specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* Moodle core API */

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function zoom_supports($feature) {
    // Adding support for FEATURE_MOD_PURPOSE (MDL-71457) and providing backward compatibility (pre-v4.0).
    if (defined('FEATURE_MOD_PURPOSE') && $feature === FEATURE_MOD_PURPOSE) {
        return MOD_PURPOSE_COMMUNICATION;
    }

    switch ($feature) {
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GROUPINGS:
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the zoom object into the database.
 *
 * Given an object containing all the necessary data (defined by the form in mod_form.php), this function
 * will create a new instance and return the id number of the new instance.
 *
 * @param stdClass $zoom Submitted data from the form in mod_form.php
 * @param mod_zoom_mod_form|null $mform The form instance (included because the function is used as a callback)
 * @return int The id of the newly inserted zoom record
 */
function zoom_add_instance(stdClass $zoom, ?mod_zoom_mod_form $mform = null) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST) || (defined('BEHAT_TEST') && BEHAT_TEST)) {
        $zoom->id = $DB->insert_record('zoom', $zoom);
        zoom_grade_item_update($zoom);
        zoom_calendar_item_update($zoom);
        return $zoom->id;
    }

    // Deals with password manager issues.
    $zoom->password = $zoom->meetingcode;
    unset($zoom->meetingcode);

    if (empty($zoom->requirepasscode)) {
        $zoom->password = '';
    }

    // Handle weekdays if weekly recurring meeting selected.
    if ($zoom->recurring && $zoom->recurrence_type == ZOOM_RECURRINGTYPE_WEEKLY) {
        $zoom->weekly_days = zoom_handle_weekly_days($zoom);
    }

    $zoom->course = (int) $zoom->course;

    $zoom->breakoutrooms = [];
    if (!empty($zoom->rooms)) {
        $breakoutrooms = zoom_build_instance_breakout_rooms_array_for_api($zoom);
        $zoom->breakoutrooms = $breakoutrooms['zoom'];
    }

    $response = zoom_webservice()->create_meeting($zoom, $zoom->coursemodule);
    $zoom = populate_zoom_from_response($zoom, $response);
    $zoom->timemodified = time();
    if (!empty($zoom->schedule_for)) {
        // Wait until after receiving a successful response from zoom to update the host
        // based on the schedule_for field. Zoom handles the schedule for on their
        // end, but returns the host as the person who created the meeting, not the person
        // that it was scheduled for.
        $correcthostzoomuser = zoom_get_user($zoom->schedule_for);
        $zoom->host_id = $correcthostzoomuser->id;
    }

    if (isset($zoom->recurring) && isset($response->occurrences) && empty($response->occurrences)) {
        // Recurring meetings did not create any occurrencces.
        // This means invalid options selected.
        // Need to rollback created meeting.
        zoom_webservice()->delete_meeting($zoom->meeting_id, $zoom->webinar);

        $redirecturl = new moodle_url('/course/view.php', ['id' => $zoom->course]);
        throw new moodle_exception('erroraddinstance', 'zoom', $redirecturl->out());
    }

    $zoom->id = $DB->insert_record('zoom', $zoom);
    if (!empty($zoom->breakoutrooms)) {
        // We ignore the API response and save the local data for breakout rooms to support dynamic users and groups.
        zoom_insert_instance_breakout_rooms($zoom->id, $breakoutrooms['db']);
    }

    // Store tracking field data for meeting.
    zoom_sync_meeting_tracking_fields($zoom->id, $response->tracking_fields ?? []);

    zoom_calendar_item_update($zoom);
    zoom_grade_item_update($zoom);

    return $zoom->id;
}

/**
 * Updates an instance of the zoom in the database and on Zoom servers.
 *
 * Given an object containing all the necessary data (defined by the form in mod_form.php), this function
 * will update an existing instance with new data.
 *
 * @param stdClass $zoom An object from the form in mod_form.php
 * @param mod_zoom_mod_form|null $mform The form instance (included because the function is used as a callback)
 * @return boolean Success/Failure
 */
function zoom_update_instance(stdClass $zoom, ?mod_zoom_mod_form $mform = null) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    // The object received from mod_form.php returns instance instead of id for some reason.
    if (isset($zoom->instance)) {
        $zoom->id = $zoom->instance;
    }

    $zoom->timemodified = time();

    // Deals with password manager issues.
    if (isset($zoom->meetingcode)) {
        $zoom->password = $zoom->meetingcode;
        unset($zoom->meetingcode);
    }

    if (property_exists($zoom, 'requirepasscode') && empty($zoom->requirepasscode)) {
        $zoom->password = '';
    }

    // Handle weekdays if weekly recurring meeting selected.
    if ($zoom->recurring && $zoom->recurrence_type == ZOOM_RECURRINGTYPE_WEEKLY) {
        $zoom->weekly_days = zoom_handle_weekly_days($zoom);
    }

    $DB->update_record('zoom', $zoom);

    $zoom->breakoutrooms = [];
    if (!empty($zoom->rooms)) {
        $breakoutrooms = zoom_build_instance_breakout_rooms_array_for_api($zoom);
        zoom_update_instance_breakout_rooms($zoom->id, $breakoutrooms['db']);
        $zoom->breakoutrooms = $breakoutrooms['zoom'];
    }

    $updatedzoomrecord = $DB->get_record('zoom', ['id' => $zoom->id]);
    $zoom->meeting_id = $updatedzoomrecord->meeting_id;
    $zoom->webinar = $updatedzoomrecord->webinar;

    // Update meeting on Zoom.
    try {
        zoom_webservice()->update_meeting($zoom, $zoom->coursemodule);
        if (!empty($zoom->schedule_for)) {
            // Only update this if we actually get a valid user.
            if ($correcthostzoomuser = zoom_get_user($zoom->schedule_for)) {
                $zoom->host_id = $correcthostzoomuser->id;
                $DB->update_record('zoom', $zoom);
            }
        }
    } catch (moodle_exception $error) {
        if (isset($mform)) {
            throw $error;
        } else {
            return false;
        }
    }

    // Get the updated meeting info from zoom, before updating calendar events.
    $response = zoom_webservice()->get_meeting_webinar_info($zoom->meeting_id, $zoom->webinar);
    $zoom = populate_zoom_from_response($zoom, $response);
    $DB->update_record('zoom', $zoom);

    // Update tracking field data for meeting.
    zoom_sync_meeting_tracking_fields($zoom->id, $response->tracking_fields ?? []);

    zoom_calendar_item_update($zoom);
    zoom_grade_item_update($zoom);

    return true;
}

/**
 * Function to handle selected weekdays, for recurring weekly meeting.
 *
 * @param stdClass $zoom The zoom instance
 * @return string The comma separated string for selected weekdays
 */
function zoom_handle_weekly_days($zoom) {
    $weekdaynumbers = [];
    for ($i = 1; $i <= 7; $i++) {
        $key = 'weekly_days_' . $i;
        if (!empty($zoom->$key)) {
            $weekdaynumbers[] = $i;
        }
    }

    return implode(',', $weekdaynumbers);
}

/**
 * Function to unset the weekly options in postprocessing.
 *
 * @param stdClass $data The form data object
 * @return stdClass $data The form data object minus weekly options.
 */
function zoom_remove_weekly_options($data) {
    // Unset the weekly_days options.
    for ($i = 1; $i <= 7; $i++) {
        $key = 'weekly_days_' . $i;
        unset($data->$key);
    }

    return $data;
}

/**
 * Function to unset the monthly options in postprocessing.
 *
 * @param stdClass $data The form data object
 * @return stdClass $data The form data object minus monthly options.
 */
function zoom_remove_monthly_options($data) {
    // Unset the monthly options.
    unset($data->monthly_repeat_option);
    unset($data->monthly_day);
    unset($data->monthly_week);
    unset($data->monthly_week_day);
    return $data;
}

/**
 * Populates a zoom meeting or webinar from a response object.
 *
 * Given a zoom meeting object from mod_form.php, this function uses the response to repopulate some of the object properties.
 *
 * @param stdClass $zoom An object from the form in mod_form.php
 * @param stdClass $response A response from an API call like 'create meeting' or 'update meeting'
 * @return stdClass A $zoom object ready to be added to the database.
 */
function populate_zoom_from_response(stdClass $zoom, stdClass $response) {
    global $CFG;
    // Inlcuded for constants.
    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    $newzoom = clone $zoom;

    $samefields = ['join_url', 'created_at', 'timezone'];
    foreach ($samefields as $field) {
        if (isset($response->$field)) {
            $newzoom->$field = $response->$field;
        }
    }

    if (isset($response->duration)) {
        $newzoom->duration = $response->duration * 60;
    }

    $newzoom->meeting_id = $response->id;
    // Need to get the meeting name from API call for comparison in the refresh_events function.
    $newzoom->apiresponsename = $response->topic;
    if (isset($response->start_time)) {
        $newzoom->start_time = strtotime($response->start_time);
    }

    $recurringtypes = [
        ZOOM_RECURRING_MEETING,
        ZOOM_RECURRING_FIXED_MEETING,
        ZOOM_RECURRING_WEBINAR,
        ZOOM_RECURRING_FIXED_WEBINAR,
    ];
    $newzoom->recurring = in_array($response->type, $recurringtypes);
    if (!empty($response->occurrences)) {
        $newzoom->occurrences = [];
        // Normalise the occurrence times.
        foreach ($response->occurrences as $occurrence) {
            $occurrence->start_time = strtotime($occurrence->start_time);
            $occurrence->duration = $occurrence->duration * 60;
            $newzoom->occurrences[] = $occurrence;
        }
    }

    if (isset($response->password)) {
        $newzoom->password = $response->password;
    }

    if (isset($response->settings->encryption_type)) {
        $newzoom->option_encryption_type = $response->settings->encryption_type;
    }

    if (isset($response->settings->join_before_host)) {
        $newzoom->option_jbh = $response->settings->join_before_host;
    }

    if (isset($response->settings->participant_video)) {
        $newzoom->option_participants_video = $response->settings->participant_video;
    }

    if (isset($response->settings->alternative_hosts)) {
        $newzoom->alternative_hosts = $response->settings->alternative_hosts;
    }

    if (isset($response->settings->mute_upon_entry)) {
        $newzoom->option_mute_upon_entry = $response->settings->mute_upon_entry;
    }

    if (isset($response->settings->meeting_authentication)) {
        $newzoom->option_authenticated_users = $response->settings->meeting_authentication;
    }

    if (isset($response->settings->waiting_room)) {
        $newzoom->option_waiting_room = $response->settings->waiting_room;
    }

    if (isset($response->settings->auto_recording)) {
        $newzoom->option_auto_recording = $response->settings->auto_recording;
    }

    return $newzoom;
}

/**
 * Removes an instance of the zoom from the database
 *
 * Given an ID of an instance of this module, this function will permanently delete the instance and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 * @throws moodle_exception if failed to delete and zoom did not issue a not found error
 */
function zoom_delete_instance($id) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    if (!$zoom = $DB->get_record('zoom', ['id' => $id])) {
        // For some reason already deleted, so let Moodle take care of the rest.
        return true;
    }

    // If the meeting is missing from zoom, don't bother with the webservice.
    if ($zoom->exists_on_zoom == ZOOM_MEETING_EXISTS) {
        try {
            zoom_webservice()->delete_meeting($zoom->meeting_id, $zoom->webinar);
        } catch (\mod_zoom\not_found_exception $error) {
            // Meeting not on Zoom, so continue.
            mtrace('Meeting not on Zoom; continuing');
        }
    }

    // If we delete a meeting instance, do we want to delete the participants?
    $meetinginstances = $DB->get_records('zoom_meeting_details', ['zoomid' => $zoom->id]);
    foreach ($meetinginstances as $meetinginstance) {
        $DB->delete_records('zoom_meeting_participants', ['detailsid' => $meetinginstance->id]);
    }

    $DB->delete_records('zoom_meeting_details', ['zoomid' => $zoom->id]);

    // Delete tracking field data for deleted meetings.
    $DB->delete_records('zoom_meeting_tracking_fields', ['meeting_id' => $zoom->id]);

    // Delete any dependent records here.
    zoom_calendar_item_delete($zoom);
    zoom_grade_item_delete($zoom);

    $DB->delete_records('zoom', ['id' => $zoom->id]);

    // Delete breakout rooms.
    zoom_delete_instance_breakout_rooms($zoom->id);

    return true;
}

/**
 * Callback function to update the Zoom event in the database and on Zoom servers.
 *
 * The function is triggered when the course module name is set via quick edit.
 *
 * @param int $courseid
 * @param stdClass $zoom Zoom Module instance object.
 * @param stdClass $cm Course Module object.
 * @return bool
 */
function zoom_refresh_events($courseid, $zoom, $cm) {
    global $CFG;

    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    try {
        // Get the updated meeting info from zoom, before updating calendar events.
        $response = zoom_webservice()->get_meeting_webinar_info($zoom->meeting_id, $zoom->webinar);
        $fullzoom = populate_zoom_from_response($zoom, $response);

        // Only if the name has changed, update meeting on Zoom.
        // Before comparing, need to apply filter on the name if applicable.
        $options = [];
        $options['context'] = \context_module::instance($cm->id);
        if (zoom_apply_filter_on_meeting_name($zoom->name, $options) !== $fullzoom->apiresponsename) {
            zoom_webservice()->update_meeting($zoom, $cm->id);
        }

        zoom_calendar_item_update($fullzoom);
        zoom_grade_item_update($fullzoom);
    } catch (moodle_exception $error) {
        return false;
    }

    return true;
}

/**
 * Given a course and a time, this module should find recent activity that has occurred in zoom activities and print it out.
 *
 * @param stdClass $course The course record
 * @param bool $viewfullnames Should we display full names
 * @param int $timestart Print activity since this timestamp
 * @return boolean True if anything was printed, otherwise false
 */
function zoom_print_recent_activity($course, $viewfullnames, $timestart) {
    return false;
}

/**
 * Prepares the recent activity data
 *
 * This callback function is supposed to populate the passed array with
 * custom activity records. These records are then rendered into HTML
 * zoom_print_recent_mod_activity().
 *
 * Returns void, it adds items into $activities and increases $index.
 *
 * @param array $activities sequentially indexed array of objects with added 'cmid' property
 * @param int $index the index in the $activities to use for the next record
 * @param int $timestart append activity since this time
 * @param int $courseid the id of the course we produce the report for
 * @param int $cmid course module id
 * @param int $userid check for a particular user's activity only, defaults to 0 (all users)
 * @param int $groupid check for a particular group's activity only, defaults to 0 (all groups)
 */
function zoom_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid = 0, $groupid = 0) {
}

/**
 * Prints single activity item prepared by zoom_get_recent_mod_activity()
 *
 * @param stdClass $activity activity record with added 'cmid' property
 * @param int $courseid the id of the course we produce the report for
 * @param bool $detail print detailed report
 * @param array $modnames as returned by get_module_types_names()
 * @param bool $viewfullnames display users' full names
 */
function zoom_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
}

/**
 * Returns all other caps used in the module
 *
 * For example, this could be array('moodle/site:accessallgroups') if the
 * module uses that capability.
 *
 * @return array
 */
function zoom_get_extra_capabilities() {
    return [];
}

/**
 * Create or update Moodle calendar event of the Zoom instance.
 *
 * @param stdClass $zoom
 */
function zoom_calendar_item_update(stdClass $zoom) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    // Based on data passed back from zoom, create/update/delete events based on data.
    $newevents = [];
    if (!$zoom->recurring) {
        $newevents[''] = zoom_populate_calender_item($zoom);
    } else if (!empty($zoom->occurrences)) {
        foreach ($zoom->occurrences as $occurrence) {
            $uuid = $occurrence->occurrence_id;
            if ($occurrence->status !== 'deleted') {
                $newevents[$uuid] = zoom_populate_calender_item($zoom, $occurrence);
            }
        }
    }

    // Fetch all the events related to this zoom instance.
    $conditions = [
        'modulename' => 'zoom',
        'instance' => $zoom->id,
    ];
    $events = $DB->get_records('event', $conditions);
    $eventfields = ['name', 'timestart', 'timeduration'];
    foreach ($events as $event) {
        $uuid = $event->uuid;
        if (isset($newevents[$uuid])) {
            // This event already exists in Moodle.
            $changed = false;
            $newevent = $newevents[$uuid];
            // Check if the important fields have actually changed.
            foreach ($eventfields as $field) {
                if ($newevent->$field !== $event->$field) {
                    $changed = true;
                }
            }

            if ($changed) {
                calendar_event::load($event)->update($newevent);
            }

            // Event has been updated, remove from the list.
            unset($newevents[$uuid]);
        } else {
            // Event does not exist in Zoom, so delete from Moodle.
            calendar_event::load($event)->delete();
        }
    }

    // Any remaining events in the array don't exist on Moodle, so create a new event.
    foreach ($newevents as $uuid => $newevent) {
        calendar_event::create($newevent, false);
    }
}

/**
 * Return an array with the days of the week.
 *
 * @return array
 */
function zoom_get_weekday_options() {
    return [
        1 => get_string('sunday', 'calendar'),
        2 => get_string('monday', 'calendar'),
        3 => get_string('tuesday', 'calendar'),
        4 => get_string('wednesday', 'calendar'),
        5 => get_string('thursday', 'calendar'),
        6 => get_string('friday', 'calendar'),
        7 => get_string('saturday', 'calendar'),
    ];
}

/**
 * Return an array with the weeks of the month.
 *
 * @return array
 */
function zoom_get_monthweek_options() {
    return [
        1 => get_string('weekoption_first', 'zoom'),
        2 => get_string('weekoption_second', 'zoom'),
        3 => get_string('weekoption_third', 'zoom'),
        4 => get_string('weekoption_fourth', 'zoom'),
        -1 => get_string('weekoption_last', 'zoom'),
    ];
}

/**
 * Populate the calendar event object, based on the zoom instance
 *
 * @param stdClass $zoom The zoom instance.
 * @param stdClass|null $occurrence The occurrence object passed from the zoom api.
 * @return stdClass The calendar event object.
 */
function zoom_populate_calender_item(stdClass $zoom, ?stdClass $occurrence = null) {
    $event = new stdClass();
    $event->type = CALENDAR_EVENT_TYPE_ACTION;
    $event->modulename = 'zoom';
    $event->eventtype = 'zoom';
    $event->courseid = $zoom->course;
    $event->instance = $zoom->id;
    $event->visible = true;
    $event->name = $zoom->name;
    if ($zoom->intro) {
        $event->description = $zoom->intro;
        $event->format = $zoom->introformat;
    }

    if (!$occurrence) {
        $event->timesort = $zoom->start_time;
        $event->timestart = $zoom->start_time;
        $event->timeduration = $zoom->duration;
    } else {
        $event->timesort = $occurrence->start_time;
        $event->timestart = $occurrence->start_time;
        $event->timeduration = $occurrence->duration;
        $event->uuid = $occurrence->occurrence_id;
    }

    // Recurring meetings/webinars with no fixed time are created as invisible events.
    // For recurring meetings/webinars with a fixed time, we want to see the events on the calendar.
    if ($zoom->recurring && $zoom->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) {
        $event->visible = false;
    }

    return $event;
}

/**
 * Delete Moodle calendar events of the Zoom instance.
 *
 * @param stdClass $zoom
 */
function zoom_calendar_item_delete(stdClass $zoom) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    $events = $DB->get_records('event', [
        'modulename' => 'zoom',
        'instance' => $zoom->id,
    ]);
    foreach ($events as $event) {
        calendar_event::load($event)->delete();
    }
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid User id override
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_zoom_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    $userid = null
) {
    global $CFG, $DB, $USER;

    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    if (empty($userid)) {
        $userid = $USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['zoom'][$event->instance];
    $zoom = $DB->get_record('zoom', ['id' => $cm->instance], '*');
    [$inprogress, $available, $finished] = zoom_get_state($zoom);

    if ($finished) {
        return null; // No point to showing finished meetings in overview.
    } else {
        return $factory->create_instance(
            get_string('join_meeting', 'zoom'),
            new \moodle_url('/mod/zoom/view.php', ['id' => $cm->id]),
            1,
            $available
        );
    }
}

/* Gradebook API */

/**
 * Checks if scale is being used by any instance of zoom.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid ID of the scale
 * @return boolean true if the scale is used by any zoom instance
 */
function zoom_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('zoom', ['grade' => -$scaleid])) {
        return true;
    } else {
        return false;
    }
}

/**
 * Creates or updates grade item for the given zoom instance
 *
 * Needed by grade_update_mod_grades().
 *
 * @param stdClass $zoom instance object with extra cmidnumber and modname property
 * @param array $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return void
 */
function zoom_grade_item_update(stdClass $zoom, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [];
    $item['itemname'] = clean_param($zoom->name, PARAM_NOTAGS);
    $item['gradetype'] = GRADE_TYPE_VALUE;

    if ($zoom->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax'] = $zoom->grade;
        $item['grademin'] = 0;
    } else if ($zoom->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid'] = -$zoom->grade;
    } else {
        $gradebook = grade_get_grades($zoom->course, 'mod', 'zoom', $zoom->id);
        // Prevent the gradetype from switching to None if grades exist.
        if (empty($gradebook->items[0]->grades)) {
            $item['gradetype'] = GRADE_TYPE_NONE;
        } else {
            return;
        }
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    grade_update('mod/zoom', $zoom->course, 'mod', 'zoom', $zoom->id, 0, $grades, $item);
}

/**
 * Delete grade item for given zoom instance
 *
 * @param stdClass $zoom instance object
 * @return int
 */
function zoom_grade_item_delete($zoom) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/zoom', $zoom->course, 'mod', 'zoom', $zoom->id, 0, null, ['deleted' => 1]);
}

/**
 * Update zoom grades in the gradebook
 *
 * Needed by grade_update_mod_grades().
 *
 * @param stdClass $zoom instance object with extra cmidnumber and modname property
 * @param int $userid update grade of specific user only, 0 means all participants
 */
function zoom_update_grades(stdClass $zoom, $userid = 0) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    // Populate array of grade objects indexed by userid.
    if ($zoom->grade == 0) {
        zoom_grade_item_update($zoom);
    } else if ($userid != 0) {
        $grade = grade_get_grades($zoom->course, 'mod', 'zoom', $zoom->id, $userid)->items[0]->grades[$userid];
        $grade->userid = $userid;
        if ($grade->grade == -1) {
            $grade->grade = null;
        }

        zoom_grade_item_update($zoom, $grade);
    } else if ($userid == 0) {
        $context = context_course::instance($zoom->course);
        $enrollusersid = array_keys(get_enrolled_users($context));
        $grades = grade_get_grades($zoom->course, 'mod', 'zoom', $zoom->id, $enrollusersid)->items[0]->grades;
        foreach ($grades as $k => $v) {
            $grades[$k]->userid = $k;
            if ($v->grade == -1) {
                $grades[$k]->grade = null;
            }
        }

        zoom_grade_item_update($zoom, $grades);
    } else {
        zoom_grade_item_update($zoom);
    }
}


/**
 * Removes all zoom grades from gradebook by course id
 *
 * @param int $courseid
 */
function zoom_reset_gradebook($courseid) {
    global $DB;

    $params = [$courseid];

    $sql = "SELECT z.*, cm.idnumber as cmidnumber, z.course as courseid
          FROM {zoom} z
          JOIN {course_modules} cm ON cm.instance = z.id
          JOIN {modules} m ON m.id = cm.module AND m.name = 'zoom'
         WHERE z.course = ?";

    if ($zooms = $DB->get_records_sql($sql, $params)) {
        foreach ($zooms as $zoom) {
            zoom_grade_item_update($zoom, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all user data from zoom activites
 * and clean up any related data.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function zoom_reset_userdata($data) {
    global $CFG, $DB;

    $componentstr = get_string('modulenameplural', 'zoom');
    $status = [];

    if (!empty($data->reset_zoom_all)) {
        // Reset tables that record user data.
        $DB->delete_records_select(
            'zoom_meeting_participants',
            'detailsid IN (SELECT zmd.id
                             FROM {zoom_meeting_details} zmd
                             JOIN {zoom} z ON z.id = zmd.zoomid
                            WHERE z.course = ?)',
            [$data->courseid]
        );
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('meetingparticipantsdeleted', 'zoom'),
            'error' => false,
        ];

        $DB->delete_records_select(
            'zoom_meeting_recordings_view',
            'recordingsid IN (SELECT zmr.id
                             FROM {zoom_meeting_recordings} zmr
                             JOIN {zoom} z ON z.id = zmr.zoomid
                            WHERE z.course = ?)',
            [$data->courseid]
        );
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('meetingrecordingviewsdeleted', 'zoom'),
            'error' => false,
        ];

        // The Zoom reset checkbox resets all user grades, always.
        zoom_reset_gradebook($data->courseid);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('grades'),
            'error' => false,
        ];
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param object $mform the course reset form that is being built.
 */
function zoom_reset_course_form_definition($mform) {
    $mform->addElement('header', 'zoomheader', get_string('modulenameplural', 'zoom'));

    $mform->addElement('checkbox', 'reset_zoom_all', get_string('resetzoomsall', 'zoom'));
}

/**
 * Course reset form defaults.
 *
 * @param object $course data passed by the form.
 * @return array the defaults.
 */
function zoom_reset_course_form_defaults($course) {
    return ['reset_zoom_all' => 1];
}

/* File API */

/**
 * Returns the lists of all browsable file areas within the given module context
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by file_browser::get_file_info_context_module()
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 */
function zoom_get_file_areas($course, $cm, $context) {
    return [];
}

/**
 * File browsing support for zoom file areas
 *
 * @package mod_zoom
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info instance or null if not found
 */
function zoom_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    return null;
}

/**
 * Serves the files from the zoom file areas
 *
 * @package mod_zoom
 * @category files
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the zoom's context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function zoom_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);

    send_file_not_found();
}

/* Navigation API */

/**
 * Extends the global navigation tree by adding zoom nodes if there is a relevant content
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $navref An object representing the navigation tree node of the zoom module instance
 * @param stdClass $course current course record
 * @param stdClass $module current zoom instance record
 * @param cm_info $cm course module information
 */
function zoom_extend_navigation(navigation_node $navref, stdClass $course, stdClass $module, cm_info $cm) {
}

/**
 * Extends the settings navigation with the zoom settings
 *
 * This function is called when the context for the page is a zoom module. This is not called by AJAX
 * so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav complete settings navigation tree
 * @param navigation_node|null $zoomnode zoom administration node
 */
function zoom_extend_settings_navigation(settings_navigation $settingsnav, ?navigation_node $zoomnode = null) {
}

/**
 * Get icon mapping for font-awesome.
 *
 * @see https://docs.moodle.org/dev/Moodle_icons
 */
function mod_zoom_get_fontawesome_icon_map() {
    return [
        'mod_zoom:i/calendar' => 'fa-calendar',
    ];
}

/**
 * This function updates the tracking field settings in config_plugins.
 */
function mod_zoom_update_tracking_fields() {
    global $DB;

    try {
        $defaulttrackingfields = zoom_clean_tracking_fields();
        $zoomprops = ['id', 'field', 'required', 'visible', 'recommended_values'];
        $confignames = [];

        if (!empty($defaulttrackingfields)) {
            $zoomtrackingfields = zoom_list_tracking_fields();
            foreach ($zoomtrackingfields as $field => $zoomtrackingfield) {
                if (isset($defaulttrackingfields[$field])) {
                    foreach ($zoomprops as $zoomprop) {
                        $configname = 'tf_' . $field . '_' . $zoomprop;
                        $confignames[] = $configname;
                        if ($zoomprop === 'recommended_values') {
                            $configvalue = implode(', ', $zoomtrackingfield[$zoomprop]);
                        } else {
                            $configvalue = $zoomtrackingfield[$zoomprop];
                        }

                        set_config($configname, $configvalue, 'zoom');
                    }
                }
            }
        }

        $config = get_config('zoom');
        $proparray = get_object_vars($config);
        $properties = array_keys($proparray);
        $oldconfigs = array_diff($properties, $confignames);
        $pattern = '/^tf_(?P<oldfield>.*)_(' . implode('|', $zoomprops) . ')$/';
        foreach ($oldconfigs as $oldconfig) {
            if (preg_match($pattern, $oldconfig, $matches)) {
                set_config($oldconfig, null, 'zoom');
                $DB->delete_records('zoom_meeting_tracking_fields', ['tracking_field' => $matches['oldfield']]);
            }
        }
    } catch (Exception $e) {
        // Fail gracefully because the callback function might be called directly.
        return false;
    }

    return true;
}

/**
 * Insert zoom instance breakout rooms
 *
 * @param int $zoomid
 * @param array $breakoutrooms zoom breakout rooms
 */
function zoom_insert_instance_breakout_rooms($zoomid, $breakoutrooms) {
    global $DB;

    foreach ($breakoutrooms as $breakoutroom) {
        $item = new stdClass();
        $item->name = $breakoutroom['name'];
        $item->zoomid = $zoomid;

        $breakoutroomid = $DB->insert_record('zoom_meeting_breakout_rooms', $item);

        foreach ($breakoutroom['participants'] as $participant) {
            $item = new stdClass();
            $item->userid = $participant;
            $item->breakoutroomid = $breakoutroomid;
            $DB->insert_record('zoom_breakout_participants', $item);
        }

        foreach ($breakoutroom['groups'] as $group) {
            $item = new stdClass();
            $item->groupid = $group;
            $item->breakoutroomid = $breakoutroomid;
            $DB->insert_record('zoom_breakout_groups', $item);
        }
    }
}

/**
 * Update zoom instance breakout rooms
 *
 * @param int $zoomid
 * @param array $breakoutrooms
 */
function zoom_update_instance_breakout_rooms($zoomid, $breakoutrooms) {
    global $DB;

    zoom_delete_instance_breakout_rooms($zoomid);
    zoom_insert_instance_breakout_rooms($zoomid, $breakoutrooms);
}

/**
 * Delete zoom instance breakout rooms
 *
 * @param int $zoomid
 */
function zoom_delete_instance_breakout_rooms($zoomid) {
    global $DB;

    $zoomcurrentbreakoutroomsids = $DB->get_fieldset_select('zoom_meeting_breakout_rooms', 'id', "zoomid = {$zoomid}");

    foreach ($zoomcurrentbreakoutroomsids as $id) {
        $DB->delete_records('zoom_breakout_participants', ['breakoutroomid' => $id]);
        $DB->delete_records('zoom_breakout_groups', ['breakoutroomid' => $id]);
    }

    $DB->delete_records('zoom_meeting_breakout_rooms', ['zoomid' => $zoomid]);
}

/**
 * Build zoom instance breakout rooms array for api
 *
 * @param stdClass $zoom Submitted data from the form in mod_form.php.
 * @return array The meeting breakout rooms array.
 */
function zoom_build_instance_breakout_rooms_array_for_api($zoom) {
    $context = context_course::instance($zoom->course);
    $users = get_enrolled_users($context);
    $groups = groups_get_all_groups($zoom->course);

    // Building meeting breakout rooms array.
    $breakoutrooms = [];
    if (!empty($zoom->rooms)) {
        foreach ($zoom->rooms as $roomid => $roomname) {
            // Getting meeting rooms participants.
            $roomparticipants = [];
            $dbroomparticipants = [];
            if (!empty($zoom->roomsparticipants[$roomid])) {
                foreach ($zoom->roomsparticipants[$roomid] as $participantid) {
                    if (isset($users[$participantid])) {
                        $roomparticipants[] = $users[$participantid]->email;
                        $dbroomparticipants[] = $participantid;
                    }
                }
            }

            // Getting meeting rooms groups members.
            $roomgroupsmembers = [];
            $dbroomgroupsmembers = [];
            if (!empty($zoom->roomsgroups[$roomid])) {
                foreach ($zoom->roomsgroups[$roomid] as $groupid) {
                    if (isset($groups[$groupid])) {
                        $groupmembers = groups_get_members($groupid);
                        $roomgroupsmembers[] = array_column(array_values($groupmembers), 'email');
                        $dbroomgroupsmembers[] = $groupid;
                    }
                }

                $roomgroupsmembers = array_merge(...$roomgroupsmembers);
            }

            $zoomdata = [
                'name' => $roomname,
                'participants' => array_values(array_unique(array_merge($roomparticipants, $roomgroupsmembers))),
            ];

            $dbdata = [
                'name' => $roomname,
                'participants' => $dbroomparticipants,
                'groups' => $dbroomgroupsmembers,
            ];

            $breakoutrooms['zoom'][] = $zoomdata;
            $breakoutrooms['db'][] = $dbdata;
        }
    }

    return $breakoutrooms;
}

/**
 * Build zoom instance breakout rooms array for view.
 *
 * @param int $zoomid
 * @param array $courseparticipants
 * @param array $coursegroups
 * @return array The meeting breakout rooms array.
 */
function zoom_build_instance_breakout_rooms_array_for_view($zoomid, $courseparticipants, $coursegroups) {
    $breakoutrooms = zoom_get_instance_breakout_rooms($zoomid);
    $rooms = [];

    if (!empty($breakoutrooms)) {
        foreach ($breakoutrooms as $key => $breakoutroom) {
            $roomparticipants = $courseparticipants;
            if (!empty($breakoutroom['participants'])) {
                $participants = $breakoutroom['participants'];
                $roomparticipants = array_map(function ($roomparticipant) use ($participants) {
                    if (isset($participants[$roomparticipant['participantid']])) {
                        $roomparticipant['selected'] = true;
                    }

                    return $roomparticipant;
                }, $courseparticipants);
            }

            $roomgroups = $coursegroups;
            if (!empty($breakoutroom['groups'])) {
                $groups = $breakoutroom['groups'];
                $roomgroups = array_map(function ($roomgroup) use ($groups) {
                    if (isset($groups[$roomgroup['groupid']])) {
                        $roomgroup['selected'] = true;
                    }

                    return $roomgroup;
                }, $coursegroups);
            }

            $rooms[] = [
                'roomid' => $breakoutroom['roomid'],
                'roomname' => $breakoutroom['roomname'],
                'courseparticipants' => $roomparticipants,
                'coursegroups' => $roomgroups,
            ];
        }

        $rooms[0]['roomactive'] = true;
    }

    return $rooms;
}

/**
 * Get zoom instance breakout rooms.
 *
 * @param int $zoomid
 * @return array
 */
function zoom_get_instance_breakout_rooms($zoomid) {
    global $DB;

    $breakoutrooms = [];
    $params = [$zoomid];

    $sql = "SELECT id, name
        FROM {zoom_meeting_breakout_rooms}
        WHERE zoomid = ?";

    $rooms = $DB->get_records_sql($sql, $params);

    foreach ($rooms as $room) {
        $breakoutrooms[$room->id] = [
            'roomid' => $room->id,
            'roomname' => $room->name,
            'participants' => [],
            'groups' => [],
        ];

        // Get breakout room participants.
        $params = [$room->id];
        $sql = "SELECT userid
        FROM {zoom_breakout_participants}
        WHERE breakoutroomid = ?";

        $participants = $DB->get_records_sql($sql, $params);

        if (!empty($participants)) {
            foreach ($participants as $participant) {
                $breakoutrooms[$room->id]['participants'][$participant->userid] = $participant->userid;
            }
        }

        // Get breakout room groups.
        $sql = "SELECT groupid
        FROM {zoom_breakout_groups}
        WHERE breakoutroomid = ?";

        $groups = $DB->get_records_sql($sql, $params);

        if (!empty($groups)) {
            foreach ($groups as $group) {
                $breakoutrooms[$room->id]['groups'][$group->groupid] = $group->groupid;
            }
        }
    }

    return $breakoutrooms;
}

/**
 * Print zoom meeting date and time in the course listing page
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing. See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object
 * @return cached_cm_info An object on information that the courses will know about
 */
function zoom_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, intro, introformat, start_time, recurring, recurrence_type, duration';
    if (!$zoom = $DB->get_record('zoom', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('zoom', $zoom, $coursemodule->id, false);
    }

    // Populate some other values that can be used in calendar or on dashboard.
    if ($zoom->start_time) {
        $result->customdata['start_time'] = $zoom->start_time;
    }

    if ($zoom->duration) {
        $result->customdata['duration'] = $zoom->duration;
    }

    // Skip the if condition for recurring and recurrence_type, the values of NULL and 0 are needed in other functions.
    $result->customdata['recurring'] = $zoom->recurring;
    $result->customdata['recurrence_type'] = $zoom->recurrence_type;

    return $result;
}

/**
 * Sets dynamic information about a course module
 *
 * This function is called from cm_info when displaying the module
 *
 * @param cm_info $cm
 */
function zoom_cm_info_dynamic(cm_info $cm) {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    if (method_exists($cm, 'override_customdata')) {
        $moduleinstance = $DB->get_record('zoom', ['id' => $cm->instance], '*', MUST_EXIST);

        // Get meeting state from Zoom.
        [$inprogress, $available, $finished] = zoom_get_state($moduleinstance);

        // For unfinished meetings, override start_time with the next occurrence.
        // If this is a recurring meeting without fixed time, do not override - it will set start_time = 0.
        if (!$finished && $moduleinstance->recurrence_type != ZOOM_RECURRINGTYPE_NOTIME) {
            $cm->override_customdata('start_time', zoom_get_next_occurrence($moduleinstance));
        }
    }
}

/**
 * Apply filter(s) on Zoom activity name if applicable.
 *
 * @param string $name Original name to be processed
 * @param array $options Format_string options
 * @return string Filtered name
 */
function zoom_apply_filter_on_meeting_name($name, $options) {
    return substr(format_string($name, true, $options + ['escape' => false]), 0, 200);
}

/**
 * Checks whether a given calendar event should be visible to the user.
 *
 * @param calendar_event $event The calendar event object.
 * @param ?int $userid User ID.
 * @return bool True if visible, false otherwise.
 */
function mod_zoom_core_calendar_is_event_visible(calendar_event $event, $userid = null) {
    global $USER;

    // Only check our events.
    if (empty($event->instance) || $event->modulename !== 'zoom') {
        return false;
    }

    try {
        $modinfo = get_fast_modinfo($event->courseid, $userid ?? $USER->id);
        $cm = $modinfo->instances['zoom'][$event->instance] ?? null;

        if (empty($cm)) {
            return false;
        }

        // Return the user's visibility for the module.
        return $cm->uservisible;
    } catch (\moodle_exception $e) {
        // Hide the event if the activity module does not exist.
        return false;
    }
}
