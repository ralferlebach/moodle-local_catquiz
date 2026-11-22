#!/usr/bin/env bash
# Self-test of await_line_order.sh against local git origins (no network). Exit 0 = all cases as expected.
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
script=$here/await_line_order.sh
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
export AWAIT_SLEEP=1 GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
fails=0

origin() { # origin <name> <version>: a bare origin with branch "line" carrying that version
    git init -q --bare "$work/$1.git"
    git init -q "$work/$1.src"
    git -C "$work/$1.src" checkout -q -b line
    printf '<?php\ndefined("MOODLE_INTERNAL") || die();\n$plugin->version = %s;\n' "$2" > "$work/$1.src/version.php"
    git -C "$work/$1.src" add version.php
    git -C "$work/$1.src" commit -qm "v$2"
    git -C "$work/$1.src" push -q "$work/$1.git" line 2>/dev/null
}
bump() { # bump <name> <version>
    sed -i "s/version = [0-9]*/version = $2/" "$work/$1.src/version.php"
    git -C "$work/$1.src" commit -qam "v$2"
    git -C "$work/$1.src" push -q "$work/$1.git" line 2>/dev/null
}
clone() { rm -rf -- "$work/$1"; git clone -q --depth 1 --branch line "file://$work/$1.git" "$work/$1" 2>/dev/null; }
expect() { # expect <case> <wanted rc> <pattern the output must hold, "" for none> <command...>
    local name=$1 want=$2 pattern=$3; shift 3
    "$@" > "$work/out.log" 2>&1; local rc=$?
    if [ "$rc" -eq "$want" ] && { [ -z "$pattern" ] || grep -q -- "$pattern" "$work/out.log"; }; then echo "OK   $name (rc $rc)"; else echo "FAIL $name (rc $rc, erwartet $want)"; sed 's/^/     /' "$work/out.log"; fails=1; fi
}

origin catquiz12 11; origin catquiz13 11; origin host12 5; origin host3 7
clone catquiz12; clone catquiz13; clone host12; clone host3
expect "1.3 gleich 1.2, kein Push -> rot (Lauf 44 vom 22.11.)" 1 "is not above" env AWAIT_TRIES=2 bash "$script" \
    "$work/catquiz12" "$work/catquiz13" line "$work/host12" "$work/host3" line
expect "festgelegte Staende -> sofort rot" 1 "push the line-1.3" env AWAIT_PINNED=1 AWAIT_TRIES=5 bash "$script" \
    "$work/catquiz12" "$work/catquiz13" line
( sleep 2; bump catquiz13 12 ) &
expect "1.3 kommt waehrend des Wartens -> gruen" 0 "catquiz13 12" env AWAIT_TRIES=10 bash "$script" \
    "$work/catquiz12" "$work/catquiz13" line "$work/host12" "$work/host3" line
wait
expect "1.3 schon ueber 1.2 -> sofort gruen" 0 "host3 7" env AWAIT_TRIES=1 bash "$script" \
    "$work/host12" "$work/host3" line
expect "1.3 unter 1.2 -> rot" 1 "is not above" env AWAIT_TRIES=1 bash "$script" "$work/host3" "$work/host12" line
expect "falsche Argumentzahl -> Aufruffehler" 2 "groups of" bash "$script" "$work/catquiz12" "$work/catquiz13"
exit $fails
