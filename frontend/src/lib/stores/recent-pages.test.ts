import { beforeEach, describe, expect, it } from 'vitest';
import { recentPages, type RecentPage } from './recent-pages.svelte';
import source from './recent-pages.svelte.ts?raw';

const storage = new Map<string, string>();

beforeEach(() => {
  storage.clear();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
      key: (index: number) => [...storage.keys()][index] ?? null,
      get length() {
        return storage.size;
      }
    }
  });
});

const ALLOWED = new Set(['/requests', '/chat', '/clients', '/stocks', '/reports', '/about']);
const TITLES = new Map([
  ['/requests', 'Заявки'],
  ['/chat', 'Чат'],
  ['/clients', 'Клиенты']
]);

function seed(userId: number, entries: RecentPage[]): void {
  storage.set(`crm.recent.${userId}`, JSON.stringify(entries));
}

describe('Phase 3 Recently visited', () => {
  it('does not expose entries as a dependency of the visiting effect', () => {
    expect(source).toContain("import { untrack } from 'svelte'");
    expect(source).toContain('untrack(() => {');
  });

  it('isolates history by user id', () => {
    seed(1, [{ path: '/requests', title: 'Заявки', visitedAt: Date.now() }]);
    seed(2, [{ path: '/chat', title: 'Чат', visitedAt: Date.now() }]);

    recentPages.setUser(1, ALLOWED, TITLES);
    expect(recentPages.entries.map((item) => item.path)).toEqual(['/requests']);
    recentPages.setUser(2, ALLOWED, TITLES);
    expect(recentPages.entries.map((item) => item.path)).toEqual(['/chat']);
  });

  it('records only real, allowed routes without query parameters', () => {
    recentPages.setUser(3, ALLOWED, TITLES);
    recentPages.visit('/requests?token=abc&password=1', ALLOWED, TITLES);
    recentPages.visit('https://evil.test/', ALLOWED, TITLES);
    recentPages.visit('/requests/new', ALLOWED, TITLES);
    recentPages.visit('/admin/audit', ALLOWED, TITLES);
    recentPages.visit('/clients', ALLOWED, TITLES);

    expect(recentPages.entries.map((item) => item.path)).toEqual(['/clients']);
    expect(JSON.stringify(storage.get('crm.recent.3'))).not.toContain('token');
    expect(JSON.stringify(storage.get('crm.recent.3'))).not.toContain('password');
  });

  it('moves an existing visit to the front and keeps at most five', () => {
    recentPages.setUser(4, ALLOWED, TITLES);
    for (const path of ['/requests', '/chat', '/clients', '/stocks', '/reports', '/about']) {
      recentPages.visit(path, ALLOWED, TITLES);
    }
    expect(recentPages.entries).toHaveLength(5);
    expect(recentPages.entries[0].path).toBe('/about');

    recentPages.visit('/requests', ALLOWED, TITLES);
    expect(recentPages.entries).toHaveLength(5);
    expect(recentPages.entries[0].path).toBe('/requests');
    expect(recentPages.entries.filter((item) => item.path === '/requests')).toHaveLength(1);
  });

  it('drops expired and inaccessible entries on load', () => {
    seed(5, [
      { path: '/requests', title: 'Заявки', visitedAt: Date.now() - 31 * 24 * 60 * 60 * 1000 },
      { path: '/admin/audit', title: 'Журнал', visitedAt: Date.now() },
      { path: '/chat?secret=1', title: 'Чат', visitedAt: Date.now() },
      { path: '/chat', title: 'Чат', visitedAt: Date.now() }
    ]);

    recentPages.setUser(5, ALLOWED, TITLES);
    expect(recentPages.entries.map((item) => item.path)).toEqual(['/chat']);
    expect(JSON.stringify(storage.get('crm.recent.5'))).not.toContain('secret');
  });

  it('drops entries when visibleNav/capabilities shrink during an open session', () => {
    recentPages.setUser(11, ALLOWED, TITLES);
    recentPages.visit('/requests', ALLOWED, TITLES);
    recentPages.visit('/clients', ALLOWED, TITLES);

    recentPages.setUser(11, new Set(['/requests']), TITLES);
    expect(recentPages.entries.map((item) => item.path)).toEqual(['/requests']);
  });

  it('survives corrupted storage and clears on logout', () => {
    storage.set('crm.recent.6', '{broken');
    recentPages.setUser(6, ALLOWED, TITLES);
    expect(recentPages.entries).toEqual([]);

    recentPages.visit('/requests', ALLOWED, TITLES);
    expect(recentPages.entries).toHaveLength(1);
    recentPages.clear();
    expect(recentPages.entries).toEqual([]);
    expect(storage.has('crm.recent.6')).toBe(false);
  });
});
