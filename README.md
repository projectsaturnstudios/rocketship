<div align="center" id="header">

<h1>ProjectSaturn Rocketship</h1>
<h3>A drop-in replacement for Laravel Octane, with a server written in PHP</h3>

[![CI](https://img.shields.io/github/actions/workflow/status/projectsaturnstudios/rocketship/tests.yml?style=flat-square&label=CI)](https://github.com/projectsaturnstudios/rocketship/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/projectsaturnstudios/rocketship?style=flat-square)](https://packagist.org/packages/projectsaturnstudios/rocketship)
[![PHP](https://img.shields.io/packagist/dependency-v/projectsaturnstudios/rocketship/php?style=flat-square&label=PHP)](https://packagist.org/packages/projectsaturnstudios/rocketship)
[![Laravel](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-ff2d20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/docs/octane)
[![License](https://img.shields.io/github/license/projectsaturnstudios/rocketship?style=flat-square)](LICENSE.md)
[![Loop](https://img.shields.io/badge/loop-epoll%20%7C%20kqueue-blue?style=flat-square)](#installation)

</div>

Laravel Octane boots your app once and keeps it in memory. To do that it needs an application server from outside PHP: Swoole, a C extension that brings its own coroutine runtime; RoadRunner, a Go binary that talks to PHP workers over pipes; or FrankenPHP, a Go binary with PHP built in.

PHP can serve HTTP itself if it can ask the kernel which sockets are ready. Linux answers that with `epoll`, macOS with `kqueue`. PHP never exposed either one to userland, so I wrote [ext-epoll](https://github.com/php-io-extensions/epoll), [ext-kqueue](https://github.com/php-io-extensions/kqueue) and [ext-posi](https://github.com/php-io-extensions/posi): the syscalls, one to one, no opinions.

<b>Rocketship is Octane with a fourth server, `native`, built on those extensions.</b> It's an HTTP/1.1 server written in PHP. Its workers are forked processes on any PHP, or threads on a ZTS PHP with ext-parallel. It runs under Octane's own commands, config and events.

Everything else is Octane's own code, forked at [v2.22.0](https://github.com/laravel/octane/tree/v2.22.0) under its MIT license: the `Laravel\Octane` namespace, `config/octane.php`, the `Octane` facade, the listeners, and the Swoole, RoadRunner and FrankenPHP servers. Your app code doesn't change. You swap the package and choose a server.

## Benchmarks

Measured on a 2 vCPU Graviton2 box, with the app, nginx and MySQL sharing it and the load sent from a second machine, against Octane's three servers with Octane's defaults:

* **PHP 8.4 ZTS, native in threads mode: 9–20% more requests per second than Swoole**, 13–24% more than FrankenPHP, and 61–98% more than RoadRunner, on every route and load measured.
* **8–17% less server CPU per request** than whichever of Octane's servers used the least on each route and load.
* **124 MB for the whole server**, against 189 MB for FrankenPHP, 233 MB for RoadRunner and 315 MB for Swoole.
* **PHP 8.4 NTS, native in processes mode: 11–17% more requests per second than Swoole** on the plain, JSON and Blade routes, and 2–8% more on the MySQL route.

Where it doesn't win: at 256 connections native's slowest 1% of requests take longer than Swoole's and FrankenPHP's, and FrankenPHP has the shortest tail at every load.

The details are in [BENCHMARKS.md](BENCHMARKS.md): the box, every version, the method, every table, the raw data, the harness to rerun it, and the numbers that went the other way. **[Open the interactive report](https://htmlpreview.github.io/?https://github.com/projectsaturnstudios/rocketship/blob/main/benchmarks/report.html)**: charts for every round, route, load and metric, with inputs to filter them. It's one self-contained file, [`benchmarks/report.html`](benchmarks/report.html), that also opens offline from a clone.

## Installation

| | |
|---|---|
| PHP | 8.4.27 or newer on 8.4, 8.5.12 or newer on 8.5. NTS or ZTS. |
| Laravel | 10, 11, 12 or 13, the same as Octane 2.22. |
| Octane's servers | As in Octane: ext-swoole for Swoole, and the binaries `octane:install` fetches for RoadRunner and FrankenPHP. |
| Native, both modes | ext-pcntl and ext-posix. [ext-epoll](https://github.com/php-io-extensions/epoll) on Linux or [ext-kqueue](https://github.com/php-io-extensions/kqueue) on macOS. On Linux, [ext-posi](https://github.com/php-io-extensions/posi) 0.10.6 or newer. |
| Native, threads mode | A ZTS PHP with ext-parallel and ext-posi, without ext-swoole loaded in the same PHP. |

```bash
composer require projectsaturnstudios/rocketship
```

Then install the extensions for the native server with [PIE](https://github.com/php/pie), on the machine that runs it:

```bash
sudo pie install php-io-extensions/epoll   # Linux
pie install php-io-extensions/kqueue       # macOS
sudo pie install php-io-extensions/posi
```

For threads mode, also install [ext-parallel](https://github.com/krakjoe/parallel) on a ZTS PHP.

## How to use

### Migrating from Octane

```bash
composer remove laravel/octane
composer require projectsaturnstudios/rocketship
```

Rocketship declares a conflict with `laravel/octane`, so remove Octane first. Everything you had keeps working as it was:

* **Your code**: the namespace is still `Laravel\Octane`. The `Octane` facade, `Octane::concurrently()`, `Octane::table()`, ticks, the `octane` cache store and Octane's events all resolve as before.
* **`config/octane.php`**: Rocketship reads the file you published. It adds one `native` section, and the defaults apply until you publish it again.
* **`.env`**: `OCTANE_SERVER`, `OCTANE_HTTPS` and the other `OCTANE_*` keys are read as before.
* **Your server**: Swoole, RoadRunner and FrankenPHP run Octane's own code. A FrankenPHP worker file that Octane published is pointed at Rocketship's worker.
* **Your process manager**: `octane:start`, `octane:stop`, `octane:reload` and `octane:status` keep their names and options.

### The native server

```bash
php artisan octane:install --server=native
php artisan octane:start --server=native
```

`octane:install --server=native` checks this PHP's extensions and prints what each mode is missing. `octane:start` takes Octane's options: `--host`, `--port`, `--workers` (default: CPU count), `--max-requests` (default: 500), `--watch` and `--poll`. Set `OCTANE_SERVER=native` to make it the default.

Native picks its mode when it starts. `auto`, the default, chooses threads when this PHP can run them and processes when it can't. To force one:

```bash
OCTANE_NATIVE_MODE=processes php artisan octane:start --server=native
php artisan octane:native --mode=threads
```

`octane:native` is the command that `octane:start --server=native` runs, as `octane:swoole` is for Swoole.

What native does:

* HTTP/1.1 with keep-alive, pipelining, chunked bodies, `100 Continue`, streamed responses and multipart uploads.
* TLS, when you give it a certificate.
* Static files from `public/`, with ETag, `Last-Modified`, `304` and byte ranges.
* `max_requests`, and `max_execution_time` with a `408` and a fresh worker, as on Swoole.
* `octane:reload` without dropping a request, `octane:stop` with a drain, and a replacement for a worker that dies.

What it doesn't do yet: native has no task workers, ticks or Swoole tables. `Octane::concurrently()` runs its tasks in the request, ticks don't fire, and `Octane::table()` and the `octane` cache behave as they do on RoadRunner and FrankenPHP. There's no HTTP/2.

The `native` section of `config/octane.php`:

| Key | Default | |
|---|---|---|
| `mode` | `env('OCTANE_NATIVE_MODE', 'auto')` | `auto`, `threads` or `processes`. |
| `tls.cert`, `tls.key` | `OCTANE_NATIVE_TLS_CERT`, `OCTANE_NATIVE_TLS_KEY` | PEM paths. The key can live in the certificate file. |
| `drain_timeout` | `30` | Seconds a stop or reload waits for requests in flight. |
| `keepalive_timeout` | `5` | Seconds an idle keep-alive connection stays open. |
| `header_timeout` | `30` | Seconds a client has to send a request head, answered `408` after. |
| `max_body` | `10485760` | Largest request body in bytes, answered `413` past it. |
| `php_options` | `[]` | Extra arguments for the PHP that runs the server, such as `-d` settings. |

Put nginx or another proxy in front in production, as with Octane's other servers.

### Adding your own server

Rocketship keeps its servers in a registry, so a package can add one. Implement `ProjectSaturnStudios\Rocketship\Servers\ServerDriver`: `start`, `stop`, `reload`, `running` and `install`, each handed the Octane command that was run. Then register it from any service provider:

```php
use Laravel\Octane\Facades\Octane;

public function register(): void
{
    Octane::registerServer('myserver', MyServer::class);
}
```

After that, `octane:start --server=myserver` and every other Octane command run through it, and `octane:install` offers it in its list. A later registration with the same name replaces an earlier one, built-ins included.

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews and architectural decisions were owned by a professional software engineer. The model speeds up drafting and exploration; it doesn't replace judgment on APIs, dependency direction or what ships.

## Running the test suite

The suite is Pest v4, in two parts that run in separate processes: Octane's own tests, and Rocketship's tests for the registry, the event loop, the HTTP parser and the native server. The native server tests start real servers on free ports and talk to them over TCP.

```bash
git clone https://github.com/projectsaturnstudios/rocketship.git
cd rocketship
composer install
vendor/bin/pest                          # Octane's tests
vendor/bin/pest --testsuite=Rocketship   # Rocketship's tests
```

`composer test` runs both.

Native tests need the extensions for the mode they cover. Without them, they're skipped. Threads-mode tests run only on a ZTS PHP with ext-parallel, and Linux-only tests are skipped on macOS. CI runs the suite on PHP 8.4 and 8.5, NTS and ZTS, on Linux with epoll and on macOS with kqueue.

## Resources

* [Laravel Octane](https://laravel.com/docs/octane): the docs for everything Rocketship keeps.
* [laravel/octane](https://github.com/laravel/octane): the upstream that Rocketship forks.
* [ext-epoll](https://github.com/php-io-extensions/epoll), [ext-kqueue](https://github.com/php-io-extensions/kqueue) and [ext-posi](https://github.com/php-io-extensions/posi): the extensions the native server runs on.
* [ext-parallel](https://github.com/krakjoe/parallel): the threads behind threads mode.

## Contributing

Open an issue first for anything beyond a typo. A pull request comes with a Pest test for the behaviour it changes and keeps CI green. Code under `rocketship/` and `bin/native-*` is Rocketship's. Everything else is Octane's, and changes there stay as close to upstream as the fix allows, so upstream fixes keep porting cleanly. The native server follows Swoole: where a behaviour question comes up, the answer is what Octane's Swoole server does.

## Security

The native server is a network server: it parses HTTP from clients, serves files from `public/` and terminates TLS when you give it a certificate. See [SECURITY.md](SECURITY.md) for the model and how to report a vulnerability.

## License

MIT. See [LICENSE.md](LICENSE.md). Rocketship is a fork of [Laravel Octane](https://github.com/laravel/octane) by Taylor Otwell and the Laravel contributors, under the same license.
