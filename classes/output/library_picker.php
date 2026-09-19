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
 * Renderable for the content library package picker grid.
 *
 * @package    mod_cmi5
 * @copyright  2026 Bylight
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_cmi5\output;

defined('MOODLE_INTERNAL') || die();

use mod_cmi5\content_library;
use renderable;
use renderer_base;
use templatable;

/**
 * Builds the template context for the content library picker.
 *
 * The form itself only shows the current selection and a button that opens the
 * browse modal; the modal's grid, searching and paging are handled by the
 * mod_cmi5/library_picker AMD module through the library_list_packages web service.
 * Without JavaScript the picker stays hidden and the plain selects remain usable.
 */
class library_picker implements renderable, templatable {

    /** @var int Number of cards shown per page in the browse modal. */
    const PER_PAGE = 9;

    /** @var int Maximum number of characters of description shown on a card. */
    const DESCRIPTION_LENGTH = 120;

    /** @var int Number of colour tones available for generated package tiles. */
    const TONE_COUNT = 6;

    /** @var int Currently selected package ID, or 0 for none. */
    protected $selectedpackageid;

    /** @var string Currently selected AU value, in "packageid:auid" form, or '' for all AUs. */
    protected $selectedauvalue;

    /** @var string Element ID of the hidden input holding the package ID. */
    protected $inputid;

    /** @var string Element ID of the hidden input holding the AU selection. */
    protected $auinputid;

    /** @var int Course or module context used to authorize picker requests. */
    protected $contextid;

    /** @var int Current package version ID, or 0 when creating/switching packages. */
    protected $currentversionid;

    /** @var int Package ID originally linked to the activity, or 0 when creating. */
    protected $currentpackageid;

    /** @var bool Whether the current package selection may be cleared. */
    protected $canclear;

    /**
     * Constructor.
     *
     * @param int $selectedpackageid Currently selected package ID, or 0 for none.
     * @param string $selectedauvalue Currently selected AU value ("packageid:auid"), or '' for all AUs.
     * @param string $inputid Element ID of the form input holding the package ID.
     * @param string $auinputid Element ID of the form input holding the AU selection.
     * @param int $contextid Course or module context ID for the activity form.
     * @param int $currentpackageid Package originally linked to the activity.
     * @param int $currentversionid Current package version ID.
     * @param bool $canclear Whether the selection may be cleared.
     */
    public function __construct(int $selectedpackageid, string $selectedauvalue,
            string $inputid, string $auinputid, int $contextid,
            int $currentpackageid = 0, int $currentversionid = 0, bool $canclear = true) {
        $this->selectedpackageid = $selectedpackageid;
        $this->selectedauvalue = $selectedauvalue;
        $this->inputid = $inputid;
        $this->auinputid = $auinputid;
        $this->contextid = $contextid;
        $this->currentpackageid = $currentpackageid;
        $this->currentversionid = $currentversionid;
        $this->canclear = $canclear;
    }

    /**
     * Export the picker context for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $total = content_library::count_packages('', content_library::STATUS_ACTIVE);

        $selectedtitle = '';
        if ($this->selectedpackageid) {
            $selected = content_library::get_package($this->selectedpackageid);
            if ($selected) {
                $selectedtitle = format_string($selected->title);
            }
        }
        $hasselection = $selectedtitle !== '';

        return [
            'contextid' => $this->contextid,
            'currentpackageid' => $this->currentpackageid,
            'currentversionid' => $this->currentversionid,
            'canclear' => $this->canclear,
            'inputid' => $this->inputid,
            'auinputid' => $this->auinputid,
            'perpage' => self::PER_PAGE,
            'total' => $total,
            'haspackages' => $total > 0,
            'hasselection' => $hasselection,
            'selectedtitle' => $selectedtitle,
            'selectedautitle' => $hasselection ? $this->selected_au_title() : '',
            'initials' => $hasselection ? self::initials($selectedtitle) : '',
            'tone' => $hasselection ? self::tone($selectedtitle) : 1,
            'modaljson' => json_encode(self::modal_context(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
    }

    /**
     * Build the sort and filter options offered by the browse modal.
     *
     * @return array Context with 'sorts' and 'sources' arrays of {value, label, selected}.
     */
    public static function modal_context(): array {
        return [
            'sorts' => [
                [
                    'value' => content_library::SORT_RECENT,
                    'label' => get_string('picker:sort_recent', 'cmi5'),
                    'selected' => true,
                ],
                [
                    'value' => content_library::SORT_TITLE,
                    'label' => get_string('picker:sort_title', 'cmi5'),
                    'selected' => false,
                ],
                [
                    'value' => content_library::SORT_USAGE,
                    'label' => get_string('picker:sort_usage', 'cmi5'),
                    'selected' => false,
                ],
            ],
            'sources' => [
                [
                    'value' => -1,
                    'label' => get_string('picker:alltypes', 'cmi5'),
                    'selected' => true,
                ],
                [
                    'value' => content_library::SOURCE_ZIP,
                    'label' => get_string('library:source_zip', 'cmi5'),
                    'selected' => false,
                ],
                [
                    'value' => content_library::SOURCE_EXTERNAL_URL,
                    'label' => get_string('library:source_external', 'cmi5'),
                    'selected' => false,
                ],
                [
                    'value' => content_library::SOURCE_API,
                    'label' => get_string('library:source_api', 'cmi5'),
                    'selected' => false,
                ],
            ],
        ];
    }

    /**
     * Describe the currently selected AU scope for the selection summary.
     *
     * @return string The AU title, or the "all AUs" label, or '' when nothing is selected.
     */
    protected function selected_au_title(): string {
        if (empty($this->selectedpackageid)) {
            return '';
        }
        if (empty($this->selectedauvalue) || strpos($this->selectedauvalue, ':') === false) {
            return get_string('library:allaus', 'cmi5');
        }

        [, $auid] = explode(':', $this->selectedauvalue, 2);
        $versionid = $this->selectedpackageid === $this->currentpackageid ? $this->currentversionid : 0;
        $details = content_library::get_package_details($this->selectedpackageid, $versionid);
        foreach ($details->aus as $au) {
            if ((string) $au->id === $auid || $au->auid === $auid) {
                return format_string($au->title);
            }
        }

        return get_string('library:allaus', 'cmi5');
    }

    /**
     * Derive up to two initials from a package title for the generated tile.
     *
     * @param string $title The package title.
     * @return string One or two upper-case characters.
     */
    public static function initials(string $title): string {
        $words = preg_split('/[\s\-_]+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($words)) {
            return '?';
        }
        $initials = \core_text::strtoupper(\core_text::substr($words[0], 0, 1));
        if (count($words) > 1) {
            $initials .= \core_text::strtoupper(\core_text::substr($words[1], 0, 1));
        }
        return $initials;
    }

    /**
     * Pick a stable colour tone for a package tile based on its title.
     *
     * @param string $title The package title.
     * @return int A tone index between 1 and TONE_COUNT.
     */
    public static function tone(string $title): int {
        return (self::title_hash($title) % self::TONE_COUNT) + 1;
    }

    /**
     * Hash a title to a small number. Mirrors titleHash() in amd/src/library_picker.js, byte for byte.
     *
     * @param string $title The text to hash.
     * @return int A number between 0 and 1000002.
     */
    public static function title_hash(string $title): int {
        $hash = 0;
        $length = strlen($title);
        for ($i = 0; $i < $length; $i++) {
            $hash = ($hash * 31 + ord($title[$i])) % 1000003;
        }
        return $hash;
    }
}
