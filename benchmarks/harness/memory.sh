#!/bin/bash
# On red: for each cell, send 100 000 requests to /json at concurrency 64 and record the RSS of every process in the
# cell's session at 0 and once past 10 000, 50 000 and 100 000 requests. Octane's max_requests (500) recycles workers on the
# way, as in production. One JSON line per checkpoint in results/<TAG>/memory.jsonl.
set -uo pipefail
F=~/rocketship-field
SSH="ssh -F $F/ssh/config server"
CELLS=${CELLS:-"swoole:plain swoole:apcu roadrunner:plain roadrunner:apcu frankenphp:plain native:plain native:apcu"}
BUILD=${BUILD:-8.4-nts}; TAG=${TAG:-run}; OUT=$F/results/$TAG; mkdir -p $OUT
log() { echo "[$(date -u +%H:%M:%S)] memory $*" | tee -a $OUT/live.log; }
sample() { # cell checkpoint -> JSON line of every process in the cell's group
  $SSH 'read -r s a g _ < ~/rocketship-field/cells/current; for p in $(pgrep -s $g); do ps -o rss=,comm= -p $p; done' |
    awk -v tag=$TAG -v cell=$1 -v n=$2 -v at=$(date -u +%FT%TZ) '
      { rss += $1; procs = procs sprintf("%s{\"rss_kb\":%d,\"comm\":\"%s\"}", (NR > 1 ? "," : ""), $1, $2) }
      END { split(cell, c, ":"); printf "{\"tag\":\"%s\",\"server\":\"%s\",\"apcu\":\"%s\",\"requests\":%d,\"at\":\"%s\",\"rss_kb\":%d,\"processes\":[%s]}\n", tag, c[1], c[2], n, at, rss, procs }'
}
for cell in $CELLS; do
  server=${cell%%:*}; apcu=${cell#*:}
  host=octane.field.test; case $server in native*) host=rocketship.field.test ;; esac
  $SSH "BUILD=$BUILD ~/rocketship-field/harness/cell.sh start $server $apcu" > /dev/null
  # wrk cannot stop at a request count: run 5 s segments, add up the requests wrk reports, and sample at the first
  # segment that reaches each checkpoint. The sample records the count actually reached.
  total=0
  sample $cell 0 >> $OUT/memory.jsonl
  for n in 10000 50000 100000; do
    while [ $total -lt $n ]; do
      got=$($F/wrk/wrk -t2 -c64 -d5s --timeout 10s -s $F/harness/report.lua -H "Host: $host" http://10.0.0.90/json 2>/dev/null | tail -1 | grep -oE '"requests":[0-9]+' | cut -d: -f2)
      total=$((total + ${got:-0}))
    done
    sample $cell $total >> $OUT/memory.jsonl
  done
  log "$cell: $(tail -4 $OUT/memory.jsonl | grep -oE '"requests":[0-9]+,"at":"[^"]+","rss_kb":[0-9]+' | sed -E 's/"at":"[^"]+",//' | tr '\n' ' ')"
  $SSH "~/rocketship-field/harness/cell.sh stop" > /dev/null
done
log DONE
