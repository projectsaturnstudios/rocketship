#!/bin/bash
# On red: drive cells on the server and measure them with wrk through nginx.
# Settings come from the environment, so a short run and the full run use the same code:
#   BUILD=8.4-zts CELLS="swoole:plain native-threads:apcu ..." ROUTES="plain json" CONCURRENCY="16 64" REPS=3 DURATION=30 WARMUP=10 TAG=name runner.sh
set -uo pipefail
F=~/rocketship-field
SSH="ssh -F $F/ssh/config server"
CELLS=${CELLS:-"swoole:plain swoole:apcu roadrunner:plain roadrunner:apcu frankenphp:plain native:plain native:apcu"}
ROUTES=${ROUTES:-"plain json view db"}
CONCURRENCY=${CONCURRENCY:-"16 64 256"}
BUILD=${BUILD:-8.4-nts}; REPS=${REPS:-3}; DURATION=${DURATION:-30}; WARMUP=${WARMUP:-10}; TAG=${TAG:-run}
OUT=$F/results/$TAG; mkdir -p $OUT
RUNS=$OUT/runs.jsonl; CELLLOG=$OUT/cells.jsonl; LOG=$OUT/live.log
log() { echo "[$(date -u +%H:%M:%S)] $*" | tee -a $LOG; }
# CPU jiffies from /proc/stat as "busy total": whole machine, so recycled workers, nginx and MySQL all count.
# Fields 2-9 are user..steal (guest time is already inside user); busy leaves out idle, iowait and steal.
cpu_line() { awk '/^cpu /{t=0; for(i=2;i<=9;i++) t+=$i; print t-$5-$6-$9, t}'; }
cpu_json() { # before after -> {"busy_s":…, "share":…}
  set -- $1 $2   # each argument is "busy total"
  awk -v b0=$1 -v t0=$2 -v b1=$3 -v t1=$4 'BEGIN { printf "{\"busy_s\":%.2f,\"share\":%.3f}", (b1-b0)/100, (t1>t0 ? (b1-b0)/(t1-t0) : 0) }'
}

log "start: build=$BUILD cells=[$CELLS] routes=[$ROUTES] c=[$CONCURRENCY] reps=$REPS duration=${DURATION}s warmup=${WARMUP}s"
for rep in $(seq 1 $REPS); do
  for cell in $CELLS; do
    server=${cell%%:*}; apcu=${cell#*:}
    host=octane.field.test; case $server in native*) host=rocketship.field.test ;; esac
    meta=$($SSH "BUILD=$BUILD ~/rocketship-field/harness/cell.sh start $server $apcu")
    echo "{\"rep\":$rep,\"cell\":$meta}" >> $CELLLOG
    if ! echo "$meta" | grep -q '"ready":true'; then log "rep $rep $cell: NOT READY $meta"; $SSH "~/rocketship-field/harness/cell.sh stop" > /dev/null; continue; fi
    $F/wrk/wrk -t2 -c64 -d${WARMUP}s -H "Host: $host" http://10.0.0.90/json > /dev/null 2>&1
    for route in $ROUTES; do
      for c in $CONCURRENCY; do
        # A run with a non-2xx answer or a socket error runs once more; both lines are kept, marked by "attempt".
        for attempt in 1 2; do
          rc0=$(head -1 /proc/stat | cpu_line); sc0=$($SSH "head -1 /proc/stat" | cpu_line)
          res=$($F/wrk/wrk -t2 -c$c -d${DURATION}s --timeout 10s -s $F/harness/report.lua -H "Host: $host" http://10.0.0.90/$route 2>/dev/null | tail -1)
          rc1=$(head -1 /proc/stat | cpu_line); sc1=$($SSH "head -1 /proc/stat" | cpu_line)
          echo "{\"tag\":\"$TAG\",\"rep\":$rep,\"attempt\":$attempt,\"server\":\"$server\",\"apcu\":\"$apcu\",\"route\":\"$route\",\"c\":$c,\"duration\":$DURATION,\"at\":\"$(date -u +%FT%TZ)\",\"red_cpu\":$(cpu_json "$rc0" "$rc1"),\"server_cpu\":$(cpu_json "$sc0" "$sc1"),\"wrk\":${res:-null}}" >> $RUNS
          log "rep $rep $cell /$route c=$c attempt $attempt: $(echo "$res" | grep -oE '"rps":[0-9.]+|"p99_us":[0-9]+|"non2xx":[0-9]+' | tr '\n' ' ')"
          echo "$res" | grep -qE '"non2xx":0,"err_connect":0,"err_read":0,"err_write":0,"err_timeout":0' && break
        done
      done
    done
    stopped=$($SSH "~/rocketship-field/harness/cell.sh stop")
    echo "$stopped" | grep -q '"killed_leftovers":""' || log "rep $rep $cell: LEFTOVERS $stopped"
  done
done
log "DONE"
