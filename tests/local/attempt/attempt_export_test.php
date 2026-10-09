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
use local_catquiz\local\result\attempt_result_validator;
use local_catquiz\local\result\personparam_repository;
use local_catquiz\local\result\scale_result;

/**
 * The export of one attempt, valid result or not (issue #120).
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\local\attempt\attempt_export
 */
final class attempt_export_test extends advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The participant who made the attempt. */
    private \stdClass $student;

    /**
     * An attempt of a participant in a real activity.
     *
     * @param string|null $resultstatus What the finaliser wrote; null for an attempt not finalised.
     * @return int adaptivequiz_attempt.id
     */
    private function attempt(?string $resultstatus): int {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $this->course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $now = time();
        $adaptiveattemptid = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $this->student->id, 'uniqueid' => 0,
            'attemptstate' => 'complete', 'attemptstopcriteria' => 'All questions answered', 'questionsattempted' => 3,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
            'resultstatus' => $resultstatus, 'resultvalid' => 0,
        ]);
        $DB->insert_record('local_catquiz_attempts', (object) [
            'userid' => $this->student->id, 'attemptid' => $adaptiveattemptid, 'component' => 'mod_adaptivequiz',
            'courseid' => $this->course->id, 'instanceid' => $adaptivequiz->id,
            'contextid' => 9, 'status' => 0, 'endtime' => $now, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return $adaptiveattemptid;
    }

    /**
     * An invalid result is exported as invalid, with its stored values and reason - nothing invented.
     */
    public function test_invalid_result_is_exported_as_such(): void {
        global $DB;
        $this->resetAfterTest();

        $adaptiveattemptid = $this->attempt('invalid');
        $catattemptid = (int) $DB->get_field('local_catquiz_attempts', 'id', ['attemptid' => $adaptiveattemptid]);
        // Every productive item right: the scale keeps its values but is invalid (issue #140).
        $result = attempt_result_validator::from_personabilities(
            [7 => ['value' => 3.1, 'toreport' => true]],
            [7 => 0.45],
            [7 => 3],
            [7 => 1.0]
        );
        personparam_repository::save_attempt_result($catattemptid, (int) $this->student->id, 9, $result);

        $rows = attempt_export::rows($adaptiveattemptid);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(attempt_export::COLUMNS, array_keys($row));
        $this->assertSame('invalid', $row['resultstatus']);
        $this->assertSame(0, $row['resultvalid']);
        $this->assertSame(7, $row['scaleid']);
        $this->assertEqualsWithDelta(3.1, $row['ability'], 1e-9);
        $this->assertEqualsWithDelta(0.45, $row['standarderror'], 1e-9);
        $this->assertSame(3, $row['n']);
        $this->assertSame(0, $row['isvalid']);
        $this->assertStringContainsString(scale_result::REASON_FRACTION_ALL_CORRECT, $row['validationstatus']);
    }

    /**
     * An attempt not finalised: no status, no scale values - empty cells, not zeros.
     */
    public function test_attempt_not_finalised_has_empty_cells(): void {
        $this->resetAfterTest();

        $rows = attempt_export::rows($this->attempt(null));

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['resultstatus']);
        $this->assertSame('', $rows[0]['resultvalid'], 'Not validated is not "invalid".');
        $this->assertSame('', $rows[0]['ability']);
        $this->assertSame('', $rows[0]['standarderror']);
        $this->assertSame('All questions answered', $rows[0]['stopreason']);
    }

    /**
     * Teachers of the course may export; the participant and other participants may not.
     */
    public function test_only_teachers_may_export(): void {
        $this->resetAfterTest();

        $adaptiveattemptid = $this->attempt('valid');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        \local_catquiz\local\access\context_resolver::reset_cache();
        $this->setUser($teacher);
        $this->assertTrue(attempt_export::can_export($adaptiveattemptid));
        $this->setUser($this->student);
        $this->assertFalse(attempt_export::can_export($adaptiveattemptid));
        $this->setUser($other);
        $this->assertFalse(attempt_export::can_export($adaptiveattemptid));
    }
}
