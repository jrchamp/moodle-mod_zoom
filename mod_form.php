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
 * The main zoom configuration form
 *
 * It uses the standard core Moodle formslib. For more info about them, please
 * visit: http://docs.moodle.org/en/Development:lib/formslib.php
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/zoom/lib.php');
require_once($CFG->dirroot . '/mod/zoom/locallib.php');

/**
 * Module instance settings form
 */
class mod_zoom_mod_form extends moodleform_mod {
    /**
     * Helper property for showing the scheduling privilege options.
     *
     * @var bool
     */
    private $showschedulingprivilege;

    /**
     * Defines forms elements
     */
    public function definition() {
        global $PAGE, $USER, $OUTPUT;

        // We don't do anything custom with completion data, so avoid doing any unnecessary work.
        $completionpagetypes = [
            'course-defaultcompletion' => 'Edit completion default settings (Moodle >= 4.3)',
            'course-editbulkcompletion' => 'Edit completion settings in bulk for a single course',
            'course-editdefaultcompletion' => 'Edit completion default settings (Moodle < 4.3)',
        ];
        if (isset($completionpagetypes[$PAGE->pagetype])) {
            return;
        }

        $config = get_config('zoom');
        $PAGE->requires->js_call_amd("mod_zoom/form", 'init');

        $isnew = empty($this->_cm);

        $zoomuserid = zoom_get_user_id(false);

        // If creating a new instance, but the Zoom user does not exist.
        if ($isnew && $zoomuserid === false) {
            // Assume user is using Zoom for the first time.
            $errstring = 'zoomerr_usernotfound';
            // After they set up their account, the user should continue to the page they were on.
            $nexturl = $PAGE->url;
            zoom_fatal_error($errstring, 'mod_zoom', $nexturl, $config->zoomurl);
        }

        // Array of emails and proper names of Moodle users in this course that
        // can add Zoom meetings, and the user can schedule.
        $scheduleusers = [];

        $canschedule = false;
        if ($zoomuserid !== false) {
            // Get the array of users they can schedule.
            $canschedule = zoom_webservice()->get_schedule_for_users($zoomuserid);
        }

        if (!empty($canschedule)) {
            // Add the current user.
            $canschedule[$zoomuserid] = new stdClass();
            $canschedule[$zoomuserid]->email = $USER->email;

            // If the activity exists and the current user is not the current host.
            if (!$isnew && $zoomuserid !== $this->current->host_id) {
                // Get intersection of current host's schedulers and $USER's schedulers to prevent zoom errors.
                $currenthostschedulers = zoom_webservice()->get_schedule_for_users($this->current->host_id);
                if (!empty($currenthostschedulers)) {
                    // Since this is the second argument to array_intersect_key,
                    // the entry from $canschedule will be used, so we can just
                    // use true to avoid a service call.
                    $currenthostschedulers[$this->current->host_id] = true;
                }

                $canschedule = array_intersect_key($canschedule, $currenthostschedulers);
            }

            // Get list of users who can add Zoom activities in this context.
            $moodleusers = get_enrolled_users($this->context, 'mod/zoom:addinstance', 0, 'u.*', 'lastname');

            // Check each potential host to see if they are a valid host.
            foreach ($canschedule as $zoomuserinfo) {
                $zoomemail = strtolower($zoomuserinfo->email);
                if (isset($scheduleusers[$zoomemail])) {
                    continue;
                }

                if ($zoomemail === strtolower($USER->email)) {
                    $scheduleusers[$zoomemail] = get_string('scheduleforself', 'zoom');
                    continue;
                }

                foreach ($moodleusers as $muser) {
                    if ($zoomemail === strtolower($muser->email)) {
                        $scheduleusers[$zoomemail] = fullname($muser);
                        break;
                    }
                }
            }
        }

        if (!$isnew) {
            try {
                zoom_webservice()->get_meeting_webinar_info($this->current->meeting_id, $this->current->webinar);
            } catch (\mod_zoom\webservice_exception $error) {
                // If the meeting can't be found, offer to recreate the meeting on Zoom.
                if (zoom_is_meeting_gone_error($error)) {
                    $errstring = 'zoomerr_meetingnotfound';
                    $param = zoom_meetingnotfound_param($this->_cm->id);
                    $nexturl = "/mod/zoom/view.php?id=" . $this->_cm->id;
                    zoom_fatal_error($errstring, 'mod_zoom', $nexturl, $param, "meeting/get : $error");
                } else {
                    throw $error;
                }
            }
        }

        // If the current editing user has the host saved in the db for this meeting on their list
        // of people that they can schedule for, allow them to change the host, otherwise don't.
        $allowschedule = false;
        if (!$isnew) {
            // Only need to check if there are scheduling options available.
            if (!empty($scheduleusers)) {
                try {
                    $founduser = zoom_get_user($this->current->host_id);
                    if ($founduser && array_key_exists($founduser->email, $scheduleusers)) {
                        $allowschedule = true;
                    }
                } catch (moodle_exception $error) {
                    // Don't need to throw an error, just leave allowschedule as false.
                    $allowschedule = false;
                }
            }
        } else {
            $allowschedule = true;
        }

        // Start of form definition.
        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are showed.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Add title (stored in database as 'name').
        $mform->addElement('text', 'name', get_string('title', 'zoom'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 200), 'maxlength', 200, 'client');

        // Add description 'intro' and 'introformat'.
        $this->standard_intro_elements();

        // Adding the "schedule" fieldset, where all settings relating to date and time are shown.
        $mform->addElement('header', 'schedule', get_string('schedule', 'mod_zoom'));
        $mform->setExpanded('schedule');

        // Add date/time. Validation in validation().
        $starttimeoptions = [
            'step' => 5,
            'defaulttime' => time() + 3600,
        ];
        $mform->addElement('date_time_selector', 'start_time', get_string('start_time', 'zoom'), $starttimeoptions);
        // Start time needs to be enabled/disabled based on recurring checkbox as well recurrence_type.
        // Moved this control to javascript, rather than using disabledIf.

        // Add duration.
        $mform->addElement('duration', 'duration', get_string('duration', 'zoom'), ['optional' => false]);
        // Validation in validation(). Default to one hour.
        $mform->setDefault('duration', ['number' => 1, 'timeunit' => 3600]);
        // Duration needs to be enabled/disabled based on recurring checkbox as well recurrence_type.
        // Moved this control to javascript, rather than using disabledIf.

        // Add recurring widget.
        $mform->addElement(
            'advcheckbox',
            'recurring',
            get_string('recurringmeeting', 'zoom'),
            get_string('recurringmeetingthisis', 'zoom')
        );
        $mform->setDefault('recurring', $config->defaultrecurring);
        $mform->addHelpButton('recurring', 'recurringmeeting', 'zoom');

        // Add options for recurring meeting.
        $recurrencetype = [
            ZOOM_RECURRINGTYPE_DAILY => get_string('recurrence_option_daily', 'zoom'),
            ZOOM_RECURRINGTYPE_WEEKLY => get_string('recurrence_option_weekly', 'zoom'),
            ZOOM_RECURRINGTYPE_MONTHLY => get_string('recurrence_option_monthly', 'zoom'),
            ZOOM_RECURRINGTYPE_NOTIME => get_string('recurrence_option_no_time', 'zoom'),
        ];
        $mform->addElement('select', 'recurrence_type', get_string('recurrencetype', 'zoom'), $recurrencetype);
        // If the defaultrecurring option is active, set default recurrence_type to be No Fixed Time.
        if ($config->defaultrecurring == 1) {
            $mform->setDefault('recurrence_type', ZOOM_RECURRINGTYPE_NOTIME);
        }
        $mform->hideif('recurrence_type', 'recurring', 'notchecked');

        // Repeat Interval options.
        $options = [];
        for ($i = 1; $i <= 90; $i++) {
            $options[$i] = $i;
        }

        $group = [];
        $group[] = $mform->createElement('select', 'repeat_interval', '', $options);
        $htmlspantextstart = '<span class="repeat_interval" id="interval_';
        $htmlspantextend = '</span>';
        $group[] = $mform->createElement('html', $htmlspantextstart . 'daily">' . get_string('day', 'zoom') . $htmlspantextend);
        $group[] = $mform->createElement('html', $htmlspantextstart . 'weekly">' . get_string('week', 'zoom') . $htmlspantextend);
        $group[] = $mform->createElement('html', $htmlspantextstart . 'monthly">' . get_string('month', 'zoom') . $htmlspantextend);
        $mform->addGroup($group, 'repeat_group', get_string('repeatinterval', 'zoom'), null, false);
        $mform->hideif('repeat_group', 'recurrence_type', 'eq', ZOOM_RECURRINGTYPE_NOTIME);
        $mform->hideif('repeat_group', 'recurring', 'notchecked');

        // Weekly options.
        $weekdayoptions = zoom_get_weekday_options();
        $group = [];
        foreach ($weekdayoptions as $key => $weekday) {
            $weekdayid = 'weekly_days_' . $key;
            $attributes = [];
            $group[] = $mform->createElement('advcheckbox', $weekdayid, '', $weekday, null, [0, $key]);
        }

        $mform->addGroup($group, 'weekly_days_group', get_string('occurson', 'zoom'), ' ', false);
        $mform->hideif('weekly_days_group', 'recurrence_type', 'noteq', ZOOM_RECURRINGTYPE_WEEKLY);
        $mform->hideif('weekly_days_group', 'recurring', 'notchecked');
        if (!empty($this->current->weekly_days)) {
            $weekdaynumbers = explode(',', $this->current->weekly_days);
            foreach ($weekdaynumbers as $daynumber) {
                $weekdayid = 'weekly_days_' . $daynumber;
                $mform->setDefault($weekdayid, $daynumber);
            }
        }

        // Monthly options.
        $monthoptions = [];
        for ($i = 1; $i <= 31; $i++) {
            $monthoptions[$i] = $i;
        }

        $monthlyweekoptions = zoom_get_monthweek_options();

        $group = [];
        $group[] = $mform->createElement(
            'radio',
            'monthly_repeat_option',
            '',
            get_string('day', 'calendar'),
            ZOOM_MONTHLY_REPEAT_OPTION_DAY
        );
        $group[] = $mform->createElement('select', 'monthly_day', '', $monthoptions);
        $group[] = $mform->createElement('static', 'month_day_text', '', get_string('month_day_text', 'zoom'));
        $group[] = $mform->createElement('radio', 'monthly_repeat_option', '', '', ZOOM_MONTHLY_REPEAT_OPTION_WEEK);
        $group[] = $mform->createElement('select', 'monthly_week', '', $monthlyweekoptions);
        $group[] = $mform->createElement('select', 'monthly_week_day', '', $weekdayoptions);
        $group[] = $mform->createElement('static', 'month_week_day_text', '', get_string('month_day_text', 'zoom'));
        $mform->addGroup($group, 'monthly_day_group', get_string('occurson', 'zoom'), null, false);
        $mform->hideif('monthly_day_group', 'recurrence_type', 'noteq', ZOOM_RECURRINGTYPE_MONTHLY);
        $mform->hideif('monthly_day_group', 'recurring', 'notchecked');
        $mform->setDefault('monthly_repeat_option', ZOOM_MONTHLY_REPEAT_OPTION_DAY);

        // End date option.
        $maxoptions = [];
        for ($i = 1; $i <= 50; $i++) {
            $maxoptions[$i] = $i;
        }

        $group = [];
        $group[] = $mform->createElement(
            'radio',
            'end_date_option',
            '',
            get_string('end_date_option_by', 'zoom'),
            ZOOM_END_DATE_OPTION_BY
        );
        $group[] = $mform->createElement('date_selector', 'end_date_time', '');
        $group[] = $mform->createElement(
            'radio',
            'end_date_option',
            '',
            get_string('end_date_option_after', 'zoom'),
            ZOOM_END_DATE_OPTION_AFTER
        );
        $group[] = $mform->createElement('select', 'end_times', '', $maxoptions);
        $group[] = $mform->createElement('static', 'end_times_text', '', get_string('end_date_option_occurrences', 'zoom'));
        $mform->addGroup($group, 'radioenddate', get_string('enddate', 'zoom'), null, false);
        $mform->hideif('radioenddate', 'recurring', 'notchecked');
        $mform->hideif('radioenddate', 'recurrence_type', 'eq', ZOOM_RECURRINGTYPE_NOTIME);
        // Set default option for end date to be "By".
        $mform->setDefault('end_date_option', ZOOM_END_DATE_OPTION_BY);
        // Set default end_date_time to be 1 week in the future.
        $mform->setDefault('end_date_time', strtotime('+1 week'));

        // Supplementary feature: Webinars.
        // Only show if the admin did not disable this feature completely.
        if ($config->showwebinars != ZOOM_WEBINAR_DISABLE) {
            // If we are creating a new instance.
            if ($isnew) {
                // Check if the user has a webinar license.
                $userfeatures = zoom_get_user_settings($zoomuserid)->feature;
                $haswebinarlicense = !empty($userfeatures->webinar) || !empty($userfeatures->zoom_events);

                // Only show if the admin always wants to show this widget or
                // if the admin wants to show this widget conditionally and the user has a valid license.
                if (
                    $config->showwebinars == ZOOM_WEBINAR_ALWAYSSHOW ||
                    ($config->showwebinars == ZOOM_WEBINAR_SHOWONLYIFLICENSE && $haswebinarlicense)
                ) {
                    // Add webinar option, disabled if the user cannot create webinars.
                    $webinarattr = null;
                    if (!$haswebinarlicense) {
                        $webinarattr = ['disabled' => true, 'group' => null];
                    }

                    $mform->addElement(
                        'advcheckbox',
                        'webinar',
                        get_string('webinar', 'zoom'),
                        get_string('webinarthisis', 'zoom'),
                        $webinarattr
                    );
                    $mform->setDefault('webinar', $config->webinardefault);
                    $mform->addHelpButton('webinar', 'webinar', 'zoom');
                }
            } else if ($this->current->webinar) {
                $mform->addElement(
                    'static',
                    'webinaralreadyset',
                    get_string('webinar', 'zoom'),
                    get_string('webinar_already_true', 'zoom')
                );
            } else {
                $mform->addElement(
                    'static',
                    'webinaralreadyset',
                    get_string('webinar', 'zoom'),
                    get_string('webinar_already_false', 'zoom')
                );
            }
        }

        // Add tracking fields, if configured in Moodle AND Zoom.
        $defaulttrackingfields = zoom_clean_tracking_fields();
        foreach ($defaulttrackingfields as $key => $defaulttrackingfield) {
            $configname = 'tf_' . $key . '_field';
            if (!empty($config->$configname)) {
                $mform->addElement('text', $key, $defaulttrackingfield);
                $mform->setType($key, PARAM_TEXT);
                $rvprop = 'tf_' . $key . '_recommended_values';
                if (!empty($config->$rvprop)) {
                    $mform->addElement(
                        'static',
                        $key . '_recommended_values',
                        null,
                        get_string('trackingfields_recommendedvalues', 'mod_zoom') . $config->$rvprop
                    );
                }

                $requiredproperty = 'tf_' . $key . '_required';
                if (!empty($config->$requiredproperty)) {
                    $mform->addRule($key, null, 'required', null, 'client');
                }
            }
        }

        // Add show widget.
        $mform->addElement(
            'advcheckbox',
            'show_schedule',
            get_string('showschedule', 'zoom'),
            get_string('showscheduleonview', 'zoom')
        );
        $mform->setDefault('show_schedule', $config->defaultshowschedule);
        $mform->addHelpButton('show_schedule', 'showschedule', 'zoom');

        // Add registration widget.
        $registrationoptions = [
            ZOOM_REGISTRATION_OFF => get_string('no'),
            ZOOM_REGISTRATION_AUTOMATIC => get_string('registration_text', 'mod_zoom'),
        ];
        $mform->addElement('select', 'registration', get_string('registration', 'mod_zoom'), $registrationoptions);
        $mform->setDefault('registration', $config->defaultregistration);
        $mform->addHelpButton('registration', 'registration', 'mod_zoom');
        $mform->hideIf('registration', 'recurrence_type', 'eq', ZOOM_RECURRINGTYPE_NOTIME);

        // Adding the "breakout rooms" fieldset.
        if ($config->preassignbreakoutrooms) {
            $mform->addElement('header', 'breakoutrooms', get_string('breakoutrooms', 'mod_zoom'));
            $mform->setExpanded('breakoutrooms');

            $courseid = $this->current->course;
            $context = context_course::instance($courseid);

            $groups = groups_get_all_groups($courseid);
            $participants = get_enrolled_users($context);

            // Getting Course participants.
            $courseparticipants = [];
            foreach ($participants as $participant) {
                $courseparticipants[] = [
                    'participantid' => $participant->id,
                    'participantname' => fullname($participant) . ' <' . $participant->email . '>',
                ];
            }

            // Getting Course groups.
            $coursegroups = [];
            foreach ($groups as $group) {
                $coursegroups[] = ['groupid' => $group->id, 'groupname' => $group->name];
            }

            // Building meeting breakout rooms template data.
            $templatedata = [
                'rooms' => [],
                'roomscount' => 0,
                'roomtoclone' => [
                    'toclone' => 'toclone',
                    'courseparticipants' => $courseparticipants,
                    'coursegroups' => $coursegroups,
                ],
            ];

            $currentinstance = $this->current->instance;
            if ($currentinstance) {
                $rooms = zoom_build_instance_breakout_rooms_array_for_view($currentinstance, $courseparticipants, $coursegroups);

                $templatedata['rooms'] = $rooms;
                $templatedata['roomscount'] = count($rooms);
            }

            $mform->addElement('html', $OUTPUT->render_from_template('zoom/breakoutrooms_rooms', $templatedata));
        }

        $mform->addElement('hidden', 'rooms', '');
        $mform->setType('rooms', PARAM_RAW);

        $mform->addElement('hidden', 'roomsparticipants', '');
        $mform->setType('roomsparticipants', PARAM_RAW);

        $mform->addElement('hidden', 'roomsgroups', '');
        $mform->setType('roomsgroups', PARAM_RAW);

        // Adding the "security" fieldset, where all settings relating to securing and protecting the meeting are shown.
        $mform->addElement('header', 'security', get_string('security', 'mod_zoom'));
        $mform->setExpanded('security');

        // Deals with password manager issues.
        if (isset($this->current->password)) {
            $this->current->meetingcode = $this->current->password;
            unset($this->current->password);
        }

        // Add password requirement prompt.
        $mform->addElement(
            'advcheckbox',
            'requirepasscode',
            get_string('password', 'zoom'),
            get_string('requirepasscode', 'zoom')
        );
        if (isset($this->current->meetingcode) && strval($this->current->meetingcode) === "") {
            $mform->setDefault('requirepasscode', 0);
        } else {
            $mform->setDefault('requirepasscode', 1);
        }

        $mform->addHelpButton('requirepasscode', 'requirepasscode', 'zoom');

        // Set default passcode and description from Zoom security settings.
        $securitysettings = zoom_get_meeting_security_settings($this->current->host_id ?? $zoomuserid);
        // Add password.
        $mform->addElement('text', 'meetingcode', get_string('setpasscode', 'zoom'), ['maxlength' => '10']);
        $mform->setType('meetingcode', PARAM_TEXT);
        // Check password uses valid characters.
        $regex = '/^[a-zA-Z0-9@_*-]{1,10}$/';
        $mform->addRule('meetingcode', get_string('err_invalid_password', 'mod_zoom'), 'regex', $regex, 'client');
        $mform->setDefault('meetingcode', zoom_create_default_passcode($securitysettings->meeting_password_requirement));
        $mform->hideIf('meetingcode', 'requirepasscode', 'notchecked');
        // Add passcode requirements note (use mform group trick from MDL-66251 to be able to conditionally hide this).
        $passwordrequirementsgroup = [];
        $passwordrequirementsgroup[] =& $mform->createElement(
            'static',
            'passwordrequirements',
            '',
            zoom_create_passcode_description($securitysettings->meeting_password_requirement)
        );
        $mform->addGroup($passwordrequirementsgroup, 'passwordrequirementsgroup', '', '', false);
        $mform->hideIf('passwordrequirementsgroup', 'requirepasscode', 'notchecked');

        // Supplementary feature: Encryption type.
        // Only show if the admin did not disable this feature completely.
        if ($config->showencryptiontype != ZOOM_ENCRYPTION_DISABLE) {
            // Check if the user can use e2e encryption.
            $e2eispossible = $securitysettings->end_to_end_encrypted_meetings;

            if ($config->showencryptiontype == ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE && !$e2eispossible) {
                // If user cannot use e2e and option is not shown to user,
                // default to enhanced encryption.
                $mform->addElement('hidden', 'option_encryption_type', ZOOM_ENCRYPTION_TYPE_ENHANCED);
            } else if (
                $config->showencryptiontype == ZOOM_ENCRYPTION_ALWAYSSHOW ||
                ($config->showencryptiontype == ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE && $e2eispossible)
            ) {
                // Only show if the admin always wants to show this widget or
                // if the admin wants to show this widget conditionally and the user can use e2e encryption.

                // Add encryption type option, disabled if the user can't use e2e encryption.
                $encryptionattr = null;
                $defaultencryptiontype = $config->defaultencryptiontypeoption;
                if (!$e2eispossible) {
                    $encryptionattr = ['disabled' => true];
                    $defaultencryptiontype = ZOOM_ENCRYPTION_TYPE_ENHANCED;
                }

                $mform->addGroup([
                    $mform->createElement(
                        'radio',
                        'option_encryption_type',
                        '',
                        get_string('option_encryption_type_enhancedencryption', 'zoom'),
                        ZOOM_ENCRYPTION_TYPE_ENHANCED,
                        $encryptionattr
                    ),
                    $mform->createElement(
                        'radio',
                        'option_encryption_type',
                        '',
                        get_string('option_encryption_type_endtoendencryption', 'zoom'),
                        ZOOM_ENCRYPTION_TYPE_E2EE,
                        $encryptionattr
                    ),
                ], 'option_encryption_type_group', get_string('option_encryption_type', 'zoom'), null, false);
                $mform->setDefault('option_encryption_type', $defaultencryptiontype);
                $mform->addHelpButton('option_encryption_type_group', 'option_encryption_type', 'zoom');
                $mform->disabledIf('option_encryption_type_group', 'webinar', 'checked');
            }

            $mform->setType('option_encryption_type', PARAM_ALPHANUMEXT);
        }

        // Add waiting room widget.
        $mform->addElement(
            'advcheckbox',
            'option_waiting_room',
            get_string('option_waiting_room', 'zoom'),
            get_string('waitingroomenable', 'zoom')
        );
        $mform->addHelpButton('option_waiting_room', 'option_waiting_room', 'zoom');
        $mform->setDefault('option_waiting_room', $config->defaultwaitingroomoption);
        $mform->disabledIf('option_waiting_room', 'webinar', 'checked');

        // Add join before host widget.
        $mform->addElement(
            'advcheckbox',
            'option_jbh',
            get_string('option_jbh', 'zoom'),
            get_string('joinbeforehostenable', 'zoom')
        );
        $mform->setDefault('option_jbh', $config->defaultjoinbeforehost);
        $mform->addHelpButton('option_jbh', 'option_jbh', 'zoom');
        $mform->disabledIf('option_jbh', 'webinar', 'checked');

        // Add authenticated users widget.
        $mform->addElement(
            'advcheckbox',
            'option_authenticated_users',
            get_string('authentication', 'zoom'),
            get_string('option_authenticated_users', 'zoom')
        );
        $mform->setDefault('option_authenticated_users', $config->defaultauthusersoption);
        $mform->addHelpButton('option_authenticated_users', 'option_authenticated_users', 'zoom');

        // Add show widget.
        $mform->addElement(
            'advcheckbox',
            'show_security',
            get_string('showsecurity', 'zoom'),
            get_string('showsecurityonview', 'zoom')
        );
        $mform->setDefault('show_security', $config->defaultshowsecurity);
        $mform->addHelpButton('show_security', 'showsecurity', 'zoom');

        // Adding the "media" fieldset, where all settings relating to media streams in the meeting are shown.
        $mform->addElement('header', 'media', get_string('media', 'mod_zoom'));
        $mform->setExpanded('media');

        // Add host/participants video options.
        $mform->addGroup([
            $mform->createElement('radio', 'option_host_video', '', get_string('on', 'zoom'), true),
            $mform->createElement('radio', 'option_host_video', '', get_string('off', 'zoom'), false),
        ], 'option_host_video_group', get_string('option_host_video', 'zoom'), null, false);
        $mform->setDefault('option_host_video', $config->defaulthostvideo);
        $mform->addHelpButton('option_host_video_group', 'option_host_video', 'zoom');
        $mform->disabledIf('option_host_video_group', 'webinar', 'checked');

        $mform->addGroup([
            $mform->createElement('radio', 'option_participants_video', '', get_string('on', 'zoom'), true),
            $mform->createElement('radio', 'option_participants_video', '', get_string('off', 'zoom'), false),
        ], 'option_participants_video_group', get_string('option_participants_video', 'zoom'), null, false);
        $mform->setDefault('option_participants_video', $config->defaultparticipantsvideo);
        $mform->addHelpButton('option_participants_video_group', 'option_participants_video', 'zoom');
        $mform->disabledIf('option_participants_video_group', 'webinar', 'checked');

        // Add audio options.
        $mform->addGroup([
            $mform->createElement('radio', 'option_audio', '', get_string('audio_telephony', 'zoom'), ZOOM_AUDIO_TELEPHONY),
            $mform->createElement('radio', 'option_audio', '', get_string('audio_voip', 'zoom'), ZOOM_AUDIO_VOIP),
            $mform->createElement('radio', 'option_audio', '', get_string('audio_both', 'zoom'), ZOOM_AUDIO_BOTH),
        ], 'option_audio_group', get_string('option_audio', 'zoom'), null, false);
        $mform->addHelpButton('option_audio_group', 'option_audio', 'zoom');
        $mform->setDefault('option_audio', $config->defaultaudiooption);

        // Add mute participants upon entry widget.
        $mform->addElement(
            'advcheckbox',
            'option_mute_upon_entry',
            get_string('audiodefault', 'mod_zoom'),
            get_string('option_mute_upon_entry', 'mod_zoom')
        );
        $mform->setDefault('option_mute_upon_entry', $config->defaultmuteuponentryoption);
        $mform->addHelpButton('option_mute_upon_entry', 'option_mute_upon_entry', 'mod_zoom');

        $hostuserid = $zoomuserid;
        if (!empty($this->current->host_id)) {
            $hostuserid = $this->current->host_id;
        }

        // Add autorecording option if enabled.
        $allowrecordingchangeoption = $config->allowrecordingchangeoption;
        if ($allowrecordingchangeoption) {
            $defaultsetting = $config->recordingoption;

            // Add auto recording options according to user settings.
            $options = [
                ZOOM_AUTORECORDING_NONE => get_string('autorecording_none', 'mod_zoom'),
            ];

            if (!empty($hostuserid)) {
                $recordingsettings = zoom_get_user_settings($hostuserid)->recording;

                if ($config->recordingoption === ZOOM_AUTORECORDING_USERDEFAULT) {
                    $defaultsetting = $recordingsettings->auto_recording;
                }

                if (!empty($recordingsettings->local_recording)) {
                    $options[ZOOM_AUTORECORDING_LOCAL] = get_string('autorecording_local', 'mod_zoom');
                }

                if (!empty($recordingsettings->cloud_recording)) {
                    $options[ZOOM_AUTORECORDING_CLOUD] = get_string('autorecording_cloud', 'mod_zoom');
                }
            }

            $mform->addElement('select', 'option_auto_recording', get_string('option_auto_recording', 'mod_zoom'), $options);
            $mform->setDefault('option_auto_recording', $defaultsetting);
            $mform->addHelpButton('option_auto_recording', 'option_auto_recording', 'mod_zoom');
        }

        // Add show widget.
        $mform->addElement(
            'advcheckbox',
            'show_media',
            get_string('showmedia', 'zoom'),
            get_string('showmediaonview', 'zoom')
        );
        $mform->setDefault('show_media', $config->defaultshowmedia);
        $mform->addHelpButton('show_media', 'showmedia', 'zoom');

        // Check if there is any setting to be shown in the "host" fieldset.
        $showschedulingprivilege = ($config->showschedulingprivilege != ZOOM_SCHEDULINGPRIVILEGE_DISABLE) &&
                count($scheduleusers) > 1 && $allowschedule; // Check if the size is greater than 1 because
                                                             // we add the editing/creating user by default.
        $this->showschedulingprivilege = $showschedulingprivilege;
        $showalternativehosts = ($config->showalternativehosts != ZOOM_ALTERNATIVEHOSTS_DISABLE);
        if ($showschedulingprivilege || $showalternativehosts) {
            // Adding the "host" fieldset, where all settings relating to defining the meeting host are shown.
            $mform->addElement('header', 'host', get_string('host', 'mod_zoom'));
            $mform->setExpanded('host');

            // Supplementary feature: Alternative hosts.
            // Only show if the admin did not disable this feature completely.
            if ($showalternativehosts) {
                // Explain alternativehosts.
                $mform->addElement('static', 'hostintro', '', get_string('hostintro', 'zoom'));

                // If the admin wants to show the plain input field.
                if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_INPUTFIELD) {
                    // Add alternative hosts.
                    $mform->addElement('text', 'alternative_hosts', get_string('alternative_hosts', 'zoom'), ['size' => '64']);
                    $mform->setType('alternative_hosts', PARAM_TEXT);
                    $mform->addHelpButton('alternative_hosts', 'alternative_hosts', 'zoom');

                    // If the admin wants to show the user picker.
                } else if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_PICKER) {
                    // Get selectable alternative host users based on the capability.
                    $alternativehostschoices = zoom_get_selectable_alternative_hosts_list($this->context);
                    // Create autocomplete widget.
                    $alternativehostsoptions = [
                        'multiple' => true,
                        'showsuggestions' => true,
                        'placeholder' => get_string('alternative_hosts_picker_placeholder', 'zoom'),
                        'noselectionstring' => get_string('alternative_hosts_picker_noneselected', 'zoom'),
                    ];
                    $mform->addElement(
                        'autocomplete',
                        'alternative_hosts_picker',
                        get_string('alternative_hosts', 'zoom'),
                        $alternativehostschoices,
                        $alternativehostsoptions
                    );
                    $mform->setType('alternative_hosts_picker', PARAM_EMAIL);
                    $mform->addHelpButton('alternative_hosts_picker', 'alternative_hosts_picker', 'zoom');
                }
            }

            // Supplementary feature: Scheduling privilege.
            // Only show if the admin did not disable this feature completely and if current user is able to use it.
            if ($showschedulingprivilege) {
                if ($allowrecordingchangeoption) {
                    $PAGE->requires->js_call_amd('mod_zoom/scheduleforchooser', 'init');
                    $mform->addElement('select', 'schedule_for', get_string('schedulefor', 'mod_zoom'), $scheduleusers, [
                        'data-scheduleforchooser-field' => 'selector',
                    ]);
                } else {
                    $mform->addElement('select', 'schedule_for', get_string('schedulefor', 'mod_zoom'), $scheduleusers);
                }

                $mform->setType('schedule_for', PARAM_EMAIL);
                if (!$isnew) {
                    $mform->disabledIf('schedule_for', 'change_schedule_for');
                    $mform->addElement('checkbox', 'change_schedule_for', get_string('changehost', 'zoom'));
                    $mform->setDefault('schedule_for', strtolower(zoom_get_user($this->current->host_id)->email));
                } else {
                    $mform->setDefault('schedule_for', strtolower(zoom_get_api_identifier($USER)));
                }

                $mform->addHelpButton('schedule_for', 'schedulefor', 'zoom');

                if ($allowrecordingchangeoption) {
                    // Button to update auto recording options based on the user permissions in Zoom (will be hidden by JavaScript).
                    $mform->registerNoSubmitButton('updateautorecordingoptions');
                    $mform->addElement(
                        'submit',
                        'updateautorecordingoptions',
                        get_string('autorecordingoptionsupdate', 'mod_zoom'),
                        [
                            'data-scheduleforchooser-field' => 'updateButton',
                            'class' => 'd-none',
                        ]
                    );
                }
            }
        }

        // Adding option for Recording Visiblity by default.
        if (!empty($config->viewrecordings)) {
            $mform->addElement('header', 'recording', get_string('recording', 'mod_zoom'));
            $mform->addElement(
                'advcheckbox',
                'recordings_visible_default',
                get_string('recordingvisibility', 'mod_zoom'),
                get_string('yes')
            );
            $mform->setDefault('recordings_visible_default', 1);
            $mform->addHelpButton('recordings_visible_default', 'recordingvisibility', 'mod_zoom');
        }

        // Add meeting id.
        $mform->addElement('hidden', 'meeting_id', -1);
        $mform->setType('meeting_id', PARAM_ALPHANUMEXT);

        // Add host id (will error if user does not have an account on Zoom).
        $mform->addElement('hidden', 'host_id', $hostuserid);
        $mform->setType('host_id', PARAM_ALPHANUMEXT);

        // Add standard grading elements.
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', false);

        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();
        $this->apply_admin_defaults();

        // Add standard buttons, common to all modules.
        $this->add_action_buttons();
    }

    /**
     * Add standard_grading_coursemodule_elements with grading for field.
     * @return void
     */
    public function standard_grading_coursemodule_elements() {
        global $DB;

        parent::standard_grading_coursemodule_elements();
        $mform = $this->_form;
        $itemnumber = 0;
        $component = "mod_{$this->_modname}";

        // A cumulative activity's grade item maximum is the points per occurrence times the occurrences, so when
        // grades lock the grade field, the form must keep the points per occurrence rather than that maximum.
        if (!empty($this->_instance) && class_exists('\\core_grades\\component_gradeitems')) {
            $zoom = $DB->get_record('zoom', ['id' => $this->_instance]);
            if ($zoom && \mod_zoom\grades\occurrences::applies($zoom)) {
                $gradefieldname = \core_grades\component_gradeitems::get_field_name_for_itemnumber(
                    $component,
                    $itemnumber,
                    'grade'
                );
                $mform->getElement($gradefieldname)->currentgrade = $zoom->grade;
            }
        }
        $options = [
            'entry' => get_string('gradingentry', 'mod_zoom'), // All credit upon entry.
            'period' => get_string('gradingperiod', 'mod_zoom'), // Credit according to attend duration.
        ];
        $mform->addElement('select', 'grading_method', get_string('gradingmethod', 'mod_zoom'), $options);
        $mform->setDefault('grading_method', get_config('zoom', 'gradingmethod'));
        $mform->addHelpButton('grading_method', 'gradingmethod', 'mod_zoom');

        // Requires Moodle 3.8+. Hide field if the grade item is not graded.
        if (class_exists('\\core_grades\\component_gradeitems')) {
            $gradefieldname = \core_grades\component_gradeitems::get_field_name_for_itemnumber($component, $itemnumber, 'grade');
            $mform->hideIf('grading_method', "{$gradefieldname}[modgrade_type]", 'eq', 'none');
        }
    }

    /**
     * Fill in the current page data for this course.
     */
    public function definition_after_data() {
        parent::definition_after_data();

        // Get config.
        $config = get_config('zoom');

        if (!$config->allowrecordingchangeoption) {
            return;
        }

        $mform = $this->_form;

        if ($this->showschedulingprivilege) {
            $scheduleelement =& $mform->getElement('schedule_for');
            $values = $scheduleelement->getValue();

            if (empty($values)) {
                return;
            }

            $scheduleforuser = current($values);
            $zoomuser = zoom_get_user($scheduleforuser);
            $zoomuserid = $zoomuser->id;
        } else if (!empty($this->current->host_id)) {
            $zoomuserid = $this->current->host_id;
        } else {
            $zoomuserid = zoom_get_user_id(false);
        }

        $recordingelement =& $mform->getElement('option_auto_recording');
        $recordingelement->removeOptions();

        // Add auto recording options according to user settings.
        $options = [
            ZOOM_AUTORECORDING_NONE => get_string('autorecording_none', 'mod_zoom'),
        ];

        if ($zoomuserid !== false) {
            $recordingsettings = zoom_get_user_settings($zoomuserid)->recording;

            if (!empty($recordingsettings->local_recording)) {
                $options[ZOOM_AUTORECORDING_LOCAL] = get_string('autorecording_local', 'mod_zoom');
            }

            if (!empty($recordingsettings->cloud_recording)) {
                $options[ZOOM_AUTORECORDING_CLOUD] = get_string('autorecording_cloud', 'mod_zoom');
            }
        }

        $recordingelement->load($options);
    }

    /**
     * Allows module to modify the data returned by form get_data().
     * This method is also called in the bulk activity completion form.
     *
     * Only available on moodleform_mod.
     *
     * @param stdClass $data the form data to be modified.
     */
    public function data_postprocessing($data) {
        global $DB;

        parent::data_postprocessing($data);

        // Get config.
        $config = get_config('zoom');

        // If the admin did show the alternative hosts user picker.
        if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_PICKER) {
            // If there was at least one alternative host selected, process these users.
            if (count($data->alternative_hosts_picker) > 0) {
                // Populate the alternative_hosts field with a concatenated string of email addresses.
                // This is done as this is the format which Zoom expects and alternative_hosts is the field to store the data
                // in mod_zoom.
                // The alternative host user picker is just an add-on to help teachers to fill this field.
                $data->alternative_hosts = implode(',', $data->alternative_hosts_picker);

                // If there wasn't any alternative host selected.
            } else {
                $data->alternative_hosts = '';
            }

            // Unfortunately, the host is not only able to add alternative hosts in Moodle with the user picker.
            // He is also able to add any alternative host with an email address in Zoom directly.
            // Thus, we have to get the latest list of alternative hosts from the DB again now,
            // identify the users who were not selectable at all in this form and append them to the list
            // of selected alternative hosts.

            // Get latest list of alternative hosts from the DB.
            $result = $DB->get_field('zoom', 'alternative_hosts', ['meeting_id' => $data->meeting_id], IGNORE_MISSING);

            // Proceed only if there is a field of alternative hosts already.
            if ($result !== false) {
                $alternativehostsdb = zoom_get_alternative_host_array_from_string($result);

                // Get selectable alternative host users based on the capability.
                $alternativehostschoices = zoom_get_selectable_alternative_hosts_list($this->context);

                // Iterate over the latest list of alternative hosts from the DB.
                foreach ($alternativehostsdb as $ah) {
                    // If the existing alternative host would not have been selectable.
                    if (!array_key_exists($ah, $alternativehostschoices)) {
                        // Add the alternative host to the alternative_hosts field.
                        if ($data->alternative_hosts == '') {
                            $data->alternative_hosts = $ah;
                        } else {
                            $data->alternative_hosts .= ',' . $ah;
                        }
                    }
                }
            }
        }

        // Add some postprocessing around the recurrence settings.
        if ($data->recurring) {
            // If "No fixed time" meeting selected, dont need repeat_interval and other options.
            if ($data->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) {
                unset($data->repeat_interval);
                // Unset end_times and end_date.
                unset($data->end_date_option);
                unset($data->end_times);
                unset($data->end_date_time);
            }

            // If weekly recurring is not selected, unset weekly options.
            if ($data->recurrence_type != ZOOM_RECURRINGTYPE_WEEKLY) {
                // Unset the weekly fields.
                $data = zoom_remove_weekly_options($data);
            }

            // If monthly recurring is not selected, unset monthly options.
            if ($data->recurrence_type != ZOOM_RECURRINGTYPE_MONTHLY) {
                // Unset the weekly fields.
                $data = zoom_remove_monthly_options($data);
            }
        }

        // Make sure registration is not enabled for No Fixed Time recurring meetings.
        if ($data->recurring && $data->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) {
            if (isset($data->registration) && $data->registration == ZOOM_REGISTRATION_AUTOMATIC) {
                $data->registration = ZOOM_REGISTRATION_OFF;
            }
        }
    }

    /**
     * Allows module to modify data returned by get_moduleinfo_data() or prepare_new_moduleinfo_data() before calling set_data()
     * This method is also called in the bulk activity completion form.
     *
     * Only available on moodleform_mod.
     *
     * @param array $defaultvalues passed by reference
     */
    public function data_preprocessing(&$defaultvalues) {
        global $DB;

        parent::data_preprocessing($defaultvalues);

        // Get config.
        $config = get_config('zoom');

        // If the admin wants to show the alternative hosts user picker.
        if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_PICKER) {
            // If there is at least one alternative host set.
            if (isset($defaultvalues['alternative_hosts']) && strlen($defaultvalues['alternative_hosts']) > 0) {
                // Populate the alternative_hosts_picker field with an exploded array of email addresses.
                // This is done as alternative_hosts is the field to store the data in mod_zoom and
                // the alternative host user picker is just an add-on to help teachers to fill this field.

                // At this point, the alternative_hosts field might also contain users who are not selectable in the user picker
                // as they aren't a member of the course or do not have a Moodle account.
                // This does not matter as user picker default values which don't have a corresponding autocomplete suggestion
                // will be simply ignored.
                // When the form is submitted, these non-selectable alternative hosts will be added again in data_postprocessing().

                // According to the documentation, the Zoom API separates the email addresses with commas,
                // but we also want to deal with semicolon-separated lists just in case.
                $defaultvalues['alternative_hosts_picker'] = zoom_get_alternative_host_array_from_string(
                    $defaultvalues['alternative_hosts']
                );
            }
        }

        if ($config->defaulttrackingfields !== '') {
            // Populate modedit form fields with previously saved values.
            $defaulttrackingfields = zoom_clean_tracking_fields();
            $tfrows = $DB->get_records('zoom_meeting_tracking_fields', ['meeting_id' => $defaultvalues['id']]);
            foreach ($tfrows as $tfrow) {
                $tfkey = $tfrow->tracking_field;
                if (!empty($defaulttrackingfields[$tfkey])) {
                    $defaultvalues[$tfkey] = $tfrow->value;
                }
            }
        }
    }

    /**
     * More validation on form data.
     * See documentation in lib/formslib.php.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $CFG, $USER;

        $errors = parent::validation($data, $files);

        $config = get_config('zoom');

        // Only check for scheduled meetings.
        if (empty($data['recurring'])) {
            // Make sure start date is in the future.
            if ($data['start_time'] < time() && $data['meeting_id'] < 0) {
                $errors['start_time'] = get_string('err_start_time_past', 'zoom');
            }

            // Make sure duration is positive and no more than 150 hours.
            if ($data['duration'] <= 0) {
                $errors['duration'] = get_string('err_duration_nonpositive', 'zoom');
            } else if ($data['duration'] > 150 * 60 * 60) {
                $errors['duration'] = get_string('err_duration_too_long', 'zoom');
            }
        } else if ($data['recurring'] == 1 && $data['recurrence_type'] != ZOOM_RECURRINGTYPE_NOTIME) {
            // Make sure start date time (first potential date of next meeting) is in the future.
            if ($data['start_time'] < time() && $data['meeting_id'] < 0) {
                $errors['start_time'] = get_string('err_start_time_past_recurring', 'zoom');
            }

            // Make sure duration is positive and no more than 150 hours.
            if ($data['duration'] <= 0) {
                $errors['duration'] = get_string('err_duration_nonpositive', 'zoom');
            } else if ($data['duration'] > 150 * 60 * 60) {
                $errors['duration'] = get_string('err_duration_too_long', 'zoom');
            }
        }

        if (!empty($data['requirepasscode']) && empty($data['meetingcode'])) {
            $errors['meetingcode'] = get_string('err_password_required', 'mod_zoom');
        }

        if (isset($data['schedule_for']) && strtolower($data['schedule_for']) !== strtolower(zoom_get_api_identifier($USER))) {
            $zoomuserid = zoom_get_user_id();
            $scheduleusers = zoom_webservice()->get_schedule_for_users($zoomuserid);
            $scheduleok = false;
            foreach ($scheduleusers as $zuser) {
                if (strtolower($zuser->email) === strtolower($data['schedule_for'])) {
                    // Found a matching email address in the Zoom users list.
                    $scheduleok = true;
                    break;
                }
            }

            if (!$scheduleok) {
                $errors['schedule_for'] = get_string('invalidscheduleuser', 'mod_zoom');
            }
        }

        // Supplementary feature: Alternative hosts.
        // Only validate if the admin did not disable this feature completely.
        if ($config->showalternativehosts != ZOOM_ALTERNATIVEHOSTS_DISABLE) {
            // If the admin did show the plain input field.
            if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_INPUTFIELD) {
                // Check if the listed alternative hosts are valid users on Zoom.
                $alternativehosts = zoom_get_alternative_host_array_from_string($data['alternative_hosts']);
                foreach ($alternativehosts as $alternativehost) {
                    if (!(zoom_get_user($alternativehost))) {
                        $errors['alternative_hosts'] = get_string('zoomerr_alternativehostusernotfound', 'zoom', $alternativehost);
                        break;
                    }
                }

                // If the admin did show the user picker.
            } else if ($config->showalternativehosts == ZOOM_ALTERNATIVEHOSTS_PICKER) {
                // Check if the picked alternative hosts are valid users on Zoom.
                foreach ($data['alternative_hosts_picker'] as $alternativehost) {
                    if (!(zoom_get_user($alternativehost))) {
                        $errors['alternative_hosts_picker'] =
                                get_string('zoomerr_alternativehostusernotfound', 'zoom', $alternativehost);
                        break;
                    }
                }
            }
        }

        // Supplementary feature: Encryption type.
        // Only validate if the admin did not disable this feature completely.
        if ($config->showencryptiontype != ZOOM_ENCRYPTION_DISABLE) {
            // Check if given encryption type is valid.
            if (
                $data['option_encryption_type'] !== ZOOM_ENCRYPTION_TYPE_ENHANCED &&
                $data['option_encryption_type'] !== ZOOM_ENCRYPTION_TYPE_E2EE
            ) {
                // This will not happen unless the user tampered with the form.
                // Because of this, we skip adding this string to the language pack.
                $errors['option_encryption_type_group'] = 'The submitted encryption type is not valid.';
            }
        }

        // Add validation for recurring meeting.
        if ($data['recurring'] == 1) {
            if ($data['recurrence_type'] == ZOOM_RECURRINGTYPE_WEEKLY) {
                $weekdaynumbers = [];
                for ($i = 1; $i <= 7; $i++) {
                    $key = 'weekly_days_' . $i;
                    if (!empty($data[$key])) {
                        $weekdaynumbers[] = $i;
                    }
                }

                if (empty($weekdaynumbers)) {
                    $errors['weekly_days_group'] = get_string('err_weekly_days', 'zoom');
                }

                // For weekly, maximum is 12 weeks.
                if ($data['repeat_interval'] > 12) {
                    $errors['repeat_group'] = get_string('err_repeat_weekly_interval', 'zoom');
                }
            }

            if ($data['recurrence_type'] == ZOOM_RECURRINGTYPE_MONTHLY) {
                // For monthly, max is 3 months.
                if ($data['repeat_interval'] > 3) {
                    $errors['repeat_group'] = get_string('err_repeat_monthly_interval', 'zoom');
                }
            }

            if ($data['recurrence_type'] != ZOOM_RECURRINGTYPE_NOTIME && $data['end_date_option'] == ZOOM_END_DATE_OPTION_BY) {
                if ($data['end_date_time'] < time()) {
                    $errors['radioenddate'] = get_string('err_end_date', 'zoom');
                }

                if ($data['end_date_time'] < $data['start_time']) {
                    $errors['radioenddate'] = get_string('err_end_date_before_start', 'zoom');
                }
            }
        }

        // Validation for registration required functionality.
        if ($data['registration'] != ZOOM_REGISTRATION_OFF) {
            // Recurring meeting validation already handled by hiding registration option where required.
            // Check licensing of the user.
            if (!zoom_webservice()->is_user_permitted_to_require_registration()) {
                $errors['registration'] = get_string('err_registration', 'mod_zoom');
            }
        }

        return $errors;
    }
}
