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

namespace local_catquiz\local\result;

/**
 * Issue #164: every reader of local_catquiz_personparams, by name, with the rule it follows.
 *
 * A person's value - start value, peer, statistics - is the newest admissible row (personparam_repository::
 * admissible_sql()). Rows of one attempt are read by that attempt's id; diagnostics, privacy and the writers see
 * every row on purpose. A file that touches the table and is not listed here fails this test: a new reader has to
 * say which rule it follows before it ships. A listed reader of the person's value must use the rule.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class personparam_readers_test extends \advanced_testcase {
    /** @var string Reads the value of a person: must apply the rule. */
    private const VALUE = 'value';

    /** @var string Reads the rows of one given attempt (its final results). */
    private const ATTEMPT = 'attempt';

    /** @var string Defines the rule. */
    private const RULE = 'rule';

    /** @var string Writes or deletes rows; reads none as a value. */
    private const WRITER = 'writer';

    /** @var string Shows every row on purpose: diagnostics, operator checks, privacy. */
    private const ALL = 'all rows on purpose';

    /** @var string Mentions the table in comments or language strings only. */
    private const COMMENT = 'comment only';

    /** @var string Schema and upgrade steps. */
    private const SCHEMA = 'schema and upgrade';

    /**
     * Every file of the plugin that names the table, and how it reads it.
     */
    private const READERS = [
        'classes/catquiz.php' => [self::VALUE, self::WRITER],
        'classes/local/model/model_person_param_list.php' => [self::WRITER],
        'classes/local/operator/operator_checks.php' => [self::ALL],
        'classes/local/result/global_scale_validity.php' => [self::COMMENT],
        'classes/local/result/personparam_repository.php' => [self::RULE, self::VALUE, self::ATTEMPT, self::WRITER],
        'classes/local/result/result_provenance.php' => [self::ATTEMPT],
        'classes/local/result/scale_result.php' => [self::RULE],
        'classes/local/statistics/attempt_population.php' => [self::VALUE, self::ATTEMPT],
        'classes/local/statistics/learning_progress_series.php' => [self::ATTEMPT],
        'classes/local/statistics/peer_population.php' => [self::VALUE],
        'classes/local/statistics/statistics_diagnosis.php' => [self::ALL],
        'classes/privacy/provider.php' => [self::ALL],
        'classes/teststrategy/context/loader/personability_loader.php' => [self::VALUE],
        'classes/teststrategy/feedbackgenerator/comparetotestaverage.php' => [self::VALUE],
        // Issue #161: deletes the rows of the attempts of its own benchmark test before a run.
        'cli/benchmark_attempts.php' => [self::WRITER],
        'cli/measure_attempt.php' => [self::WRITER],
        'db/upgrade.php' => [self::SCHEMA],
        'db/upgradelib.php' => [self::SCHEMA],
        'lang/de/local_catquiz.php' => [self::COMMENT],
        'lang/en/local_catquiz.php' => [self::COMMENT],
    ];

    /** @var string[] The calls that apply the rule. */
    private const RULE_CALLS = ['admissible_sql(', 'reference_sql(', 'valid_measurement_sql(', 'get_latest_valid(',
        'get_latest_valid_by_scale(', 'peer_population::sql(', 'get_person_abilities('];

    /**
     * The PHP files of the plugin, without tests and third-party code.
     *
     * @return string[] Paths relative to the plugin.
     */
    private static function files(): array {
        global $CFG;
        $root = $CFG->dirroot . '/local/catquiz';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(tests|vendor|node_modules|catquizcentralhub|\.)#', $path)) {
                continue;
            }
            if (preg_match('#(^|/)tests/#', $path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }

    /**
     * Every file that names the table is listed, and every listed file still names it.
     */
    public function test_every_reader_is_listed(): void {
        global $CFG;
        $found = [];
        foreach (self::files() as $path) {
            $code = file_get_contents($CFG->dirroot . '/local/catquiz/' . $path);
            if (str_contains($code, 'local_catquiz_personparams')) {
                $found[] = $path;
            }
        }
        $this->assertSame([], array_values(array_diff($found, array_keys(self::READERS))), 'Unlisted readers.');
        $this->assertSame([], array_values(array_diff(array_keys(self::READERS), $found)), 'Listed, but gone.');
    }

    /**
     * A reader of the person's value applies the rule.
     */
    public function test_a_reader_of_the_value_applies_the_rule(): void {
        global $CFG;
        foreach (self::READERS as $path => $kinds) {
            if (!in_array(self::VALUE, $kinds, true)) {
                continue;
            }
            $code = file_get_contents($CFG->dirroot . '/local/catquiz/' . $path);
            $calls = array_filter(self::RULE_CALLS, fn($call) => str_contains($code, $call));
            $this->assertNotEmpty($calls, "$path reads the value of a person without the rule.");
        }
    }

    /**
     * The functions that hand a person's value to the engine - start value, prior population, peers - choose the same
     * row for the same data.
     */
    public function test_the_value_readers_agree(): void {
        global $DB;
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $row = fn(array $f) => (int) $DB->insert_record('local_catquiz_personparams', (object) ($f + [
            'userid' => $user, 'contextid' => 4711, 'catscaleid' => 77, 'ability' => 0.0, 'isvalid' => 1,
            'resultsource' => 'current', 'timecreated' => time(), 'timemodified' => time(),
        ]));
        $row(['attemptid' => 1, 'ability' => 0.4, 'standarderror' => 0.3, 'n' => 9]);
        $row(['attemptid' => 2, 'ability' => 0.8, 'isvalid' => 0, 'standarderror' => 0.3, 'n' => 9,
            'validationstatus' => 'not_primary']);
        $row(['attemptid' => 3, 'ability' => -2.0, 'isvalid' => 0, 'validationstatus' => personparam_repository::PROVISIONAL]);
        $row(['ability' => -3.0]);

        $values = [
            'start value' => (float) personparam_repository::get_latest_valid($user, 4711, 77)->ability,
            'current' => (float) \local_catquiz\catquiz::get_current_person_param($user, 4711, 77)->ability,
            'person abilities' => (float) current(\local_catquiz\catquiz::get_person_abilities(4711, [77], [$user]))->ability,
        ];
        [$sql, $params] = \local_catquiz\local\statistics\peer_population::sql(4711, 77);
        $values['peer'] = (float) $DB->get_record_sql("SELECT x.* FROM ($sql) x WHERE x.userid = :u", $params + ['u' => $user])
            ->ability;
        $this->assertSame(['start value' => 0.8, 'current' => 0.8, 'person abilities' => 0.8, 'peer' => 0.8], $values);
    }
}
