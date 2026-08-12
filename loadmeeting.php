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
 * Load zoom meeting.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

// Course_module ID.
$id = required_param('id', PARAM_INT);
// FormaSuisse patch: join via the Zoom web client instead of the app launcher.
$browser = optional_param('browser', 0, PARAM_BOOL);
if ($id) {
    $context = context_module::instance($id);
    $PAGE->set_context($context);

    // Call load meeting function (note: this is where additional access checks happen).
    $meetinginfo = zoom_load_meeting($id, $context, true, $browser);

    // Redirect if available, otherwise deny access.
    if ($meetinginfo['nexturl']) {
        redirect($meetinginfo['nexturl']);
    } else {
        // Get redirect URL.
        $unavailabilityurl = new moodle_url('/mod/zoom/view.php', ['id' => $id]);

        // Redirect the user back to the activity overview page.
        redirect($unavailabilityurl, $meetinginfo['error'], null, \core\output\notification::NOTIFY_ERROR);
    }
} else {
    throw new moodle_exception('zoomerr_id_missing', 'mod_zoom');
}
