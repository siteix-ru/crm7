import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChunkLoader, type ChunkStatus } from './chunk-loader';

function record() {
  const events: Array<{ status: ChunkStatus; value: unknown | null }> = [];

  return {
    events,
    onChange: (status: ChunkStatus, value: unknown | null) => events.push({ status, value })
  };
}

describe('ChunkLoader', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it('reports success on the first attempt', async () => {
    const module = { default: 'View' };
    const sink = record();
    const loader = new ChunkLoader(() => Promise.resolve(module), sink.onChange, 2, 10);

    loader.start();
    await Promise.resolve();

    expect(sink.events.map((event) => event.status)).toEqual(['loading', 'ready']);
    expect(sink.events[1].value).toBe(module);
  });

  it('retries after the first failure and reports success', async () => {
    vi.useFakeTimers();
    let calls = 0;
    const module = { default: 'View' };
    const sink = record();
    const loader = new ChunkLoader(
      () => (++calls === 1 ? Promise.reject(new Error('offline')) : Promise.resolve(module)),
      sink.onChange,
      2,
      10
    );

    loader.start();
    await vi.runAllTimersAsync();

    expect(calls).toBe(2);
    expect(sink.events.map((event) => event.status)).toEqual(['loading', 'ready']);
  });

  it('reports error after all retries are exhausted', async () => {
    vi.useFakeTimers();
    let calls = 0;
    const sink = record();
    const loader = new ChunkLoader(
      () => {
        calls += 1;

        return Promise.reject(new Error('chunk not found'));
      },
      sink.onChange,
      2,
      10
    );

    loader.start();
    await vi.runAllTimersAsync();

    expect(calls).toBe(3);
    expect(sink.events.map((event) => event.status)).toEqual(['loading', 'error']);
  });

  it('starts a fresh loading cycle on retry', async () => {
    vi.useFakeTimers();
    let fail = true;
    const module = { default: 'View' };
    const sink = record();
    const loader = new ChunkLoader(
      () => (fail ? Promise.reject(new Error('offline')) : Promise.resolve(module)),
      sink.onChange,
      0,
      10
    );

    loader.start();
    await vi.runAllTimersAsync();
    expect(sink.events.at(-1)?.status).toBe('error');

    fail = false;
    loader.start();
    await vi.runAllTimersAsync();

    expect(sink.events.map((event) => event.status)).toEqual(['loading', 'error', 'loading', 'ready']);
  });

  it('ignores a result after cancellation', async () => {
    const module = { default: 'View' };
    const sink = record();
    const loader = new ChunkLoader(() => Promise.resolve(module), sink.onChange, 2, 10);

    loader.start();
    loader.cancel();
    await Promise.resolve();

    expect(sink.events.map((event) => event.status)).toEqual(['loading']);
  });
});
