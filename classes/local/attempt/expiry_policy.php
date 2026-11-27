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

use local_catquiz\catquiz_handler;
use stdClass;

/**
 * The effective expiry policy of the attempts of a test (#178): when the clean-up task closes an open attempt.
 *
 * One reading for the task, the lab and the documentation (doc/issue-178-ablaufpolicy.md), in this order:
 * 1. Policy of the test (`catquiz_expirypolicy` in the settings of the test; set by the lab, no form field):
 *    'none' - never closed by the task; a positive number - this many seconds, without a buffer.
 * 2. Time limit of the test (form: "time limit" on, value > 0, unit): the limit times BUFFER_FACTOR, the time for
 *    the last answer.
 * 3. Otherwise - no settings, time limit switched off, value empty or 0 - the default of the site,
 *    `maximum_attempt_duration_hours`: hours, 0 = none; not set = 24.
 * The time counts from the start of the attempt (absolute run time); a pause counts. The settings of the test are
 * read from the row of the activity (both spellings of the component; the newest row wins).
 *
 * @package    local_catquiz
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class expiry_policy {
    /** @var string The key in the settings of a test. */
    public const SETTING = 'catquiz_expirypolicy';

    /** @var string Value of SETTING: the task never closes an attempt of the test. */
    public const NONE = 'none';

    /** @var float Extra time on the time limit of a test, for the last answer. */
    public const BUFFER_FACTOR = 1.5;

    /** @var int Hours of the default of the site when it is not set. */
    public const DEFAULT_HOURS = 24;

    /** @var string Source: policy of the test, none. */
    public const SOURCE_TEST_NONE = 'test-policy-none';
    /** @var string Source: policy of the test, seconds. */
    public const SOURCE_TEST_SECONDS = 'test-policy-seconds';
    /** @var string Source: time limit of the test. */
    public const SOURCE_TEST_LIMIT = 'test-timelimit';
    /** @var string Source: default of the site. */
    public const SOURCE_SITE = 'site-default';
    /** @var string Source: default of the site, 0 = none. */
    public const SOURCE_SITE_NONE = 'site-default-none';

    /** @var int|null Seconds after the start at which an attempt is expired; null: never. */
    public readonly ?int $seconds;

    /** @var string One of the SOURCE_* constants. */
    public readonly string $source;

    /** @var string Why the rule above it did not apply (for the log; empty if none). */
    public readonly string $note;

    /**
     * Constructor.
     *
     * @param int|null $seconds
     * @param string $source
     * @param string $note
     */
    private function __construct(?int $seconds, string $source, string $note = '') {
        $this->seconds = $seconds;
        $this->source = $source;
        $this->note = $note;
    }

    /**
     * The policy for the settings of a test.
     *
     * @param stdClass|null $settings decoded JSON of local_catquiz_tests; null: the test has no settings row
     * @param mixed $sitehours the setting maximum_attempt_duration_hours as get_config() returns it (false: not set)
     * @return self
     */
    public static function from_settings(?stdClass $settings, $sitehours): self {
        $note = '';
        if ($settings !== null && property_exists($settings, self::SETTING)) {
            $value = $settings->{self::SETTING};
            if ($value === self::NONE) {
                return new self(null, self::SOURCE_TEST_NONE);
            }
            if (is_numeric($value) && (int) $value > 0 && (float) $value == (int) $value) {
                return new self((int) $value, self::SOURCE_TEST_SECONDS);
            }
            // Not a value of the policy: it is not read as some other one; the rules below apply, and the log says so.
            $note = 'invalid ' . self::SETTING . ' ignored';
        }
        $limit = catquiz_handler::time_limits($settings)['attempt'];
        if ($limit !== null) {
            return new self((int) round($limit * self::BUFFER_FACTOR), self::SOURCE_TEST_LIMIT, $note);
        }
        $note = $note !== '' ? $note : ($settings === null ? 'no settings of the test' : 'no time limit of the test');
        $hours = self::site_hours($sitehours);
        if ($hours === 0) {
            return new self(null, self::SOURCE_SITE_NONE, $note);
        }
        return new self($hours * 3600, self::SOURCE_SITE, $note);
    }

    /**
     * The default of the site in hours: not set (false, null, '') is DEFAULT_HOURS; 0 is none; negative is none.
     *
     * Before #178 a setting that was not set read as 0 - no limit at all - since intval(false) is 0.
     *
     * @param mixed $value
     * @return int
     */
    public static function site_hours($value): int {
        if ($value === false || $value === null || trim((string) $value) === '') {
            return self::DEFAULT_HOURS;
        }
        return max(0, (int) $value);
    }

    /**
     * The policy of the attempts of an activity, from its settings row.
     *
     * @param int $adaptivequizid
     * @return self
     */
    public static function for_activity(int $adaptivequizid): self {
        return self::from_settings(
            self::settings_of($adaptivequizid),
            get_config('local_catquiz', 'maximum_attempt_duration_hours')
        );
    }

    /**
     * The settings of the test of an activity: the newest row of both spellings of the component.
     *
     * @param int $adaptivequizid
     * @return stdClass|null
     */
    public static function settings_of(int $adaptivequizid): ?stdClass {
        global $DB;
        $rows = $DB->get_records_select(
            'local_catquiz_tests',
            "componentid = :id AND component IN ('mod_adaptivequiz', 'adaptivequiz')",
            ['id' => $adaptivequizid],
            'id DESC',
            'id, json',
            0,
            1
        );
        if (!$rows) {
            return null;
        }
        $settings = json_decode((string) reset($rows)->json);
        return $settings instanceof stdClass ? $settings : null;
    }

    /**
     * Sets or removes the policy of a test (the lab; no form field). Writes all settings rows of the activity.
     *
     * @param int $adaptivequizid
     * @param string|int|null $value self::NONE, seconds > 0, or null to remove it (the default rules apply again)
     * @return int the number of settings rows written
     */
    public static function set_for_activity(int $adaptivequizid, $value): int {
        global $DB;
        if ($value !== null && $value !== self::NONE && !(is_int($value) && $value > 0)) {
            throw new \coding_exception('An expiry policy is \'' . self::NONE . '\', seconds > 0 or null.');
        }
        $rows = $DB->get_records_select(
            'local_catquiz_tests',
            "componentid = :id AND component IN ('mod_adaptivequiz', 'adaptivequiz')",
            ['id' => $adaptivequizid],
            'id ASC',
            'id, json'
        );
        foreach ($rows as $row) {
            $settings = json_decode((string) $row->json);
            $settings = $settings instanceof stdClass ? $settings : new stdClass();
            if ($value === null) {
                unset($settings->{self::SETTING});
            } else {
                $settings->{self::SETTING} = $value;
            }
            $DB->update_record('local_catquiz_tests', (object) [
                'id' => $row->id,
                'json' => json_encode($settings),
                'timemodified' => time(),
            ]);
        }
        \cache_helper::purge_by_event('changesintestenvironments');
        return count($rows);
    }

    /**
     * Whether an attempt is expired at a time.
     *
     * @param int $timecreated start of the attempt
     * @param int $now
     * @return bool
     */
    public function expired(int $timecreated, int $now): bool {
        return $this->seconds !== null && $now - $timecreated > $this->seconds;
    }

    /**
     * The policy for the log and the lab: no data of a person or an answer.
     *
     * @return array seconds (null: never), source, note
     */
    public function describe(): array {
        return ['seconds' => $this->seconds, 'source' => $this->source, 'note' => $this->note];
    }
}
