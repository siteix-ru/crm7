// @ts-ignore — тестовый Node API, не часть browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const css = readFileSync(new URL('../../../app.css', import.meta.url), 'utf8');

const sources = import.meta.glob('./AppShell.svelte', {
  query: '?raw',
  import: 'default',
  eager: true
});
const source = sources['./AppShell.svelte'] as string;

describe('AppShell header after Navigation 2.0 Phase 1', () => {
  it('renders the application rail from visibleNav', () => {
    expect(source).toContain('<AppRail');
    expect(source).toContain('nodes={appSettings.visibleNav}');
    expect(source).not.toContain('<nav class="desktop-nav"');
  });

  it('has no topbar and offsets the workspace by the rail width', () => {
    expect(source).not.toContain('<header class="topbar"');
    expect(source).not.toContain('.topbar-actions {');
    expect(source).toContain('style:--rail-width={`${railWidth}px`}');
    expect(source).toContain('width: min(calc(100% - var(--rail-width, 56px)), 1328px)');
  });

  it('applies global density tokens per user', () => {
    expect(source).toContain('document.documentElement.dataset.density = navPrefs.density');
    expect(source).toContain('class="mobile-density"');
    expect(source).toContain('theme.toggle()');
    expect(css).toContain(":root[data-density='compact']");
    expect(css).toContain('--control-height: 28px');
  });

  it('allows manual Expanded from 768px and collapses below it', () => {
    expect(source).toContain("matchMedia('(min-width: 768px)')");
    expect(source).toContain('if (!canExpandRail && railExpanded)');
    expect(source).toContain('navPrefs.setUser(auth.user?.id ?? null)');
    expect(source).toContain('navPrefs.setRailExpanded(false)');
  });
});
