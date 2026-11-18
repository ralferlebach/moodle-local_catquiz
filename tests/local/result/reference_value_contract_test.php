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

use advanced_testcase;
use local_catquiz\catquiz;
use local_catquiz\local\statistics\peer_population;
use local_catquiz\teststrategy\context\loader\personability_loader;
use local_catquiz\teststrategy\progress;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Issue #164: the result contract - which rows of local_catquiz_personparams are reference values.
 *
 * A reference value may be a start value of a later attempt (carry-over) and may stand for a person in a peer
 * group. An intermediate estimate of an attempt may not, also not in old data with isvalid = 1. The running
 * attempt goes on from its own intermediate estimates, and only from its own. See doc/ergebnisvertrag-164.md.
 *
 * Every reader is checked with real Moodle DML: the repository (carry-over), the loader of the running attempt,
 * get_person_abilities(), get_current_person_param(), the peer population and the peer statistics.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(personparam_repository::class)]
final class reference_value_contract_test extends advanced_testcase {
    /** @var int CAT context of the tests. */
    private const CTX = 4711;

    /** @var int Scale of the tests. */
    private const SCALE = 77;

    /**
     * Writes one row of local_catquiz_personparams.
     *
     * @param int $userid
     * @param array $fields
     * @return int id
     */
    private function row(int $userid, array $fields): int {
        global $DB;
        return (int) $DB->insert_record('local_catquiz_personparams', (object) ($fields + [
            'userid' => $userid, 'contextid' => self::CTX, 'catscaleid' => self::SCALE, 'ability' => 0.0,
            'isvalid' => 1, 'isprimary' => 0, 'resultsource' => 'current', 'timecreated' => time(),
            'timemodified' => time(),
        ]));
    }

    /**
     * The states of the contract matrix: the fields of the row and whether it is a reference value.
     *
     * @return array
     */
    public static function states(): array {
        return [
            'running (provisional, since 2026111704)' => [['attemptid' => 501, 'isvalid' => 0,
                'validationstatus' => 'provisional'], false],
            'final valid' => [['attemptid' => 501, 'standarderror' => 0.3, 'n' => 12, 'fraction' => 0.5,
                'isprimary' => 1, 'validationstatus' => ''], true],
            'final invalid' => [['attemptid' => 501, 'isvalid' => 0, 'standarderror' => 0.9, 'n' => 3,
                'validationstatus' => 'se_max'], true],
            'prior after a measured value' => [['attemptid' => 501, 'resultsource' => 'prior',
                '_before' => ['attemptid' => 500, 'standarderror' => 0.3, 'n' => 9]], true],
            'prior without a measured value (default start value)' => [['attemptid' => 501, 'resultsource' => 'prior'],
                false],
            'prior after an invalid result only' => [['attemptid' => 501, 'resultsource' => 'prior',
                '_before' => ['attemptid' => 500, 'isvalid' => 0, 'standarderror' => 1.3, 'n' => 2]], false],
            'prior after a prior of a default only' => [['attemptid' => 502, 'resultsource' => 'prior',
                '_before' => ['attemptid' => 501, 'resultsource' => 'prior']], false],
            'prior after a measured value in another context' => [['attemptid' => 501, 'resultsource' => 'prior',
                '_before' => ['attemptid' => 500, 'standarderror' => 0.3, 'contextid' => 4712]], false],
            'prior without context after a measured value without context' => [['attemptid' => 501,
                'resultsource' => 'prior', 'contextid' => null,
                '_before' => ['attemptid' => 500, 'standarderror' => 0.3, 'contextid' => null]], true],
            'legacy (before 26.09.2026)' => [['resultsource' => 'legacy', 'standarderror' => 0.4], true],
            'legacy without SE and N' => [['resultsource' => 'legacy'], true],
            'recalibration (no attempt)' => [['resultsource' => 'recalibration', 'standarderror' => 0.2], true],
            'old intermediate: current without attempt' => [['standarderror' => null], false],
            'old intermediate: current of an attempt without SE' => [['attemptid' => 501], false],
            'historically orphaned final (attempt deleted)' => [['attemptid' => 999999, 'standarderror' => 0.3,
                'n' => 10], true],
            'revalidated final' => [['attemptid' => 501, 'standarderror' => 0.35, 'n' => 12,
                'rangebackfilled' => 1], true],
            'resultsource NULL (oldest rows)' => [['resultsource' => null], true],
        ];
    }

    /**
     * PHP rule and SQL rule agree for every state of the matrix.
     *
     * @param array $fields
     * @param bool $reference
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('states')]
    public function test_the_rule_in_php_and_sql(array $fields, bool $reference): void {
        global $DB;
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        if (isset($fields['_before'])) {
            $this->row($user, $fields['_before']);
            unset($fields['_before']);
        }
        $id = $this->row($user, $fields);
        $row = $DB->get_record('local_catquiz_personparams', ['id' => $id]);

        $this->assertSame($reference, personparam_repository::is_reference($row));
        $this->assertSame($reference, $DB->record_exists_select(
            'local_catquiz_personparams',
            'id = :id AND ' . personparam_repository::reference_sql(),
            ['id' => $id]
        ));
        $this->assertSame($reference, $DB->record_exists_sql(
            'SELECT 1 FROM {local_catquiz_personparams} p WHERE p.id = :id AND ' . personparam_repository::reference_sql('p'),
            ['id' => $id]
        ));
    }

    /**
     * A running attempt's estimate is nobody's start value and no peer value; the last result stays the reference.
     */
    public function test_an_intermediate_estimate_is_not_read_as_a_reference(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $final = $this->row($user, ['attemptid' => 400, 'ability' => 1.25, 'standarderror' => 0.3, 'n' => 10]);
        // The next attempt runs: its estimate, and an old-style one with isvalid = 1 from before the marking.
        personparam_repository::save_provisional($user, self::CTX, self::SCALE, -2.0, 401);
        $this->row($user, ['ability' => -2.5]);

        $this->assertSame($final, (int) personparam_repository::get_latest_valid($user, self::CTX, self::SCALE)->id);
        $byscale = personparam_repository::get_latest_valid_by_scale($user, self::CTX, [self::SCALE]);
        $this->assertSame($final, (int) $byscale[self::SCALE]->id);
        $this->assertSame($final, (int) catquiz::get_current_person_param($user, self::CTX, self::SCALE)->id);
        $abilities = catquiz::get_person_abilities(self::CTX, [self::SCALE], [$user]);
        $this->assertSame([$final], array_map('intval', array_keys($abilities)));
        $this->assertSame([1.25], $this->peers());
    }

    /**
     * The defect of #164 with old data and the readers alone (no new API, so the same test runs against the code
     * before the fix and fails there): an intermediate estimate with isvalid = 1, newer than the last result, was
     * read as start value, as current ability, as ability in lists and as peer value.
     */
    public function test_old_intermediate_rows_do_not_win(): void {
        global $DB;
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $final = (int) $DB->insert_record('local_catquiz_personparams', (object) ['userid' => $user, 'contextid' => self::CTX,
            'catscaleid' => self::SCALE, 'attemptid' => 400, 'ability' => 1.25, 'standarderror' => 0.3, 'n' => 10,
            'isvalid' => 1, 'isprimary' => 1, 'resultsource' => 'current', 'timecreated' => time(), 'timemodified' => time()]);
        // Written while the next attempt ran (before 2026111704): no attempt, no SE, valid.
        foreach ([-1.0, -2.0, -2.5] as $estimate) {
            $DB->insert_record('local_catquiz_personparams', (object) ['userid' => $user, 'contextid' => self::CTX,
                'catscaleid' => self::SCALE, 'ability' => $estimate, 'isvalid' => 1, 'isprimary' => 0,
                'resultsource' => 'current', 'timecreated' => time(), 'timemodified' => time()]);
        }
        // Another person with only intermediate estimates.
        $DB->insert_record('local_catquiz_personparams', (object) ['userid' => $other, 'contextid' => self::CTX,
            'catscaleid' => self::SCALE, 'attemptid' => 500, 'ability' => 0.7, 'isvalid' => 1, 'isprimary' => 0,
            'resultsource' => 'current', 'timecreated' => time(), 'timemodified' => time()]);

        $this->assertSame($final, (int) personparam_repository::get_latest_valid($user, self::CTX, self::SCALE)->id);
        $byscale = personparam_repository::get_latest_valid_by_scale($user, self::CTX, [self::SCALE]);
        $this->assertSame($final, (int) $byscale[self::SCALE]->id);
        $this->assertSame($final, (int) catquiz::get_current_person_param($user, self::CTX, self::SCALE)->id);
        $this->assertSame([$final], array_map('intval', array_keys(catquiz::get_person_abilities(self::CTX, [self::SCALE]))));
        $this->assertSame([1.25], $this->peers());
    }

    /**
     * The running attempt goes on from its own estimate - not from another person's, another context's or another
     * attempt's - and before its first estimate from the reference value.
     */
    public function test_the_running_attempt_reads_its_own_estimate_only(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $this->row($user, ['attemptid' => 400, 'ability' => 1.25, 'standarderror' => 0.3, 'n' => 10]);
        personparam_repository::save_provisional($other, self::CTX, self::SCALE, -3.0, 402);
        personparam_repository::save_provisional($user, self::CTX + 1, self::SCALE, -1.0, 401);
        personparam_repository::save_provisional($user, self::CTX, self::SCALE, -0.5, 399);

        $this->assertSame([self::SCALE => 1.25], $this->load($user, 401), 'Before its first estimate: the reference.');

        personparam_repository::save_provisional($user, self::CTX, self::SCALE, 0.75, 401);
        $this->assertSame([self::SCALE => 0.75], $this->load($user, 401), 'After it: its own estimate.');
        $this->assertSame([self::SCALE => 1.25], $this->load($user, 403), 'A new attempt starts from the reference.');
    }

    /**
     * One row per attempt and scale however long the attempt runs; none once it is finalised.
     */
    public function test_intermediate_rows_are_bounded_and_discarded(): void {
        global $DB;
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $before = $DB->count_records('local_catquiz_personparams');
        foreach (range(1, 25) as $step) {
            personparam_repository::save_provisional($user, self::CTX, self::SCALE, $step / 10, 401);
            personparam_repository::save_provisional($user, self::CTX, self::SCALE + 1, -$step / 10, 401);
        }
        $this->assertSame($before + 2, $DB->count_records('local_catquiz_personparams'), 'One row per scale.');
        $this->assertEqualsWithDelta(2.5, (float) personparam_repository::get_provisional(
            401,
            $user,
            self::CTX,
            [self::SCALE]
        )[self::SCALE]->ability, 1e-9);

        $this->assertSame(2, personparam_repository::discard_provisional(401));
        $this->assertSame($before, $DB->count_records('local_catquiz_personparams'));
        $this->assertSame(0, personparam_repository::discard_provisional(401), 'Again: nothing left, no error.');
    }

    /**
     * The pattern of 10.129 groups on CLARA: a valid 'prior' and an invalid 'current' of the same attempt. The prior
     * stays the reference; the invalid result is none.
     */
    public function test_a_valid_prior_with_an_invalid_current(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->row($user, ['attemptid' => 300, 'ability' => 0.4, 'standarderror' => 0.3, 'n' => 9]);
        $prior = $this->row($user, ['attemptid' => 301, 'ability' => 0.4, 'resultsource' => 'prior']);
        $this->row($user, ['attemptid' => 301, 'ability' => 3.9, 'isvalid' => 0, 'standarderror' => 1.4, 'n' => 2,
            'validationstatus' => 'se_max']);

        $this->assertSame($prior, (int) personparam_repository::get_latest_valid($user, self::CTX, self::SCALE)->id);
        $this->assertSame([0.4], $this->peers());
    }

    /**
     * F1/F2: carry-over and peer population take the same row of every person - one definition (admissible_sql()).
     * A subscale measured validly but not reported (isvalid 0, not_primary) counts for both; an intermediate
     * estimate, a statistically invalid result and a 'prior' of the default count for neither.
     */
    public function test_carry_over_and_peers_choose_the_same_row(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $people = [
            // Measured, not reported: newer than the valid primary result before it.
            'notprimary' => [['attemptid' => 600, 'ability' => 0.2, 'standarderror' => 0.3, 'n' => 9],
                ['attemptid' => 601, 'ability' => 0.9, 'isvalid' => 0, 'standarderror' => 0.3, 'n' => 9,
                    'validationstatus' => 'not_primary']],
            // A statistically invalid result after a valid one: the valid one.
            'invalidlater' => [['attemptid' => 610, 'ability' => -0.6, 'standarderror' => 0.3, 'n' => 9],
                ['attemptid' => 611, 'ability' => 2.9, 'isvalid' => 0, 'standarderror' => 1.4, 'n' => 2,
                    'validationstatus' => 'se_max,not_primary']],
            // An intermediate estimate, old and new style, after a result: the result.
            'intermediate' => [['attemptid' => 620, 'ability' => 1.4, 'standarderror' => 0.3, 'n' => 9],
                ['ability' => -1.0], ['attemptid' => 621, 'ability' => -1.5, 'isvalid' => 0,
                    'validationstatus' => 'provisional']],
            // Legacy and a carried 'prior' after it.
            'legacy' => [['resultsource' => 'legacy', 'ability' => 0.5],
                ['attemptid' => 630, 'ability' => 0.5, 'resultsource' => 'prior']],
            // Only a 'prior' of the default: nobody.
            'default' => [['attemptid' => 640, 'ability' => 0.0, 'resultsource' => 'prior']],
        ];
        $chosen = [];
        foreach ($people as $name => $rows) {
            $userid = (int) $gen->create_user()->id;
            foreach ($rows as $fields) {
                $this->row($userid, $fields);
            }
            $carry = personparam_repository::get_latest_valid($userid, self::CTX, self::SCALE);
            $byscale = personparam_repository::get_latest_valid_by_scale($userid, self::CTX, [self::SCALE]);
            [$sql, $params] = peer_population::sql(self::CTX, self::SCALE);
            $peer = $DB->get_record_sql("SELECT x.* FROM ($sql) x WHERE x.userid = :who", $params + ['who' => $userid]);
            $chosen[$name] = [
                $carry ? round((float) $carry->ability, 4) : null,
                isset($byscale[self::SCALE]) ? round((float) $byscale[self::SCALE]->ability, 4) : null,
                $peer ? round((float) $peer->ability, 4) : null,
            ];
        }
        $this->assertSame([
            'notprimary' => [0.9, 0.9, 0.9],
            'invalidlater' => [-0.6, -0.6, -0.6],
            'intermediate' => [1.4, 1.4, 1.4],
            'legacy' => [0.5, 0.5, 0.5],
            'default' => [null, null, null],
        ], $chosen);
    }

    /**
     * The CLARA pattern without a measured value before it (10,129 pairs on 17.11.2026): the 'prior' row carries the
     * default start value of a person who was never measured validly on the scale. It is kept (no data change), but
     * it is no reference value: no carry-over, no peer - and the person's peer set stays empty.
     */
    public function test_a_prior_of_the_default_is_no_reference(): void {
        global $DB;
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $prior = $this->row($user, ['attemptid' => 301, 'ability' => 0.0, 'resultsource' => 'prior']);
        $this->row($user, ['attemptid' => 301, 'ability' => 3.9, 'isvalid' => 0, 'standarderror' => 1.4, 'n' => 2,
            'validationstatus' => 'se_max']);
        $this->row($other, ['attemptid' => 302, 'ability' => 0.7, 'standarderror' => 0.3, 'n' => 9]);
        $rows = $DB->count_records('local_catquiz_personparams');

        $this->assertNull(personparam_repository::get_latest_valid($user, self::CTX, self::SCALE));
        $this->assertSame([], personparam_repository::get_latest_valid_by_scale($user, self::CTX, [self::SCALE]));
        $this->assertSame([0.7], $this->peers(), 'Only the measured person is a peer.');
        $this->assertTrue($DB->record_exists('local_catquiz_personparams', ['id' => $prior]), 'The row is kept.');
        $this->assertSame($rows, $DB->count_records('local_catquiz_personparams'), 'Reading changes nothing.');

        // A later valid measurement makes a later 'prior' of the same person a reference again.
        $this->row($user, ['attemptid' => 303, 'ability' => 1.1, 'standarderror' => 0.3, 'n' => 10]);
        $again = $this->row($user, ['attemptid' => 304, 'ability' => 1.1, 'resultsource' => 'prior']);
        $this->assertSame($again, (int) personparam_repository::get_latest_valid($user, self::CTX, self::SCALE)->id);
        $this->assertEqualsCanonicalizing([0.7, 1.1], $this->peers());
    }

    /**
     * Legacy and recalibration rows without an attempt are reference values; a newer one wins over an older result.
     */
    public function test_legacy_and_recalibration_count(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->row($user, ['resultsource' => 'legacy', 'ability' => 0.1]);
        $this->row($user, ['attemptid' => 300, 'ability' => 0.2, 'standarderror' => 0.3, 'n' => 9]);
        $recal = $this->row($user, ['resultsource' => 'recalibration', 'ability' => 0.3]);

        $this->assertSame($recal, (int) personparam_repository::get_latest_valid($user, self::CTX, self::SCALE)->id);
        $this->assertSame([0.3], $this->peers());
    }

    /**
     * Peers: one value per person - the newest reference value -, ties by the highest id, the compared person
     * left out, other contexts and scales apart, saturated values out; the midrank percentile counts the same people.
     */
    public function test_the_peer_population(): void {
        $this->resetAfterTest();
        $users = [];
        foreach (range(1, 5) as $i) {
            $users[$i] = (int) $this->getDataGenerator()->create_user()->id;
        }
        // 1: two results, the newer counts; an intermediate estimate after them changes nothing.
        $this->row($users[1], ['attemptid' => 10, 'ability' => -1.0, 'standarderror' => 0.3, 'n' => 9]);
        $this->row($users[1], ['attemptid' => 11, 'ability' => 0.5, 'standarderror' => 0.3, 'n' => 9]);
        personparam_repository::save_provisional($users[1], self::CTX, self::SCALE, 2.9, 12);
        $this->row($users[1], ['ability' => 2.8]);
        // 2: a tie at 0.5.
        $this->row($users[2], ['attemptid' => 20, 'ability' => 0.5, 'standarderror' => 0.3, 'n' => 9]);
        // 3: only intermediate estimates - no peer.
        personparam_repository::save_provisional($users[3], self::CTX, self::SCALE, 0.1, 30);
        $this->row($users[3], ['ability' => 0.2]);
        // 4: saturated - no peer; in another context and another scale - not here.
        $this->row($users[4], ['attemptid' => 40, 'ability' => 1000.0, 'standarderror' => 0.3, 'n' => 9]);
        $this->row($users[4], ['attemptid' => 41, 'ability' => 0.0, 'standarderror' => 0.3, 'n' => 9,
            'contextid' => self::CTX + 1]);
        $this->row($users[4], ['attemptid' => 42, 'ability' => 0.0, 'standarderror' => 0.3, 'n' => 9,
            'catscaleid' => self::SCALE + 1]);
        // 5: the compared person.
        $this->row($users[5], ['attemptid' => 50, 'ability' => 0.5, 'standarderror' => 0.3, 'n' => 9]);

        $this->assertSame([0.5, 0.5, 0.5], $this->peers());
        $stats = catquiz::get_peer_comparison_stats(self::CTX, self::SCALE, 0.5, $users[5]);
        $this->assertSame([2, 0, 2], [$stats->n, $stats->lowercount, $stats->equalcount]);
        $this->assertEqualsWithDelta(0.5, $stats->meanvalue, 1e-9);
        $this->assertSame([], $this->peers(self::CTX + 2), 'An empty context has no peers.');
    }

    /**
     * Abilities of the peer population, sorted.
     *
     * @param int $contextid
     * @return float[]
     */
    private function peers(int $contextid = self::CTX): array {
        global $DB;
        [$sql, $params] = peer_population::sql($contextid, self::SCALE);
        $values = array_map(fn($r) => round((float) $r->ability, 4), array_values($DB->get_records_sql($sql, $params)));
        sort($values);
        return $values;
    }

    /**
     * The loader of the running attempt, for one CAT attempt.
     *
     * @param int $userid
     * @param int $catattemptid
     * @return array scale => ability
     */
    private function load(int $userid, int $catattemptid): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');
        $loader = new class ($catattemptid) extends personability_loader {
            /** @var int */
            private int $catid;

            /**
             * Constructor.
             *
             * @param int $catid
             */
            public function __construct(int $catid) {
                $this->catid = $catid;
            }

            /**
             * The CAT attempt under test.
             *
             * @param array $context
             * @return int|null
             */
            protected function running_cat_attempt_id(array $context): ?int {
                return $this->catid;
            }
        };
        $progress = $this->createMock(progress::class);
        $progress->method('get_selected_subscales')->willReturn([]);
        (new ReflectionProperty(personability_loader::class, 'progress'))->setValue($loader, $progress);
        $context = ['userid' => $userid, 'contextid' => self::CTX, 'catscaleid' => self::SCALE, 'includesubscales' => false,
            'progress' => $progress];
        return (new ReflectionMethod(personability_loader::class, 'load_saved_personparams'))->invokeArgs($loader, [&$context]);
    }
}
