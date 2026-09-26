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
 * Review the occurrences of a cumulatively graded meeting that were flagged when their session moved.
 *
 * @package    mod_zoom
 * @copyright  2026 Moodle Zoom plugin contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

[$course, $cm, $zoom] = zoom_get_instance_setup();

$context = context_module::instance($cm->id);
require_capability('mod/zoom:addinstance', $context);
require_capability('moodle/grade:manage', context_course::instance($course->id));

$url = new moodle_url('/mod/zoom/occurrencereview.php', ['id' => $cm->id]);
$PAGE->set_url($url);

$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '' && confirm_sesskey()) {
    $occurrenceid = required_param('occurrenceid', PARAM_INT);
    $targetid = optional_param('targetid', 0, PARAM_INT);
    \mod_zoom\grades\occurrences::resolve_flagged($zoom, $occurrenceid, $action, $targetid ?: null);
    redirect($url, get_string('occurrencereviewdone', 'mod_zoom'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$strtitle = get_string('occurrencereview', 'mod_zoom');
$PAGE->navbar->add($strtitle);
$PAGE->set_title(format_string("$course->shortname: $zoom->name", true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading($strtitle);

$flagged = \mod_zoom\grades\occurrences::get_flagged($zoom->id);
if (empty($flagged)) {
    echo $OUTPUT->notification(get_string('occurrencereview_none', 'mod_zoom'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->continue_button(new moodle_url('/mod/zoom/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('occurrencereview_desc', 'mod_zoom'));

// The occurrences a flagged one can be merged into.
$targets = [];
$counted = $DB->get_records_select(
    'zoom_grade_occurrences',
    'zoomid = ? AND flaggedforreview IS NULL',
    [$zoom->id],
    'occurrencetime ASC'
);
foreach ($counted as $occurrence) {
    $targets[$occurrence->id] = userdate($occurrence->occurrencetime);
}

$table = new html_table();
$table->head = [
    get_string('occurrencereviewtime', 'mod_zoom'),
    get_string('occurrencereviewflagged', 'mod_zoom'),
    get_string('occurrencereviewscores', 'mod_zoom'),
    get_string('actions'),
];

foreach ($flagged as $occurrence) {
    $scores = $DB->get_records('zoom_grade_occurrence_users', ['occurrenceid' => $occurrence->id], 'userid ASC');
    $lines = [];
    foreach ($scores as $score) {
        $user = core_user::get_user($score->userid);
        $name = $user ? fullname($user) : $score->userid;
        $lines[] = s($name) . ': ' . format_float($score->score * $zoom->grade, 2);
    }

    $hidden = ['id' => $cm->id, 'occurrenceid' => $occurrence->id, 'sesskey' => sesskey()];

    $actions = $OUTPUT->single_button(
        new moodle_url($url, $hidden + ['action' => 'keep']),
        get_string('occurrencereviewkeep', 'mod_zoom'),
        'post'
    );
    if (!empty($targets)) {
        $merge = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out_omit_querystring()]);
        foreach ($hidden + ['action' => 'merge'] as $name => $value) {
            $merge .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }

        $merge .= html_writer::select($targets, 'targetid', '', false);
        $merge .= html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-secondary ms-1',
            'value' => get_string('occurrencereviewmerge', 'mod_zoom'),
        ]);
        $merge .= html_writer::end_tag('form');
        $actions .= $merge;
    }

    $actions .= $OUTPUT->single_button(
        new moodle_url($url, $hidden + ['action' => 'discard']),
        get_string('occurrencereviewdiscard', 'mod_zoom'),
        'post',
        ['title' => get_string('occurrencereviewdiscard_help', 'mod_zoom')]
    );

    $table->data[] = [
        userdate($occurrence->occurrencetime),
        userdate($occurrence->flaggedforreview),
        implode(html_writer::empty_tag('br'), $lines),
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
