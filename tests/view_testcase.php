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

namespace block_le_dashboard;

use block_le_dashboard\external\get_view;
use block_le_dashboard\fixture\failed;
use block_le_dashboard\fixture\phrases;
use block_le_dashboard\fixture\steps;
use block_le_dashboard\fixture\unavailable;
use block_le_dashboard\local\years;
use block_le_dashboard\output\dashboard;

/**
 * Runs a Behat scenario's sentences from PHPUnit (beta30, B2): the rule tests under tests/rules/ are feature
 * files turned into test methods, one step() call per line.
 *
 * A sentence is matched against the Behat context's own step regexes (tests/behat/behat_block_le_dashboard.php
 * is the one place the vocabulary is defined). A sentence that writes to the site or checks its tables runs the
 * same steps::method() the context delegates to. A sentence that reads the dashboard is checked here against the
 * block's rendered HTML, with the same selectors the Behat steps use on the page: "I log in as ... and view ..."
 * renders the dashboard as the block does, into a DOM, and the assertion twins read that DOM. Only sentences
 * that have a twin here, or a method in steps, can be run; the converter (tests/rules/convert.py) leaves the rest
 * in Behat.
 *
 * @package    block_le_dashboard
 * @category   test
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class view_testcase extends \advanced_testcase {
    /** @var string The Behat context the sentences come from, relative to the plugin. */
    const CONTEXT = 'tests/behat/behat_block_le_dashboard.php';

    /** @var array[] Core sentences with a twin here: regex => method. */
    const CORE = [
        '/^the following "(?P<type>[^"]*)" exist:$/' => 'the_following_exist',
        '/^the following config values are set as admin:$/' => 'config_values_are_set',
        '/^I log in as "(?P<user>[^"]*)"$/' => 'i_log_in_as',
        '/^I should (?P<not>not )?see "(?P<text>(?:[^"]|\\\\")*)"$/' => 'i_should_see',
    ];

    /** @var array[]|null The context's sentences: [regex, method], parsed once. */
    private static ?array $sentences = null;

    /** @var \DOMXPath|null The rendered dashboard, once a sentence has opened it. */
    protected ?\DOMXPath $dom = null;

    /** @var int|null Who is logged in: the viewer every render is for, whatever the index tasks ran as. */
    protected ?int $viewer = null;

    /** @var string|null The view on screen. */
    protected ?string $view = null;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        // Index writers observe events after commit, and some steps roll a write back: no test transaction.
        $this->preventResetByRollback();
        $this->setAdminUser();
        // Loads the fixtures and steps.
        $this->getDataGenerator()->get_plugin_generator('block_le_dashboard');
        steps::reset();
        local\memo::reset();
        self::forget();
        // As the context's @BeforeScenario hook: the module convenor role exists before enrolments refer to it.
        if (!$DB->record_exists('role', ['shortname' => 'moduleconvenor'])) {
            $roleid = create_role('Module convenor', 'moduleconvenor', '', 'editingteacher');
            set_role_contextlevels($roleid, [CONTEXT_COURSE]);
            role_cap_duplicate($DB->get_record('role', ['shortname' => 'editingteacher']), $roleid);
        }
    }

    /**
     * Runs one sentence of a scenario.
     *
     * @param string $sentence As written in the feature file, with or without its Given/When/Then/And/But.
     * @param array $table The sentence's table, as rows of column => value.
     */
    protected function step(string $sentence, array $table = []): void {
        $text = preg_replace('/^(Given|When|Then|And|But) /', '', trim($sentence));
        [$where, $method, $args] = self::resolve($text);
        if ($where === null) {
            throw new \coding_exception("No twin for the sentence '$text'");
        }
        if ($table) {
            $args[] = $table;
        }
        try {
            if ($where === 'steps') {
                $reflection = new \ReflectionMethod(steps::class, $method);
                $reflection->isStatic() ? steps::$method(...$args) : steps::instance()->$method(...$args);
            } else {
                $this->$method(...$args);
            }
        } catch (unavailable $e) {
            $this->markTestSkipped($e->getMessage());
        } catch (failed $e) {
            $this->fail($e->getMessage() . "\n  at: $text");
        }
        // A sentence that held is an assertion. A debugging() call during it is not a failure, as it is not in Behat.
        $this->resetDebugging();
        $this->addToAssertionCount(1);
    }

    /**
     * Where a sentence runs and with what: ['steps' or 'self', method, args], or [null, null, []] with no twin.
     *
     * @param string $text
     * @return array
     */
    public static function resolve(string $text): array {
        foreach (self::CORE + self::sentences() as $regex => $method) {
            if (!preg_match($regex, $text, $m)) {
                continue;
            }
            // As Behat passes them: the numbered groups, '' where an optional one did not match.
            $args = array_values(array_filter($m, 'is_int', ARRAY_FILTER_USE_KEY));
            array_shift($args);
            if (isset(self::CORE[$regex]) || method_exists(static::class, $method)) {
                return ['self', $method, $args];
            }
            if (method_exists(steps::class, $method)) {
                return ['steps', $method, $args];
            }
            return [null, null, []];
        }
        return [null, null, []];
    }

    /**
     * The context's sentences, from its step annotations.
     *
     * @return string[] regex => method
     */
    private static function sentences(): array {
        global $CFG;
        if (self::$sentences === null) {
            $src = file_get_contents($CFG->dirroot . '/blocks/le_dashboard/' . self::CONTEXT);
            $pattern = '~@(?:Given|When|Then) (/.*?/)\n(?:[^\n]*\n)*?\s*public function (\w+)\(~';
            preg_match_all($pattern, $src, $matches, PREG_SET_ORDER);
            self::$sentences = [];
            foreach ($matches as $match) {
                self::$sentences[$match[1]] = $match[2];
            }
        }
        return self::$sentences;
    }

    // Core sentences.

    /**
     * Core's data generator sentence, for the entities the scenarios use.
     *
     * @param string $type
     * @param array $rows
     */
    protected function the_following_exist(string $type, array $rows): void {
        global $DB;
        $gen = $this->getDataGenerator();
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                // Behat's ##date## syntax.
                if (preg_match('/^##(.*)##$/', $value, $m)) {
                    $row[$key] = strtotime($m[1]);
                }
            }
            switch ($type) {
                case 'users':
                    $gen->create_user($row);
                    break;
                case 'courses':
                    if (isset($row['category'])) {
                        $row['category'] = $DB->get_field('course_categories', 'id', ['idnumber' => $row['category']], MUST_EXIST);
                    }
                    $gen->create_course($row);
                    break;
                case 'course enrolments':
                    $gen->enrol_user(
                        steps::userid($row['user']),
                        steps::course($row['course'])->id,
                        $row['role'],
                        $row['enrol'] ?? 'manual'
                    );
                    break;
                case 'groups':
                    $gen->create_group(['courseid' => steps::course($row['course'])->id] + $row);
                    break;
                case 'group members':
                    $gen->create_group_member(['userid' => steps::userid($row['user']),
                        'groupid' => $DB->get_field('groups', 'id', ['idnumber' => $row['group']], MUST_EXIST)]);
                    break;
                default:
                    throw new \coding_exception("No twin creates '$type'");
            }
        }
    }

    /**
     * Core's config sentence.
     *
     * @param array $rows config, value, plugin
     */
    protected function config_values_are_set(array $rows): void {
        foreach ($rows as $row) {
            set_config($row['config'], $row['value'], $row['plugin'] ?? null);
        }
        self::forget();
    }

    /**
     * Drops what the plugin remembers within a request: each scenario is a request of its own.
     */
    protected static function forget(): void {
        local\guidance::reset_caches();
        local\mitigation::reset_caches();
    }

    /**
     * Core's log-in sentence: the viewer, without opening anything.
     *
     * @param string $username
     */
    protected function i_log_in_as(string $username): void {
        $this->viewer = steps::userid($username);
        $this->setUser($this->viewer);
    }

    /**
     * Core's "I should see": the block is the only thing on the page here.
     *
     * @param string $not
     * @param string $text
     */
    protected function i_should_see(string $not, string $text): void {
        $this->i_should_see_in_the_block($not, str_replace('\\"', '"', $text));
    }

    // Opening the dashboard.

    /**
     * Renders the dashboard as the block does, for the viewer, after the index catches up.
     *
     * @param string|null $view A view to open, or null for the viewer's default.
     * @param int|null $yearid
     */
    protected function render(?string $view = null, ?int $yearid = null): void {
        global $PAGE, $USER;
        $viewer = $this->viewer ?? (int)$USER->id;
        steps::instance()->catch_up();
        $this->setUser($viewer);
        $output = $PAGE->get_renderer('core');
        $dashboard = (new dashboard($viewer))->export_for_template($output);
        $html = $output->render_from_template('block_le_dashboard/dashboard', $dashboard);
        $this->view = $dashboard['activeview'];
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div class="block_le_dashboard">' . $html . '</div>');
        libxml_clear_errors();
        $this->dom = new \DOMXPath($doc);
        // As the browser step does, a view is opened only where its tab is on the page; otherwise the one shown stays.
        $tab = $view === null ? null : $this->dom->query("//button[@data-action='switch-view'][@data-view='$view']")->item(0);
        $open = $tab === null ? $this->view : $view;
        if ($open !== $this->view || $yearid !== null) {
            // As the tab switch and the year picker do: the view fetched by the web service replaces the one shown.
            $fetched = new \DOMDocument();
            $fetched->loadHTML('<?xml encoding="utf-8"?><div>' . get_view::execute($open, $yearid ?? -1)['html'] . '</div>');
            libxml_clear_errors();
            $holder = $this->dom->query("//*[@data-region='ma-view']")->item(0);
            while ($holder->firstChild) {
                $holder->removeChild($holder->firstChild);
            }
            $wrapper = $fetched->documentElement->getElementsByTagName('body')->item(0)->firstChild;
            foreach (iterator_to_array($wrapper->childNodes) as $node) {
                $holder->appendChild($doc->importNode($node, true));
            }
            $this->view = $open;
        }
    }

    /**
     * The viewer logs in and opens the dashboard.
     *
     * @param string $username
     */
    protected function i_log_in_and_view_my_dashboard(string $username): void {
        $this->i_log_in_as($username);
        $this->render();
    }

    /**
     * A view's key, or null for a label no tab carries: the browser step then clicks nothing and the
     * default view stays, so this does the same.
     *
     * @param string $view
     * @return string|null
     */
    protected static function view_or_default(string $view): ?string {
        try {
            return steps::view_key($view);
        } catch (\coding_exception $e) {
            return null;
        }
    }

    /**
     * The dashboard again, after the index catches up.
     */
    protected function i_view_my_dashboard_again(): void {
        $this->render();
    }

    /**
     * The dashboard without the index catching up.
     */
    protected function i_view_my_dashboard(): void {
        $noadhoc = steps::instance()->noadhoc;
        steps::instance()->noadhoc = true;
        try {
            $this->render();
        } finally {
            steps::instance()->noadhoc = $noadhoc;
        }
    }

    /**
     * The viewer logs in and opens one view, for an academic year when one is named.
     *
     * @param string $username
     * @param string $view
     * @param string $year
     */
    protected function i_log_in_and_view(string $username, string $view, string $year = ''): void {
        $this->i_log_in_as($username);
        $this->render(self::view_or_default($view), $year === '' ? null : self::year_id($year));
    }

    /**
     * One view again.
     *
     * @param string $view
     */
    protected function i_view_again(string $view): void {
        $this->render(self::view_or_default($view));
    }

    /**
     * One view for an academic year.
     *
     * @param string $view
     * @param string $year
     */
    protected function i_view_for_year(string $view, string $year): void {
        $this->render(self::view_or_default($view), self::year_id($year));
    }

    /**
     * An academic year's id from the picker's label.
     *
     * @param string $label
     * @return int
     */
    protected static function year_id(string $label): int {
        global $DB;
        if ($label === get_string('allyears', 'block_le_dashboard')) {
            return 0;
        }
        return (int)$DB->get_field(years::TABLE, 'id', ['label' => $label], MUST_EXIST);
    }

    // Reading the rendered dashboard, with the selectors the Behat steps use on the page.

    /**
     * The nodes an XPath finds.
     *
     * @param string $xpath
     * @param \DOMNode|null $context
     * @return \DOMElement[]
     */
    protected function find_all(string $xpath, ?\DOMNode $context = null): array {
        if ($this->dom === null) {
            throw new \coding_exception('The dashboard has not been opened: "I log in as ... and view ..." first');
        }
        return iterator_to_array($this->dom->query($xpath, $context), false);
    }

    /**
     * The first node an XPath finds, or null.
     *
     * @param string $xpath
     * @param \DOMNode|null $context
     * @return \DOMElement|null
     */
    protected function find(string $xpath, ?\DOMNode $context = null): ?\DOMElement {
        return $this->find_all($xpath, $context)[0] ?? null;
    }

    /**
     * An XPath string literal.
     *
     * @param string $text
     * @return string
     */
    protected static function literal(string $text): string {
        if (!str_contains($text, "'")) {
            return "'$text'";
        }
        return 'concat(' . implode(', "\'", ', array_map(fn($part) => "'$part'", explode("'", $text))) . ')';
    }

    /**
     * A node's text as a browser would show it, whitespace collapsed.
     *
     * @param \DOMNode $node
     * @return string
     */
    protected static function text(\DOMNode $node): string {
        return trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * Whether a node has a class.
     *
     * @param \DOMElement $node
     * @param string $class
     * @return bool
     */
    protected static function has_class(\DOMElement $node, string $class): bool {
        return in_array($class, preg_split('/\s+/', trim($node->getAttribute('class'))));
    }

    /**
     * The block's row for an activity.
     *
     * @param string $name
     * @return \DOMElement
     */
    protected function row(string $name): \DOMElement {
        return $this->find("//*[contains(@class, 'block_le_dashboard')]//li[@data-region='ma-row']" .
            "[.//a[contains(@class, 'ma-row-name')][normalize-space(.) = " . self::literal($name) . ']]')
            ?? throw new failed("No row for '$name'");
    }

    /**
     * A row's text without its closed tips.
     *
     * @param \DOMNode $node
     * @return string
     */
    protected function row_text(\DOMNode $node): string {
        $text = '';
        foreach ($node->childNodes as $child) {
            $closedtip = $child instanceof \DOMElement && $child->getAttribute('data-region') === 'ma-tip'
                && $child->hasAttribute('hidden');
            if ($closedtip) {
                continue;
            }
            $text .= $child instanceof \DOMText ? $child->textContent : ' ' . $this->row_text($child) . ' ';
        }
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * A progress bar on a row, or first under Show marking on a finished row.
     *
     * @param string $name
     * @param string $label
     * @return \DOMElement|null
     */
    protected function bar(string $name, string $label): ?\DOMElement {
        $match = "[span[contains(@class, 'ma-bar-label')][normalize-space(.) = " . self::literal($label) . ']]';
        return $this->find(
            "./div[contains(@class, 'ma-row-status')]/div[@data-bar]$match" .
            " | ./ul[contains(@class, 'ma-markers')]/li[contains(@class, 'ma-marker--all')]//div[@data-bar]$match",
            $this->row($name)
        );
    }

    /**
     * A row's date by label.
     *
     * @param \DOMElement $row
     * @param string $name
     * @param string $label
     * @param string $when
     */
    protected function row_date(\DOMElement $row, string $name, string $label, string $when): void {
        $time = $this->find(".//div[@data-date][span[contains(@class, 'ma-row-date-label')][normalize-space(.) = " .
            self::literal($label) . ']]/time', $row) ?? throw new failed("'$name' has no '$label' date");
        $expected = date('Y-m-d', phrases::time($when));
        if (substr($time->getAttribute('datetime'), 0, 10) !== $expected) {
            throw new failed("'$name' $label is {$time->getAttribute('datetime')}, not $expected");
        }
    }

    /**
     * A row's hint icon.
     *
     * @param string $key
     * @param string $name
     * @return \DOMElement
     */
    protected function flag(string $key, string $name): \DOMElement {
        $key = steps::HINTS[$key] ?? $key;
        return $this->find(".//*[@data-hint='$key']", $this->row($name)) ?? throw new failed("'$name' has no $key icon");
    }

    // Twins of the context's assertion steps, by the same names.

    /**
     * A row's presence in a section, and its to-mark count.
     *
     * @param string $name
     * @param string $not
     * @param string $section
     * @param string $tomark
     */
    protected function should_appear_in_section(string $name, string $not, string $section, string $tomark = ''): void {
        $row = $this->find("//*[contains(@class, 'block_le_dashboard')]//section[@data-region='ma-section']" .
            "[.//h3[normalize-space(.) = " . self::literal($section) . ']]' .
            "//li[@data-region='ma-row'][.//a[contains(@class, 'ma-row-name')][normalize-space(.) = " .
            self::literal($name) . ']]');
        if ($not) {
            if ($row) {
                throw new failed("'$name' is in '$section'");
            }
            return;
        }
        if (!$row) {
            throw new failed("'$name' is not in '$section'");
        }
        if ($tomark !== '') {
            $this->should_show_to_mark($name, $tomark);
        }
    }

    /**
     * A row's to-mark count.
     *
     * @param string $name
     * @param string $count
     */
    protected function should_show_to_mark(string $name, string $count): void {
        $row = $this->row($name);
        $actual = $row->hasAttribute('data-tomark') ? $row->getAttribute('data-tomark') : '0';
        if ($actual !== $count) {
            throw new failed("'$name' shows $actual to mark, not $count");
        }
    }

    /**
     * A row's status pill.
     *
     * @param string $name
     * @param string $status
     */
    protected function should_show_the_status(string $name, string $status): void {
        $pill = $this->find('.//*[@data-status]', $this->row($name));
        if (!$pill || self::text($pill) !== $status) {
            throw new failed("'$name' shows '" . ($pill ? self::text($pill) : '') . "', not '$status'");
        }
    }

    /**
     * No status pill.
     *
     * @param string $name
     */
    protected function should_have_no_status_pill(string $name): void {
        if ($pill = $this->find('.//*[@data-status]', $this->row($name))) {
            throw new failed("'$name' has the pill '" . self::text($pill) . "'");
        }
    }

    /**
     * A row's hint.
     *
     * @param string $name
     * @param string $not
     * @param string $hint
     */
    protected function should_have_a_hint(string $name, string $not, string $hint): void {
        $has = (bool)$this->find(".//*[@data-hint='" . steps::HINTS[$hint] . "']", $this->row($name));
        if ($has === (bool)$not) {
            throw new failed("'$name' " . ($has ? 'has' : 'has no') . " $hint hint");
        }
    }

    /**
     * The flags under a row's name, in order.
     *
     * @param string $name
     * @param string $hints
     */
    protected function the_hints_should_be(string $name, string $hints): void {
        $actual = array_map(
            fn($e) => $e->getAttribute('data-hint'),
            $this->find_all(".//*[contains(@class, 'ma-flags')]//*[@data-hint]", $this->row($name))
        );
        $expected = array_map(fn($h) => steps::HINTS[trim($h)], explode(',', $hints));
        if ($actual !== $expected) {
            throw new failed("'$name' has the hints " . implode(', ', $actual));
        }
    }

    /**
     * A hint's title.
     *
     * @param string $key
     * @param string $name
     * @param string $title
     */
    protected function the_hint_should_be_titled(string $key, string $name, string $title): void {
        $hint = $this->find(".//*[@data-hint='$key']", $this->row($name)) ?? throw new failed("'$name' has no $key hint");
        if ($hint->getAttribute('aria-label') !== $title) {
            throw new failed("The $key hint of '$name' is titled '{$hint->getAttribute('aria-label')}'");
        }
    }

    /**
     * A flag's count.
     *
     * @param string $name
     * @param string $key
     * @param string $n
     */
    protected function should_have_a_flag_showing(string $name, string $key, string $n = ''): void {
        $count = $this->find(".//*[contains(@class, 'ma-flag-count')]", $this->flag($key, $name));
        $shown = $count ? self::text($count) : '';
        if ($shown !== $n) {
            throw new failed("The $key flag of '$name' shows '$shown'");
        }
    }

    /**
     * A flag's label.
     *
     * @param string $key
     * @param string $name
     * @param string $label
     */
    protected function the_flag_should_be_labelled(string $key, string $name, string $label): void {
        $shown = $this->flag($key, $name)->getAttribute('aria-label');
        if ($shown !== $label) {
            throw new failed("The $key flag of '$name' is labelled '$shown'");
        }
    }

    /**
     * Text on a row, or a date by label.
     *
     * @param string $name
     * @param string $not
     * @param string $text
     * @param string $when
     */
    protected function row_should_show(string $name, string $not, string $text, string $when = ''): void {
        $row = $this->row($name);
        if ($when === '') {
            $shown = $this->row_text($row);
            if (str_contains($shown, $text) === (bool)$not) {
                throw new failed("'$name' " . ($not ? 'shows' : 'does not show') . " '$text': '$shown'");
            }
            return;
        }
        $this->row_date($row, $name, $text, $when);
    }

    /**
     * A row's due date, labelled Due or, under an extension, Extended deadline.
     *
     * @param string $name
     * @param string $when
     */
    protected function should_show_the_due_date(string $name, string $when): void {
        // A student's deadline under an extension is labelled as the extended one (CS5).
        $row = $this->row($name);
        $label = get_string('date_due', 'block_le_dashboard');
        $extended = get_string('date_extendeddeadline', 'block_le_dashboard');
        try {
            $this->row_date($row, $name, $label, $when);
        } catch (\Throwable $e) {
            try {
                $this->row_date($row, $name, $extended, $when);
            } catch (\Throwable $other) {
                throw $e;
            }
        }
    }

    /**
     * A row's date labels, in order.
     *
     * @param string $name
     * @param string $labels
     */
    protected function the_dates_should_be(string $name, string $labels): void {
        $actual = array_map(
            [self::class, 'text'],
            $this->find_all(
                ".//*[contains(@class, 'ma-row-dates')]//*[@data-date]//*[contains(@class, 'ma-row-date-label')]",
                $this->row($name)
            )
        );
        $expected = array_map('trim', explode(',', $labels));
        if ($actual !== $expected) {
            throw new failed("'$name' shows its dates as \"" . implode(', ', $actual) . '"');
        }
    }

    /**
     * A date's text.
     *
     * @param string $label
     * @param string $name
     * @param string $text
     */
    protected function the_date_should_read(string $label, string $name, string $text): void {
        $time = $this->find(".//div[@data-date][span[contains(@class, 'ma-row-date-label')][normalize-space(.) = " .
            self::literal($label) . ']]//time', $this->row($name)) ?? throw new failed("'$name' has no '$label' date");
        if (self::text($time) !== $text) {
            throw new failed("'$name' $label reads '" . self::text($time) . "'");
        }
    }

    /**
     * An action button on a row.
     *
     * @param string $name
     * @param string $not
     * @param string $label
     */
    protected function row_should_have_button(string $name, string $not, string $label): void {
        $has = false;
        $buttons = $this->find_all(".//a[contains(@class, 'ma-btn')] | .//button[contains(@class, 'ma-btn')]", $this->row($name));
        foreach ($buttons as $button) {
            $hidden = $this->find(".//*[contains(@class, 'accesshide')]", $button);
            $shown = trim(str_replace($hidden ? $hidden->textContent : '', '', $button->textContent));
            $has = $has || $shown === $label;
        }
        if ($has === (bool)$not) {
            throw new failed("'$name' " . ($not ? 'has' : 'has no') . " '$label' button");
        }
    }

    /**
     * A progress bar's value.
     *
     * @param string $name
     * @param string $label
     * @param string $value
     * @param string $max
     */
    protected function should_show_progress_bar(string $name, string $label, string $value, string $max = ''): void {
        $bar = $this->bar($name, $label) ?? throw new failed("'$name' has no '$label' bar");
        if ($bar->getAttribute('data-value') !== $value || ($max !== '' && $bar->getAttribute('data-max') !== $max)) {
            throw new failed("'$name' $label bar is {$bar->getAttribute('data-value')} of {$bar->getAttribute('data-max')}");
        }
    }

    /**
     * No such bar.
     *
     * @param string $name
     * @param string $label
     */
    protected function should_not_show_progress_bar(string $name, string $label): void {
        if ($this->bar($name, $label)) {
            throw new failed("'$name' has a '$label' bar");
        }
    }

    /**
     * The bars on a row, in order.
     *
     * @param string $name
     * @param string $labels
     */
    protected function should_show_progress_bars(string $name, string $labels): void {
        $actual = array_map([self::class, 'text'], $this->find_all(
            "./div[contains(@class, 'ma-row-status')]/div[@data-bar]/span[contains(@class, 'ma-bar-label')]",
            $this->row($name)
        ));
        $expected = array_map('trim', explode(',', $labels));
        if ($actual !== $expected) {
            throw new failed("'$name' shows bars " . implode(', ', $actual));
        }
    }

    /**
     * No bars at all.
     *
     * @param string $name
     */
    protected function should_not_show_any_bars(string $name): void {
        if ($this->find("./div[contains(@class, 'ma-row-status')]/div[@data-bar]", $this->row($name))) {
            throw new failed("'$name' shows progress bars");
        }
    }

    /**
     * A finished row's grading status.
     *
     * @param string $name
     * @param string $text
     */
    protected function should_show_grading_status(string $name, string $text): void {
        $status = $this->find('.//*[@data-grading]', $this->row($name));
        $actual = $status ? self::text($status) : null;
        if ($actual !== $text) {
            throw new failed("'$name' shows the grading status '$actual'");
        }
    }

    /**
     * No grading status.
     *
     * @param string $name
     */
    protected function should_not_show_grading_status(string $name): void {
        if ($this->find('.//*[@data-grading]', $this->row($name))) {
            throw new failed("'$name' shows a grading status");
        }
    }

    /**
     * Markers listed on a row.
     *
     * @param string $name
     * @param string $m1
     * @param string $m2
     */
    protected function should_list_markers(string $name, string $m1, string $m2 = ''): void {
        $names = array_map([self::class, 'text'], $this->find_all(".//*[contains(@class, 'ma-marker-name')]", $this->row($name)));
        foreach (array_filter([$m1, $m2], 'strlen') as $marker) {
            if (!in_array($marker, $names)) {
                throw new failed("'$name' does not list $marker");
            }
        }
    }

    /**
     * Whether a marker's name links to their email.
     *
     * @param string $marker
     * @param string $name
     * @param string $not
     */
    protected function the_marker_should_link_to_their_email(string $marker, string $name, string $not = ''): void {
        foreach ($this->find_all(".//*[contains(@class, 'ma-marker-name')]", $this->row($name)) as $el) {
            if (self::text($el) !== $marker) {
                continue;
            }
            $links = $el->tagName === 'a' && str_starts_with($el->getAttribute('href'), 'mailto:');
            if ($links === (bool)$not) {
                throw new failed("'$marker' " . ($links ? 'links' : 'does not link') . ' to their email');
            }
            return;
        }
        throw new failed("'$name' lists no marker '$marker'");
    }

    /**
     * A summary box's value.
     *
     * @param string $label
     * @param string $value
     */
    protected function the_summary_box_should_show(string $label, string $value): void {
        foreach ($this->find_all("//*[contains(@class, 'block_le_dashboard')]//*[@data-region='ma-box']") as $box) {
            if (self::text($this->find(".//*[contains(@class, 'ma-box-label')]", $box)) === $label) {
                $shown = self::text($this->find(".//*[contains(@class, 'ma-box-value')]", $box));
                if ($shown !== $value) {
                    throw new failed("The '$label' box shows '$shown'");
                }
                return;
            }
        }
        throw new failed("No '$label' summary box");
    }

    /**
     * A summary box's count.
     *
     * @param string $box
     * @param string $count
     */
    protected function the_summary_count_should_be(string $box, string $count): void {
        $node = $this->find("//*[contains(@class, 'block_le_dashboard')]//div[@data-region='ma-box']" .
            "[div/div[contains(@class, 'ma-box-label')][normalize-space(.) = " . self::literal($box) . ']]' .
            "//div[@data-region='ma-box-value']") ?? throw new failed("No '$box' summary");
        if (self::text($node) !== $count) {
            throw new failed("'$box' count is " . self::text($node) . ", not $count");
        }
    }

    /**
     * The rows of a section, in order.
     *
     * @param string $section
     * @param string $names
     */
    protected function the_section_should_list(string $section, string $names): void {
        $found = array_map([self::class, 'text'], $this->find_all("//*[contains(@class, 'block_le_dashboard')]" .
            "//section[@data-region='ma-section'][.//h3[normalize-space(.) = " . self::literal($section) . ']]' .
            "//a[contains(@class, 'ma-row-name')]"));
        $expected = array_map('trim', explode(',', $names));
        if ($found !== $expected) {
            throw new failed("'$section' lists " . implode(', ', $found));
        }
    }

    /**
     * The sections, in order.
     *
     * @param string $titles
     */
    protected function the_sections_should_be_in_the_order(string $titles): void {
        $actual = array_map([self::class, 'text'], $this->find_all("//*[contains(@class, 'block_le_dashboard')]" .
            "//*[@data-region='ma-view-root']//*[@data-region='ma-section']//*[contains(@class, 'ma-section-title')]"));
        $expected = array_map('trim', explode(',', $titles));
        if ($actual !== $expected) {
            throw new failed('Sections are in the order "' . implode(', ', $actual) . '"');
        }
    }

    /**
     * A modifier on a row's activity icon.
     *
     * @param string $name
     * @param string $not
     * @param string $state
     */
    protected function the_activity_icon_should_have_modifier(string $name, string $not, string $state): void {
        $has = (bool)$this->find(".//*[contains(@class, 'ma-row-overlay--$state')]", $this->row($name));
        if ($has === ($not !== '')) {
            throw new failed("The icon of '$name' " . ($has ? 'has' : 'has no') . " $state modifier");
        }
    }

    /**
     * No modifiers on a row's activity icon.
     *
     * @param string $name
     */
    protected function should_have_no_modifiers(string $name): void {
        if ($this->find(".//*[contains(@class, 'ma-row-overlay')]", $this->row($name))) {
            throw new failed("The icon of '$name' has modifiers");
        }
    }

    /**
     * Text anywhere in the block.
     *
     * @param string $not
     * @param string $text
     */
    protected function i_should_see_in_the_block(string $not, string $text): void {
        $shown = self::text($this->find("//*[contains(@class, 'block_le_dashboard')]"));
        if (str_contains($shown, $text) === (bool)$not) {
            throw new failed('The block ' . ($not ? 'shows' : 'does not show') . " '$text'");
        }
    }

    /**
     * A link in the block.
     *
     * @param string $text
     * @param string $url
     */
    protected function i_should_see_linking_to(string $text, string $url): void {
        foreach ($this->find_all("//*[contains(@class, 'block_le_dashboard')]//a") as $link) {
            if (self::text($link) === $text && $link->getAttribute('href') === $url) {
                return;
            }
        }
        throw new failed("No '$text' link to '$url' in the block");
    }

    /**
     * The freshness line says the data is recent.
     *
     * @param string $not
     */
    protected function i_should_see_updated(string $not = ''): void {
        $node = $this->find("//*[contains(@class, 'block_le_dashboard')]//*[contains(@class, 'ma-freshness-text')]")
            ?? throw new failed('No freshness line');
        $recent = (bool)preg_match('/^Updated (just now|\d+ min ago)$/', self::text($node));
        if ($recent === (bool)$not) {
            throw new failed("Freshness says '" . self::text($node) . "'");
        }
    }

    /**
     * Whether a view can be opened: a tab, or the only view.
     *
     * @param string $not
     * @param string $view
     */
    protected function i_should_be_able_to_open(string $not, string $view): void {
        $key = steps::view_key($view);
        $can = $this->find("//*[contains(@class, 'ma-tabs')]//button[@data-view='$key']") || $this->view === $key;
        if ($not && $can) {
            throw new failed("The '$view' can be opened");
        }
        if (!$not && !$can) {
            throw new failed("The '$view' can't be opened");
        }
    }

    /**
     * The view on screen.
     *
     * @param string $view
     */
    protected function the_view_should_be_open(string $view): void {
        $root = $this->find("//*[@data-region='ma-view-root']");
        $open = $root ? $root->getAttribute('data-view') : null;
        if ($open !== steps::view_key($view)) {
            throw new failed("The '$view' is not open (open: '$open')");
        }
    }

    /**
     * A row's name links to its activity.
     *
     * @param string $name
     */
    protected function the_name_links_to_its_activity(string $name): void {
        $link = $this->find(".//a[contains(@class, 'ma-row-name')]", $this->row($name));
        if (!$link || $link->getAttribute('href') !== steps::instance()->cm($name)->url->out(false)) {
            throw new failed("The name of '$name' doesn't link to its activity");
        }
    }

    /**
     * A row's course links to its page.
     *
     * @param string $name
     * @param string $shortname
     */
    protected function the_course_links_to_its_page(string $name, string $shortname): void {
        $url = (new \moodle_url('/course/view.php', ['id' => steps::course($shortname)->id]))->out(false);
        $link = $this->find(".//a[contains(@class, 'ma-row-course')]", $this->row($name));
        if (!$link || $link->getAttribute('href') !== $url) {
            throw new failed("The course of '$name' doesn't link to $url");
        }
    }

    /**
     * The admin's notice, above the dashboard.
     *
     * @param string $text
     */
    protected function the_block_should_show_the_notice(string $text): void {
        $notice = $this->find("//*[contains(@class, 'block_le_dashboard')]//*[@data-region='ma-notice']")
            ?? throw new failed('The block shows no notice');
        if (!str_contains(self::text($notice), $text)) {
            throw new failed("The notice says '" . self::text($notice) . "', not '$text'");
        }
        if (!$this->find("following-sibling::*[contains(@class, 'ma-tabs') or @data-region='ma-view']", $notice)) {
            throw new failed('The notice is not above the dashboard');
        }
    }

    /**
     * No notice.
     */
    protected function the_block_should_show_no_notice(): void {
        if ($this->find("//*[contains(@class, 'block_le_dashboard')]//*[@data-region='ma-notice']")) {
            throw new failed('The block shows a notice');
        }
    }
}
