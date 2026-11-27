import { normalizeQuery, searchNav, type SearchSection } from './components/layout/nav-search';
import type { QuickAction, QuickActionContext } from './quick-actions';
import { canRunQuickAction, quickActions } from './quick-actions';
import type { RecentPage } from './stores/recent-pages.svelte';
import { routePath } from './navigation-context';

export type PaletteResultKind = 'recent' | 'page' | 'action' | 'system';

export interface PaletteResult {
  id: string;
  kind: PaletteResultKind;
  label: string;
  sublabel: string;
  iconId: string;
  route: string | null;
  actionId?: QuickAction['id'];
  system?: 'clear-recent';
}

export interface PaletteSources {
  sections: SearchSection[];
  context: QuickActionContext;
  recent: RecentPage[];
  query: string;
}

const HINT = 'Недавние страницы и доступные действия';

function matches(text: string, needle: string): boolean {
  return normalizeQuery(text).includes(needle);
}

/** Три источника объединяются в один список без дублей и без второго реестра. */
export function buildPaletteResults({ sections, context, recent, query }: PaletteSources): PaletteResult[] {
  const needle = normalizeQuery(query);
  const seen = new Set<string>();
  const results: PaletteResult[] = [];

  const add = (result: PaletteResult, dedupeKey: string | null): void => {
    if (dedupeKey !== null) {
      if (seen.has(dedupeKey)) {
        return;
      }
      seen.add(dedupeKey);
    }
    results.push(result);
  };

  for (const item of recent) {
    if (needle !== '' && !matches(item.title, needle) && !matches(item.path, needle)) {
      continue;
    }
    add(
      { id: `recent:${item.path}`, kind: 'recent', label: item.title, sublabel: HINT, iconId: 'history', route: item.path },
      item.path
    );
  }

  if (recent.length > 0 && (needle === '' || matches('Очистить недавние', needle))) {
    add(
      {
        id: 'system:clear-recent',
        kind: 'system',
        label: 'Очистить недавние',
        sublabel: 'История просмотров',
        iconId: 'trash',
        route: null,
        system: 'clear-recent'
      },
      null
    );
  }

  if (needle !== '') {
    for (const page of searchNav(sections, query)) {
      const route = routePath(page.route.replace(/^#/, ''));
      add(
        {
          id: `page:${page.id}`,
          kind: 'page',
          label: page.label,
          sublabel: page.path.join(' › '),
          iconId: page.iconId,
          route
        },
        route
      );
    }
  }

  for (const action of quickActions(context)) {
    if (needle !== '' && !matches(action.label, needle)) {
      continue;
    }
    add(
      {
        id: `action:${action.id}`,
        kind: 'action',
        label: action.label,
        sublabel: 'Действие',
        iconId: action.iconId,
        route: action.route,
        actionId: action.id
      },
      `action:${action.id}`
    );
  }

  return results;
}

/** Повторная проверка непосредственно перед выполнением результата. */
export function canRunResult(result: PaletteResult, context: QuickActionContext, allowed: Set<string>): boolean {
  if (result.route === null) {
    return result.system === 'clear-recent';
  }
  // Действия проверяются своим провайдером: их формы не являются узлами visibleNav.
  if (result.actionId !== undefined) {
    return canRunQuickAction(result.actionId, context);
  }
  return allowed.has(routePath(result.route));
}
