#!/bin/bash
# Attempt load plan of the re-audit 19.11.2026 (load P0), against a site seeded by seed_attempt_load.php with
# --items=26,150. Every run takes fresh participants; a cold run purges the caches before it starts, the warm run
# after it finds them filled. Per run: the k6 summary (client) and analyse_attempt_load.php (server: traces,
# question usages, integrity, sessions). attempt_load_report.py turns the runs into the report and the verdict.
#
#   run_attempt_load.sh <moodle dir> <out dir> [plan]      plan: full (default) | quick
#
# Environment: what the seed printed (LOAD_BASE_URL, LOAD_USERS, LOAD_PASSWORD, LOAD_CMID_26, LOAD_IDNUMBER_26,
# LOAD_CMID_150, LOAD_IDNUMBER_150); K6 (default: k6 on the PATH).
#
# full:  3 repetitions x (cold, warm) x {1 x 26, 12 x 26, 1 x 150, 12 x 150}, then - only if every run so far is
#        correct - 3 x 25 VUs x 26 (burst).
# quick: 1 repetition x (cold, warm) x {1 x 26, 12 x 26}, then 1 x 25 x 26 under the same condition.
set -euo pipefail
moodle=$1
out=$2
plan=${3:-full}
k6=${K6:-k6}
here=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$out"

if [ "$plan" = quick ]; then
    reps=1
    stages="s1:1:26 s12:12:26"
    bursts=1
else
    reps=3
    stages="s1:1:26 s12:12:26 l1:1:150 l12:12:150"
    bursts=3
fi

declare -A offset=([26]=0 [150]=0)
: > "$out/runs.txt"

run() {
    local name=$1 vus=$2 items=$3 temp=$4
    local cmid idnumber since
    cmid=$(printenv "LOAD_CMID_$items")
    idnumber=$(printenv "LOAD_IDNUMBER_$items")
    if [ "$temp" = cold ]; then
        php "$moodle/admin/cli/purge_caches.php" > /dev/null
    fi
    since=$(date +%s)
    echo "=== $name: $vus VU x $items items, $temp (people from offset ${offset[$items]})"
    set +e
    LOAD_CMID="$cmid" LOAD_ITEMS="$items" VUS="$vus" OFFSET="${offset[$items]}" LOAD_OUT="$out/$name.k6.json" \
        "$k6" run --quiet "$here/catquiz-attempts.js" 2>&1 | tee "$out/$name.k6.log"
    local k6status=${PIPESTATUS[0]}
    set -e
    offset[$items]=$(( ${offset[$items]} + vus ))
    # The server side of exactly this run: its attempts started at or after $since.
    php "$here/analyse_attempt_load.php" --since="$since" --idnumber="$idnumber" > "$out/$name.server.json" \
        || echo '{"error": "analysis failed"}' > "$out/$name.server.json"
    echo "$name $vus $items $temp $k6status" >> "$out/runs.txt"
}

for rep in $(seq 1 "$reps"); do
    for temp in cold warm; do
        for stage in $stages; do
            IFS=: read -r key vus items <<< "$stage"
            run "${key}-${temp}-r${rep}" "$vus" "$items" "$temp"
        done
    done
done

# The burst only after stable correctness.
if python3 "$here/attempt_load_report.py" "$out" --check-only; then
    for rep in $(seq 1 "$bursts"); do
        run "b25-warm-r${rep}" 25 26 warm
    done
else
    echo "Burst left out: a run before it was not correct." | tee "$out/burst-skipped.txt"
fi
python3 "$here/attempt_load_report.py" "$out"
