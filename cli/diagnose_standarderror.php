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
 * The stored standard errors of finished attempts against the definition of #163. Read-only.
 *
 * Usage: php local/catquiz/cli/diagnose_standarderror.php --contextid=1605 [--from=2026-10-01] [--to=2026-11-20] [--json]
 * Exit code 2 when a stored value differs. See local_catquiz\local\integrity\standard_error_audit.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['contextid' => 0, 'from' => '', 'to' => '', 'json' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || $unrecognised || empty($options['contextid'])) {
    cli_writeln('Stored standard errors of finished attempts against the definition of #163. Read-only.');
    cli_writeln('  --contextid=ID     the CAT context (required)');
    cli_writeln('  --from=YYYY-MM-DD  attempts that ended on or after this day');
    cli_writeln('  --to=YYYY-MM-DD    attempts that ended before this day');
    cli_writeln('  --json             JSON instead of text');
    exit($options['help'] ? 0 : 1);
}
$from = $options['from'] ? (int) strtotime($options['from']) : 0;
$to = $options['to'] ? (int) strtotime($options['to']) : 0;
$result = \local_catquiz\local\integrity\standard_error_audit::check((int) $options['contextid'], $from, $to);
if ($options['json']) {
    cli_writeln(json_encode($result, JSON_PRETTY_PRINT));
} else {
    cli_writeln("Result rows: {$result['rows']}  equal: {$result['equal']}  stored smaller: {$result['smaller']}  "
        . "stored larger: {$result['larger']}  without answers to compute from: {$result['unreadable']}");
    cli_writeln('Stored / today: p5 ' . json_encode($result['ratio']['p5']) . ', p50 ' . json_encode($result['ratio']['p50'])
        . ', p95 ' . json_encode($result['ratio']['p95']));
    foreach ($result['bymonth'] as $month => $counts) {
        cli_writeln(sprintf(
            '  %s  rows %5d  equal %5d  smaller %5d  larger %5d',
            $month,
            $counts['rows'],
            $counts['equal'],
            $counts['smaller'],
            $counts['larger']
        ));
    }
    if ($result['sample']) {
        cli_writeln('Rows that differ (local_catquiz_personparams.id): ' . implode(', ', $result['sample']));
    }
}
exit($result['smaller'] + $result['larger'] > 0 ? 2 : 0);
