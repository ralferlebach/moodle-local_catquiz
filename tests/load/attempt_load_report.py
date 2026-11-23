#!/usr/bin/env python3
"""Report and verdict of an attempt load plan (run_attempt_load.sh; re-audit 19.11.2026, load P0).

    attempt_load_report.py <out dir> [--check-only]

Reads runs.txt and per run <name>.k6.json (client, catquiz-attempts.js) and <name>.server.json
(analyse_attempt_load.php). Writes report.md and report.json into the directory and appends the Markdown to
$GITHUB_STEP_SUMMARY. Exit 1 when a run is not correct:
  - an attempt did not reach its result page, an HTTP 5xx, a page with an exception or without its progress, a
    response of attempt.php without the request id of the host, traced requests sharing an id;
  - a question twice in an attempt, a slot processed twice, an attempt with fewer slots than its length;
  - a CAT attempt or progress of another person, more than one of them for an attempt, one without the other;
  - a completed attempt not finalised, or announced other than once;
  - a fatal error in a traced request.
Latency is reported, not judged: the targets are for a comparable environment (audit §5).
--check-only prints nothing but the verdict of the runs so far (used before the burst).
"""
import json
import os
import statistics
import sys


def load(path):
    try:
        return json.load(open(path))
    except (OSError, ValueError):
        return None


def count(metric):
    return int((metric or {}).get('count', 0) or 0)


def problems_of(name, vus, items, k6, server):
    problems = []
    if k6 is None:
        return [f'{name}: no k6 summary']
    if count(k6.get('completed')) != vus:
        problems.append(f'{name}: {count(k6.get("completed"))} of {vus} attempts reached the result page')
    for key, label in (('serverErrors', 'HTTP 5xx'), ('pageExceptions', 'pages with an exception'),
                       ('progressMissing', 'pages without progress'), ('missingRequestIds', 'responses of attempt.php without request id')):
        if count(k6.get(key)):
            problems.append(f'{name}: {count(k6.get(key))} {label}')
    if server is None or 'error' in server:
        return problems + [f'{name}: no server analysis']
    if server.get('attempts') != vus:
        problems.append(f'{name}: {server.get("attempts")} attempts on the server, {vus} expected')
    for usage in server.get('usages', []):
        if usage['duplicatequestions']:
            problems.append(f'{name}: attempt {usage["attempt"]} has a question twice')
        if usage['slotsprocessedtwice']:
            problems.append(f'{name}: attempt {usage["attempt"]} processed a slot twice')
        if usage['state'] == 'complete' and usage['slots'] < items:
            problems.append(f'{name}: attempt {usage["attempt"]} ended after {usage["slots"]} of {items} questions')
    integrity = server.get('integrity', {})
    for key in ('withoutcatattempt', 'morethanonecatattempt', 'catattemptofanother', 'morethanoneprogress',
                'progressofanother', 'progresswithoutcatattempt', 'notfinalised', 'notannouncedonce'):
        if integrity.get(key):
            problems.append(f'{name}: integrity {key} = {integrity[key]}')
    # Host #17: under parallel load every traced request has an id of its own - none shared, none missing.
    if server.get('traces') is not None and server.get('requestids') != server.get('traces'):
        problems.append(f'{name}: {server.get("traces")} traced requests, {server.get("requestids")} distinct request ids')
    for kind, values in server.get('kinds', {}).items():
        if values.get('fatal'):
            problems.append(f'{name}: {values["fatal"]} fatal error(s) in {kind} requests')
    return problems


def p(values, key):
    return (values or {}).get(key)


# The parts of a request (re-audit "ab #147", #161: DB, MUC and lock as sub-spans, #166: the peer comparison on its
# own). The server traces every span with its time and its queries; the report shows the p95 of each, per stage the
# mean over the repetitions.
SUBSPANS = [
    ('host:lock_wait', 'Lock: session/attempt before the request'),
    ('host:answer_lock_wait', 'Lock: answer of the attempt'),
    ('pool:cache_lookup', 'MUC: pool from the cache'),
    ('context:progress', 'DB/MUC: progress of the attempt'),
    ('context:person_ability', 'DB: person parameters'),
    ('context:questions', 'CPU: candidates of the pool'),
    ('strategy:update_personability', 'CPU+DB: ability estimate'),
    ('strategy:add_scale_standarderror', 'CPU: standard error (#163)'),
    ('strategy:fisherinformation', 'CPU: information of the candidates'),
    ('strategy:select_question', 'CPU: selection'),
    ('strategy:progress_save', 'DB+MUC: progress saved'),
    ('feedback:graphicalsummary', 'Feedback per answer: history (#168)'),
    ('feedback:save', 'DB: attempt row (#168)'),
    ('host:quba_save', 'DB: question usage (host)'),
    ('adapter:attempt_finalizer', 'End: finalisation'),
    ('finalizer:peer_comparison', 'End: peer comparison (#166)'),
]
SUBSPANNAMES = [name for name, _ in SUBSPANS]


def row(name, vus, items, temp, k6, server):
    k6 = k6 or {}
    server = server or {}
    nextitem = server.get('kinds', {}).get('next_item', {})
    spans = server.get('spans', {})
    lock = max([p(spans.get(s, {}).get('ms'), 'p95') or 0 for s in ('host:lock_wait', 'host:answer_lock_wait')] or [0])
    answer = k6.get('bykind', {}).get('answer', {})
    subspans = {name: {'ms': p(spans.get(name, {}).get('ms'), 'p95'), 'n': p(spans.get(name, {}).get('ms'), 'n'),
                       'db': p(spans.get(name, {}).get('dbqueries'), 'p95')} for name in SUBSPANNAMES}
    return {
        'run': name, 'vus': vus, 'items': items, 'temp': temp,
        'completed': count(k6.get('completed')), 'answers': count(k6.get('answers')),
        'http5xx': count(k6.get('serverErrors')), 'exceptions': count(k6.get('pageExceptions')),
        'ttfb': {q: p(answer.get('catquiz_ttfb'), q) for q in ('med', 'p(95)', 'p(99)')},
        'roundtrip': {q: p(answer.get('catquiz_roundtrip'), q) for q in ('med', 'p(95)', 'p(99)')},
        'next_item_ms': {q: p(nextitem.get('requestms'), q) for q in ('p50', 'p95', 'p99')},
        'next_item_db_p95': p(nextitem.get('dbqueries'), 'p95'),
        'next_item_peakkb_p95': p(nextitem.get('memorypeakkb'), 'p95'),
        'lock_wait_p95_ms': lock,
        'session_kb_max': p(server.get('sessionkb'), 'max'),
        'cold_first': (server.get('cold') or {}).get('ms'),
        'subspans': subspans,
    }


def fmt(v):
    if v is None:
        return '–'
    return f'{v:.0f}' if isinstance(v, (int, float)) else str(v)


def main():
    out = sys.argv[1]
    checkonly = '--check-only' in sys.argv
    runs = []
    for line in open(os.path.join(out, 'runs.txt')):
        if line.strip():
            name, vus, items, temp, status = line.split()
            runs.append((name, int(vus), int(items), temp, int(status)))
    problems, rows = [], []
    for name, vus, items, temp, status in runs:
        k6 = load(os.path.join(out, name + '.k6.json'))
        server = load(os.path.join(out, name + '.server.json'))
        problems += problems_of(name, vus, items, k6, server)
        rows.append(row(name, vus, items, temp, k6, server))
    if checkonly:
        for problem in problems:
            print(problem)
        return 1 if problems else 0

    # Repetitions of a stage and temperature: mean and standard deviation of the p95 values.
    groups = {}
    for r in rows:
        key = r['run'].rsplit('-r', 1)[0]
        groups.setdefault(key, []).append(r)
    agg = []
    for key, rs in groups.items():
        def series(get):
            vals = [get(r) for r in rs if get(r) is not None]
            if not vals:
                return None, None
            return statistics.mean(vals), (statistics.stdev(vals) if len(vals) > 1 else 0.0)
        agg.append({
            'stage': key, 'runs': len(rs), 'vus': rs[0]['vus'], 'items': rs[0]['items'],
            'ttfb_p95': series(lambda r: r['ttfb']['p(95)']),
            'roundtrip_p95': series(lambda r: r['roundtrip']['p(95)']),
            'next_item_p95': series(lambda r: r['next_item_ms']['p95']),
            'next_item_p99': series(lambda r: r['next_item_ms']['p99']),
            'lock_wait_p95': series(lambda r: r['lock_wait_p95_ms']),
            'subspans': {name: {'ms': series(lambda r, n=name: r['subspans'][n]['ms']),
                                'db': series(lambda r, n=name: r['subspans'][n]['db'])} for name in SUBSPANNAMES},
        })

    md = ['## Attempt load (k6) – re-audit 19.11.2026', '',
          'Client: k6 (`tests/load/catquiz-attempts.js`). Server: traces of the same run '
          '(`analyse_attempt_load.php`). Times in ms; per run the answers (not the start).', '',
          '| Run | VUs × items | Done | Answers | 5xx | Exc. | TTFB p50/p95/p99 | Roundtrip p50/p95/p99 | '
          'next_item p50/p95/p99 | Lock wait p95 | DB queries p95 | Peak KB p95 | Session KB max |',
          '|---|---|---:|---:|---:|---:|---|---|---|---:|---:|---:|---:|']
    for r in rows:
        md.append(
            f'| {r["run"]} | {r["vus"]} × {r["items"]} | {r["completed"]} | {r["answers"]} | {r["http5xx"]} | '
            f'{r["exceptions"]} | {fmt(r["ttfb"]["med"])}/{fmt(r["ttfb"]["p(95)"])}/{fmt(r["ttfb"]["p(99)"])} | '
            f'{fmt(r["roundtrip"]["med"])}/{fmt(r["roundtrip"]["p(95)"])}/{fmt(r["roundtrip"]["p(99)"])} | '
            f'{fmt(r["next_item_ms"]["p50"])}/{fmt(r["next_item_ms"]["p95"])}/{fmt(r["next_item_ms"]["p99"])} | '
            f'{fmt(r["lock_wait_p95_ms"])} | {fmt(r["next_item_db_p95"])} | {fmt(r["next_item_peakkb_p95"])} | '
            f'{fmt(r["session_kb_max"])} |')
    md += ['', '### Repetitions (mean ± standard deviation of the p95 values)', '',
           '| Stage | Runs | TTFB p95 | Roundtrip p95 | next_item p95 | next_item p99 | Lock wait p95 |',
           '|---|---:|---|---|---|---|---|']
    for a in agg:
        cell = lambda t: '–' if t[0] is None else f'{t[0]:.0f} ± {t[1]:.0f}'
        md.append(f'| {a["stage"]} ({a["vus"]} × {a["items"]}) | {a["runs"]} | {cell(a["ttfb_p95"])} | '
                  f'{cell(a["roundtrip_p95"])} | {cell(a["next_item_p95"])} | {cell(a["next_item_p99"])} | '
                  f'{cell(a["lock_wait_p95"])} |')
    md += ['', '### Sub-spans of the requests (p95 ms, mean over the repetitions; DB queries p95 in brackets)', '',
           '| Span | ' + ' | '.join(f'{a["stage"]}' for a in agg) + ' |',
           '|---|' + '---:|' * len(agg)]
    for name, label in SUBSPANS:
        cells = []
        for a in agg:
            ms, db = a['subspans'][name]['ms'], a['subspans'][name]['db']
            cells.append('–' if ms[0] is None else f'{ms[0]:.0f}' + ('' if db[0] is None else f' ({db[0]:.0f})'))
        md.append(f'| {label} (`{name}`) | ' + ' | '.join(cells) + ' |')
    # Performance re-audit of 22.11.2026: next_item by the number of answers, and the SLO decided on 22.11.2026 (from
    # #136): first_item p95 <= 1500 ms; next_item p95 <= 500 ms and p99 <= 1000 ms up to 150 answers and 12 VUs.
    # Longer attempts and more VUs are reported, not checked. A verdict, not a failure of the run: the hardware of a
    # run decides it (a 2-CPU runner serves 12 VUs at a sixth of its speed).
    md += ['', '### next_item by answers (ms, p50/p95/p99/max) and the SLO (decision 22.11.2026)', '',
           '| Run | VUs × items | first_item p95 | 1-10 | 11-50 | 51-100 | 101-150 | SLO |',
           '|---|---|---:|---|---|---|---|---|']
    slo = []
    for name, vus, items, temp, status in runs:
        server = load(os.path.join(out, name + '.server.json'))
        stages = server.get('nextitembyanswers') or {}
        first = ((server.get('kinds') or {}).get('first_item') or {}).get('requestms') or {}
        cell = lambda st: '–' if not st else f'{fmt(st.get("p50"))}/{fmt(st.get("p95"))}/{fmt(st.get("p99"))}/{fmt(st.get("max"))}'
        checked = vus <= 12 and items <= 150
        verdict = '–'
        if checked:
            breaches = [f'{k} p95 {v["p95"]:.0f}' for k, v in stages.items() if k != '151+' and v.get('p95', 0) > 500] \
                + [f'{k} p99 {v["p99"]:.0f}' for k, v in stages.items() if k != '151+' and v.get('p99', 0) > 1000]
            if first.get('p95') is not None and first['p95'] > 1500:
                breaches.insert(0, f'first_item p95 {first["p95"]:.0f}')
            verdict = 'erfüllt' if not breaches else 'nicht erfüllt: ' + ', '.join(breaches)
            slo.append((name, not breaches))
        md.append(f'| {name} | {vus} × {items} | {fmt(first.get("p95"))} | {cell(stages.get("1-10"))} | '
                  f'{cell(stages.get("11-50"))} | {cell(stages.get("51-100"))} | {cell(stages.get("101-150"))} | {verdict} |')
    if os.path.exists(os.path.join(out, 'burst-skipped.txt')):
        md += ['', 'Burst (25 VUs) left out: a run before it was not correct.']
    md += ['', '### Correctness', '']
    md += ['Every run: all attempts at their result page, no 5xx, no exception, every response of attempt.php with its request id, '
           'every traced request with an id of its own; '
           'no question twice, no slot processed twice, no CAT attempt or progress of another person, every '
           'completed attempt finalised and announced exactly once, no fatal error.'] if not problems \
        else [f'- ✘ {x}' for x in problems]
    text = '\n'.join(md) + '\n'
    open(os.path.join(out, 'report.md'), 'w').write(text)
    json.dump({'rows': rows, 'repetitions': agg, 'problems': problems, 'slo': slo},
              open(os.path.join(out, 'report.json'), 'w'), indent=2)
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        open(os.environ['GITHUB_STEP_SUMMARY'], 'a').write(text)
    print(text)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
