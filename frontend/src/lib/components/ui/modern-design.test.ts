// @ts-ignore — test-only Node API, not part of the browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import searchInput from './SearchInput.svelte?raw';

const appCss = readFileSync(new URL('../../../app.css', import.meta.url), 'utf8');

describe('modern B2B design tokens', () => {
  it('defines a consistent type scale', () => {
    for (const token of ['--font-xs: 12px', '--font-sm: 13px', '--font-md: 14px', '--font-lg: clamp(', '--font-xl: clamp(']) {
      expect(appCss).toContain(token);
    }
    expect(appCss).toMatch(/h1\s*\{[^}]*font-size:\s*var\(--font-xl\)/s);
  });

  it('uses softer modern radii and shadows', () => {
    expect(appCss).toContain('--radius-sm: 8px');
    expect(appCss).toContain('--radius-md: 12px');
    expect(appCss).toMatch(/--shadow-md:[^;]*0\.06/s);
  });

  it('keeps toolbar icons at a comfortable size', () => {
    expect(appCss).toMatch(/\.page-icon-btn svg\s*\{[^}]*width:\s*18px/s);
    expect(searchInput).toContain('name="search" size={16}');
  });
});
