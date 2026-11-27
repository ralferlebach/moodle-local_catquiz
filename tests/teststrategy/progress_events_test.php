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

namespace local_catquiz\teststrategy;

use advanced_testcase;
use local_catquiz\local\attempt\progress_steps_maintenance;

/**
 * The working state as a head of fixed size and events that are only appended (issue #177).
 *
 * Every answer used to write the whole progress again: its size grew with the answers given, the bytes written with
 * their square. Now a save appends what happened since the last one and writes the head, whose size does not depend on
 * the number of answers. What is read back is what the code read before - played questions in the order they were
 * handed out, by scale, the responses, the given up and excluded questions, the history, the estimates.
 *
 * The answers are real answers of a question usage, so the identities of the usage (step, slot, question attempt) are
 * those of Moodle (AK3).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_catquiz\teststrategy\progress
 * @covers     \local_catquiz\local\attempt\progress_steps_maintenance
 */
final class progress_events_test extends advanced_testcase {
    /** @var int The attempt of the activity. */
    private int $adaptiveattemptid;

    /** @var int The CAT attempt. */
    private int $catattemptid;

    /** @var int The root scale. */
    private int $root;

    /** @var int[] The two subscales below the root. */
    private array $subscales;

    /** @var \question_usage_by_activity The usage of the attempt. */
    private \question_usage_by_activity $quba;

    /** @var int[] Questions of the bank, by number. */
    private array $questions = [];

    /**
     * A running attempt with its CAT attempt, a question usage and a hierarchy root > two subscales.
     *
     * @param int $questions How many questions the bank holds.
     */
    private function attempt(int $questions): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $this->setAdminUser();
        $now = time();
        $this->root = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'name' => 'Root', 'parentid' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->subscales = [];
        foreach (['A', 'B'] as $name) {
            $this->subscales[] = (int) $DB->insert_record('local_catquiz_catscales', (object) [
                'name' => $name, 'parentid' => $this->root, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        \cache::make('local_catquiz', 'catscales')->purge();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        for ($i = 1; $i <= $questions; $i++) {
            $this->questions[$i] = (int) $generator->create_question(
                'truefalse',
                null,
                ['category' => $category->id, 'name' => "Q$i"]
            )->id;
        }
        $this->quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', \context_system::instance());
        $this->quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($this->quba);

        $this->adaptiveattemptid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => 1, 'userid' => $USER->id, 'uniqueid' => $this->quba->get_id(), 'attemptstate' => 'inprogress',
            'attemptstopcriteria' => '', 'questionsattempted' => 0, 'difficultysum' => 0,
            'standarderror' => 1, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->catattemptid = (int) $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $USER->id, 'attemptid' => $this->adaptiveattemptid, 'component' => 'mod_adaptivequiz',
            'contextid' => 9, 'status' => 2, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * The progress of the attempt, as the strategy loads it.
     *
     * @return progress
     */
    private function load(): progress {
        return progress::load(
            $this->adaptiveattemptid,
            'mod_adaptivequiz',
            9,
            (object) ['catquiz_catscales' => $this->root, 'catquiz_selectteststrategy' => 1]
        );
    }

    /**
     * Drops the session cache: the next load reads head and events.
     */
    private function forget(): void {
        \cache::make('local_catquiz', 'adaptivequizattempt')->purge();
        \cache::make('local_catquiz', 'progresscurrent')->purge();
    }

    /**
     * The played question as the selection hands it out.
     *
     * @param int $number
     * @param bool $pilot
     * @return \stdClass
     */
    private function question(int $number, bool $pilot = false): \stdClass {
        $scale = $this->subscales[$number % 2];
        return (object) [
            'id' => (string) $this->questions[$number],
            'componentid' => (string) $this->questions[$number],
            'catscaleid' => (string) $scale,
            'label' => "L$number",
            'model' => 'raschbirnbaum',
            'difficulty' => (string) (0.1 * $number),
            'discrimination' => '1.2',
            'is_pilot' => $pilot,
            'activeparamid' => null,
            'fisherinformation' => [$this->root => 0.2 + $number / 1000, $scale => 0.3],
            'json' => str_repeat('x', 300),
        ];
    }

    /**
     * One answer as the strategy processes it: the question is handed out and saved, answered in the usage, the
     * response is taken in, the estimates are updated and the progress is saved again.
     *
     * @param progress $progress
     * @param int $number
     * @param bool $right
     * @param bool $pilot
     */
    private function answer(progress $progress, int $number, bool $right, bool $pilot = false): void {
        $progress->add_playedquestion($this->question($number, $pilot))->save();
        $slot = $this->quba->add_question(\question_bank::load_question($this->questions[$number]));
        $this->quba->start_question($slot);
        $this->quba->process_action($slot, ['answer' => $right ? 1 : 0, '-submit' => 1]);
        $this->quba->finish_question($slot);
        \question_engine::save_questions_usage_by_activity($this->quba);
        $progress->update_cached_responses();
        $theta = ($right ? 0.1 : -0.1) * $number;
        $progress->set_ability($theta, $this->root);
        $progress->set_ability($theta / 2, (int) $this->question($number)->catscaleid);
        $progress->record_standard_errors([$this->root => 1 / sqrt($number + 1), $this->subscales[0] => INF]);
        $progress->record_answer_state($theta);
        $progress->save();
    }

    /**
     * What the code reads of a progress (the readers of the field inventory, #177 part A).
     *
     * @param progress $progress
     * @return array
     */
    private function view(progress $progress): array {
        $played = [];
        foreach ($progress->get_playedquestions() as $id => $question) {
            $played[(string) $id] = [
                (string) $question->catscaleid,
                (bool) $question->is_pilot,
                (float) $question->difficulty,
                ((array) $question->fisherinformation)[$this->root] ?? null,
            ];
        }
        $byscale = [];
        foreach ($progress->get_playedquestions(true) as $scaleid => $questions) {
            $byscale[$scaleid] = array_map(fn($q) => (string) $q->id, array_values($questions));
        }
        $responses = [];
        foreach ($progress->get_responses() as $id => $response) {
            $responses[(string) $id] = array_diff_key((array) $response, array_flip(progress::RESPONSE_TEXTS));
        }
        $reflection = new \ReflectionClass($progress);
        $get = function (string $name) use ($reflection, $progress) {
            $property = $reflection->getProperty($name);
            return $property->getValue($progress);
        };
        return [
            'played' => $played,
            'byscale' => $byscale,
            'last' => $progress->get_last_question() ? (string) $progress->get_last_question()->id : null,
            'responses' => $responses,
            'excluded' => array_map('intval', $progress->get_excluded_questions()),
            'gaveup' => array_map('intval', $get('gaveupquestions')),
            'history' => $progress->get_answer_history(),
            'abilities' => $progress->get_abilities(),
            'answered' => $progress->get_num_answered_questions(),
            'productive' => $progress->get_num_answered_productive_questions(),
            'debug' => $progress->get_debug_rows(),
        ];
    }

    /**
     * The events of the attempt, in order.
     *
     * @return array
     */
    private function events(): array {
        global $DB;
        return array_values($DB->get_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid], 'seq ASC'));
    }

    /**
     * Head and events give back what the code read before - after the cache is gone, and after a question was handed
     * out again (a reload adds it once more). RED before: the head of fixed size holds no played question, no response.
     */
    public function test_head_and_events_give_back_the_working_state(): void {
        $this->resetAfterTest();
        $this->attempt(8);
        $progress = $this->load();
        for ($i = 1; $i <= 6; $i++) {
            $this->answer($progress, $i, $i % 3 !== 0, $i === 4);
        }
        // A reload hands out the last question again (strategy::check_break()), the next one is excluded.
        $progress->add_playedquestion($progress->get_last_question())->save();
        $progress->exclude_question($this->questions[7]);
        $progress->add_debug_row(['questionattemptid' => 1, 'personabilities' => [$this->root => 0.5]]);
        $progress->save();
        $expected = $this->view($progress);

        $this->forget();
        $loaded = $this->load();
        $this->assertEquals($expected, $this->view($loaded));
        // One object per question, as before a save (populate_from_object() resolves by id).
        foreach ($loaded->get_playedquestions(true) as $questions) {
            foreach ($questions as $question) {
                $this->assertSame($loaded->get_playedquestions()[$question->id], $question);
            }
        }
        $this->assertSame($loaded->get_playedquestions()[$loaded->get_last_question()->id], $loaded->get_last_question());
        // The reload counts twice in the lists by scale, once in the played questions.
        $this->assertCount(7, $loaded->get_playedquestions(true)[$this->root]);
        $this->assertCount(6, $loaded->get_playedquestions());
    }

    /**
     * The head does not grow with the answers, and every answer appends the same few events (AK1). RED before: the
     * stored JSON grew with every answer (the format before #177 is what jsonSerialize() still gives).
     */
    public function test_the_head_has_a_fixed_size_and_an_answer_appends_two_events(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(40);
        $progress = $this->load();
        $heads = [];
        $before = [];
        $events = [];
        for ($i = 1; $i <= 40; $i++) {
            $count = $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]);
            $this->answer($progress, $i, $i % 2 === 0);
            $events[] = $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]) - $count;
            $heads[$i] = strlen((string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $this->catattemptid]));
            $before[$i] = strlen(json_encode($progress));
        }
        // Two events per answer: the question handed out, the answer.
        $this->assertSame(array_fill(0, 40, 2), $events);
        // The head: the same size after 10 answers and after 40, give or take the digits of the numbers in it.
        $this->assertLessThan(60, abs($heads[40] - $heads[10]), json_encode([$heads[10], $heads[40]]));
        // The format before grew by every question and answer.
        $this->assertGreaterThan(3 * $before[10], $before[40]);
        $this->assertLessThan($before[40] / 3, $heads[40]);
    }

    /**
     * Events of the attempt left over without their head - a cleanup that did not know them, a number given out again
     * after a restore - do not block its first save: the head is filed new, so none of them is its own. RED before: the
     * first event took the place of a left over one and every save of the attempt failed (unique index).
     */
    public function test_left_over_events_do_not_block_a_new_working_state(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(2);
        foreach ([1, 2, 3] as $seq) {
            $DB->insert_record('local_catquiz_progress_steps', (object) [
                'catattemptid' => $this->catattemptid, 'seq' => $seq, 'eventtype' => 'shown', 'itemid' => 99,
                'ispilot' => 0, 'timecreated' => time(), 'formatversion' => 1,
            ]);
        }
        $progress = $this->load();
        $this->answer($progress, 1, true);
        $this->assertSame([1, 2], array_map(fn($e) => (int) $e->seq, $this->events()));
        $this->assertNotContains(99, array_map(fn($e) => (int) $e->itemid, $this->events()));
        $this->forget();
        $this->assertSame([(string) $this->questions[1]], array_map('strval', array_keys($this->load()->get_playedquestions())));
    }

    /**
     * The settings are written with the head when they are not stored as they are - with the first save, after a
     * change - and not again with every answer: they are the largest part of the head and do not change during an
     * attempt. RED before: every save wrote them again.
     */
    public function test_the_settings_are_written_only_when_they_changed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(4);
        $stored = fn() => $DB->get_field('local_catquiz_progress', 'quizsettings', ['attemptid' => $this->catattemptid]);
        $progress = $this->load();
        $this->answer($progress, 1, true);
        $settingsread = json_decode((string) $stored());
        $this->assertEquals($this->root, $settingsread->catquiz_catscales);

        // A mark in the column: the next saves leave it as long as the settings are the same.
        $DB->set_field('local_catquiz_progress', 'quizsettings', '{"mark":1}', ['attemptid' => $this->catattemptid]);
        $this->answer($progress, 2, false);
        $this->assertSame('{"mark":1}', $stored());

        // Changed settings are written with the next save.
        $settings = new \ReflectionProperty(progress::class, 'quizsettings');
        $changed = clone $settings->getValue($progress);
        $changed->catquiz_maxquestions = 3;
        $settings->setValue($progress, $changed);
        $this->answer($progress, 3, true);
        $settingsread = json_decode((string) $stored());
        $this->assertEquals(3, $settingsread->catquiz_maxquestions);

        // A progress loaded from the database knows what is stored: unchanged, nothing is written.
        $DB->set_field('local_catquiz_progress', 'quizsettings', json_encode($changed), ['attemptid' => $this->catattemptid]);
        $this->forget();
        $loaded = $this->load();
        $before = $stored();
        $DB->set_field('local_catquiz_progress', 'quizsettings', $before . ' ', ['attemptid' => $this->catattemptid]);
        $this->answer($loaded, 4, true);
        $this->assertSame($before . ' ', $stored());
    }

    /**
     * The events are numbers and identities: no text of the question usage (D) - the given answer, the right answer,
     * the question summary are read from the usage when they are shown.
     */
    public function test_the_events_hold_no_texts_of_the_usage(): void {
        $this->resetAfterTest();
        $this->attempt(3);
        $progress = $this->load();
        for ($i = 1; $i <= 3; $i++) {
            $this->answer($progress, $i, true);
        }
        // The response in memory holds the texts the usage gave.
        $this->assertArrayHasKey('responsesummary', (array) $progress->get_responses()[$this->questions[1]]);
        foreach ($this->events() as $event) {
            foreach (['questionsummary', 'rightanswer', 'responsesummary', 'True', 'False', 'Q1', 'L1'] as $text) {
                $this->assertStringNotContainsString($text, (string) $event->diagnostics, "event {$event->seq}");
            }
        }
        $this->forget();
        $this->assertArrayNotHasKey('responsesummary', (array) $this->load()->get_responses()[$this->questions[1]]);
    }

    /**
     * The identities of the usage are columns of the answer event, distinct from each other and from the CAT attempt
     * (AK3): step, slot, question attempt, usage, item.
     */
    public function test_the_answer_event_names_the_step_of_the_usage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(2);
        $progress = $this->load();
        $this->answer($progress, 1, true);
        $this->answer($progress, 2, false);
        $answers = array_values(array_filter($this->events(), fn($e) => $e->eventtype === progress::EVENT_ANSWER));
        $this->assertCount(2, $answers);
        foreach ($answers as $n => $event) {
            $slot = $n + 1;
            $qa = $this->quba->get_question_attempt($slot);
            $this->assertSame($this->quba->get_id(), (int) $event->questionusageid);
            $this->assertSame($slot, (int) $event->slot);
            $this->assertSame((int) $qa->get_database_id(), (int) $event->questionattemptid);
            $this->assertSame($this->questions[$n + 1], (int) $event->itemid);
            $step = $DB->get_record('question_attempt_steps', ['id' => $event->questionattemptstepid], '*', MUST_EXIST);
            $this->assertSame((int) $qa->get_database_id(), (int) $step->questionattemptid);
            $this->assertEqualsWithDelta($n === 0 ? 1.0 : 0.0, (float) $event->fraction, 1e-9);
        }
    }

    /**
     * The estimates of an answer: the scales that changed, root included, with the precision they have; a standard error
     * without information is null, never INF (C, AK8). Replayed in order, they are the estimates of the head.
     */
    public function test_the_estimates_are_deltas_of_the_changed_scales(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(4);
        $progress = $this->load();
        for ($i = 1; $i <= 4; $i++) {
            $this->answer($progress, $i, true);
        }
        $replayed = [];
        foreach ($this->events() as $event) {
            if ($event->estimatesdelta === null) {
                continue;
            }
            $this->assertStringNotContainsString('INF', $event->estimatesdelta);
            $this->assertStringNotContainsString('NAN', $event->estimatesdelta);
            $delta = json_decode($event->estimatesdelta, true);
            $this->assertArrayHasKey($this->root, $delta, 'The root scale changes with every answer.');
            foreach ($delta as $scaleid => $estimate) {
                $replayed[$scaleid] = $estimate;
            }
        }
        $head = json_decode((string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $this->catattemptid]), true);
        $this->assertEquals($head['estimates'], $replayed);
        // The ability with the precision it has: 0.4 for the root after the fourth answer, not a rounded value.
        $this->assertSame(0.4, $replayed[$this->root][0]);
        $this->assertNull($replayed[$this->subscales[0]][1], 'An infinite standard error is stored as null.');
        $this->assertSame(4, $replayed[$this->root][2], 'Four productive answers in the root scale.');
        // The second answer is in subscale A; subscale B did not change with it.
        $second = array_values(array_filter($this->events(), fn($e) => $e->eventtype === progress::EVENT_ANSWER))[1];
        $this->assertArrayNotHasKey($this->subscales[1], json_decode($second->estimatesdelta, true));
    }

    /**
     * A session behind the stored state does not overwrite it (AK3, no lost update): the cache of the other session is
     * dropped when it is loaded, and a save from a state that is behind stores nothing. RED before: the older state of
     * the session cache was written over the newer one.
     */
    public function test_a_stale_working_state_is_not_written_over_a_newer_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(4);
        $progress = $this->load();
        $this->answer($progress, 1, true);
        $stale = clone $progress;

        // The other session goes on.
        $this->answer($progress, 2, true);
        $seq = (int) $DB->get_field('local_catquiz_progress', 'lastseq', ['attemptid' => $this->catattemptid]);
        $events = count($this->events());

        // The stale one tries to save what it did meanwhile.
        $stale->add_playedquestion($this->question(3));
        try {
            $stale->save();
            $this->fail('The stale state was saved.');
        } catch (progress_conflict_exception $e) {
            $this->assertStringContainsString('expected 2, stored 4', $e->debuginfo);
        }
        $this->assertSame($seq, (int) $DB->get_field('local_catquiz_progress', 'lastseq', ['attemptid' => $this->catattemptid]));
        $this->assertCount($events, $this->events());

        // A stale session cache is not handed out: the stored state is read instead.
        \cache::make('local_catquiz', 'adaptivequizattempt')->set('progress_cat_' . $this->catattemptid, $stale);
        \cache::make('local_catquiz', 'progresscurrent')->purge();
        $this->assertSame(2, $this->load()->get_num_answered_questions());
    }

    /**
     * A save whose events cannot be stored stores nothing - neither events nor head - and the next save stores what it
     * would have (crash before the commit). RED before: an event inserted on its own would have been followed by a head
     * that does not name it.
     */
    public function test_a_failed_save_stores_nothing_and_the_next_one_stores_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->attempt(3);
        $progress = $this->load();
        $this->answer($progress, 1, true);
        $head = $DB->get_record('local_catquiz_progress', ['attemptid' => $this->catattemptid]);
        // Somebody took the place of the next event (a writer outside the lock).
        $DB->insert_record('local_catquiz_progress_steps', (object) [
            'catattemptid' => $this->catattemptid, 'seq' => $head->lastseq + 1, 'eventtype' => 'scalestate',
            'ispilot' => 0, 'timecreated' => time(), 'formatversion' => 1,
        ]);
        $progress->add_playedquestion($this->question(2));
        try {
            $progress->save();
            $this->fail('The save went through.');
        } catch (\dml_write_exception $e) {
            $this->assertInstanceOf(\dml_write_exception::class, $e);
        }
        $this->assertEquals($head, $DB->get_record('local_catquiz_progress', ['attemptid' => $this->catattemptid]));
        $this->assertCount((int) $head->lastseq + 1, $this->events());
        // The place is free again: the same save stores the question now, once.
        $DB->delete_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid, 'seq' => $head->lastseq + 1]);
        $progress->save();
        $shown = array_filter($this->events(), fn($e) => $e->eventtype === progress::EVENT_SHOWN);
        $this->assertCount(2, $shown);
        $this->forget();
        $this->assertCount(2, $this->load()->get_playedquestions());
    }

    /**
     * Contract (DoD2): a question given up is stored as a gave-up event of its response; an answer changed afterwards
     * (mark_lastquestion_failed()) as a new event of the same question - the later one counts on reading, nothing is
     * counted twice. RED before: the head held the responses, and a changed one was found only in the whole JSON.
     */
    public function test_a_given_up_and_a_changed_answer_are_events_of_their_own(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(3);
        $progress = $this->load();
        $this->answer($progress, 1, true);

        // Question 2 is handed out and given up (progress::load(): no response in the usage, the time is over).
        $progress->add_playedquestion($this->question(2))->save();
        $gaveup = new \ReflectionProperty(progress::class, 'gaveupquestions');
        $gaveup->setValue($progress, [...$gaveup->getValue($progress), (string) $this->questions[2]]);
        $progress->mark_lastquestion_failed();
        $progress->save();

        // Question 1 is marked failed afterwards (a changed answer of the same question).
        $progress->add_playedquestion($this->question(1));
        $progress->mark_lastquestion_failed();
        $progress->save();

        $types = array_map(fn($e) => [$e->eventtype, (int) $e->itemid], array_values(array_filter(
            $this->events(),
            fn($e) => $e->eventtype !== progress::EVENT_SHOWN && $e->eventtype !== progress::EVENT_SCALESTATE
        )));
        $this->assertSame([
            [progress::EVENT_ANSWER, $this->questions[1]],
            [progress::EVENT_GAVEUP, $this->questions[2]],
            [progress::EVENT_ANSWER, $this->questions[1]],
        ], $types);

        $this->forget();
        $loaded = $this->load();
        $responses = $loaded->get_responses();
        $this->assertCount(2, $responses);
        $this->assertEquals(0.0, (float) $responses[$this->questions[1]]['fraction']);
        $this->assertEquals(0.0, (float) $responses[$this->questions[2]]['fraction']);
        $this->assertSame([(string) $this->questions[2]], array_map('strval', $gaveup->getValue($loaded)));
        $this->assertSame(2, $loaded->get_num_answered_questions());
        $this->assertSame(0, (int) $DB->count_records_select(
            'local_catquiz_progress_steps',
            'catattemptid = ? AND eventtype = ? AND itemid = ?',
            [$this->catattemptid, progress::EVENT_GAVEUP, $this->questions[1]]
        ));
    }

    /**
     * A running attempt of the format before is converted by its next save - all events, then the head - and reads
     * the same afterwards (AK5). An ended attempt is not touched; nothing is converted by reading.
     */
    public function test_a_progress_of_the_format_before_is_converted_by_its_next_save(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(5);
        $progress = $this->load();
        for ($i = 1; $i <= 4; $i++) {
            $this->answer($progress, $i, $i !== 2);
        }
        $progress->add_playedquestion($progress->get_last_question())->save();
        $expected = $this->view($progress);
        // As the code before #177 stored it.
        $DB->set_field('local_catquiz_progress', 'json', json_encode($progress), ['attemptid' => $this->catattemptid]);
        $DB->set_field('local_catquiz_progress', 'lastseq', 0, ['attemptid' => $this->catattemptid]);
        $DB->delete_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]);
        $this->forget();

        // Reading converts nothing.
        $this->assertNotNull(progress::load_for_reading($this->catattemptid));
        $this->assertSame(0, $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]));

        $loaded = $this->load();
        $this->assertEquals($expected, $this->view($loaded));
        $loaded->save();
        $head = json_decode((string) $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $this->catattemptid]));
        $this->assertSame(progress::FORMAT_STEPS, $head->progressformat);
        $this->assertSame(5, count(array_filter($this->events(), fn($e) => $e->eventtype === progress::EVENT_SHOWN)));
        $this->forget();
        $this->assertEquals($expected, $this->view($this->load()));
        $this->assertSame(['heads' => [progress::FORMAT_STEPS => 1], 'findings' => array_fill_keys(
            [...progress_steps_maintenance::ERRORS, progress_steps_maintenance::PARAMS_GONE],
            []
        )], progress_steps_maintenance::check([$this->catattemptid]));
    }

    /**
     * The way back writes the format before - every played question with the parameters it was shown with, every
     * response with the texts of the usage - and deletes the events, both or neither (decision 5). The check finds a
     * gap in the events.
     */
    public function test_the_way_back_and_the_check(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(4);
        $progress = $this->load();
        for ($i = 1; $i <= 3; $i++) {
            $this->answer($progress, $i, true);
        }
        $expected = $this->view($progress);

        $dry = progress_steps_maintenance::rollback([$this->catattemptid]);
        $this->assertSame(6, $dry['attempts'][$this->catattemptid]['events']);
        $this->assertSame(6, $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]));

        // A gap in the events is found.
        $third = $this->events()[2];
        $DB->delete_records('local_catquiz_progress_steps', ['id' => $third->id]);
        $findings = progress_steps_maintenance::check([$this->catattemptid])['findings'];
        $this->assertSame([$this->catattemptid], $findings[progress_steps_maintenance::SEQ_GAP]);
        $this->assertSame([$this->catattemptid], $findings[progress_steps_maintenance::COUNT_MISMATCH]);
        $DB->insert_record('local_catquiz_progress_steps', $third);

        $result = progress_steps_maintenance::rollback([$this->catattemptid], true);
        $this->assertSame([], $result['failed']);
        $this->assertSame(0, $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]));
        $row = $DB->get_record('local_catquiz_progress', ['attemptid' => $this->catattemptid]);
        $this->assertSame(0, (int) $row->lastseq);
        $old = json_decode((string) $row->json, true);
        $this->assertSame(progress::FORMAT_COMPACT, $old['progressformat']);
        $this->assertArrayHasKey('responsesummary', $old['responses'][$this->questions[1]], 'The texts from the usage.');
        $this->assertCount(3, $old['playedquestions']);
        $this->assertEquals($expected, $this->view(progress::load_for_reading($this->catattemptid)));
    }

    /**
     * Deleting the progress deletes its events (privacy, retention: progress::delete()).
     */
    public function test_deleting_the_progress_deletes_its_events(): void {
        global $DB;
        $this->resetAfterTest();
        $this->attempt(2);
        $progress = $this->load();
        $this->answer($progress, 1, true);
        $this->assertNotEmpty($this->events());
        progress::delete($this->adaptiveattemptid, 'mod_adaptivequiz');
        $this->assertSame(0, $DB->count_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]));
        $this->assertFalse($DB->record_exists('local_catquiz_progress', ['attemptid' => $this->catattemptid]));
    }
}
