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
 * Internal library of functions for module zoom
 *
 * All the zoom specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/zoom/lib.php');
require_once($CFG->dirroot . '/mod/zoom/classes/webservice_exception.php');
require_once($CFG->dirroot . '/mod/zoom/classes/api_limit_exception.php');
require_once($CFG->dirroot . '/mod/zoom/classes/bad_request_exception.php');
require_once($CFG->dirroot . '/mod/zoom/classes/not_found_exception.php');
require_once($CFG->dirroot . '/mod/zoom/classes/retry_failed_exception.php');
require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');

// Constants.
// Audio options.
define('ZOOM_AUDIO_TELEPHONY', 'telephony');
define('ZOOM_AUDIO_VOIP', 'voip');
define('ZOOM_AUDIO_BOTH', 'both');
// Meeting types.
define('ZOOM_INSTANT_MEETING', 1);
define('ZOOM_SCHEDULED_MEETING', 2);
define('ZOOM_RECURRING_MEETING', 3);
define('ZOOM_SCHEDULED_WEBINAR', 5);
define('ZOOM_RECURRING_WEBINAR', 6);
define('ZOOM_RECURRING_FIXED_MEETING', 8);
define('ZOOM_RECURRING_FIXED_WEBINAR', 9);
// Meeting status.
define('ZOOM_MEETING_EXPIRED', 0);
define('ZOOM_MEETING_EXISTS', 1);

// Number of meetings per page from zoom's get user report.
define('ZOOM_DEFAULT_RECORDS_PER_CALL', 30);
define('ZOOM_MAX_RECORDS_PER_CALL', 300);
// User types. Numerical values from Zoom API.
define('ZOOM_USER_TYPE_BASIC', 1);
define('ZOOM_USER_TYPE_PRO', 2);
define('ZOOM_USER_TYPE_CORP', 3);
define('ZOOM_MEETING_NOT_FOUND_ERROR_CODE', 3001);
define('ZOOM_USER_NOT_FOUND_ERROR_CODE', 1001);
define('ZOOM_INVALID_USER_ERROR_CODE', 1120);
// Webinar options.
define('ZOOM_WEBINAR_DISABLE', 0);
define('ZOOM_WEBINAR_SHOWONLYIFLICENSE', 1);
define('ZOOM_WEBINAR_ALWAYSSHOW', 2);
// Encryption type options.
define('ZOOM_ENCRYPTION_DISABLE', 0);
define('ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE', 1);
define('ZOOM_ENCRYPTION_ALWAYSSHOW', 2);
// Encryption types. String values for Zoom API.
define('ZOOM_ENCRYPTION_TYPE_ENHANCED', 'enhanced_encryption');
define('ZOOM_ENCRYPTION_TYPE_E2EE', 'e2ee');
// Alternative hosts options.
define('ZOOM_ALTERNATIVEHOSTS_DISABLE', 0);
define('ZOOM_ALTERNATIVEHOSTS_INPUTFIELD', 1);
define('ZOOM_ALTERNATIVEHOSTS_PICKER', 2);
// Scheduling privilege options.
define('ZOOM_SCHEDULINGPRIVILEGE_DISABLE', 0);
define('ZOOM_SCHEDULINGPRIVILEGE_ENABLE', 1);
// All meetings options.
define('ZOOM_ALLMEETINGS_DISABLE', 0);
define('ZOOM_ALLMEETINGS_ENABLE', 1);
// Download iCal options.
define('ZOOM_DOWNLOADICAL_DISABLE', 0);
define('ZOOM_DOWNLOADICAL_ENABLE', 1);
// Capacity warning options.
define('ZOOM_CAPACITYWARNING_DISABLE', 0);
define('ZOOM_CAPACITYWARNING_ENABLE', 1);
// Recurrence type options.
define('ZOOM_RECURRINGTYPE_NOTIME', 0);
define('ZOOM_RECURRINGTYPE_DAILY', 1);
define('ZOOM_RECURRINGTYPE_WEEKLY', 2);
define('ZOOM_RECURRINGTYPE_MONTHLY', 3);
// Recurring monthly repeat options.
define('ZOOM_MONTHLY_REPEAT_OPTION_DAY', 1);
define('ZOOM_MONTHLY_REPEAT_OPTION_WEEK', 2);
// Recurring end date options.
define('ZOOM_END_DATE_OPTION_BY', 1);
define('ZOOM_END_DATE_OPTION_AFTER', 2);
// API endpoint options.
define('ZOOM_API_ENDPOINT_EU', 'eu');
define('ZOOM_API_ENDPOINT_GLOBAL', 'global');
define('ZOOM_API_URL_EU', 'https://eu01api-www4local.zoom.us/v2/');
define('ZOOM_API_URL_GLOBAL', 'https://api.zoom.us/v2/');
// Auto-recording options.
define('ZOOM_AUTORECORDING_NONE', 'none');
define('ZOOM_AUTORECORDING_USERDEFAULT', 'userdefault');
define('ZOOM_AUTORECORDING_LOCAL', 'local');
define('ZOOM_AUTORECORDING_CLOUD', 'cloud');
// Registration options.
define('ZOOM_REGISTRATION_AUTOMATIC', 0);
define('ZOOM_REGISTRATION_MANUAL', 1);
define('ZOOM_REGISTRATION_OFF', 2);

/**
 * Terminate the current script with a fatal error.
 *
 * Adapted from core_renderer's fatal_error() method. Needed because throwing errors with HTML links in them will convert links
 * to text using htmlentities. See MDL-66161 - Reflected XSS possible from some fatal error messages.
 *
 * So need custom error handler for fatal Zoom errors that have links to help people.
 *
 * @param string $errorcode The name of the string from error.php to print
 * @param string $module name of module
 * @param string $continuelink The url where the user will be prompted to continue.
 *                             If no url is provided the user will be directed to
 *                             the site index page.
 * @param mixed $a Extra words and phrases that might be required in the error string
 */
function zoom_fatal_error($errorcode, $module = '', $continuelink = '', $a = null) {
    global $COURSE, $OUTPUT, $PAGE;

    $output = '';

    // Assumes that function is run before output is generated.
    if ($OUTPUT->has_started()) {
        // If not then have to default to standard error.
        throw new moodle_exception($errorcode, $module, $continuelink, $a);
    }

    $PAGE->set_heading(format_string($COURSE->fullname));
    $output .= $OUTPUT->header();

    // Output message without messing with HTML content of error.
    $message = '<p class="errormessage">' . get_string($errorcode, $module, $a) . '</p>';

    $output .= $OUTPUT->box($message, 'errorbox alert alert-danger', null, ['data-rel' => 'fatalerror']);

    if (!empty($continuelink)) {
        $output .= $OUTPUT->continue_button($continuelink);
    }

    $output .= $OUTPUT->footer();

    // Padding to encourage IE to display our error page, rather than its own.
    $output .= str_repeat(' ', 512);

    echo $output;

    exit(1); // General error code.
}

/**
 * Get course/cm/zoom objects from url parameters, and check for login/permissions.
 *
 * @return array Array of ($course, $cm, $zoom)
 */
function zoom_get_instance_setup() {
    global $DB;

    $id = optional_param('id', 0, PARAM_INT); // Course_module ID.
    $n = optional_param('n', 0, PARAM_INT);  // Zoom instance ID.

    if ($id) {
        $cm = get_coursemodule_from_id('zoom', $id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $zoom = $DB->get_record('zoom', ['id' => $cm->instance], '*', MUST_EXIST);
    } else if ($n) {
        $zoom = $DB->get_record('zoom', ['id' => $n], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $zoom->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('zoom', $zoom->id, $course->id, false, MUST_EXIST);
    } else {
        throw new moodle_exception('zoomerr_id_missing', 'mod_zoom');
    }

    require_login($course, true, $cm);

    $context = context_module::instance($cm->id);
    require_capability('mod/zoom:view', $context);

    return [$course, $cm, $zoom];
}

/**
 * Retrieves information for a meeting.
 *
 * @param int $zoomid
 * @return array information about the meeting
 */
function zoom_get_sessions_for_display($zoomid) {
    global $DB, $CFG;

    require_once($CFG->libdir . '/moodlelib.php');

    $sessions = [];
    $format = get_string('strftimedatetimeshort', 'langconfig');

    // Sort sessions in start_time ascending order.
    $instances = $DB->get_records('zoom_meeting_details', ['zoomid' => $zoomid], 'start_time');

    foreach ($instances as $instance) {
        // The meeting uuid, not the participant's uuid.
        $uuid = $instance->uuid;
        $participantlist = zoom_get_participants_report($instance->id);
        $sessions[$uuid]['participants'] = $participantlist;

        $uniquevalues = [];
        $uniqueparticipantcount = 0;
        foreach ($participantlist as $participant) {
            $unique = true;
            if ($participant->uuid != null) {
                if (array_key_exists($participant->uuid, $uniquevalues)) {
                    $unique = false;
                } else {
                    $uniquevalues[$participant->uuid] = true;
                }
            }

            if ($participant->userid != null) {
                if (!$unique || !array_key_exists($participant->userid, $uniquevalues)) {
                    $uniquevalues[$participant->userid] = true;
                } else {
                    $unique = false;
                }
            }

            if ($participant->user_email != null) {
                if (!$unique || !array_key_exists($participant->user_email, $uniquevalues)) {
                    $uniquevalues[$participant->user_email] = true;
                } else {
                    $unique = false;
                }
            }

            $uniqueparticipantcount += $unique ? 1 : 0;
        }

        $sessions[$uuid]['count'] = $uniqueparticipantcount;
        $sessions[$uuid]['topic'] = $instance->topic;
        $sessions[$uuid]['duration'] = $instance->duration;
        $sessions[$uuid]['starttime'] = userdate($instance->start_time, $format);
        $sessions[$uuid]['endtime'] = userdate($instance->start_time + $instance->duration, $format);
    }

    return $sessions;
}

/**
 * Get the next occurrence of a meeting.
 *
 * @param stdClass $zoom
 * @return int The timestamp of the next occurrence of a recurring meeting or
 *             0 if this is a recurring meeting without fixed time or
 *             the timestamp of the meeting start date if this isn't a recurring meeting.
 */
function zoom_get_next_occurrence($zoom) {
    global $DB;

    // Prepare an ad-hoc request cache as this function could be called multiple times throughout a request
    // and we want to avoid to make duplicate DB calls.
    $cacheoptions = [
        'simplekeys' => true,
        'simpledata' => true,
    ];
    $cache = cache::make_from_params(cache_store::MODE_REQUEST, 'zoom', 'nextoccurrence', [], $cacheoptions);

    // If the next occurrence wasn't already cached, fill the cache.
    $cachednextoccurrence = $cache->get($zoom->id);
    if ($cachednextoccurrence === false) {
        // If this isn't a recurring meeting.
        if (!$zoom->recurring) {
            // Use the meeting start time.
            $cachednextoccurrence = $zoom->start_time;

            // Or if this is a recurring meeting without fixed time.
        } else if ($zoom->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) {
            // Use 0 as there isn't anything better to return.
            $cachednextoccurrence = 0;

            // Otherwise we have a recurring meeting with a recurrence schedule.
        } else {
            // Get the calendar event of the next occurrence.
            $selectclause = "modulename = :modulename AND instance = :instance AND (timestart + timeduration) >= :now";
            $selectparams = ['modulename' => 'zoom', 'instance' => $zoom->id, 'now' => time()];
            $nextoccurrence = $DB->get_records_select('event', $selectclause, $selectparams, 'timestart ASC', 'timestart', 0, 1);

            // If we haven't got a single event.
            if (empty($nextoccurrence)) {
                // Use 0 as there isn't anything better to return.
                $cachednextoccurrence = 0;
            } else {
                // Use the timestamp of the event.
                $nextoccurenceobject = reset($nextoccurrence);
                $cachednextoccurrence = $nextoccurenceobject->timestart;
            }
        }

        // Store the next occurrence into the cache.
        $cache->set($zoom->id, $cachednextoccurrence);
    }

    // Return the next occurrence.
    return $cachednextoccurrence;
}

/**
 * Determine if a zoom meeting is in progress, is available, and/or is finished.
 *
 * @param stdClass $zoom
 * @return array Array of booleans: [in progress, available, finished].
 */
function zoom_get_state($zoom) {
    // Get plugin config.
    $config = get_config('zoom');

    // Get the current time as calculation basis.
    $now = time();

    // If this is a recurring meeting with a recurrence schedule.
    if ($zoom->recurring && $zoom->recurrence_type != ZOOM_RECURRINGTYPE_NOTIME) {
        // Get the next occurrence start time.
        $starttime = zoom_get_next_occurrence($zoom);
    } else {
        // Get the meeting start time.
        $starttime = $zoom->start_time;
    }

    // Calculate the time when the recurring meeting becomes available next,
    // based on the next occurrence start time and the general meeting lead time.
    $firstavailable = $starttime - ($config->firstabletojoin * 60);

    // Calculate the time when the meeting ends to be available,
    // based on the next occurrence start time and the meeting duration.
    $lastavailable = $starttime + $zoom->duration;

    // Determine if the meeting is in progress.
    $inprogress = ($firstavailable <= $now && $now <= $lastavailable);

    // Determine if its a recurring meeting with no fixed time.
    $isrecurringnotime = $zoom->recurring && $zoom->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME;

    // Determine if the meeting is available,
    // based on the fact if it is recurring or in progress.
    $available = $isrecurringnotime || $inprogress;

    // Determine if the meeting is finished,
    // based on the fact if it is recurring or the meeting end time is still in the future.
    $finished = !$isrecurringnotime && $now > $lastavailable;

    // Return the requested information.
    return [$inprogress, $available, $finished];
}

/**
 * Get the Zoom id of the currently logged-in user.
 *
 * @param bool $required If true, will error if the user doesn't have a Zoom account.
 * @return string
 */
function zoom_get_user_id($required = true) {
    global $USER;

    $cache = cache::make('mod_zoom', 'zoomid');
    if (!($zoomuserid = $cache->get($USER->id))) {
        $zoomuserid = false;
        try {
            $zoomuser = zoom_get_user(zoom_get_api_identifier($USER));
            if ($zoomuser !== false && isset($zoomuser->id) && ($zoomuser->id !== false)) {
                $zoomuserid = $zoomuser->id;
                $cache->set($USER->id, $zoomuserid);
            }
            // If user does not have a Zoom account, throw an error.
            if ($required && $zoomuser === false) {
                throw new moodle_exception('zoomerr_usernotfound', 'mod_zoom', '', get_config('zoom', 'zoomurl'));
            }
        } catch (moodle_exception $error) {
            if ($required) {
                throw $error;
            }
        }
    }

    return $zoomuserid;
}

/**
 * Get the Zoom meeting security settings, including meeting password requirements of the user's master account.
 *
 * @param string|int $identifier The user's email or the user's ID per Zoom API.
 * @return stdClass
 */
function zoom_get_meeting_security_settings($identifier) {
    $cache = cache::make('mod_zoom', 'zoommeetingsecurity');
    $zoommeetingsecurity = $cache->get($identifier);
    if (empty($zoommeetingsecurity)) {
        $zoommeetingsecurity = zoom_webservice()->get_account_meeting_security_settings($identifier);
        $cache->set($identifier, $zoommeetingsecurity);
    }

    return $zoommeetingsecurity;
}

/**
 * Check if the error indicates that a meeting is gone.
 *
 * @param moodle_exception $error
 * @return bool
 */
function zoom_is_meeting_gone_error($error) {
    // If the meeting's owner/user cannot be found, we consider the meeting to be gone.
    return ($error->zoomerrorcode === ZOOM_MEETING_NOT_FOUND_ERROR_CODE) || zoom_is_user_not_found_error($error);
}

/**
 * Check if the error indicates that a user is not found or does not belong to the current account.
 *
 * @param moodle_exception $error
 * @return bool
 */
function zoom_is_user_not_found_error($error) {
    return ($error->zoomerrorcode === ZOOM_USER_NOT_FOUND_ERROR_CODE) || ($error->zoomerrorcode === ZOOM_INVALID_USER_ERROR_CODE);
}

/**
 * Return the string parameter for zoomerr_meetingnotfound.
 *
 * @param string $cmid
 * @return stdClass
 */
function zoom_meetingnotfound_param($cmid) {
    // Provide links to recreate and delete.
    $recreate = new moodle_url('/mod/zoom/recreate.php', ['id' => $cmid, 'sesskey' => sesskey()]);
    $delete = new moodle_url('/course/mod.php', ['delete' => $cmid, 'sesskey' => sesskey()]);

    // Convert links to strings and pass as error parameter.
    $param = new stdClass();
    $param->recreate = $recreate->out();
    $param->delete = $delete->out();

    return $param;
}

/**
 * Get the data of each user for the participants report.
 * @param string $detailsid The meeting ID that you want to get the participants report for.
 * @return array The user data as an array of records (array of arrays).
 */
function zoom_get_participants_report($detailsid) {
    global $DB;
    $sql = 'SELECT zmp.id,
                   zmp.name,
                   zmp.userid,
                   zmp.user_email,
                   zmp.join_time,
                   zmp.leave_time,
                   zmp.duration,
                   zmp.uuid
              FROM {zoom_meeting_participants} zmp
             WHERE zmp.detailsid = :detailsid
    ';
    $params = [
        'detailsid' => $detailsid,
    ];
    $participants = $DB->get_records_sql($sql, $params);
    return $participants;
}

/**
 * Creates a default passcode from the user's Zoom meeting security settings.
 *
 * @param stdClass $meetingpasswordrequirement
 * @return string passcode
 */
function zoom_create_default_passcode($meetingpasswordrequirement) {
    $length = max($meetingpasswordrequirement->length, 6);
    $random = random_int(0, (int) pow(10, $length) - 1);
    $passcode = str_pad(strval($random), $length, '0', STR_PAD_LEFT);

    // Get a random set of indexes to replace with non-numberic values.
    $indexes = range(0, $length - 1);
    shuffle($indexes);

    if ($meetingpasswordrequirement->have_letter || $meetingpasswordrequirement->have_upper_and_lower_characters) {
        // Random letter from A-Z.
        $passcode[$indexes[0]] = chr(random_int(65, 90));
        // Random letter from a-z.
        $passcode[$indexes[1]] = chr(random_int(97, 122));
    }

    if ($meetingpasswordrequirement->have_special_character) {
        $specialchar = '@_*-';
        $passcode[$indexes[2]] = $specialchar[random_int(0, strlen($specialchar) - 1)];
    }

    return $passcode;
}

/**
 * Creates a description string from the user's Zoom meeting security settings.
 *
 * @param stdClass $meetingpasswordrequirement
 * @return string description of password requirements
 */
function zoom_create_passcode_description($meetingpasswordrequirement) {
    $description = '';
    if ($meetingpasswordrequirement->only_allow_numeric) {
        $description .= get_string('password_only_numeric', 'mod_zoom') . ' ';
    } else {
        if ($meetingpasswordrequirement->have_letter && !$meetingpasswordrequirement->have_upper_and_lower_characters) {
            $description .= get_string('password_letter', 'mod_zoom') . ' ';
        } else if ($meetingpasswordrequirement->have_upper_and_lower_characters) {
            $description .= get_string('password_lower_upper', 'mod_zoom') . ' ';
        }

        if ($meetingpasswordrequirement->have_number) {
            $description .= get_string('password_number', 'mod_zoom') . ' ';
        }

        if ($meetingpasswordrequirement->have_special_character) {
            $description .= get_string('password_special', 'mod_zoom') . ' ';
        } else {
            $description .= get_string('password_allowed_char', 'mod_zoom') . ' ';
        }
    }

    if ($meetingpasswordrequirement->length) {
        $description .= get_string('password_length', 'mod_zoom', $meetingpasswordrequirement->length) . ' ';
    }

    if ($meetingpasswordrequirement->consecutive_characters_length > 0) {
        $description .= get_string(
            'password_consecutive',
            'mod_zoom',
            $meetingpasswordrequirement->consecutive_characters_length - 1
        ) . ' ';
    }

    $description .= get_string('password_max_length', 'mod_zoom');
    return $description;
}

/**
 * Creates an array of users who can be selected as alternative host in a given context.
 *
 * @param context $context The context to be used.
 *
 * @return array Array of users (mail => fullname).
 */
function zoom_get_selectable_alternative_hosts_list(context $context) {
    // Get selectable alternative host users based on the capability.
    $users = get_enrolled_users($context, 'mod/zoom:eligiblealternativehost', 0, 'u.*', 'lastname');

    // Create array of users.
    $selectablealternativehosts = [];

    // Iterate over selectable alternative host users.
    foreach ($users as $u) {
        // Note: Basically, if this is the user's own data row, the data row should be skipped.
        // But this would then not cover the case when a user is scheduling the meeting _for_ another user
        // and wants to be an alternative host himself.
        // As this would have to be handled at runtime in the browser, we just offer all users with the
        // capability as selectable and leave this aspect as possible improvement for the future.
        // At least, Zoom does not care if the user who is the host adds himself as alternative host as well.

        // Verify that the user really has a Zoom account.
        // Furthermore, verify that the user's status is active. Adding a pending or inactive user as alternative host will result
        // in a Zoom API error otherwise.
        $zoomuser = zoom_get_user($u->email);
        if ($zoomuser !== false && $zoomuser->status === 'active') {
            // Add user to array of users.
            $selectablealternativehosts[strtolower($u->email)] = fullname($u);
        }
    }

    return $selectablealternativehosts;
}

/**
 * Creates a string of roles who can be selected as alternative host in a given context.
 *
 * @param context $context The context to be used.
 *
 * @return string The string of roles.
 */
function zoom_get_selectable_alternative_hosts_rolestring(context $context) {
    // Get selectable alternative host users based on the capability.
    $roles = get_role_names_with_caps_in_context($context, ['mod/zoom:eligiblealternativehost']);

    // Compose string.
    $rolestring = implode(', ', $roles);

    return $rolestring;
}

/**
 * Get existing Moodle users from a given set of alternative hosts.
 *
 * @param array $alternativehosts The array of alternative hosts email addresses.
 *
 * @return array The array of existing Moodle user objects.
 */
function zoom_get_users_from_alternativehosts(array $alternativehosts) {
    global $DB;

    // Get the existing Moodle user objects from the DB.
    [$insql, $inparams] = $DB->get_in_or_equal($alternativehosts);
    $sql = 'SELECT *
            FROM {user}
            WHERE email ' . $insql . '
            ORDER BY lastname ASC';
    $alternativehostusers = $DB->get_records_sql($sql, $inparams);

    return $alternativehostusers;
}

/**
 * Get non-Moodle users from a given set of alternative hosts.
 *
 * @param array $alternativehosts The array of alternative hosts email addresses.
 *
 * @return array The array of non-Moodle user mail addresses.
 */
function zoom_get_nonusers_from_alternativehosts(array $alternativehosts) {
    global $DB;

    // Get the non-Moodle user mail addresses by checking which one does not exist in the DB.
    $alternativehostnonusers = [];
    [$insql, $inparams] = $DB->get_in_or_equal($alternativehosts);
    $sql = 'SELECT email
            FROM {user}
            WHERE email ' . $insql . '
            ORDER BY email ASC';
    $alternativehostusersmails = $DB->get_records_sql($sql, $inparams);
    foreach ($alternativehosts as $ah) {
        if (!array_key_exists($ah, $alternativehostusersmails)) {
            $alternativehostnonusers[] = $ah;
        }
    }

    return $alternativehostnonusers;
}

/**
 * Get the unavailability note based on the Zoom plugin configuration.
 *
 * @param object $zoom The Zoom meeting object.
 * @param bool|null $finished The function needs to know if the meeting is already finished.
 *                       You can provide this information, if already available, to the function.
 *                       Otherwise it will determine it with a small overhead.
 *
 * @return string The unavailability note.
 */
function zoom_get_unavailability_note($zoom, $finished = null) {
    // Get config.
    $config = get_config('zoom');

    // Get the plain unavailable string.
    $strunavailable = get_string('unavailable', 'mod_zoom');

    // If this is a recurring meeting without fixed time, just use the plain unavailable string.
    if ($zoom->recurring && $zoom->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) {
        $unavailabilitynote = $strunavailable;

        // Otherwise we add some more information to the unavailable string.
    } else {
        // If we don't have the finished information yet, get it with a small overhead.
        if ($finished === null) {
            [$inprogress, $available, $finished] = zoom_get_state($zoom);
        }

        // If this meeting is still pending.
        if ($finished !== true) {
            // If the admin wants to show the leadtime.
            if (!empty($config->displayleadtime) && $config->firstabletojoin > 0) {
                $unavailabilitynote = $strunavailable . '<br />' .
                        get_string('unavailablefirstjoin', 'mod_zoom', ['mins' => ($config->firstabletojoin)]);

                // Otherwise.
            } else {
                $unavailabilitynote = $strunavailable . '<br />' . get_string('unavailablenotstartedyet', 'mod_zoom');
            }

            // Otherwise, the meeting has finished.
        } else {
            $unavailabilitynote = $strunavailable . '<br />' . get_string('unavailablefinished', 'mod_zoom');
        }
    }

    return $unavailabilitynote;
}

/**
 * Gets the meeting capacity of a given Zoom user.
 * Please note: This function does not check if the Zoom user really exists, this has to be checked before calling this function.
 *
 * @param string $zoomhostid The Zoom ID of the host.
 * @param bool $iswebinar The meeting is a webinar.
 *
 * @return int|bool The meeting capacity of the Zoom user or false if the user does not have any meeting capacity at all.
 */
function zoom_get_meeting_capacity(string $zoomhostid, bool $iswebinar = false) {
    // Get the 'feature' section of the user's Zoom settings.
    $userfeatures = zoom_get_user_settings($zoomhostid)->feature;

    $meetingcapacity = false;

    // If this is a webinar.
    if ($iswebinar === true) {
        // Get the appropriate capacity value.
        if (!empty($userfeatures->webinar_capacity)) {
            $meetingcapacity = $userfeatures->webinar_capacity;
        } else if (!empty($userfeatures->zoom_events_capacity)) {
            $meetingcapacity = $userfeatures->zoom_events_capacity;
        }
    } else {
        // If this is a meeting, get the 'meeting_capacity' value.
        if (!empty($userfeatures->meeting_capacity)) {
            $meetingcapacity = $userfeatures->meeting_capacity;

            // Check if the user has a 'large_meeting' license that has a higher capacity value.
            if (!empty($userfeatures->large_meeting_capacity) && $userfeatures->large_meeting_capacity > $meetingcapacity) {
                $meetingcapacity = $userfeatures->large_meeting_capacity;
            }
        }
    }

    return $meetingcapacity;
}

/**
 * Gets the number of eligible meeting participants in a given context.
 * Please note: This function only covers users who are enrolled into the given context.
 * It does _not_ include users who have the necessary capability on a higher context without being enrolled.
 *
 * @param context $context The context which we want to check.
 *
 * @return int The number of eligible meeting participants.
 */
function zoom_get_eligible_meeting_participants(context $context) {
    global $DB;

    // Compose SQL query.
    $sqlsnippets = get_enrolled_with_capabilities_join($context, '', 'mod/zoom:view', 0, true);
    $sql = 'SELECT count(DISTINCT u.id)
            FROM {user} u ' . $sqlsnippets->joins . ' WHERE ' . $sqlsnippets->wheres;

    // Run query and count records.
    $eligibleparticipantcount = $DB->count_records_sql($sql, $sqlsnippets->params);

    return $eligibleparticipantcount;
}

/**
 * Get array of alternative hosts from a string.
 *
 * @param string $alternativehoststring Comma (or semicolon) separated list of alternative hosts.
 * @return string[] $alternativehostarray Array of alternative hosts.
 */
function zoom_get_alternative_host_array_from_string($alternativehoststring) {
    if (empty($alternativehoststring)) {
        return [];
    }

    // The Zoom API has historically returned either semicolons or commas, so we need to support both.
    $alternativehoststring = str_replace(';', ',', $alternativehoststring);
    $alternativehostarray = array_filter(explode(',', $alternativehoststring));

    // Lowercase email addresses so that we can do case-insensitive comparisons.
    foreach ($alternativehostarray as $key => $value) {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            $alternativehostarray[$key] = strtolower($value);
        }
    }
    return $alternativehostarray;
}

/**
 * Get all custom user profile fields of type text
 *
 * @return array list of user profile fields
 */
function zoom_get_user_profile_fields() {
    global $DB;

    $userfields = [];
    $records = $DB->get_records('user_info_field', ['datatype' => 'text']);
    foreach ($records as $record) {
        $userfields[$record->shortname] = $record->name;
    }

    return $userfields;
}

/**
 * Get all valid options for API Identifier field
 *
 * @return array list of all valid options
 */
function zoom_get_api_identifier_fields() {
    $options = [
        'email' => get_string('email'),
        'username' => get_string('username'),
        'idnumber' => get_string('idnumber'),
    ];

    $userfields = zoom_get_user_profile_fields();
    if (!empty($userfields)) {
        $options += $userfields;
    }

    return $options;
}

/**
 * Get the zoom api identifier
 *
 * @param object $user The user object
 *
 * @return string the value of the identifier
 */
function zoom_get_api_identifier($user) {
    // Get the value from the config first.
    $field = get_config('zoom', 'apiidentifier');

    $identifier = '';
    if (isset($user->$field)) {
        // If one of the standard user fields.
        $identifier = $user->$field;
    } else if (isset($user->profile[$field])) {
        // If one of the custom user fields.
        $identifier = $user->profile[$field];
    }

    if (empty($identifier)) {
        // Fallback to email if the field is not set.
        $identifier = $user->email;
    }

    return $identifier;
}

/**
 * Creates an iCalendar_event for a Zoom meeting.
 *
 * @param stdClass $event The meeting object.
 * @param string $description The event description.
 *
 * @return iCalendar_event
 */
function zoom_helper_icalendar_event($event, $description) {
    global $CFG;

    // Match Moodle's uid format for iCal events.
    $hostaddress = str_replace('http://', '', $CFG->wwwroot);
    $hostaddress = str_replace('https://', '', $hostaddress);
    $uid = $event->id . '@' . $hostaddress;

    $icalevent = new iCalendar_event();
    $icalevent->add_property('uid', $uid); // A unique identifier.
    $icalevent->add_property('summary', $event->name); // Title.
    $icalevent->add_property('dtstamp', Bennu::timestamp_to_datetime()); // Time of creation.
    $icalevent->add_property('last-modified', Bennu::timestamp_to_datetime($event->timemodified));
    $icalevent->add_property('dtstart', Bennu::timestamp_to_datetime($event->timestart)); // Start time.
    $icalevent->add_property('dtend', Bennu::timestamp_to_datetime($event->timestart + $event->timeduration)); // End time.
    $icalevent->add_property('description', $description);
    return $icalevent;
}

/**
 * Get the configured Zoom API URL.
 *
 * @return string The API URL.
 */
function zoom_get_api_url() {
    // Get the API endpoint setting.
    $apiendpoint = get_config('zoom', 'apiendpoint');

    // Pick the corresponding API URL.
    switch ($apiendpoint) {
        case ZOOM_API_ENDPOINT_EU:
            $apiurl = ZOOM_API_URL_EU;
            break;

        case ZOOM_API_ENDPOINT_GLOBAL:
        default:
            $apiurl = ZOOM_API_URL_GLOBAL;
            break;
    }

    // Return API URL.
    return $apiurl;
}

/**
 * Loads the zoom meeting and passes back a meeting URL
 * after processing events, view completion, grades, and license updates.
 *
 * @param int $id course module id
 * @param object $context moodle context object
 * @param bool $usestarturl
 * @return array $returns contains url object 'nexturl' or string 'error'
 */
function zoom_load_meeting($id, $context, $usestarturl = true) {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir . '/gradelib.php');

    $cm = get_coursemodule_from_id('zoom', $id, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    $zoom = $DB->get_record('zoom', ['id' => $cm->instance], '*', MUST_EXIST);

    require_login($course, true, $cm);

    require_capability('mod/zoom:view', $context);

    $returns = ['nexturl' => null, 'error' => null];

    [$inprogress, $available, $finished] = zoom_get_state($zoom);

    $userisregistered = false;
    $userisregistering = false;
    if ($zoom->registration != ZOOM_REGISTRATION_OFF) {
        // Check if user already registered.
        $registrantjoinurl = zoom_get_registrant_join_url($USER->email, $zoom->meeting_id, $zoom->webinar);
        $userisregistered = !empty($registrantjoinurl);

        // Allow unregistered users to register.
        if (!$userisregistered) {
            $userisregistering = true;
        }
    }

    // If the meeting is not yet available, deny access.
    if (!$available && !$userisregistering) {
        // Get unavailability note.
        $returns['error'] = zoom_get_unavailability_note($zoom, $finished);
        return $returns;
    }

    $userisrealhost = (zoom_get_user_id(false) === $zoom->host_id);
    $alternativehosts = zoom_get_alternative_host_array_from_string($zoom->alternative_hosts);
    // Lowercase email addresses so that we can do case-insensitive comparisons.
    $userapiidentifier = zoom_get_api_identifier($USER);
    if (filter_var($userapiidentifier, FILTER_VALIDATE_EMAIL) !== false) {
        $userapiidentifier = strtolower($userapiidentifier);
    }
    $userishost = ($userisrealhost || in_array($userapiidentifier, $alternativehosts, true));

    // Check if we should use the start meeting url.
    if ($userisrealhost && $usestarturl) {
        // Important: Only the real host can use this URL, because it joins the meeting as the host user.
        $starturl = zoom_get_start_url($zoom->meeting_id, $zoom->webinar, $zoom->join_url);
        $returns['nexturl'] = new moodle_url($starturl);
    } else {
        $url = $zoom->join_url;
        if ($userisregistered) {
            $url = $registrantjoinurl;
        }

        $unamesetting = get_config('zoom', 'unamedisplay');
        switch ($unamesetting) {
            case 'fullname':
            default:
                $unamedisplay = fullname($USER);
                break;

            case 'firstname':
                $unamedisplay = $USER->firstname;
                break;

            case 'idfullname':
                $unamedisplay = '(' . $USER->id . ') ' . fullname($USER);
                break;

            case 'id':
                $unamedisplay = '(' . $USER->id . ')';
                break;
        }

        // Try to send the user email (not guaranteed).
        $returns['nexturl'] = new moodle_url($url, ['uname' => $unamedisplay, 'uemail' => $USER->email]);
    }

    // If the user is pre-registering, skip grading/completion.
    if (!$available && $userisregistering) {
        return $returns;
    }

    // Record user's clicking join.
    \mod_zoom\event\join_meeting_button_clicked::create([
        'context' => $context,
        'objectid' => $zoom->id,
        'other' => [
            'cmid' => $id,
            'meetingid' => (int) $zoom->meeting_id,
            'userishost' => $userishost,
        ],
    ])->trigger();

    // Track completion viewed.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

    // Check the grading method settings.
    if (!empty($zoom->grading_method)) {
        $gradingmethod = $zoom->grading_method;
    } else if ($defaultgrading = get_config('gradingmethod', 'zoom')) {
        $gradingmethod = $defaultgrading;
    } else {
        $gradingmethod = 'entry';
    }

    if ($gradingmethod === 'entry') {
        // Check whether user has a grade. If not, then assign full credit to them.
        $gradelist = grade_get_grades($course->id, 'mod', 'zoom', $cm->instance, $USER->id);

        // Assign full credits for user who has no grade yet, if this meeting is gradable (i.e. the grade type is not "None").
        if (!empty($gradelist->items) && empty($gradelist->items[0]->grades[$USER->id]->grade)) {
            $grademax = $gradelist->items[0]->grademax;
            $grades = [
                'rawgrade' => $grademax,
                'userid' => $USER->id,
                'usermodified' => $USER->id,
                'dategraded' => '',
                'feedbackformat' => '',
                'feedback' => '',
            ];

            zoom_grade_item_update($zoom, $grades);
        }
    } // Otherwise, the get_meetings_report task calculates the grades according to duration.

    // Upgrade host upon joining meeting, if host is not Licensed.
    if ($userishost) {
        $config = get_config('zoom');
        if (!empty($config->recycleonjoin)) {
            zoom_webservice()->provide_license($zoom->host_id);
        }
    }

    return $returns;
}

/**
 * Fetches a fresh URL that can be used to start the Zoom meeting.
 *
 * @param string $meetingid Zoom meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @param string $fallbackurl URL to use if the webservice call fails.
 * @return string Best available URL for starting the meeting.
 */
function zoom_get_start_url($meetingid, $iswebinar, $fallbackurl) {
    try {
        $response = zoom_webservice()->get_meeting_webinar_info($meetingid, $iswebinar);
        return $response->start_url ?? $response->join_url;
    } catch (moodle_exception $e) {
        // If an exception was thrown, gracefully use the fallback URL.
        return $fallbackurl;
    }
}

/**
 * Get the configured Zoom tracking fields.
 *
 * @return array tracking fields, keys as lower case
 */
function zoom_list_tracking_fields() {
    $trackingfields = [];

    // Get the tracking fields configured on the account.
    $response = zoom_webservice()->list_tracking_fields();
    if (isset($response->tracking_fields)) {
        foreach ($response->tracking_fields as $trackingfield) {
            $field = str_replace(' ', '_', strtolower($trackingfield->field));
            $trackingfields[$field] = (array) $trackingfield;
        }
    }

    return $trackingfields;
}

/**
 * Trim and lower case tracking fields.
 *
 * @return array tracking fields trimmed, keys as lower case
 */
function zoom_clean_tracking_fields() {
    $config = get_config('zoom');
    $defaulttrackingfields = explode(',', $config->defaulttrackingfields);
    $trackingfields = [];

    foreach ($defaulttrackingfields as $key => $defaulttrackingfield) {
        $trimmed = trim($defaulttrackingfield);
        if (!empty($trimmed)) {
            $key = str_replace(' ', '_', strtolower($trimmed));
            $trackingfields[$key] = $trimmed;
        }
    }

    return $trackingfields;
}

/**
 * Synchronize tracking field data for a meeting.
 *
 * @param int $zoomid Zoom meeting ID
 * @param array $trackingfields Tracking fields configured in Zoom.
 */
function zoom_sync_meeting_tracking_fields($zoomid, $trackingfields) {
    global $DB;

    $tfvalues = [];
    foreach ($trackingfields as $trackingfield) {
        $field = str_replace(' ', '_', strtolower($trackingfield->field));
        $tfvalues[$field] = $trackingfield->value;
    }

    $tfrows = $DB->get_records('zoom_meeting_tracking_fields', ['meeting_id' => $zoomid]);
    $tfobjects = [];
    foreach ($tfrows as $tfrow) {
        $tfobjects[$tfrow->tracking_field] = $tfrow;
    }

    $defaulttrackingfields = zoom_clean_tracking_fields();
    foreach ($defaulttrackingfields as $key => $defaulttrackingfield) {
        $value = $tfvalues[$key] ?? '';
        if (isset($tfobjects[$key])) {
            $tfobject = $tfobjects[$key];
            if ($value === '') {
                $DB->delete_records('zoom_meeting_tracking_fields', ['meeting_id' => $zoomid, 'tracking_field' => $key]);
            } else if ($tfobject->value !== $value) {
                $tfobject->value = $value;
                $DB->update_record('zoom_meeting_tracking_fields', $tfobject);
            }
        } else if ($value !== '') {
            $tfobject = new stdClass();
            $tfobject->meeting_id = $zoomid;
            $tfobject->tracking_field = $key;
            $tfobject->value = $value;
            $DB->insert_record('zoom_meeting_tracking_fields', $tfobject);
        }
    }
}

/**
 * Get all meeting records
 *
 * @return array All zoom meetings stored in the database.
 */
function zoom_get_all_meeting_records() {
    global $DB;

    $meetings = [];
    // Only get meetings that exist on zoom.
    $records = $DB->get_records('zoom', ['exists_on_zoom' => ZOOM_MEETING_EXISTS]);
    foreach ($records as $record) {
        $meetings[] = $record;
    }

    return $meetings;
}

/**
 * Get all recordings for a particular meeting.
 *
 * @param int $zoomid Optional. The id of the zoom meeting.
 *
 * @return array All the recordings for the zoom meeting.
 */
function zoom_get_meeting_recordings($zoomid = null) {
    global $DB;

    $params = [];
    if ($zoomid !== null) {
        $params['zoomid'] = $zoomid;
    }

    $records = $DB->get_records('zoom_meeting_recordings', $params);
    $recordings = [];
    foreach ($records as $recording) {
        $recordings[$recording->zoomrecordingid] = $recording;
    }

    return $recordings;
}

/**
 * Get all meeting recordings grouped together.
 *
 * @param int $zoomid Optional. The id of the zoom meeting.
 *
 * @return array All recordings for the zoom meeting grouped together.
 */
function zoom_get_meeting_recordings_grouped($zoomid = null) {
    global $DB;

    $params = [];
    if ($zoomid !== null) {
        $params['zoomid'] = $zoomid;
    }

    $records = $DB->get_records('zoom_meeting_recordings', $params, 'recordingstart ASC');
    $recordings = [];
    foreach ($records as $recording) {
        $recordings[$recording->meetinguuid][$recording->zoomrecordingid] = $recording;
    }

    return $recordings;
}

/**
 * Singleton for Zoom webservice class.
 *
 * @return \mod_zoom\webservice
 */
function zoom_webservice() {
    static $service;

    if (empty($service)) {
        $service = new \mod_zoom\webservice();
    }

    return $service;
}

/**
 * Helper to get a Zoom user, efficiently.
 *
 * @param string|int $identifier The user's email or the user's ID per Zoom API.
 * @return stdClass|false If user is found, returns a Zoom user object. Otherwise, returns false.
 */
function zoom_get_user($identifier) {
    static $users = [];

    if (!isset($users[$identifier])) {
        $users[$identifier] = zoom_webservice()->get_user($identifier);
    }

    return $users[$identifier];
}

/**
 * Helper to get Zoom user settings, efficiently.
 *
 * @param string|int $identifier The user's email or the user's ID per Zoom API.
 * @return stdClass|false If user is found, returns a Zoom user object. Otherwise, returns false.
 */
function zoom_get_user_settings($identifier) {
    static $settings = [];

    if (!isset($settings[$identifier])) {
        $settings[$identifier] = zoom_webservice()->get_user_settings($identifier);
    }

    return $settings[$identifier];
}

/**
 * Get the zoom meeting registrants.
 *
 * @param string $meetingid Zoom meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @return stdClass Returns a Zoom object containing the registrants (if found).
 */
function zoom_get_meeting_registrants($meetingid, $iswebinar) {
    $response = zoom_webservice()->get_meeting_registrants($meetingid, $iswebinar);
    return $response;
}

/**
 * Checks if a user has registered for a meeting/webinar based on their email address.
 *
 * @param string $useremail The email address of a user used to determine if they registered or not.
 * @param string $meetingid Zoom meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @return bool Returns whether or not the user has registered for the zoom meeting/webinar based on their email address.
 */
function zoom_is_user_registered_for_meeting($useremail, $meetingid, $iswebinar) {
    $registrantjoinurl = zoom_get_registrant_join_url($useremail, $meetingid, $iswebinar);
    return !empty($registrantjoinurl);
}

/**
 * Get the join url for a user for the specified meeting/webinar.
 *
 * @param string $useremail The email address of a user used to determine if they registered or not.
 * @param string $meetingid Zoom meeting ID.
 * @param bool $iswebinar If the session is a webinar.
 * @return string|false Returns the join url for the user (based on email address) for the specified meeting (if found).
 */
function zoom_get_registrant_join_url($useremail, $meetingid, $iswebinar) {
    $response = zoom_get_meeting_registrants($meetingid, $iswebinar);
    if (isset($response->registrants)) {
        foreach ($response->registrants as $registrant) {
            if (strcasecmp($useremail, $registrant->email) == 0) {
                return $registrant->join_url;
            }
        }
    }

    return false;
}

/**
 * Get the display name for a Zoom user.
 * This is wrapped in a function to avoid unnecessary API calls.
 *
 * @param string $zoomuserid Zoom user ID.
 * @return ?string
 */
function zoom_get_user_display_name($zoomuserid) {
    try {
        $hostuser = zoom_get_user($zoomuserid);

        // Compose Moodle user object for host.
        $hostmoodleuser = new stdClass();
        $hostmoodleuser->firstname = $hostuser->first_name;
        $hostmoodleuser->lastname = $hostuser->last_name;
        $hostmoodleuser->alternatename = '';
        $hostmoodleuser->firstnamephonetic = '';
        $hostmoodleuser->lastnamephonetic = '';
        $hostmoodleuser->middlename = '';

        return fullname($hostmoodleuser);
    } catch (moodle_exception $error) {
        return null;
    }
}
