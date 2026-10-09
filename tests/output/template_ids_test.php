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

/**
 * Several feedbacks and statistics on one page do not share ids (issue #30).
 *
 * Tabs, collapses and modals find their target by id. Two instances with the same ids - the
 * attempts of a feedback list, the same shortcode twice, a shortcode next to the result page -
 * make a click in one open the panel of the other, or none at all.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class template_ids_test extends advanced_testcase {
    /**
     * All ids of a piece of HTML.
     *
     * @param string $html
     * @return string[]
     */
    private static function ids(string $html): array {
        preg_match_all('/\sid\s*=\s*"([^"]+)"/', $html, $matches);
        return $matches[1];
    }

    /**
     * The ids a piece of HTML points to through tabs, collapses and aria-controls.
     *
     * @param string $html
     * @return string[]
     */
    private static function targets(string $html): array {
        preg_match_all('/(?:href|data-bs-target|data-target)="#([^"]+)"|aria-controls="([^"]+)"/', $html, $matches);
        return array_values(array_filter(array_merge($matches[1], $matches[2])));
    }

    /**
     * Asserts unique ids, and that every target exists within the same piece.
     *
     * @param string[] $pieces Rendered instances, as they would sit on one page.
     */
    private function assert_ids_are_page_safe(array $pieces): void {
        $all = [];
        foreach ($pieces as $html) {
            $ids = self::ids($html);
            foreach (self::targets($html) as $target) {
                $this->assertContains($target, $ids, "Target #$target is not part of its own instance.");
            }
            $all = array_merge($all, $ids);
        }
        $duplicates = array_keys(array_filter(array_count_values($all), fn($n) => $n > 1));
        $this->assertSame([], $duplicates, 'Ids used twice on one page.');
        $this->assertNotEmpty($all);
    }

    /**
     * Feedback of one attempt with a generator that has both a student and a teacher tab.
     *
     * @param int $attemptid
     * @return array
     */
    private static function feedback(int $attemptid): array {
        return [
            'attemptid' => $attemptid,
            'feedback' => [
                'studentfeedback' => [
                    ['generatorname' => 'customscalefeedback', 'heading' => 'Result', 'content' => 'x', 'frontpage' => '1'],
                    ['generatorname' => 'graphicalsummary', 'heading' => 'History', 'content' => 'x', 'othertabs' => '1'],
                ],
                'teacherfeedback' => [
                    ['generatorname' => 'graphicalsummary', 'heading' => 'History', 'content' => 'x'],
                    ['generatorname' => 'debuginfo', 'heading' => 'Export', 'content' => 'x'],
                ],
            ],
        ];
    }

    /**
     * Two attempts, and the same attempt twice, on one page.
     */
    public function test_attempt_feedback(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $this->assert_ids_are_page_safe([
            $OUTPUT->render_from_template('local_catquiz/attemptfeedback', self::feedback(5)),
            $OUTPUT->render_from_template('local_catquiz/attemptfeedback', self::feedback(6)),
            $OUTPUT->render_from_template('local_catquiz/attemptfeedback', self::feedback(5)),
        ]);
    }

    /**
     * A list of attempts, twice on one page.
     */
    public function test_feedback_list(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $render = fn() => implode('', array_map(
            fn($id) => $OUTPUT->render_from_template(
                'local_catquiz/feedback/feedbacklist',
                ['attemptid' => $id, 'header' => 'Attempt ' . $id, 'active' => $id === 7] + self::feedback($id)
            ),
            [7, 8]
        ));

        $this->assert_ids_are_page_safe([$render(), $render()]);
    }

    /**
     * The same statistics shortcode twice on one page.
     */
    public function test_statistics_shortcode(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $context = ['heading' => ['title' => 'Statistics', 'description' => ''], 'shortcodeid' => 'a b'];
        $this->assert_ids_are_page_safe([
            $OUTPUT->render_from_template('local_catquiz/catscaleshortcodes/catscalestatistics', $context),
            $OUTPUT->render_from_template('local_catquiz/catscaleshortcodes/catscalestatistics', $context),
        ]);
    }
}
