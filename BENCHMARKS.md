# Benchmarks

Rocketship's `native` server against Laravel Octane's three servers (Swoole, RoadRunner and FrankenPHP), on PHP 8.4 on a 2 vCPU Graviton2 box, in two rounds: PHP 8.4.27 NTS and PHP 8.4.27 ZTS. Every number here comes from the JSON lines in [`benchmarks/results/`](benchmarks/results/), which the harness in [`benchmarks/harness/`](benchmarks/harness/) wrote. [`benchmarks/report.py`](benchmarks/report.py) prints the tables below from those files and writes [`benchmarks/report.html`](benchmarks/report.html), a self-contained page with charts and filters that opens offline.

In the NTS round native runs in **processes mode**: an NTS PHP has no threads. The [ZTS round](#php-84-zts-round) adds **threads mode** and runs processes mode again on the ZTS build. The sections from [The box](#the-box) to [Where native didn't win](#where-native-didnt-win) describe the NTS round; the ZTS round reuses its box, apps and method. PHP 8.5 is a later round.

## Summary

### NTS round

Native against the best Octane server for each cell (Swoole, in every row), median of three reps:

| | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| Requests/s, `/plain` | **+12.6%** (1,290 vs 1,146) | **+11.6%** (1,251 vs 1,121) | **+12.4%** (1,264 vs 1,125) |
| Requests/s, `/json` | **+11.1%** (1,234 vs 1,111) | **+13.5%** (1,236 vs 1,089) | **+11.7%** (1,221 vs 1,093) |
| Requests/s, `/view` | **+15.4%** (1,142 vs 989) | **+16.8%** (1,135 vs 972) | **+15.4%** (1,123 vs 973) |
| Requests/s, `/db` | **+4.7%** (889 vs 849) | **+8.1%** (912 vs 844) | +2.3% (868 vs 849) |
| Server CPU per request, `/plain` | **−11.3%** (1,552 vs 1,748 µs) | **−10.4%** (1,603 vs 1,789 µs) | **−11.0%** (1,596 vs 1,793 µs) |
| p99 latency, `/plain` | **35 vs 137 ms** | **136 vs 185 ms** | 492 vs 344 ms |

Against FrankenPHP native served 10–22% more requests per second, and against RoadRunner 52–86% more. Native had the most requests per second in all 12 route and concurrency pairs, and used the least server CPU per request in all 12.

All 252 runs answered every request with a 2xx: no errors and no retries. [Where native didn't win](#where-native-didnt-win) covers the numbers that go the other way: above all the tail at 256 connections, where Swoole and FrankenPHP are steadier, and FrankenPHP's tighter p99 at every load.

### ZTS round

Native in each mode against the best Octane server for each cell (Swoole, except FrankenPHP at `/plain` with 64 connections), median of three reps:

| | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| Requests/s, `/plain`, threads | **+16.1%** (1,318 vs 1,135) | **+17.5%** (1,319 vs 1,122) | **+19.9%** (1,323 vs 1,103) |
| Requests/s, `/plain`, processes | **+16.9%** (1,326) | **+13.5%** (1,274) | **+15.8%** (1,277) |
| Requests/s, `/json`, threads | **+18.5%** (1,297 vs 1,095) | **+17.3%** (1,272 vs 1,084) | **+17.6%** (1,275 vs 1,084) |
| Requests/s, `/json`, processes | **+17.6%** (1,287) | **+14.6%** (1,242) | **+13.5%** (1,231) |
| Requests/s, `/view`, threads | **+19.7%** (1,169 vs 977) | **+19.9%** (1,154 vs 963) | **+18.3%** (1,139 vs 962) |
| Requests/s, `/view`, processes | **+16.7%** (1,140) | **+18.0%** (1,137) | **+16.3%** (1,119) |
| Requests/s, `/db`, threads | **+10.1%** (928 vs 843) | **+8.8%** (915 vs 841) | **+9.4%** (905 vs 828) |
| Requests/s, `/db`, processes | **+6.8%** (900) | **+6.0%** (891) | **+6.2%** (879) |
| Server CPU per request, `/plain`, threads | **−14.0%** (1,518 vs 1,765 µs) | **−15.5%** (1,529 vs 1,809 µs) | **−16.6%** (1,523 vs 1,825 µs) |
| Server CPU per request, `/plain`, processes | **−14.5%** (1,508 µs) | **−13.0%** (1,574 µs) | **−13.6%** (1,576 µs) |

Both native modes beat every Octane server on requests per second and server CPU per request in all 12 route and concurrency pairs. Threads mode had the most requests per second and the least CPU per request in 11 of the 12; processes mode in the twelfth (`/plain` at 16 connections). Threads mode ran 9–20% ahead of Swoole, 13–24% ahead of FrankenPHP and 61–98% ahead of RoadRunner, with the smallest memory footprint of any cell (124 MB). All 324 runs answered every request with a 2xx: no errors and no retries. The native threads cells were measured in a second session with a control cell; see [Threads and processes](#threads-and-processes).

## The box

| | Server (`rocketship-server`) | Load (`rocketship-red`) |
|---|---|---|
| Machine | AWS EC2 **c6g.large**: Graviton2 (Arm Neoverse-N1), **2 vCPU**, 3.8 GB RAM | AWS EC2 t4g.medium: Graviton2, 2 vCPU, 3.8 GB RAM |
| OS | Ubuntu 24.04.5 LTS, Linux 7.0.0-1014-aws, aarch64 | same |
| Runs | nginx 1.31.8, MySQL 8.0.46, the server under test | wrk 4.2.0 |
| Network | same VPC and zone (us-east-1a); red reaches the server on its private address, 1 ms TCP connect | |
| Date | 2026-10-22, 19:03–21:16 UTC for the load runs | |

The server was CPU-bound throughout: its machine-wide CPU was 99.4% busy (median over all runs). Red never went above 6.8% busy, so the load generator never limited a run. nginx, MySQL and the PHP server share the server's two cores, as they would on a small production box.

## Software

Two fresh Laravel 13.38.0 apps that differ only in their server package.

| | `octane-app` | `rocketship-app` |
|---|---|---|
| Package | `laravel/octane` 2.22.0 | `projectsaturnstudios/rocketship` at `dce0563` (no `laravel/octane`) |
| Servers | Swoole 6.2.3; RoadRunner `rr` 3.0.1 (`spiral/roadrunner-http` 3.6.0); FrankenPHP 1.13.2 (Caddy 2.11.7) | native |
| Start | `php artisan octane:start --server=… --host=127.0.0.1 --port=8000` | `php artisan octane:start --server=native --host=127.0.0.1 --port=8001` |
| Config | Octane's defaults: workers = CPU count (2), `max_requests` 500, `max_execution_time` 30 s; Swoole's 2 default task workers | same defaults; native has no task workers yet |

Both apps have the same `.env` (`APP_ENV=production`, `APP_DEBUG=false`, MySQL, file cache) and the same cached config, routes and views. The routes ([`bench.php`](benchmarks/harness/bench.php)) load without the `web` middleware, so no session or cookie is written per request:

* **`/plain`**: `ok` as `text/plain`.
* **`/json`**: an array of 20 small objects, 964 bytes.
* **`/view`**: a Blade view with a 50-item loop, 2.5 KB.
* **`/db`**: one MySQL row by a random primary key, from a table of 10,000 rows.

**PHP.** Every cell except FrankenPHP runs the server's stock PHP 8.4.27 NTS (sury) with its own ini directory: the stock modules minus APCu and Swoole, plus OPcache for the CLI (JIT off), epoll 0.10.6 and posi 0.10.6. The "+ APCu" cells add APCu 5.1.28 (`apc.enable_cli=1`); the Swoole cells add Swoole. Each cell recorded the modules it ran with. **FrankenPHP runs the PHP built into its own binary, 8.5.12**, because that binary can't load another PHP. Its rows therefore compare a different PHP version as well as a different server.

nginx proxies both apps with HTTP/1.1 and a pool of 64 keep-alive upstream connections, the same for every cell.

## Method

A cell is one server, with or without APCu. Seven cells: native, native + APCu, Swoole, Swoole + APCu, FrankenPHP, RoadRunner, RoadRunner + APCu.

1. Start the cell with `octane:start`, as the leader of its own session, and wait until `/plain` answers 200. Only one cell runs at a time.
2. Warm up: 10 s of `/json` at 64 connections.
3. For each route and each concurrency (16, 64, 256): `wrk -t2 -c<n> -d30s --timeout 10s` from red, through nginx. Record wrk's requests, req/s and latency percentiles, and the CPU time of both machines from `/proc/stat` before and after. **Server CPU per request** is the server's busy CPU time over the run divided by the requests answered. It's machine-wide, so nginx, MySQL and workers that recycled during the run all count, the same for every cell.
4. Stop the cell with `octane:stop` and kill anything left in its session (nothing ever was).

Three reps, interleaved: rep 1 of all seven cells, then rep 2, then rep 3, so a slow stretch on the box lands on every server. The tables give the median of the three, with the min–max range in brackets. A run with any non-2xx answer or socket error would have been run again; none was.

**Memory.** For each cell: 100,000 requests to `/json` at 64 connections, sending the RSS of every process in the cell's session at 0 requests and after about 10,000, 50,000 and 100,000. Octane's `max_requests` (500) recycles workers as it would in production.

**Behaviour.** For each cell, on the server: `octane:status`; a static file; a 2 MB upload; a 1 MB streamed response; `octane:reload` during 10 s of load, counting what the clients saw; `kill -9` of the worker that answered, then the time until a request is answered again; a request that sleeps past `max_execution_time`, then the next request; `octane:stop` and the processes left.

## Results

### `/plain`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native | 1,290 [1,071–1,305] | 1,251 [1,059–1,278] | 1,264 [1,047–1,283] |
| native + APCu | 1,317 [1,144–1,320] | 1,287 [1,127–1,301] | 1,268 [1,109–1,282] |
| Swoole | 1,146 [1,134–1,153] | 1,121 [1,115–1,130] | 1,125 [1,123–1,132] |
| Swoole + APCu | 1,153 [1,059–1,159] | 1,129 [1,033–1,129] | 1,117 [1,026–1,137] |
| FrankenPHP | 1,108 [906–1,151] | 1,075 [911–1,144] | 1,047 [925–1,076] |
| RoadRunner | 695 [681–716] | 704 [671–719] | 692 [677–716] |
| RoadRunner + APCu | 716 [693–719] | 700 [684–703] | 644 [633–710] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native | 12.1 / 35.2 | 48.3 / 136.3 | 191.6 / 491.9 | 1,603 |
| native + APCu | 12.0 / 33.8 | 47.6 / 129.7 | 193.3 / 467.6 | 1,557 |
| Swoole | 12.9 / 137.3 | 51.1 / 185.3 | 205.4 / 344.5 | 1,789 |
| Swoole + APCu | 13.5 / 137.8 | 50.9 / 187.7 | 206.5 / 351.5 | 1,777 |
| FrankenPHP | 14.2 / 23.0 | 59.3 / 70.9 | 240.5 / 304.8 | 1,847 |
| RoadRunner | 17.3 / 349.4 | 70.0 / 401.0 | 290.4 / 664.2 | 2,841 |
| RoadRunner + APCu | 17.0 / 334.9 | 70.4 / 399.7 | 315.0 / 747.2 | 2,854 |

### `/json`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native | 1,234 [1,040–1,267] | 1,236 [1,028–1,249] | 1,221 [1,041–1,242] |
| native + APCu | 1,243 [1,105–1,247] | 1,248 [1,248–1,262] | 1,232 [1,213–1,250] |
| Swoole | 1,111 [1,028–1,117] | 1,089 [1,084–1,094] | 1,093 [1,091–1,097] |
| Swoole + APCu | 1,105 [973–1,118] | 1,086 [1,049–1,094] | 1,080 [1,024–1,100] |
| FrankenPHP | 1,101 [911–1,111] | 1,038 [883–1,042] | 1,034 [875–1,086] |
| RoadRunner | 671 [666–701] | 695 [566–698] | 700 [659–707] |
| RoadRunner + APCu | 628 [551–694] | 671 [547–673] | 638 [553–686] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native | 13.0 / 38.5 | 50.9 / 134.7 | 201.0 / 504.8 | 1,623 |
| native + APCu | 12.7 / 37.0 | 49.0 / 134.6 | 199.7 / 501.0 | 1,606 |
| Swoole | 13.2 / 137.6 | 52.9 / 193.0 | 211.1 / 352.8 | 1,841 |
| Swoole + APCu | 13.6 / 141.3 | 53.1 / 186.1 | 214.4 / 361.4 | 1,847 |
| FrankenPHP | 14.4 / 22.5 | 61.0 / 76.3 | 244.4 / 278.1 | 1,911 |
| RoadRunner | 18.2 / 291.3 | 72.5 / 198.3 | 298.2 / 659.7 | 2,880 |
| RoadRunner + APCu | 20.9 / 268.4 | 79.2 / 256.4 | 371.8 / 663.5 | 2,970 |

### `/view`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native | 1,142 [1,092–1,154] | 1,135 [1,125–1,136] | 1,123 [1,100–1,131] |
| native + APCu | 1,154 [1,135–1,155] | 1,083 [1,017–1,106] | 1,094 [1,072–1,098] |
| Swoole | 989 [987–996] | 972 [967–977] | 973 [951–977] |
| Swoole + APCu | 918 [917–992] | 973 [954–977] | 977 [963–983] |
| FrankenPHP | 938 [814–984] | 954 [771–956] | 960 [788–991] |
| RoadRunner | 663 [638–669] | 656 [649–668] | 642 [615–668] |
| RoadRunner + APCu | 636 [545–654] | 631 [527–637] | 614 [530–637] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native | 13.7 / 39.8 | 56.0 / 150.9 | 220.6 / 543.0 | 1,769 |
| native + APCu | 13.5 / 39.3 | 56.0 / 153.5 | 225.1 / 561.8 | 1,852 |
| Swoole | 15.8 / 142.2 | 59.6 / 195.5 | 241.8 / 381.7 | 2,064 |
| Swoole + APCu | 16.0 / 152.8 | 59.9 / 195.0 | 239.4 / 387.8 | 2,064 |
| FrankenPHP | 16.7 / 27.3 | 66.5 / 81.1 | 264.6 / 298.5 | 2,082 |
| RoadRunner | 19.1 / 265.3 | 75.5 / 424.2 | 324.5 / 716.5 | 3,034 |
| RoadRunner + APCu | 20.4 / 219.5 | 83.1 / 226.2 | 364.3 / 669.7 | 3,161 |

### `/db`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native | 889 [863–908] | 912 [887–913] | 868 [751–896] |
| native + APCu | 892 [889–896] | 869 [823–887] | 837 [791–886] |
| Swoole | 849 [827–872] | 844 [806–863] | 849 [821–864] |
| Swoole + APCu | 835 [833–863] | 843 [822–848] | 850 [848–862] |
| FrankenPHP | 797 [635–801] | 755 [622–777] | 789 [622–806] |
| RoadRunner | 576 [558–582] | 561 [536–575] | 572 [554–578] |
| RoadRunner + APCu | 521 [451–571] | 516 [459–569] | 527 [437–568] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native | 18.1 / 49.6 | 68.7 / 181.7 | 283.5 / 697.7 | 2,198 |
| native + APCu | 17.2 / 49.0 | 70.7 / 173.2 | 292.0 / 706.8 | 2,307 |
| Swoole | 17.2 / 149.2 | 69.3 / 214.5 | 279.5 / 450.4 | 2,380 |
| Swoole + APCu | 17.8 / 151.6 | 69.2 / 213.5 | 278.0 / 439.4 | 2,379 |
| FrankenPHP | 19.9 / 27.8 | 84.3 / 96.6 | 322.7 / 351.5 | 2,641 |
| RoadRunner | 22.2 / 280.8 | 92.2 / 353.3 | 362.0 / 764.6 | 3,548 |
| RoadRunner + APCu | 25.7 / 219.6 | 100.6 / 495.5 | 392.8 / 828.3 | 3,869 |

### Memory: RSS of every process in the cell (MB)

| | 0 requests | ~10,000 | ~50,000 | ~100,000 | processes |
|---|---:|---:|---:|---:|---:|
| native | 214 | 205 | 276 | 205 | 4 |
| native + APCu | 216 | 205 | 205 | 205 | 4 |
| FrankenPHP | 220 | 205 | 208 | 208 | 2 |
| RoadRunner | 266 | 277 | 277 | 278 | 4 |
| RoadRunner + APCu | 267 | 259 | 278 | 279 | 4 |
| Swoole | 343 | 345 | 345 | 345 | 7 |
| Swoole + APCu | 344 | 346 | 346 | 346 | 7 |

Every count includes the `artisan octane:start` process (74–80 MB). The cells' processes:

* **native**: the start command, the master (52 MB) and two workers (39 MB each).
* **FrankenPHP**: the start command and one `frankenphp` process with its worker threads (129–144 MB).
* **RoadRunner**: the start command, `rr` (44–55 MB) and two PHP workers (73 MB each).
* **Swoole**: the start command, master (67 MB), manager (19 MB), two workers and Octane's two default task workers (43–44 MB each).

Native's 276 MB sample caught both workers reaching `max_requests` at once: six processes, two replacements booting next to the two retiring workers. Fifty thousand requests later it was back at four processes and 205 MB. No cell kept growing after its first 10,000 requests.

### Behaviour

| | status | static file | upload 2 MB | stream 1 MB | reload under load: errors / requests | `kill -9` a worker: answering again in | past `max_execution_time` | left after stop |
|---|---|---|---|---|---|---|---|---|
| native | ok | 200 | ok | ok | 0 / 13,588 | 0.015 s | 408 at 30.1 s, next 200 | none |
| native + APCu | ok | 200 | ok | ok | 0 / 12,686 | 0.017 s | 408 at 30.0 s, next 200 | none |
| Swoole | ok | 200 | ok | ok | 0 / 11,696 | 0.098 s | 408 at 31.2 s, next 200 | none |
| Swoole + APCu | ok | 200 | ok | ok | 0 / 11,918 | 0.094 s | 408 at 31.2 s, next 200 | none |
| RoadRunner | ok | 200 | ok | ok | 0 / 6,901 | 0.341 s | 500 at 30.0 s, next 200 | none |
| RoadRunner + APCu | ok | 200 | ok | ok | 0 / 6,783 | 0.338 s | 500 at 30.0 s, next 200 | none |
| FrankenPHP | ok | 200 | ok | ok | 0 / 4,709 | n/a: workers are threads | 500 at 30.0 s, next 200 | none |

Native answers a request past `max_execution_time` with 408, as Octane's Swoole server does. RoadRunner and FrankenPHP answer 500, as Octane runs them. During a reload FrankenPHP answered every request, but it served 4,709 in the 10 s against 11,700–13,600 for Swoole and native.

## Where native didn't win

Every number that goes the other way, and why where it's known.

* **p99 at 256 connections, every route.** Native's slowest 1% took longer than Swoole's and FrankenPHP's: 492 vs 344 and 305 ms on `/plain`, 698 vs 450 and 352 ms on `/db`. Its p50 at 256 connections was still the lowest of the three on `/plain`, `/json` and `/view`. Native's workers accept their own connections, and on Linux the kernel spreads new connections over the workers' sockets without knowing which worker is busy. Swoole and FrankenPHP hand each request to a free worker from one front. Under deep queues that likely shows up in the tail. This is a hypothesis and hasn't been measured yet.
* **p99 against FrankenPHP at every load.** FrankenPHP has the tightest tail in all 12 route and concurrency pairs: 23 vs 35 ms on `/plain` at 16 connections, 71 vs 136 ms at 64. It runs PHP 8.5.12 here, so part of any difference may be the PHP version.
* **`/db` at 256 connections: +2.3% is not a clear win.** Native's three reps ran 751, 868 and 896 req/s, and Swoole's 821, 849 and 864. The ranges overlap.
* **p50 on `/db`.** At 16 connections Swoole's median latency was lower (17.2 vs 18.1 ms), and at 256 too (279.5 vs 283.5 ms), even though native answered more requests per second in both.
* **Memory against FrankenPHP: a tie.** About 205 MB each. Native used less than RoadRunner (−26%) and Swoole (−41%); part of Swoole's figure is Octane's two task workers, which native doesn't have yet.

## PHP 8.4 ZTS round

Round `2026-10-22-84zts`, load runs 2026-10-22 23:17 to 2026-10-23 02:08 UTC, on the same two machines, apps, routes, nginx and method as the NTS round. The server was 99.4% busy (median over all runs). Red's median was 4.1% busy; one RoadRunner run reached 28.2%, every other run stayed under 7%.

**PHP.** No ZTS package exists for Ubuntu, so [`provision-zts.sh`](benchmarks/harness/provision-zts.sh) builds PHP 8.4.27 ZTS from the php.net tarball into `/opt/rocketship-php/8.4-zts`, with the stock CLI `php.ini` and the modules a Laravel app uses: no imagick, redis, memcached, igbinary, msgpack, intl, gd or pgsql, which the stock NTS PHP loads. OPcache for the CLI (JIT off), epoll 0.10.6, posi 0.10.6 and parallel 1.2.15 load in every cell; APCu 5.1.28 and Swoole 6.2.3, built for this PHP, through the same toggles as before. Every cell but FrankenPHP recorded PHP 8.4.27 with ZTS on; FrankenPHP again runs its own PHP 8.5.12.

**Cells.** Nine: native threads, native threads + APCu, native (processes mode), native + APCu, Swoole, Swoole + APCu, FrankenPHP, RoadRunner, RoadRunner + APCu. The native cells start `octane:native --mode=threads` or `--mode=processes` (what `octane:start --server=native` runs, which has no `--mode` option, as in Octane), and each records the mode native resolved: every threads cell ran in threads mode. The two native threads cells were measured in a second session (2026-10-23 03:19–04:09 UTC, after `0d0cb24`), with native processes run again beside them as a control; [Threads and processes](#threads-and-processes) gives the control's numbers.

**Across rounds.** On the same route at 64 connections the ZTS build moved each server by under 5% against NTS: native processes −2.3% to +1.8%, Swoole −0.9% to −0.3%, RoadRunner −3.7% to +0.4%, FrankenPHP (whose PHP didn't change) −0.9% to +4.5%. ZTS itself costs little here; the gap below is threads mode's own.

### Results

#### `/plain`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native threads | 1,318 [1,289–1,367] | 1,319 [1,257–1,336] | 1,323 [1,270–1,325] |
| native threads + APCu | 1,241 [1,204–1,348] | 1,289 [1,211–1,309] | 1,283 [1,263–1,307] |
| native | 1,326 [1,316–1,331] | 1,274 [1,264–1,282] | 1,277 [1,267–1,305] |
| native + APCu | 1,293 [1,274–1,317] | 1,274 [1,264–1,290] | 1,247 [1,227–1,277] |
| Swoole | 1,135 [1,134–1,136] | 1,111 [1,109–1,121] | 1,103 [1,057–1,119] |
| Swoole + APCu | 1,136 [1,109–1,150] | 1,118 [1,099–1,129] | 1,118 [1,117–1,121] |
| FrankenPHP | 1,124 [1,123–1,177] | 1,122 [1,069–1,131] | 1,094 [1,019–1,123] |
| RoadRunner | 702 [660–708] | 684 [639–705] | 669 [650–705] |
| RoadRunner + APCu | 698 [641–717] | 705 [670–706] | 701 [679–711] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native threads | 12.2 / 33.3 | 46.8 / 124.3 | 187.2 / 463.7 | 1,529 |
| native threads + APCu | 12.4 / 36.1 | 48.1 / 119.9 | 194.2 / 467.1 | 1,558 |
| native | 11.9 / 33.3 | 47.5 / 131.3 | 189.9 / 483.9 | 1,574 |
| native + APCu | 11.9 / 35.1 | 48.3 / 125.4 | 197.4 / 491.6 | 1,574 |
| Swoole | 12.7 / 139.5 | 51.6 / 188.9 | 206.7 / 364.2 | 1,809 |
| Swoole + APCu | 12.6 / 140.4 | 50.8 / 187.7 | 205.9 / 352.2 | 1,793 |
| FrankenPHP | 14.1 / 22.5 | 56.6 / 67.8 | 227.4 / 273.9 | 1,766 |
| RoadRunner | 17.7 / 306.2 | 79.8 / 190.0 | 359.2 / 586.6 | 2,916 |
| RoadRunner + APCu | 18.1 / 277.8 | 72.7 / 339.8 | 298.7 / 677.6 | 2,839 |

#### `/json`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native threads | 1,297 [1,277–1,332] | 1,272 [1,270–1,295] | 1,275 [1,249–1,280] |
| native threads + APCu | 1,242 [1,228–1,299] | 1,243 [1,213–1,267] | 1,233 [1,228–1,234] |
| native | 1,287 [1,254–1,289] | 1,242 [1,223–1,257] | 1,231 [1,206–1,237] |
| native + APCu | 1,246 [1,222–1,248] | 1,243 [1,205–1,251] | 1,217 [1,165–1,220] |
| Swoole | 1,095 [979–1,105] | 1,084 [1,052–1,085] | 1,084 [1,069–1,085] |
| Swoole + APCu | 1,084 [1,080–1,097] | 1,063 [1,061–1,077] | 1,074 [882–1,095] |
| FrankenPHP | 1,050 [1,033–1,106] | 1,065 [1,053–1,090] | 1,077 [1,062–1,090] |
| RoadRunner | 668 [643–708] | 669 [639–705] | 677 [631–684] |
| RoadRunner + APCu | 662 [657–688] | 673 [673–686] | 686 [670–707] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native threads | 12.4 / 33.2 | 48.5 / 116.6 | 191.2 / 482.5 | 1,575 |
| native threads + APCu | 12.7 / 35.1 | 49.9 / 127.0 | 198.4 / 505.5 | 1,613 |
| native | 12.1 / 34.5 | 50.5 / 136.1 | 200.2 / 510.1 | 1,614 |
| native + APCu | 12.4 / 35.6 | 49.3 / 132.7 | 201.5 / 514.2 | 1,614 |
| Swoole | 14.6 / 140.9 | 53.6 / 184.0 | 213.8 / 358.8 | 1,850 |
| Swoole + APCu | 13.7 / 141.2 | 53.6 / 194.5 | 215.0 / 373.4 | 1,891 |
| FrankenPHP | 15.0 / 23.6 | 59.7 / 70.9 | 237.0 / 257.9 | 1,864 |
| RoadRunner | 19.1 / 234.2 | 76.1 / 419.1 | 304.7 / 657.3 | 2,997 |
| RoadRunner + APCu | 19.1 / 214.7 | 78.0 / 302.1 | 296.5 / 635.0 | 2,963 |

#### `/view`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native threads | 1,169 [1,164–1,185] | 1,154 [1,088–1,163] | 1,139 [1,099–1,155] |
| native threads + APCu | 1,145 [1,094–1,178] | 1,142 [1,122–1,144] | 1,133 [1,129–1,139] |
| native | 1,140 [1,135–1,165] | 1,137 [1,119–1,143] | 1,119 [1,118–1,133] |
| native + APCu | 1,104 [1,040–1,147] | 1,122 [1,110–1,125] | 1,116 [1,100–1,129] |
| Swoole | 977 [957–979] | 963 [932–967] | 962 [916–965] |
| Swoole + APCu | 967 [845–984] | 906 [834–979] | 896 [878–977] |
| FrankenPHP | 969 [966–981] | 946 [921–970] | 962 [936–966] |
| RoadRunner | 607 [601–650] | 653 [623–654] | 652 [611–654] |
| RoadRunner + APCu | 642 [636–662] | 625 [596–635] | 621 [580–635] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native threads | 13.4 / 37.0 | 57.0 / 139.6 | 214.5 / 534.7 | 1,737 |
| native threads + APCu | 13.7 / 38.7 | 54.3 / 140.1 | 218.3 / 535.0 | 1,756 |
| native | 13.6 / 38.7 | 54.4 / 153.2 | 216.0 / 547.8 | 1,766 |
| native + APCu | 14.2 / 40.8 | 55.6 / 150.5 | 217.5 / 538.5 | 1,788 |
| Swoole | 15.1 / 142.9 | 60.5 / 194.1 | 243.6 / 395.1 | 2,084 |
| Swoole + APCu | 17.7 / 148.0 | 66.3 / 208.8 | 260.7 / 412.8 | 2,213 |
| FrankenPHP | 16.4 / 25.0 | 66.5 / 86.0 | 262.5 / 301.4 | 2,102 |
| RoadRunner | 21.4 / 326.8 | 78.7 / 355.9 | 380.1 / 609.2 | 3,054 |
| RoadRunner + APCu | 20.2 / 224.0 | 85.1 / 168.4 | 389.0 / 612.8 | 3,201 |

#### `/db`

| Requests/s | 16 connections | 64 connections | 256 connections |
|---|---:|---:|---:|
| native threads | 928 [855–928] | 915 [870–933] | 905 [758–920] |
| native threads + APCu | 911 [881–937] | 902 [853–914] | 875 [857–895] |
| native | 900 [883–905] | 891 [882–892] | 879 [847–885] |
| native + APCu | 908 [884–916] | 899 [888–903] | 885 [864–911] |
| Swoole | 843 [761–853] | 841 [825–844] | 828 [825–847] |
| Swoole + APCu | 838 [752–843] | 816 [761–843] | 830 [759–854] |
| FrankenPHP | 812 [811–829] | 789 [778–809] | 799 [713–823] |
| RoadRunner | 536 [409–585] | 564 [530–576] | 561 [558–586] |
| RoadRunner + APCu | 554 [540–569] | 556 [554–566] | 563 [559–578] |

| p50 / p99 (ms) · CPU per request | 16 | 64 | 256 | CPU µs, 64 |
|---|---:|---:|---:|---:|
| native threads | 17.2 / 47.5 | 67.1 / 170.9 | 279.4 / 673.8 | 2,192 |
| native threads + APCu | 16.7 / 47.4 | 69.5 / 168.9 | 286.6 / 734.1 | 2,224 |
| native | 17.7 / 47.9 | 70.8 / 183.5 | 286.4 / 690.6 | 2,251 |
| native + APCu | 17.3 / 48.1 | 69.8 / 187.1 | 274.7 / 709.6 | 2,229 |
| Swoole | 20.2 / 155.6 | 69.8 / 219.8 | 287.7 / 457.2 | 2,390 |
| Swoole + APCu | 19.7 / 155.6 | 71.4 / 219.1 | 286.0 / 452.2 | 2,454 |
| FrankenPHP | 19.4 / 27.4 | 79.8 / 97.7 | 318.2 / 355.9 | 2,530 |
| RoadRunner | 24.9 / 292.1 | 93.5 / 306.0 | 373.6 / 758.5 | 3,537 |
| RoadRunner + APCu | 24.0 / 300.8 | 93.8 / 345.5 | 388.5 / 719.5 | 3,587 |

### Memory: RSS of every process in the cell (MB)

| | 0 requests | ~10,000 | ~50,000 | ~100,000 | processes |
|---|---:|---:|---:|---:|---:|
| native threads | 127 | 125 | 124 | 124 | 2 |
| native threads + APCu | 127 | 123 | 124 | 127 | 2 |
| native | 179 | 170 | 170 | 170 | 4 |
| native + APCu | 181 | 171 | 203 | 203 | 5 |
| FrankenPHP | 201 | 188 | 189 | 189 | 2 |
| RoadRunner | 222 | 234 | 233 | 233 | 4 |
| RoadRunner + APCu | 224 | 235 | 235 | 235 | 4 |
| Swoole | 313 | 315 | 316 | 315 | 7 |
| Swoole + APCu | 312 | 315 | 315 | 315 | 7 |

Every count includes the `artisan` start process (59–66 MB). Native threads: the start command and one process holding the master and both worker threads (62–67 MB). Native processes: the start command, the master (38 MB) and two workers (35–41 MB). Under this load each native worker reaches `max_requests` about every 0.8 s, so a sample often catches a replacement booting next to a retiring worker; that is the fifth process in the native + APCu rows. A follow-up watch of that cell across 75,000 requests always found four processes once the load stopped. All cells are smaller than in the NTS round, which loads more modules per process.

### Behaviour

| | status | static file | upload 2 MB | stream 1 MB | reload under load: errors / requests | `kill -9` a worker: answering again in | past `max_execution_time` | left after stop |
|---|---|---|---|---|---|---|---|---|
| native threads | ok | 200 | ok | ok | 0 / 13,509 | n/a: workers are threads | 408 at 30.1 s, next 200 | none |
| native threads + APCu | ok | 200 | ok | ok | 0 / 13,571 | n/a: workers are threads | 408 at 30.1 s, next 200 | none |
| native | ok | 200 | ok | ok | 0 / 13,239 | 0.016 s | 408 at 30.1 s, next 200 | none |
| native + APCu | ok | 200 | ok | ok | 0 / 13,571 | 0.013 s | 408 at 30.1 s, next 200 | none |
| Swoole | ok | 200 | ok | ok | 0 / 11,506 | 0.127 s | 408 at 31.2 s, next 200 | none |
| Swoole + APCu | ok | 200 | ok | ok | 0 / 11,959 | 0.125 s | 408 at 30.3 s, next 200 | none |
| RoadRunner | ok | 200 | ok | ok | 0 / 6,637 | 0.015 s | 500 at 30.0 s, next 200 | 4 processes, killed |
| RoadRunner + APCu | ok | 200 | ok | ok | 0 / 7,079 | 0.321 s | 500 at 30.0 s, next 200 | none |
| FrankenPHP | ok | 200 | ok | ok | 0 / 4,411 | n/a: workers are threads | 500 at 30.1 s, next 200 | none |

In threads mode a request past `max_execution_time` gets 408 and its slot a fresh thread, as a timed-out Swoole worker is replaced; the next request answers. `kill -9` doesn't apply: the workers are threads in the master's process, as FrankenPHP's are. One RoadRunner cell left four processes 10 s after `octane:stop`; the harness killed them.

### Threads and processes

The threads cells ran in a later session than the rest of the round, so native processes ran again beside them as a control. The control came in 4.8% below to 1.6% above its own numbers from the main session (1.5% below on average): the box was, if anything, a little slower for the threads cells. Comparisons between the threads rows and the other rows carry that much uncertainty.

Within that second session, on the same box at the same time, threads mode answered 1.3–8.0% more requests per second than processes mode and spent 1.3–6.9% less server CPU per request, on every route and at every load.

### Where native didn't win (ZTS round)

* **p99 at 256 connections.** Both modes' slowest 1% took longer than Swoole's and FrankenPHP's: on `/plain` 464 ms (threads) and 484 ms (processes) against 364 and 274 ms; on `/db` 674 and 691 ms against 457 and 356 ms. The NTS round's hypothesis applies to both modes, which each give every worker its own socket.
* **p99 against FrankenPHP at every load**, as on NTS: 23 ms against 33 ms for both native modes on `/plain` at 16 connections, 68 ms against 124 (threads) and 131 (processes) at 64.

## Not measured

One box size, one architecture (aarch64), one PHP version per round for every cell but FrankenPHP. Not covered here:

* PHP 8.5 for native, Swoole and RoadRunner;
* TLS, HTTP/2 and HTTP/3;
* `concurrently()`, ticks, tables and the Octane cache (native has no task workers yet);
* requests that wait on slow I/O, beyond one local MySQL query;
* more than 256 connections, more than 2 workers, or more than 2 vCPU;
* x86.

## Reproduce

[`benchmarks/harness/README.md`](benchmarks/harness/README.md) lists every script and the order to run them on two fresh Ubuntu 24.04 machines. Then, on the load machine:

```bash
TAG=2026-10-22-84nts nohup ~/rocketship-field/harness/all.sh > /dev/null 2>&1 &   # NTS round, about 2.5 hours
BUILD=8.4-zts TAG=2026-10-22-84zts CELLS="native-threads:plain native-threads:apcu native:plain native:apcu swoole:plain swoole:apcu roadrunner:plain roadrunner:apcu frankenphp:plain" \
  nohup ~/rocketship-field/harness/all.sh > /dev/null 2>&1 &                        # ZTS round, about 3.3 hours
```

Copy `~/rocketship-field/results/<tag>/` into `benchmarks/results/`, then:

```bash
python3 benchmarks/report.py    # prints the tables above and writes benchmarks/report.html with every round
```
