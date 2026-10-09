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

namespace local_catquiz\local\monitoring;

/**
 * Timeline of one request of a CAT attempt: where the time to a question goes (issue #136).
 *
 * Switched off by default (setting local_catquiz/attempttrace). When off, span() only calls the
 * function it is given and start()/stop()/note() return at once, so the instrumented code runs as
 * before.
 *
 * When on, the request collects spans - name, offset from the request start, duration from hrtime(), nesting depth,
 * database queries during the span and a few numbers such as cache hit or miss, rows and
 * candidates. At the end of the request one row per attempt request is written to
 * local_catquiz_trace, together with the spans the host recorded for the same request (lock wait,
 * loading the question, the question usage) when the host offers them.
 *
 * The trace never holds the content of a question or an answer. Span names are fixed in the code;
 * the values of note() are numbers, booleans or short identifiers, and anything else is dropped.
 *
 * @package    local_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class timeline {
    /** @var string Config name of the switch. */
    public const CONFIG = 'attempttrace';

    /** @var string Trace of the request that administers the first item of an attempt. */
    public const KIND_FIRST_ITEM = 'first_item';

    /** @var string Trace of a request that administers a following item. */
    public const KIND_NEXT_ITEM = 'next_item';

    /** @var string Table the traces are written to. */
    public const TABLE = 'local_catquiz_trace';

    /** @var string Host class that offers its own spans of the request; optional. */
    private const HOST_TIMING = '\\mod_adaptivequiz\\local\\request_timing';

    /** @var bool|null Whether tracing is on; null until first asked. */
    private static ?bool $enabled = null;

    /** @var array Recorded spans, in the order they started. */
    private static array $spans = [];

    /** @var int[] Indices of the open spans, innermost last. */
    private static array $open = [];

    /** @var int|null The adaptive quiz attempt the request works on. */
    private static ?int $adaptiveattemptid = null;

    /** @var string|null KIND_FIRST_ITEM or KIND_NEXT_ITEM. */
    private static ?string $kind = null;

    /** @var int|null Number of the item administered in this request, 1 for the first. */
    private static ?int $questionnumber = null;

    /** @var bool Whether the flush at the end of the request is registered. */
    private static bool $registered = false;

    /**
     * Whether the trace is switched on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        if (self::$enabled === null) {
            self::$enabled = (bool) get_config('local_catquiz', self::CONFIG);
        }
        return self::$enabled;
    }

    /**
     * Forgets everything recorded in this request and the cached switch.
     *
     * For tests and for the request end; nothing else needs it.
     */
    public static function reset(): void {
        self::$enabled = null;
        self::$spans = [];
        self::$open = [];
        self::$adaptiveattemptid = null;
        self::$kind = null;
        self::$questionnumber = null;
    }

    /**
     * Names the attempt and the kind of request the trace belongs to.
     *
     * Only a request with an attempt is written; spans of a request without one are discarded.
     *
     * @param int $adaptiveattemptid adaptivequiz_attempt.id
     * @param int $questionsattempted Items already answered before this request.
     */
    public static function attempt(int $adaptiveattemptid, int $questionsattempted): void {
        if (!self::enabled()) {
            return;
        }
        self::$adaptiveattemptid = $adaptiveattemptid;
        self::$kind = $questionsattempted > 0 ? self::KIND_NEXT_ITEM : self::KIND_FIRST_ITEM;
        self::$questionnumber = $questionsattempted + 1;
        if (!self::$registered && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            \core_shutdown_manager::register_function([self::class, 'flush']);
            self::$registered = true;
        }
    }

    /**
     * Opens a span.
     *
     * @param string $name Fixed name from the code, e.g. 'context:questions'.
     * @param array $meta Numbers or short identifiers; see note().
     */
    public static function start(string $name, array $meta = []): void {
        if (!self::enabled()) {
            return;
        }
        self::$spans[] = [
            'name' => $name,
            'depth' => count(self::$open),
            'offsetms' => self::offsetms(),
            'startns' => hrtime(true),
            'durationns' => null,
            'queriesatstart' => self::queries(),
            'meta' => self::clean($meta),
        ];
        self::$open[] = array_key_last(self::$spans);
    }

    /**
     * Closes the innermost open span of that name, and any span opened inside it and left open.
     *
     * @param string $name
     * @param array $meta Added to the span; see note().
     */
    public static function stop(string $name, array $meta = []): void {
        if (!self::enabled()) {
            return;
        }
        $now = hrtime(true);
        for ($i = count(self::$open) - 1; $i >= 0; $i--) {
            if (self::$spans[self::$open[$i]]['name'] !== $name) {
                continue;
            }
            // The last span closed in this loop is the one named; spans left open inside it end with it.
            while (count(self::$open) > $i) {
                $index = array_pop(self::$open);
                self::$spans[$index]['durationns'] = $now - self::$spans[$index]['startns'];
                self::$spans[$index]['queries'] = self::queries() - self::$spans[$index]['queriesatstart'];
            }
            self::$spans[$index]['meta'] += self::clean($meta);
            return;
        }
    }

    /**
     * Runs a function inside a span and returns its result.
     *
     * @param string $name
     * @param callable $fn
     * @param array $meta
     * @return mixed What the function returns.
     */
    public static function span(string $name, callable $fn, array $meta = []) {
        if (!self::enabled()) {
            return $fn();
        }
        self::start($name, $meta);
        try {
            return $fn();
        } finally {
            self::stop($name);
        }
    }

    /**
     * Attaches a number or a short identifier to the innermost open span.
     *
     * Allowed are integers, floats, booleans, null and identifiers of up to 40 characters made of
     * letters, digits and _ : . -; anything else is dropped. This keeps question text, answers and
     * names out of the trace whatever a caller passes.
     *
     * @param string $key
     * @param mixed $value
     */
    public static function note(string $key, $value): void {
        if (!self::enabled() || !self::$open) {
            return;
        }
        $index = end(self::$open);
        self::$spans[$index]['meta'] += self::clean([$key => $value]);
    }

    /**
     * Adds a span measured elsewhere, e.g. by the host, as a finished span.
     *
     * @param string $name
     * @param float $ms Duration in milliseconds.
     * @param float|null $offsetms Start relative to the request start, when known.
     * @param int $depth Nesting depth.
     * @param array $meta
     */
    public static function add(string $name, float $ms, ?float $offsetms = null, int $depth = 0, array $meta = []): void {
        if (!self::enabled()) {
            return;
        }
        self::$spans[] = [
            'name' => $name,
            'depth' => $depth,
            'offsetms' => $offsetms,
            'durationns' => (int) round($ms * 1e6),
            'meta' => self::clean($meta),
        ];
    }

    /**
     * The spans recorded so far, in milliseconds, ready to store.
     *
     * @return array
     */
    public static function get_spans(): array {
        $out = [];
        foreach (self::$spans as $span) {
            $row = [
                'name' => $span['name'],
                'depth' => $span['depth'],
                'offsetms' => $span['offsetms'],
                'ms' => $span['durationns'] === null ? null : round($span['durationns'] / 1e6, 3),
            ];
            if (isset($span['queries'])) {
                $row['queries'] = $span['queries'];
            }
            if ($span['meta']) {
                $row['meta'] = $span['meta'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Writes the trace of this request and starts afresh.
     *
     * Runs at the end of the request. Nothing is written when the trace is off, or when the request
     * named no attempt. Spans the host recorded for the request are added under host:.
     *
     * @return int|null Id of the written row.
     */
    public static function flush(): ?int {
        global $DB, $USER;

        if (!self::enabled() || self::$adaptiveattemptid === null) {
            self::discard();
            return null;
        }

        $hostclass = self::HOST_TIMING;
        if (class_exists($hostclass)) {
            foreach ($hostclass::get_spans() as $span) {
                self::add(
                    'host:' . $span['name'],
                    (float) $span['ms'],
                    isset($span['offsetms']) ? (float) $span['offsetms'] : null,
                    (int) ($span['depth'] ?? 0),
                    (array) ($span['meta'] ?? [])
                );
            }
            // Read once: a process that serves several attempts (a CLI benchmark) must not carry the
            // host's spans into the next trace.
            $hostclass::reset();
        }

        $requestms = self::offsetms();
        $record = (object) [
            'adaptiveattemptid' => self::$adaptiveattemptid,
            'userid' => (int) ($USER->id ?? 0),
            'kind' => self::$kind,
            'questionnumber' => self::$questionnumber,
            'requestms' => $requestms,
            'dbqueries' => self::queries(),
            'spans' => json_encode(self::get_spans()),
            'timecreated' => time(),
        ];
        self::discard();

        try {
            return (int) $DB->insert_record(self::TABLE, $record);
        } catch (\Throwable $e) {
            // A diagnosis must never cost the attempt anything.
            debugging('local_catquiz: the attempt trace could not be written: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Drops what was recorded, keeping the switch.
     */
    private static function discard(): void {
        $enabled = self::$enabled;
        self::reset();
        self::$enabled = $enabled;
    }

    /**
     * Milliseconds since the start of the request - the time axis the host uses too.
     *
     * @return float|null
     */
    public static function offsetms(): ?float {
        if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return null;
        }
        return round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 3);
    }

    /**
     * Database queries of this request so far.
     *
     * @return int
     */
    private static function queries(): int {
        global $DB;
        return method_exists($DB, 'perf_get_queries') ? (int) $DB->perf_get_queries() : 0;
    }

    /**
     * Keeps numbers, booleans, null and short identifiers; drops everything else.
     *
     * @param array $meta
     * @return array
     */
    private static function clean(array $meta): array {
        $out = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,40}$/', $key)) {
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $out[$key] = $value;
            } else if (is_string($value) && preg_match('/^[A-Za-z0-9_:.\-]{0,40}$/', $value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
