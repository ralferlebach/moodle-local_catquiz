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

namespace local_catquiz\output;

use advanced_testcase;
use local_catquiz\teststrategy\feedback_helper;
use local_catquiz\teststrategy\feedbackgenerator;
use local_catquiz\teststrategy\feedbackgenerator\comparetotestaverage;
use local_catquiz\teststrategy\feedbackgenerator\customscalefeedback;
use local_catquiz\teststrategy\feedbackgenerator\debuginfo;
use local_catquiz\teststrategy\feedbackgenerator\graphicalsummary;
use local_catquiz\teststrategy\feedbackgenerator\learningprogress;
use local_catquiz\teststrategy\feedbackgenerator\personabilities;
use local_catquiz\teststrategy\feedbackgenerator\pilotquestions;
use local_catquiz\teststrategy\feedbackgenerator\questionssummary;
use local_catquiz\teststrategy\feedbacksettings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/catquiz/lib.php');

/**
 * What an attempt without a valid result still shows (issue #120).
 *
 * Validity used to switch off every feedback generator but the primary one - and with them the
 * quiz history, the question summary and the export. What reads the result stays out; what the
 * attempt contained and the export for authorised users stay.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_catquiz\output\attemptfeedback
 * @covers \local_catquiz\teststrategy\feedbackgenerator
 */
final class invalid_result_feedback_test extends advanced_testcase {
    /**
     * Each real generator declares what it depends on.
     */
    public function test_generators_declare_their_dependency(): void {
        $expected = [
            customscalefeedback::class => feedbackgenerator::DEPENDS_ON_RESULT,
            comparetotestaverage::class => feedbackgenerator::DEPENDS_ON_RESULT,
            personabilities::class => feedbackgenerator::DEPENDS_ON_RESULT,
            learningprogress::class => feedbackgenerator::DEPENDS_ON_RESULT,
            pilotquestions::class => feedbackgenerator::DEPENDS_ON_RESULT,
            questionssummary::class => feedbackgenerator::DEPENDS_ON_ATTEMPT,
            graphicalsummary::class => feedbackgenerator::DEPENDS_ON_ATTEMPT,
            debuginfo::class => feedbackgenerator::DIAGNOSTIC,
        ];
        foreach ($expected as $class => $dependency) {
            $generator = new $class(new feedbacksettings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB), new feedback_helper());
            $this->assertSame($dependency, $generator->get_result_dependency(), $class);
        }
    }

    /**
     * A generator that returns fixed feedback, for driving generate_feedback().
     *
     * @param string $name
     * @param string $dependency
     * @return feedbackgenerator
     */
    private function generator(string $name, string $dependency): feedbackgenerator {
        return new class ($name, $dependency) extends feedbackgenerator {
            /** @var string */
            private string $name;
            /** @var string */
            private string $dependency;

            /**
             * Constructor.
             *
             * @param string $name
             * @param string $dependency
             */
            public function __construct(string $name, string $dependency) {
                parent::__construct(new feedbacksettings(LOCAL_CATQUIZ_STRATEGY_LOWESTSUB), new feedback_helper());
                $this->name = $name;
                $this->dependency = $dependency;
            }

            /**
             * Fixed feedback for both audiences.
             *
             * @param array $feedbackdata
             * @return array
             */
            public function get_feedback(array $feedbackdata): array {
                return [
                    'studentfeedback' => ['heading' => $this->name, 'content' => 'student ' . $this->name],
                    'teacherfeedback' => ['heading' => $this->name, 'content' => 'teacher ' . $this->name],
                ];
            }

            /**
             * Student feedback.
             *
             * @param array $data
             * @return array
             */
            protected function get_studentfeedback(array $data): array {
                return [];
            }

            /**
             * Teacher feedback.
             *
             * @param array $data
             * @return array
             */
            protected function get_teacherfeedback(array $data): array {
                return [];
            }

            /**
             * Required keys.
             *
             * @return array
             */
            public function get_required_context_keys(): array {
                return [];
            }

            /**
             * Heading.
             *
             * @return string
             */
            public function get_heading(): string {
                return $this->name;
            }

            /**
             * Name.
             *
             * @return string
             */
            public function get_generatorname(): string {
                return $this->name;
            }

            /**
             * Dependency.
             *
             * @return string
             */
            public function get_result_dependency(): string {
                return $this->dependency;
            }

            /**
             * Load data.
             *
             * @param int $adaptiveattemptid
             * @param array $existingdata
             * @param array $newdata
             * @return array|null
             */
            public function load_data(int $adaptiveattemptid, array $existingdata, array $newdata): ?array {
                return null;
            }
        };
    }

    /**
     * Runs generate_feedback() with the usual set of generator kinds.
     *
     * @param bool $valid Whether the one scale counts as measured in this attempt.
     * @return array The feedback context.
     */
    private function feedback(bool $valid): array {
        $generators = [
            $this->generator('customscalefeedback', feedbackgenerator::DEPENDS_ON_RESULT),
            $this->generator('comparetotestaverage', feedbackgenerator::DEPENDS_ON_RESULT),
            $this->generator('learningprogress', feedbackgenerator::DEPENDS_ON_RESULT),
            $this->generator('questionssummary', feedbackgenerator::DEPENDS_ON_ATTEMPT),
            $this->generator('graphicalsummary', feedbackgenerator::DEPENDS_ON_ATTEMPT),
            $this->generator('debuginfo', feedbackgenerator::DIAGNOSTIC),
        ];
        $feedbackdata = [
            'attemptid' => 0,
            'customscalefeedback_abilities' => [5 => ['value' => 0.3, 'toreport' => true, 'primary' => true]],
            // Measured or not: without a productive answer the scale has no result.
            'nbyscale' => [5 => $valid ? 4 : 0],
        ];

        $reflection = new \ReflectionClass(attemptfeedback::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('generate_feedback');
        $method->setAccessible(true);
        return $method->invoke($instance, $generators, $feedbackdata);
    }

    /**
     * Invalid: the notice comes first, quiz history and summary stay, what reads the result goes.
     */
    public function test_invalid_result_keeps_the_attempt_and_the_export(): void {
        $this->resetAfterTest();

        $context = $this->feedback(false);
        $student = array_column($context['studentfeedback'], 'generatorname');
        $teacher = array_column($context['teacherfeedback'], 'generatorname');

        $this->assertSame('novalidresult', $student[0], 'The notice comes first.');
        $this->assertContains('graphicalsummary', $student);
        $this->assertContains('questionssummary', $student);
        $this->assertNotContains('comparetotestaverage', $student);
        $this->assertNotContains('learningprogress', $student);
        $this->assertNotContains('customscalefeedback', $student, 'No scale feedback for an invalid result.');

        $this->assertContains('debuginfo', $teacher, 'The export stays for authorised users.');
        $this->assertContains('customscalefeedback', $teacher, 'Teachers still see the reason.');
        $this->assertNotContains('comparetotestaverage', $teacher);
    }

    /**
     * Valid: everything is shown, no notice.
     */
    public function test_valid_result_is_unchanged(): void {
        $this->resetAfterTest();

        $student = array_column($this->feedback(true)['studentfeedback'], 'generatorname');

        $this->assertSame(
            [
                'customscalefeedback', 'comparetotestaverage', 'learningprogress',
                'questionssummary', 'graphicalsummary', 'debuginfo',
            ],
            $student
        );
    }
}
