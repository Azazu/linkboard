-- The percentiles NFR-PERF-1 is written in terms of.
--
-- `wrk --latency` prints p50, p75, p90 and p99, and the target is p95 — which
-- those four only bracket: a run whose p90 is 22 ms and p99 is 89 ms says
-- nothing about a 50 ms target. So the recipe asks wrk for the number itself
-- (change harden-quality-and-docs). Mounted read-only into the throwaway
-- container, so the repository still carries no benchmarking tool.
done = function(summary, latency, requests)
    for _, p in ipairs({ 50, 75, 90, 95, 99 }) do
        io.write(string.format("  p%-3d %8.2f ms\n", p, latency:percentile(p) / 1000))
    end
end
