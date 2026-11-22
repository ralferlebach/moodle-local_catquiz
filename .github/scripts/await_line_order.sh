#!/usr/bin/env bash
# Waits until each plugin of line 1.3 carries a higher version than its line-1.2 twin (install and upgrade matrix).
#
# Why (re-audit of 22.11.2026): Moodle refuses to go back to a lower version, and with an equal one it skips the
# upgrade steps of the newer line. The upgrade job of the matrix therefore needs line 1.3 above line 1.2. The two lines
# are pushed one after the other; when line 1.2 comes first, its run cloned line 1.3 of the previous delivery and
# failed (run 44 of 22.11.2026: catquiz13 2026111711 not above catquiz12 2026111711). The missing push arrives a few
# minutes later - so the job waits for it instead of failing on the order of two pushes.
#
# Usage: await_line_order.sh <line-1.2 dir> <line-1.3 dir> <line-1.3 branch> [<dir> <dir> <branch> ...]
#   A line-1.3 checkout needs its .git directory and the remote "origin".
# Environment: AWAIT_TRIES (default 30), AWAIT_SLEEP seconds (default 30) - 15 minutes.
#   AWAIT_PINNED=1: the refs were chosen for this run (manual run) - no waiting, a wrong order fails at once.
set -euo pipefail

if [ $(($# % 3)) -ne 0 ] || [ $# -eq 0 ]; then
    echo "::error::await_line_order.sh: expected groups of <line-1.2 dir> <line-1.3 dir> <line-1.3 branch>" >&2
    exit 2
fi
tries=${AWAIT_TRIES:-30}
pause=${AWAIT_SLEEP:-30}
[ "${AWAIT_PINNED:-0}" = 1 ] && tries=1

ver() { grep -o '$plugin->version *= *[0-9]*' "$1/version.php" | grep -o '[0-9]*$'; }

args=("$@")
attempt=1
while :; do
    lagging=()
    for ((i = 0; i < ${#args[@]}; i += 3)); do
        low=${args[i]} high=${args[i+1]}
        lowversion=$(ver "$low")
        highversion=$(ver "$high")
        if [ "$highversion" -gt "$lowversion" ]; then
            echo "$(basename "$low") $lowversion -> $(basename "$high") $highversion"
            continue
        fi
        lagging+=("$i")
        echo "::warning::$(basename "$high") ($highversion) is not above $(basename "$low") ($lowversion) ($attempt/$tries)."
    done
    [ ${#lagging[@]} -eq 0 ] && exit 0
    if [ "$attempt" -ge "$tries" ]; then
        for i in "${lagging[@]}"; do
            echo "::error::$(basename "${args[i+1]}") ($(ver "${args[i+1]}")) is not above $(basename "${args[i]}")" \
                 "($(ver "${args[i]}")): push the line-1.3 branch with a higher version first, then run this again."
        done
        exit 1
    fi
    echo "Waiting ${pause}s for the push of line 1.3."
    sleep "$pause"
    for i in "${lagging[@]}"; do
        git -C "${args[i+1]}" fetch -q --depth 50 origin "${args[i+2]}"
        git -C "${args[i+1]}" checkout -q FETCH_HEAD
        echo "::notice::$(basename "${args[i+1]}") now $(git -C "${args[i+1]}" rev-parse HEAD)"
    done
    attempt=$((attempt + 1))
done
