#!/bin/bash
# Start or stop one cell on the server, one at a time.
#   [BUILD=8.4-zts] cell.sh start <swoole|roadrunner|frankenphp|native|native-threads> <apcu|plain>   prints one JSON line
#   cell.sh stop
# BUILD names a directory in /opt/rocketship-php (default 8.4-nts, the stock php8.4). `native` runs processes mode,
# `native-threads` threads mode (ZTS builds only).
set -uo pipefail
F=~/rocketship-field; BUILD=${BUILD:-8.4-nts}; B=/opt/rocketship-php/$BUILD; STATE=$F/cells/current
PHP=php8.4; [ -x $B/bin/php ] && PHP=$B/bin/php
mkdir -p $F/cells

stop() {
  [ -f $STATE ] || return 0
  read -r server app pgid php < $STATE
  (cd $F/$app && PHP_INI_SCAN_DIR=$(cat $F/cells/scan) $php artisan octane:stop --server=${server%-threads} > /dev/null 2>&1)
  # The cell's session holds every process it spawned: RoadRunner gives its workers their own process groups.
  for i in $(seq 1 100); do pgrep -s $pgid > /dev/null || break; sleep 0.1; done
  left=$(pgrep -s $pgid | tr '\n' ' ')
  [ -n "$left" ] && kill -9 $left 2>/dev/null
  rm -f $STATE
  echo "{\"stopped\":\"$server\",\"killed_leftovers\":\"${left}\"}"
}

start() {
  server=$1; apcu=$2
  stop > /dev/null
  app=octane-app; port=8000; mode=
  case $server in native) app=rocketship-app; port=8001; mode=processes ;; native-threads) app=rocketship-app; port=8001; mode=threads ;; esac
  scan=$B/conf.d; [ $apcu = apcu ] && scan=$scan:$B/toggles/apcu; [ $server = swoole ] && scan=$scan:$B/toggles/swoole
  echo $scan > $F/cells/scan
  cd $F/$app
  # Native cells call octane:native, which octane:start --server=native forwards to, for its --mode option.
  start=(octane:start --server=$server); [ -n "$mode" ] && start=(octane:native --mode=$mode)
  PHP_INI_SCAN_DIR=$scan setsid nohup $PHP artisan "${start[@]}" --host=127.0.0.1 --port=$port > $F/cells/$BUILD-$server-$apcu.log 2>&1 < /dev/null &
  pgid=$!
  echo "$server $app $pgid $PHP" > $STATE
  for i in $(seq 1 300); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$port/plain)" = 200 ] && break; sleep 0.1
  done
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$port/plain)
  modules=$(PHP_INI_SCAN_DIR=$scan $PHP -m | grep -ixE 'apcu|swoole|epoll|posi|parallel|pcntl|Zend OPcache' | sort -u | tr '\n' ',' | sed 's/,$//')
  [ $server = frankenphp ] && modules="frankenphp-embedded"
  # The mode native resolved, from Octane's server-state file: a threads cell that fell back to processes is caught here.
  [ -n "$mode" ] && mode=$($PHP -r 'echo json_decode(file_get_contents($argv[1]), true)["state"]["mode"] ?? "";' $F/$app/storage/logs/octane-server-state.json)
  procs=$(pgrep -s $pgid | wc -l)
  printf '{"server":"%s","apcu":"%s","build":"%s","mode":"%s","app":"%s","port":%d,"pgid":%d,"processes":%d,"ready":%s,"php":"%s","zts":%s,"modules":"%s","started":"%s"}\n' \
    $server $apcu $BUILD "$mode" $app $port $pgid $procs $([ "$code" = 200 ] && echo true || echo false) "$($PHP -r 'echo PHP_VERSION;')" "$($PHP -r 'echo PHP_ZTS ? "true" : "false";')" "$modules" "$(date -u +%FT%TZ)"
}

case ${1:-} in
  start) start $2 ${3:-plain} ;;
  stop) stop ;;
  *) echo "usage: [BUILD=…] cell.sh start <server> <apcu|plain> | stop" >&2; exit 2 ;;
esac
