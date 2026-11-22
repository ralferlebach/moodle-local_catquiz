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
 * Decides how long the progress of an attempt is kept (issue #56).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\local;

/**
 * Retention policy for local_catquiz_progress.
 *
 * The table holds what is needed to continue a running attempt. Until now the row
 * survived the attempt and was only removed with the activity, so a JSON blob of
 * personal answer data stayed behind without anyone having decided that. At the same
 * time it was too thin for an evaluation: abilities kept only the last estimate per
 * scale, never a trajectory.
 *
 * The three levels make that a deliberate choice. MINIMAL is the default because a
 * plugin should not accumulate personal data by accident.
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_retention {
    /** @var string Delete the progress row once the attempt is finished. */
    const MINIMAL = 'minimal';

    /** @var string Keep the final state - the behaviour before issue #56. */
    const KEEP = 'keep';

    /** @var string Record the step by step trajectory. */
    const TRACE = 'trace';

    /**
     * Returns the levels in ascending order of how much they retain.
     *
     * The order is what makes the site setting an upper bound: an activity may
     * choose a level at most as high as the site allows.
     *
     * @return string[]
     */
    public static function levels(): array {
        return [self::MINIMAL, self::KEEP, self::TRACE];
    }

    /**
     * Returns the level configured for the site.
     *
     * @return string
     */
    public static function site_level(): string {
        $value = get_config('local_catquiz', 'progressretention');

        return self::normalise($value);
    }

    /**
     * Returns the number of days after which recorded traces are removed.
     *
     * @return int Zero means unlimited.
     */
    public static function retention_days(): int {
        return max(0, (int) get_config('local_catquiz', 'progressretentiondays'));
    }

    /**
     * Returns the effective level for an activity, capped by the site setting.
     *
     * A site configured for data minimisation must not be overridden by a single
     * activity: the activity may retain less than the site allows, never more.
     *
     * @param string|null $activitylevel Null or empty means "use the site default".
     * @return string
     */
    public static function effective_level(?string $activitylevel): string {
        $site = self::site_level();

        if ($activitylevel === null || $activitylevel === '') {
            return $site;
        }

        $order = array_flip(self::levels());
        $wanted = self::normalise($activitylevel);

        return $order[$wanted] > $order[$site] ? $site : $wanted;
    }

    /**
     * Whether the progress row is removed once the attempt is finished.
     *
     * @param string|null $activitylevel
     * @return bool
     */
    public static function should_delete(?string $activitylevel = null): bool {
        return self::effective_level($activitylevel) === self::MINIMAL;
    }

    /**
     * Whether the step by step trajectory is recorded.
     *
     * @param string|null $activitylevel
     * @return bool
     */
    public static function should_trace(?string $activitylevel = null): bool {
        return self::effective_level($activitylevel) === self::TRACE;
    }

    /**
     * Maps an unknown or unset value onto a valid level.
     *
     * Defaults to MINIMAL: an unreadable setting must not silently turn into the
     * most retentive option.
     *
     * @param mixed $value
     * @return string
     */
    private static function normalise($value): string {
        $value = is_string($value) ? $value : '';

        return in_array($value, self::levels(), true) ? $value : self::MINIMAL;
    }
    /**
     * Before the progress of a finished attempt is removed: what the page of its result needs and its stored data do
     * not hold is kept from the progress (issue #159, decision of 21.11.2026).
     *
     * After the removal the result is shown from the stored data alone (progress::load_historical()). Data stored
     * before the history and the abilities were kept with the result lack them - the page read them from the
     * progress, and lost its result tabs once it was gone. Copied, not computed: the answered questions in their
     * order with answer, scale, difficulty and Fisher information (retention_history), and the abilities
     * (retention_abilities), as the progress holds them. Data that hold both are left as they are; nothing is
     * written for them.
     *
     * @param int $catattemptid Id in local_catquiz_attempts.
     * @return string[] What was kept: 'history', 'abilities'.
     */
    public static function preserve_for_display(int $catattemptid): array {
        global $DB;
        $attempt = $DB->get_record('local_catquiz_attempts', ['id' => $catattemptid], 'id, json, scaleid');
        $progressjson = $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $catattemptid]);
        if (!$attempt || !$progressjson) {
            return [];
        }
        $stored = json_decode((string) $attempt->json, true);
        $progress = json_decode((string) $progressjson, true);
        if (!is_array($stored) || !is_array($progress)) {
            return [];
        }
        $kept = [];
        if (empty($stored['graphicalsummary_data']) && empty($stored['retention_history'])) {
            $root = (string) ($stored['catscaleid'] ?? $attempt->scaleid);
            $played = (array) ($progress['playedquestions'] ?? []);
            $rows = [];
            // The responses in the order they were given - the order of the history.
            foreach ((array) ($progress['responses'] ?? []) as $qid => $response) {
                $question = $played[$qid] ?? null;
                if (!is_array($question) || !is_array($response) || !isset($response['fraction'])) {
                    continue;
                }
                $pilot = !empty($question['is_pilot']);
                $information = $question['fisherinformation'] ?? null;
                $rows[] = [
                    'id' => (string) $qid,
                    'questionname' => (string) ($question['label'] ?? ''),
                    'questionscale' => (string) ($question['catscaleid'] ?? ''),
                    'difficulty' => $question['difficulty'] ?? null,
                    'pilot' => $pilot,
                    'fisherinformation' => $pilot || !is_array($information) || !isset($information[$root])
                        ? null : round((float) $information[$root], 2),
                    'lastresponse' => round((float) $response['fraction'], 2),
                ];
            }
            if ($rows) {
                $stored['retention_history'] = $rows;
                $kept[] = 'history';
            }
        }
        if (empty($stored['personabilities']) && empty($stored['retention_abilities'])) {
            $abilities = array_filter((array) ($progress['abilities'] ?? []), 'is_numeric');
            if ($abilities) {
                $stored['retention_abilities'] = $abilities;
                $kept[] = 'abilities';
            }
        }
        if ($kept) {
            $DB->set_field('local_catquiz_attempts', 'json', json_encode($stored), ['id' => $catattemptid]);
        }
        return $kept;
    }
}
