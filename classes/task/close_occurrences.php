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
 * The task that records and closes the occurrences of cumulatively graded meetings.
 *
 * @package    mod_zoom
 * @copyright  2026 Moodle Zoom plugin contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom\task;

use core\task\scheduled_task;

/**
 * Scheduled task to record the upon entry occurrences whose window has opened, even if nobody joins,
 * and to close the ended ones, giving 0 to the users who did not attend.
 */
class close_occurrences extends scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('closeoccurrences', 'mod_zoom');
    }

    /**
     * Record and close the occurrences.
     *
     * @return void
     */
    public function execute() {
        $result = \mod_zoom\grades\occurrences::seed_and_close();
        mtrace('Recorded ' . $result['seeded'] . ' and closed ' . $result['closed'] . ' occurrence(s) of recurring meetings.');
    }
}
