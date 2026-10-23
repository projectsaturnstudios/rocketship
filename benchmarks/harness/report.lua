-- Field-test vape: print one JSON line per wrk run.
done = function(summary, latency, requests)
  local e = summary.errors
  io.write(string.format(
    '{"requests":%d,"duration_us":%d,"bytes":%d,"rps":%.2f,"p50_us":%d,"p90_us":%d,"p99_us":%d,"max_us":%d,"mean_us":%.1f,"non2xx":%d,"err_connect":%d,"err_read":%d,"err_write":%d,"err_timeout":%d}\n',
    summary.requests, summary.duration, summary.bytes, summary.requests / (summary.duration / 1e6),
    latency:percentile(50), latency:percentile(90), latency:percentile(99), latency.max, latency.mean,
    e.status, e.connect, e.read, e.write, e.timeout))
end
