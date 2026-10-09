<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_le_dashboard\local;

/**
 * The guidance and support text popovers show under their factual line, edited as rich text in the
 * "Guidance and support" settings (Pierre, 2026-10-09).
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class guidance {
    /** The boxes, in the order the settings list them. Each is the setting guidance_<key>. */
    const KEYS = ['latepenalty', 'feedbacklate', 'nosubmission', 'finalised', 'attempts', 'brief', 'untagged', 'setup',
        'unallocated', 'plagiarism', 'loaderror'];

    /** @var array Formatted guidance by key and language, for this request. */
    protected static $cache = [];

    /**
     * A box's default: today's wording, so nothing changes until someone edits it.
     *
     * @param string $key
     * @return string HTML, or '' for none.
     */
    public static function default(string $key): string {
        return get_string('guidance_' . $key . '_default', 'block_le_dashboard');
    }

    /**
     * A box's text, formatted for the viewer, or null when the box is empty.
     *
     * @param string $key One of KEYS.
     * @return string|null
     */
    public static function html(string $key): ?string {
        $lang = current_language();
        if (!array_key_exists($key . ':' . $lang, self::$cache)) {
            $raw = get_config('block_le_dashboard', 'guidance_' . $key);
            // Unset until an admin saves the settings: use the default.
            $raw = $raw === false ? self::default($key) : (string)$raw;
            // Filters (multilang included) apply, and unsafe HTML is cleaned.
            $html = self::is_empty($raw) ? null
                : format_text($raw, FORMAT_HTML, ['context' => \context_system::instance()]);
            self::$cache[$key . ':' . $lang] = $html !== null && self::is_empty($html) ? null : $html;
        }
        return self::$cache[$key . ':' . $lang];
    }

    /**
     * Whether rich text says nothing: no text once tags, non-breaking spaces and whitespace go, and no
     * image. The editor's leftovers (<p><br></p>, <p dir="ltr"></p>, <div>&nbsp;</div>) count as empty.
     *
     * @param string $html
     * @return bool
     */
    public static function is_empty(string $html): bool {
        if (preg_match('/<img\b/i', $html)) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    /**
     * Fills the boxes from the settings they replace, then removes those (upgrade to beta20). A site that
     * edited them keeps its wording and links; one that didn't keeps the defaults.
     */
    public static function migrate(): void {
        $old = fn($name) => get_config('block_le_dashboard', $name);
        $url = fn($name) => trim((string)$old($name));
        $link = fn($href, $text) => \html_writer::link($href, s($text));

        $note = $old('latenote');
        if ($note !== false || $url('latenoteurl') !== '') {
            $note = $note === false ? strip_tags(self::default('latepenalty')) : trim($note);
            $html = $note !== '' ? '<p>' . s($note) . '</p>' : '';
            if ($url('latenoteurl') !== '') {
                $html .= '<p>Read more about ' . $link($url('latenoteurl'), 'late penalties') . '.</p>';
            }
            set_config('guidance_latepenalty', $html, 'block_le_dashboard');
        }

        $contact = $old('feedbackcontact');
        if ($contact !== false || $url('feedbackcontacturl') !== '') {
            $contact = $contact === false ? 'your module convenor' : trim($contact);
            $who = $url('feedbackcontacturl') !== '' ? $link($url('feedbackcontacturl'), $contact) : s($contact);
            // An empty contact showed no hint at all; now the factual line stays, with nothing under it.
            set_config(
                'guidance_feedbacklate',
                $contact !== '' ? '<p>If it doesn\'t, contact ' . $who . '.</p>' : '',
                'block_le_dashboard'
            );
        }

        if ($url('briefguidanceurl') !== '') {
            set_config('guidance_brief', self::default('brief') . '<p>See our ' . $link($url('briefguidanceurl'), 'guidance')
                . ' for more info.</p>', 'block_le_dashboard');
        }
        if ($url('untaggedguidanceurl') !== '') {
            set_config('guidance_untagged', '<p>' . $link($url('untaggedguidanceurl'), 'Guidance on tagging activities')
                . '</p>', 'block_le_dashboard');
        }

        foreach (
            ['latenote', 'latenoteurl', 'feedbackcontact', 'feedbackcontacturl', 'briefguidanceurl',
                'untaggedguidanceurl'] as $name
        ) {
            unset_config($name, 'block_le_dashboard');
        }
        self::reset_caches();
    }

    /**
     * Forgets the formatted text, after a setting changes (tests).
     */
    public static function reset_caches(): void {
        self::$cache = [];
    }
}
