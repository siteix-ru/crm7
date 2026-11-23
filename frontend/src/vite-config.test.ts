import { describe, expect, it } from 'vitest';
import { createConfig } from '../vite.config';

describe('production source-map policy', () => {
  it('does not publish source maps in production builds', () => {
    const config = createConfig({ mode: 'production', command: 'build' });
    expect(config.build.sourcemap).not.toBe(true);
    expect(config.build.sourcemap).toBe(false);
  });

  it('keeps source maps for the development server', () => {
    const config = createConfig({ mode: 'development', command: 'serve' });
    expect(config.build.sourcemap).toBe(true);
  });
});
