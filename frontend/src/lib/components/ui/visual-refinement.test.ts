// @ts-ignore — test-only Node API, not part of the browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import button from './Button.svelte?raw';
import emptyState from './EmptyState.svelte?raw';

const appCss = readFileSync(new URL('../../../app.css', import.meta.url), 'utf8');

describe('visual refinement', () => {
  it('uses fluid heading sizes and tabular figures', () => {
    expect(appCss).toMatch(/--font-xl:\s*clamp\(/);
    expect(appCss).toContain('font-variant-numeric: tabular-nums');
    expect(appCss).toMatch(/h1\s*\{[^}]*letter-spacing:\s*-0\.015em/s);
  });

  it('adds restrained motion only when the user allows it', () => {
    expect(appCss).toContain('@media (prefers-reduced-motion: no-preference)');
    expect(appCss).toMatch(/\.page-hero:hover[\s\S]*box-shadow:\s*var\(--shadow-md\)/);
  });

  it('gives primary actions and empty states a soft tactile treatment', () => {
    expect(button).toMatch(/\.primary\s*\{[^}]*linear-gradient/s);
    expect(emptyState).toMatch(/\.icon\s*\{[^}]*color-mix\(in srgb, var\(--primary\) 8%/s);
  });
});
