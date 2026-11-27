import { describe, expect, it } from 'vitest';
import {
  highlightParts,
  normalizeQuery,
  searchNav,
  sectionsFromNav,
  type SearchSection,
  type SearchTreeNode
} from './nav-search';
import type { NavNode } from '../../stores/app-settings.svelte';

function node(id: string, label: string, route: string, order = 1): NavNode {
  return {
    id,
    label,
    iconId: id,
    group: 'work',
    route,
    capabilities: [],
    presentation: { rail: true, topbar: false, bottom: false, drawer: true },
    order,
    type: 'section'
  };
}

function tree(nodeValue: NavNode, children: SearchTreeNode[] = []): SearchTreeNode {
  return { node: nodeValue, children };
}

const sections: SearchSection[] = [
  {
    code: 'work',
    title: 'Работа',
    iconId: 'requests',
    items: [
      tree(node('requests', 'Заявки', '#/requests', 1)),
      tree(node('clients', 'Клиенты', '#/clients', 2))
    ]
  },
  {
    code: 'stock',
    title: 'Склад',
    iconId: 'stocks',
    items: [
      tree(node('stocks', 'Номенклатура', '#/stocks', 1), [
        tree(node('warehouses', 'Склады', '#/stocks/warehouses', 1))
      ])
    ]
  },
  {
    code: 'system',
    title: 'Система',
    iconId: 'settings',
    items: [tree(node('settings', 'Настройки', '#/settings', 1))]
  }
];

describe('Phase 2B navigation search', () => {
  it('ignores empty, whitespace-only queries', () => {
    expect(normalizeQuery('  ')).toBe('');
    expect(searchNav(sections, '')).toEqual([]);
    expect(searchNav(sections, '   ')).toEqual([]);
  });

  it('is case-insensitive and handles ё/е', () => {
    expect(normalizeQuery('  СКЛАДЫ  ')).toBe('склады');
    expect(searchNav(sections, 'СКЛАДЫ').map((item) => item.id)).toEqual(['warehouses']);
    expect(searchNav(sections, 'склады').map((item) => item.id)).toEqual(['warehouses']);
    expect(searchNav(sections, 'номенклатУРА').map((item) => item.id)).toEqual(['stocks', 'warehouses']);
    expect(searchNav(sections, 'настройки').map((item) => item.id)).toEqual(['settings']);
  });

  it('searches labels and hierarchical paths from visibleNav only', () => {
    expect(searchNav(sections, 'Работа').map((item) => item.id)).toEqual(['requests', 'clients']);
    const byPath = searchNav(sections, 'Номенклатура › Склады');
    expect(byPath.map((item) => item.id)).toEqual(['warehouses']);
    expect(byPath[0].path).toEqual(['Склад', 'Номенклатура']);
  });

  it('does not invent routes and keeps group/subsection context', () => {
    const result = searchNav(sections, 'Склады')[0];
    expect(result.route).toBe('#/stocks/warehouses');
    expect(result.group).toBe('Склад');
    expect(result.path).toEqual(['Склад', 'Номенклатура']);
    expect(result.iconId).toBe('warehouses');
  });

  it('returns a clear empty state for a query without matches', () => {
    expect(searchNav(sections, 'несуществующий раздел')).toEqual([]);
  });

  it('highlights the matched fragment without changing the original text', () => {
    expect(highlightParts('Заявки', '')).toEqual([{ text: 'Заявки', hit: false }]);
    expect(highlightParts('Заявки', 'ЗАЯВ')).toEqual([
      { text: 'Заяв', hit: true },
      { text: 'ки', hit: false }
    ]);
    expect(highlightParts('Номенклатура', 'нет')).toEqual([
      { text: 'Номенклатура', hit: false }
    ]);
  });
});

describe('compact group icon default', () => {
  const item = (id: string, order: number, extra: Partial<NavNode> = {}): NavNode => ({
    id,
    label: id,
    iconId: id,
    group: 'stock',
    route: `#/${id}`,
    capabilities: [],
    presentation: { rail: true, topbar: false, bottom: false, drawer: true },
    order,
    type: 'section',
    ...extra
  });

  it('uses the first section of each group, not an earlier subsection', () => {
    const sections = sectionsFromNav([
      item('warehouses', 0, { type: 'subsection', parent: 'stocks' }),
      item('stocks', 1)
    ]);
    expect(sections[0].iconId).toBe('stocks');
  });

  it('falls back to the first available node when a group has no section', () => {
    const sections = sectionsFromNav([item('warehouses', 0, { type: 'subsection', parent: 'missing' })]);
    expect(sections[0].iconId).toBe('warehouses');
  });
});
