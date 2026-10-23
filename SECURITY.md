# Security Policy

## Supported versions

Rocketship is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the next
release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on the
[projectsaturnstudios/rocketship](https://github.com/projectsaturnstudios/rocketship) repository's **Security** tab.
If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, your platform, the PHP build (NTS or ZTS), the server
(`native` in threads or processes mode, `swoole`, `roadrunner` or `frankenphp`), and steps to reproduce.
Reports are read and weighed for the release line in development; before 1.0 there is no response-time
commitment.

A problem that Laravel Octane has too, in code Rocketship forks unchanged (anything outside `rocketship/` and
`bin/native-*`), should also go to Laravel through [their security policy](https://github.com/laravel/octane/security/policy).

## Security model

The native server runs inside your Laravel app, with that app's privileges. It accepts TCP connections on
the configured host and port, parses HTTP/1.1, hands each request to a worker that runs your app, and serves
files from `public/`.

- **HTTP parsing.** A request line past 8 KB is answered `414`, headers past 64 KB `431`, and a body past
  `max_body` (10 MB by default) `413`, before the body is read. Request smuggling shapes (conflicting
  `Content-Length` values, `Content-Length` with `Transfer-Encoding`, malformed chunk framing) are refused.
  A client that doesn't finish its request head within `header_timeout` is answered `408`. Large bodies are
  spooled to a temp file instead of held in memory.
- **Uploads.** Multipart files stream to temp files under PHP's `upload_tmp_dir`, with PHP's
  `upload_max_filesize` and `max_file_uploads` limits; temp files are removed when a request is aborted.
- **Static files.** Only regular files under `public/` are served, including through symlinks you put
  there, such as Laravel's `public/storage`. PHP scripts, dotfiles, directories and `..` traversal go to
  your app instead.
- **Time limits.** A request that runs past `max_execution_time` is answered `408` and its worker is
  replaced, as on Octane's Swoole server.
- **TLS.** The native server terminates TLS only when you give it a certificate (`octane.native.tls`).
  Without one it speaks plain TCP; put a TLS proxy in front in production.
- **Control socket.** The master and its workers talk over a Unix socket in `storage/rocketship/` (or the
  temp directory when that path is too long), created owner-only. A worker must present a per-start random
  secret before the master acts on its frames; other connections are ignored.

A report is in scope when the native server accepts what it should refuse: a request past a configured
limit, a file outside `public/` reached without a symlink you placed, a control frame from a process
without the secret, a crash or unbounded memory growth reachable from a client. Effects of configuration
you chose, such as serving without TLS or a raised `max_body`, are documented behaviour.
