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
 * The task for deleting recordings in Moodle if removed from Zoom.
 *
 * @package    mod_zoom
 * @author     Jwalit Shah <jwalitshah@catalyst-au.net>
 * @copyright  2021 Jwalit Shah <jwalitshah@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom/locallib.php');

use core\task\scheduled_task;
use moodle_exception;

/**
 * Scheduled task to delete meeting recordings from Moodle.
 */
class delete_meeting_recordings extends scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('deletemeetingrecordings', 'mod_zoom');
    }

    /**
     * Delete any recordings that have been removed from zoom.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        try {
            $service = zoom_webservice();
        } catch (moodle_exception $exception) {
            mtrace('Skipping task - ' . $exception->getMessage());
            return;
        }

        // Required scopes for deleting meeting recordings.
        $requiredscopes = [
            'classic' => [
                'recording:read:admin',
            ],
            'granular' => [
                'cloud_recording:read:list_recording_files:admin',
            ],
        ];

        // Checking for missing scopes.
        $missingscopes = $service->check_scopes($requiredscopes);
        if (!empty($missingscopes)) {
            foreach ($missingscopes as $missingscope) {
                mtrace('Missing scope: ' . $missingscope);
            }
            return;
        }

        // See if we cannot make anymore API calls.
        $retryafter = get_config('zoom', 'retry-after');
        if (!empty($retryafter) && time() < $retryafter) {
            mtrace('Out of API calls, retry after ' . userdate($retryafter, get_string('strftimedaydatetime', 'core_langconfig')));
            return;
        }

        mtrace('Checking if any meeting recordings in Moodle have been removed from Zoom...');

        // Get all recordings stored in Moodle, grouped by meetinguuid.
        $zoomrecordings = zoom_get_meeting_recordings_grouped();
        foreach ($zoomrecordings as $meetinguuid => $recordings) {
            try {
                // Now check which recordings still exist on Zoom.
                $recordinglist = $service->get_recording_url_list($meetinguuid);
                foreach ($recordinglist as $recordinginfo) {
                    $zoomrecordingid = trim($recordinginfo->recordingid);
                    if (isset($recordings[$zoomrecordingid])) {
                        mtrace('Recording id: ' . $zoomrecordingid . ' exist(s)...skipping');
                        unset($recordings[$zoomrecordingid]);
                    }
                }

                // If recordings are in Moodle but not in Zoom, we need to remove them from Moodle as well.
                foreach ($recordings as $zoomrecordingid => $recording) {
                    mtrace('Deleting recording with id: ' . $zoomrecordingid . ' because the recording is no longer in Zoom.');
                    $DB->delete_records('zoom_meeting_recordings', ['zoomrecordingid' => $zoomrecordingid]);
                }
            } catch (moodle_exception $e) {
                mtrace('Exception occurred: ' . $e->getMessage());
            }
        }
    }
}
