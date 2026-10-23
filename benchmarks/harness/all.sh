#!/bin/bash
# On red: the whole round for one tag: load runs, memory, then behaviour checks for every cell.
#   [BUILD=8.4-zts] TAG=2026-10-22-84nts all.sh
set -uo pipefail
F=~/rocketship-field
export BUILD=${BUILD:-8.4-nts} TAG=${TAG:?set TAG} CELLS=${CELLS:-"swoole:plain swoole:apcu roadrunner:plain roadrunner:apcu frankenphp:plain native:plain native:apcu"}
OUT=$F/results/$TAG; mkdir -p $OUT
$F/harness/runner.sh
$F/harness/memory.sh
for c in $CELLS; do
  ssh -F $F/ssh/config server "BUILD=$BUILD ~/rocketship-field/harness/behaviour.sh ${c%%:*} ${c#*:}" >> $OUT/behaviour.jsonl
  echo "[$(date -u +%H:%M:%S)] behaviour $c done" >> $OUT/live.log
done
echo "[$(date -u +%H:%M:%S)] ALL DONE" >> $OUT/live.log
