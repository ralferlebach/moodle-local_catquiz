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
 * Issue #146: what a change of items or parameters costs the runtime pools - before (every pool purged) and now
 * (the pools it touches get a new revision).
 *
 * Reads every pool of the site that has items (CAT context x root scale, with subscales): the cold build - time,
 * queries, cache bytes, memory - and the warm read. Then replays a fixed, seeded sequence of changes, each in one of
 * the pools, and reads every pool after each change, as the attempts of a busy site would:
 *   - "before": a change purges every pool (pool_revision::all_changed(), which is what the events did to the pools);
 *   - "now": a change gives the touched pool a new revision (pool_revision::params_changed()).
 * Counted: pools built anew, their time, queries and bytes written. Nothing in the database is changed - only the
 * caches of the pools; run it on a copy or outside busy hours, the pools are built anew while it runs.
 *
 *   php local/catquiz/cli/measure_pool_invalidation.php [--changes=20] [--seed=146] [--json=FILE]
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\catscale;
use local_catquiz\local\pool_revision;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/catquiz/lib.php');

[$options] = cli_get_params(['help' => false, 'changes' => 20, 'seed' => 146, 'json' => ''], ['h' => 'help']);
if ($options['help']) {
    cli_writeln("Issue #146: cost of pool invalidation, every pool purged versus revisions.\n");
    cli_writeln('  --changes=N  Changes to replay (default 20).');
    cli_writeln('  --seed=N     Seed of the sequence of changes (default 146).');
    cli_writeln('  --json=FILE  Also write the numbers as JSON.');
    exit(0);
}

\core\session\manager::set_user(get_admin());
$pairs = [];
$rows = $DB->get_recordset_sql(
    "SELECT DISTINCT i.contextid, i.catscaleid FROM {local_catquiz_items} i WHERE i.contextid IS NOT NULL AND i.contextid > 0"
);
foreach ($rows as $row) {
    $parents = catscale::hierarchy()['parent'];
    $root = (int) $row->catscaleid;
    for ($guard = 0; !empty($parents[$root]) && $guard < 1000; $guard++) {
        $root = (int) $parents[$root];
    }
    $pairs[$row->contextid . '/' . $root] = [(int) $row->contextid, $root];
}
$rows->close();
ksort($pairs);
if (!$pairs) {
    cli_error('No items in any CAT context.');
}

/**
 * Reads a pool; whether it was built, and what that cost.
 *
 * @param int $contextid
 * @param int $rootid
 * @return array
 */
function measure_pool_read(int $contextid, int $rootid): array {
    global $DB;
    $cache = cache::make('local_catquiz', 'runtimepool');
    $key = catscale::pool_key($contextid, true, $rootid, []);
    $built = $cache->get($key) === false;
    $queries = $DB->perf_get_queries();
    $memory = memory_get_usage();
    $start = microtime(true);
    $pool = (new catscale($rootid))->get_testitems($contextid, true);
    $ms = (microtime(true) - $start) * 1000;
    return [
        'built' => $built,
        'ms' => $ms,
        'queries' => $DB->perf_get_queries() - $queries,
        'memorykb' => (memory_get_usage() - $memory) / 1024,
        'items' => count($pool),
        'bytes' => $built ? strlen(serialize($cache->get(catscale::pool_key($contextid, true, $rootid, [])))) : 0,
    ];
}

$result = ['pools' => [], 'replay' => []];
cli_writeln(sprintf("Pools (CAT context / root scale, with subscales): %d\n", count($pairs)));
cli_writeln(sprintf('%-12s %7s %9s %8s %9s %10s %9s', 'pool', 'items', 'cold ms', 'queries', 'cache KB', 'memory KB', 'warm ms'));
foreach ($pairs as $name => [$contextid, $rootid]) {
    pool_revision::params_changed($contextid, [$rootid]);
    $cold = measure_pool_read($contextid, $rootid);
    $warm = measure_pool_read($contextid, $rootid);
    $result['pools'][$name] = ['cold' => $cold, 'warm' => $warm];
    cli_writeln(sprintf(
        '%-12s %7d %9.1f %8d %9.1f %10.1f %9.1f',
        $name,
        $cold['items'],
        $cold['ms'],
        $cold['queries'],
        $cold['bytes'] / 1024,
        $cold['memorykb'],
        $warm['ms']
    ));
}

mt_srand((int) $options['seed']);
$names = array_keys($pairs);
$sequence = [];
for ($i = 0; $i < (int) $options['changes']; $i++) {
    $sequence[] = $names[mt_rand(0, count($names) - 1)];
}
foreach (['before' => true, 'now' => false] as $mode => $global) {
    $totals = ['changes' => count($sequence), 'built' => 0, 'ms' => 0.0, 'queries' => 0, 'byteswritten' => 0];
    foreach ($sequence as $changed) {
        [$contextid, $rootid] = $pairs[$changed];
        if ($global) {
            pool_revision::all_changed();
        } else {
            pool_revision::params_changed($contextid, [$rootid]);
        }
        foreach ($pairs as [$c, $r]) {
            $read = measure_pool_read($c, $r);
            if ($read['built']) {
                $totals['built']++;
                $totals['ms'] += $read['ms'];
                $totals['queries'] += $read['queries'];
                $totals['byteswritten'] += $read['bytes'];
            }
        }
    }
    $result['replay'][$mode] = $totals;
}
cli_writeln(sprintf("\nReplay of %d changes (seed %d), every pool read after each change:", count($sequence), $options['seed']));
cli_writeln(sprintf('%-8s %8s %10s %9s %12s', 'mode', 'built', 'build ms', 'queries', 'written KB'));
foreach ($result['replay'] as $mode => $t) {
    cli_writeln(sprintf('%-8s %8d %10.0f %9d %12.0f', $mode, $t['built'], $t['ms'], $t['queries'], $t['byteswritten'] / 1024));
}
if ($options['json'] !== '') {
    file_put_contents($options['json'], json_encode($result, JSON_PRETTY_PRINT));
}
