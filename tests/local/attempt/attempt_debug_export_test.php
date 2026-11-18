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

namespace local_catquiz\local\attempt;

use advanced_testcase;
use moodle_exception;

/**
 * The debug export shows the attempt it is asked for, and only that (issue #173).
 *
 * The ids are set on purpose so that the two id spaces cross: the activity attempt of A has the number
 * of B's CAT attempt and the other way round. Before the fix the export read the progress by the number
 * of the activity attempt and showed B's progress in A's export.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\attempt\attempt_debug_export
 */
final class attempt_debug_export_test extends advanced_testcase {
    /** @var int Activity attempt of A = CAT attempt of B. */
    private const A_HOST = 7001;

    /** @var int Activity attempt of B = CAT attempt of A. */
    private const B_HOST = 8001;

    /** @var int */
    private int $usera;

    /** @var int */
    private int $userb;

    /**
     * Files an activity attempt with a given id.
     *
     * @param int $id
     * @param int $userid
     */
    private function host_attempt(int $id, int $userid): void {
        global $DB;
        $DB->import_record('adaptivequiz_attempt', (object) [
            'id' => $id, 'instance' => 1, 'userid' => $userid, 'uniqueid' => 0, 'attemptstate' => 'complete',
            'attemptstopcriteria' => 'done', 'questionsattempted' => 3, 'difficultysum' => 0, 'standarderror' => 0.4,
            'measure' => 0.2, 'timecreated' => 1000, 'timemodified' => 2000,
        ]);
    }

    /**
     * Files a CAT attempt with a given id.
     *
     * @param int $id
     * @param int $hostid
     * @param int $userid
     * @param array $extra
     */
    private function cat_attempt(int $id, int $hostid, int $userid, array $extra = []): void {
        global $DB;
        $DB->import_record('local_catquiz_attempts', (object) ($extra + [
            'id' => $id, 'userid' => $userid, 'scaleid' => 5, 'contextid' => 9, 'courseid' => 1,
            'instanceid' => 1, 'attemptid' => $hostid, 'component' => 'mod_adaptivequiz', 'status' => 2,
            'teststrategy' => 1, 'json' => '{}', 'debug_info' => '[]', 'timecreated' => 1000, 'timemodified' => 2000,
        ]));
    }

    /**
     * Files a progress row.
     *
     * @param int $catattemptid
     * @param int $userid
     * @param string $marker
     * @param string $component
     */
    private function progress(int $catattemptid, int $userid, string $marker, string $component = 'mod_adaptivequiz'): void {
        global $DB;
        $DB->insert_record('local_catquiz_progress', (object) [
            'userid' => $userid, 'component' => $component, 'attemptid' => $catattemptid,
            'json' => json_encode(['marker' => $marker]), 'quizsettings' => '{}', 'timemodified' => 3000,
        ]);
    }

    /**
     * Two people whose attempt numbers cross.
     */
    private function crossed(): void {
        $this->resetAfterTest();
        $this->usera = (int) $this->getDataGenerator()->create_user()->id;
        $this->userb = (int) $this->getDataGenerator()->create_user()->id;
        $this->host_attempt(self::A_HOST, $this->usera);
        $this->host_attempt(self::B_HOST, $this->userb);
        $this->cat_attempt(self::B_HOST, self::A_HOST, $this->usera);
        $this->cat_attempt(self::A_HOST, self::B_HOST, $this->userb);
    }

    /**
     * Everything the export would print, as one string.
     *
     * @param \stdClass $data
     * @return string
     */
    private static function printed(\stdClass $data): string {
        return json_encode($data) . implode('', array_keys(attempt_debug_export::pages($data)))
            . implode('', attempt_debug_export::pages($data));
    }

    /**
     * A's export shows A's progress; B's does not appear, neither in the data nor in the pages.
     */
    public function test_crossed_ids_show_only_the_own_progress(): void {
        $this->crossed();
        $this->progress(self::B_HOST, $this->usera, 'progress-of-A');
        $this->progress(self::A_HOST, $this->userb, 'progress-of-B');

        foreach ([[self::A_HOST, 'progress-of-A', 'progress-of-B'], [self::B_HOST, 'progress-of-B', 'progress-of-A']] as $case) {
            [$host, $own, $foreign] = $case;
            $data = attempt_debug_export::collect($host);
            $this->assertSame(attempt_debug_export::PROGRESS_PRESENT, $data->progressstate);
            $this->assertSame($own, $data->progress['marker']);
            $printed = self::printed($data);
            $this->assertStringContainsString($own, $printed);
            $this->assertStringNotContainsString($foreign, $printed);
        }
    }

    /**
     * Issue #173: the document itself - the PDF the page sends - holds the own attempt and nothing of the crossed one.
     */
    public function test_the_pdf_holds_only_the_own_attempt(): void {
        $this->crossed();
        $this->progress(self::B_HOST, $this->usera, 'progress-of-A');
        $this->progress(self::A_HOST, $this->userb, 'progress-of-B');

        foreach ([[self::A_HOST, 'progress-of-A', 'progress-of-B'], [self::B_HOST, 'progress-of-B', 'progress-of-A']] as $case) {
            [$host, $own, $foreign] = $case;
            $document = attempt_debug_export::pdf(attempt_debug_export::collect($host), false, 'helvetica');
            $this->assertStringStartsWith('%PDF-', $document);
            $this->assertStringContainsString($own, $document, 'The own progress is in the document.');
            $this->assertStringNotContainsString($foreign, $document, 'Nothing of the crossed attempt.');
        }
        // The download is compressed - and still a PDF.
        $this->assertStringStartsWith('%PDF-', attempt_debug_export::pdf(attempt_debug_export::collect(self::A_HOST)));
    }

    /**
     * No progress: said plainly, without a PHP notice.
     */
    public function test_missing_progress_is_reported_as_absent(): void {
        $this->crossed();
        // B's progress exists: under the old lookup A's export showed it, under a lookup of nothing it showed NULL.
        $this->progress(self::A_HOST, $this->userb, 'progress-of-B');

        $data = attempt_debug_export::collect(self::A_HOST);

        $this->assertSame(attempt_debug_export::PROGRESS_ABSENT, $data->progressstate);
        $this->assertNull($data->progress);
        $printed = self::printed($data);
        $this->assertStringContainsString(get_string('debugexport:progressabsent', 'local_catquiz'), $printed);
        $this->assertStringNotContainsString('progress-of-B', $printed);
    }

    /**
     * A row with the number of the CAT attempt but of another person or component is counted, not shown.
     *
     * @return array
     */
    public static function foreign_rows(): array {
        return [
            'other person (from before #101)' => ['userb', 'mod_adaptivequiz'],
            'other component' => ['usera', 'mod_quiz'],
        ];
    }

    /**
     * Foreign row under the number of the CAT attempt.
     *
     * @param string $owner
     * @param string $component
     * @dataProvider foreign_rows
     */
    public function test_a_foreign_row_under_the_same_number_is_not_shown(string $owner, string $component): void {
        $this->crossed();
        $this->progress(self::B_HOST, $this->$owner, 'foreign-progress', $component);

        $data = attempt_debug_export::collect(self::A_HOST);

        $this->assertSame(attempt_debug_export::PROGRESS_ABSENT, $data->progressstate);
        $this->assertSame(1, $data->foreignprogressrows);
        $printed = self::printed($data);
        $this->assertStringNotContainsString('foreign-progress', $printed);
        $this->assertStringContainsString(get_string('debugexport:foreignprogress', 'local_catquiz', 1), $printed);
    }

    /**
     * No CAT attempt for the activity attempt: nothing is shown.
     */
    public function test_missing_cat_attempt_fails_closed(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->host_attempt(self::A_HOST, $user);
        // A CAT attempt whose id is the number of the activity attempt, belonging to something else.
        $this->cat_attempt(self::A_HOST, 9999, $user);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('debugexport:notfound', 'local_catquiz'));
        attempt_debug_export::collect(self::A_HOST);
    }

    /**
     * The CAT attempt names another person or activity than the activity attempt: fail closed.
     *
     * @return array
     */
    public static function mismatches(): array {
        return [
            'other person' => ['userid'],
            'other activity' => ['instanceid'],
        ];
    }

    /**
     * Fail closed on a CAT attempt that does not belong to the activity attempt.
     *
     * @param string $field
     * @dataProvider mismatches
     */
    public function test_ownership_mismatch_fails_closed(string $field): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $this->host_attempt(self::A_HOST, $user);
        $this->cat_attempt(
            self::B_HOST,
            self::A_HOST,
            $field === 'userid' ? $other : $user,
            $field === 'instanceid' ? ['instanceid' => 2] : []
        );
        $this->progress(self::B_HOST, $field === 'userid' ? $other : $user, 'must-not-show');

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('debugexport:inconsistent', 'local_catquiz'));
        attempt_debug_export::collect(self::A_HOST);
    }

    /**
     * A finished attempt from the past: the database fields are shown as they are, the stored JSON apart
     * from them, even when the JSON is older.
     */
    public function test_a_historical_attempt_shows_db_fields_apart_from_the_snapshot(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->host_attempt(self::A_HOST, $user);
        $this->cat_attempt(self::B_HOST, self::A_HOST, $user, [
            'personability_after_attempt' => 1.25, 'endtime' => 2500,
            'json' => json_encode(['personability_after_attempt' => -3.0, 'status' => 'running']),
        ]);

        $data = attempt_debug_export::collect(self::A_HOST);

        $this->assertEqualsWithDelta(1.25, (float) $data->lifecycle['personability_after_attempt'], 1e-9);
        $this->assertSame(2500, (int) $data->lifecycle['endtime']);
        $this->assertSame(-3.0, (float) $data->snapshot['personability_after_attempt']);
        $this->assertSame(self::B_HOST, $data->catattemptid);
        $this->assertSame(self::A_HOST, $data->hostattemptid);
        $this->assertArrayHasKey(get_string(
            'debugexport:snapshot',
            'local_catquiz',
            (object) ['host' => self::A_HOST, 'cat' => self::B_HOST]
        ), attempt_debug_export::pages($data));
    }

    /**
     * Stored values are text: markup in the debug data is escaped.
     */
    public function test_stored_markup_is_escaped(): void {
        $this->resetAfterTest();
        $user = (int) $this->getDataGenerator()->create_user()->id;
        $this->host_attempt(self::A_HOST, $user);
        $this->cat_attempt(self::B_HOST, self::A_HOST, $user, [
            'debug_info' => json_encode([['note' => '<img src=x onerror=alert(1)>']]),
        ]);

        $html = implode('', attempt_debug_export::pages(attempt_debug_export::collect(self::A_HOST)));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    /**
     * The export writes nothing.
     */
    public function test_the_export_is_read_only(): void {
        global $DB;
        $this->crossed();
        $this->progress(self::B_HOST, $this->usera, 'progress-of-A');
        $before = $DB->perf_get_writes();

        attempt_debug_export::pages(attempt_debug_export::collect(self::A_HOST));

        $this->assertSame($before, $DB->perf_get_writes());
    }
}
