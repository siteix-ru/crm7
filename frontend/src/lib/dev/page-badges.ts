import { matchRoute } from '../match-route';

export interface PageBadge {
  id: string;
  task: string;
}

export interface ResolvedPageBadge extends PageBadge {
  route: string;
}

export const pageBadges: Record<string, PageBadge> = {
  '/': { id: 'AUTH-LOGIN', task: '' },
  '/register': { id: 'AUTH-REGISTER', task: '' },
  '/forgot': { id: 'AUTH-FORGOT', task: '' },
  '/reset': { id: 'AUTH-RESET', task: '' },
  '/requests': { id: 'REQ-LIST', task: '' },
  '/requests/new': { id: 'REQ-NEW', task: '' },
  '/requests/:id': { id: 'REQ-CARD', task: '' },
  '/cart': { id: 'CART', task: '' },
  '/clients': { id: 'CLI-LIST', task: '' },
  '/clients/new': { id: 'CLI-NEW', task: '' },
  '/clients/:id': { id: 'CLI-CARD', task: '' },
  '/staff': { id: 'STAFF-LIST', task: '' },
  '/staff/new': { id: 'STAFF-NEW', task: '' },
  '/staff/:id': { id: 'STAFF-CARD', task: '' },
  '/staff/substitutions/new': { id: 'STAFF-SUBST-NEW', task: '' },
  '/staff/substitutions/:id': { id: 'STAFF-SUBST-EDIT', task: '' },
  '/chat': { id: 'CHAT', task: '' },
  '/stocks': { id: 'STK-LIST', task: '' },
  '/stocks/groups': { id: 'STK-GROUPS', task: '' },
  '/stocks/groups/new': { id: 'STK-GROUP-NEW', task: '' },
  '/stocks/groups/:id': { id: 'STK-GROUP-EDIT', task: '' },
  '/stocks/types': { id: 'STK-TYPES', task: '' },
  '/stocks/types/new': { id: 'STK-TYPE-NEW', task: '' },
  '/stocks/types/:id': { id: 'STK-TYPE-EDIT', task: '' },
  '/stocks/price-types': { id: 'STK-PRICE-TYPES', task: '' },
  '/stocks/price-types/new': { id: 'STK-PRICE-TYPE-NEW', task: '' },
  '/stocks/price-types/:id': { id: 'STK-PRICE-TYPE-EDIT', task: '' },
  '/stocks/items/new': { id: 'STK-ITEM-NEW', task: '' },
  '/stocks/items/:id/edit': { id: 'STK-ITEM-EDIT', task: '' },
  '/stocks/items/:id': { id: 'STK-ITEM-CARD', task: '' },
  '/stocks/warehouses': { id: 'WH-LIST', task: '' },
  '/stocks/warehouses/new': { id: 'WH-NEW', task: '' },
  '/stocks/warehouses/:id/edit': { id: 'WH-EDIT', task: '' },
  '/stocks/warehouses/:id': { id: 'WH-VIEW', task: '' },
  '/reports': { id: 'REP-LIST', task: '' },
  '/my-reports': { id: 'REP-MY', task: '' },
  '/substitutions': { id: 'STAFF-SUBST', task: '' },
  '/notifications': { id: 'NOTIFY', task: '' },
  '/users/:id': { id: 'USER-PROFILE', task: '' },
  '/legal/:code': { id: 'LEGAL', task: '' },
  '/admin/audit': { id: 'AUDIT-LOG', task: '' },
  '/profile': { id: 'PROFILE', task: '' },
  '/about': { id: 'ABOUT', task: '' }
};

function resolve(route: string, badge: PageBadge): ResolvedPageBadge | null {
  const id = badge.id.trim();

  if (!id) return null;

  return { route, id, task: badge.task.trim() };
}

export function settingsBadgeId(tab: string, sub = ''): string {
  const normalize = (value: string): string =>
    value
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');

  const section = normalize(tab);
  const suffix = sub ? `-${normalize(sub)}` : '';

  return `SETTINGS-${section}${suffix}`;
}

export function badgeForRoute(
  path: string,
  badges: Record<string, PageBadge> = pageBadges
): ResolvedPageBadge | null {
  const exact = badges[path];

  if (exact) return resolve(path, exact);

  for (const [pattern, badge] of Object.entries(badges)) {
    if (pattern.includes(':') && matchRoute(pattern, path)) {
      return resolve(pattern, badge);
    }
  }

  return null;
}
