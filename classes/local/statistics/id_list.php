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

namespace local_catquiz\local\statistics;

/**
 * A list of IDs in a shortcode, a request or a scope: one ID or several, separated by commas (issue #175).
 *
 * One strict reading for every ID argument of the shortcodes, the chart endpoint and the CSV exports, so that the
 * page, the charts loaded later and the export never disagree about the set:
 * - "12", 12, "12,13", "12, 13", " 12 ,13 " and [12, 13] are lists; whitespace around an ID (also a non-breaking
 *   space from an editor) is ignored.
 * - Every entry is a positive whole number of at most 18 digits. Anything else - "12a", "-3", "1.5", "0x10", an
 *   empty entry as in "12,,13" or "12," - is refused with the entry named. Nothing is cut to a number: before,
 *   (int) "12a" became 12 and "12,13" became 12.
 * - A repeated ID counts once; the order of first appearance is kept.
 * - At most MAX distinct IDs (the limit is documented in the README, section of the shortcodes).
 * - Not given, an empty string, 0 or "0" alone: no list (the earlier meaning of 0 - "no test", "this course").
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class id_list {
    /** @var int The most IDs one argument may list. */
    public const MAX = 100;

    /** @var int The most digits of one ID: below PHP_INT_MAX and any BIGINT id. */
    private const DIGITS = 18;

    /**
     * Reads an argument: one ID or a comma-separated list.
     *
     * @param mixed $raw The argument as given: null, int, string or array of those.
     * @param string $name Name of the argument, for the message.
     * @param int $max The most distinct IDs.
     * @return int[] The distinct IDs in the order of their first appearance; empty when none is given.
     * @throws \moodle_exception For an entry that is no ID, or too many IDs.
     */
    public static function parse($raw, string $name, int $max = self::MAX): array {
        if ($raw === null || $raw === false || $raw === '' || $raw === 0 || $raw === []) {
            return [];
        }
        if ($raw === true) {
            // An argument without a value ([catquizstatistics testid]): the shortcode filter gives true.
            throw self::invalid($name, '');
        }
        if (is_array($raw)) {
            $tokens = array_map(fn($value) => is_int($value) ? (string) $value : $value, array_values($raw));
        } else if (is_int($raw)) {
            $tokens = [(string) $raw];
        } else if (is_string($raw)) {
            $trimmed = self::trim($raw);
            if ($trimmed === '' || $trimmed === '0') {
                return [];
            }
            $tokens = explode(',', $trimmed);
        } else {
            throw self::invalid($name, is_scalar($raw) ? (string) $raw : gettype($raw));
        }

        $ids = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                throw self::invalid($name, is_scalar($token) ? (string) $token : gettype($token));
            }
            $token = self::trim($token);
            if (!preg_match('/^[0-9]{1,' . self::DIGITS . '}$/', $token) || (int) $token <= 0) {
                throw self::invalid($name, $token);
            }
            $ids[(int) $token] = (int) $token;
            if (count($ids) > $max) {
                throw new \moodle_exception(
                    'shortcodeidlisttoolong',
                    'local_catquiz',
                    '',
                    (object) ['name' => $name, 'max' => $max]
                );
            }
        }
        return array_values($ids);
    }

    /**
     * Reads an argument that has more than one name - courseid and course, say (issue #175).
     *
     * Each name given is read; given under two names, the lists have to name the same IDs.
     *
     * @param array $args The arguments.
     * @param string[] $names The names, the first is the one in the message.
     * @param int $max
     * @return int[]
     * @throws \moodle_exception For an invalid list, or two names with different IDs.
     */
    public static function parse_alias(array $args, array $names, int $max = self::MAX): array {
        $found = null;
        $foundname = null;
        foreach ($names as $name) {
            if (!array_key_exists($name, $args)) {
                continue;
            }
            $ids = self::parse($args[$name], $name, $max);
            if ($ids === []) {
                continue;
            }
            if ($found !== null && self::sorted($found) !== self::sorted($ids)) {
                throw new \moodle_exception(
                    'shortcodealiasconflict',
                    'local_catquiz',
                    '',
                    (object) ['first' => $foundname, 'second' => $name]
                );
            }
            $found = $found ?? $ids;
            $foundname = $foundname ?? $name;
        }
        return $found ?? [];
    }

    /**
     * The set of an internal scope argument: null for "no filter", otherwise the distinct IDs, sorted.
     *
     * For the classes that take a course or a test as one ID or as several (catquizstatistics,
     * attempt_population, eligible_population). 0, null and an empty array are no filter, as 0 and null were.
     *
     * @param int|int[]|null $value
     * @return int[]|null
     */
    public static function normalise($value): ?array {
        if ($value === null || $value === 0 || $value === []) {
            return null;
        }
        $values = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($values as $id) {
            if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]{1,' . self::DIGITS . '}$/', $id))) {
                throw new \coding_exception('Not an ID: ' . (is_scalar($id) ? (string) $id : gettype($id)));
            }
            if ((int) $id <= 0) {
                throw new \coding_exception('Not an ID: ' . $id);
            }
            $ids[(int) $id] = (int) $id;
        }
        ksort($ids);
        return array_values($ids);
    }

    /**
     * The only ID of a set, or null for no set or more than one.
     *
     * @param int[]|null $ids
     * @return int|null
     */
    public static function single(?array $ids): ?int {
        return ($ids !== null && count($ids) === 1) ? (int) reset($ids) : null;
    }

    /**
     * A list for a URL or a scope: "12,13", empty for none.
     *
     * @param int[]|null $ids
     * @return string
     */
    public static function to_string(?array $ids): string {
        return $ids ? implode(',', array_map('intval', $ids)) : '';
    }

    /**
     * Sorted copy.
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function sorted(array $ids): array {
        sort($ids);
        return $ids;
    }

    /**
     * Whitespace around an entry, the non-breaking space of an editor included.
     *
     * @param string $value
     * @return string
     */
    private static function trim(string $value): string {
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);
        // Not UTF-8: left as it is, and refused as an entry.
        return $trimmed === null ? $value : $trimmed;
    }

    /**
     * The error of an entry that is no ID: the entry named and shortened. The message is plain text: whoever shows it
     * escapes it.
     *
     * @param string $name
     * @param string $token
     * @return \moodle_exception
     */
    private static function invalid(string $name, string $token): \moodle_exception {
        $token = mb_check_encoding($token, 'UTF-8') ? $token : bin2hex(substr($token, 0, 10));
        $shown = \core_text::substr($token, 0, 20) . (\core_text::strlen($token) > 20 ? '…' : '');
        return new \moodle_exception(
            'shortcodeidlistinvalid',
            'local_catquiz',
            '',
            (object) ['name' => $name, 'token' => $shown]
        );
    }
}
