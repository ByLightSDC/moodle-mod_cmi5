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
 * View page for mod_cmi5 - displays AU list with status.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT); // Course module ID.
$c = optional_param('c', 0, PARAM_INT);   // cmi5 instance ID.
$tab = optional_param('tab', '', PARAM_ALPHA);

if ($id) {
    $cm = get_coursemodule_from_id('cmi5', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $cmi5 = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $cmi5 = $DB->get_record('cmi5', ['id' => $c], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cmi5->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('cmi5', $cmi5->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/cmi5:view', $context);

// Trigger course module viewed event.
$event = \mod_cmi5\event\course_module_viewed::create([
    'objectid' => $cmi5->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('cmi5', $cmi5);
$event->trigger();

// Mark completion as viewed.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Load AUs and check launch capability.
$aus = $DB->get_records('cmi5_aus', ['cmi5id' => $cmi5->id, 'retired' => 0], 'sortorder ASC');
$canlaunch = has_capability('mod/cmi5:launch', $context);

$ismetrics = ($tab === 'metrics');
$PAGE->set_url('/mod/cmi5/view.php', $ismetrics ? ['id' => $cm->id, 'tab' => 'metrics'] : ['id' => $cm->id]);
if ($ismetrics) {
    // Metrics shares this page, so name the active secondary navigation tab explicitly.
    $PAGE->set_secondary_active_tab('cmi5metrics');
}
$PAGE->set_title(format_string($cmi5->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($cmi5);
// Same page width as the activity's other pages (core's settings page uses limitedwidth), so switching
// between them doesn't shift the header and navigation.
$PAGE->add_body_class('limitedwidth');

echo $OUTPUT->header();

// Line the activity content up with the page header, using the theme's own header width.
echo html_writer::start_div('mod-cmi5-page');
echo html_writer::start_div('header-maxwidth');

// Display intro if set.
if (trim(strip_tags($cmi5->intro))) {
    echo $OUTPUT->box(format_module_intro('cmi5', $cmi5, $cm->id), 'generalbox', 'intro');
}

// AUs and $canlaunch were loaded above.
$blocks = $DB->get_records('cmi5_blocks', ['cmi5id' => $cmi5->id], 'sortorder ASC');

// Load user registration and status if student.
$registration = null;
$austatuses = [];
if ($canlaunch) {
    $registration = $DB->get_record('cmi5_registrations', [
        'cmi5id' => $cmi5->id,
        'userid' => $USER->id,
    ]);
    if ($registration) {
        $statusrecords = $DB->get_records('cmi5_au_status', ['registrationid' => $registration->id]);
        foreach ($statusrecords as $status) {
            $austatuses[$status->auid] = $status;
        }
    }
}

// Button label for each status: what the learner does next with that AU.
$actionstrings = [
    'notstarted' => get_string('austart', 'cmi5'),
    'inprogress' => get_string('auresume', 'cmi5'),
    'failed' => get_string('auretry', 'cmi5'),
    'completed' => get_string('aureview', 'cmi5'),
    'passed' => get_string('aureview', 'cmi5'),
    'satisfied' => get_string('aureview', 'cmi5'),
];
$newwindow = ((int) $cmi5->launchmethod === 0);

// Build template data.
$audata = [];
$index = 0;
foreach ($aus as $au) {
    $index++;
    $status = $austatuses[$au->id] ?? null;
    $statustext = get_string('aunotstarted', 'cmi5');
    $statusclass = 'notstarted';

    if ($status) {
        if ($status->satisfied) {
            $statustext = get_string('ausatisfied', 'cmi5');
            $statusclass = 'satisfied';
        } else if ($status->passed) {
            $statustext = get_string('aupassed', 'cmi5');
            $statusclass = 'passed';
        } else if ($status->failed) {
            $statustext = get_string('aufailed', 'cmi5');
            $statusclass = 'failed';
        } else if ($status->completed) {
            $statustext = get_string('aucompleted', 'cmi5');
            $statusclass = 'completed';
        } else {
            $statustext = get_string('auinprogress', 'cmi5');
            $statusclass = 'inprogress';
        }
    }

    $launchurl = null;
    if ($canlaunch) {
        $launchurl = new moodle_url('/mod/cmi5/launch.php', [
            'id' => $cm->id,
            'auid' => $au->id,
        ]);
    }

    // Many packages repeat the AU title as its description; show a description only when it adds something.
    $description = format_text($au->description ?? '', FORMAT_MOODLE, ['context' => $context]);
    $plaindescription = core_text::strtolower(trim(html_to_text($description, 0, false)));
    $plaintitle = core_text::strtolower(trim($au->title));
    if ($plaindescription === '' || $plaindescription === $plaintitle) {
        $description = '';
    }

    $hasscore = $status && $status->score_scaled !== null;
    $score = $hasscore ? round($status->score_scaled * 100, 2) : null;

    $audata[] = [
        'id' => $au->id,
        'index' => $index,
        // Mustache escapes the output, so format_string() must not escape it too.
        'title' => format_string($au->title, true, ['context' => $context, 'escape' => false]),
        'description' => $description,
        'statustext' => $statustext,
        'statusclass' => $statusclass,
        'is' . $statusclass => true,
        'score' => $score,
        'hasscore' => $hasscore,
        'scorewidth' => $hasscore ? max(0, min(100, $score)) : 0,
        'canlaunch' => $canlaunch,
        'launchurl' => $launchurl ? $launchurl->out(false) : null,
        'actiontext' => $actionstrings[$statusclass],
        'emphasis' => $statusclass === 'inprogress',
        'newwindow' => $newwindow,
    ];
}

// Progress is only shown to learners who can launch units.
$progress = $canlaunch ? \mod_cmi5\output\learner_progress::build($audata) : null;

// Course satisfaction status.
$coursesatisfied = false;
if ($registration) {
    $coursesatisfied = (bool) $registration->coursesatisfied;
}

// Check for package version update availability (teachers/managers only).
$updateavailable = false;
$latestversionnumber = 0;
$changelogsummary = '';
$changelogentries = [];
$syncurl = '';
if (!empty($cmi5->packageid) && has_capability('mod/cmi5:managecontent', $context)) {
    $currentversionid = $cmi5->packageversionid ?? 0;
    if ($currentversionid) {
        $updateinfo = \mod_cmi5\content_library::check_update_available(
            (int) $cmi5->packageid, (int) $currentversionid
        );
        if ($updateinfo->available) {
            $updateavailable = true;
            $latestversionnumber = $updateinfo->latestversionnumber;
            $changecount = count($updateinfo->changelog);
            $changelogsummary = $changecount > 0 ? $changecount . ' ' .
                get_string('library:changes', 'cmi5') : '';
            // Into the same review flow the upgrades list uses, so a single activity gets
            // the same validation, the same learner-data explanation and the same result
            // reporting as a batch.
            $syncurl = (new moodle_url('/mod/cmi5/upgrades.php', [
                'courseid' => $cm->course,
            ]))->out(false);
            // Build changelog entries for template.
            foreach ($updateinfo->changelog as $entry) {
                $desc = is_array($entry)
                    ? \mod_cmi5\content_library::describe_change($entry)
                    : (string) $entry;
                if ($desc !== '') {
                    $changelogentries[] = ['description' => $desc];
                }
            }
        }
    }
}

$isteacher = has_capability('mod/cmi5:viewreports', $context);
$cancleardata = has_capability('mod/cmi5:managecontent', $context);

$templatedata = [
    'aus' => $audata,
    'hasaus' => !empty($audata),
    'canlaunch' => $canlaunch,
    'coursesatisfied' => $coursesatisfied,
    'hasprogress' => $progress !== null,
    'progress' => $progress,
    'newwindow' => $newwindow,
    'aucount' => count($audata),
    'cmid' => $cm->id,
    'cmi5id' => $cmi5->id,
    'launchmethod' => $cmi5->launchmethod,
    'updateavailable' => $updateavailable,
    'latestversionnumber' => $latestversionnumber,
    'changelogsummary' => $changelogsummary,
    'changecount' => count($changelogentries),
    'haschangelog' => !empty($changelogentries),
    'changelogentries' => $changelogentries,
    'syncurl' => $syncurl,
    'isteacher' => $isteacher,
    'cancleardata' => $cancleardata,
    'ismetrics' => $ismetrics,
];

echo $OUTPUT->render_from_template('mod_cmi5/view', $templatedata);

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
