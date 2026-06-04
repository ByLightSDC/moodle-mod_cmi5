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
 * Activity creation/editing form for mod_cmi5.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module instance settings form.
 */
class mod_cmi5_mod_form extends moodleform_mod {

    /**
     * Define the form elements.
     */
    public function definition() {
        global $DB;
        $mform = $this->_form;

        // General section.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('cmi5name', 'cmi5'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Package section.
        if (empty($this->current->instance)) {
            // Create mode.
            $mform->addElement('header', 'packagehdr', get_string('cmi5fieldset', 'cmi5'));
            $this->add_package_source_elements($mform, 'packagefile');
        } else if (empty($this->current->packageid)) {
            // Edit mode, upload-based: file manager for ZIP replacement only.
            $mform->addElement('header', 'packagehdr', get_string('cmi5fieldset', 'cmi5'));
            $filemanageroptions = [
                'accepted_types' => ['.zip'],
                'maxbytes' => 0,
                'maxfiles' => 1,
                'subdirs' => 0,
            ];
            $mform->addElement('filemanager', 'packagefile', get_string('packagefile_replace', 'cmi5'), null, $filemanageroptions);
            $mform->addHelpButton('packagefile', 'packagefile_replace', 'cmi5');
        } else {
            // Edit mode, library-linked: library picker + version selector.
            $mform->addElement('header', 'packagehdr', get_string('cmi5fieldset', 'cmi5'));
            $this->add_library_picker_elements($mform);

            // Version selector — for syncing to a different version of the current package.
            $currentversionid = $this->current->packageversionid ?? 0;
            if ($currentversionid) {
                $allversions = \mod_cmi5\content_library::get_package_versions(
                    (int) $this->current->packageid
                );
                $currentversion = \mod_cmi5\content_library::get_version((int) $currentversionid);
                $currentnum = $currentversion ? (int) $currentversion->versionnumber : 0;

                $versionoptions = [
                    0 => get_string('library:currentversion', 'cmi5', $currentnum),
                ];
                $changelogs = [];
                foreach ($allversions as $ver) {
                    if ((int) $ver->id === (int) $currentversionid) {
                        continue;
                    }
                    $changecount = 0;
                    $changeentries = [];
                    if (!empty($ver->changelog)) {
                        $decoded = json_decode($ver->changelog, true);
                        if (is_array($decoded)) {
                            $changecount = count($decoded);
                            $changeentries = $decoded;
                        }
                    }
                    $versionoptions[$ver->id] = get_string('library:versionoption', 'cmi5', (object) [
                        'number' => $ver->versionnumber,
                        'date' => userdate($ver->timecreated, get_string('strftimedatefullshort', 'langconfig')),
                        'changes' => $changecount,
                    ]);
                    if ($changeentries) {
                        $items = '';
                        foreach ($changeentries as $entry) {
                            $desc = is_array($entry) ? ($entry['description'] ?? '') : (string) $entry;
                            $items .= '<li>' . s($desc) . '</li>';
                        }
                        $changelogs[$ver->id] = '<ul class="mb-0">' . $items . '</ul>';
                    } else {
                        $changelogs[$ver->id] = '<em>' . get_string('library:nochanges', 'cmi5') . '</em>';
                    }
                }

                if (count($versionoptions) > 1) {
                    $mform->addElement('select', 'syncversion',
                        get_string('library:selectversion', 'cmi5'), $versionoptions);
                    $mform->setDefault('syncversion', 0);

                    $changeloghtml = '<div id="cmi5-version-changelog">';
                    foreach ($changelogs as $vid => $html) {
                        $changeloghtml .= '<div class="cmi5-changelog-entry" '
                            . 'data-versionid="' . $vid . '" style="display:none;">'
                            . $html . '</div>';
                    }
                    $changeloghtml .= '</div>';
                    $mform->addElement('static', 'changelogpreview',
                        get_string('library:changelog', 'cmi5'), $changeloghtml);

                    $mform->addElement('html', "<script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var sel = document.getElementById('id_syncversion');
                        if (!sel) return;
                        function showChangelog() {
                            var entries = document.querySelectorAll('.cmi5-changelog-entry');
                            entries.forEach(function(el) { el.style.display = 'none'; });
                            var vid = sel.value;
                            if (vid && vid !== '0') {
                                var el = document.querySelector('.cmi5-changelog-entry[data-versionid=\"' + vid + '\"]');
                                if (el) el.style.display = 'block';
                            }
                        }
                        sel.addEventListener('change', showChangelog);
                        showChangelog();
                    });
                    </script>");
                }
            }
        }

        // Grade settings.
        $mform->addElement('header', 'gradehdr', get_string('grades'));

        $gradeoptions = [
            0 => get_string('gradehighest', 'cmi5'),
            1 => get_string('gradeaverage', 'cmi5'),
            2 => get_string('gradefirst', 'cmi5'),
            3 => get_string('gradelast', 'cmi5'),
        ];
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'cmi5'), $gradeoptions);
        $mform->addHelpButton('grademethod', 'grademethod', 'cmi5');
        $mform->setDefault('grademethod', 0);

        $mform->addElement('text', 'maxgrade', get_string('maxgrade', 'cmi5'));
        $mform->setType('maxgrade', PARAM_FLOAT);
        $mform->setDefault('maxgrade', 100);

        // Launch settings.
        $mform->addElement('header', 'launchhdr', get_string('launchmethod', 'cmi5'));

        $launchoptions = [
            0 => get_string('launchnewwindow', 'cmi5'),
            1 => get_string('launchiframe', 'cmi5'),
        ];
        $mform->addElement('select', 'launchmethod', get_string('launchmethod', 'cmi5'), $launchoptions);
        $mform->addHelpButton('launchmethod', 'launchmethod', 'cmi5');
        $mform->setDefault('launchmethod', get_config('mod_cmi5', 'defaultlaunchmethod') ?: 0);

        $mform->addElement('text', 'sessiontimeout', get_string('sessiontimeout', 'cmi5'));
        $mform->setType('sessiontimeout', PARAM_INT);
        $mform->setDefault('sessiontimeout', get_config('mod_cmi5', 'defaultsessiontimeout') ?: 3600);
        $mform->addHelpButton('sessiontimeout', 'sessiontimeout', 'cmi5');

        // Launch parameter profile selector.
        $profileoptions = [0 => get_string('profile:none', 'cmi5')];
        $profiles = $DB->get_records('cmi5_launch_profiles', null, 'name ASC');
        foreach ($profiles as $profile) {
            $profileoptions[$profile->id] = format_string($profile->name);
        }
        $mform->addElement('select', 'profileid', get_string('launchprofile', 'cmi5'), $profileoptions);
        $mform->addHelpButton('profileid', 'launchprofile', 'cmi5');
        $mform->setDefault('profileid', 0);

        $mform->addElement('textarea', 'launchparameters', get_string('launchparameters', 'cmi5'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('launchparameters', PARAM_RAW);
        $mform->addHelpButton('launchparameters', 'launchparameters', 'cmi5');

        // LRS settings.
        $mform->addElement('header', 'lrshdr', get_string('lrssettings', 'cmi5'));

        $lrsmodeoptions = [
            0 => get_string('lrsmode_local', 'cmi5'),
            1 => get_string('lrsmode_forward', 'cmi5'),
            2 => get_string('lrsmode_lrsonly', 'cmi5'),
        ];
        $mform->addElement('select', 'lrsmode', get_string('lrsmode', 'cmi5'), $lrsmodeoptions);
        $mform->addHelpButton('lrsmode', 'lrsmode', 'cmi5');
        $mform->setDefault('lrsmode', get_config('mod_cmi5', 'defaultlrsmode') ?: 0);

        $mform->addElement('text', 'lrsendpoint', get_string('lrsendpoint', 'cmi5'), ['size' => '64']);
        $mform->setType('lrsendpoint', PARAM_URL);
        $mform->addHelpButton('lrsendpoint', 'lrsendpoint', 'cmi5');
        $mform->setDefault('lrsendpoint', get_config('mod_cmi5', 'defaultlrsendpoint'));
        $mform->hideIf('lrsendpoint', 'lrsmode', 'eq', 0);

        $mform->addElement('text', 'lrskey', get_string('lrskey', 'cmi5'), ['size' => '40']);
        $mform->setType('lrskey', PARAM_TEXT);
        $mform->setDefault('lrskey', get_config('mod_cmi5', 'defaultlrskey'));
        $mform->hideIf('lrskey', 'lrsmode', 'eq', 0);

        $mform->addElement('passwordunmask', 'lrssecret', get_string('lrssecret', 'cmi5'), ['size' => '40']);
        $mform->setType('lrssecret', PARAM_TEXT);
        $mform->setDefault('lrssecret', get_config('mod_cmi5', 'defaultlrssecret'));
        $mform->hideIf('lrssecret', 'lrsmode', 'eq', 0);

        // Standard elements.
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Render the package source selector (upload or library) and all dependent fields.
     * Used in both create and edit modes; only the file manager string key differs.
     *
     * @param MoodleQuickForm $mform
     * @param string $filestringkey  Lang string key for the file manager label/help ('packagefile' or 'packagefile_replace')
     */
    private function add_package_source_elements($mform, string $filestringkey): void {
        $sourceoptions = [
            'upload' => get_string('packagesource_upload', 'cmi5'),
            'library' => get_string('packagesource_library', 'cmi5'),
        ];
        $mform->addElement('select', 'packagesource', get_string('packagesource', 'cmi5'), $sourceoptions);
        $mform->setDefault('packagesource', 'upload');

        $filemanageroptions = [
            'accepted_types' => ['.zip'],
            'maxbytes' => 0,
            'maxfiles' => 1,
            'subdirs' => 0,
        ];
        $mform->addElement('filemanager', 'packagefile', get_string($filestringkey, 'cmi5'), null, $filemanageroptions);
        $mform->addHelpButton('packagefile', $filestringkey, 'cmi5');
        $mform->hideIf('packagefile', 'packagesource', 'ne', 'upload');

        $this->add_library_picker_elements($mform);
        $mform->hideIf('packageid', 'packagesource', 'ne', 'library');
        $mform->hideIf('libraryauid', 'packagesource', 'ne', 'library');
    }

    /**
     * Render the library package picker and AU picker without a source selector.
     * Used standalone for library-linked edit mode, and via add_package_source_elements
     * for create/upload-based edit mode where hideIf controls visibility.
     *
     * @param MoodleQuickForm $mform
     */
    private function add_library_picker_elements($mform): void {
        $libraryoptions = ['' => get_string('selectpackage', 'cmi5')];
        $packages = \mod_cmi5\content_library::list_packages('', 1, 0, 200);
        $ausByPackage = [];
        foreach ($packages as $pkg) {
            $libraryoptions[$pkg->id] = format_string($pkg->title);
            $details = \mod_cmi5\content_library::get_package_details((int) $pkg->id);
            $ausByPackage[$pkg->id] = $details->aus ?? [];
        }
        $mform->addElement('select', 'packageid', get_string('librarypackage', 'cmi5'), $libraryoptions);
        $mform->addHelpButton('packageid', 'librarypackage', 'cmi5');

        $auoptions = ['' => get_string('library:allaus', 'cmi5')];
        $aujsonmap = [];
        foreach ($ausByPackage as $pkgid => $aus) {
            $aujsonmap[$pkgid] = [];
            foreach ($aus as $au) {
                $key = $pkgid . ':' . $au->id;
                $auoptions[$key] = format_string($au->title);
                $aujsonmap[$pkgid][] = ['key' => $key, 'title' => format_string($au->title)];
            }
        }
        $mform->addElement('select', 'libraryauid', get_string('library:selectau', 'cmi5'), $auoptions);
        $mform->addHelpButton('libraryauid', 'library:selectau', 'cmi5');

        $aujson = json_encode($aujsonmap);
        $allauslabel = get_string('library:allaus', 'cmi5');
        $mform->addElement('html', "<script>
        document.addEventListener('DOMContentLoaded', function() {
            var pkgSelect = document.getElementById('id_packageid');
            var auSelect = document.getElementById('id_libraryauid');
            var auMap = {$aujson};
            var allLabel = " . json_encode($allauslabel) . ";
            if (!pkgSelect || !auSelect) return;
            function updateAuOptions() {
                var pkgId = pkgSelect.value;
                var currentVal = auSelect.value;
                auSelect.innerHTML = '';
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = allLabel;
                auSelect.appendChild(opt);
                if (pkgId && auMap[pkgId]) {
                    auMap[pkgId].forEach(function(au) {
                        var o = document.createElement('option');
                        o.value = au.key;
                        o.textContent = au.title;
                        if (au.key === currentVal) o.selected = true;
                        auSelect.appendChild(o);
                    });
                }
            }
            pkgSelect.addEventListener('change', updateAuOptions);
            updateAuOptions();
        });
        </script>");
    }

    /**
     * After form data is set, detect AU IRI mismatches for any package change and inject
     * a warning block + confirmation checkbox. Covers three edit scenarios:
     *   - Upload-based instance replacing via a new ZIP upload
     *   - Upload-based instance switching source to a library package
     *   - Library-linked instance syncing to a different version
     */
    public function definition_after_data() {
        global $USER;
        parent::definition_after_data();

        if (empty($this->current->instance) || !$this->is_submitted()) {
            return;
        }

        $mismatches = [];
        $cmi5id = (int) $this->current->instance;

        if (empty($this->current->packageid)) {
            // Upload-based instance: check draft file.
            $mform = $this->_form;
            if (!$mform->elementExists('packagefile')) {
                return;
            }
            $draftitemid = optional_param('packagefile', 0, PARAM_INT);
            if (!empty($draftitemid)) {
                $mismatches = \mod_cmi5\cmi5_package::detect_au_mismatches_from_draft(
                    $draftitemid, $cmi5id, (int) $USER->id
                );
            }
        } else {
            // Library-linked instance: check package switch or version sync.
            $newpackageid = optional_param('packageid', 0, PARAM_INT);
            if ($newpackageid && $newpackageid !== (int) $this->current->packageid) {
                $libpackage = \mod_cmi5\content_library::get_package($newpackageid);
                if ($libpackage && !empty($libpackage->latestversion)) {
                    $mismatches = \mod_cmi5\cmi5_package::detect_au_mismatches_from_version(
                        (int) $libpackage->latestversion, $cmi5id
                    );
                }
            } else {
                $syncversion = optional_param('syncversion', 0, PARAM_INT);
                if ($syncversion > 0) {
                    $mismatches = \mod_cmi5\cmi5_package::detect_au_mismatches_from_version(
                        $syncversion, $cmi5id
                    );
                }
            }
        }

        if (!empty($mismatches)) {
            $this->inject_mismatch_warning($mismatches);
        }
    }

    /**
     * Inject the AU IRI mismatch warning alert and confirmation checkbox into the form.
     *
     * @param array $mismatches Objects with ->title and ->auid.
     */
    private function inject_mismatch_warning(array $mismatches): void {
        $mform = $this->_form;

        $aulist = '<ul>';
        foreach ($mismatches as $au) {
            $aulist .= '<li><strong>' . s($au->title) . '</strong>'
                . ' <code class="small">' . s($au->auid) . '</code></li>';
        }
        $aulist .= '</ul>';

        $warninghtml = '<div class="alert alert-warning mt-2">'
            . '<p class="mb-1"><strong>' . get_string('packagemismatch:warningtitle', 'cmi5') . '</strong></p>'
            . '<p class="mb-1">' . get_string('packagemismatch:warningbody', 'cmi5') . '</p>'
            . '<p class="mb-0">' . get_string('packagemismatch:aulistlabel', 'cmi5') . '</p>'
            . $aulist
            . '<p class="mb-0"><strong>' . get_string('packagemismatch:recommendation', 'cmi5') . '</strong></p>'
            . '</div>';

        $mform->addElement('static', 'packagemismatchwarning', '', $warninghtml);
        $mform->addElement('advcheckbox', 'packagemismatchconfirmed', '',
            get_string('packagemismatch:confirm', 'cmi5'));

        $mform->addElement('html', "<script>
        document.addEventListener('DOMContentLoaded', function() {
            var checkbox = document.getElementById('id_packagemismatchconfirmed');
            if (!checkbox) return;
            var buttons = document.querySelectorAll('#id_submitbutton, #id_submitbutton2');
            function toggle() {
                buttons.forEach(function(btn) {
                    btn.disabled = !checkbox.checked;
                });
            }
            toggle();
            checkbox.addEventListener('change', toggle);
        });
        </script>");
    }

    /**
     * Pre-populate form defaults when editing an existing instance.
     *
     * @param array|stdClass $defaultvalues
     */
    public function set_data($defaultvalues) {
        $defaultvalues = (array) $defaultvalues;
        if (!empty($defaultvalues['packageid'])) {
            $defaultvalues['packagesource'] = 'library';
        } else {
            $defaultvalues['packagesource'] = 'upload';
        }
        parent::set_data($defaultvalues);
    }

    /**
     * Validate the form data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);

        // On create, require either a package upload or a library selection.
        if (empty($this->current->instance)) {
            if (($data['packagesource'] ?? 'upload') === 'upload') {
                //Check if a file is uploaded.
                $fs = get_file_storage();
                $usercontext = context_user::instance($USER->id);
                $draftfiles = $fs->get_area_files(
                    $usercontext->id, 'user', 'draft', $data['packagefile'], 'id', false
                );
                if (empty($draftfiles)) {
                    $errors['packagefile'] = get_string('required');
                }
            // This is when packagesource is library.   
            } else {
                if (empty($data['packageid'])) {
                    $errors['packageid'] = get_string('required');
                }
                // libraryauid is optional — empty means "all AUs".
            }
        }

        // On edit of upload-based instance, check for AU IRI mismatches in replacement ZIP.
        if (!empty($this->current->instance) && empty($this->current->packageid)) {
            $draftitemid = (int) ($data['packagefile'] ?? 0);
            if (!empty($draftitemid)) {
                $mismatches = \mod_cmi5\cmi5_package::detect_au_mismatches_from_draft(
                    $draftitemid, (int) $this->current->instance, $USER->id
                );
                if (!empty($mismatches) && empty($data['packagemismatchconfirmed'])) {
                    $errors['packagemismatchconfirmed'] = get_string('packagemismatch:confirmerror', 'cmi5');
                }
            }
        }

        // On edit of library-linked instance, check for AU IRI mismatches in package switch or version sync.
        if (!empty($this->current->instance) && !empty($this->current->packageid)) {
            $newpackageid = (int) ($data['packageid'] ?? 0);
            if ($newpackageid && $newpackageid !== (int) $this->current->packageid) {
                $libpackage = \mod_cmi5\content_library::get_package($newpackageid);
                if ($libpackage && !empty($libpackage->latestversion)) {
                    $mismatches = \mod_cmi5\cmi5_package::detect_au_mismatches_from_version(
                        (int) $libpackage->latestversion, (int) $this->current->instance
                    );
                    if (!empty($mismatches) && empty($data['packagemismatchconfirmed'])) {
                        $errors['packagemismatchconfirmed'] = get_string('packagemismatch:confirmerror', 'cmi5');
                    }
                }
            } else {
                $syncversion = (int) ($data['syncversion'] ?? 0);
                if ($syncversion > 0) {
                    $mismatches = \mod_cmi5\cmi5_package::detect_au_mismatches_from_version(
                        $syncversion, (int) $this->current->instance
                    );
                    if (!empty($mismatches) && empty($data['packagemismatchconfirmed'])) {
                        $errors['packagemismatchconfirmed'] = get_string('packagemismatch:confirmerror', 'cmi5');
                    }
                }
            }
        }

        if ($data['lrsmode'] > 0) {
            if (empty($data['lrsendpoint'])) {
                $errors['lrsendpoint'] = get_string('required');
            }
            if (empty($data['lrskey'])) {
                $errors['lrskey'] = get_string('required');
            }
            if (empty($data['lrssecret'])) {
                $errors['lrssecret'] = get_string('required');
            }
        }

        if (isset($data['maxgrade']) && $data['maxgrade'] <= 0) {
            $errors['maxgrade'] = get_string('required');
        }

        return $errors;
    }
}
