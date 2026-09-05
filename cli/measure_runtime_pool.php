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
 * Measures the runtime item pool against the protocol required by issue #26.
 *
 * The issue asks for a decision based on measurement rather than for an architectural
 * change made in advance. It prescribes what has to be measured, and this script
 * implements exactly that so the numbers are comparable between runs and between
 * machines.
 *
 * What it reports per pool size:
 *   - a cold run after a cache reset, and a warm run after warm-up, kept apart,
 *   - median and p95 over repeated runs,
 *   - SQL time of the pool query and total time including hydration,
 *   - number of database queries,
 *   - additional memory, and the size of the serialised cache payload.
 *
 * The first run of anything is not the runtime cost: a measurement taken directly
 * after ANALYZE once suggested a factor of fifty that did not exist. Cold and warm
 * are therefore reported separately and never averaged together.
 *
 * Usage:
 *   php cli/measure_runtime_pool.php --scaleid=3 --contextid=4 [--repeats=7]
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'scaleid' => 0,
    'contextid' => 0,
    'repeats' => 7,
    'out' => '',
], ['h' => 'help']);

if ($options['help'] || empty($options['scaleid'])) {
    cli_writeln("Measures the runtime item pool (issue #26).\n");
    cli_writeln("  --scaleid=N    Scale to load the pool for (required).");
    cli_writeln("  --contextid=N  Context; defaults to the scale's own.");
    cli_writeln("  --repeats=N    Warm runs used for median and p95 (default 7).");
    cli_writeln("  --out=FILE     Write a markdown report as well.");
    exit(0);
}

$scaleid = (int) $options['scaleid'];
$contextid = (int) $options['contextid'];
$repeats = max(3, (int) $options['repeats']);

if (empty($contextid)) {
    $contextid = (int) $DB->get_field('local_catquiz_catscales', 'contextid', ['id' => $scaleid]);
}

/**
 * Runs one pool load and returns its measurements.
 *
 * @param int $scaleid
 * @param int $contextid
 * @return array
 */
function measure_once(int $scaleid, int $contextid): array {
    global $DB, $USER;

    $queriesbefore = $DB->perf_get_queries();
    $memorybefore = memory_get_usage();

    [$select, $from, $where, , $params] = \local_catquiz\catquiz::return_sql_for_catscalequestions(
        [$scaleid],
        $contextid,
        [],
        $USER->id,
        null
    );

    $sqlstart = microtime(true);
    $rows = $DB->get_records_sql("SELECT $select FROM $from WHERE $where", $params);
    $sqltime = (microtime(true) - $sqlstart) * 1000;

    // Hydration is what the selection actually works on, so it belongs in the total
    // rather than being measured as SQL alone.
    $hydrationstart = microtime(true);
    $payload = serialize($rows);
    $hydrationtime = (microtime(true) - $hydrationstart) * 1000;

    return [
        'rows' => count($rows),
        'sqlms' => $sqltime,
        'totalms' => $sqltime + $hydrationtime,
        'queries' => $DB->perf_get_queries() - $queriesbefore,
        'memorykb' => (memory_get_usage() - $memorybefore) / 1024,
        'payloadkb' => strlen($payload) / 1024,
    ];
}

/**
 * Returns the value at a percentile of a sorted sample.
 *
 * @param array $values
 * @param float $percentile
 * @return float
 */
function percentile(array $values, float $percentile): float {
    sort($values);
    $index = (int) ceil($percentile * count($values)) - 1;

    return $values[max(0, min($index, count($values) - 1))];
}

/**
 * Runs one "add questions" dialog query and returns its measurements.
 *
 * Issue #58 asks for the same protocol on this dialog. It is measured here rather
 * than in a second script so that both use one definition of cold, warm, median and
 * p95 - two harnesses drift apart and their numbers stop being comparable.
 *
 * @param int $scaleid
 * @param int $contextid
 * @param int $pagesize Rows the dialog shows; kept identical between pool sizes.
 * @return array
 */
function measure_add_questions(int $scaleid, int $contextid, int $pagesize = 10): array {
    global $DB;

    $queriesbefore = $DB->perf_get_queries();

    [$select, $from, $where, , $params] = \local_catquiz\catquiz::return_sql_for_addcatscalequestions(
        $scaleid,
        $contextid
    );

    $start = microtime(true);
    $rows = $DB->get_records_sql("SELECT $select FROM $from WHERE $where", $params, 0, $pagesize);
    $sqltime = (microtime(true) - $start) * 1000;

    // The statistics of the visible rows are fetched separately since issue #58; that
    // second query is part of what the dialog costs and belongs in the total.
    $statstart = microtime(true);
    $counts = \local_catquiz\catquiz::get_contextattempts_for_questions(
        array_map(fn($row) => (int) $row->id, $rows),
        $contextid
    );
    $stattime = (microtime(true) - $statstart) * 1000;

    return [
        'rows' => count($rows),
        'sqlms' => $sqltime,
        'totalms' => $sqltime + $stattime,
        'statms' => $stattime,
        'queries' => $DB->perf_get_queries() - $queriesbefore,
        'counts' => count($counts),
    ];
}

\core\session\manager::set_user(get_admin());

$items = $DB->count_records('local_catquiz_items');
cli_writeln(sprintf(
    'Pool: scale %d, context %d, %s items in the installation',
    $scaleid,
    $contextid,
    number_format($items, 0, ',', '.')
));

// Cold run: caches purged first, reported on its own. Averaging it into the warm
// runs is what turns a cache artefact into an apparent architectural problem.
purge_all_caches();
$cold = measure_once($scaleid, $contextid);
cli_writeln(sprintf(
    "\nCold run after cache purge:\n  %d rows, SQL %.0f ms, total %.0f ms, %d queries, %.0f KB memory, payload %.0f KB",
    $cold['rows'],
    $cold['sqlms'],
    $cold['totalms'],
    $cold['queries'],
    $cold['memorykb'],
    $cold['payloadkb']
));

// Warm-up, then the sample the decision is based on.
measure_once($scaleid, $contextid);

$sqltimes = [];
$totaltimes = [];
$last = null;
for ($i = 0; $i < $repeats; $i++) {
    $last = measure_once($scaleid, $contextid);
    $sqltimes[] = $last['sqlms'];
    $totaltimes[] = $last['totalms'];
}

cli_writeln(sprintf("\nWarm runs (n=%d):", $repeats));
cli_writeln(sprintf('  SQL    median %.0f ms   p95 %.0f ms', percentile($sqltimes, 0.5), percentile($sqltimes, 0.95)));
cli_writeln(sprintf('  Total  median %.0f ms   p95 %.0f ms', percentile($totaltimes, 0.5), percentile($totaltimes, 0.95)));
cli_writeln(sprintf(
    '  %d queries, %.0f KB memory, payload %.0f KB',
    $last['queries'],
    $last['memorykb'],
    $last['payloadkb']
));

// Issue #58: the same protocol for the "add questions" dialog. The page size is
// fixed so that the comparison across pool sizes shows how the query scales, not how
// many rows happen to be displayed.
purge_all_caches();
$addcold = measure_add_questions($scaleid, $contextid);
measure_add_questions($scaleid, $contextid);

$addtotals = [];
$addlast = null;
for ($i = 0; $i < $repeats; $i++) {
    $addlast = measure_add_questions($scaleid, $contextid);
    $addtotals[] = $addlast['totalms'];
}

cli_writeln(sprintf("\nAdd-questions dialog (issue #58), page size 10:"));
cli_writeln(sprintf('  Cold   total %.0f ms', $addcold['totalms']));
cli_writeln(sprintf(
    '  Warm   median %.0f ms   p95 %.0f ms',
    percentile($addtotals, 0.5),
    percentile($addtotals, 0.95)
));
cli_writeln(sprintf(
    '  of which statistics of the visible rows: %.0f ms, %d queries',
    $addlast['statms'],
    $addlast['queries']
));

if (!empty($options['out'])) {
    $report = sprintf(
        "| %s | %d | %.0f | %.0f | %.0f | %.0f | %d | %.0f | %.0f |\n",
        number_format($items, 0, ',', '.'),
        $cold['rows'],
        $cold['totalms'],
        percentile($totaltimes, 0.5),
        percentile($totaltimes, 0.95),
        percentile($sqltimes, 0.5),
        $last['queries'],
        $last['memorykb'],
        $last['payloadkb']
    );
    file_put_contents($options['out'], $report, FILE_APPEND);
    cli_writeln("\nAppended to " . $options['out']);
}
