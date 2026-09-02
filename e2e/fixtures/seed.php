<?php
// This file is part of the mod_cmi5 e2e suite.
//
// A Moodle CLI seeder for Playwright fixtures. It is piped into the webserver
// container's PHP:
//
//   docker exec -i -e E2E_SEED_SPEC='<json>' moodle-docker-webserver-1 \
//       php /dev/stdin < e2e/fixtures/seed.php
//
// The spec (JSON in E2E_SEED_SPEC) describes a course, its cmi5 activities and
// AUs, the users to enrol, and the per-user progress to create. It prints a
// JSON summary of everything it made so the test can target exact ids.
//
// SAFETY: every course this creates is stamped with idnumber "e2e-<runId>", and
// --teardown only ever deletes courses whose idnumber starts with "e2e-".

define('CLI_SCRIPT', true);

// Keep stdout as pure JSON: send every PHP notice/warning to stderr instead.
ini_set('display_errors', 'stderr');

// The docroot inside the moodle-docker webserver container.
require('/var/www/html/config.php');

global $CFG, $DB;

// This script's stdout must be clean JSON, so silence Moodle's debugging
// output and never let it try to send enrolment / welcome email.
$CFG->debug = 0;
$CFG->debugdisplay = false;
$CFG->noemailever = true;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/mod/cmi5/lib.php');

/** Read and decode the spec. */
function e2e_spec(): stdClass {
    $raw = getenv('E2E_SEED_SPEC');
    if ($raw === false || trim($raw) === '') {
        fwrite(STDERR, "E2E_SEED_SPEC is empty\n");
        exit(2);
    }
    $spec = json_decode($raw);
    if (!is_object($spec)) {
        fwrite(STDERR, "E2E_SEED_SPEC is not a JSON object\n");
        exit(2);
    }
    return $spec;
}

/** Delete every e2e course + its now-empty category. */
function e2e_teardown(string $runid): void {
    global $DB;
    // Delete straight through, without the recycle-bin backup detour.
    set_config('coursebinenable', 0, 'tool_recyclebin');
    set_config('categorybinenable', 0, 'tool_recyclebin');
    $prefix = 'e2e-';
    $like = $DB->sql_like('idnumber', ':idn');
    $courses = $DB->get_records_select(
        'course',
        $runid !== '' ? 'idnumber = :exact' : $like,
        $runid !== '' ? ['exact' => "e2e-{$runid}"] : ['idn' => $DB->sql_like_escape($prefix) . '%']
    );
    $categoryids = [];
    foreach ($courses as $course) {
        if (strpos((string)$course->idnumber, $prefix) !== 0) {
            continue; // never touch a non-e2e course
        }
        $categoryids[$course->category] = true;
        delete_course($course, false); // cascades module + cmi5 data deletes
    }
    foreach (array_keys($categoryids) as $catid) {
        $cat = \core_course_category::get($catid, IGNORE_MISSING, true);
        if ($cat && $cat->get_courses_count() === 0 && strpos($cat->idnumber ?? '', $prefix) === 0) {
            $cat->delete_full(false);
        }
    }
    echo json_encode(['ok' => true, 'action' => 'teardown', 'deleted' => count($courses)]) . "\n";
}

/** Get an existing user by username, or create a manual-auth one. */
function e2e_user(string $username): stdClass {
    global $DB, $CFG;
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if ($user) {
        return $user;
    }
    $new = (object)[
        'username' => $username,
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'firstname' => ucfirst(str_replace(['e2e_', '_'], ['', ' '], $username)),
        'lastname' => 'E2E',
        'email' => $username . '@e2e.invalid',
        'password' => 'Passw0rd!e2e',
    ];
    $new->id = user_create_user($new, true, false);
    return $DB->get_record('user', ['id' => $new->id], '*', MUST_EXIST);
}

/** Create a cmi5 activity (record + course module + gradebook item) and return {cmi5, cm}. */
function e2e_activity(stdClass $course, string $name): array {
    global $DB;

    $now = time();
    $cmi5 = (object)[
        'course' => $course->id,
        'name' => $name,
        'intro' => '',
        'introformat' => FORMAT_HTML,
        'timecreated' => $now,
        'timemodified' => $now,
        // grademethod / maxgrade / launchmethod / sessiontimeout / lrsmode take their column defaults
    ];
    $cmi5->id = $DB->insert_record('cmi5', $cmi5);

    $moduleid = $DB->get_field('modules', 'id', ['name' => 'cmi5'], MUST_EXIST);
    $cm = (object)[
        'course' => $course->id,
        'module' => $moduleid,
        'instance' => $cmi5->id,
        'section' => 0,
        'added' => $now,
        'visible' => 1,
        'visibleold' => 1,
    ];
    $cm->id = add_course_module($cm);
    course_add_cm_to_section($course->id, $cm->id, 0);
    \context_module::instance($cm->id); // materialise the module context
    rebuild_course_cache($course->id, true);

    $cmi5record = $DB->get_record('cmi5', ['id' => $cmi5->id], '*', MUST_EXIST);
    cmi5_grade_item_update($cmi5record); // create the gradebook item

    return ['cmi5' => $cmi5record, 'cm' => $cm];
}

/** Insert one cmi5_aus row and return it. */
function e2e_au(int $cmi5id, string $title, int $sortorder): stdClass {
    global $DB;
    $au = (object)[
        'cmi5id' => $cmi5id,
        'auid' => 'https://e2e.invalid/au/' . $cmi5id . '/' . $sortorder,
        'title' => $title,
        'url' => 'index.html',
        'sortorder' => $sortorder,
    ];
    $au->id = $DB->insert_record('cmi5_aus', $au);
    return $DB->get_record('cmi5_aus', ['id' => $au->id], '*', MUST_EXIST);
}

/**
 * Create realistic progress for one learner on one AU: a registration, a
 * session, a couple of statements, a state document, an au_status row with a
 * score, then push the grade. Returns a summary.
 */
function e2e_progress(stdClass $cmi5, stdClass $au, stdClass $user, stdClass $p): array {
    global $DB;
    $now = time();

    $reg = \mod_cmi5\registration::get_or_create($cmi5->id, $user->id);

    $session = (object)[
        'registrationid' => $reg->id,
        'auid' => $au->id,
        'sessionid' => \core\uuid::generate(),
        'launchmode' => 'Normal',
        'initialized' => 1,
        'terminated' => !empty($p->completed) ? 1 : 0,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $session->id = $DB->insert_record('cmi5_sessions', $session);

    foreach (['http://adlnet.gov/expapi/verbs/initialized', 'https://w3id.org/xapi/adl/verbs/satisfied'] as $verb) {
        $DB->insert_record('cmi5_statements', (object)[
            'sessionid' => $session->id,
            'statementid' => \core\uuid::generate(),
            'verb' => $verb,
            'statement_json' => json_encode(['verb' => ['id' => $verb]]),
            'is_cmi5_defined' => 1,
            'forwarded' => 0,
            'voided' => 0,
            'registration' => $reg->registrationid,
            'timecreated' => $now,
        ]);
    }

    $DB->insert_record('cmi5_state_documents', (object)[
        'registrationid' => $reg->id,
        'activityid' => $au->auid,
        'activityidhash' => sha1($au->auid),
        'stateid' => 'LMS.LaunchData',
        'document' => '{}',
        'timecreated' => $now,
        'timemodified' => $now,
    ]);

    $status = (object)[
        'registrationid' => $reg->id,
        'auid' => $au->id,
        'completed' => !empty($p->completed) ? 1 : 0,
        'passed' => !empty($p->passed) ? 1 : 0,
        'failed' => (isset($p->passed) && $p->passed === false && !empty($p->completed)) ? 1 : 0,
        'satisfied' => 0,
        'waived' => 0,
        'score_scaled' => isset($p->score) ? (float)$p->score : null,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $status->id = $DB->insert_record('cmi5_au_status', $status);

    cmi5_update_grades($cmi5, $user->id, true);

    return [
        'user' => $user->username,
        'activity' => $cmi5->name,
        'au' => $au->title,
        'registrationId' => (int)$reg->id,
        'registrationUuid' => $reg->registrationid,
        'sessionId' => (int)$session->id,
        'auStatusId' => (int)$status->id,
    ];
}

// --- main -----------------------------------------------------------------

$spec = e2e_spec();

if (!empty($spec->teardown)) {
    e2e_teardown(is_string($spec->teardown) ? $spec->teardown : '');
    exit(0);
}

$runid = $spec->runId ?? (string)time();
$category = \core_course_category::create([
    'name' => 'E2E ' . $runid,
    'idnumber' => 'e2e-' . $runid,
]);
$course = create_course((object)[
    'category' => $category->id,
    'fullname' => 'E2E ' . $runid,
    'shortname' => 'e2e-' . $runid,
    'idnumber' => 'e2e-' . $runid,
    'summary' => '',
    'summaryformat' => FORMAT_HTML,
    'format' => 'topics',
    'numsections' => 3,
]);

// Activities + AUs, keyed by name for the progress lookups below.
$activities = [];
$out_activities = [];
foreach (($spec->activities ?? []) as $aspec) {
    $made = e2e_activity($course, $aspec->name);
    $aus = [];
    $out_aus = [];
    $i = 0;
    foreach (($aspec->aus ?? [(object)['title' => 'AU 1']]) as $au) {
        $row = e2e_au($made['cmi5']->id, $au->title, $i++);
        $aus[$au->title] = $row;
        $out_aus[] = ['id' => (int)$row->id, 'title' => $row->title, 'auid' => $row->auid];
    }
    $activities[$aspec->name] = ['cmi5' => $made['cmi5'], 'cm' => $made['cm'], 'aus' => $aus];
    $out_activities[] = [
        'name' => $aspec->name,
        'cmi5Id' => (int)$made['cmi5']->id,
        'cmId' => (int)$made['cm']->id,
        'contextId' => (int)\context_module::instance($made['cm']->id)->id,
        'aus' => $out_aus,
    ];
}

// Users + enrolment.
$manual = enrol_get_plugin('manual');
$enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
if (!$enrol) {
    $manual->add_default_instance($course);
    $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
}
// Don't trigger the "course welcome" message on enrol.
$DB->set_field('enrol', 'customint1', 0, ['id' => $enrol->id]);
$users = [];
$out_users = [];
foreach (($spec->users ?? []) as $uspec) {
    $user = e2e_user($uspec->username);
    $roleid = $DB->get_field('role', 'id', ['shortname' => $uspec->role], MUST_EXIST);
    $manual->enrol_user($enrol, $user->id, $roleid);
    $users[$uspec->username] = $user;
    $out_users[] = ['username' => $user->username, 'userId' => (int)$user->id, 'role' => $uspec->role];
}

// Progress.
$out_progress = [];
foreach (($spec->progress ?? []) as $pspec) {
    $act = $activities[$pspec->activity];
    $au = $act['aus'][$pspec->au];
    $out_progress[] = e2e_progress($act['cmi5'], $au, $users[$pspec->user], $pspec);
}

echo json_encode([
    'ok' => true,
    'runId' => $runid,
    'categoryId' => (int)$category->id,
    'courseId' => (int)$course->id,
    'activities' => $out_activities,
    'users' => $out_users,
    'progress' => $out_progress,
], JSON_PRETTY_PRINT) . "\n";
