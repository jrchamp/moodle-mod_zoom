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
 * Scheduled task for updating Zoom tracking fields
 *
 * @package    mod_zoom
 * @copyright  2021 Michelle Melton <meltonml@appstate.edu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom/lib.php');
require_once($CFG->dirroot . '/mod/zoom/locallib.php');

use core\task\scheduled_task;
use moodle_exception;

/**
 * Scheduled task to sychronize tracking field data.
 */
class update_tracking_fields extends scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('updatetrackingfields', 'mod_zoom');
    }

    /**
     * Updates tracking fields.
     *
     * @return boolean
     */
    public function execute() {
        try {
            $service = zoom_webservice();
        } catch (moodle_exception $exception) {
            mtrace('Skipping task - ' . $exception->getMessage());
            return;
        }

        // Required scopes for tracking fields.
        $requiredscopes = [
            'classic' => [
                'tracking_fields:read:admin',
            ],
            'granular' => [
                'tracking_field:read:list_tracking_fields:admin',
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

        // Show trace message.
        mtrace('Starting to process existing Zoom tracking fields ...');

        if (!mod_zoom_update_tracking_fields()) {
            mtrace('Error: Failed to update tracking fields.');
        }

        // Show trace message.
        mtrace('Finished processing existing Zoom tracking fields');

        return true;
    }
}
