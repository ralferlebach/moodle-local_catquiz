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

namespace local_catquiz\local;

use cache;
use local_catquiz\catscale;

/**
 * Revisions of the runtime item pools (issue #146): a change invalidates the pools it touches, not all of them.
 *
 * A runtime pool (catscale::get_testitems(), cache "runtimepool") is the same for everybody who plays a scale in a CAT
 * context. Before, every change of an item, a parameter or a scale anywhere purged every pool of the site (the
 * events changesintestitems, changesinitemparams, changesincatscales): a recalibration of one context rebuilt the
 * pools of all others, and a question that turned out to be hidden in the middle of an attempt purged them all.
 *
 * Now the key of a pool carries revisions (catscale::pool_key()):
 * - "r<root>": the root scale of the pool - its items, its scales, the question bank behind its items;
 * - "c<context>": the CAT context - everything in it (a context copied or filled anew);
 * - "cr<context>_<root>": the parameters of the items of one root scale in one context;
 * - "g": everything - a change whose scope is unknown;
 * - and the scales of the pool tree themselves (catscale::pool_key() hashes them): a scale moved to another root or
 *   deleted changes the trees of both roots at once.
 * A revision is a random token, not a counter: a revision that falls out of its cache comes back as a new token -
 * a pool is rebuilt, never an older one served again. A change sets a new token: the pools under the old key are no
 * longer read, and the next read builds the pool once and drops the one it replaces (catscale::get_testitems()), so
 * a store holds at most the current pool and the one before per pool key.
 *
 * Who changes items, parameters or scales calls the matching method. The events above still purge the caches of
 * the CAT manager and the counts - but the plugin's own changes no longer reach the pools through them. Code that
 * only triggers the events (tests, other plugins) still invalidates every pool: the revisions listen to them.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pool_revision {
    /** @var string The cache of the revisions. */
    public const CACHE = 'runtimepoolrevision';

    /** @var string Every pool. */
    private const GLOBAL = 'g';

    /**
     * The revision of the pools of a scale in a CAT context.
     *
     * @param int $contextid
     * @param int $rootid The root scale of the pool's scale.
     * @return string
     */
    public static function of(int $contextid, int $rootid): string {
        $cache = self::cache();
        $keys = [self::GLOBAL, 'c' . $contextid, 'r' . $rootid, 'cr' . $contextid . '_' . $rootid];
        $tokens = $cache->get_many($keys);
        $missing = [];
        foreach ($keys as $key) {
            if (($tokens[$key] ?? false) === false) {
                $tokens[$key] = self::new_token();
                $missing[$key] = $tokens[$key];
            }
        }
        if ($missing) {
            $cache->set_many($missing);
        }
        return implode('.', array_map(fn($key) => $tokens[$key], $keys));
    }

    /**
     * The items or the structure of a scale changed: the pools of its root scale, in every context.
     *
     * @param int $scaleid Any scale; its root is meant.
     */
    public static function scale_changed(int $scaleid): void {
        if ($scaleid <= 0) {
            return;
        }
        $root = self::root_of($scaleid);
        self::bump(['r' . $root, 'r' . $scaleid]);
    }

    /**
     * Everything of a CAT context changed: the pools of every scale in it.
     *
     * @param int $contextid
     */
    public static function context_changed(int $contextid): void {
        if ($contextid > 0) {
            self::bump(['c' . $contextid]);
        }
    }

    /**
     * Parameters of items changed in a CAT context: the pools of the root scales of those items there.
     *
     * @param int $contextid
     * @param int[] $scaleids The scales of the items (any scale; its root is meant). Empty: the whole context.
     */
    public static function params_changed(int $contextid, array $scaleids): void {
        if ($contextid <= 0) {
            return;
        }
        if (!$scaleids) {
            self::context_changed($contextid);
            return;
        }
        $keys = [];
        foreach (array_unique(array_map('intval', $scaleids)) as $scaleid) {
            if ($scaleid > 0) {
                $keys[] = 'cr' . $contextid . '_' . self::root_of($scaleid);
            }
        }
        self::bump($keys);
    }

    /**
     * Parameters of these items changed in a CAT context: their root scales, found in one query.
     *
     * @param int $contextid
     * @param int[] $itemids local_catquiz_items.id
     */
    public static function params_of_items_changed(int $contextid, array $itemids): void {
        global $DB;
        $itemids = array_values(array_unique(array_filter(array_map('intval', $itemids))));
        if (!$itemids) {
            self::context_changed($contextid);
            return;
        }
        $scales = [];
        foreach (array_chunk($itemids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'prvi');
            $scales = array_merge($scales, $DB->get_fieldset_sql(
                "SELECT DISTINCT catscaleid FROM {local_catquiz_items} WHERE id $insql",
                $params
            ));
        }
        $scales ? self::params_changed($contextid, $scales) : self::context_changed($contextid);
    }

    /**
     * Questions changed - in the question bank, or as items: the pools of the root scales they are items of.
     *
     * @param int[] $questionids Question ids (local_catquiz_items.componentid of question items).
     */
    public static function questions_changed(array $questionids): void {
        global $DB;
        $questionids = array_values(array_unique(array_filter(array_map('intval', $questionids))));
        $scales = [];
        foreach (array_chunk($questionids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'prvq');
            $params['component'] = 'question';
            $scales = array_merge($scales, $DB->get_fieldset_sql(
                "SELECT DISTINCT catscaleid
                   FROM {local_catquiz_items}
                  WHERE componentid $insql AND componentname = :component",
                $params
            ));
        }
        foreach (array_unique(array_map('intval', $scales)) as $scaleid) {
            self::scale_changed($scaleid);
        }
    }

    /**
     * Purges the caches an event invalidates - except the revisions of the pools (issue #146).
     *
     * For the plugin's own changes: the tables of the CAT manager, the counts, the scales and the item parameters
     * are purged as the event purges them; the pools follow the revisions the caller sets.
     *
     * @param string $event changesintestitems, changesinitemparams or changesincatscales.
     */
    public static function purge_others(string $event): void {
        foreach (\cache_config::instance()->get_definitions() as $definition) {
            if (!in_array($event, $definition['invalidationevents'] ?? [], true)) {
                continue;
            }
            if (($definition['component'] ?? '') === 'local_catquiz' && ($definition['area'] ?? '') === self::CACHE) {
                continue;
            }
            \cache_helper::purge_by_definition($definition['component'], $definition['area']);
        }
    }

    /**
     * A change whose scope is unknown: every pool.
     */
    public static function all_changed(): void {
        self::bump([self::GLOBAL]);
    }

    /**
     * The cache of the revisions.
     *
     * @return \cache_application|\cache
     */
    public static function cache() {
        return cache::make('local_catquiz', self::CACHE);
    }

    /**
     * New tokens for the keys.
     *
     * @param string[] $keys
     */
    private static function bump(array $keys): void {
        $tokens = [];
        foreach (array_unique($keys) as $key) {
            $tokens[$key] = self::new_token();
        }
        if ($tokens) {
            self::cache()->set_many($tokens);
        }
    }

    /**
     * A token no revision had before.
     *
     * @return string
     */
    private static function new_token(): string {
        return bin2hex(random_bytes(6));
    }

    /**
     * The root scale of a scale, from the index of the hierarchy; the scale itself when it is not (or no longer) in it.
     *
     * @param int $scaleid
     * @return int
     */
    private static function root_of(int $scaleid): int {
        $parents = catscale::hierarchy()['parent'];
        $seen = [];
        $id = $scaleid;
        while (!empty($parents[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (int) $parents[$id];
        }
        return $id;
    }
}
