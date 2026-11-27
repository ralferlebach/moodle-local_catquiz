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
 * Progress stores the progress of a catquiz attempt that is not yet finished.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_catquiz\teststrategy;

use local_catquiz\local\attempt\progress_integrity;
use cache;
use coding_exception;
use JsonSerializable;
use local_catquiz\catquiz;
use local_catquiz\catscale;
use local_catquiz\testenvironment;
use Random\RandomException;
use stdClass;
use local_catquiz\local\progress_retention;

/**
 * Stores the progress of a catquiz attempt that is not yet finished.
 *
 * @package local_catquiz
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress implements JsonSerializable {
    /** @var string A scale became active (issue #133). */
    public const SCALE_ACTIVATED = 'activated';

    /** @var string A scale stopped being active. */
    public const SCALE_DEACTIVATED = 'deactivated';

    /** @var string A scale was locked: only a forced activation brings it back. */
    public const SCALE_LOCKED = 'locked';

    /** @var string A lock was lifted. */
    public const SCALE_UNLOCKED = 'unlocked';

    /** @var string A scale was removed for the rest of the attempt. */
    public const SCALE_REMOVED = 'removed';

    /** @var int JSON format that refers to played questions by id (issue #143). */
    public const FORMAT_COMPACT = 2;

    /** @var int Issue #177: the JSON is the head of fixed size; the rest are events in local_catquiz_progress_steps. */
    public const FORMAT_STEPS = 3;

    /** @var string Issue #177: a question was handed out (add_playedquestion()). */
    public const EVENT_SHOWN = 'shown';

    /** @var string Issue #177: the response that counts for a question was taken in (the first processed one). */
    public const EVENT_ANSWER = 'answer';

    /**
     * @var string Issue #177 (re-audit 27.11.2026, decision 8): a later response to a question that has its answer - the
     * question in another slot of the usage (handed out once more), a later step of its slot (a manual grade), a
     * failure when it was handed out again. Logged with its identities of the usage, not counted.
     */
    public const EVENT_LATER = 'later';

    /** @var string Issue #177: the person gave up the question; with the response it was counted as. */
    public const EVENT_GAVEUP = 'gaveup';

    /** @var string Issue #177: a question was excluded from the selection (exclude_question()). */
    public const EVENT_EXCLUDED = 'excluded';

    /** @var string Issue #177: the state of the scales changed without a question event (carries estimates, history). */
    public const EVENT_SCALESTATE = 'scalestate';

    /** @var int Issue #177: version of the event format. */
    public const EVENT_FORMAT = 1;

    /** @var string[] Issue #177: the columns of an event (local_catquiz_progress_steps), but its id. */
    private const EVENT_COLUMNS = [
        'catattemptid', 'seq', 'eventtype', 'questionusageid', 'slot', 'questionattemptid', 'questionattemptstepid',
        'itemid', 'catscaleid', 'ispilot', 'paramid', 'fraction', 'originalfraction', 'difficulty', 'information',
        'estimatesdelta', 'diagnostics', 'timecreated', 'formatversion',
    ];

    /** @var string[] Response fields that are texts of the question usage: stored nowhere by the progress (D). */
    public const RESPONSE_TEXTS = ['questionsummary', 'rightanswer', 'responsesummary'];

    /**
     * @var ?int $id The ID in the database.
     */
    private ?int $id;

    /**
     * @var int $userid The ID of the user.
     */
    private int $userid;

    /**
     * @var string $component The name of the component. E.g. mod_adaptivequiz.
     */
    private string $component;

    /**
     * @var int $contextid The context ID
     */
    private int $contextid;

    /**
     * @var int $attemptid ID to identify the quiz attempt.
     */
    private int $attemptid;

    /**
     * @var int|null $catattemptid The CAT attempt this progress belongs to - local_catquiz_attempts.id.
     *
     * This is what local_catquiz_progress.attemptid holds. The property above keeps the id of the
     * attempt of the component - for mod_adaptivequiz that is adaptivequiz_attempt.id - because the
     * callers work with it; that one is never written to local_catquiz_progress.
     */
    private ?int $catattemptid = null;

    /**
     * @var ?int $usageid Used to find questions answered in the current attempt.
     */
    private ?int $usageid;

    /**
     * @var array $playedquestions The questions that were already displayed to the user.
     */
    private array $playedquestions;

    /**
     * @var array $playedquestionsbyscale The questions that were already displayed to the user.
     */
    private array $playedquestionsbyscale;

    /**
     * @var bool $isfirstquestion Indicates if this is the first question in the current attempt.
     */
    private bool $isfirstquestion;

    /**
     * @var null|stdClass The previous question.
     */
    private ?stdClass $lastquestion;

    /**
     * @var ?int $breakend If a user is forced to take a break, this stores the end of the break.
     */
    private ?int $breakend;

    /**
     * @var array $activescales
     */
    private array $activescales;

    /**
     * @var array $droppedscales
     */
    private array $droppedscales;

    /**
     * If a scale is locked, it can not be activated or deactivated without
     * using a `force` parameter.
     *
     * @var array
     */
    private array $lockedscales;

    /**
     * @var array $responses
     */
    private array $responses;

    /**
     * @var array Holds the abilities indexed by catscale
     */
    private array $abilities;

    /**
     * Ability estimate per scale and step, recorded in trace mode only.
     * @var array
     */
    private array $abilitytrace = [];

    /**
     * Changes of the scales' state, recorded in trace mode only (issue #133): step, scaleid, event.
     * @var array
     */
    private array $scalestatetrace = [];

    /**
     * The state after each answer that the history of the attempt needs and nothing else keeps (issue #168): per
     * answered question, in order, the ability of the root scale and of every scale after the answer, rounded as the
     * history shows them. The history itself (graphicalsummary) is built from this, the played questions and the
     * responses when the attempt ends - it is no longer written into the feedback data with every answer.
     *
     * Entries: ['q' => question id, 'a' => ability of the root scale or null, 's' => [scaleid => ability]].
     * @var array
     */
    private array $answerhistory = [];

    /**
     * @var array Holds the person abilities as they were BEFORE this attempt,
     * indexed by catscale. Captured once at the first question from the loaded
     * priors, so a scale not validly measured in this attempt can be restored to
     * its pre-attempt state at finalisation (issue #9, Phase 2).
     */
    private array $preattemptabilities = [];

    /**
     * If the user is forced to take a break, this holds the timestamp of the end of the break.
     *
     * If no break is enforced, it has a value of null.
     *
     * @var ?int $forcedbreakend
     */
    private ?int $forcedbreakend;

    /**
     * Indicates if we have a new response and should update feedbackdata etc.
     * @var bool
     */
    private bool $hasnewresponse;

    /**
     * Holds the session key of the session when the quiz was started
     *
     * @var ?string
     */
    private ?string $session;

    /**
     * Shows if a new question should be displayed even after a page reload.
     *
     * @var bool
     */
    private bool $forcenewquestion;

    /**
     * Holds a list of questions that should not be returned.
     *
     * @var array
     */
    private array $excludedquestions;

    /**
     * Shows if we should skip updating internal values based on the last response.
     *
     * @var bool
     */
    private bool $ignorelastresponse;

    /**
     * Contains question IDs that the user did not answer.
     *
     * @var array
     */
    private array $gaveupquestions;

    /**
     * Holds the starttime of the attempt.
     *
     * @var int
     */
    private int $starttime;

    /**
     * Holds the quizsetting
     *
     * This holds the settings as given when the attempt was started.
     * @var stdClass
     */
    private stdClass $quizsettings;

    /**
     * Issue #177: the settings as they are stored with the head (JSON), null when not known to be stored. The head
     * writes them again only when they differ - they are the largest part of it and do not change during an attempt.
     *
     * @var string|null
     */
    private ?string $savedsettings = null;

    /**
     * Issue #177: the head was filed by this save - there was none before, so no event of this attempt can be its own.
     *
     * @var bool
     */
    private bool $freshhead = false;

    /**
     * Issue #159: rebuilt from what was stored with a finished attempt after its progress was removed - read only.
     *
     * @var bool
     */
    private bool $historical = false;

    /**
     * Issue #177: the format this progress is stored in - FORMAT_STEPS, or 0 for one that was read from (or cached in)
     * a format before and is converted by its next save: all its events at once, then the head.
     *
     * @var int
     */
    private int $format = 0;

    /**
     * Issue #177: the last event of this working state that is stored (local_catquiz_progress.lastseq).
     *
     * @var int
     */
    private int $lastseq = 0;

    /**
     * Issue #177: the questions handed out since the last save, as their shown events will be stored - in the order
     * add_playedquestion() was called, a question again when it was added again (a reload).
     *
     * @var array
     */
    private array $unsavedshown = [];

    /**
     * Issue #177: the responses taken in or changed since the last save, by question id.
     *
     * @var array
     */
    private array $dirtyresponses = [];

    /** @var int Issue #177: how many calls of add_playedquestion() are stored as shown events. */
    private int $savedshown = 0;

    /** @var int Issue #177: how many responses are stored as events (the responses are only added to, in order). */
    private int $savedresponses = 0;

    /** @var int Issue #177: how many excluded questions are stored as events. */
    private int $savedexcluded = 0;

    /** @var int Issue #177: how many given up questions are stored as events. */
    private int $savedgaveup = 0;

    /**
     * Issue #177 (re-audit 27.11.2026): later responses to questions that have their answer, in order - logged, not
     * counted (decision 8). Each as the usage gives it, without its texts.
     *
     * @var array[]
     */
    private array $laterresponses = [];

    /** @var int Issue #177: how many later responses are stored as events. */
    private int $savedlater = 0;

    /**
     * Issue #177 (AK3): the steps of the usage taken in - by the response that counts or as a later one -, so that a
     * step is taken in once (a reload, a second submit, a request after a crash). Built from the responses when null.
     *
     * @var array<string,bool>|null
     */
    private ?array $recordedsteps = null;

    /** @var int Issue #177: how many entries of the answer history are stored with events. */
    private int $savedhistory = 0;

    /** @var int Issue #177: how many entries of the scale state trace are stored with events (trace mode). */
    private int $savedscalestates = 0;

    /** @var array Issue #177: per scale, how many entries of the ability trace are stored with events (trace mode). */
    private array $savedtrace = [];

    /**
     * Issue #177 (C): the estimate of every scale as last stored - scale id => [ability, standard error, n]. An event
     * carries the scales that differ from it.
     *
     * @var array
     */
    private array $savedestimates = [];

    /**
     * Issue #177 (C): the standard error of every scale after the last answer, as the selection computed it.
     *
     * @var array
     */
    private array $standarderrors = [];

    /**
     * Issue #177: the rows of the debug information (store_debug_info), one per answer - numbers and identities only.
     * Stored with the events; the column of the attempt is written once, at its end.
     *
     * @var array
     */
    private array $debugrows = [];

    /** @var int Issue #177: how many rows of the debug information are stored with events. */
    private int $saveddebugrows = 0;

    /**
     * Returns the internal CAT attempt id for the attempt of a component.
     *
     * Kept as a thin delegate: the resolution lives in catquiz because more than the progress
     * needs it, and it must read the same way everywhere.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component The component the attempt belongs to.
     * @return int|null The id in local_catquiz_attempts, null when there is no CAT attempt yet.
     */
    public static function get_cat_attempt_id(int $adaptiveattemptid, string $component): ?int {
        return catquiz::get_cat_attempt_id($adaptiveattemptid, $component);
    }

    /**
     * Files the CAT attempt for a test that is starting.
     *
     * Only what is known at the start is written: the person, the attempt of the component and a
     * running status. Scale, context and course stay empty until the result page saves the attempt
     * through catquiz::save_attempt_to_db(), which updates this very row - it looks the attempt up by
     * its component id. Leaving them empty is deliberate: statistics select by scale, context or
     * course, and a running attempt is excluded from them without every query having to know.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component The component the attempt belongs to.
     * @return int|null The id of the new row in local_catquiz_attempts, null if none can be filed.
     */
    private static function start_cat_attempt(int $adaptiveattemptid, string $component): ?int {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/catquiz/lib.php');

        // The CAT attempt belongs to the person whose attempt it is - also when somebody else is the
        // first to touch it (issue #95). For an activity attempt that is its user, not $USER.
        $ownerid = (int) $USER->id;
        if (in_array('mod_adaptivequiz', catquiz::component_names($component), true)) {
            $owner = $DB->get_field('adaptivequiz_attempt', 'userid', ['id' => $adaptiveattemptid]);
            if ($owner !== false) {
                $ownerid = (int) $owner;
            }
        }

        $existing = $DB->get_record('local_catquiz_attempts', ['attemptid' => $adaptiveattemptid]);
        if (!$existing) {
            $now = time();
            try {
                return (int) $DB->insert_record('local_catquiz_attempts', (object) [
                    'userid' => $ownerid,
                    'attemptid' => $adaptiveattemptid,
                    'component' => $component,
                    'status' => LOCAL_CATQUIZ_ATTEMPT_RUNNING,
                    'json' => '{}',
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            } catch (\dml_write_exception $e) {
                // Issue #95: a request in parallel was first - its row is taken over below. Before, the
                // unique index failed the second request with an error page.
                $existing = $DB->get_record('local_catquiz_attempts', ['attemptid' => $adaptiveattemptid]);
                if (!$existing) {
                    throw $e;
                }
            }
        }

        /* local_catquiz_attempts.attemptid is unique on its own (issue #5), not per component. A row of the
           same component and the same person is this attempt's CAT attempt; another component or person
           holding the number gets nothing of it - the progress then stays in the cache, as before issue #101,
           and is never handed someone else's. Resolving the namespace properly is issue #105. */
        if (
            in_array((string) $existing->component, catquiz::component_names($component), true)
            && (int) $existing->userid === $ownerid
        ) {
            return (int) $existing->id;
        }
        debugging(
            "Attempt {$adaptiveattemptid} of {$component} shares its number with a CAT attempt of another "
                . 'component or person; no CAT attempt was filed for it.',
            DEBUG_DEVELOPER
        );
        return null;
    }

    /**
     * Returns a new progress instance.
     *
     * If we already have data in the cache or DB, the instance is populated with those data.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component
     * @param int $contextid
     * @param ?stdClass $quizsettings
     * @return progress
     */
    public static function load(int $adaptiveattemptid, string $component, int $contextid, ?stdClass $quizsettings = null): self {
        $catattemptid = self::get_cat_attempt_id($adaptiveattemptid, $component);

        /* Issue #101: the CAT attempt exists before the first progress access. Until now it was
           written only by the result page at the end of a test, so during the whole attempt there
           was nothing to attach the progress to - it lived in the cache only and was lost to anyone
           else and to any cache flush. A caller that brings quiz settings is starting or continuing
           a test; that is where the CAT attempt is filed. A caller without settings only reads, and
           a reader never creates an attempt. */
        if ($catattemptid === null && $quizsettings !== null) {
            $catattemptid = self::start_cat_attempt($adaptiveattemptid, $component);
        }

        $instance = self::load_from_cache($catattemptid, $adaptiveattemptid, $component)
            ?: self::load_from_db($catattemptid, $contextid, $quizsettings !== null);
        if (!$instance) {
            /* Issue #149: a reader - no settings - never creates a progress, a CAT attempt or a cache entry. It
               used to go on to create_new() with settings null and ended in a TypeError on the result page. */
            if ($quizsettings === null) {
                throw new progress_missing_exception($adaptiveattemptid, $component);
            }
            $instance = self::create_new($adaptiveattemptid, $component, $contextid, $quizsettings);
        }

        // The CAT attempt may have been created after this progress was first built - the component
        // starts its attempt, the CAT model files its own right after. Pick it up when it appears.
        $instance->catattemptid = $catattemptid ?? $instance->catattemptid;
        // Re-audit 28.10.2026 (#149): a reader leaves the cache as it is - the move to the key of the CAT attempt is a
        // write, and only a writer makes it.
        if ($quizsettings !== null) {
            $instance->move_to_canonical_cache_key();
        }

        $instance->hasnewresponse = false;
        $instance->ignorelastresponse = false;

        if (!$instance->lastquestion) {
            return $instance;
        }

        $lastresponse = $instance->get_last_response_for_attempt();

        // This is the expected default behaviour: the user answered the last
        // question and now we'll return the next one.
        if ($lastresponse && $lastresponse->questionid === $instance->lastquestion->id) {
            $instance->hasnewresponse = true;
            return $instance;
        }

        // If the user gave up, count it as negative response.
        if ($instance->user_gave_up_last_question()) {
            $instance->gaveupquestions[] = $instance->lastquestion->id;
            $instance->mark_lastquestion_failed();
            $instance->hasnewresponse = true;

            return $instance;
        }

        /* The last administered question is still unanswered - typically after a
           reload or a resume. It STAYS in playedquestions: it was displayed to the
           user, and playedquestions is documented as exactly that ("the questions
           that were already displayed"). Removing it made the structure contradict
           itself, because lastquestion then pointed at a question that had
           supposedly never been played, and it made get_num_playedquestions()
           non-monotonic. The missing response identifies the item as pending, and
           every place that needs "how many questions were ANSWERED" now asks
           get_num_answered_productive_questions() instead of counting this array
           (Issue #6). Keeping it also prevents the pending item from being selected
           again as if it were a new question. */
        return $instance;
    }

    /**
     * The progress of a finished attempt whose progress was removed by the retention task, rebuilt read-only from
     * what was stored with the attempt when it was finalised (issue #159).
     *
     * Nothing is played again, nothing is estimated again with today's item parameters, nothing is written: the
     * questions in their order with the answer, scale and Fisher information they had, the abilities of the result,
     * the context and the start of the attempt. The settings come as for any progress stored without them (the
     * test's). What the stored data do not hold (the per-step trace, the state of the selection) stays empty; the
     * sections that would need it say so.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id.
     * @param string $component
     * @return self|null Null while the attempt is running, without stored data, or without a CAT attempt.
     */
    public static function load_historical(int $adaptiveattemptid, string $component): ?self {
        global $DB;
        $catattemptid = self::get_cat_attempt_id($adaptiveattemptid, $component);
        if ($catattemptid === null) {
            return null;
        }
        $attempt = $DB->get_record('local_catquiz_attempts', ['id' => $catattemptid]);
        if (!$attempt || empty($attempt->endtime)) {
            return null;
        }
        $stored = json_decode((string) $attempt->json);
        if (!is_object($stored)) {
            return null;
        }
        // Issue #159 (21.11.2026): data stored without them got the history and the abilities from the progress
        // before it was removed (progress_retention::preserve_for_display()).
        if (empty($stored->personabilities) && !empty($stored->retention_abilities)) {
            $stored->personabilities = $stored->retention_abilities;
        }
        if (empty($stored->graphicalsummary_data) && !empty($stored->retention_history)) {
            $stored->graphicalsummary_data = $stored->retention_history;
        } else if (!empty($stored->retention_history)) {
            // Re-audit of 22.11.2026: a history that lacked some answers got their rows from the progress.
            $have = array_flip(array_map(fn($row) => (string) (((object) $row)->id ?? ''), (array) $stored->graphicalsummary_data));
            foreach ((array) $stored->retention_history as $row) {
                if (!isset($have[(string) (((object) $row)->id ?? '')])) {
                    $stored->graphicalsummary_data[] = $row;
                }
            }
        }
        if (empty($stored->personabilities)) {
            return null;
        }
        $root = (int) ($stored->catscaleid ?? $attempt->scaleid);
        $parents = $DB->get_records_menu('local_catquiz_catscales', null, '', 'id, parentid');
        $played = [];
        $byscale = [];
        $responses = [];
        foreach ((array) ($stored->graphicalsummary_data ?? []) as $row) {
            $row = (object) $row;
            if (empty($row->id)) {
                continue;
            }
            $pilot = !empty($row->pilot);
            $question = (object) [
                'id' => (string) $row->id,
                'componentid' => (string) $row->id,
                'label' => (string) ($row->questionname ?? ''),
                'idnumber' => (string) ($row->questionname ?? ''),
                'name' => (string) ($row->questiontitle ?? ''),
                'catscaleid' => (string) ($row->questionscale ?? ''),
                'difficulty' => $row->difficulty ?? null,
                'is_pilot' => $pilot,
                'fisherinformation' => $pilot ? [] : [$root => (float) ($row->fisherinformation ?? 0)],
            ];
            $played[$question->id] = $question;
            // As the progress files a question: under its scale and every ancestor.
            for ($scaleid = (int) $question->catscaleid, $guard = 0; $scaleid > 0 && $guard < 50; $guard++) {
                $byscale[$scaleid][] = $question->id;
                $scaleid = (int) ($parents[$scaleid] ?? 0);
            }
            if (isset($row->lastresponse)) {
                $responses[$question->id] = ['questionid' => $question->id, 'fraction' => (float) $row->lastresponse];
            }
        }
        $abilities = [];
        foreach ((array) ($stored->updated_personabilities ?? []) as $scaleid => $entry) {
            if (is_object($entry) && isset($entry->value) && is_numeric($entry->value)) {
                $abilities[$scaleid] = (float) $entry->value;
            }
        }
        foreach ((array) $stored->personabilities as $scaleid => $value) {
            if (is_numeric($value)) {
                $abilities[$scaleid] = (float) $value;
            }
        }
        $data = [
            'progressformat' => self::FORMAT_COMPACT, 'playedquestions' => $played, 'playedquestionsbyscale' => $byscale,
            'isfirstquestion' => false, 'lastquestion' => null, 'breakend' => null, 'activescales' => [],
            'droppedscales' => [], 'contextid' => (int) $attempt->contextid, 'responses' => $responses,
            'abilities' => $abilities, 'preattemptabilities' => [], 'forcedbreakend' => null, 'lockedscales' => [],
            'usageid' => null, 'session' => null, 'excludedquestions' => [], 'gaveupquestions' => [],
            'starttime' => (int) $attempt->starttime, 'abilitytrace' => [],
        ];
        $object = (object) [
            'id' => null, 'userid' => (int) $attempt->userid, 'component' => $component, 'attemptid' => $catattemptid,
            'json' => json_encode($data), 'quizsettings' => null,
        ];
        // Read only: populate_from_object() without the cache neither saves nor caches (issue #149).
        $instance = self::populate_from_object($object, (int) $attempt->contextid, false);
        $instance->historical = true;
        $instance->hasnewresponse = false;
        $instance->ignorelastresponse = false;
        return $instance;
    }

    /**
     * Whether this progress was rebuilt from a finished attempt after its own was removed (issue #159).
     *
     * @return bool
     */
    public function is_historical(): bool {
        return $this->historical;
    }

    /**
     * Try to load a progress object from the cache.
     *
     * Under the key of the CAT attempt; before it exists - or for a progress built just before it was
     * filed - under the key of the component and its attempt (issue #95).
     *
     * @param int|null $catattemptid Id in local_catquiz_attempts, null while there is none.
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component Component asking; empty to skip the component check.
     * @return progress|false
     * @throws coding_exception
     */
    private static function load_from_cache(?int $catattemptid, int $adaptiveattemptid, string $component = '') {
        $attemptcache = cache::make('local_catquiz', 'adaptivequizattempt');
        $cachekey = self::get_cache_key($catattemptid, $adaptiveattemptid, $component);
        $cached = $attemptcache->get($cachekey);
        if (!$cached instanceof self && $catattemptid !== null) {
            $cachekey = self::get_cache_key(null, $adaptiveattemptid, $component);
            $cached = $attemptcache->get($cachekey);
        }

        if (!$cached instanceof self) {
            return false;
        }

        // The cache key carries the user, but a stale entry could still name another one, and the
        // component has never been part of the key at all. Progress of one component must not be
        // handed to another under the same numeric id.
        if (!$cached->belongs_to_current_request($component)) {
            $attemptcache->delete($cachekey);

            return false;
        }

        // Issue #177 (AK3): another session went on with the attempt - the stored working state is ahead of this cache.
        if (!$cached->is_current()) {
            $attemptcache->delete($cachekey);

            return false;
        }

        return $cached;
    }

    /**
     * Returns whether this progress belongs to the user and component asking for it.
     *
     * The identity of a progress record is the pair (component, attemptid) plus the user it was
     * created for - a numeric attempt id on its own says nothing. Ids are reused across components
     * and survive as orphans, so a record found under one must still be proven to belong here.
     *
     * @param string $component Component asking, empty to skip the component check.
     * @return bool
     */
    private function belongs_to_current_request(string $component): bool {
        global $USER;

        if ((int) $this->userid !== (int) $USER->id) {
            return false;
        }

        return $component === '' || $this->component === $component;
    }

    /**
     * Reads the stored progress of a CAT attempt, for looking at it only.
     *
     * No cache, no ownership check against the current user and nothing created: a monitor reads
     * the progress of other people's running attempts, and a missing progress stays missing. The
     * caller is responsible for deciding who may see it.
     *
     * @param int $catattemptid Id in local_catquiz_attempts.
     * @return self|null
     */
    public static function load_for_reading(int $catattemptid): ?self {
        global $DB;

        $record = $DB->get_record('local_catquiz_progress', ['attemptid' => $catattemptid]);
        if (!$record) {
            return null;
        }
        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catattemptid]) ?: null;
        $contextid = $catattempt ? (int) $catattempt->contextid : 0;
        // Issue #96: a reader gets no progress rather than a foreign one; operations get an event.
        $reason = progress_integrity::check($record, $catattempt);
        if ($reason !== null) {
            progress_integrity::report($record, $catattempt, $reason, $contextid);
            return null;
        }

        return self::populate_from_object($record, $contextid, false);
    }

    /**
     * Try to load a progress object from the database.
     *
     * @param int|null $catattemptid Id of the CAT attempt - local_catquiz_attempts.id.
     * @param int $contextid
     * @param bool $cache Whether to keep it in the cache - not for a reader (issue #149).
     * @return progress|false
     */
    private static function load_from_db(?int $catattemptid, int $contextid, bool $cache = true) {
        global $DB;

        if ($catattemptid === null) {
            // No CAT attempt, so there cannot be progress belonging to one.
            return false;
        }

        // The attemptid of this table is the CAT attempt, not the attempt of the component.
        $record = $DB->get_record('local_catquiz_progress', ['attemptid' => $catattemptid], '*');

        if (!$record) {
            return false;
        }

        /* Issue #96: progress is taken over only if it belongs to its CAT attempt - same person, same
           component - and the CAT attempt exists. The comparison is against the owner of the CAT
           attempt, not against whoever is looking: a teacher opening a student's result reads that
           student's progress legitimately. A foreign row fails closed: the test stops with a generic
           message, the row is neither used nor overwritten, and operations get an event. Going on
           with a fresh progress instead would have collided with the foreign row on save. */
        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => $catattemptid]) ?: null;
        $reason = progress_integrity::check($record, $catattempt);
        if ($reason !== null) {
            progress_integrity::fail($record, $catattempt, $reason, $contextid);
        }
        return self::populate_from_object($record, $contextid, $cache);
    }

    /**
     * Populates the data from an object.
     *
     * @param stdClass $object
     * @param int $contextid Fallback used for old records saved before contextid was persisted.
     * @param bool $cache Whether to keep it in the cache; a reader does not write the cache (issue #149).
     * @return self
     */
    private static function populate_from_object(stdClass $object, int $contextid, bool $cache = true): self {
        global $DB;
        $instance = new self();
        $instance->id = $object->id;
        $instance->userid = $object->userid;
        $instance->component = $object->component;
        $instance->catattemptid = isset($object->attemptid) ? (int) $object->attemptid : null;

        // The external id is not stored here any more; it comes from the CAT attempt this progress
        // belongs to.
        $instance->attemptid = (int) $DB->get_field(
            'local_catquiz_attempts',
            'attemptid',
            ['id' => $instance->catattemptid]
        );

        // Set properties from json encoded data.
        $data = json_decode($object->json);
        // Issue #177: a head in the format of events gets its lists from them; the code below reads the structure as
        // it was. A progress in a format before is converted by its next save.
        $markers = (int) ($data->progressformat ?? 0) === self::FORMAT_STEPS
            ? self::replay_events($data, (int) $instance->catattemptid)
            : null;
        // Old records saved before contextid was part of the serialized state don't have it.
        $instance->contextid = $data->contextid ?? $contextid;
        $instance->playedquestions = (array) $data->playedquestions;
        foreach ($instance->playedquestions as $pq) {
            if (!$pq->is_pilot) {
                $pq->fisherinformation = (array) $pq->fisherinformation;
            }
        }
        // Issue #143: entries by id point at the very same played question again.
        $instance->playedquestionsbyscale = [];
        foreach ((array) $data->playedquestionsbyscale as $scaleid => $entries) {
            $instance->playedquestionsbyscale[$scaleid] = [];
            foreach ((array) $entries as $entry) {
                $question = self::resolve_played($entry, $instance->playedquestions);
                if ($question !== null) {
                    $instance->playedquestionsbyscale[$scaleid][] = $question;
                }
            }
        }
        $instance->isfirstquestion = $data->isfirstquestion;
        $instance->lastquestion = self::resolve_played($data->lastquestion ?? null, $instance->playedquestions);
        if ($instance->lastquestion !== null && empty($instance->lastquestion->is_pilot)) {
            $instance->lastquestion->fisherinformation = (array) ($instance->lastquestion->fisherinformation ?? []);
        }

        $instance->breakend = $data->breakend;
        $instance->activescales = (array) $data->activescales;
        $instance->droppedscales = property_exists($data, 'droppedscales') ? (array) $data->droppedscales : [];

        // Attempts written before the trace was persisted have no such
        // key. Defaulting to an empty array keeps them loadable - refusing them would
        // break running attempts to fix a display detail.
        $instance->abilitytrace = property_exists($data, 'abilitytrace')
            // PHP 8.2 deprecates 'static::method' as a callable string and a later
            // version removes it. This sits on the abilitytrace load path, so the
            // removal would turn a loadable attempt into a fatal.
            ? array_map([static::class, 'to_array'], (array) $data->abilitytrace)
            : [];
        // Issue #133: progress written before the scale state was traced has none.
        $instance->scalestatetrace = property_exists($data, 'scalestatetrace')
            ? array_values(array_map([static::class, 'to_array'], (array) $data->scalestatetrace))
            : [];
        // Issue #168: a progress written before the history was recorded here has none; its answers so far are in
        // the history of the feedback data.
        $instance->answerhistory = property_exists($data, 'answerhistory')
            ? array_values(array_map(fn($entry) => self::to_array($entry), (array) $data->answerhistory))
            : [];
        foreach ($instance->answerhistory as $i => $entry) {
            $instance->answerhistory[$i]['s'] = self::to_array($entry['s'] ?? []);
        }
        $instance->responses = (array) $data->responses;
        foreach ($instance->responses as $id => $val) {
            $instance->responses[$id] = (array) $val;
        }
        $instance->abilities = (array) $data->abilities;
        $instance->preattemptabilities = property_exists($data, 'preattemptabilities')
            ? (array) $data->preattemptabilities
            : [];
        $instance->forcedbreakend = intval($data->forcedbreakend) ?: null;
        $instance->lockedscales = property_exists($data, 'lockedscales') ? (array) $data->lockedscales : [];
        $instance->usageid = $data->usageid;
        $instance->session = $data->session ?? null;
        $instance->excludedquestions = $data->excludedquestions ?? [];
        $instance->gaveupquestions = $data->gaveupquestions ?? [];
        $instance->laterresponses = array_map(fn($response) => (array) $response, (array) ($data->laterresponses ?? []));
        $instance->recordedsteps = null;
        $instance->starttime = $data->starttime ?? 0;
        if ($markers !== null) {
            $instance->format = self::FORMAT_STEPS;
            $instance->lastseq = $markers['lastseq'];
            $instance->savedshown = $markers['savedshown'];
            $instance->savedresponses = $markers['savedresponses'];
            $instance->savedexcluded = $markers['savedexcluded'];
            $instance->savedgaveup = $markers['savedgaveup'];
            $instance->savedlater = $markers['savedlater'];
            $instance->savedhistory = $markers['savedhistory'];
            $instance->savedscalestates = $markers['savedscalestates'];
            $instance->savedtrace = $markers['savedtrace'];
            $instance->savedestimates = $markers['savedestimates'];
            $instance->saveddebugrows = $markers['saveddebugrows'];
            $instance->debugrows = array_map(fn($row) => (array) $row, (array) ($data->debugrows ?? []));
        }

        // Issue #177: what is stored, so that a save writes the settings only when they changed.
        $instance->savedsettings = isset($object->quizsettings) ? (string) $object->quizsettings : null;
        // Fallback for old attempts that did not store the quizsettings: use the current ones.
        if (!property_exists($object, 'quizsettings') || $object->quizsettings === null) {
            // The CAT attempt of this progress, by its own id - its external number may be another component's.
            $attemptjson = $DB->get_field('local_catquiz_attempts', 'json', ['id' => $instance->catattemptid]);
            $attemptdata = $attemptjson ? json_decode($attemptjson) : null;
            $quizsettings = $attemptdata->quizsettings ?? false;
            // If not even the attempt has quizsettings, get them from the test table.
            if (!$quizsettings) {
                $componentid = $DB->get_record('adaptivequiz_attempt', ['id' => $instance->attemptid], 'instance')->instance;
                $component = $instance->component;
                $data = (object)['componentid' => $componentid, 'component' => $component];
                $testenvironment = new testenvironment($data);
                $quizsettings = $testenvironment->return_settings();
            }
            $object->quizsettings = json_encode($quizsettings);

            /* Save the quiz settings so that in the future we do not have to use the fallback anymore - but only
               for a writer: a reader (issue #149) writes nothing, it gets the settings for this request only. */
            $instance->quizsettings = $quizsettings;
            if ($cache) {
                $instance->save();
            }
        }
        $instance->quizsettings = json_decode($object->quizsettings);

        if ($cache) {
            $instance->save_to_cache();
        }

        return $instance;
    }

    /**
     * The working state of a head in the format of events (issue #177): its events in order, one query, replayed into
     * the lists the code reads - the played questions and those by scale, the responses, the given up and excluded
     * questions, the history and, in trace mode, the traces. What was stored is what is read: nothing is estimated or
     * looked up again, except the label of a played question (D: from the question bank, for the history).
     *
     * @param stdClass $data The head; the lists are set in it, in the structure of the format before.
     * @param int $catattemptid
     * @return array The markers of what is stored: savedexcluded, savedgaveup, savedhistory, savedscalestates,
     *      savedtrace, savedestimates, lastseq.
     */
    private static function replay_events(stdClass $data, int $catattemptid): array {
        global $DB;
        $rows = $DB->get_records(
            'local_catquiz_progress_steps',
            ['catattemptid' => $catattemptid],
            'seq ASC',
            'id, seq, eventtype, itemid, catscaleid, ispilot, paramid, difficulty, information, estimatesdelta, diagnostics'
        );
        $played = [];
        $order = [];
        $responses = [];
        $gaveup = [];
        $later = [];
        $excluded = [];
        $history = [];
        $trace = [];
        $scalestates = [];
        $estimates = [];
        $debugrows = [];
        $lastseq = 0;
        foreach ($rows as $row) {
            $lastseq = (int) $row->seq;
            $diagnostics = $row->diagnostics !== null ? json_decode((string) $row->diagnostics, true) : [];
            $diagnostics = is_array($diagnostics) ? $diagnostics : [];
            $qid = $row->itemid === null ? null : (string) $row->itemid;
            switch ($row->eventtype) {
                case self::EVENT_SHOWN:
                    $pilot = (bool) $row->ispilot;
                    $played[$qid] = (object) [
                        'id' => $qid,
                        'componentid' => $qid,
                        'catscaleid' => $row->catscaleid === null ? null : (string) $row->catscaleid,
                        'is_pilot' => $pilot,
                        'activeparamid' => $row->paramid === null ? null : (string) $row->paramid,
                        'difficulty' => $diagnostics['d'] ?? null,
                        'fisherinformation' => (array) ($diagnostics['fi'] ?? []),
                        'label' => null,
                    ];
                    $order[] = [$qid, array_map('strval', (array) ($diagnostics['sp'] ?? [$row->catscaleid]))];
                    break;
                case self::EVENT_ANSWER:
                    $responses[$qid] = (array) ($diagnostics['r'] ?? []);
                    break;
                case self::EVENT_GAVEUP:
                    $gaveup[] = (int) $qid;
                    if (isset($diagnostics['r'])) {
                        $responses[$qid] = (array) $diagnostics['r'];
                    }
                    break;
                case self::EVENT_LATER:
                    $later[] = (array) ($diagnostics['r'] ?? ['questionid' => $qid]);
                    break;
                case self::EVENT_EXCLUDED:
                    $excluded[] = (int) $qid;
                    break;
            }
            foreach ((array) ($diagnostics['h'] ?? []) as $entry) {
                $entry = (array) $entry;
                $entry['s'] = (array) ($entry['s'] ?? []);
                $history[] = $entry;
            }
            foreach ((array) ($diagnostics['t'] ?? []) as $scaleid => $entries) {
                foreach ((array) $entries as $entry) {
                    $trace[$scaleid][] = (array) $entry;
                }
            }
            foreach ((array) ($diagnostics['ss'] ?? []) as $entry) {
                $scalestates[] = (array) $entry;
            }
            foreach ((array) ($diagnostics['dbg'] ?? []) as $entry) {
                $debugrows[] = (array) $entry;
            }
            if ($row->estimatesdelta !== null) {
                foreach ((array) json_decode((string) $row->estimatesdelta, true) as $scaleid => $estimate) {
                    $estimates[$scaleid] = array_values((array) $estimate);
                }
            }
        }

        // D: the label of a played question is the idnumber of its question bank entry - read for all of them at once.
        if ($played) {
            [$insql, $params] = $DB->get_in_or_equal(array_map('intval', array_keys($played)));
            $labels = $DB->get_records_sql_menu(
                "SELECT qv.questionid, qbe.idnumber
                   FROM {question_versions} qv
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  WHERE qv.questionid $insql",
                $params
            );
            foreach ($played as $qid => $question) {
                $question->label = $labels[$qid] ?? null;
            }
        }
        // The question handed out last is in the head in full; it stands for its played question again, so that both
        // are one object, as they are before a save.
        if (is_object($data->lastquestion ?? null) && isset($data->lastquestion->id)) {
            $lastid = (string) $data->lastquestion->id;
            if (isset($played[$lastid])) {
                $played[$lastid] = $data->lastquestion;
            }
        }
        // The list by scale is the order of adding replayed, as add_playedquestion() builds it: by reference to the
        // played question, as populate_from_object() resolves it.
        $byscale = [];
        foreach ($order as [$qid, $path]) {
            foreach ($path as $scaleid) {
                $byscale[$scaleid][] = $qid;
            }
        }
        $data->playedquestions = (object) $played;
        $data->playedquestionsbyscale = (object) $byscale;
        $data->responses = (object) $responses;
        $data->gaveupquestions = $gaveup;
        $data->laterresponses = $later;
        $data->excludedquestions = $excluded;
        $data->answerhistory = $history;
        $data->abilitytrace = (object) $trace;
        if ($scalestates) {
            $data->scalestatetrace = $scalestates;
        }
        $data->debugrows = $debugrows;
        return [
            'savedshown' => count($order),
            'savedresponses' => count($responses),
            'savedexcluded' => count($excluded),
            'savedgaveup' => count($gaveup),
            'savedlater' => count($later),
            'savedhistory' => count($history),
            'savedscalestates' => count($scalestates),
            'savedtrace' => array_map('count', $trace),
            'savedestimates' => $estimates,
            'saveddebugrows' => count($debugrows),
            'lastseq' => $lastseq,
        ];
    }

    /**
     * The stored progress of a CAT attempt in the structure of the format before #177 - played questions, responses,
     * abilities and the rest, decoded to arrays - for code that reads it without loading a progress (retention,
     * display, reports, privacy export, debug export). A head in the format of events is replayed with its events.
     *
     * @param int $catattemptid
     * @param string|null $json The JSON of the row when the caller has it; null to read it.
     * @return array|null Null without a readable progress.
     */
    public static function stored_structure(int $catattemptid, ?string $json = null): ?array {
        global $DB;
        $json = $json ?? $DB->get_field('local_catquiz_progress', 'json', ['attemptid' => $catattemptid]);
        $data = $json ? json_decode((string) $json) : null;
        if (!$data instanceof stdClass) {
            return null;
        }
        if ((int) ($data->progressformat ?? 0) === self::FORMAT_STEPS) {
            self::replay_events($data, $catattemptid);
            $data->progressformat = self::FORMAT_COMPACT;
            // In the structure before, the list by scale and the last question refer to the played questions by id.
            if (is_object($data->lastquestion ?? null) && isset($data->lastquestion->id)) {
                $data->lastquestion = (string) $data->lastquestion->id;
            }
            unset($data->estimates, $data->counts, $data->lastseq);
        }
        return json_decode(json_encode($data), true);
    }

    /**
     * Whether this progress, taken from the session cache, is the stored working state (issue #177, AK3): another
     * session of the same person may have gone on with the attempt - a second device - and the cache of this one is
     * behind. One read of the head per attempt and request.
     *
     * @return bool
     */
    private function is_current(): bool {
        global $DB;
        if ($this->id === null || $this->catattemptid === null) {
            return true;
        }
        $known = cache::make('local_catquiz', 'progresscurrent')->get((string) $this->catattemptid);
        if ($known !== false && (int) $known === $this->lastseq) {
            return true;
        }
        $stored = $DB->get_field('local_catquiz_progress', 'lastseq', ['id' => $this->id]);
        if ($stored === false) {
            return false;
        }
        if ((int) $stored !== $this->lastseq) {
            return false;
        }
        self::note_current((int) $this->catattemptid, $this->lastseq);
        return true;
    }

    /**
     * Notes for the rest of the request that the working state of a CAT attempt is stored up to this event.
     *
     * @param int $catattemptid
     * @param int $lastseq
     * @return void
     */
    private static function note_current(int $catattemptid, int $lastseq): void {
        cache::make('local_catquiz', 'progresscurrent')->set((string) $catattemptid, $lastseq);
    }

    /**
     * This sets default data for a new instance.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component
     * @param int $contextid
     * @param stdClass $quizsettings
     * @return self
     */
    private static function create_new(int $adaptiveattemptid, string $component, int $contextid, stdClass $quizsettings): self {
        global $DB, $USER;
        $instance = new self();
        $instance->id = null;
        $instance->component = $component;
        $instance->attemptid = $adaptiveattemptid;
        $instance->catattemptid = self::get_cat_attempt_id($adaptiveattemptid, $component);
        // The progress belongs to the person of its CAT attempt, as the CAT attempt itself - also when somebody
        // else is the first to touch it (issue #95). Before, it was filed under that somebody, and the owner's
        // next request was refused as somebody else's progress.
        $owner = $instance->catattemptid !== null
            ? $DB->get_field('local_catquiz_attempts', 'userid', ['id' => $instance->catattemptid])
            : false;
        $instance->userid = $owner !== false ? (int) $owner : $USER->id;
        $instance->contextid = $contextid;

        $instance->playedquestions = [];
        $instance->playedquestionsbyscale = [];
        $instance->isfirstquestion = true;
        $instance->lastquestion = null;
        $instance->breakend = null;
        $instance->activescales = [];
        $instance->droppedscales = [];
        $instance->responses = [];
        $instance->abilities = [];
        $instance->preattemptabilities = [];
        $instance->forcedbreakend = null;
        $instance->lockedscales = [];
        $instance->usageid = null;
        $instance->hasnewresponse = false;
        $instance->session = sesskey();
        $instance->excludedquestions = [];
        $instance->gaveupquestions = [];
        $instance->laterresponses = [];
        $instance->recordedsteps = [];
        $instance->starttime = time();
        $instance->quizsettings = $quizsettings;
        // Issue #177: a new working state is stored as head and events from its first save on.
        $instance->format = self::FORMAT_STEPS;
        return $instance;
    }

    /**
     * Returns a representation of this instance that can be serialized to json
     *
     * This does not include all data, just the ones that will be saved to the
     * 'json' column in the database.
     */
    public function jsonSerialize(): array {
        /* Issue #143: every administered question once. playedquestionsbyscale held the full
           question again for its scale and every ancestor, lastquestion once more - in the JSON
           written on every answer, three to five copies of each question. Both now refer to the
           played questions by id; a question that is not among them (none should be) is still
           written in full, so nothing is lost. */
        $byscale = [];
        foreach ($this->playedquestionsbyscale as $scaleid => $questions) {
            $byscale[$scaleid] = [];
            foreach ($questions as $question) {
                $byscale[$scaleid][] = $this->played_reference($question);
            }
        }
        $data = [
            'progressformat' => self::FORMAT_COMPACT,
            'playedquestions' => $this->playedquestions,
            'playedquestionsbyscale' => $byscale,
            'isfirstquestion' => $this->isfirstquestion,
            'lastquestion' => $this->lastquestion === null ? null : $this->played_reference($this->lastquestion),
            'breakend' => $this->breakend,
            'activescales' => $this->activescales,
            'droppedscales' => $this->droppedscales,
            'contextid' => $this->contextid,
            'responses' => $this->responses,
            'abilities' => $this->abilities,
            'preattemptabilities' => $this->preattemptabilities,
            'forcedbreakend' => $this->forcedbreakend,
            'lockedscales' => $this->lockedscales,
            'usageid' => $this->usageid,
            'session' => $this->session,
            'excludedquestions' => $this->excludedquestions,
            'gaveupquestions' => $this->gaveupquestions,
            'starttime' => $this->starttime,
            // The trace was collected for the whole attempt and then
            // dropped at the end of the request. Everything that reads it - the
            // learning progress chart, the debug view - saw at most the steps of the
            // current page load, which looks like a short attempt rather than a lost
            // history.
            'abilitytrace' => $this->abilitytrace,
        ];
        // Issue #133: outside trace mode the stored JSON stays exactly as it was - not even an empty key.
        if ($this->scalestatetrace) {
            $data['scalestatetrace'] = $this->scalestatetrace;
        }
        // Issue #168: like the trace, the key is only there once there is something in it.
        if ($this->answerhistory) {
            $data['answerhistory'] = $this->answerhistory;
        }
        // Issue #177 (decision 8): later responses only once there is one - the JSON stays as it was otherwise.
        if ($this->laterresponses) {
            $data['laterresponses'] = $this->laterresponses;
        }
        return $data;
    }

    /**
     * A played question as the JSON refers to it: its id when it is among the played questions.
     *
     * @param stdClass $question
     * @return int|string|stdClass
     */
    private function played_reference(stdClass $question) {
        if (isset($question->id) && isset($this->playedquestions[$question->id])) {
            return $question->id;
        }
        return $question;
    }

    /**
     * The played question an entry of the JSON stands for: by id, or written in full (issue #143).
     *
     * Older records hold full copies; those are taken as they are.
     *
     * @param mixed $entry
     * @param array $played Played questions by id.
     * @return stdClass|null
     */
    private static function resolve_played($entry, array $played): ?stdClass {
        if (is_object($entry)) {
            return $entry;
        }
        if ((is_int($entry) || is_string($entry)) && isset($played[$entry])) {
            return $played[$entry];
        }
        return null;
    }

    /**
     * Deletes entries of this instance from the database and cache.
     *
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component Component asking; empty to skip the component check.
     * @return void
     */
    public static function delete(int $adaptiveattemptid, string $component = 'mod_adaptivequiz'): void {
        global $DB;

        $catattemptid = self::get_cat_attempt_id($adaptiveattemptid, $component);

        // Delete cache - under both keys.
        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        $cache->delete(self::get_cache_key(null, $adaptiveattemptid, $component));
        if ($catattemptid !== null) {
            $cache->delete(self::get_cache_key($catattemptid, $adaptiveattemptid, $component));
        }

        if ($catattemptid !== null) {
            // Issue #177: the events with their head.
            $DB->delete_records('local_catquiz_progress_steps', ['catattemptid' => $catattemptid]);
            $DB->delete_records('local_catquiz_progress', ['attemptid' => $catattemptid]);
        }
    }

    /**
     * Saves the object to the cache and DB so that it can be re-used later.
     *
     * Issue #177: the head of fixed size is written, and what happened since the last save is appended as events - both
     * in one transaction, never one without the other. A progress in a format before is converted by its first save
     * here: all its events, then the head (AK5). The save runs under the lock of the item administration of the host;
     * it still refuses to write over a working state that went on in another session (AK3, no lost update).
     *
     * @return void
     */
    public function save(): void {
        global $DB;

        // Issue #159: a progress rebuilt from a finished attempt is never written - neither to the table nor the cache.
        if ($this->historical) {
            return;
        }

        // Save to the DB.
        if ($this->catattemptid === null) {
            /* Without a CAT attempt there is nothing to attach the progress to, and guessing a
               reference is what issue #95 is about. The state stays in memory and in the cache;
               the next save once the CAT model has filed its attempt persists it. */
            $this->save_to_cache();

            return;
        }

        // The first save of an attempt files the head, as before: a parallel request may have filed it (issue #95), and
        // the fallback of an insert that fails cannot run inside a transaction (PostgreSQL aborts it).
        if (!$this->id) {
            $this->insert_head();
        }

        // The row of this attempt, as long as it is still ours (issue #96), and not ahead of this request (#177): checked
        // before anything is written. Under the lock of the item administration nobody writes in between; should
        // somebody, the place of the first event is taken already (unique index) and nothing of this save is stored.
        $stored = $this->refuse_a_foreign_row((int) $this->id);
        if ($this->format === self::FORMAT_STEPS && $stored !== null && (int) $stored->lastseq !== $this->lastseq) {
            cache::make('local_catquiz', 'adaptivequizattempt')
                ->delete(self::get_cache_key($this->catattemptid, (int) $this->attemptid, (string) $this->component));
            throw new progress_conflict_exception((int) $this->catattemptid, $this->lastseq, (int) $stored->lastseq);
        }

        $markers = $this->save_markers();
        $transaction = $DB->start_delegated_transaction();
        try {
            $events = \local_catquiz\local\monitoring\timeline::span(
                'progress:events',
                fn() => $this->format === self::FORMAT_STEPS ? $this->unsaved_events() : $this->all_events()
            );
            if ($this->format !== self::FORMAT_STEPS || $this->freshhead) {
                /* AK5: the conversion writes the whole set of events; whatever an earlier attempt of it left is not
                   part of this working state (its head was never switched). A head filed just now had no head before
                   it: events of the attempt are left over (a cleanup without them, a number given out again after a
                   restore) and would take the places of its first events. */
                $DB->delete_records('local_catquiz_progress_steps', ['catattemptid' => $this->catattemptid]);
                $this->lastseq = 0;
            }
            $now = time();
            foreach ($events as $i => $event) {
                // One structure for all rows of the insert.
                $row = array_fill_keys(self::EVENT_COLUMNS, null);
                foreach ((array) $event as $column => $value) {
                    $row[$column] = $value;
                }
                $row['catattemptid'] = $this->catattemptid;
                $row['seq'] = ++$this->lastseq;
                $row['ispilot'] = (int) ($row['ispilot'] ?? 0);
                $row['timecreated'] = $now;
                $row['formatversion'] = self::EVENT_FORMAT;
                $events[$i] = (object) $row;
            }
            if ($events) {
                $DB->insert_records('local_catquiz_progress_steps', $events);
            }
            $this->format = self::FORMAT_STEPS;
            $record = $this->head_record();
            $record->id = $this->id;
            $DB->update_record('local_catquiz_progress', $record);
            $transaction->allow_commit();
            $this->savedsettings = $record->quizsettings ?? $this->savedsettings;
            $this->freshhead = false;
        } catch (\Throwable $e) {
            // Nothing was stored: what this save would have stored counts as unsaved, so that the next save stores it.
            $this->restore_markers($markers);
            $transaction->rollback($e);
        }

        self::note_current((int) $this->catattemptid, $this->lastseq);
        // Re-audit 27.11.2026: the session cache holds the whole working state - O(N); measured on its own.
        \local_catquiz\local\monitoring\timeline::span('progress:cache', fn() => $this->save_to_cache());
    }

    /**
     * The row of the head as it is written (issue #177): the JSON of fixed size and the last event.
     *
     * @return stdClass
     */
    private function head_record(): stdClass {
        $record = (object) [
            // When this progress was last persisted (issue #122) - for live monitoring.
            'timemodified' => time(),
            'attemptid' => $this->catattemptid,
            'userid' => $this->userid,
            'component' => $this->component,
            // Issue #168 (re-audit of 22.11.2026): the encoding of the progress as a span of its own, for the matrix of costs.
            'json' => \local_catquiz\local\monitoring\timeline::span(
                'progress:json_encode',
                fn() => json_encode($this->head_data())
            ),
            'lastseq' => $this->lastseq,
        ];
        /* The settings go along with a save whenever they are not stored as they are: a progress stored before they were
           kept with it gets them on its next save (populate_from_object()), as intended - an update without them left
           the fallback running on every load (issue #149). Issue #177: not again with every save while they are the
           same - they are the largest part of the head and do not change during an attempt. */
        if (isset($this->quizsettings)) {
            $settings = json_encode($this->quizsettings);
            if ($settings !== $this->savedsettings) {
                $record->quizsettings = $settings;
            }
        }
        return $record;
    }

    /**
     * Files the head of a progress saved for the first time, without events yet.
     */
    private function insert_head(): void {
        global $DB;
        $record = $this->head_record();
        $record->quizsettings = json_encode($this->quizsettings);
        // Until the first events are stored the head names none: a reader sees an empty working state.
        $record->lastseq = 0;
        try {
            $id = $DB->insert_record('local_catquiz_progress', $record);
            $this->freshhead = true;
        } catch (\dml_write_exception $e) {
            // Issue #95: a request in parallel saved the first progress of this attempt - the unique index
            // on attemptid let only one insert through. That row is this attempt's progress; it is updated
            // instead of failing the request with an error page.
            $id = $DB->get_field('local_catquiz_progress', 'id', ['attemptid' => $this->catattemptid]);
            if ($id === false) {
                throw $e;
            }
            $id = (int) $id;
            $stored = $this->refuse_a_foreign_row($id);
            // Issue #177: the other request stored its events already; this one continues from them, not over them.
            if ($stored !== null && (int) $stored->lastseq !== $this->lastseq) {
                cache::make('local_catquiz', 'adaptivequizattempt')
                    ->delete(self::get_cache_key($this->catattemptid, (int) $this->attemptid, (string) $this->component));
                throw new progress_conflict_exception((int) $this->catattemptid, $this->lastseq, (int) $stored->lastseq);
            }
        }
        if (! is_int($id)) {
            throw new \Exception(sprintf("Could not save quiz progress of attempt %d to the database", $this->attemptid));
        }
        $this->id = $id;
        $this->savedsettings = null;
    }

    /**
     * The head as it is stored (issue #177): what does not grow with the number of answers. The played questions, the
     * responses, the excluded and given up questions, the history and the traces are events.
     *
     * @return array
     */
    public function head_data(): array {
        return [
            'progressformat' => self::FORMAT_STEPS,
            'isfirstquestion' => $this->isfirstquestion,
            // The question handed out last, in full: what the next request reads of it (check_break(): the time it was
            // handed out; a reload hands it out again) is not part of its shown event.
            'lastquestion' => $this->lastquestion,
            'breakend' => $this->breakend,
            'activescales' => $this->activescales,
            'droppedscales' => $this->droppedscales,
            'contextid' => $this->contextid,
            'abilities' => $this->abilities,
            'preattemptabilities' => $this->preattemptabilities,
            // C: the estimate of every scale as stored with the last event that changed it.
            'estimates' => $this->savedestimates,
            'forcedbreakend' => $this->forcedbreakend,
            'lockedscales' => $this->lockedscales,
            'usageid' => $this->usageid,
            'session' => $this->session,
            'starttime' => $this->starttime,
            // How much of each list the events hold - the check of the working state compares them (CLI).
            'counts' => [
                'shown' => $this->count_shown(),
                'responses' => count($this->responses),
                'excluded' => count($this->excludedquestions),
                'gaveup' => count($this->gaveupquestions),
                'later' => count($this->laterresponses),
                'history' => count($this->answerhistory),
                'debug' => count($this->debugrows),
            ],
            'lastseq' => $this->lastseq,
        ];
    }

    /**
     * How many times add_playedquestion() was called: the list of the root scale holds each call, the played questions
     * each question once.
     *
     * @return int
     */
    private function count_shown(): int {
        $max = count($this->playedquestions);
        foreach ($this->playedquestionsbyscale as $questions) {
            $max = max($max, count($questions));
        }
        return $max;
    }

    /**
     * What a save changes before it is committed, to be put back when it fails.
     *
     * @return array
     */
    private function save_markers(): array {
        return [
            $this->format, $this->lastseq, $this->unsavedshown, $this->dirtyresponses, $this->savedshown,
            $this->savedresponses, $this->savedexcluded, $this->savedgaveup, $this->savedhistory, $this->savedscalestates,
            $this->savedtrace, $this->savedestimates, $this->saveddebugrows, $this->savedlater,
        ];
    }

    /**
     * Puts back what save_markers() returned.
     *
     * @param array $markers
     * @return void
     */
    private function restore_markers(array $markers): void {
        [
            $this->format, $this->lastseq, $this->unsavedshown, $this->dirtyresponses, $this->savedshown,
            $this->savedresponses, $this->savedexcluded, $this->savedgaveup, $this->savedhistory, $this->savedscalestates,
            $this->savedtrace, $this->savedestimates, $this->saveddebugrows, $this->savedlater,
        ] = $markers;
    }

    /**
     * The events of what happened since the last save (issue #177), in order: questions handed out, responses taken
     * in or changed, questions given up, questions excluded. The entries of the history, the traces and the changed
     * estimates go with the first event of a response, else with the last event; without any event they make one of
     * their own (EVENT_SCALESTATE).
     *
     * @return stdClass[] Without catattemptid, seq, timecreated and formatversion.
     */
    private function unsaved_events(): array {
        $events = [];
        // The questions handed out since the last save: as add_playedquestion() took them; when the lists were set
        // otherwise (a progress of a format before, a test), from the lists themselves.
        $shown = $this->count_shown();
        $new = $shown - $this->savedshown;
        if ($new > 0) {
            $entries = count($this->unsavedshown) === $new ? $this->unsavedshown : $this->shown_from_lists();
            foreach ($entries as $entry) {
                $events[] = self::shown_event($entry);
            }
        }
        $this->unsavedshown = [];
        $this->savedshown = $shown;

        // Given up questions since the last save, in order; a response of the same question takes the first of them.
        $gaveup = array_values(array_slice($this->gaveupquestions, $this->savedgaveup));
        $this->savedgaveup = count($this->gaveupquestions);
        $carrier = null;
        // The responses added since the last save, and those changed (mark_lastquestion_failed()).
        $keys = array_map('strval', array_keys($this->responses));
        $changed = array_fill_keys(array_slice($keys, $this->savedresponses), true) + $this->dirtyresponses;
        foreach ($keys as $qid) {
            if (!isset($changed[$qid])) {
                continue;
            }
            $type = self::EVENT_ANSWER;
            $position = array_search((string) $qid, array_map('strval', $gaveup), true);
            if ($position !== false) {
                $type = self::EVENT_GAVEUP;
                array_splice($gaveup, $position, 1);
            }
            $event = $this->response_event((string) $qid, $type);
            $events[] = $event;
            $carrier = $carrier ?? $event;
        }
        $this->dirtyresponses = [];
        $this->savedresponses = count($keys);
        foreach ($gaveup as $qid) {
            $events[] = (object) ['eventtype' => self::EVENT_GAVEUP, 'itemid' => (int) $qid, 'ispilot' => 0];
        }
        foreach (array_slice($this->laterresponses, $this->savedlater) as $response) {
            $events[] = $this->response_row($response, self::EVENT_LATER);
        }
        $this->savedlater = count($this->laterresponses);
        foreach (array_slice($this->excludedquestions, $this->savedexcluded) as $qid) {
            $events[] = (object) ['eventtype' => self::EVENT_EXCLUDED, 'itemid' => (int) $qid, 'ispilot' => 0];
        }
        $this->savedexcluded = count($this->excludedquestions);

        $this->attach_state($events, $carrier ?? ($events ? end($events) : null), $carrier !== null);
        return $events;
    }

    /**
     * Every event of this working state, for a progress in a format before (AK5): its questions in the order they
     * were handed out, its responses, given up and excluded questions, then the history and the traces.
     *
     * @return stdClass[]
     */
    private function all_events(): array {
        $this->unsavedshown = [];
        $this->dirtyresponses = [];
        $this->savedshown = 0;
        $this->savedresponses = 0;
        $this->savedexcluded = 0;
        $this->savedgaveup = 0;
        $this->savedlater = 0;
        $this->savedhistory = 0;
        $this->savedscalestates = 0;
        $this->savedtrace = [];
        $this->savedestimates = [];
        $this->saveddebugrows = 0;
        return $this->unsaved_events();
    }

    /**
     * Adds to the events what is not an event of its own: the entries of the history and of the traces recorded since
     * the last save, and the scales whose estimate changed.
     *
     * @param array $events The events of this save; a scale state event is appended when one is needed.
     * @param stdClass|null $carrier The event that takes them.
     * @param bool $isresponse Whether the carrier is the event of a response - the estimates belong to it.
     * @return void
     */
    private function attach_state(array &$events, ?stdClass $carrier, bool $isresponse): void {
        $diagnostics = [];
        $history = array_slice($this->answerhistory, $this->savedhistory);
        if ($history) {
            $diagnostics['h'] = array_values($history);
            $this->savedhistory = count($this->answerhistory);
        }
        $trace = [];
        foreach ($this->abilitytrace as $scaleid => $entries) {
            $rest = array_slice(array_values((array) $entries), (int) ($this->savedtrace[$scaleid] ?? 0));
            if ($rest) {
                $trace[$scaleid] = $rest;
            }
            $this->savedtrace[$scaleid] = count((array) $entries);
        }
        if ($trace) {
            $diagnostics['t'] = $trace;
        }
        $scalestates = array_slice($this->scalestatetrace, $this->savedscalestates);
        if ($scalestates) {
            $diagnostics['ss'] = array_values($scalestates);
            $this->savedscalestates = count($this->scalestatetrace);
        }
        $debugrows = array_slice($this->debugrows, $this->saveddebugrows);
        if ($debugrows) {
            $diagnostics['dbg'] = array_values($debugrows);
            $this->saveddebugrows = count($this->debugrows);
        }
        $delta = $this->estimates_delta($isresponse);
        if (!$diagnostics && !$delta) {
            return;
        }
        if ($carrier === null) {
            $carrier = (object) ['eventtype' => self::EVENT_SCALESTATE, 'ispilot' => 0];
            $events[] = $carrier;
        }
        if ($diagnostics) {
            $existing = isset($carrier->diagnostics) ? (array) json_decode($carrier->diagnostics, true) : [];
            $carrier->diagnostics = json_encode($existing + $diagnostics);
        }
        if ($delta) {
            $carrier->estimatesdelta = json_encode($delta);
        }
    }

    /**
     * The scales whose estimate differs from the one last stored (issue #177, C): ability, standard error and number
     * of productive answers, with the precision they have - no rounding, no value that is not finite (a standard error
     * without information is null), no value for a scale that has none.
     *
     * @param bool $counts Whether the numbers of answers are taken anew (after a response; they change only then).
     * @return array Scale id => [ability, standard error or null, n].
     */
    private function estimates_delta(bool $counts): array {
        $delta = [];
        foreach ($this->abilities as $scaleid => $ability) {
            if (!is_numeric($ability) || !is_finite((float) $ability)) {
                continue;
            }
            $se = $this->standarderrors[$scaleid] ?? null;
            $se = is_numeric($se) && is_finite((float) $se) ? (float) $se : null;
            $n = $counts ? $this->get_num_answered_productive_questions((int) $scaleid)
                : (int) ($this->savedestimates[$scaleid][2] ?? $this->get_num_answered_productive_questions((int) $scaleid));
            $estimate = [(float) $ability, $se, $n];
            if (($this->savedestimates[$scaleid] ?? null) !== $estimate) {
                $delta[$scaleid] = $estimate;
                $this->savedestimates[$scaleid] = $estimate;
            }
        }
        return $delta;
    }

    /**
     * What a shown event holds of a question, taken when it is handed out (issue #177, field inventory A): its scale and
     * the path of scales it was filed under, the pilot flag, its active parameters with their difficulty, and its
     * Fisher information for the root scale. Exact values in the diagnostics; the columns are a copy for queries.
     *
     * @param stdClass $question
     * @param array $path The scale and its ancestors as add_playedquestion() filed it.
     * @return array
     */
    private function shown_entry(stdClass $question, array $path): array {
        $root = (int) ($this->quizsettings->catquiz_catscales ?? 0);
        $pilot = !empty($question->is_pilot);
        $information = null;
        // The information of a pilot question is kept as well: it is one number, and a reader decides what counts.
        if (isset($question->fisherinformation)) {
            $fi = (array) $question->fisherinformation;
            $information = isset($fi[$root]) && is_numeric($fi[$root]) ? (float) $fi[$root] : null;
        }
        $difficulty = isset($question->difficulty) && is_numeric($question->difficulty) ? (float) $question->difficulty : null;
        return [
            'itemid' => (int) $question->id,
            'catscaleid' => isset($question->catscaleid) && is_numeric($question->catscaleid) ? (int) $question->catscaleid : null,
            'ispilot' => $pilot ? 1 : 0,
            'paramid' => !empty($question->activeparamid) && is_numeric($question->activeparamid)
                ? (int) $question->activeparamid : null,
            'difficulty' => $difficulty,
            'information' => $information,
            'root' => $root,
            'path' => array_map('intval', $path),
        ];
    }

    /**
     * The shown event of an entry of shown_entry().
     *
     * @param array $shown
     * @return stdClass
     */
    private static function shown_event(array $shown): stdClass {
        $diagnostics = ['sp' => $shown['path']];
        if ($shown['difficulty'] !== null) {
            $diagnostics['d'] = $shown['difficulty'];
        }
        if ($shown['information'] !== null) {
            $diagnostics['fi'] = [$shown['root'] => $shown['information']];
        }
        return (object) [
            'eventtype' => self::EVENT_SHOWN,
            'itemid' => $shown['itemid'],
            'catscaleid' => $shown['catscaleid'],
            'ispilot' => $shown['ispilot'],
            'paramid' => $shown['paramid'],
            'difficulty' => $shown['difficulty'],
            'information' => $shown['information'],
            'diagnostics' => json_encode($diagnostics),
        ];
    }

    /**
     * The event of a response (issue #177): its identities in the question usage as columns, the response as the
     * estimation reads it - without the texts of the usage (D) - in the diagnostics.
     *
     * @param string $qid
     * @param string $type EVENT_ANSWER or EVENT_GAVEUP.
     * @return stdClass
     */
    private function response_event(string $qid, string $type): stdClass {
        return $this->response_row((array) $this->responses[$qid], $type, $qid);
    }

    /**
     * The event of a response as the usage gave it, with its identities of the usage (AK3).
     *
     * @param array $response
     * @param string $type EVENT_ANSWER, EVENT_GAVEUP or EVENT_LATER.
     * @param string|null $qid The question; the one of the response when null.
     * @return stdClass
     */
    private function response_row(array $response, string $type, ?string $qid = null): stdClass {
        $qid = $qid ?? (string) ($response['questionid'] ?? '');
        $question = $this->playedquestions[$qid] ?? null;
        $int = fn($value) => is_numeric($value) ? (int) $value : null;
        $number = fn($value) => is_numeric($value) ? (float) $value : null;
        return (object) [
            'eventtype' => $type,
            'questionusageid' => $int($response['questionusageid'] ?? $this->usageid),
            'slot' => $int($response['slot'] ?? null),
            'questionattemptid' => $int($response['questionattemptid'] ?? null),
            'questionattemptstepid' => $int($response['id'] ?? null),
            'itemid' => (int) $qid,
            'catscaleid' => $question !== null ? $int($question->catscaleid ?? null) : null,
            'ispilot' => $question !== null && !empty($question->is_pilot) ? 1 : 0,
            'paramid' => $question !== null ? $int($question->activeparamid ?? null) : null,
            'fraction' => $number($response['fraction'] ?? null),
            'originalfraction' => $number($response['originalfraction'] ?? null),
            'diagnostics' => json_encode(['r' => array_diff_key($response, array_flip(self::RESPONSE_TEXTS))]),
        ];
    }

    /**
     * The scales a question was filed under when it was added: its scale and its ancestors (add_playedquestion()).
     *
     * @param stdClass $question
     * @return array
     */
    private function path_of(stdClass $question): array {
        return [$question->catscaleid, ...catscale::get_ancestors($question->catscaleid)];
    }

    /**
     * The order in which the questions of a progress in a format before were added (AK5): the list by scale that
     * holds each of them as often as it was added - the root scale -, else the order of the played questions.
     *
     * @return stdClass[]
     */
    private function add_order(): array {
        $ids = array_map('strval', array_keys($this->playedquestions));
        $best = null;
        foreach ($this->playedquestionsbyscale as $questions) {
            $list = array_map(fn($q) => (string) (is_object($q) ? $q->id : $q), (array) $questions);
            if ($best !== null && count($list) <= count($best)) {
                continue;
            }
            $set = array_flip($list);
            $complete = true;
            foreach ($ids as $id) {
                if (!isset($set[$id])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                $best = $list;
            }
        }
        $order = [];
        foreach ($best ?? $ids as $qid) {
            if (isset($this->playedquestions[$qid])) {
                $order[] = $this->playedquestions[$qid];
            }
        }
        return $order;
    }

    /**
     * The shown entries of the questions not stored yet, from the lists (a progress whose lists were not built by
     * add_playedquestion() in this request): the order of adding from the last stored one on, each with the scales
     * whose lists hold it.
     *
     * @return array
     */
    private function shown_from_lists(): array {
        $entries = [];
        $lists = [];
        foreach ($this->playedquestionsbyscale as $scaleid => $questions) {
            foreach ((array) $questions as $entry) {
                $lists[(string) (is_object($entry) ? $entry->id : $entry)][(string) $scaleid] = (int) $scaleid;
            }
        }
        foreach (array_slice($this->add_order(), $this->savedshown) as $question) {
            $member = $lists[(string) $question->id] ?? [];
            // The path as add_playedquestion() files it, restricted to the lists that hold the question; a list that
            // holds it beyond that path (the scales were moved in between) is kept as well.
            $path = [];
            foreach ($this->path_of($question) as $scaleid) {
                if (isset($member[(string) $scaleid])) {
                    $path[] = (int) $scaleid;
                    unset($member[(string) $scaleid]);
                }
            }
            $entries[] = $this->shown_entry($question, array_merge($path, array_values($member)));
        }
        return $entries;
    }

    /**
     * Records the standard error of every scale after the answer, as the selection computed it (issue #177, C) - for
     * the estimates the events keep. Nothing the selection reads.
     *
     * @param array $standarderrors Scale id => standard error.
     * @return self
     */
    public function record_standard_errors(array $standarderrors): self {
        foreach ($standarderrors as $scaleid => $se) {
            $this->standarderrors[$scaleid] = is_numeric($se) ? (float) $se : null;
        }
        return $this;
    }

    /**
     * Adds a row of the debug information (issue #177): stored with the events, not in the column of the attempt.
     *
     * @param array $row
     * @return self
     */
    public function add_debug_row(array $row): self {
        $this->debugrows[] = $row;
        return $this;
    }

    /**
     * The rows of the debug information recorded during the attempt (issue #177).
     *
     * @return array
     */
    public function get_debug_rows(): array {
        return $this->debugrows;
    }

    /**
     * Stops a save that would overwrite a stored progress of somebody else (issue #96, re-audit 27.10.2026).
     *
     * The progress in the session cache is checked against the person when it is loaded, not against the row it is
     * written back to. A row that belongs to another person or component by now - an id met by a foreign row, data
     * moved by hand or by a migration - was overwritten with the state of the session, and its owner set back. Here
     * the stored owner is compared first, with one read by primary key; on a mismatch nothing is written, the cached
     * state is dropped, and the attempt stops with the integrity message, as a load from the database does.
     *
     * Issue #177: the row read is returned - its last event tells whether the stored working state went on without
     * this request.
     *
     * @param int $id local_catquiz_progress.id
     * @return stdClass|null The stored head (id, attemptid, userid, component, lastseq), null without one.
     */
    private function refuse_a_foreign_row(int $id): ?stdClass {
        global $DB;
        $stored = $DB->get_record('local_catquiz_progress', ['id' => $id], 'id, attemptid, userid, component, lastseq');
        if (!$stored) {
            return null;
        }
        $samecomponent = in_array((string) $stored->component, catquiz::component_names((string) $this->component), true);
        if ((int) $stored->userid === (int) $this->userid && $samecomponent) {
            return $stored;
        }
        cache::make('local_catquiz', 'adaptivequizattempt')
            ->delete(self::get_cache_key($this->catattemptid, (int) $this->attemptid, (string) $this->component));
        $catattempt = $DB->get_record('local_catquiz_attempts', ['id' => (int) $stored->attemptid]) ?: null;
        $reason = progress_integrity::check($stored, $catattempt)
            ?? ((int) $stored->userid !== (int) $this->userid
                ? progress_integrity::USER_MISMATCH
                : progress_integrity::COMPONENT_MISMATCH);
        progress_integrity::fail($stored, $catattempt, $reason, (int) $this->contextid);
    }

    /**
     * Writes this progress to the cache.
     */
    private function save_to_cache(): void {
        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        $cache->set(self::get_cache_key($this->catattemptid, (int) $this->attemptid, (string) $this->component), $this);
    }

    /**
     * Moves a progress cached before its CAT attempt existed to the key of the CAT attempt.
     */
    private function move_to_canonical_cache_key(): void {
        if ($this->catattemptid === null) {
            return;
        }
        $cache = cache::make('local_catquiz', 'adaptivequizattempt');
        $prekey = self::get_cache_key(null, (int) $this->attemptid, (string) $this->component);
        if ($cache->get($prekey) !== false) {
            $cache->delete($prekey);
            $this->save_to_cache();
        }
    }

    /**
     * The CAT context the attempt plays in: the one of its scale when the progress was created (issue #157, T10).
     *
     * A running attempt stays in it, also when the scale moves to another context in between - its questions,
     * parameters and estimates are of one calibration. 0 for a progress stored before the context was kept with it.
     *
     * @return int
     */
    public function get_contextid(): int {
        return (int) ($this->contextid ?? 0);
    }

    /**
     * Returns the id of the CAT attempt this progress belongs to.
     *
     * @return int|null
     */
    public function get_catattemptid(): ?int {
        return $this->catattemptid;
    }

    /**
     * Returns the ID.
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Returns the userid.
     *
     * @return int
     */
    public function get_userid() {
        return $this->userid;
    }

    /**
     * Returns the component name.
     *
     * @return string
     */
    public function get_component() {
        return $this->component;
    }

    /**
     * Returns the attempt ID.
     *
     * @return int
     */
    public function get_attemptid() {
        return $this->attemptid;
    }

    /**
     * Returns the responses.
     *
     * @return array
     */
    public function get_responses() {
        return $this->responses;
    }

    /**
     * Returns the questions played in this attempt.
     *
     * @param bool $byscale Return questions per scale.
     * @param ?int $scaleid If given, only return questions from that scale.
     * @return array
     */
    public function get_playedquestions(bool $byscale = false, ?int $scaleid = null) {
        if (! $byscale) {
            return $this->playedquestions;
        }

        if (! $scaleid) {
            return $this->playedquestionsbyscale;
        }

        if (! array_key_exists($scaleid, $this->playedquestionsbyscale)) {
            return [];
        }

        return $this->playedquestionsbyscale[$scaleid];
    }

    /**
     * Returns the number of ANSWERED questions of this attempt, pilot items included.
     *
     * The measure for the test's maximum (issue #141): the maximum limits every question a person
     * is asked, productive or pilot, while the minimum - and every result - counts productive items
     * only ({@see self::get_num_answered_productive_questions()}). Based on the responses, like the
     * productive count, so a pending item after a resume does not count.
     *
     * @return int
     */
    public function get_num_answered_questions(): int {
        return count($this->responses);
    }

    /**
     * Returns the number of ANSWERED productive questions of this attempt.
     *
     * This is the authoritative measure for the configured test length. It is
     * deliberately based on the responses: the played questions only record which
     * items were displayed, and the questionsattempted counter on the adaptivequiz
     * attempt is maintained outside this plugin and can drift across a resume.
     * Pilot items never count towards the productive test length. Passing a scale
     * id restricts the count to the answers attributed to that scale, which is the
     * per-scale N used by the result validator.
     *
     * @param ?int $scaleid Restrict the count to this scale, or null for the whole attempt.
     *
     * @return int
     */
    public function get_num_answered_productive_questions(?int $scaleid = null): int {
        $count = 0;
        $inscale = $scaleid === null ? null : $this->question_ids_of_scale($scaleid);
        foreach (array_keys($this->responses) as $questionid) {
            $question = $this->playedquestions[$questionid] ?? null;
            if ($question !== null && !empty($question->is_pilot)) {
                continue;
            }
            if ($inscale !== null && !isset($inscale[(int) $questionid])) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * Returns the share of points reached on a scale, or null when nothing counts.
     *
     * Same population as get_num_answered_productive_questions(): answered items,
     * pilots excluded. The value is the mean of the per-item fractions, so a scale
     * answered fully correctly gives 1.0 and one answered fully wrongly gives 0.0.
     *
     * Null rather than 0.0 when no item counts - "no productive answer on this scale"
     * and "every answer was wrong" are different statements, and a column that cannot
     * tell them apart is worse than an empty one.
     *
     * @param int|null $scaleid Null counts across all scales.
     * @return float|null
     */
    public function get_fraction_for_scale(?int $scaleid = null): ?float {
        $sum = 0.0;
        $count = 0;
        $inscale = $scaleid === null ? null : $this->question_ids_of_scale($scaleid);

        foreach ($this->responses as $questionid => $response) {
            $question = $this->playedquestions[$questionid] ?? null;
            if ($question !== null && !empty($question->is_pilot)) {
                continue;
            }
            if ($inscale !== null && !isset($inscale[(int) $questionid])) {
                continue;
            }

            $fraction = is_array($response)
                ? ($response['fraction'] ?? null)
                : ($response->fraction ?? null);

            if ($fraction === null) {
                continue;
            }

            // Clamped: a question may award more than its maximum through overrides,
            // and a share above 1 would misrepresent the scale.
            $sum += min(1.0, max(0.0, (float) $fraction));
            $count++;
        }

        return $count > 0 ? $sum / $count : null;
    }

    /**
     * The questions counted towards the given scale, as a set of ids.
     *
     * Performance re-audit of 22.11.2026: the two counts above asked, for every response, whether its question is in
     * the list of the scale - a walk through that list each time, O(N²) per scale. The result check at the end asks
     * for every scale of every feedback area: with 2.368 answers, 14 s of the 20 s of the end of the attempt. Built
     * once per count, the same answer in O(N).
     *
     * @param int $scaleid
     * @return array Question id (int) => true.
     */
    private function question_ids_of_scale(int $scaleid): array {
        $ids = [];
        foreach ($this->playedquestionsbyscale[$scaleid] ?? [] as $question) {
            $ids[(int) ($question->id ?? 0)] = true;
        }
        return $ids;
    }

    /**
     * Returns a clone of the progress class with pilot questions removed
     *
     * @return self
     */
    public function without_pilots() {
        $filteredprogress = clone $this;
        foreach ($filteredprogress->playedquestionsbyscale as $scaleid => $questions) {
            $filteredprogress->playedquestionsbyscale[$scaleid] = array_filter($questions, fn ($q) => !$q->is_pilot);
        }
        $filteredprogress->playedquestions = array_filter($filteredprogress->playedquestions, fn ($q) => !$q->is_pilot);
        return $filteredprogress;
    }

    /**
     * Returns the number of questions played in this attempt.
     *
     * @return int
     */
    public function get_num_playedquestions() {
        return count($this->playedquestions);
    }

    /**
     * Shows if this is the first question in the current attempt.
     *
     * @return bool
     */
    public function is_first_question() {
        return $this->isfirstquestion;
    }

    /**
     * Marks, that the first question was already played.
     *
     * @return $this
     */
    public function set_first_question_played() {
        $this->isfirstquestion = false;
        return $this;
    }

    /**
     * Adds a new question to the array of played questions.
     *
     * @param stdClass $q A question
     * @return self
     */
    public function add_playedquestion(stdClass $q): self {
        $now = time();
        $q->lastattempttime = $now;
        $q->userlastattempttime = $now;

        $this->playedquestions[$q->id] = $q;
        // Keep track of questions played per scale.
        $affectedscales = [
            $q->catscaleid,
            ...catscale::get_ancestors($q->catscaleid),
        ];
        foreach ($affectedscales as $scaleid) {
            if (!array_key_exists($scaleid, $this->playedquestionsbyscale)) {
                $this->playedquestionsbyscale[$scaleid] = [$q];
                continue;
            }
            $this->playedquestionsbyscale[$scaleid][] = $q;
        }
        // Issue #177: stored as a shown event with the next save - what is read of it later, taken now.
        $this->unsavedshown[] = $this->shown_entry($q, $affectedscales);

        $this->lastquestion = $q;

        return $this;
    }

    /**
     * Returns the previous question.
     *
     * @return null|stdClass
     */
    public function get_last_question(): ?stdClass {
        return $this->lastquestion;
    }

    /**
     * Force user to take a break for $duration seconds.
     *
     * @param int $duration
     * @return $this
     */
    public function force_break(int $duration) {
        $now = time();
        $this->breakend = $now + $duration;
        return $this;
    }

    /**
     * Returns the scales that are currently active
     *
     * This is not used by all teststrategies, but some strategies keep a list
     * of scales from which they are returning questions.
     *
     * @return array
     */
    public function get_active_scales() {
        return $this->activescales;
    }

    /**
     * Shows if the given scale is active.
     *
     * @param int $scaleid
     * @return bool
     */
    public function is_active_scale(int $scaleid) {
        return in_array($scaleid, $this->activescales);
    }

    /**
     * Adds the given scale to the list of active scales.
     *
     * @param int $scaleid The scale ID
     * @param bool $force If set to true, also a locked scale will be set to active.
     * @return self
     */
    public function add_active_scale(int $scaleid, bool $force = false) {
        if ($this->is_dropped_scale($scaleid)) {
            // Skip - a dropped scale can not be re-activated.
            return $this;
        }
        if (
            !in_array($scaleid, $this->activescales)
            && (!array_key_exists($scaleid, $this->lockedscales) || $force)
        ) {
            if (array_key_exists($scaleid, $this->lockedscales)) {
                unset($this->lockedscales[$scaleid]);
                $this->trace_scale_state($scaleid, self::SCALE_UNLOCKED);
            }
            $this->activescales[] = $scaleid;
            $this->trace_scale_state($scaleid, self::SCALE_ACTIVATED);
        }
        return $this;
    }

    /**
     * This will mark the given scales as active.
     *
     * @param array $scales
     * @param bool $force If set to true, also a locked scale will be set to active.
     * @return $this
     */
    public function set_active_scales(array $scales, bool $force = false) {
        foreach ($scales as $scaleid) {
            $this->add_active_scale($scaleid, $force);
        }
        return $this;
    }

    /**
     * Deactivates the given scaleid
     *
     * @param int $scaleid
     * @param bool $lock If true, the scale can only be re-activated with the 'force' parameter.
     * @return $this
     */
    public function deactivate_scale(int $scaleid, bool $lock = false) {
        if ($lock && !array_key_exists($scaleid, $this->lockedscales)) {
            $this->lockedscales[$scaleid] = true;
            $this->trace_scale_state($scaleid, self::SCALE_LOCKED);
        }
        if (!in_array($scaleid, $this->activescales)) {
            return $this;
        }
        unset($this->activescales[array_search($scaleid, $this->activescales)]);
        $this->trace_scale_state($scaleid, self::SCALE_DEACTIVATED);
        return $this;
    }

    /**
     * Permanently removes the given scaleid from the list of active scales
     *
     * @param int $scaleid
     * @return $this
     */
    public function drop_scale(int $scaleid) {
        $this->deactivate_scale($scaleid);
        if (!isset($this->droppedscales[$scaleid])) {
            $this->droppedscales[$scaleid] = $scaleid;
            $this->trace_scale_state($scaleid, self::SCALE_REMOVED);
        }
        return $this;
    }

    /**
     * The scales dropped for good in this attempt (issue #172).
     *
     * @return int[]
     */
    public function get_dropped_scales(): array {
        return array_values(array_map('intval', $this->droppedscales));
    }

    /**
     * Shows if the given scale was dropped.
     *
     * In contrast to a deactivated scale, a dropped scale is removed
     * permanently for the current quiz attempt.
     *
     * @param mixed $scaleid
     * @return bool
     */
    public function is_dropped_scale($scaleid) {
        return array_key_exists($scaleid, $this->droppedscales);
    }

    /**
     * Ensures that the scale with the given sclaeid is not locked.
     *
     * @param int $scaleid
     * @return self
     */
    public function unlock_scale($scaleid): self {
        if (array_key_exists($scaleid, $this->lockedscales)) {
            unset($this->lockedscales[$scaleid]);
            $this->trace_scale_state((int) $scaleid, self::SCALE_UNLOCKED);
        }
        return $this;
    }

    /**
     * Records a change of a scale's state, in trace mode only (issue #133).
     *
     * The step is the one of the ability trace: the number of questions played so far.
     *
     * @param int $scaleid
     * @param string $event One of the SCALE_* constants.
     * @return void
     */
    private function trace_scale_state(int $scaleid, string $event): void {
        if (!progress_retention::should_trace()) {
            return;
        }
        $this->scalestatetrace[] = [
            'step' => $this->get_num_playedquestions(),
            'scaleid' => $scaleid,
            'event' => $event,
        ];
    }

    /**
     * The recorded changes of the scales' state: step, scaleid, event. Empty unless in trace mode.
     *
     * @return array
     */
    public function get_scale_state_trace(): array {
        return $this->scalestatetrace;
    }

    /**
     * The state the scales are in after a trace: active, dropped and locked scale ids, sorted.
     *
     * @param array $trace As get_scale_state_trace() returns it.
     * @return array active, dropped, locked
     */
    public static function replay_scale_state_trace(array $trace): array {
        $active = [];
        $dropped = [];
        $locked = [];
        foreach ($trace as $entry) {
            $scaleid = (int) $entry['scaleid'];
            switch ($entry['event']) {
                case self::SCALE_ACTIVATED:
                    $active[$scaleid] = $scaleid;
                    break;
                case self::SCALE_DEACTIVATED:
                    unset($active[$scaleid]);
                    break;
                case self::SCALE_LOCKED:
                    $locked[$scaleid] = $scaleid;
                    break;
                case self::SCALE_UNLOCKED:
                    unset($locked[$scaleid]);
                    break;
                case self::SCALE_REMOVED:
                    unset($active[$scaleid]);
                    $dropped[$scaleid] = $scaleid;
                    break;
            }
        }
        foreach ([&$active, &$dropped, &$locked] as &$ids) {
            $ids = array_values($ids);
            sort($ids);
        }
        unset($ids);
        return ['active' => $active, 'dropped' => $dropped, 'locked' => $locked];
    }

    /**
     * Returns if the given scale is locked.
     *
     * @param int $scaleid
     * @return bool
     */
    public function is_locked(int $scaleid): bool {
        return array_key_exists($scaleid, $this->lockedscales);
    }

    /**
     * Returns the responses in this attempt.
     * @return array
     */
    public function get_user_responses() {
        return $this->responses;
    }

    /**
     * Returns the pilot questions that were shown to the user.
     * @return array
     */
    public function get_played_pilot_questions(): array {
        return array_filter(
            $this->playedquestions,
            fn ($q) => !empty($q->is_pilot)
        );
    }

    /**
     * Returns the abilities calculated during the current attempt.
     *
     * @param bool $rounded Round the abilities
     * @param int $precision Desired precision
     *
     * @return array
     */
    public function get_abilities(bool $rounded = false, int $precision = 2): array {
        if (!$rounded) {
            return $this->abilities;
        }
        $roundedabilities = array_map(fn($ab) => round($ab, $precision), $this->abilities);
        return $roundedabilities;
    }

    /**
     * Sets the ability for the given CAT scale.
     *
     * @param float $ability
     * @param int $catscaleid
     *
     * @return self
     */
    public function set_ability(float $ability, int $catscaleid): self {
        /* Issue #174: a non-finite estimate is no estimate. Stored, it broke the JSON of the whole progress -
           json_encode() fails on INF and NAN, and the next save wrote no progress at all. It is not written: the
           previous value stays, as updatepersonability does it since #106, and a missing one stays missing, which
           the result validator reports as such. */
        if (!is_finite($ability)) {
            return $this;
        }
        // The empty array initialisation used to be pointless - the very
        // next line replaced it with a scalar, so abilities only ever held the last
        // estimate per scale and no trajectory could be reconstructed.
        //
        // In trace mode the estimates are appended instead. The other modes keep the
        // scalar, so nothing about the existing behaviour or the size of the stored
        // JSON changes for them.
        if (progress_retention::should_trace()) {
            if (!isset($this->abilitytrace[$catscaleid])) {
                $this->abilitytrace[$catscaleid] = [];
            }
            $this->abilitytrace[$catscaleid][] = [
                // The number of questions played so far identifies the step. There is
                // no get_step(); calling it broke every running attempt as soon as
                // trace mode was switched on, because set_ability() is on the hot path
                // of the estimation, not in some rarely used branch.
                'step' => $this->get_num_playedquestions(),
                'ability' => $ability,
            ];
        }

        $this->abilities[$catscaleid] = $ability;

        return $this;
    }

    /**
     * Returns the recorded ability trajectory per scale.
     *
     * Empty unless the retention level is trace. Each entry carries the step number
     * and the estimate, so a trajectory can be reconstructed without depending on
     * store_debug_info.
     *
     * @return array
     */
    public function get_ability_trace(): array {
        return $this->abilitytrace;
    }

    /**
     * Records the state after the last answer for the history of the attempt (issue #168).
     *
     * Called by the selection with every answer, before the progress is saved - where the history used to be written
     * into the feedback data. An entry is recorded under the condition the history added a row: there is a last
     * response, its question was played, and not every played question has an entry yet. A question is recorded once.
     *
     * Issue #159 (re-audit of 22.11.2026): an answer the selection never saw gets its entry as well - the answer a reload
     * gave as wrong (catquiz::mark_last_question_failed()) is stored in the progress and the question usage, and the
     * request then redirects without a selection. Before, only the last response was recorded, so such an answer never
     * had a row in the history, nor had it before #168. It is recorded before the last one, in the order of the
     * responses, without an ability: none was estimated between the two answers. Nothing is estimated for it now.
     *
     * @param float|null $rootability The ability of the root scale after the answer (the context of the selection).
     * @param int $precision As the history rounds.
     * @return bool Whether an entry was recorded.
     */
    public function record_answer_state(?float $rootability, int $precision = 2): bool {
        $last = $this->get_last_response();
        if (!is_array($last) || !isset($last['qid']) || !array_key_exists($last['qid'], $this->playedquestions)) {
            return false;
        }
        if (count($this->answerhistory) === count($this->playedquestions)) {
            return false;
        }
        $lastqid = (string) $last['qid'];
        $recorded = [];
        foreach ($this->answerhistory as $entry) {
            $recorded[(string) $entry['q']] = true;
        }
        if (isset($recorded[$lastqid])) {
            return false;
        }
        // Answers before the last one without an entry, in the order of the responses.
        foreach ($this->responses as $qid => $response) {
            $qid = (string) $qid;
            if (
                $qid === $lastqid || isset($recorded[$qid]) || !array_key_exists($qid, $this->playedquestions)
                || !is_array($response) || !isset($response['fraction'])
            ) {
                continue;
            }
            $this->answerhistory[] = ['q' => $qid, 'a' => null, 's' => array_map(fn($value) => null, $this->abilities)];
            $recorded[$qid] = true;
        }
        $this->answerhistory[] = [
            'q' => $lastqid,
            'a' => $rootability === null ? null : round($rootability, $precision),
            's' => array_map(fn($value) => round($value, $precision), $this->abilities),
        ];
        return true;
    }

    /**
     * The state after each answer, as record_answer_state() recorded it (issue #168).
     *
     * @return array
     */
    public function get_answer_history(): array {
        return $this->answerhistory;
    }

    /**
     * Captures the person abilities as they are BEFORE this attempt.
     *
     * Called once at the first question with the loaded priors, before any
     * during-attempt estimate has been written. Only fills scales not captured
     * yet, so repeated calls are safe (issue #9, Phase 2).
     *
     * @param array $abilities Map of catscaleid => ability.
     * @return self
     */
    public function capture_preattempt_abilities(array $abilities): self {
        foreach ($abilities as $catscaleid => $ability) {
            if (!array_key_exists((int) $catscaleid, $this->preattemptabilities)) {
                $this->preattemptabilities[(int) $catscaleid] = (float) $ability;
            }
        }

        return $this;
    }

    /**
     * Returns the person abilities as they were before this attempt, indexed by
     * catscale (issue #9, Phase 2).
     *
     * @return array
     */
    public function get_preattempt_abilities(): array {
        return $this->preattemptabilities;
    }

    /**
     * Returns the end of the user's break.
     *
     * If no break is enforced, returns null.
     *
     * @return ?int
     */
    public function get_forced_break_end(): ?int {
        return $this->forcedbreakend;
    }

    /**
     * Shows if a user just completed a break.
     *
     * @return bool
     */
    public function break_completed(): bool {
        $now = time();

        // User was not in a break.
        if (!$this->forcedbreakend) {
            return false;
        }

        // User did not end the break.
        if ($this->forcedbreakend > $now) {
            return false;
        }

        // Ok: reset breakend to null and indicate the break finished.
        $this->forcedbreakend = null;
        return true;
    }

    /**
     * Shows if the user still has a break.
     *
     * @return bool
     */
    public function has_break(): bool {
        $now = time();
        if ($this->forcedbreakend && $this->forcedbreakend <= $now) {
            $this->forcedbreakend = null;
            return false;
        }
        return true;
    }

    /**
     * Update responses.
     *
     * @return self
     */
    public function update_cached_responses() {
        // No new response - maybe a page reload. Do not change anything.
        if (!($lastresponse = $this->get_last_response_for_attempt())) {
            return $this;
        }

        /* Issue #177 (AK3, re-audit 27.11.2026): a step of the usage is taken in once - a reload, a second submit or a
           request after a crash before the save of the CAT attempt meet the same step again. Known by the step, not by
           the question: the same question can have another slot (the host hands a question named again out once
           more) or a later step of its slot (a manual grade). */
        $step = isset($lastresponse->id) ? (string) $lastresponse->id : null;
        $recorded = $this->recorded_steps();
        if ($step !== null && isset($recorded[$step])) {
            return $this;
        }

        // Decision 8 (21.11.2026): the first processed answer of a question counts; a later one is logged, not counted.
        if (array_key_exists($lastresponse->questionid, $this->responses)) {
            $this->log_later_response(array_diff_key((array) $lastresponse, array_flip(self::RESPONSE_TEXTS)));
            return $this;
        }

        $this->responses[$lastresponse->questionid] = (array) $lastresponse;
        if ($step !== null) {
            $this->recordedsteps[$step] = true;
        }
        // Issue #177: stored as an event with the next save.
        $this->dirtyresponses[(string) $lastresponse->questionid] = true;
        return $this;
    }

    /**
     * Logs a later response to a question that has its answer (decision 8): stored as an event, not counted.
     *
     * @param array $response As the usage gave it, without its texts.
     * @return void
     */
    private function log_later_response(array $response): void {
        $this->laterresponses[] = $response;
        if (isset($response['id'])) {
            $this->recorded_steps();
            $this->recordedsteps[(string) $response['id']] = true;
        }
    }

    /**
     * The steps of the usage taken in, by the responses that count and the later ones (built once per object).
     *
     * @return array<string,bool>
     */
    private function recorded_steps(): array {
        if ($this->recordedsteps === null) {
            $this->recordedsteps = [];
            foreach ([...array_values($this->responses), ...$this->laterresponses] as $response) {
                $response = (array) $response;
                if (isset($response['id'])) {
                    $this->recordedsteps[(string) $response['id']] = true;
                }
            }
        }
        return $this->recordedsteps;
    }

    /**
     * Later responses to questions that have their answer, in order - logged, not counted (decision 8). Each with its
     * identities of the usage (questionusageid, slot, questionattemptid, id of the step), without texts.
     *
     * @return array[]
     */
    public function get_later_responses(): array {
        return $this->laterresponses;
    }

    /**
     * Marks the last question as failed
     *
     * @return $this
     */
    public function mark_lastquestion_failed() {
        /* Decision 8 (re-audit 27.11.2026): a question with its answer that fails when it is handed out again (a reload,
           a technical duplicate of the host) keeps the answer that counts; the failure is logged. Before, the first
           answer was overwritten and counted as wrong. */
        if (array_key_exists($this->lastquestion->id, $this->responses)) {
            $this->log_later_response([
                'questionid' => $this->lastquestion->id,
                'fraction' => 0.0,
                'state' => 'gaveup',
                'questionusageid' => $this->usageid,
                'userlastattempttime' => time(),
            ]);
            return $this;
        }
        $this->responses[$this->lastquestion->id] = array_merge(
            $this->responses[$this->lastquestion->id] ?? [],
            [
                'questionid' => $this->lastquestion->id,
                'fraction' => 0.0,
                'userlastattempttime' => time(),
            ],
        );
        // Issue #177: stored as an event with the next save.
        $this->dirtyresponses[(string) $this->lastquestion->id] = true;
        return $this;
    }

    /**
     * Returns the last response for the current attempt.
     *
     * @return stdClass|bool
     */
    private function get_last_response_for_attempt() {
        /* Deliberately NOT cached. The cache key used to be
           "lastresponse_<usageid>_<numplayedquestions>", which silently assumed
           that the number of played questions is a monotonically growing version
           indicator of the response history. load() breaks that assumption: when
           the last administered question is still unanswered it is removed from
           playedquestions, so the counter goes 2 -> 1 and back to 2 once the item
           IS answered. The second time the key "..._2" is hit, the cache returns
           the OLD response (the one before the pending item), so the freshly given
           answer never reaches the response accumulation. The attempt then keeps
           administering items past the configured maximum.
           This query is small and targeted (one row via LIMIT 1) and runs a few
           dozen times per attempt at most, so correctness clearly outweighs the
           saved lookup. */
        $response = catquiz::get_last_response_for_attempt($this->get_usage_id());
        if ($response && $response->state === 'gaveup') {
            $response->fraction = 0.0;
        }
        return $response;
    }

    /**
     * Show if the last question was answered.
     *
     * @return bool
     */
    private function user_gave_up_last_question(): bool {
        return catquiz::user_gave_up_question($this->get_usage_id(), $this->lastquestion->id);
    }

    /**
     * Returns the last response, if available.
     *
     * Adds the question ID as 'qid' key.
     *
     * @return null|array
     */
    public function get_last_response(): ?array {
        if (!$this->responses) {
            return null;
        }

        $lastresponse = array_slice($this->responses, -1, 1, true);
        $responseid = array_keys($lastresponse)[0];
        $lastresponse[$responseid]['qid'] = $responseid;
        return $lastresponse[$responseid];
    }

    /**
     * Returns the usage id for the current attempt.
     *
     * @return null|int
     */
    public function get_usage_id() {
        if ($usageid = $this->usageid) {
            return $usageid;
        }

        global $DB;
        $this->usageid = $DB->get_record(
            'adaptivequiz_attempt',
            ['id' => $this->attemptid],
            'uniqueid',
            MUST_EXIST
        )->uniqueid;
        return $this->usageid;
    }

    /**
     * Shows if there is a new response.
     *
     * @return bool
     */
    public function has_new_response() {
        return $this->hasnewresponse;
    }

    /**
     * Shows if the current session matches the one used to start the quiz
     *
     * @return bool
     */
    public function check_session() {
        $currentsess = sesskey();
        return $this->session === $currentsess;
    }

    /**
     * Updates the session key of the attempt to the current session.
     * @return self
     * @throws RandomException
     */
    public function set_current_session() {
        $currentsess = sesskey();
        $this->session = $currentsess;
        return $this;
    }

    /**
     * Sets forcenewquestion to true
     *
     * @return self
     */
    public function force_new_question() {
        $this->forcenewquestion = true;
        // Exclude the last question.
        if ($this->lastquestion) {
            $this->exclude_question($this->lastquestion->id);
        }
        return $this;
    }

    /**
     * Show if a new question should be used
     *
     * @return bool
     */
    public function get_force_new_question() {
        return $this->forcenewquestion ?? false;
    }

    /**
     * Updates the list of question IDs that should be ignored.
     *
     * @param int $qid The ID of the question to exclude
     * @return self
     */
    public function exclude_question(int $qid) {
        $this->excludedquestions[] = $qid;
        return $this;
    }

    /**
     * Returns IDs of excluded questions
     *
     * @return array
     */
    public function get_excluded_questions() {
        return $this->excludedquestions;
    }

    /**
     * Shows if the page was reloaded
     *
     * @return bool
     */
    public function page_was_reloaded() {
        if ($this->is_first_question() && !$this->get_last_question()) {
            return false;
        }
        if ($this->has_new_response()) {
            return false;
        }

        return true;
    }

    /**
     * Set the value of ignorelastresponse
     *
     * @see get_ignore_last_response
     * @param bool $val
     * @return $this
     */
    public function set_ignore_last_response(bool $val) {
        $this->ignorelastresponse = $val;
        return $this;
    }

    /**
     * Indicates if the last response should be ignored

     * Can be used by preselect tasks to check if they can skip their calculations.
     *
     * @return bool
     */
    public function get_ignore_last_response() {
        return $this->ignorelastresponse ?? false;
    }

    /**
     * Returns the timestamp of the quiz start.
     *
     * @return int
     */
    public function get_starttime(): int {
        return $this->starttime;
    }

    /**
     * Returns the quiz settings
     *
     * The settings are given as defined at the beginning of the attempt.
     * @return stdClass
     */
    public function get_quiz_settings(): stdClass {
        return $this->quizsettings;
    }

    /**
     * Gets selected subscales
     *
     * @return array
     */
    public function get_selected_subscales() {
        // Get selected subscales from quizdata.
        $selectedsubscales = [];
        foreach ($this->quizsettings as $key => $value) {
            if (
                strpos($key, 'catquiz_subscalecheckbox_') !== false
                && $value == "1"
            ) {
                    $catscaleid = substr_replace($key, '', 0, 25);
                    $selectedsubscales[] = $catscaleid;
            }
        };
        return $selectedsubscales;
    }

    /**
     * Returns the cache key (issue #95).
     *
     * The canonical key is the CAT attempt - one id space, no collision between components. Before the
     * CAT attempt exists the key is the component with its own attempt id: the same number of two
     * components no longer pushes the other out. The cache is per session; whose progress it is, is
     * still checked when an entry is taken.
     *
     * @param int|null $catattemptid Id in local_catquiz_attempts, null while there is none.
     * @param int $adaptiveattemptid Id of the attempt of the component: adaptivequiz_attempt.id - not local_catquiz_attempts.id.
     * @param string $component
     * @return string
     */
    private static function get_cache_key(?int $catattemptid, int $adaptiveattemptid, string $component): string {
        if ($catattemptid !== null) {
            return 'progress_cat_' . $catattemptid;
        }
        return sprintf('progress_pre_%s_%d', preg_replace('/[^a-z0-9_]/', '', strtolower($component)), $adaptiveattemptid);
    }
    /**
     * Casts a decoded json branch back to a nested array.
     *
     * json_decode() returns objects, while the trace is written and read as arrays of
     * arrays. Without the cast the restored value has the right shape but the wrong
     * type, and the first array access on it fails.
     *
     * @param mixed $value
     * @return array
     */
    protected static function to_array($value): array {
        $value = (array) $value;

        foreach ($value as $key => $entry) {
            if (is_object($entry) || is_array($entry)) {
                $value[$key] = (array) $entry;
            }
        }

        return $value;
    }
}
