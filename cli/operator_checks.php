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
 * Read-only operator checks BP-01 to BP-07 (re-audit of 17.11.2026), output in the return contract as JSON.
 *
 * Nothing is written to the database. The output holds counts only; small cells are suppressed (--mincell).
 *
 * Examples:
 *   php local/catquiz/cli/operator_checks.php --check=all --system=CLARA > clara-bp.json
 *   php local/catquiz/cli/operator_checks.php --check=BP-02-snapshot --instance=42 > before.json
 *   (delete the activity on the staging system, run cron)
 *   php local/catquiz/cli/operator_checks.php --check=BP-02-snapshot --instance=42 --before=before.json > after.json
 *   php local/catquiz/cli/operator_checks.php --check=BP-02 --before=before.json --after=after.json
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_catquiz\local\operator\operator_checks;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$usage = "Read-only operator checks of the re-audit of 17.11.2026 (BP-01 to BP-07), as JSON.

Options:
  --check=ID         BP-01, BP-02-snapshot, BP-02, BP-03, BP-04, BP-05, BP-06, BP-07 or all (default all;
                     all leaves out BP-02 and BP-02-snapshot, which need an activity).
  --system=LABEL     Label of the system (CLARA, Staging 5.3, ...); default: a pseudonym of the site.
  --mincell=K        Smallest count shown as a number; smaller ones appear as \"<K\" (default 5).
  --instance=ID      BP-02-snapshot: id of the adaptivequiz activity (table adaptivequiz).
  --before=FILE      BP-02: snapshot before the deletion. BP-02-snapshot after the deletion: pass it too, so that
                     rows left under the CAT ids of before are counted as rows of the activity.
  --after=FILE       BP-02: snapshot after the deletion.
  --notdeleted       BP-02: the activity was not deleted between the snapshots (e.g. a repeated run).
  --slo-p95-ms=MS    BP-05: agreed SLO for p95 per kind of request; without it there is no verdict.
  -h, --help         This help.

Exit code: 0 when no check failed, 1 when one did, 2 on wrong use.
";

[$options, $unrecognised] = cli_get_params([
    'check' => 'all', 'system' => '', 'mincell' => 5, 'instance' => 0, 'before' => '', 'after' => '',
    'notdeleted' => false, 'slo-p95-ms' => '', 'help' => false,
], ['h' => 'help']);
if ($options['help'] || $unrecognised) {
    cli_writeln($usage);
    exit($unrecognised ? 2 : 0);
}

operator_checks::set_system((string) $options['system']);
$mincell = max(1, (int) $options['mincell']);
$read = function (string $file): array {
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) {
        cli_error("Cannot read a snapshot from $file.", 2);
    }
    return isset($data['check_id']) ? $data : ($data[0] ?? []);
};

$results = [];
switch (strtoupper((string) $options['check'])) {
    case 'ALL':
        $results[] = operator_checks::bp01();
        $results[] = operator_checks::bp03($mincell);
        $results[] = operator_checks::bp04($mincell);
        $results[] = operator_checks::bp05($options['slo-p95-ms'] === '' ? null : (int) $options['slo-p95-ms']);
        $results[] = operator_checks::bp06($mincell);
        $results[] = operator_checks::bp07();
        break;
    case 'BP-01':
        $results[] = operator_checks::bp01();
        break;
    case 'BP-02-SNAPSHOT':
        if ((int) $options['instance'] <= 0) {
            cli_error('--instance is needed.', 2);
        }
        $earlier = $options['before'] ? $read($options['before']) : [];
        $results[] = operator_checks::bp02_snapshot((int) $options['instance'], $earlier['values']['cat_ids'] ?? []);
        break;
    case 'BP-02':
        if (!$options['before'] || !$options['after']) {
            cli_error('--before and --after are needed.', 2);
        }
        $results[] = operator_checks::bp02_compare($read($options['before']), $read($options['after']), !$options['notdeleted']);
        break;
    case 'BP-03':
        $results[] = operator_checks::bp03($mincell);
        break;
    case 'BP-04':
        $results[] = operator_checks::bp04($mincell);
        break;
    case 'BP-05':
        $results[] = operator_checks::bp05($options['slo-p95-ms'] === '' ? null : (int) $options['slo-p95-ms']);
        break;
    case 'BP-06':
        $results[] = operator_checks::bp06($mincell);
        break;
    case 'BP-07':
        $results[] = operator_checks::bp07();
        break;
    default:
        cli_error('Unknown check: ' . $options['check'], 2);
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
echo json_encode(count($results) === 1 ? $results[0] : $results, $flags) . "\n";
exit(array_filter($results, fn($r) => $r['status'] === operator_checks::FAIL) ? 1 : 0);
