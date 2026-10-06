import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // One reusable worker keeps the Angular suite within local resource limits.
    pool: 'threads',
    fileParallelism: false,
    minWorkers: 1,
    maxWorkers: 1,
    isolate: false,
    testTimeout: 30_000
  }
});
