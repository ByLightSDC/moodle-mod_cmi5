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
 * Language strings for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 David Ropte
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname'] = 'cmi5 Activity';
$string['modulename'] = 'cmi5 Activity';
$string['modulenameplural'] = 'cmi5 Activities';
$string['pluginadministration'] = 'cmi5 Activity administration';
$string['cmi5name'] = 'Activity name';
$string['cmi5fieldset'] = 'cmi5 Package settings';
$string['modulename_help'] = 'The cmi5 activity module allows teachers to upload cmi5-compliant e-learning packages. Students launch Assignable Units (AUs) from the package, and their progress is tracked via xAPI statements.';

// Mod form.
$string['packagefile'] = 'Package file (ZIP)';
$string['packagefile_help'] = 'Upload a cmi5 ZIP package containing a cmi5.xml manifest and content files.';
$string['grademethod'] = 'Grade method';
$string['grademethod_help'] = 'How the grade is calculated when multiple AUs have scores.';
$string['gradehighest'] = 'Highest score';
$string['gradeaverage'] = 'Average score';
$string['gradefirst'] = 'First score';
$string['gradelast'] = 'Last score';
$string['maxgrade'] = 'Maximum grade';
$string['launchmethod'] = 'Launch method';
$string['launchmethod_help'] = 'How the AU content opens for the student.';
$string['launchnewwindow'] = 'New window';
$string['launchiframe'] = 'Embedded (iframe)';
$string['sessiontimeout'] = 'Session timeout';
$string['sessiontimeout_help'] = 'Seconds of inactivity before a session is considered abandoned.';
$string['launchparameters'] = 'Launch parameters';
$string['launchparameters_help'] = 'Custom configuration string passed to the AU via the LMS.LaunchData state document. This overrides any launch parameters defined in the cmi5 package. The format is defined by the AU — typically JSON or query string.';

// LRS settings.
$string['lrssettings'] = 'LRS settings';
$string['lrsendpoint'] = 'LRS endpoint URL';
$string['lrsendpoint_help'] = 'The xAPI endpoint URL for an external LRS. Leave blank to only store statements locally.';
$string['lrskey'] = 'LRS auth key';
$string['lrssecret'] = 'LRS auth secret';
$string['lrsmode'] = 'Statement storage mode';
$string['lrsmode_help'] = 'Where xAPI statements are stored.';
$string['lrsmode_local'] = 'Local only';
$string['lrsmode_forward'] = 'Local + forward to LRS';
$string['lrsmode_lrsonly'] = 'LRS only';

// Admin settings.
$string['defaultlrsendpoint'] = 'Default LRS endpoint';
$string['defaultlrsendpoint_desc'] = 'Default LRS endpoint for new activities. Can be overridden per activity.';
$string['defaultlrskey'] = 'Default LRS auth key';
$string['defaultlrskey_desc'] = 'Default LRS basic auth key.';
$string['defaultlrssecret'] = 'Default LRS auth secret';
$string['defaultlrssecret_desc'] = 'Default LRS basic auth secret.';
$string['defaultlrsmode'] = 'Default storage mode';
$string['defaultlrsmode_desc'] = 'Default statement storage mode for new activities.';
$string['defaultsessiontimeout'] = 'Default session timeout';
$string['defaultsessiontimeout_desc'] = 'Default session timeout in seconds for new activities.';
$string['defaultlaunchmethod'] = 'Default launch method';
$string['defaultlaunchmethod_desc'] = 'Default method for opening AU content.';
$string['tokenexpiry'] = 'Token expiry duration';
$string['tokenexpiry_desc'] = 'How long fetch tokens remain valid, in seconds.';

// View page.
$string['noaus'] = 'No assignable units found in this package.';
$string['aulist'] = 'Assignable Units';
$string['launchau'] = 'Launch';
$string['austatus'] = 'Status';
$string['auscore'] = 'Score';
$string['aucompleted'] = 'Completed';
$string['aupassed'] = 'Passed';
$string['aufailed'] = 'Failed';
$string['ausatisfied'] = 'Satisfied';
$string['auwaived'] = 'Waived';
$string['aunotstarted'] = 'Not started';
$string['auinprogress'] = 'In progress';
$string['coursesatisfied'] = 'Course satisfied';
$string['coursenotsatisfied'] = 'Course not yet satisfied';

// Launch.
$string['launcherror'] = 'Error launching AU';
$string['backtocourse'] = 'Back to course';
$string['sessioncreated'] = 'Session created';
$string['invalidau'] = 'Invalid Assignable Unit';
$string['nopermission'] = 'You do not have permission to launch this activity.';

// Errors.
$string['invalidpackage'] = 'Invalid cmi5 package: {$a}';
$string['missingcmi5xml'] = 'Package does not contain a cmi5.xml manifest.';
$string['invalidcmi5xml'] = 'Invalid cmi5.xml: {$a}';
$string['packageparseerror'] = 'Error parsing cmi5 package: {$a}';

// Events.
$string['eventaulaunched'] = 'AU launched';
$string['eventauinitialized'] = 'AU initialized';
$string['eventauterminated'] = 'AU terminated';
$string['eventaucompleted'] = 'AU completed';
$string['eventaupassed'] = 'AU passed';
$string['eventaufailed'] = 'AU failed';
$string['eventsessionabandoned'] = 'Session abandoned';
$string['eventcoursesatisfied'] = 'Course satisfied';
$string['eventcoursemoduleviewed'] = 'Course module viewed';

// Errors (additional).
$string['invalidsession'] = 'Invalid or expired session.';
$string['packagenotfound'] = 'Package file not found.';
$string['cmi5xmlnotfound'] = 'cmi5.xml not found in the package.';
$string['cmi5xmlreaderror'] = 'Could not read cmi5.xml from the package.';
$string['missingcourseelement'] = 'Missing course element in cmi5.xml.';
$string['invalidjson'] = 'Invalid JSON: {$a}';
$string['sessionterminated'] = 'Session terminated: {$a}';
$string['notinitialised'] = 'Session not initialized: {$a}';
$string['alreadyinitialised'] = 'Session already initialized: {$a}';

// Privacy.
$string['privacy:metadata:cmi5_registrations'] = 'User registrations for cmi5 activities.';
$string['privacy:metadata:cmi5_registrations:userid'] = 'The ID of the user registered for the activity.';
$string['privacy:metadata:cmi5_au_status'] = 'User progress through assignable units.';
$string['privacy:metadata:cmi5_sessions'] = 'Launch session records.';
$string['privacy:metadata:cmi5_statements'] = 'xAPI statements generated during learning.';
$string['privacy:metadata:externallrs'] = 'xAPI statements may be forwarded to an external LRS.';
$string['privacy:metadata:externallrs:actor'] = 'The learner actor identifier (account-based, no PII).';
$string['privacy:metadata:externallrs:statements'] = 'xAPI statements describing learning activities.';

// Backup.
$string['backupincludestatements'] = 'Include xAPI statements';

// Tasks.
$string['taskabandonsessions'] = 'Abandon stale cmi5 sessions';
$string['taskcleanuptokens'] = 'Clean up expired cmi5 tokens';

// Completion.
$string['completionau'] = 'Student must satisfy all AUs';
$string['completionaugroup'] = 'Require AU satisfaction';

// Content library.
$string['contentlibrary'] = 'Content Library';
$string['contentlibrary_desc'] = 'Manage site-wide cmi5 content packages and external AUs.';
$string['library:uploadpackage'] = 'Upload package';
$string['library:uploadpackage_help'] = 'Upload a cmi5 ZIP package to the content library for reuse across activities.';
$string['library:registerexternalau'] = 'Register external AU';
$string['library:registerexternalau_help'] = 'Register an externally-hosted AU by providing its URL and metadata.';
$string['library:title'] = 'Title';
$string['library:description'] = 'Description';
$string['library:source'] = 'Source';
$string['library:source_zip'] = 'ZIP upload';
$string['library:source_external'] = 'External URL';
$string['library:source_api'] = 'API registered';
$string['library:status'] = 'Status';
$string['library:status_active'] = 'Active';
$string['library:status_disabled'] = 'Disabled';
$string['library:usagecount'] = 'Used by';
$string['library:usagecount_activities'] = '{$a} activities';
$string['library:aucount'] = 'AUs';
$string['library:actions'] = 'Actions';
$string['library:delete'] = 'Delete';
$string['library:deleteconfirm'] = 'Are you sure you want to delete the package "{$a}"?';
$string['library:packageinuse'] = 'This package is used by {$a} activities and cannot be deleted. Unlink the activities first or use force delete.';
$string['library:nopackages'] = 'No packages in the content library yet.';
$string['library:auid'] = 'AU IRI';
$string['library:auurl'] = 'AU URL';
$string['library:moveoncriteria'] = 'moveOn criteria';
$string['library:masteryscore'] = 'Mastery score';
$string['library:launchparameters'] = 'Launch parameters';
$string['library:isexternal'] = 'External';
$string['library:packageuploaded'] = 'Package uploaded successfully.';
$string['library:auregistered'] = 'External AU registered successfully.';
$string['library:packagedeleted'] = 'Package deleted successfully.';
$string['library:viewdetails'] = 'View details';
$string['library:backtolist'] = 'Back to library';
$string['library:backtolibrary'] = 'Back to library';
$string['library:createdby'] = 'Created by';
$string['library:timecreated'] = 'Created';
$string['library:courseidiri'] = 'Course ID (IRI)';
$string['library:assignableunits'] = 'Assignable Units';
$string['library:external'] = 'External';
$string['library:blocks'] = 'Blocks';
$string['library:blockid'] = 'Block ID';
$string['library:launchprofile'] = 'Launch parameter profile';
$string['library:settingsdesc'] = '<a href="{$a}">Manage the Content Library</a> — upload cmi5 packages and register external AUs for reuse across activities.';

// Activity form - library picker.
$string['packagesource'] = 'Package source';
$string['packagesource_upload'] = 'Upload new package';
$string['packagesource_library'] = 'Select from Content Library';
$string['librarypackage'] = 'Library package';
$string['librarypackage_help'] = 'Select an existing package from the content library.';
$string['selectpackage'] = 'Select a package...';
$string['nolibrarypackages'] = 'No packages available in the content library.';

// AU selection.
$string['library:selectau'] = 'Select AU';
$string['library:selectau_help'] = 'Choose a specific Assignable Unit from the package, or select "All AUs" to include every AU in this activity.';
$string['library:allaus'] = 'All AUs (entire package)';

// Launch parameter profiles.
$string['launchprofiles'] = 'Launch Parameter Profiles';
$string['launchprofiles_desc'] = '<a href="{$a}">Manage Launch Parameter Profiles</a> — define reusable launch parameter sets for different content types.';
$string['profile:name'] = 'Profile name';
$string['profile:parameters'] = 'Launch parameters';
$string['profile:parameters_help'] = 'Default launch parameters for activities using this profile. If JSON, these are deep-merged with AU-level and activity-level parameters. Non-JSON values replace lower-priority values entirely.';
$string['profile:create'] = 'Create profile';
$string['profile:edit'] = 'Edit profile';
$string['profile:delete'] = 'Delete';
$string['profile:deleteconfirm'] = 'Are you sure you want to delete the profile "{$a}"?';
$string['profile:noprofiles'] = 'No launch parameter profiles defined yet.';
$string['profile:created'] = 'Profile created successfully.';
$string['profile:updated'] = 'Profile updated successfully.';
$string['profile:deleted'] = 'Profile deleted successfully.';
$string['profile:inuse'] = 'This profile is used by {$a} activities and cannot be deleted.';
$string['launchprofile'] = 'Launch parameter profile';
$string['launchprofile_help'] = 'Select a profile to apply default launch parameters. Profile parameters are merged with AU-level parameters, and activity-level overrides take highest priority.';
$string['profile:none'] = '(None)';

// Package versioning.
$string['library:versions'] = 'Versions';
$string['library:uploadnewversion'] = 'Upload new version';
$string['library:versionhistory'] = 'Version history';
$string['library:changelog'] = 'Changes';
$string['library:changes'] = 'changes';
$string['library:auchanged'] = 'AU "{$a->title}" field "{$a->field}" changed';
$string['library:auadded'] = 'AU "{$a}" added';
$string['library:auremoved'] = 'AU "{$a}" removed';
$string['library:blockadded'] = 'Block "{$a}" added';
$string['library:blockremoved'] = 'Block "{$a}" removed';
$string['library:updateavailable'] = 'A newer version (v{$a}) is available';
$string['library:reviewandupdate'] = 'Review and update';
$string['library:syncactivity'] = 'Update to latest version';
$string['library:synccomplete'] = 'Activity updated to latest version successfully.';
$string['library:currentversion'] = 'Current version (v{$a})';
$string['library:selectversion'] = 'Select version';
$string['library:versionoption'] = 'v{$a->number} — {$a->date} ({$a->changes} changes)';
$string['library:nochanges'] = 'No changes recorded';
$string['library:updateexternal'] = 'Update external AU';
$string['library:versionuploaded'] = 'Version {$a} uploaded successfully.';

// Standalone LRS.
$string['lrs_standalone'] = 'Standalone LRS';
$string['lrs_standalone_desc'] = 'Enable the built-in xAPI LRS endpoint for external tool access. When enabled, external tools can query statements via the LRS API using API key authentication.';
$string['lrs_enabled'] = 'Enable standalone LRS';
$string['lrs_enabled_desc'] = 'Allow external tools to access the xAPI LRS endpoint with API key authentication.';
$string['lrs_api_key'] = 'LRS API key';
$string['lrs_api_key_desc'] = 'API key for Basic auth access to the standalone LRS endpoint. Auto-generated on first use.';
$string['lrs_api_secret'] = 'LRS API secret';
$string['lrs_api_secret_desc'] = 'API secret for Basic auth access. Enter a new secret here; it will be stored as a SHA256 hash. Leave blank to keep the current secret.';

// Metrics dashboard.
$string['metrics:tab'] = 'Metrics';
$string['metrics:overview'] = 'Overview';
$string['metrics:learners'] = 'Learners';
$string['metrics:auanalytics'] = 'AU Analytics';
$string['metrics:registrations'] = 'Registrations';
$string['metrics:activesessions'] = 'Active Sessions';
$string['metrics:completionrate'] = 'Completion Rate';
$string['metrics:passed'] = 'Passed';
$string['metrics:failed'] = 'Failed';
$string['metrics:statementsovertime'] = 'Statements Over Time';
$string['metrics:verbdistribution'] = 'Verb Distribution';
$string['metrics:learner'] = 'Learner';
$string['metrics:registered'] = 'Registered';
$string['metrics:completed'] = 'Completed';
$string['metrics:avgscore'] = 'Avg Score';
$string['metrics:lastactive'] = 'Last Active';
$string['metrics:satisfied'] = 'Satisfied';
$string['metrics:austatus'] = 'AU Status';
$string['metrics:sessionhistory'] = 'Session History';
$string['metrics:state'] = 'State';
$string['metrics:duration'] = 'Duration';
$string['metrics:started'] = 'Started';
$string['metrics:sessions'] = 'Sessions';
$string['metrics:avgduration'] = 'Avg Duration';
$string['metrics:completionpct'] = 'Completion %';
$string['metrics:nodata'] = 'No data available yet.';
$string['metrics:backtolearners'] = '&larr; Back to all learners';
$string['metrics:deleteregistration'] = 'Delete';
$string['metrics:deleteconfirm'] = 'Delete all progress for {$a}? This cannot be undone.';
$string['metrics:exportcsv'] = 'Export CSV';
$string['registrationnotfound'] = 'Registration not found for this user.';
$string['metrics:alllearners'] = 'All learners';
$string['metrics:daterange7'] = '7d';
$string['metrics:daterange14'] = '14d';
$string['metrics:daterange30'] = '30d';
$string['metrics:daterangeall'] = 'All';

// Capabilities.
$string['cmi5:managelibrary'] = 'Manage cmi5 content library';
