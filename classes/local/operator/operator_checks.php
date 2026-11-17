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

namespace local_catquiz\local\operator;

use core_plugin_manager;
use local_catquiz\local\status;

/**
 * Read-only operator checks BP-01 to BP-07 of the re-audit of 17.11.2026, with its return contract.
 *
 * Every check returns one record of the contract (section 5 of the re-audit): check_id, system, execution time,
 * versions and SHAs of engine, host and adapter, status PASS|FAIL|NOT_RUN|INCONCLUSIVE, number of records looked at,
 * aggregated values, the allowed deviation, notes, links (empty, for the operator to add) and the operator's
 * confirmation (empty, to be signed by the operator).
 *
 * The checks only read. They output counts, never names, answers or single attempts; a count below the minimum cell
 * size is shown as "<k" (re-audit BP-03, small cells). BP-02 is a pair: a snapshot before and one after an authorised
 * test deletion on a staging system, then the comparison of the two.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class operator_checks {
    /** @var string The check holds. */
    public const PASS = 'PASS';
    /** @var string The check fails. */
    public const FAIL = 'FAIL';
    /** @var string The check could not run here. */
    public const NOT_RUN = 'NOT_RUN';
    /** @var string The check ran, but its data do not allow a verdict. */
    public const INCONCLUSIVE = 'INCONCLUSIVE';

    /** @var string[] The three plugins of the trilogy. */
    public const COMPONENTS = [
        'engine' => 'local_catquiz',
        'host' => 'mod_adaptivequiz',
        'adapter' => 'adaptivequizcatmodel_catquiz',
    ];

    /** @var int[] Lengths the re-audit asks to show apart (BP-03). */
    public const MARKED_LENGTHS = [16, 17, 25];

    /** @var int Version of local_catquiz 1.2 that brought the stop code of a complete scale set (#172). */
    public const FIX172_VERSION = 2026111700;

    /** @var string Label of the system; a pseudonym of the site when empty. */
    private static string $system = '';

    /**
     * Sets the label of the system, e.g. "CLARA" or "Staging 5.3".
     *
     * @param string $label
     */
    public static function set_system(string $label): void {
        self::$system = $label;
    }

    /**
     * One record of the return contract.
     *
     * @param string $id
     * @param string $status
     * @param int $records
     * @param array $values
     * @param array $notes
     * @param mixed $maxdeviation
     * @return array
     */
    public static function result(
        string $id,
        string $status,
        int $records,
        array $values,
        array $notes = [],
        $maxdeviation = null
    ): array {
        return [
            'check_id' => $id,
            'system' => self::system_label(),
            'executed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'versions' => self::versions(),
            'status' => $status,
            'records' => $records,
            'values' => $values,
            'max_deviation' => $maxdeviation,
            'notes' => array_values($notes),
            'links' => [],
            'operator_confirmation' => null,
        ];
    }

    /**
     * The label of the system, or a pseudonym that does not reveal the site's address.
     *
     * @return string
     */
    public static function system_label(): string {
        global $CFG;
        if (self::$system !== '') {
            return self::$system;
        }
        return 'site-' . substr(hash('sha256', ($CFG->siteidentifier ?? '') . '|' . $CFG->wwwroot), 0, 12);
    }

    /**
     * Installed version, release and - when the plugin directory is a git checkout - the commit of the three plugins.
     *
     * @return array
     */
    public static function versions(): array {
        $out = [];
        foreach (self::COMPONENTS as $role => $component) {
            $info = core_plugin_manager::instance()->get_plugin_info($component);
            $out[$role] = [
                'component' => $component,
                'version' => $info ? (int) $info->versiondb : null,
                'release' => $info ? $info->release : null,
                'sha' => $info ? self::git_sha($info->rootdir) : null,
            ];
        }
        return $out;
    }

    /**
     * The commit a plugin directory is checked out at, or null (installed from a ZIP).
     *
     * @param string $dir
     * @return string|null
     */
    public static function git_sha(string $dir): ?string {
        $head = $dir . '/.git/HEAD';
        if (!is_readable($head)) {
            return null;
        }
        $content = trim((string) file_get_contents($head));
        if (preg_match('/^[0-9a-f]{40}$/', $content)) {
            return $content;
        }
        if (preg_match('/^ref: (.+)$/', $content, $m)) {
            $ref = $dir . '/.git/' . $m[1];
            if (is_readable($ref)) {
                return trim((string) file_get_contents($ref));
            }
            $packed = $dir . '/.git/packed-refs';
            if (
                is_readable($packed) && preg_match(
                    '/^([0-9a-f]{40}) ' . preg_quote($m[1], '/') . '$/m',
                    (string) file_get_contents($packed),
                    $p
                )
            ) {
                return $p[1];
            }
        }
        return null;
    }

    /**
     * A count for publication: below the minimum cell size it is only "<k".
     *
     * @param int $count
     * @param int $mincell
     * @return int|string
     */
    public static function cell(int $count, int $mincell) {
        return ($count > 0 && $count < $mincell) ? '<' . $mincell : $count;
    }

    /**
     * BP-01: system and plugin inventory.
     *
     * PASS when the three plugins are installed and every dependency among them is met (one compatible trilogy).
     * The PHP version of the web server is not visible from the command line; it is to be read on the site
     * (Site administration > Server > Environment) and added by the operator.
     *
     * @return array
     */
    public static function bp01(): array {
        global $CFG, $DB;
        $manager = core_plugin_manager::instance();
        $values = [
            'moodle' => ['release' => $CFG->release, 'version' => (string) $CFG->version, 'branch' => $CFG->branch],
            'php_cli' => PHP_VERSION,
            'php_web' => null,
            'database' => ['family' => $DB->get_dbfamily(), 'version' => $DB->get_server_info()['version'] ?? null],
            'dependencies' => [],
            'deployed' => [],
            'cron' => [
                'last_start' => self::iso((int) get_config('tool_task', 'lastcronstart')),
                'last_interval_s' => (int) get_config('tool_task', 'lastcroninterval'),
            ],
            'scheduled_tasks' => [],
            'adhoc_queued' => [],
        ];
        $notes = ['php_web: im Browser unter Website-Administration > Server > Umgebung ablesen und eintragen.'];
        $status = self::PASS;
        foreach (self::COMPONENTS as $role => $component) {
            $info = $manager->get_plugin_info($component);
            if (!$info || !$info->versiondb) {
                $status = self::FAIL;
                $notes[] = "$component ist nicht installiert.";
                continue;
            }
            foreach ((array) $info->dependencies as $dependency => $needed) {
                if (!in_array($dependency, self::COMPONENTS, true)) {
                    continue;
                }
                $dep = $manager->get_plugin_info($dependency);
                $have = $dep ? (int) $dep->versiondb : 0;
                $ok = $needed === ANY_VERSION || $have >= (int) $needed;
                $values['dependencies'][] = ['plugin' => $component, 'needs' => $dependency,
                    'required' => $needed, 'installed' => $have, 'ok' => $ok];
                if (!$ok) {
                    $status = self::FAIL;
                }
            }
            if ($info->versiondisk && (int) $info->versiondisk !== (int) $info->versiondb) {
                $status = self::FAIL;
                $notes[] = "$component: Code {$info->versiondisk}, Datenbank {$info->versiondb} - Upgrade ausstehend.";
            }
            $last = $DB->get_records(
                'upgrade_log',
                ['plugin' => $component],
                'timemodified DESC, id DESC',
                'id, version, timemodified',
                0,
                1
            );
            $last = reset($last);
            $values['deployed'][$role] = $last ? ['version' => $last->version, 'at' => self::iso((int) $last->timemodified)] : null;
            if (!self::git_sha($info->rootdir)) {
                $notes[] = "$component: kein Commit ermittelbar (Installation ohne .git); den SHA der Auslieferung dazuschreiben.";
            }
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values(self::COMPONENTS), SQL_PARAMS_NAMED);
        foreach (
            $DB->get_records_select(
                'task_scheduled',
                "component $insql",
                $params,
                'classname',
                'id, classname, component, disabled, lastruntime, nextruntime, faildelay'
            ) as $task
        ) {
            $values['scheduled_tasks'][] = [
                'task' => $task->classname, 'disabled' => (int) $task->disabled,
                'last_run' => self::iso((int) $task->lastruntime), 'next_run' => self::iso((int) $task->nextruntime),
                'faildelay' => (int) $task->faildelay,
            ];
        }
        foreach ($DB->get_records_select('task_adhoc', "component $insql", $params, '', 'id, classname') as $task) {
            $values['adhoc_queued'][$task->classname] = ($values['adhoc_queued'][$task->classname] ?? 0) + 1;
        }
        return self::result('BP-01', $status, count(self::COMPONENTS), $values, $notes);
    }

    /**
     * BP-02, first half: a snapshot of one activity and of everything around it, before or after a test deletion.
     *
     * Counted for the activity: its attempts, its CAT attempts, the saved progress under the internal CAT ids and the
     * abilities bound to those attempts. For everything else: counts and a checksum per table, so that the comparison
     * shows whether anything outside the activity changed. Plus the orphan inventory of the adapter and the number of
     * id collisions (an activity attempt id that is the id of another attempt's CAT attempt).
     *
     * The snapshot after a deletion is to be taken with the CAT ids of the snapshot before ($alsocatids): rows left
     * under those ids are then counted as rows of the activity, even when their CAT attempt is gone.
     *
     * @param int $instanceid The adaptivequiz instance (activity) under test.
     * @param int[] $alsocatids CAT attempt ids of an earlier snapshot of the same activity.
     * @return array
     */
    public static function bp02_snapshot(int $instanceid, array $alsocatids = []): array {
        global $DB;
        $current = array_map('intval', $DB->get_fieldset_select(
            'local_catquiz_attempts',
            'id',
            "instanceid = :instance AND component IN ('mod_adaptivequiz', 'adaptivequiz')",
            ['instance' => $instanceid]
        ));
        $catids = array_values(array_unique(array_merge($current, array_map('intval', $alsocatids))));
        sort($catids);
        [$insql, $inparams] = $DB->get_in_or_equal($catids ?: [0], SQL_PARAMS_NAMED, 'cat');
        $target = [
            'activity_attempts' => $DB->count_records('adaptivequiz_attempt', ['instance' => $instanceid]),
            'cat_attempts' => $DB->count_records_select('local_catquiz_attempts', "id $insql", $inparams),
            'progress_of_cat_attempts' => $DB->count_records_select('local_catquiz_progress', "attemptid $insql", $inparams),
            'abilities_of_cat_attempts' => $DB->count_records_select('local_catquiz_personparams', "attemptid $insql", $inparams),
        ];
        $others = [
            'activity_attempts' => self::checksum(
                'adaptivequiz_attempt',
                'instance <> :instance',
                ['instance' => $instanceid],
                ['id', 'instance', 'userid', 'attemptstate', 'questionsattempted', 'timemodified']
            ),
            'cat_attempts' => self::checksum(
                'local_catquiz_attempts',
                "NOT (id $insql)",
                $inparams,
                ['id', 'userid', 'attemptid', 'instanceid', 'status', 'timemodified']
            ),
            'progress' => self::checksum(
                'local_catquiz_progress',
                "NOT (attemptid $insql)",
                $inparams,
                ['id', 'userid', 'attemptid', 'timemodified'],
                'json'
            ),
            'abilities' => self::checksum(
                'local_catquiz_personparams',
                "(attemptid IS NULL OR NOT (attemptid $insql))",
                $inparams,
                ['id', 'userid', 'catscaleid', 'attemptid', 'ability', 'standarderror', 'timemodified']
            ),
        ];
        $collisions = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {adaptivequiz_attempt} a
               JOIN {local_catquiz_attempts} c ON c.id = a.id
              WHERE c.attemptid <> a.id"
        );
        $orphans = [];
        if (class_exists('\adaptivequizcatmodel_catquiz\local\orphan_inventory')) {
            foreach (\adaptivequizcatmodel_catquiz\local\orphan_inventory::collect() as $class => $counts) {
                $orphans[$class] = (int) $counts['total'];
            }
        }
        $records = array_sum($target) + array_sum(array_column($others, 'count'));
        return self::result('BP-02-snapshot', self::INCONCLUSIVE, $records, [
            'instance' => $instanceid,
            'cat_ids' => $catids,
            'target' => $target,
            'others' => $others,
            'id_collisions' => $collisions,
            'orphans' => $orphans,
        ], ['Momentaufnahme ohne Urteil; das Urteil gibt der Vergleich vorher/nachher (BP-02).']);
    }

    /**
     * BP-02, second half: compares two snapshots of the same activity.
     *
     * After a deletion ($deleted = true): nothing of the activity is left. Always: nothing outside the activity
     * changed (same counts and checksums), and no orphan class grew - except the traces, which wait for the retention
     * task. Two snapshots after the deletion compared with each other show whether a repeated deletion is idempotent.
     *
     * @param array $before
     * @param array $after
     * @param bool $deleted Whether the activity was deleted between the two snapshots.
     * @return array
     */
    public static function bp02_compare(array $before, array $after, bool $deleted = true): array {
        $b = $before['values'];
        $a = $after['values'];
        $findings = [];
        if ((int) $b['instance'] !== (int) $a['instance']) {
            return self::result('BP-02', self::INCONCLUSIVE, 0, [], ['Die Momentaufnahmen betreffen verschiedene Aktivitäten.']);
        }
        $notes = [];
        if (array_diff($b['cat_ids'] ?? [], $a['cat_ids'] ?? [])) {
            $notes[] = 'Die Momentaufnahme nachher ist ohne die CAT-IDs von vorher erstellt (--before); Reste unter diesen IDs '
                . 'zeigt dann nur die Inventur.';
        }
        if ($deleted) {
            foreach ($a['target'] as $what => $count) {
                if ($count !== 0) {
                    $findings[] = "Nach dem Löschen übrig: $what = $count.";
                }
            }
        } else if ($a['target'] !== $b['target']) {
            $findings[] = 'Die Daten der Aktivität haben sich geändert: '
                . json_encode(['vorher' => $b['target'], 'nachher' => $a['target']]);
        }
        foreach ($b['others'] as $table => $sum) {
            if ($a['others'][$table] !== $sum) {
                $findings[] = "Außerhalb der Aktivität geändert: $table (" . $sum['count'] . ' -> '
                    . $a['others'][$table]['count'] . ' Zeilen).';
            }
        }
        $grown = [];
        foreach ($a['orphans'] as $class => $count) {
            $was = $b['orphans'][$class] ?? 0;
            if ($count > $was) {
                $grown[$class] = [$was, $count];
                if ($class !== 'trace_orphan') {
                    $findings[] = "Neue verwaiste Zeilen: $class ($was -> $count).";
                }
            }
        }
        $notes = array_merge($findings, $notes);
        if (isset($grown['trace_orphan'])) {
            $notes[] = 'Traces gelöschter Versuche warten auf die Aufbewahrung von local_catquiz (erlaubt).';
        }
        $notes[] = 'Eine Inventur belegt keine Ursache und keine bytegenaue Unverändertheit jenseits der Prüfsummen.';
        return self::result('BP-02', $findings ? self::FAIL : self::PASS, (int) ($after['records'] ?? 0), [
            'instance' => (int) $a['instance'],
            'deleted' => $deleted,
            'target_before' => $b['target'],
            'target_after' => $a['target'],
            'others_unchanged' => !array_filter(array_keys($b['others']), fn($t) => $a['others'][$t] !== $b['others'][$t]),
            'orphans_before' => $b['orphans'],
            'orphans_after' => $a['orphans'],
            'id_collisions' => ['before' => $b['id_collisions'], 'after' => $a['id_collisions']],
        ], $notes, 0);
    }

    /**
     * Count and SHA-256 over the given fields of the selected rows (ordered by id); a large text field enters as its
     * own hash.
     *
     * @param string $table
     * @param string $select
     * @param array $params
     * @param array $fields
     * @param string|null $textfield
     * @return array
     */
    private static function checksum(
        string $table,
        string $select,
        array $params,
        array $fields,
        ?string $textfield = null
    ): array {
        global $DB;
        $columns = implode(', ', array_merge($fields, $textfield ? [$textfield] : []));
        $hash = hash_init('sha256');
        $count = 0;
        $rs = $DB->get_recordset_select($table, $select, $params, 'id', $columns);
        foreach ($rs as $row) {
            $parts = [];
            foreach ($fields as $field) {
                $parts[] = (string) $row->$field;
            }
            if ($textfield) {
                $parts[] = sha1((string) $row->$textfield);
            }
            hash_update($hash, implode('|', $parts) . "\n");
            $count++;
        }
        $rs->close();
        return ['count' => $count, 'sha256' => hash_final($hash)];
    }

    /**
     * BP-03: early stops and result validity, aggregated per strategy.
     *
     * One row per combination of strategy, minimum reached, state of the active scale set at the end, engine status,
     * host stop code, result validity, grade and completion; counts below the minimum cell size are suppressed. A
     * second table gives the lengths per strategy, with 16, 17 and 25 marked.
     *
     * Verdict over the attempts that ended after local_catquiz reached the version with the fix of #172: FAIL when one
     * of them ended with an empty active scale set after the minimum as an error; PASS when there are such attempts
     * and none did; INCONCLUSIVE when there are none yet. Historical attempts are counted, not judged or changed.
     *
     * @param int $mincell
     * @return array
     */
    public static function bp03(int $mincell = 5): array {
        global $DB;
        $fixsince = self::first_upgrade_to('local_catquiz', self::FIX172_VERSION);
        $strategies = self::strategy_names();
        $sql = "SELECT c.id, c.teststrategy, c.status, c.instanceid, c.endtime, c.timemodified, c.userid,
                       a.questionsattempted, a.stopcode, a.resultvalid, a.timefinished, a.instance,
                       p.json AS progress
                  FROM {adaptivequiz_attempt} a
                  JOIN {local_catquiz_attempts} c
                    ON c.attemptid = a.id AND c.component IN ('mod_adaptivequiz', 'adaptivequiz')
             LEFT JOIN {local_catquiz_progress} p ON p.attemptid = c.id AND p.userid = c.userid
                 WHERE a.attemptstate = 'complete'";
        $minimums = self::minimums();
        $graded = self::graded_pairs();
        $completed = self::completed_pairs();
        $combos = [];
        $lengths = [];
        $records = 0;
        $judged = 0;
        $badafterfix = 0;
        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $row) {
            $records++;
            $strategy = $strategies[(int) $row->teststrategy] ?? ('strategy ' . (int) $row->teststrategy);
            $min = $minimums[(int) $row->instance] ?? 0;
            $progress = $row->progress ? json_decode($row->progress, true) : null;
            $scaleset = $progress === null ? 'no progress'
                : (empty($progress['activescales']) ? 'empty' : 'not empty');
            $played = $progress['playedquestions'] ?? [];
            $pilots = count(array_filter((array) $played, fn($q) => !empty($q['is_pilot'])));
            $minreached = (int) $row->questionsattempted >= $min ? 'yes' : 'no';
            $engine = self::status_code((int) $row->status);
            $key = implode('|', [$strategy, $minreached, $scaleset, $engine, (string) $row->stopcode,
                (string) $row->resultvalid, isset($graded[$row->instance . '-' . $row->userid]) ? 'graded' : 'no grade',
                isset($completed[$row->instance . '-' . $row->userid]) ? 'complete' : 'not complete',
                $pilots > 0 ? 'with pilots' : 'no pilots']);
            $combos[$key] = ($combos[$key] ?? 0) + 1;
            $len = (int) $row->questionsattempted;
            $lengths[$strategy][$len] = ($lengths[$strategy][$len] ?? 0) + 1;
            $ended = (int) ($row->timefinished ?: $row->timemodified);
            if ($fixsince !== null && $ended >= $fixsince) {
                $judged++;
                $technical = in_array($row->stopcode, ['error', 'noeligiblequestions'], true);
                if ($scaleset === 'empty' && $minreached === 'yes' && $technical) {
                    $badafterfix++;
                }
            }
        }
        $rs->close();
        $rows = [];
        foreach ($combos as $key => $count) {
            $f = explode('|', $key);
            $rows[] = ['strategy' => $f[0], 'minimum_reached' => $f[1], 'active_scale_set' => $f[2], 'engine_status' => $f[3],
                'host_stopcode' => $f[4], 'resultvalid' => $f[5], 'gradebook' => $f[6], 'completion' => $f[7],
                'pilots' => $f[8], 'attempts' => self::cell($count, $mincell)];
        }
        $lengthrows = [];
        foreach ($lengths as $strategy => $bylength) {
            ksort($bylength);
            foreach ($bylength as $len => $count) {
                $lengthrows[] = ['strategy' => $strategy, 'answered' => $len,
                    'marked' => in_array($len, self::MARKED_LENGTHS, true), 'attempts' => self::cell($count, $mincell)];
            }
        }
        $notes = [
            "Zellen unter $mincell als \"<$mincell\" unterdrückt.",
            'Historische Versuche werden gezählt, nicht neu bewertet.',
        ];
        if ($fixsince === null) {
            $status = self::INCONCLUSIVE;
            $notes[] = 'Die Version mit dem Fix von #172 ist laut upgrade_log noch nicht installiert.';
        } else if ($judged === 0) {
            $status = self::INCONCLUSIVE;
            $notes[] = 'Seit dem Fix (' . self::iso($fixsince) . ') ist kein Versuch beendet worden.';
        } else {
            $status = $badafterfix ? self::FAIL : self::PASS;
            $notes[] = "Seit dem Fix (" . self::iso($fixsince) . ") beendet: $judged; davon mit leerem Skalensatz nach dem "
                . "Minimum als Fehler oder leerer Pool: $badafterfix.";
        }
        return self::result('BP-03', $status, $records, ['combinations' => $rows, 'lengths' => $lengthrows], $notes, 0);
    }

    /**
     * BP-04: items and their context; provenance of the stored abilities.
     *
     * Counted per CAT context: item assignments, items with an active parameter set by its status, and items whose
     * active parameters belong to another context (mixed context). Abilities by source and validity. Whether the
     * standard errors are right needs the golden oracle of the tests (#163) and is not decided here.
     *
     * @param int $mincell
     * @return array
     */
    public static function bp04(int $mincell = 5): array {
        global $DB;
        $percontext = [];
        $mixed = 0;
        $records = 0;
        $rs = $DB->get_recordset_sql(
            "SELECT i.id, i.contextid, i.activeparamid, ip.contextid AS paramcontext, ip.status AS paramstatus, ip.usable
               FROM {local_catquiz_items} i
          LEFT JOIN {local_catquiz_itemparams} ip ON ip.id = i.activeparamid"
        );
        foreach ($rs as $row) {
            $records++;
            $ctx = (int) $row->contextid;
            $percontext[$ctx]['items'] = ($percontext[$ctx]['items'] ?? 0) + 1;
            if ($row->activeparamid === null) {
                $kind = 'no active parameters';
            } else if ($row->paramcontext === null) {
                $kind = 'active parameters missing';
            } else {
                $kind = 'status ' . (int) $row->paramstatus . ((int) $row->usable ? '' : ', not usable');
                if ((int) $row->paramcontext !== $ctx) {
                    $mixed++;
                    $percontext[$ctx]['mixed context'] = ($percontext[$ctx]['mixed context'] ?? 0) + 1;
                }
            }
            $percontext[$ctx][$kind] = ($percontext[$ctx][$kind] ?? 0) + 1;
        }
        $rs->close();
        foreach ($percontext as $ctx => $counts) {
            $percontext[$ctx] = array_map(fn($n) => self::cell($n, $mincell), $counts);
        }
        $abilities = [];
        $rs = $DB->get_recordset_sql(
            "SELECT resultsource, isvalid, isprimary, CASE WHEN attemptid IS NULL THEN 0 ELSE 1 END AS ofattempt,
                    COUNT(1) AS n
               FROM {local_catquiz_personparams}
           GROUP BY resultsource, isvalid, isprimary, CASE WHEN attemptid IS NULL THEN 0 ELSE 1 END"
        );
        foreach ($rs as $row) {
            $abilities[] = ['source' => $row->resultsource, 'isvalid' => $row->isvalid, 'isprimary' => $row->isprimary,
                'of_attempt' => (int) $row->ofattempt, 'rows' => self::cell((int) $row->n, $mincell)];
        }
        $rs->close();
        $notes = [
            'Kontextvermischung: aktive Parameter aus einem anderen Kontext als das Item.',
            'Ob SE und Fähigkeiten fachlich richtig sind, entscheidet das Orakel der Tests (#163), nicht diese Zählung.',
        ];
        if (!$mixed) {
            $notes[] = 'Keine Kontextvermischung; ohne Orakel kein PASS.';
        }
        return self::result('BP-04', $mixed ? self::FAIL : self::INCONCLUSIVE, $records, [
            'items_per_context' => $percontext,
            'mixed_context_items' => $mixed,
            'abilities' => $abilities,
        ], $notes, 0);
    }

    /**
     * BP-05: runtime of the hot path from the stored traces.
     *
     * Per kind of request: number, percentiles 50/90/95/99 and maximum of the request time and of the number of
     * queries, the period and the database. Without traces: NOT_RUN. Without an agreed SLO there is no verdict;
     * with one (milliseconds for p95) the check passes or fails on it.
     *
     * @param int|null $slop95ms
     * @return array
     */
    public static function bp05(?int $slop95ms = null): array {
        global $DB;
        $bykind = [];
        $first = null;
        $last = null;
        $rs = $DB->get_recordset('local_catquiz_trace', null, '', 'id, kind, requestms, dbqueries, timecreated');
        foreach ($rs as $row) {
            $bykind[$row->kind]['ms'][] = (float) $row->requestms;
            $bykind[$row->kind]['q'][] = (int) $row->dbqueries;
            $first = $first === null ? (int) $row->timecreated : min($first, (int) $row->timecreated);
            $last = $last === null ? (int) $row->timecreated : max($last, (int) $row->timecreated);
        }
        $rs->close();
        if (!$bykind) {
            return self::result('BP-05', self::NOT_RUN, 0, [], [
                'Keine Traces gespeichert; Tracing für einen Messzeitraum einschalten.',
            ]);
        }
        $values = ['database' => $DB->get_dbfamily(), 'period' => [self::iso($first), self::iso($last)], 'kinds' => []];
        $records = 0;
        $status = $slop95ms === null ? self::INCONCLUSIVE : self::PASS;
        foreach ($bykind as $kind => $series) {
            $records += count($series['ms']);
            $ms = self::percentiles($series['ms']);
            $values['kinds'][$kind] = [
                'n' => count($series['ms']),
                'request_ms' => $ms,
                'queries' => self::percentiles($series['q']),
            ];
            if ($slop95ms !== null && $ms['p95'] > $slop95ms) {
                $status = self::FAIL;
            }
        }
        $notes = ['Vergleichsbasis aus den Issues: 70-82 s sowie 85,29 s mit 242 SQL (historische Problemfälle).'];
        $notes[] = $slop95ms === null ? 'Ohne vereinbarte SLO kein Urteil (--slo-p95-ms).' : "SLO: p95 <= $slop95ms ms je Art.";
        return self::result('BP-05', $status, $records, $values, $notes, $slop95ms);
    }

    /**
     * BP-06: history and retention of finished attempts.
     *
     * Counted: finished CAT attempts with and without saved progress, with and without a stored final result
     * (abilities bound to the attempt), and for LOWESTSUB the subscale results by validity. FAIL when a finished
     * attempt has neither progress nor a final result (its result can no longer be shown); PASS when there are
     * finished attempts and none of them is in that state; INCONCLUSIVE without finished attempts.
     *
     * @param int $mincell
     * @return array
     */
    public static function bp06(int $mincell = 5): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT c.id, c.teststrategy,
                    (SELECT COUNT(1) FROM {local_catquiz_progress} p WHERE p.attemptid = c.id AND p.userid = c.userid) AS progress,
                    (SELECT COUNT(1) FROM {local_catquiz_personparams} pp WHERE pp.attemptid = c.id) AS results
               FROM {local_catquiz_attempts} c
               JOIN {adaptivequiz_attempt} a ON a.id = c.attemptid
              WHERE a.attemptstate = 'complete' AND c.component IN ('mod_adaptivequiz', 'adaptivequiz')"
        );
        $counts = ['with progress' => 0, 'without progress' => 0, 'with final result' => 0,
            'only final result (no progress)' => 0, 'neither' => 0];
        $lowest = [];
        foreach ($rows as $row) {
            $hasprogress = (int) $row->progress > 0;
            $hasresult = (int) $row->results > 0;
            $counts[$hasprogress ? 'with progress' : 'without progress']++;
            if ($hasresult) {
                $counts['with final result']++;
            }
            if ($hasresult && !$hasprogress) {
                $counts['only final result (no progress)']++;
            }
            if (!$hasresult && !$hasprogress) {
                $counts['neither']++;
            }
        }
        require_once(__DIR__ . '/../../../lib.php');
        $lowestids = array_keys(array_filter($rows, fn($r) => (int) $r->teststrategy === LOCAL_CATQUIZ_STRATEGY_LOWESTSUB));
        if ($lowestids) {
            [$insql, $params] = $DB->get_in_or_equal($lowestids, SQL_PARAMS_NAMED);
            foreach (
                $DB->get_records_sql(
                    "SELECT isvalid, isprimary, COUNT(1) AS n FROM {local_catquiz_personparams}
                  WHERE attemptid $insql GROUP BY isvalid, isprimary",
                    $params
                ) as $r
            ) {
                $lowest[] = ['isvalid' => $r->isvalid, 'isprimary' => $r->isprimary, 'rows' => self::cell((int) $r->n, $mincell)];
            }
        }
        $neither = $counts['neither'];
        $status = !$rows ? self::INCONCLUSIVE : ($neither ? self::FAIL : self::PASS);
        return self::result('BP-06', $status, count($rows), [
            'finished_attempts' => array_map(fn($n) => self::cell($n, $mincell), $counts),
            'lowestsub_results' => $lowest,
            'chart_errors' => null,
        ], [
            'Fehler beim Zeichnen der Diagramme stehen nicht in der Datenbank (Browser-Konsole bzw. Behat).',
            'Ob Lesen keinen Fortschritt anlegt, zeigt der Vergleich zweier Läufe vor und nach dem Öffnen von Ergebnisseiten.',
        ], 0);
    }

    /**
     * BP-07: CI, accessibility and runtime runs - not in the database.
     *
     * @return array
     */
    public static function bp07(): array {
        return self::result('BP-07', self::NOT_RUN, 0, [], [
            'Belege kommen aus GitHub Actions (Run-URL, Jobliste, SHAs, Testzahlen, Artefakte) und dem NVDA-Lauf, '
                . 'nicht aus Moodle.',
        ]);
    }

    /**
     * Percentiles 50/90/95/99 and maximum (nearest rank).
     *
     * @param array $values
     * @return array
     */
    public static function percentiles(array $values): array {
        sort($values);
        $n = count($values);
        $at = fn(float $p) => $values[max(0, (int) ceil($p * $n) - 1)];
        return ['p50' => $at(0.50), 'p90' => $at(0.90), 'p95' => $at(0.95), 'p99' => $at(0.99), 'max' => $values[$n - 1]];
    }

    /**
     * Time of the first upgrade of a plugin to at least the given version, from upgrade_log; null if none.
     *
     * @param string $component
     * @param int $version
     * @return int|null
     */
    public static function first_upgrade_to(string $component, int $version): ?int {
        global $DB;
        $time = $DB->get_field_sql(
            "SELECT MIN(timemodified) FROM {upgrade_log} WHERE plugin = :plugin AND version >= :version",
            ['plugin' => $component, 'version' => (string) $version]
        );
        return $time ? (int) $time : null;
    }

    /**
     * The code of an engine status (e.g. "stopscalescomplete"), not its translated text.
     *
     * @param int $value
     * @return string
     */
    public static function status_code(int $value): string {
        static $codes = null;
        if ($codes === null) {
            $codes = [];
            foreach ((new \ReflectionClass(status::class))->getConstants() as $code) {
                if (is_string($code)) {
                    try {
                        $codes[status::to_int($code)] = $code;
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        }
        return $codes[$value] ?? ('status ' . $value);
    }

    /**
     * Names of the strategies.
     *
     * @return array
     */
    private static function strategy_names(): array {
        require_once(__DIR__ . '/../../../lib.php');
        return [
            LOCAL_CATQUIZ_STRATEGY_FASTEST => 'FASTEST', LOCAL_CATQUIZ_STRATEGY_BALANCED => 'BALANCED',
            LOCAL_CATQUIZ_STRATEGY_ALLSUBS => 'ALLSUBS', LOCAL_CATQUIZ_STRATEGY_LOWESTSUB => 'LOWESTSUB',
            LOCAL_CATQUIZ_STRATEGY_HIGHESTSUB => 'HIGHESTSUB', LOCAL_CATQUIZ_STRATEGY_PILOT => 'PILOT',
            LOCAL_CATQUIZ_STRATEGY_CLASSIC => 'CLASSIC', LOCAL_CATQUIZ_STRATEGY_RELSUBS => 'RELSUBS',
        ];
    }

    /**
     * The minimum number of questions per activity, from the test settings.
     *
     * @return array instance id => minimum
     */
    private static function minimums(): array {
        global $DB;
        $out = [];
        foreach (
            $DB->get_records_select(
                'local_catquiz_tests',
                "component IN ('mod_adaptivequiz', 'adaptivequiz')",
                null,
                '',
                'id, componentid, json'
            ) as $test
        ) {
            $json = json_decode((string) $test->json);
            $out[(int) $test->componentid] = (int) ($json->catquiz_minquestions ?? 0);
        }
        return $out;
    }

    /**
     * Pairs of activity and person with a grade in the gradebook.
     *
     * @return array "instance-user" => true
     */
    private static function graded_pairs(): array {
        global $DB;
        $out = [];
        $rs = $DB->get_recordset_sql(
            "SELECT g.id, gi.iteminstance, g.userid
               FROM {grade_grades} g
               JOIN {grade_items} gi ON gi.id = g.itemid
              WHERE gi.itemtype = 'mod' AND gi.itemmodule = 'adaptivequiz' AND g.finalgrade IS NOT NULL"
        );
        foreach ($rs as $row) {
            $out[$row->iteminstance . '-' . $row->userid] = true;
        }
        $rs->close();
        return $out;
    }

    /**
     * Pairs of activity and person whose activity is complete (completion states 1 and 2).
     *
     * @return array "instance-user" => true
     */
    private static function completed_pairs(): array {
        global $DB;
        $out = [];
        $rs = $DB->get_recordset_sql(
            "SELECT cmc.id, cm.instance, cmc.userid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {modules} m ON m.id = cm.module AND m.name = 'adaptivequiz'
              WHERE cmc.completionstate IN (1, 2)"
        );
        foreach ($rs as $row) {
            $out[$row->instance . '-' . $row->userid] = true;
        }
        $rs->close();
        return $out;
    }

    /**
     * A Unix time as ISO 8601 (UTC), or null.
     *
     * @param int $time
     * @return string|null
     */
    private static function iso(int $time): ?string {
        return $time > 0 ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }
}
