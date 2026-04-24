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
        $mform->addElement('header', 'packagehdr', get_string('cmi5fieldset', 'cmi5'));

        // Package source: upload or library.
        $sourceoptions = [
            'upload' => get_string('packagesource_upload', 'cmi5'),
            'library' => get_string('packagesource_library', 'cmi5'),
        ];
        $mform->addElement('select', 'packagesource', get_string('packagesource', 'cmi5'), $sourceoptions);
        $mform->setDefault('packagesource', 'upload');

        // Upload option.
        $filemanageroptions = [];
        $filemanageroptions['accepted_types'] = ['.zip'];
        $filemanageroptions['maxbytes'] = 0;
        $filemanageroptions['maxfiles'] = 1;
        $filemanageroptions['subdirs'] = 0;
        $mform->addElement(
            'filemanager',
            'packagefile',
            get_string('packagefile', 'cmi5'),
            null,
            $filemanageroptions
        );
        $mform->addHelpButton('packagefile', 'packagefile', 'cmi5');
        $mform->hideIf('packagefile', 'packagesource', 'ne', 'upload');

        // Library picker option.
        $libraryoptions = ['' => get_string('selectpackage', 'cmi5')];
        $packages = \mod_cmi5\content_library::list_packages('', 1, 0, 200);
        // Build AU lookup keyed by package for the AU picker.
        $ausByPackage = [];
        foreach ($packages as $pkg) {
            $libraryoptions[$pkg->id] = format_string($pkg->title);
            $details = \mod_cmi5\content_library::get_package_details((int) $pkg->id);
            $ausByPackage[$pkg->id] = $details->aus ?? [];
        }
        $mform->addElement('select', 'packageid', get_string('librarypackage', 'cmi5'), $libraryoptions);
        $mform->addHelpButton('packageid', 'librarypackage', 'cmi5');
        $mform->hideIf('packageid', 'packagesource', 'ne', 'library');

        // AU picker — select which AU from the package (or "all").
        $auoptions = ['' => get_string('library:allaus', 'cmi5')];
        // Build a JSON map for JS to use when switching packages.
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
        $mform->hideIf('libraryauid', 'packagesource', 'ne', 'library');

        // Inline JS to filter AU options based on selected package.
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

        if (empty($this->current->instance)) {
            // Validation is handled in validation() — one of the two must be provided.
        }

        // Version selector — when editing an existing library-linked instance.
        if (!empty($this->current->instance) && !empty($this->current->packageid)) {
            $currentversionid = $this->current->packageversionid ?? 0;
            if ($currentversionid) {
                $allversions = \mod_cmi5\content_library::get_package_versions(
                    (int) $this->current->packageid
                );
                $currentversion = \mod_cmi5\content_library::get_version((int) $currentversionid);
                $currentnum = $currentversion ? (int) $currentversion->versionnumber : 0;

                // Build dropdown options and per-version changelog HTML.
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
                    // Build changelog HTML for this version.
                    if ($changeentries) {
                        $items = '';
                        foreach ($changeentries as $entry) {
                            $desc = is_array($entry) ? ($entry['description'] ?? '') : (string) $entry;
                            $items .= '<li>' . s($desc) . '</li>';
                        }
                        $changelogs[$ver->id] = '<ul class="mb-0">' . $items . '</ul>';
                    } else {
                        $changelogs[$ver->id] = '<em>' .
                            get_string('library:nochanges', 'cmi5') . '</em>';
                    }
                }

                if (count($versionoptions) > 1) {
                    $mform->addElement('select', 'syncversion',
                        get_string('library:selectversion', 'cmi5'), $versionoptions);
                    $mform->setDefault('syncversion', 0);

                    // Changelog preview area (all changelogs rendered, toggled by JS).
                    $changeloghtml = '<div id="cmi5-version-changelog">';
                    foreach ($changelogs as $vid => $html) {
                        $changeloghtml .= '<div class="cmi5-changelog-entry" '
                            . 'data-versionid="' . $vid . '" style="display:none;">'
                            . $html . '</div>';
                    }
                    $changeloghtml .= '</div>';
                    $mform->addElement('static', 'changelogpreview',
                        get_string('library:changelog', 'cmi5'), $changeloghtml);

                    // Inline JS to show/hide changelog based on selected version.
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
        $mform->hideIf('lrskey', 'lrsmode', 'eq', 0);

        $mform->addElement('passwordunmask', 'lrssecret', get_string('lrssecret', 'cmi5'), ['size' => '40']);
        $mform->setType('lrssecret', PARAM_TEXT);
        $mform->hideIf('lrssecret', 'lrsmode', 'eq', 0);

        // Standard elements.
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
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
        $errors = parent::validation($data, $files);

        // On create, require either a package upload or a library selection.
        if (empty($this->current->instance)) {
            if (($data['packagesource'] ?? 'upload') === 'upload') {
                if (empty($data['packagefile'])) {
                    $errors['packagefile'] = get_string('required');
                }
            } else {
                if (empty($data['packageid'])) {
                    $errors['packageid'] = get_string('required');
                }
                // libraryauid is optional — empty means "all AUs".
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
