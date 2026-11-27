import type { NavNode } from '../../stores/app-settings.svelte';

/**
 * Phase 2B — навигационный поиск. Чистая логика без привязки к UI,
 * чтобы Results можно было переиспользовать будущими провайдерами (Phase 3).
 * Источник — только дерево, уже построенное из visibleNav.
 */

export interface SearchTreeNode {
  node: NavNode;
  children: SearchTreeNode[];
}

export interface SearchSection {
  code: string;
  title: string;
  iconId: string;
  items: SearchTreeNode[];
}

export const NAV_GROUP_TITLES: Record<string, string> = {
  work: 'Работа',
  stock: 'Склад',
  analytics: 'Аналитика',
  org: 'Организация',
  system: 'Система'
};

const NAV_GROUP_ORDER = ['work', 'stock', 'analytics', 'org', 'system'];

/** Единственный способ построить дерево блоков из visibleNav (Rail и Palette). */
export function sectionsFromNav(nodes: NavNode[]): SearchSection[] {
  const included = nodes.filter((node) => node.presentation.rail || !node.presentation.topbar);
  const byGroup = new Map<string, NavNode[]>();

  for (const node of included) {
    const list = byGroup.get(node.group) ?? [];
    list.push(node);
    byGroup.set(node.group, list);
  }

  const rank = (code: string): number => {
    const index = NAV_GROUP_ORDER.indexOf(code);
    return index === -1 ? NAV_GROUP_ORDER.length : index;
  };

  return Array.from(byGroup.entries())
    .map(([code, list]) => {
      const sorted = [...list].sort((a, b) => a.order - b.order);
      const ids = new Set(sorted.map((item) => item.id));
      const roots: NavNode[] = [];
      const children = new Map<string, NavNode[]>();

      for (const item of sorted) {
        if (item.parent !== undefined && item.parent !== item.id && ids.has(item.parent)) {
          const nested = children.get(item.parent) ?? [];
          nested.push(item);
          children.set(item.parent, nested);
        } else {
          roots.push(item);
        }
      }

      const build = (item: NavNode): SearchTreeNode => ({
        node: item,
        children: (children.get(item.id) ?? []).map(build)
      });

      /* В Compact по умолчанию показываем иконку первой страницы блока,
         а не подраздела, даже если у подраздела меньший order. */
      const firstPage = roots.find((item) => item.type === 'section') ?? roots[0] ?? sorted[0];

      return {
        code,
        title: NAV_GROUP_TITLES[code] ?? code,
        iconId: firstPage?.iconId ?? 'menu',
        items: roots.map(build)
      };
    })
    .sort((a, b) => rank(a.code) - rank(b.code) || a.code.localeCompare(b.code));
}

export interface SearchResult {
  id: string;
  label: string;
  iconId: string;
  route: string;
  group: string;
  path: string[];
  score: number;
  order: number;
}

export interface TextPart {
  text: string;
  hit: boolean;
}

function fold(value: string): string {
  return value.toLocaleLowerCase('ru').replace(/ё/g, 'е');
}

export function normalizeQuery(value: string): string {
  return fold(value.trim());
}

export function searchNav(sections: SearchSection[], query: string): SearchResult[] {
  const needle = normalizeQuery(query);
  if (needle === '') {
    return [];
  }

  const results: SearchResult[] = [];

  for (const section of sections) {
    const walk = (items: SearchTreeNode[], ancestors: string[]): void => {
      for (const item of items) {
        const path = [section.title, ...ancestors];
        const inLabel = fold(item.node.label).includes(needle);
        const inPath = fold([...path, item.node.label].join(' › ')).includes(needle);

        if (inLabel || inPath) {
          results.push({
            id: item.node.id,
            label: item.node.label,
            iconId: item.node.iconId,
            route: item.node.route,
            group: section.title,
            path,
            score: inLabel ? 0 : 1,
            order: item.node.order
          });
        }

        walk(item.children, [...ancestors, item.node.label]);
      }
    };

    walk(section.items, []);
  }

  return results.sort(
    (left, right) =>
      left.score - right.score ||
      left.group.localeCompare(right.group, 'ru') ||
      left.order - right.order ||
      left.label.localeCompare(right.label, 'ru')
  );
}

/** Разбивает текст на части для визуального выделения совпадения. */
export function highlightParts(text: string, query: string): TextPart[] {
  const needle = normalizeQuery(query);
  if (needle === '') {
    return [{ text, hit: false }];
  }

  const haystack = fold(text);
  const index = haystack.indexOf(needle);
  if (index === -1) {
    return [{ text, hit: false }];
  }

  const parts: TextPart[] = [];
  if (index > 0) {
    parts.push({ text: text.slice(0, index), hit: false });
  }
  parts.push({ text: text.slice(index, index + needle.length), hit: true });
  const tail = index + needle.length;
  if (tail < text.length) {
    parts.push({ text: text.slice(tail), hit: false });
  }
  return parts;
}
