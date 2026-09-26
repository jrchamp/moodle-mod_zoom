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
 * Define all the restore steps that will be used by the restore_zoom_activity_task
 *
 * @package   mod_zoom
 * @category  backup
 * @copyright 2015 UC Regents
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom/locallib.php');

use moodle_exception;
use restore_path_element;

/**
 * Structure step to restore one zoom activity
 */
class restore_activity_structure_step extends \restore_activity_structure_step {
    /**
     * Defines structure of path elements to be processed during the restore
     *
     * @return array of restore_path_element
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('zoom', '/activity/zoom');
        $paths[] = new restore_path_element('zoom_tracking_field', '/activity/zoom/trackingfields/trackingfield');

        if ($userinfo) {
            $paths[] = new restore_path_element('zoom_grade_occurrence', '/activity/zoom/gradeoccurrences/gradeoccurrence');
            $paths[] = new restore_path_element(
                'zoom_grade_occurrence_user',
                '/activity/zoom/gradeoccurrences/gradeoccurrence/gradeoccurrenceusers/gradeoccurrenceuser'
            );
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the given restore path element data
     *
     * @param array $data parsed element data
     */
    protected function process_zoom($data) {
        global $DB;

        $data = (object) $data;

        // Update start_time before attempting to create a new meeting.
        $data->start_time = $this->apply_date_offset($data->start_time);

        // Either create a new meeting or set meeting as expired.
        try {
            // FIXME: Do we provide course context? That won't have the right activity names etc.
            $cmid = null;
            $updateddata = zoom_webservice()->create_meeting($data, $cmid);
            $data = populate_zoom_from_response($data, $updateddata);
            $data->exists_on_zoom = ZOOM_MEETING_EXISTS;
        } catch (moodle_exception $e) {
            $data->join_url = '';
            $data->meeting_id = 0;
            $data->exists_on_zoom = ZOOM_MEETING_EXPIRED;
        }

        $data->course = $this->get_courseid();

        if (empty($data->timemodified)) {
            $data->timemodified = time();
        }

        if ($data->grade < 0) {
            // Scale found, get mapping.
            $data->grade = -($this->get_mappingid('scale', abs($data->grade)));
        }

        // Create the zoom instance.
        $newitemid = $DB->insert_record('zoom', $data);
        $this->apply_activity_instance($newitemid);

        // Create the calendar events for the new meeting.
        $data->id = $newitemid;
        zoom_calendar_item_update($data);
    }

    /**
     * Process the zoom tracking fields.
     *
     * @param array $data
     */
    protected function process_zoom_tracking_field($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->meeting_id = $this->get_new_parentid('zoom');

        $defaulttrackingfields = zoom_clean_tracking_fields();

        if (isset($defaulttrackingfields[$data->tracking_field])) {
            $newitemid = $DB->insert_record('zoom_meeting_tracking_fields', $data);
            $this->set_mapping('zoom_tracking_field', $oldid, $newitemid);
        }
    }

    /**
     * Process an occurrence of a recurring meeting.
     *
     * @param array $data
     */
    protected function process_zoom_grade_occurrence($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->zoomid = $this->get_new_parentid('zoom');
        $data->occurrencetime = $this->apply_date_offset($data->occurrencetime);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        foreach (['reportstart', 'reportend', 'timeclosed', 'flaggedforreview'] as $field) {
            if (isset($data->$field)) {
                $data->$field = $this->apply_date_offset($data->$field);
            }
        }

        $newitemid = $DB->insert_record('zoom_grade_occurrences', $data);
        $this->set_mapping('zoom_grade_occurrence', $oldid, $newitemid);
    }

    /**
     * Process the score of a user in an occurrence of a recurring meeting.
     *
     * @param array $data
     */
    protected function process_zoom_grade_occurrence_user($data) {
        global $DB;

        $data = (object) $data;

        $data->occurrenceid = $this->get_new_parentid('zoom_grade_occurrence');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            return;
        }

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('zoom_grade_occurrence_users', $data);
    }

    /**
     * Post-execution actions
     */
    protected function after_execute() {
        // Add zoom related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_zoom', 'intro', null);
    }
}
