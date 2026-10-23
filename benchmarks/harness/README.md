# Field-test harness

The scripts that produced every number in [`BENCHMARKS.md`](../../BENCHMARKS.md). They run on two machines in one network: the **server** runs the apps behind nginx, and **red** sends the load. Each machine keeps the harness in `~/rocketship-field/harness/` and its own files in `~/rocketship-field/`; removing that directory removes everything the harness added, except the packages and nginx site the setup steps install.

| File | Runs on | What it does |
|---|---|---|
| `provision.sh` | server, red | Builds epoll and posi (v0.10.6) for the stock PHP 8.4 NTS and writes `/opt/rocketship-php/8.4-nts`: its own `conf.d` (the stock modules minus APCu and Swoole, plus OPcache for the CLI, JIT off, epoll, posi) and two toggles, `toggles/apcu` and `toggles/swoole`. Builds wrk 4.2.0. The stock PHP's files are read, never written. |
| `provision-zts.sh` | server | Builds PHP 8.4.27 ZTS from the php.net tarball into `/opt/rocketship-php/8.4-zts` (no ZTS package exists), with the stock CLI `php.ini` and the modules a Laravel app uses, then epoll, posi, parallel, APCu and Swoole for it. Same layout as the NTS build: `conf.d` plus the `apcu` and `swoole` toggles. |
| `setup-apps.sh` | server | Creates `octane-app` (`laravel/octane`) and `rocketship-app` (this repo as a path repository), with the same `.env`, the routes in `bench.php` and the view `bench.blade.php`; fills MySQL table `items` with 10 000 rows. Reads the MySQL user's password from `FIELD_DB_PASSWORD`. |
| `bench.php`, `bench.blade.php` | server | The routes measured: `/plain`, `/json`, `/view`, `/db`, plus `/slow`, `/stream`, `/upload` and `/pid` for the behaviour checks. Loaded without the `web` middleware, so no session or cookie per request. |
| `nginx.conf` | server | Two sites: `octane.field.test` → 127.0.0.1:8000, `rocketship.field.test` → 127.0.0.1:8001, keep-alive upstreams. |
| `cell.sh` | server | Starts one server (`swoole`, `roadrunner`, `frankenphp`, `native` in processes mode or `native-threads`) on one build (`BUILD`, default `8.4-nts`), with or without the APCu toggle, as the leader of its own session; prints its build, PHP version, ZTS flag, native's resolved mode, modules and process count. `stop` runs `octane:stop` and kills anything left in the session (RoadRunner starts its workers in their own process groups, but in that session). |
| `runner.sh` | red | For each rep and cell: starts the cell, warms it up, runs wrk on every route and concurrency through nginx, records the server's and red's CPU from `/proc/stat` around each run, retries a run with any error once. Appends to `results/<tag>/runs.jsonl` and `cells.jsonl`. |
| `report.lua` | red | wrk script: one JSON line per run (requests, req/s, p50/p90/p99/max latency, non-2xx, socket errors). |
| `memory.sh` | red | For each cell: 100 000 requests to `/json` in 5 s wrk segments, with the RSS of every process in the cell's session at 0 and once past 10 000, 50 000 and 100 000 requests (the count reached is recorded). Appends to `results/<tag>/memory.jsonl`. |
| `all.sh` | red | The whole round for one tag and build: `runner.sh`, `memory.sh`, then `behaviour.sh` for every cell over ssh. |
| `behaviour.sh` | server | For one cell: status, static file, upload, streamed response, reload under load, `kill -9` of a worker, a request past `max_execution_time`, stop. Prints one JSON line. |

Order on fresh machines: `provision.sh` on both; MySQL and the database on the server; `setup-apps.sh`; `nginx.conf` into nginx; red's ssh key into the server's `authorized_keys`, with `~/rocketship-field/ssh/config` naming the server `server`. For a ZTS round, also `provision-zts.sh` on the server, and `BUILD=8.4-zts` with `native-threads` cells on red. Then on red, the whole round (load runs, memory, behaviour) for one tag, about 3 hours:

```bash
BUILD=8.4-nts TAG=2026-10-22-84nts nohup ~/rocketship-field/harness/all.sh > /dev/null 2>&1 &
tail -f ~/rocketship-field/results/2026-10-22-84nts/live.log
```
