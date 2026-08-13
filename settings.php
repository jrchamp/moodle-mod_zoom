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
 * Settings.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use mod_zoom\invitation;
use mod_zoom\webservice_exception;

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig && $ADMIN->fulltree) {
    require_once($CFG->dirroot . '/mod/zoom/locallib.php');

    // Test whether connection works and display result to user.
    if (!CLI_SCRIPT && $PAGE->url == $CFG->wwwroot . '/' . $CFG->admin . '/settings.php?section=' . $settings->name) {
        $status = 'connectionfailed';
        $notifyclass = notification::NOTIFY_ERROR;
        $errormessage = '';
        try {
            zoom_get_user(zoom_get_api_identifier($USER));
            $status = 'connectionok';
            $notifyclass = notification::NOTIFY_SUCCESS;
        } catch (webservice_exception $error) {
            $errormessage = $error->response;
        } catch (moodle_exception $error) {
            $errormessage = $error->a;
        }

        $settings->add(new admin_setting_heading(
            'zoom/connectionstatus',
            $OUTPUT->notification(
                get_string('connectionstatus', 'mod_zoom') . ': ' . get_string($status, 'mod_zoom') . $errormessage,
                $notifyclass,
                false
            ),
            ''
        ));
    }

    // Connection settings.
    $settings->add(new admin_setting_heading(
        'zoom/connectionsettings',
        new lang_string('connectionsettings', 'mod_zoom'),
        new lang_string('connectionsettings_desc', 'mod_zoom')
    ));

    $settings->add(new admin_setting_configtext(
        'zoom/accountid',
        new lang_string('accountid', 'mod_zoom'),
        new lang_string('accountid_desc', 'mod_zoom'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'zoom/clientid',
        new lang_string('clientid', 'mod_zoom'),
        new lang_string('clientid_desc', 'mod_zoom'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'zoom/clientsecret',
        new lang_string('clientsecret', 'mod_zoom'),
        new lang_string('clientsecret_desc', 'mod_zoom'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'zoom/zoomurl',
        new lang_string('zoomurl', 'mod_zoom'),
        new lang_string('zoomurl_desc', 'mod_zoom'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/apiendpoint',
        new lang_string('apiendpoint', 'mod_zoom'),
        new lang_string('apiendpoint_desc', 'mod_zoom'),
        ZOOM_API_ENDPOINT_GLOBAL,
        [
            ZOOM_API_ENDPOINT_GLOBAL => new lang_string('apiendpoint_global', 'mod_zoom'),
            ZOOM_API_ENDPOINT_EU => new lang_string('apiendpoint_eu', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'zoom/proxyhost',
        new lang_string('option_proxyhost', 'mod_zoom'),
        new lang_string('option_proxyhost_desc', 'mod_zoom'),
        '',
        '/^[a-zA-Z0-9.-]+:[0-9]+$|^$/'
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/apiidentifier',
        new lang_string('apiidentifier', 'mod_zoom'),
        new lang_string('apiidentifier_desc', 'mod_zoom'),
        'email',
        zoom_get_api_identifier_fields()
    ));

    // License settings.
    $settings->add(new admin_setting_heading(
        'zoom/licensesettings',
        new lang_string('licensesettings', 'mod_zoom'),
        new lang_string('licensesettings_desc', 'mod_zoom')
    ));

    $settings->add(new admin_setting_configtext(
        'zoom/licensesnumber',
        new lang_string('licensesnumber', 'mod_zoom'),
        null,
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/utmost',
        new lang_string('redefinelicenses', 'mod_zoom'),
        new lang_string('lowlicenses', 'mod_zoom'),
        0,
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/instanceusers',
        new lang_string('instanceusers', 'mod_zoom'),
        new lang_string('instanceusers_desc', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/recycleonjoin',
        new lang_string('recycleonjoin', 'mod_zoom'),
        new lang_string('licenseonjoin', 'mod_zoom'),
        0,
        1
    ));

    // Only call to the web services and load the setting if the connection is OK.
    if (isset($status) && $status === 'connectionok') {
        $zoomgroups = [];
        $groups = zoom_webservice()->get_groups();
        foreach ($groups as $group) {
            $zoomgroups[$group->id] = $group->name;
        }

        $settings->add(new admin_setting_configmultiselect(
            'zoom/protectedgroups',
            new lang_string('protectedgroups', 'mod_zoom'),
            new lang_string('protectedgroups_desc', 'mod_zoom'),
            [],
            $zoomgroups
        ));
    }

    // Global settings.
    $settings->add(new admin_setting_heading(
        'zoom/globalsettings',
        new lang_string('globalsettings', 'mod_zoom'),
        new lang_string('globalsettings_desc', 'mod_zoom')
    ));

    $jointimechoices = [0, 5, 10, 15, 20, 30, 45, 60];
    $jointimeselect = [];
    foreach ($jointimechoices as $minutes) {
        $jointimeselect[$minutes] = $minutes . ' ' . get_string('mins');
    }

    $settings->add(new admin_setting_configselect(
        'zoom/firstabletojoin',
        new lang_string('firstjoin', 'mod_zoom'),
        new lang_string('firstjoin_desc', 'mod_zoom'),
        15,
        $jointimeselect
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/displayleadtime',
        new lang_string('displayleadtime', 'mod_zoom'),
        new lang_string('displayleadtime_desc', 'mod_zoom'),
        0,
        1,
        0
    ));
    $settings->hide_if('zoom/displayleadtime', 'zoom/firstabletojoin', 'eq', 0);

    $settings->add(new admin_setting_configcheckbox(
        'zoom/displaypassword',
        new lang_string('displaypassword', 'mod_zoom'),
        new lang_string('displaypassword_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/maskparticipantdata',
        new lang_string('maskparticipantdata', 'mod_zoom'),
        new lang_string('maskparticipantdata_help', 'mod_zoom'),
        0,
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/viewrecordings',
        new lang_string('option_view_recordings', 'mod_zoom'),
        '',
        0,
        1,
        0
    ));

    // Adding options for the display name using uname parameter in zoom join_url.
    $settings->add(new admin_setting_configselect(
        'zoom/unamedisplay',
        new lang_string('unamedisplay', 'mod_zoom'),
        new lang_string('unamedisplay_help', 'mod_zoom'),
        'fullname',
        [
            'fullname' => new lang_string('displayfullname', 'mod_zoom'),
            'firstname' => new lang_string('displayfirstname', 'mod_zoom'),
            'idfullname' => new lang_string('displayidfullname', 'mod_zoom'),
            'id' => new lang_string('displayid', 'mod_zoom'),
        ]
    ));

    // Supplementary features settings.
    $settings->add(new admin_setting_heading(
        'zoom/supplementaryfeaturessettings',
        new lang_string('supplementaryfeaturessettings', 'mod_zoom'),
        new lang_string('supplementaryfeaturessettings_desc', 'mod_zoom')
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/showwebinars',
        new lang_string('webinar', 'mod_zoom'),
        new lang_string('webinar_desc', 'mod_zoom'),
        ZOOM_WEBINAR_ALWAYSSHOW,
        [
            ZOOM_WEBINAR_DISABLE => new lang_string('webinar_disable', 'mod_zoom'),
            ZOOM_WEBINAR_SHOWONLYIFLICENSE => new lang_string('webinar_showonlyiflicense', 'mod_zoom'),
            ZOOM_WEBINAR_ALWAYSSHOW => new lang_string('webinar_alwaysshow', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/webinardefault',
        new lang_string('webinar_by_default', 'mod_zoom'),
        new lang_string('webinar_by_default_desc', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/showencryptiontype',
        new lang_string('encryptiontype', 'mod_zoom'),
        new lang_string('encryptiontype_desc', 'mod_zoom'),
        ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE,
        [
            ZOOM_ENCRYPTION_DISABLE => new lang_string('encryptiontype_disable', 'mod_zoom'),
            ZOOM_ENCRYPTION_SHOWONLYIFPOSSIBLE => new lang_string('encryptiontype_showonlyife2epossible', 'mod_zoom'),
            ZOOM_ENCRYPTION_ALWAYSSHOW => new lang_string('encryptiontype_alwaysshow', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/showschedulingprivilege',
        new lang_string('schedulingprivilege', 'mod_zoom'),
        new lang_string('schedulingprivilege_desc', 'mod_zoom'),
        ZOOM_SCHEDULINGPRIVILEGE_ENABLE,
        [
            ZOOM_SCHEDULINGPRIVILEGE_DISABLE => new lang_string('schedulingprivilege_disable', 'mod_zoom'),
            ZOOM_SCHEDULINGPRIVILEGE_ENABLE => new lang_string('schedulingprivilege_enable', 'mod_zoom'),
        ]
    ));

    $alternativehostsroles = zoom_get_selectable_alternative_hosts_rolestring(context_system::instance());
    $settings->add(new admin_setting_configselect(
        'zoom/showalternativehosts',
        new lang_string('alternative_hosts', 'mod_zoom'),
        new lang_string('alternative_hosts_desc', 'mod_zoom', ['roles' => $alternativehostsroles]),
        ZOOM_ALTERNATIVEHOSTS_INPUTFIELD,
        [
            ZOOM_ALTERNATIVEHOSTS_DISABLE => new lang_string('alternative_hosts_disable', 'mod_zoom'),
            ZOOM_ALTERNATIVEHOSTS_INPUTFIELD => new lang_string('alternative_hosts_inputfield', 'mod_zoom'),
            ZOOM_ALTERNATIVEHOSTS_PICKER => new lang_string('alternative_hosts_picker', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/showcapacitywarning',
        new lang_string('meetingcapacitywarning', 'mod_zoom'),
        new lang_string('meetingcapacitywarning_desc', 'mod_zoom'),
        ZOOM_CAPACITYWARNING_ENABLE,
        [
            ZOOM_CAPACITYWARNING_DISABLE => new lang_string('meetingcapacitywarning_disable', 'mod_zoom'),
            ZOOM_CAPACITYWARNING_ENABLE => new lang_string('meetingcapacitywarning_enable', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/showallmeetings',
        new lang_string('allmeetings', 'mod_zoom'),
        new lang_string('allmeetings_desc', 'mod_zoom'),
        ZOOM_ALLMEETINGS_ENABLE,
        [
            ZOOM_ALLMEETINGS_DISABLE => new lang_string('allmeetings_disable', 'mod_zoom'),
            ZOOM_ALLMEETINGS_ENABLE => new lang_string('allmeetings_enable', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/showdownloadical',
        new lang_string('downloadical', 'mod_zoom'),
        new lang_string('downloadical_desc', 'mod_zoom'),
        ZOOM_DOWNLOADICAL_ENABLE,
        [
            ZOOM_DOWNLOADICAL_DISABLE => new lang_string('downloadical_disable', 'mod_zoom'),
            ZOOM_DOWNLOADICAL_ENABLE => new lang_string('downloadical_enable', 'mod_zoom'),
        ]
    ));

    $sendicalnotificationshelp = get_string('sendicalnotifications_help', 'mod_zoom');
    if (empty($CFG->allowattachments)) {
        $sendicalnotificationshelp .= $OUTPUT->notification(
            get_string('sendicalnotifications_warning', 'mod_zoom'),
            notification::NOTIFY_WARNING,
            false
        );
    }

    $settings->add(new admin_setting_configcheckbox(
        'zoom/sendicalnotifications',
        new lang_string('sendicalnotifications', 'mod_zoom'),
        $sendicalnotificationshelp,
        0,
        1,
        0
    ));

    // Default Zoom settings.
    $settings->add(new admin_setting_heading(
        'zoom/defaultsettings',
        new lang_string('defaultsettings', 'mod_zoom'),
        new lang_string('defaultsettings_help', 'mod_zoom')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultrecurring',
        new lang_string('recurringmeeting', 'mod_zoom'),
        new lang_string('recurringmeeting_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultshowschedule',
        new lang_string('showschedule', 'mod_zoom'),
        new lang_string('showschedule_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultregistration',
        new lang_string('registration', 'mod_zoom'),
        new lang_string('registration_help', 'mod_zoom'),
        ZOOM_REGISTRATION_OFF,
        ZOOM_REGISTRATION_AUTOMATIC,
        ZOOM_REGISTRATION_OFF
    ));

    $settings->add(new admin_setting_configcheckbox_with_lock(
        'zoom/requirepasscode',
        new lang_string('requirepasscode', 'mod_zoom'),
        new lang_string('requirepasscode_help', 'mod_zoom'),
        [
            'value' => 1,
            'locked' => true,
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/defaultencryptiontypeoption',
        new lang_string('option_encryption_type', 'mod_zoom'),
        new lang_string('option_encryption_type_help', 'mod_zoom'),
        ZOOM_ENCRYPTION_TYPE_ENHANCED,
        [
            ZOOM_ENCRYPTION_TYPE_ENHANCED => new lang_string('option_encryption_type_enhancedencryption', 'mod_zoom'),
            ZOOM_ENCRYPTION_TYPE_E2EE => new lang_string('option_encryption_type_endtoendencryption', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultwaitingroomoption',
        new lang_string('option_waiting_room', 'mod_zoom'),
        new lang_string('option_waiting_room_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultjoinbeforehost',
        new lang_string('option_jbh', 'mod_zoom'),
        new lang_string('option_jbh_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultauthusersoption',
        new lang_string('option_authenticated_users', 'mod_zoom'),
        new lang_string('option_authenticated_users_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultshowsecurity',
        new lang_string('showsecurity', 'mod_zoom'),
        new lang_string('showsecurity_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaulthostvideo',
        new lang_string('option_host_video', 'mod_zoom'),
        new lang_string('option_host_video_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultparticipantsvideo',
        new lang_string('option_participants_video', 'mod_zoom'),
        new lang_string('option_participants_video_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/defaultaudiooption',
        new lang_string('option_audio', 'mod_zoom'),
        new lang_string('option_audio_help', 'mod_zoom'),
        ZOOM_AUDIO_BOTH,
        [
            ZOOM_AUDIO_TELEPHONY => new lang_string('audio_telephony', 'mod_zoom'),
            ZOOM_AUDIO_VOIP => new lang_string('audio_voip', 'mod_zoom'),
            ZOOM_AUDIO_BOTH => new lang_string('audio_both', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultmuteuponentryoption',
        new lang_string('option_mute_upon_entry', 'mod_zoom'),
        new lang_string('option_mute_upon_entry_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/recordingoption',
        new lang_string('option_auto_recording', 'mod_zoom'),
        new lang_string('option_auto_recording_help', 'mod_zoom'),
        ZOOM_AUTORECORDING_USERDEFAULT,
        [
            ZOOM_AUTORECORDING_NONE => new lang_string('autorecording_none', 'mod_zoom'),
            ZOOM_AUTORECORDING_USERDEFAULT => new lang_string('autorecording_userdefault', 'mod_zoom'),
            ZOOM_AUTORECORDING_LOCAL => new lang_string('autorecording_local', 'mod_zoom'),
            ZOOM_AUTORECORDING_CLOUD => new lang_string('autorecording_cloud', 'mod_zoom'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/allowrecordingchangeoption',
        new lang_string('option_allow_recording_change', 'mod_zoom'),
        new lang_string('option_allow_recording_change_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/defaultshowmedia',
        new lang_string('showmedia', 'mod_zoom'),
        new lang_string('showmedia_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $defaulttrackingfields = new admin_setting_configtextarea(
        'zoom/defaulttrackingfields',
        new lang_string('trackingfields', 'mod_zoom'),
        new lang_string('trackingfields_help', 'mod_zoom'),
        ''
    );
    $defaulttrackingfields->set_updatedcallback('mod_zoom_update_tracking_fields');
    $settings->add($defaulttrackingfields);

    // Adding setting for pre-assigned breakout rooms.
    $settings->add(new admin_setting_configcheckbox(
        'zoom/preassignbreakoutrooms',
        new lang_string('setting_breakoutroom', 'mod_zoom'),
        new lang_string('setting_breakoutroom_help', 'mod_zoom'),
        1,
        1,
        0
    ));

    $settings->add(new admin_setting_heading(
        'zoom/invitationregex',
        new lang_string('invitationregex', 'mod_zoom'),
        new lang_string('invitationregex_help', 'mod_zoom')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/invitationregexenabled',
        new lang_string('invitationregexenabled', 'mod_zoom'),
        new lang_string('invitationregexenabled_help', 'mod_zoom'),
        0,
        1,
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'zoom/invitationremoveinvite',
        new lang_string('invitationremoveinvite', 'mod_zoom'),
        new lang_string('invitationremoveinvite_help', 'mod_zoom'),
        0,
        1,
        0
    ));
    $settings->hide_if('zoom/invitationremoveinvite', 'zoom/invitationregexenabled', 'eq', 0);

    $settings->add(new admin_setting_configcheckbox(
        'zoom/invitationremoveicallink',
        new lang_string('invitationremoveicallink', 'mod_zoom'),
        new lang_string('invitationremoveicallink_help', 'mod_zoom'),
        0,
        1,
        0
    ));
    $settings->hide_if('zoom/invitationremoveicallink', 'zoom/invitationregexenabled', 'eq', 0);

    // Allow admin to modify regex for invitation parts if zoom api changes.
    foreach (invitation::get_default_invitation_regex() as $element => $pattern) {
        $settings->add(new admin_setting_configtext(
            'zoom/' . invitation::PREFIX . $element,
            new lang_string(invitation::PREFIX . $element, 'mod_zoom'),
            new lang_string(invitation::PREFIX . $element . '_help', 'mod_zoom'),
            $pattern
        ));
        $settings->hide_if(
            'zoom/' . invitation::PREFIX . $element,
            'zoom/invitationregexenabled',
            'eq',
            0
        );
    }

    // Extra hideif for elements which can be enabled / disabled individually.
    $settings->hide_if('zoom/invitation_invite', 'zoom/invitationremoveinvite', 'eq', 0);
    $settings->hide_if('zoom/invitation_icallink', 'zoom/invitationremoveicallink', 'eq', 0);

    // Adding options for grading methods.
    $settings->add(new admin_setting_heading(
        'zoom/gradingmethod',
        new lang_string('gradingmethod_heading', 'mod_zoom'),
        new lang_string('gradingmethod_heading_help', 'mod_zoom')
    ));

    $settings->add(new admin_setting_configselect(
        'zoom/gradingmethod',
        new lang_string('gradingmethod', 'mod_zoom'),
        new lang_string('gradingmethod_help', 'mod_zoom'),
        'entry',
        [
             // The user gets the full score when clicking to join the session through Moodle.
            'entry' => new lang_string('gradingentry', 'mod_zoom'),
             // The user is graded based on how long they attended the actual session.
            'period' => new lang_string('gradingperiod', 'mod_zoom'),
        ]
    ));
}
