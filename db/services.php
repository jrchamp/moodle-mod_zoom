<?php
// This file is part of Moodle - http://moodle.org/
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
 * Zoom external functions and service definitions.
 *
 * @package    mod_zoom
 * @category   external
 * @author     Nick Stefanski
 * @copyright  2017 Auguste Escoffier School of Culinary Arts {@link https://www.escoffier.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_zoom_get_state' => [
        'classname' => 'mod_zoom\external',
        'methodname' => 'get_state',
        'classpath' => 'mod/zoom/classes/external.php',
        'description' => 'Determine if a zoom meeting is available, meeting '
        . 'status, and the start time, duration, and other meeting options.',
        'type' => 'read',
        'capabilities' => 'mod/zoom:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_zoom_grade_item_update' => [
        'classname' => 'mod_zoom\external',
        'methodname' => 'grade_item_update',
        'classpath' => 'mod/zoom/classes/external.php',
        'description' => 'Creates or updates grade item for the given zoom instance and returns join url.',
        'type' => 'write',
        'capabilities' => 'mod/zoom:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
