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

namespace block_le_dashboard\local\index;

use block_le_dashboard\local\config;
use block_le_dashboard\local\testing;

/**
 * A pass of the index over its courses that stops at a time limit and carries on where it stopped
 * (Pierre: "gracefully fail after a while, and pickup where it left off").
 *
 * Two kinds: the first fill (every course, after install, Rebuild or a setting change) and the nightly
 * check (live courses, catching missed events). Each saves how far it has got after every course, so a
 * run that stops at its limit or dies carries on from there. One lock stops two runs working at once.
 *
 * @package    block_le_dashboard
 * @copyright  2026 Pierre Camaly de Brosses - University of Exeter, based on CoSector's my_assessment dashboard
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pass {
    /** The first fill. */
    const FILL = 'fill';
    /** The nightly check. */
    const NIGHTLY = 'nightly';
    /** The nightly check works in hourly runs from 02:00 until 06:00, server time. */
    const NIGHT = [2, 6];

    /** How a run ended: the pass is complete. */
    const DONE = 'done';
    /** How a run ended: it reached its time limit. */
    const LIMIT = 'limit';
    /** How a run ended: a Rebuild or setting change started the pass again meanwhile. */
    const RESTARTED = 'restarted';
    /** How a run ended: another run holds the lock. */
    const LOCKED = 'locked';

    /**
     * A pass's saved progress. gen is the generation it belongs to: a restart bumps the generation, so
     * progress saved for an older one counts as nothing done.
     *
     * @param string $kind
     * @return array gen, open (started and not complete), after (last course id done), done, total, started,
     *     stopped, reason (how the last run ended), finished (when the last complete pass ended)
     */
    public static function state(string $kind): array {
        global $DB;
        // Read past the config cache: another process may have changed it during a long run.
        $raw = $DB->get_field('config_plugins', 'value', ['plugin' => 'block_le_dashboard', 'name' => 'pass_' . $kind]);
        $state = ($raw ? json_decode($raw, true) : null) ?: [];
        $state += ['gen' => 0, 'open' => false, 'after' => 0, 'done' => 0, 'total' => 0, 'started' => 0,
            'stopped' => 0, 'reason' => '', 'finished' => 0];
        if ($state['gen'] !== self::generation($kind)) {
            // A restart since: only when the last complete pass ended still holds.
            $state = ['gen' => self::generation($kind), 'open' => false, 'after' => 0, 'done' => 0, 'total' => 0,
                'started' => 0, 'stopped' => 0, 'reason' => '', 'finished' => $state['finished'], 'stale' => true];
        }
        return $state;
    }

    /**
     * Whether the pass's current generation is complete: no restart since its last run ended.
     *
     * @param string $kind
     * @return bool
     */
    public static function complete(string $kind): bool {
        $state = self::state($kind);
        return empty($state['stale']) && !$state['open'] && $state['reason'] === self::DONE;
    }

    /**
     * Starts a pass again from the first course, at its next run. A run in progress stops after its course.
     *
     * @param string $kind
     */
    public static function restart(string $kind): void {
        set_config('passgen_' . $kind, self::generation($kind) + 1, 'block_le_dashboard');
    }

    /**
     * The pass's current generation.
     *
     * @param string $kind
     * @return int
     */
    protected static function generation(string $kind): int {
        global $DB;
        return (int)$DB->get_field('config_plugins', 'value', ['plugin' => 'block_le_dashboard', 'name' => 'passgen_' . $kind]);
    }

    /**
     * Saves a pass's progress.
     *
     * @param string $kind
     * @param array $state
     */
    protected static function save(string $kind, array $state): void {
        unset($state['stale']);
        set_config('pass_' . $kind, json_encode($state), 'block_le_dashboard');
    }

    /**
     * Whether the nightly check should work in a run at this time: inside the night hours, carrying on an
     * unfinished pass or starting the night's one.
     *
     * @param int $now
     * @return bool
     */
    public static function night(int $now): bool {
        $time = (new \DateTime('@' . $now))->setTimezone(\core_date::get_server_timezone_object());
        $hour = (int)$time->format('G');
        if ($hour < self::NIGHT[0] || $hour >= self::NIGHT[1]) {
            return false;
        }
        $state = self::state(self::NIGHTLY);
        $tonight = (clone $time)->setTime(self::NIGHT[0], 0)->getTimestamp();
        return $state['open'] || $state['started'] < $tonight;
    }

    /**
     * When a run that starts now must stop: the time limit setting (minutes, 0 for none).
     *
     * @return int A timestamp, 0 for no limit.
     */
    public static function deadline(): int {
        $minutes = config::number('indexminutes');
        return $minutes ? time() + $minutes * MINSECS : 0;
    }

    /**
     * Runs a pass until it is complete or reaches its limit.
     *
     * @param string $kind
     * @param callable|null $trace Receives progress lines.
     * @param int $deadline When to stop, 0 for no limit.
     * @param int $wait Seconds to wait for another run's lock.
     * @param int $now The time a nightly pass counts as started at.
     * @param int $maxcourses Tests: courses to do before stopping as at the limit, 0 for no such limit.
     * @return string How the run ended: DONE, LIMIT, RESTARTED or LOCKED.
     */
    public static function run(
        string $kind,
        ?callable $trace = null,
        int $deadline = 0,
        int $wait = 0,
        int $now = 0,
        int $maxcourses = 0
    ): string {
        $lock = \core\lock\lock_config::get_lock_factory('block_le_dashboard_index')->get_lock('pass', $wait);
        if (!$lock) {
            if ($trace) {
                $trace('Another index run is working; this one leaves it to that.');
            }
            return self::LOCKED;
        }
        try {
            $state = self::state($kind);
            if (!$state['open']) {
                if ($kind === self::FILL && empty($state['stale']) && $state['finished']) {
                    // This generation's fill is complete already.
                    return self::DONE;
                }
                $state = ['open' => true, 'after' => 0, 'started' => $now ?: time(), 'reason' => ''] + $state;
            }
            $gen = $state['gen'];
            $indexer = new indexer();
            $courses = $indexer->pass_courses($kind === self::FILL);
            $state['total'] = count($courses);
            $state['done'] = count(array_filter($courses, fn($id) => $id <= $state['after']));
            self::save($kind, $state);
            $limit = $maxcourses ?: self::course_limit();
            $count = 0;
            foreach ($courses as $courseid) {
                if ($courseid <= $state['after']) {
                    continue;
                }
                if (($deadline && time() >= $deadline) || ($limit && $count >= $limit)) {
                    $state['stopped'] = time();
                    $state['reason'] = self::LIMIT;
                    self::save($kind, $state);
                    if ($trace) {
                        $trace("Stopped at the time limit after {$state['done']} of {$state['total']} courses; " .
                            'the next run carries on.');
                    }
                    return self::LIMIT;
                }
                $indexer->reconcile_course($courseid, $trace);
                $count++;
                if (self::generation($kind) !== $gen) {
                    // Rebuild or a setting change: the next run starts again from the first course.
                    return self::RESTARTED;
                }
                $state['after'] = $courseid;
                $state['done']++;
                self::save($kind, $state);
            }
            $state['open'] = false;
            $state['stopped'] = $state['finished'] = time();
            $state['reason'] = self::DONE;
            self::save($kind, $state);
            return self::DONE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Tests only: how many courses a run does before it counts as reaching its limit.
     *
     * @return int 0 for no such limit.
     */
    protected static function course_limit(): int {
        return testing::course_limit();
    }

    /**
     * Lines for the Index page: how far each pass has got.
     *
     * @param bool $filling Whether a first fill is pending.
     * @return string[]
     */
    public static function progress(bool $filling): array {
        $lines = [];
        $fill = self::state(self::FILL);
        if ($filling && $fill['total']) {
            $lines[] = get_string('indexprogress_fill', 'block_le_dashboard', (object)$fill);
            if ($fill['open'] && $fill['reason'] === self::LIMIT) {
                $lines[] = get_string('indexprogress_limit', 'block_le_dashboard');
            }
        }
        $nightly = self::state(self::NIGHTLY);
        if ($nightly['open']) {
            $lines[] = get_string('indexprogress_nightly', 'block_le_dashboard', (object)$nightly);
            if ($nightly['reason'] === self::LIMIT) {
                $lines[] = get_string('indexprogress_limit', 'block_le_dashboard');
            }
        }
        if ($nightly['finished']) {
            $lines[] = get_string('indexprogress_finished', 'block_le_dashboard', userdate($nightly['finished']));
        }
        return $lines;
    }
}
