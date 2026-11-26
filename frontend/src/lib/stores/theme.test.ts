// @ts-ignore — test-only Node API, not part of the browser bundle
import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it } from 'vitest';
import { theme } from './theme.svelte';

const appCss = readFileSync(new URL('../../app.css', import.meta.url), 'utf8');

describe('dark theme', () => {
  beforeEach(() => {
    localStorage.clear();
    theme.dark = false;
  });

  it('toggles the flag and persists the choice', () => {
    theme.toggle();
    expect(theme.dark).toBe(true);
    expect(localStorage.getItem('crm.theme')).toBe('dark');

    theme.toggle();
    expect(theme.dark).toBe(false);
    expect(localStorage.getItem('crm.theme')).toBe('light');
  });

  it('overrides surface and text tokens without touching layout rules', () => {
    expect(appCss).toContain(":root[data-theme='dark']");
    expect(appCss).toMatch(/--bg:\s*#14161a/);
    expect(appCss).toMatch(/--surface:\s*#1e2127/);
    expect(appCss).toContain(":root[data-theme='dark'] button.primary");
    expect(appCss).toContain(":root[data-theme='dark'] .empty-state .icon");
  });
});
