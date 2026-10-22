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

use block_le_dashboard\local\mitigation;

/**
 * Mitigate menu items from block_rhb's links.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_le_dashboard\local\mitigation
 */
final class mitigation_test extends \advanced_testcase {
    /**
     * rc6's test for a real link: an anchor with an href.
     */
    public function test_has_link(): void {
        $this->assertTrue(mitigation::has_link('<a class="btn" href="https://example.com/x">Apply</a>'));
        $this->assertTrue(mitigation::has_link("<A HREF='/x'>Apply</A>"));
        $this->assertFalse(mitigation::has_link('<span>Limit reached</span>'));
        $this->assertFalse(mitigation::has_link('<a name="x">Anchor</a>'));
        $this->assertFalse(mitigation::has_link(''));
    }

    /**
     * Links for deleting a self-certification read "Delete mitigation", found by their text or address as
     * rc6 did; other links and the rest of the HTML are left as they are.
     */
    public function test_delete_label(): void {
        $this->resetAfterTest();
        $this->assertSame(
            '<div><a href="/rhb/delete.php?id=3" class="btn" data-action="mitigate-delete">Delete mitigation</a></div>',
            mitigation::delete_label('<div><a href="/rhb/delete.php?id=3" class="btn">Remove</a></div>')
        );
        $this->assertSame(
            '<a href="/rhb/x.php?id=3" data-action="mitigate-delete">Delete mitigation</a>',
            mitigation::delete_label('<a href="/rhb/x.php?id=3"><i></i> Delete self-cert</a>')
        );
        $plain = '<a href="/rhb/selfcert.php?id=3">Self-certify</a> <span>2 left</span>';
        $this->assertSame($plain, mitigation::delete_label($plain));
    }

    /**
     * Without block_rhb there is no Mitigate.
     */
    public function test_links_without_block_rhb(): void {
        global $CFG;
        if (file_exists($CFG->dirroot . '/blocks/rhb/lib.php')) {
            $this->markTestSkipped('block_rhb is installed');
        }
        mitigation::reset_caches();
        $this->assertNull(mitigation::links('assign', 1));
        $this->assertNull(mitigation::links('coursework', 1));
    }

    /** block_rhb's request form link, as the stand-in gives it. */
    const SRM = '<a href="https://example.com/blocks/rhb/srm.php">Request mitigating circumstances</a>';

    /** block_rhb's self-certification link. */
    const SELFCERT = '<a href="https://example.com/blocks/rhb/selfcert.php?id=3">Self-certify</a>';

    /**
     * The Mitigate menu of option A (mitigate-menu-explains.feature): every case of the scope.
     *
     * @dataProvider menu_provider
     * @param bool $coursework
     * @param string $selfcert
     * @param string $srm
     * @param array|null $count
     * @param int|null $here
     * @param array|null $expected Each item as [kind, text]: live, delete, info (text: the reason), divider, html;
     *     a link the reason line describes is marked " [described]". Or null.
     */
    public function test_menu(
        bool $coursework,
        string $selfcert,
        string $srm,
        ?array $count,
        ?int $here,
        ?array $expected
    ): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Australia/Perth']));
        $menu = mitigation::menu($coursework, $selfcert, $srm, $count, $here);
        if ($expected === null) {
            $this->assertNull($menu);
            return;
        }
        $this->assertNotNull($menu);
        $got = array_map(function (array $item): array {
            $described = !empty($item['describedby']) ? ' [described]' : '';
            if (!empty($item['delete'])) {
                return ['delete', $item['label'] . ' ' . $item['url'] . $described];
            }
            if (!empty($item['divider'])) {
                return ['divider', ''];
            }
            if (!empty($item['info'])) {
                // A date in the reason is marked up for the page (item 8), the same text around it.
                if ($item['reasondated']) {
                    $dated = $item['reasondated'];
                    $this->assertSame($item['reason'], $dated['before'] . $dated['date'] . $dated['after']);
                }
                return ['info', $item['reason']];
            }
            if (isset($item['url'])) {
                $this->assertTrue($item['live']);
                return ['live', $item['label'] . (isset($item['note']) ? ' (' . $item['note'] . ')' : '') . $described];
            }
            return ['html', strip_tags($item['html'])];
        }, $menu['items']);
        $this->assertSame($expected, $got);
    }

    /**
     * Cases for test_menu.
     *
     * @return array
     */
    public static function menu_provider(): array {
        $request = ['live', 'Request formal mitigation'];
        $described = ['live', 'Request formal mitigation [described]'];
        $divider = ['divider', ''];
        $stillrequest = ' You can still request formal mitigation.';
        return [
            'a self-cert left: the count, both under our labels' => [true, self::SELFCERT, self::SRM,
                ['used' => 1, 'max' => 3], null,
                [['live', 'Self-certify an extension (2 of 3 left)'], $divider, $request]],
            'no limit: no count' => [true, self::SELFCERT, self::SRM, ['used' => 5, 'max' => null], null,
                [['live', 'Self-certify an extension'], $divider, $request]],
            'all used: the reason, no item' => [true, '', self::SRM, ['used' => 3, 'max' => 3], null,
                [['info', 'You have used all 3 of your self-certified extensions.' . $stillrequest], $divider,
                    $described]],
            'already self-certified here: the new deadline, and Delete mitigation' => [true,
                '<a href="https://example.com/blocks/rhb/delete.php?id=3">Remove self-certification</a>', self::SRM,
                ['used' => 1, 'max' => 3], (new \DateTime('2026-10-17 13:47 Australia/Perth'))->getTimestamp(),
                [['info', 'You already self-certified an extension for this assessment. ' .
                    'Your deadline is now 17 Oct 2026, 13:47.'],
                    ['delete', 'Delete mitigation https://example.com/blocks/rhb/delete.php?id=3 [described]'],
                    $divider, $request]],
            'a delete link without the deadline' => [true,
                '<a href="https://example.com/blocks/rhb/delete.php?id=3">Delete</a>', self::SRM, null, null,
                [['info', 'You already self-certified an extension for this assessment.'],
                    ['delete', 'Delete mitigation https://example.com/blocks/rhb/delete.php?id=3 [described]'],
                    $divider, $request]],
            'Delete mitigation without a request form: no divider' => [true,
                '<a href="https://example.com/blocks/rhb/delete.php?id=3">Delete</a>', '', null, null,
                [['info', 'You already self-certified an extension for this assessment.'],
                    ['delete', 'Delete mitigation https://example.com/blocks/rhb/delete.php?id=3 [described]']]],
            'no self-cert link, reason unknown' => [true, '<span>Not available</span>', self::SRM, ['used' => 0, 'max' => 3],
                null, [['info', "Self-certification isn't available for this coursework." . $stillrequest], $divider,
                    $described]],
            "rhb's answer wins over the count" => [true, self::SELFCERT, self::SRM, ['used' => 3, 'max' => 3], null,
                [['live', 'Self-certify an extension'], $divider, $request]],
            'self-cert without a request form' => [true, self::SELFCERT, '<span>Requests are closed</span>', null, null,
                [['live', 'Self-certify an extension']]],
            'no self-cert link and no request form: no Mitigate' => [true, '', '', ['used' => 3, 'max' => 3], null, null],
            'no live link at all: no Mitigate' => [true, '', '<span>Requests are closed</span>', null, null, null],
            'a quiz: formal mitigation only' => [false, '', self::SRM, ['used' => 0, 'max' => 3], null, [$request]],
            'a quiz without a request form: no Mitigate' => [false, '', '', null, null, null],
            "an address rhb wraps oddly: its own HTML" => [true, '<a href="javascript:void(0)">Self-certify</a>',
                self::SRM, null, null, [['html', 'Self-certify'], $divider, $request]],
        ];
    }

    /**
     * C4 (beta29): on a coursework schema whose mitigations table lacks the type and selfcert columns, the
     * self-certification count copes as the coursework adapter's extensions() rule does, instead of throwing:
     * without a selfcert column no extension is known to be self-certified, so none is used.
     */
    public function test_selfcert_copes_with_older_coursework_schema(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('coursework_mitigations')) {
            $this->markTestSkipped('mod_coursework is installed: its own schema applies');
        }
        $table = new \xmldb_table('coursework_mitigations');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        foreach (['allocatableid', 'extended_deadline', 'timecreated'] as $field) {
            $table->add_field($field, XMLDB_TYPE_INTEGER, '10');
        }
        $table->add_field('allocatabletype', XMLDB_TYPE_CHAR, '100');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_table($table);
        try {
            $user = $this->getDataGenerator()->create_user();
            $DB->insert_record('coursework_mitigations', (object)['allocatableid' => $user->id, 'allocatabletype' => 'user',
                'extended_deadline' => time() + DAYSECS, 'timecreated' => time()]);
            set_config('selfcertmax', 3, 'block_rhb');
            $this->assertSame(['used' => 0, 'max' => 3], mitigation::selfcert((int)$user->id));
        } finally {
            $dbman->drop_table($table);
        }
    }
}
