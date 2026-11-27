import { describe, expect, it } from 'vitest';
import { buildPaletteResults, canRunResult } from './palette-results';
import type { SearchSection } from './components/layout/nav-search';
import type { QuickActionContext } from './quick-actions';
import type { RecentPage } from './stores/recent-pages.svelte';

const sections: SearchSection[] = [
  {
    code: 'work',
    title: 'Работа',
    iconId: 'requests',
    items: [
      { node: { id: 'requests', label: 'Заявки', iconId: 'requests', group: 'work', route: '#/requests', capabilities: [], presentation: { rail: true, topbar: false, bottom: true, drawer: true }, order: 1, type: 'section' }, children: [] },
      { node: { id: 'chat', label: 'Чат', iconId: 'chat', group: 'work', route: '#/chat', capabilities: [], presentation: { rail: true, topbar: true, bottom: false, drawer: true }, order: 2, type: 'section' }, children: [] }
    ]
  }
];

function context(overrides: Partial<QuickActionContext> = {}): QuickActionContext {
  return {
    navIds: new Set(['requests', 'chat', 'settings']),
    capabilities: ['requests.create', 'chat.use', 'settings.manage'],
    requestsEnabled: true,
    salesEnabled: true,
    chatEnabled: true,
    ...overrides
  };
}

const recent: RecentPage[] = [
  { path: '/requests', title: 'Заявки', visitedAt: Date.now() },
  { path: '/chat', title: 'Чат', visitedAt: Date.now() - 1000 }
];

describe('Phase 3 palette results', () => {
  it('merges recent, pages and actions into one ranked list', () => {
    const results = buildPaletteResults({ sections, context: context(), recent, query: '' });
    expect(results.filter((item) => item.kind === 'recent').map((item) => item.route)).toEqual(['/requests', '/chat']);
    expect(results.filter((item) => item.kind === 'action').map((item) => item.id)).toHaveLength(3);
    expect(results.some((item) => item.kind === 'system' && item.system === 'clear-recent')).toBe(true);
  });

  it('does not duplicate a recent page in the page results', () => {
    const results = buildPaletteResults({ sections, context: context(), recent, query: 'заявк' });
    expect(results.filter((item) => item.route === '/requests')).toHaveLength(1);
  });

  it('shows only capabilities-allowed actions', () => {
    const results = buildPaletteResults({
      sections,
      context: context({ capabilities: ['chat.use'], navIds: new Set(['chat']) }),
      recent: [],
      query: ''
    });
    expect(results.map((item) => item.id)).toEqual(['action:open-chat']);
  });

  it('has a clear empty state', () => {
    expect(buildPaletteResults({ sections, context: context(), recent: [], query: 'нет такого' })).toEqual([]);
  });

  it('re-checks page, action and system permissions before execution', () => {
    const allowed = new Set(['/requests', '/chat', '/settings']);
    const page = buildPaletteResults({ sections, context: context(), recent, query: '' }).find((item) => item.kind === 'action' && item.id === 'action:new-request')!;
    expect(canRunResult(page, context(), allowed)).toBe(true);
    expect(canRunResult(page, context({ capabilities: [] }), allowed)).toBe(false);

    const pageResult = buildPaletteResults({ sections, context: context(), recent: [], query: 'чат' }).find((item) => item.kind === 'page')!;
    expect(canRunResult(pageResult, context(), allowed)).toBe(true);
    expect(canRunResult(pageResult, context(), new Set(['/requests']))).toBe(false);

    const clear = buildPaletteResults({ sections, context: context(), recent, query: '' }).find((item) => item.system === 'clear-recent')!;
    expect(canRunResult(clear, context(), allowed)).toBe(true);
  });

  it('never stores or restores sensitive query parameters', () => {
    const results = buildPaletteResults({ sections, context: context(), recent, query: 'token=abc' });
    expect(results).toEqual([]);
  });
});
