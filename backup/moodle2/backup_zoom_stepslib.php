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
 * Defines backup_activity_structure_step class.
 *
 * @package   mod_zoom
 * @category  backup
 * @copyright 2015 UC Regents
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_zoom;

use backup;
use backup_nested_element;

/**
 * Define the complete zoom structure for backup, with file and id annotations.
 */
class backup_activity_structure_step extends \backup_activity_structure_step {
    /**
     * Defines the backup structure of the module.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Define the root element describing the zoom instance.
        $zoom = new backup_nested_element('zoom', ['id'], [
            'intro', 'introformat', 'grade', 'grading_method', 'meeting_id', 'join_url', 'created_at', 'host_id', 'name',
            'start_time', 'timemodified', 'recurring', 'recurrence_type', 'repeat_interval', 'weekly_days', 'monthly_day',
            'monthly_week', 'monthly_week_day', 'monthly_repeat_option', 'end_times', 'end_date_time', 'end_date_option',
            'webinar', 'duration', 'timezone', 'password', 'option_jbh', 'option_start_type', 'option_host_video',
            'option_participants_video', 'option_audio', 'option_mute_upon_entry', 'option_waiting_room',
            'option_authenticated_users', 'option_encryption_type', 'exists_on_zoom', 'alternative_hosts',
            'recordings_visible_default', 'show_schedule', 'show_security', 'show_media', 'option_auto_recording',
            'registration', 'cumulativegradingstart',
        ]);

        $trackingfields = new backup_nested_element('trackingfields');

        $trackingfield = new backup_nested_element('trackingfield', ['id'], ['meeting_id', 'tracking_field', 'value']);

        $occurrences = new backup_nested_element('gradeoccurrences');

        $occurrence = new backup_nested_element('gradeoccurrence', ['id'], [
            'occurrencetime', 'reportstart', 'reportend', 'timecreated', 'timeclosed', 'flaggedforreview',
        ]);

        $occurrenceusers = new backup_nested_element('gradeoccurrenceusers');

        $occurrenceuser = new backup_nested_element('gradeoccurrenceuser', ['id'], [
            'userid', 'score', 'timecreated', 'timemodified',
        ]);

        // If we had more elements, we would build the tree here.
        $zoom->add_child($trackingfields);
        $trackingfields->add_child($trackingfield);
        $zoom->add_child($occurrences);
        $occurrences->add_child($occurrence);
        $occurrence->add_child($occurrenceusers);
        $occurrenceusers->add_child($occurrenceuser);

        // Define data sources.
        $zoom->set_source_table('zoom', ['id' => backup::VAR_ACTIVITYID]);
        $trackingfield->set_source_table('zoom_meeting_tracking_fields', ['meeting_id' => backup::VAR_ACTIVITYID]);

        // The occurrences of a recurring meeting are the source of its grades, so they go with them.
        if ($userinfo) {
            $occurrence->set_source_table('zoom_grade_occurrences', ['zoomid' => backup::VAR_PARENTID], 'id ASC');
            $occurrenceuser->set_source_table('zoom_grade_occurrence_users', ['occurrenceid' => backup::VAR_PARENTID], 'id ASC');
        }

        // Define id annotations.
        $occurrenceuser->annotate_ids('user', 'userid');

        // Define file annotations.
        // Intro does not need itemid.
        $zoom->annotate_files('mod_zoom', 'intro', null);

        // Return the root element (zoom), wrapped into standard activity structure.
        return $this->prepare_activity_structure($zoom);
    }
}
