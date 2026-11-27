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
 * The bytes the working state of an attempt writes, answer by answer, with the format before #177 and with head and
 * events (issue #177, AK2/AK12/DoD6): the persistence alone, without the estimation, the selection or the feedback.
 *
 * An attempt of N answers is simulated with the methods the strategy calls - a question handed out and saved, the
 * response taken in, the estimates set, the state after the answer recorded, saved again. The questions are objects
 * with the 38 fields of a candidate of the selection, the responses carry the texts of the question usage, as the format
 * before stored them. Measured per save:
 *   - head: the JSON of the head and the settings written with it; events: the events appended (rows, text bytes);
 *   - before: what the format before wrote - json_encode() of the progress and the settings (progress::jsonSerialize()
 *     is that format) - measured at every save up to --exactupto answers, beyond that at every --sample-th answer;
 *   - database: the bytes the database wrote for the save (PostgreSQL write-ahead log, MariaDB/MySQL redo log), for the
 *     new format at every save; for the format before by writing its JSON into a second progress row of its own, at the
 *     same saves as its size;
 *   - session cache: the serialised progress (what the session store keeps), at the sampled saves.
 *
 * Creates two CAT attempts of its own, with a hierarchy of scales of its own, and deletes all of it at the end. Only on a
 * test copy of a site.
 *
 *   php local/catquiz/cli/simulate_progress_writes.php --confirm --answers=25,100,250,625,2500,10000 --json=FILE
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_catquiz\teststrategy\progress;

[$options] = cli_get_params([
    'help' => false, 'confirm' => false, 'answers' => '25,100,250,625', 'json' => '', 'exactupto' => 2500, 'sample' => 50,
    'nobefore' => false,
], ['h' => 'help']);

if ($options['help'] || !$options['confirm']) {
    cli_writeln('Simulates attempts and measures the bytes the working state writes (local_catquiz #177).');
    cli_writeln('  --confirm         Required: creates and deletes attempts of its own (a test copy only).');
    cli_writeln('  --answers=LIST    Lengths of the attempts (default 25,100,250,625).');
    cli_writeln('  --exactupto=N     The format before measured at every save up to N answers (default 2500).');
    cli_writeln('  --sample=K        Beyond that, at every K-th answer (default 50).');
    cli_writeln('  --nobefore        Do not write the format before into the database (sizes only).');
    cli_writeln('  --json=FILE       Write the measurements.');
    exit($options['help'] ? 0 : 1);
}

/**
 * The bytes the database wrote so far (write-ahead or redo log), null where it cannot be read.
 *
 * @return int|null
 */
function local_catquiz_sim_dbwritten(): ?int {
    global $DB;
    try {
        if ($DB->get_dbfamily() === 'postgres') {
            return (int) $DB->get_field_sql("SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), '0/0')::bigint");
        }
        if ($DB->get_dbfamily() === 'mysql') {
            $row = $DB->get_record_sql("SHOW GLOBAL STATUS LIKE 'Innodb_os_log_written'");
            return $row ? (int) $row->value : null;
        }
    } catch (\Throwable $e) {
        return null;
    }
    return null;
}

/**
 * Switches the timeline of local_catquiz on for one save (on = true) and returns the milliseconds of its spans by name
 * when switched off (on = false).
 *
 * @param bool $on
 * @return array
 */
function local_catquiz_sim_timeline(bool $on): array {
    $enabled = new ReflectionProperty(\local_catquiz\local\monitoring\timeline::class, 'enabled');
    if ($on) {
        \local_catquiz\local\monitoring\timeline::reset();
        $enabled->setValue(null, true);
        return [];
    }
    $out = [];
    foreach (\local_catquiz\local\monitoring\timeline::get_spans() as $span) {
        if ($span['ms'] !== null) {
            $out[$span['name']] = round(($out[$span['name']] ?? 0) + (float) $span['ms'], 3);
        }
    }
    \local_catquiz\local\monitoring\timeline::reset();
    $enabled->setValue(null, false);
    return $out;
}

/**
 * A candidate of the selection with the 38 fields it has (field inventory of #177).
 *
 * @param int $id
 * @param int $scaleid
 * @param array $scales Root first.
 * @param bool $pilot
 * @return stdClass
 */
function local_catquiz_sim_question(int $id, int $scaleid, array $scales, bool $pilot): stdClass {
    $now = time();
    return (object) [
        'id' => (string) $id, 'componentid' => (string) $id, 'catscaleid' => (string) $scaleid, 'is_pilot' => $pilot,
        'activeparamid' => (string) (900000 + $id), 'model' => 'raschbirnbaum',
        'difficulty' => sprintf('%.4f', (($id * 37) % 400) / 100 - 2), 'discrimination' => '1.2345', 'guessing' => '0.0000',
        'json' => '{"difficulty":' . ((($id * 37) % 400) / 100 - 2) . ',"discrimination":1.2345,"source":"import"}',
        'status' => '4', 'fisherinformation' => array_fill_keys($scales, 0.2 + ($id % 50) / 1000),
        'label' => 'ITEM-' . $id, 'idnumber' => 'ITEM-' . $id, 'qtype' => 'multichoice', 'testitemstatus' => '0',
        'component' => 'question', 'itemid' => (string) $id, 'lccscatscaleid' => (string) $scaleid,
        'timecreated' => (string) ($now - 86400), 'timemodified' => (string) ($now - 3600), 'usable' => '1',
        'itemparamvalidity' => '1', 'lcipcontextid' => '7', 'itemcontextid' => '7', 'invalidparamref' => null,
        'questionstatus' => 'ready', 'attempts' => (string) ($id % 300), 'astatlastattempttime' => (string) ($now - 600),
        'userid' => '0', 'userattempts' => '0', 'lastattempttime' => (string) ($now - 600),
        'lasttimeplayedpenaltyfactor' => '1', 'processterm' => 0.123456789, 'scaleterm' => 0.987654321,
        'itemterm' => 0.5, 'score' => 0.123456789012, 'userlastattempttime' => $now,
    ];
}

/**
 * A response as the question usage gives it (catquiz::get_last_response_for_attempt()), with its texts.
 *
 * @param int $id
 * @param int $usage
 * @param int $slot
 * @param bool $right
 * @return array
 */
function local_catquiz_sim_response(int $id, int $usage, int $slot, bool $right): array {
    $now = time();
    return [
        'id' => (string) (5000000 + $slot), 'questionattemptid' => (string) (4000000 + $slot), 'slot' => (string) $slot,
        'state' => $right ? 'gradedright' : 'gradedwrong', 'originalfraction' => $right ? '1.0000000' : '0.0000000',
        'fraction' => $right ? '1.000' : '0.000', 'timecreated' => (string) $now, 'userid' => '2',
        'questionusageid' => (string) $usage, 'questionid' => (string) $id,
        'questionsummary' => 'Berechnen Sie den Wert des folgenden Ausdrucks für x = 3: f(x) = 2x² - 4x + 1. '
            . 'Welche der folgenden Antworten ist richtig?: 7; 5; 11; 13',
        'rightanswer' => '7', 'responsesummary' => $right ? '7' : '11', 'timemodified' => (string) $now,
    ];
}

$lengths = array_map('intval', explode(',', (string) $options['answers']));
$now = time();
$results = ['meta' => ['db' => $DB->get_dbfamily(), 'catquiz' => get_config('local_catquiz', 'version'),
    'php' => PHP_VERSION, 'exactupto' => (int) $options['exactupto'], 'sample' => (int) $options['sample']], 'runs' => []];

\core\session\manager::set_user(get_admin());
foreach ($lengths as $n) {
    // Scales of its own: root > two subscales.
    $root = (int) $DB->insert_record('local_catquiz_catscales', (object) [
        'name' => 'sim177 root', 'parentid' => 0, 'timecreated' => $now, 'timemodified' => $now,
    ]);
    $subs = [];
    foreach (['A', 'B'] as $name) {
        $subs[] = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => "sim177 $name", 'parentid' => $root, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    cache::make('local_catquiz', 'catscales')->purge();
    $created = [];
    foreach (['new', 'before'] as $kind) {
        $attempt = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 0, 'userid' => $USER->id, 'uniqueid' => 0, 'attemptstate' => 'inprogress',
            'attemptstopcriteria' => '', 'questionsattempted' => 0, 'difficultysum' => 0, 'standarderror' => 1,
            'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $cat = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $USER->id, 'attemptid' => $attempt, 'component' => 'mod_adaptivequiz', 'contextid' => 0,
            'status' => 2, 'json' => '{}', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $created[$kind] = [$attempt, $cat];
    }
    $settings = (object) [
        'catquiz_catscales' => $root, 'catquiz_selectteststrategy' => 4, 'modulename' => 'adaptivequiz',
        'catquiz_minquestions' => $n, 'catquiz_maxquestions' => $n,
    ];
    $progress = progress::load($created['new'][0], 'mod_adaptivequiz', 0, $settings);
    $responses = new ReflectionProperty(progress::class, 'responses');
    $beforeid = null;
    $beforefailed = null;
    $saves = [];
    $packet = $DB->get_dbfamily() === 'mysql'
        ? (int) $DB->get_field_sql("SELECT @@max_allowed_packet") : null;
    $sampled = fn(int $i) => !$options['nobefore'] && ($i <= (int) $options['exactupto'] || $i % (int) $options['sample'] === 0
        || $i === $n);
    $saveit = function (
        int $i,
        string $what
    ) use (
        &$saves,
        $progress,
        $DB,
        $created,
        $settings,
        &$beforeid,
        &$beforefailed,
        $packet,
        $sampled,
        $n
    ): void {
        $events = $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $created['new'][1]]);
        $wal = local_catquiz_sim_dbwritten();
        // Re-audit 27.11.2026: the parts of a save - events, head, session cache - from the spans of the timeline.
        local_catquiz_sim_timeline(true);
        $start = microtime(true);
        $progress->save();
        $ms = (microtime(true) - $start) * 1000;
        $parts = local_catquiz_sim_timeline(false);
        $walafter = local_catquiz_sim_dbwritten();
        $head = $DB->get_record('local_catquiz_progress', ['attemptid' => $created['new'][1]], 'json, quizsettings, lastseq');
        $newevents = $DB->get_records_select(
            'local_catquiz_progress_steps',
            'catattemptid = ? AND seq > ?',
            [$created['new'][1], $events],
            '',
            'id, diagnostics, estimatesdelta'
        );
        $textbytes = 0;
        foreach ($newevents as $event) {
            $textbytes += strlen((string) $event->diagnostics) + strlen((string) $event->estimatesdelta);
        }
        $save = [
            'i' => $i, 'what' => $what, 'ms' => round($ms, 3),
            'headbytes' => strlen((string) $head->json) + strlen((string) $head->quizsettings),
            'events' => count($newevents), 'eventtextbytes' => $textbytes,
            'walnew' => $wal !== null && $walafter !== null ? $walafter - $wal : null,
            'eventsms' => $parts['progress:events'] ?? null, 'headms' => $parts['progress:json_encode'] ?? null,
            'cachems' => $parts['progress:cache'] ?? null,
        ];
        if ($what === 'answer' && ($i === $n || ($i % 25 === 0 && ($i <= 625 || $i % 125 === 0)))) {
            // Re-audit 27.11.2026: what a request costs that finds no session cache - head and events read again (the
            // way a request cache instead of the session cache would go every time): time, queries, memory.
            // The entry of the session cache: the whole working state, serialised.
            $save['sessionbytes'] = strlen(serialize($progress));
            \cache::make('local_catquiz', 'adaptivequizattempt')->purge();
            \cache::make('local_catquiz', 'progresscurrent')->purge();
            gc_collect_cycles();
            $memory = memory_get_usage();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $queries = $DB->perf_get_queries();
            $cold = microtime(true);
            $loaded = progress::load($created['new'][0], 'mod_adaptivequiz', 0, $settings);
            $save['coldms'] = round((microtime(true) - $cold) * 1000, 3);
            $save['coldqueries'] = $DB->perf_get_queries() - $queries;
            $save['coldpeakkb'] = (int) ((memory_get_peak_usage() - $memory) / 1024);
            $save['coldresponses'] = count($loaded->get_responses());
            unset($loaded);
        }
        if ($sampled($i) && $beforefailed === null) {
            // The format before: what progress::save() wrote before #177 - the whole progress and the settings.
            $json = json_encode($progress);
            $save['beforebytes'] = strlen($json) + strlen(json_encode($settings));
            $save['sessionbytes'] = strlen(serialize($progress));
            $record = (object) ['json' => $json, 'quizsettings' => json_encode($settings), 'timemodified' => time()];
            $wal = local_catquiz_sim_dbwritten();
            try {
                // The statement as sent: the JSON escaped (quotes and backslashes doubled), with the settings.
                if ($packet !== null && strlen(addslashes($json)) + strlen((string) $record->quizsettings) + 4096 > $packet) {
                    // MariaDB/MySQL refuses a statement larger than max_allowed_packet - and drops the connection with
                    // it. Not sent: the format before cannot be stored from here on.
                    throw new \dml_write_exception('larger than max_allowed_packet (' . $packet . ' bytes)');
                }
                if ($beforeid === null) {
                    $record->attemptid = $created['before'][1];
                    $record->userid = $progress->get_userid();
                    $record->component = 'mod_adaptivequiz';
                    $beforeid = (int) $DB->insert_record('local_catquiz_progress', $record);
                } else {
                    $record->id = $beforeid;
                    $DB->update_record('local_catquiz_progress', $record);
                }
                $walafter = local_catquiz_sim_dbwritten();
                $save['walbefore'] = $wal !== null && $walafter !== null ? $walafter - $wal : null;
            } catch (\dml_exception $e) {
                // The format before cannot be written any more (MariaDB/MySQL: max_allowed_packet): a finding, the
                // measurement of the new format goes on.
                $beforefailed = [
                    'i' => $i, 'bytes' => strlen($json), 'error' => substr($e->getMessage() . ' ' . $e->debuginfo, 0, 300),
                ];
                $save['beforefailed'] = $beforefailed;
            }
        }
        $saves[] = $save;
    };
    $started = microtime(true);
    for ($i = 1; $i <= $n; $i++) {
        $scale = $subs[$i % 2];
        $pilot = $i % 13 === 0;
        $progress->add_playedquestion(local_catquiz_sim_question(70000000 + $i, $scale, [$root, $scale], $pilot));
        $saveit($i, 'shown');
        $list = $responses->getValue($progress);
        $list[70000000 + $i] = local_catquiz_sim_response(70000000 + $i, 9000000, $i, $i % 3 !== 0);
        $responses->setValue($progress, $list);
        $theta = sin($i / 7) * 1.5;
        $progress->set_ability($theta, $root);
        $progress->set_ability($theta / 2, $scale);
        $progress->record_standard_errors([$root => 1 / sqrt($i + 1), $scale => 1 / sqrt($i / 2 + 1)]);
        $progress->record_answer_state($theta);
        $saveit($i, 'answer');
        if ($i % 1000 === 0) {
            cli_writeln(sprintf('  %d/%d answers, %.0f s', $i, $n, microtime(true) - $started));
        }
    }
    $results['runs'][$n] = [
        'answers' => $n, 'seconds' => round(microtime(true) - $started, 1), 'saves' => $saves, 'beforefailed' => $beforefailed,
    ];
    cli_writeln(sprintf('%d answers: %.1f s', $n, microtime(true) - $started));

    // Cleanup: both attempts, their progress and events, the scales.
    foreach ($created as [$attempt, $cat]) {
        $DB->delete_records('local_catquiz_progress_steps', ['catattemptid' => $cat]);
        $DB->delete_records('local_catquiz_progress', ['attemptid' => $cat]);
        $DB->delete_records('local_catquiz_attempts', ['id' => $cat]);
        $DB->delete_records('adaptivequiz_attempt', ['id' => $attempt]);
    }
    $DB->delete_records_list('local_catquiz_catscales', 'id', [$root, ...$subs]);
    cache::make('local_catquiz', 'catscales')->purge();
    cache::make('local_catquiz', 'adaptivequizattempt')->purge();
    // Written after every length: a long run that stops keeps what it measured.
    if ($options['json'] !== '') {
        file_put_contents((string) $options['json'], json_encode($results));
    }
}
if ($options['json'] !== '') {
    file_put_contents((string) $options['json'], json_encode($results));
}
