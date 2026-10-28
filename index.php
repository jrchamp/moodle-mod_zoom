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
 * List all zoom meetings.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/moodlelib.php');

$id = required_param('id', PARAM_INT); // Course.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$context = context_course::instance($course->id);
require_capability('mod/zoom:view', $context);
$iszoommanager = has_capability('mod/zoom:addinstance', $context);

$params = [
    'context' => $context,
];
$event = \mod_zoom\event\course_module_instance_list_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->trigger();

$strname = get_string('modulenameplural', 'mod_zoom');
$strnew = get_string('newmeetings', 'mod_zoom');
$strold = get_string('oldmeetings', 'mod_zoom');

$strtitle = get_string('title', 'mod_zoom');
$strwebinar = get_string('webinar', 'mod_zoom');
$strtime = get_string('meeting_time', 'mod_zoom');
$strduration = get_string('duration', 'mod_zoom');
$stractions = get_string('actions', 'mod_zoom');
$strsessions = get_string('sessions', 'mod_zoom');

$strmeetingstarted = get_string('meeting_started', 'mod_zoom');
$strjoin = get_string('join', 'mod_zoom');

$PAGE->set_url('/mod/zoom/index.php', ['id' => $id]);
$PAGE->navbar->add($strname);
$PAGE->set_title(format_string("$course->shortname: $strname", true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

if ($CFG->branch < '400') {
    echo $OUTPUT->heading($strname);
}

if (! $zooms = get_all_instances_in_course('zoom', $course)) {
    notice(get_string('nozooms', 'mod_zoom'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);

$zoomuserid = zoom_get_user_id(false);

$newtable = new html_table();
$newtable->attributes['class'] = 'generaltable mod_index';
$newhead = [$strtitle, $strtime, $strduration, $stractions];
$newalign = ['left', 'left', 'left', 'left'];

$oldtable = new html_table();
$oldhead = [$strtitle, $strtime];
$oldalign = ['left', 'left'];

// Show section column if there are sections.
if ($usesections) {
    $strsectionname = get_string('sectionname', 'format_' . $course->format);
    array_unshift($newhead, $strsectionname);
    array_unshift($newalign, 'center');
    array_unshift($oldhead, $strsectionname);
    array_unshift($oldalign, 'center');
}

// Show sessions column only if user can edit Zoom meetings.
if ($iszoommanager) {
    $newhead[] = $strsessions;
    $newalign[] = 'left';
    $oldhead[] = $strsessions;
    $oldalign[] = 'left';
}

$newtable->head = $newhead;
$newtable->align = $newalign;
$oldtable->head = $oldhead;
$oldtable->align = $oldalign;

$now = time();
$modinfo = get_fast_modinfo($course);
$cms = $modinfo->instances['zoom'];
foreach ($zooms as $z) {
    $row = [];
    [$inprogress, $available, $finished] = zoom_get_state($z);

    $cm = $cms[$z->id];
    if ($usesections && isset($cm->sectionnum)) {
        $row[0] = get_section_name($course, $cm->sectionnum);
    }

    $url = new moodle_url('view.php', ['id' => $cm->id]);
    $row[1] = html_writer::link($url, $cm->get_formatted_name());
    if ($z->webinar) {
        $row[1] .= " ($strwebinar)";
    }

    // Get start time column information.
    if ($z->recurring && $z->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) {
        $displaytime = get_string('recurringmeeting', 'mod_zoom');
        $displaytime .= html_writer::empty_tag('br');
        $displaytime .= get_string('recurringmeetingexplanation', 'mod_zoom');
    } else if ($z->recurring && $z->recurrence_type != ZOOM_RECURRINGTYPE_NOTIME) {
        $displaytime = get_string('recurringmeeting', 'mod_zoom');
        $displaytime .= html_writer::empty_tag('br');
        if (($nextoccurrence = zoom_get_next_occurrence($z)) > 0) {
            $displaytime .= get_string('nextoccurrence', 'mod_zoom') . ': ' . userdate($nextoccurrence);
        } else {
            $displaytime .= get_string('nooccurrenceleft', 'mod_zoom');
        }
    } else {
        $displaytime = userdate($z->start_time);
    }

    $report = new moodle_url('report.php', ['id' => $cm->id]);
    $sessions = html_writer::link($report, $strsessions);

    if ($finished) {
        $row[2] = $displaytime;
        if ($iszoommanager) {
            $row[3] = $sessions;
        }

        $oldtable->data[] = $row;
    } else {
        if ($inprogress) {
            $label = html_writer::tag('span', $strmeetingstarted, ['class' => 'badge bg-dark badge-dark bg-text-dark']);
            $row[2] = html_writer::tag('div', $label);
        } else {
            $row[2] = $displaytime;
        }

        $row[3] = ($z->recurring && $z->recurrence_type == ZOOM_RECURRINGTYPE_NOTIME) ? '--' : format_time($z->duration);

        if ($available) {
            $buttonhtml = html_writer::tag('button', $strjoin, ['type' => 'submit', 'class' => 'btn btn-primary']);
            $aurl = new moodle_url('/mod/zoom/loadmeeting.php', ['id' => $cm->id]);
            $buttonhtml .= html_writer::input_hidden_params($aurl);
            $row[4] = html_writer::tag('form', $buttonhtml, ['action' => $aurl->out_omit_querystring(), 'target' => '_blank']);
        } else {
            $row[4] = '--';
        }

        if ($iszoommanager) {
            $row[] = $sessions;
        }

        $newtable->data[] = $row;
    }
}

echo $OUTPUT->heading($strnew, 4);
echo html_writer::table($newtable);
echo $OUTPUT->heading($strold, 4, null, 'mod-zoom-old-meetings-header');
// Show refresh meeting sessions link only if user can run the 'refresh session reports' console command.
if (has_capability('mod/zoom:refreshsessions', $context)) {
    $linkarguments = [
        'courseid' => $id,
        'start' => date('Y-m-d', strtotime('-3 days')),
        'end' => date('Y-m-d'),
        'sesskey' => sesskey(),
    ];
    $url = new moodle_url($CFG->wwwroot . '/mod/zoom/console/get_meeting_report.php', $linkarguments);
    echo html_writer::link($url, get_string('refreshreports', 'mod_zoom'), ['target' => '_blank', 'class' => 'pl-4']);
}

echo html_writer::table($oldtable);

echo $OUTPUT->footer();
