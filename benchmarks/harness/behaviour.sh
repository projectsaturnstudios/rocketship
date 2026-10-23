#!/bin/bash
# On the server: start one cell and check how it behaves. Prints one JSON line.
#   [BUILD=8.4-zts] behaviour.sh <swoole|roadrunner|frankenphp|native|native-threads> <apcu|plain>
set -uo pipefail
F=~/rocketship-field; server=$1; apcu=${2:-plain}
$F/harness/cell.sh start $server $apcu > /dev/null
read -r _ app pgid php < $F/cells/current; scan=$(cat $F/cells/scan)
port=8000; case $server in native*) port=8001 ;; esac
url=http://127.0.0.1:$port
art() { (cd $F/$app && PHP_INI_SCAN_DIR=$scan $php artisan "$@" > /dev/null 2>&1); echo $?; }
code() { curl -s -o /dev/null -m ${2:-10} -w '%{http_code}' "$url$1"; }

status_exit=$(art octane:status --server=${server%-threads})
static=$(curl -s -o /dev/null -w '%{http_code} %{content_type}' $url/robots.txt)
head -c 2097152 /dev/urandom > /tmp/rf-upload.bin
upload=$(curl -s -m 10 -F file=@/tmp/rf-upload.bin $url/upload); rm -f /tmp/rf-upload.bin
stream=$(curl -s -m 10 $url/stream | wc -c)

# Reload during load: count what the clients saw.
$F/wrk/wrk -t2 -c16 -d10s --timeout 10s -s $F/harness/report.lua $url/json > /tmp/rf-reload.json 2>/dev/null &
sleep 3; reload_exit=$(art octane:reload --server=${server%-threads}); wait
reload=$(tail -1 /tmp/rf-reload.json); rm -f /tmp/rf-reload.json

# A request past max_execution_time (Octane's default, 30 s), then the next one.
t0=$(date +%s.%N); slow=$(code /slow 60); slow_s=$(echo "$(date +%s.%N) - $t0" | bc)
after_slow=$(code /json 10)

# kill -9 the worker that answered, then time until a request answers again. FrankenPHP and native threads mode run
# their workers as threads inside one process: there is no worker process to kill, so the check does not apply.
victim=null; recovered=null
if [ $server != frankenphp ] && [ $server != native-threads ]; then
  victim=$(curl -s -m 5 $url/pid)
  kill -9 $victim 2>/dev/null; t0=$(date +%s.%N)
  for i in $(seq 1 100); do [ "$(code /json 1)" = 200 ] && { recovered=$(printf "%.3f" "$(echo "$(date +%s.%N) - $t0" | bc)"); break; }; sleep 0.05; done
  victim="\"$victim\""
fi

stopped=$($F/harness/cell.sh stop)
printf '{"server":"%s","apcu":"%s","at":"%s","status_exit":%s,"static":"%s","upload_bytes":"%s","stream_bytes":%s,"reload_exit":%s,"reload_wrk":%s,"killed_pid":%s,"recovered_s":%s,"slow_status":"%s","slow_s":%.1f,"after_slow":"%s","stop":%s}\n' \
  $server $apcu "$(date -u +%FT%TZ)" $status_exit "$static" "$upload" $stream $reload_exit "${reload:-null}" "$victim" $recovered $slow $slow_s $after_slow "$stopped"
