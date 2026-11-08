// Bounded, read-only staging HTTP baseline.
import { performance } from 'node:perf_hooks';
const target = new URL(process.env.POS_LOAD_URL ?? 'http://127.0.0.1:8880/readyz');
const total = Number(process.env.POS_LOAD_REQUESTS ?? 100);
const concurrency = Number(process.env.POS_LOAD_CONCURRENCY ?? 5);
if (!['http:', 'https:'].includes(target.protocol) || !Number.isInteger(total) || total < 1 || total > 1000 || !Number.isInteger(concurrency) || concurrency < 1 || concurrency > 20) throw new Error('Use 1–1000 requests and concurrency 1–20');
const latencies = []; let next = 0, failed = 0;
const begin = performance.now();
await Promise.all(Array.from({ length: concurrency }, async () => {
  while (next++ < total) {
    const start = performance.now();
    try {
      const response = await fetch(target, { signal: AbortSignal.timeout(10000), redirect: 'error' });
      await response.arrayBuffer();
      if (!response.ok) failed++;
    } catch { failed++; }
    latencies.push(performance.now() - start);
  }
}));
latencies.sort((a,b) => a-b);
const percentile = (p) => Math.round(latencies[Math.min(latencies.length-1,Math.ceil(latencies.length*p)-1)]);
console.log(JSON.stringify({ requests: total, concurrency, failed, p50_ms: percentile(.5), p95_ms: percentile(.95), elapsed_ms: Math.round(performance.now()-begin) }));
process.exitCode = failed ? 1 : 0;
