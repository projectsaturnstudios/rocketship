#!/usr/bin/env python3
"""Turn a field-test round into BENCHMARKS.md tables and a self-contained interactive report.

    python3 benchmarks/report.py [tag ...]      # default: every directory in benchmarks/results/

Reads results/<tag>/{runs,cells,memory,behaviour}.jsonl as the harness wrote them. Prints the markdown tables and
writes benchmarks/report.html: one file with Chart.js (vendor/chart.umd.min.js) and the data inline, no network.
"""

import json
import statistics
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
RESULTS = HERE / 'results'
ERRORS = ('err_connect', 'err_read', 'err_write', 'err_timeout')
SERVERS = {'swoole': 'Swoole', 'roadrunner': 'RoadRunner', 'frankenphp': 'FrankenPHP', 'native': 'native', 'native-threads': 'native threads'}
ORDER = ['native-threads:plain', 'native-threads:apcu', 'native:plain', 'native:apcu', 'swoole:plain', 'swoole:apcu', 'frankenphp:plain', 'roadrunner:plain', 'roadrunner:apcu']
ROUTES = ['plain', 'json', 'view', 'db']


def label(cell: str) -> str:
    server, apcu = cell.split(':')
    return SERVERS.get(server, server) + (' + APCu' if apcu == 'apcu' else '')


def jsonl(path: Path) -> list:
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()] if path.exists() else []


def failed(wrk) -> bool:
    return wrk is None or wrk.get('non2xx', 0) > 0 or any(wrk.get(k, 0) > 0 for k in ERRORS)


def load(tag: str) -> dict:
    base = RESULTS / tag
    runs = jsonl(base / 'runs.jsonl')

    # One measurement per (cell, route, concurrency, rep): the last attempt, which is the retry when the first failed.
    last = {}
    for run in runs:
        key = (f"{run['server']}:{run['apcu']}", run['route'], run['c'], run['rep'])
        last[key] = run

    points = []
    for (cell, route, c, rep), run in sorted(last.items()):
        wrk = run['wrk'] or {}
        requests = wrk.get('requests', 0)
        points.append({
            'cell': cell, 'route': route, 'c': c, 'rep': rep,
            'attempt': run.get('attempt', 1), 'failed': failed(run['wrk']),
            'rps': wrk.get('rps'), 'p50': wrk.get('p50_us', 0) / 1000, 'p90': wrk.get('p90_us', 0) / 1000,
            'p99': wrk.get('p99_us', 0) / 1000, 'max': wrk.get('max_us', 0) / 1000,
            'cpu': (run['server_cpu']['busy_s'] * 1e6 / requests) if requests and run.get('server_cpu') else None,
            'server_share': (run.get('server_cpu') or {}).get('share'),
            'red_share': (run.get('red_cpu') or {}).get('share'),
            'non2xx': wrk.get('non2xx'), 'errors': sum(wrk.get(k, 0) for k in ERRORS), 'at': run['at'],
        })

    return {
        'tag': tag,
        'points': points,
        'retries': sum(1 for r in runs if r.get('attempt', 1) > 1),
        'cells': jsonl(base / 'cells.jsonl'),
        'memory': jsonl(base / 'memory.jsonl'),
        'behaviour': jsonl(base / 'behaviour.jsonl'),
    }


def stats(points: list, metric: str) -> dict | None:
    values = [p[metric] for p in points if not p['failed'] and p[metric] is not None]
    return {'median': statistics.median(values), 'min': min(values), 'max': max(values), 'n': len(values)} if values else None


def cells_of(data: dict) -> list:
    present = {p['cell'] for p in data['points']}
    return [c for c in ORDER if c in present] + sorted(present - set(ORDER))


def markdown(data: dict) -> str:
    out = []
    cells = cells_of(data)
    concurrencies = sorted({p['c'] for p in data['points']})
    routes = [r for r in ROUTES if any(p['route'] == r for p in data['points'])]
    pick = lambda cell, route, c: [p for p in data['points'] if p['cell'] == cell and p['route'] == route and p['c'] == c]

    out.append(f"## Round {data['tag']}\n")
    failures = sum(1 for p in data['points'] if p['failed'])
    out.append(f"{len(data['points'])} measurements, {failures} failed after a retry, {data['retries']} retries.\n")

    for route in routes:
        out.append(f"### `/{route}`: req/s, median of reps [min–max]\n")
        out.append('| | ' + ' | '.join(f'c={c}' for c in concurrencies) + ' |')
        out.append('|---|' + '---:|' * len(concurrencies))
        for cell in cells:
            row = []
            for c in concurrencies:
                s = stats(pick(cell, route, c), 'rps')
                row.append(f"{s['median']:,.0f} [{s['min']:,.0f}–{s['max']:,.0f}]" if s else '—')
            out.append(f'| {label(cell)} | ' + ' | '.join(row) + ' |')
        out.append('')
        out.append(f"### `/{route}`: p50 / p99 latency (ms) and server CPU per request (µs), median of reps\n")
        out.append('| | ' + ' | '.join(f'c={c}' for c in concurrencies) + ' | CPU/request, c=64 |')
        out.append('|---|' + '---:|' * (len(concurrencies) + 1))
        for cell in cells:
            row = []
            for c in concurrencies:
                p50, p99 = stats(pick(cell, route, c), 'p50'), stats(pick(cell, route, c), 'p99')
                row.append(f"{p50['median']:.1f} / {p99['median']:.1f}" if p50 and p99 else '—')
            cpu = stats(pick(cell, route, 64), 'cpu')
            cpu_text = f"{cpu['median']:,.0f}" if cpu else '—'
            out.append(f'| {label(cell)} | ' + ' | '.join(row) + f' | {cpu_text} |')
        out.append('')

    if data['memory']:
        out.append('### Memory: RSS of every process in the cell (MB)\n')
        checkpoints = sorted({0, 10000, 50000, 100000})
        out.append('| | ' + ' | '.join(f'{c:,} req' for c in checkpoints) + ' | processes |')
        out.append('|---|' + '---:|' * (len(checkpoints) + 1))
        for cell in cells:
            rows = [m for m in data['memory'] if f"{m['server']}:{m['apcu']}" == cell]
            if not rows:
                continue
            values = []
            for target in checkpoints:
                m = next((r for r in rows if r['requests'] >= target), None)
                values.append(f"{m['rss_kb'] / 1024:.0f}" if m else '—')
            out.append(f'| {label(cell)} | ' + ' | '.join(values) + f" | {len(rows[-1]['processes'])} |")
        out.append('')

    if data['behaviour']:
        out.append('### Behaviour\n')
        out.append('| | status | static | upload | stream | reload: errors / requests | kill -9: back in | past max_execution_time | stop leftovers |')
        out.append('|---|---|---|---|---|---|---|---|---|')
        for b in data['behaviour']:
            w = b.get('reload_wrk') or {}
            errs = (w.get('non2xx', 0) or 0) + sum(w.get(k, 0) for k in ERRORS)
            out.append(
                f"| {label(b['server'] + ':' + b['apcu'])} | exit {b['status_exit']} | {b['static']} | {b['upload_bytes']} B "
                f"| {b['stream_bytes']:,} B | {errs} / {w.get('requests', 0):,} | "
                f"{'n/a (threads)' if b.get('killed_pid') is None else ((str(b['recovered_s']) + ' s') if b.get('recovered_s') is not None else 'no')} | "
                f"{b['slow_status']} at {b['slow_s']} s, next {b['after_slow']} | {(str(len(b['stop']['killed_leftovers'].split())) + ' processes, killed') if (b['stop'].get('killed_leftovers') or '').strip() else 'none'} |")
        out.append('')

    return '\n'.join(out)


def html(rounds: list) -> str:
    chart = (HERE / 'vendor' / 'chart.umd.min.js').read_text()
    payload = {
        'rounds': rounds,
        'labels': {c: label(c) for r in rounds for c in cells_of(r)},
        'order': ORDER,
        'routes': ROUTES,
    }
    data = json.dumps(payload, separators=(',', ':')).replace('</', '<\\/')
    return TEMPLATE.replace('/*__CHARTJS__*/', chart).replace('/*__DATA__*/null', data)


TEMPLATE = (HERE / 'report.template.html').read_text() if (HERE / 'report.template.html').exists() else ''


def main() -> None:
    tags = sys.argv[1:] or sorted(p.name for p in RESULTS.iterdir() if p.is_dir())
    rounds = [load(tag) for tag in tags]

    for data in rounds:
        print(markdown(data))

    (HERE / 'report.html').write_text(html(rounds))
    print(f"wrote {HERE / 'report.html'}", file=sys.stderr)


if __name__ == '__main__':
    main()
