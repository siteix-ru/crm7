import { untrack } from 'svelte';
import { internalRoute, routePath } from '../navigation-context';

export interface RecentPage {
  path: string;
  title: string;
  visitedAt: number;
}

const PREFIX = 'crm.recent.';
const LIMIT = 5;
const TTL_MS = 30 * 24 * 60 * 60 * 1000;

/** Маршруты, которые не являются реальными страницами для истории. */
function isRecordable(path: string): boolean {
  return (
    path !== '/requests/new' &&
    !path.startsWith('/legal/') &&
    path !== '/reset' &&
    path !== '/login' &&
    path !== '/register' &&
    path !== '/forgot'
  );
}

class RecentPagesStore {
  entries = $state<RecentPage[]>([]);

  private userId: number | null = null;

  private key(): string {
    return this.userId === null ? `${PREFIX}guest` : `${PREFIX}${this.userId}`;
  }

  setUser(userId: number | null, allowed: Set<string>, titles: Map<string, string>): void {
    this.userId = userId;
    this.entries = this.read(allowed, titles);
  }

  private read(allowed: Set<string>, titles: Map<string, string>): RecentPage[] {
    let raw: string | null = null;
    try {
      raw = localStorage.getItem(this.key());
    } catch {
      return [];
    }
    if (raw === null) {
      return [];
    }

    try {
      const parsed: unknown = JSON.parse(raw);
      if (!Array.isArray(parsed)) {
        return [];
      }
      const now = Date.now();
      const seen = new Set<string>();
      const entries: RecentPage[] = [];

      for (const item of parsed) {
        if (item === null || typeof item !== 'object') {
          continue;
        }
        const path = (item as { path?: unknown }).path;
        const visitedAt = (item as { visitedAt?: unknown }).visitedAt;
        if (
          typeof path !== 'string' ||
          typeof visitedAt !== 'number' ||
          !Number.isFinite(visitedAt) ||
          now - visitedAt > TTL_MS ||
          seen.has(path)
        ) {
          continue;
        }
        const normalized = internalRoute(path);
        const route = normalized === null ? null : routePath(normalized);
        if (route === null || !allowed.has(route) || !isRecordable(route)) {
          continue;
        }
        seen.add(route);
        entries.push({
          path: route,
          title: titles.get(route) ?? route,
          visitedAt
        });
      }

      const result = entries.slice(0, LIMIT);
      this.persist(result);
      return result;
    } catch {
      return [];
    }
  }

  private persist(entries: RecentPage[]): void {
    try {
      localStorage.setItem(this.key(), JSON.stringify(entries));
    } catch {
      // приватный режим — история не сохраняется
    }
  }

  visit(path: string, allowed: Set<string>, titles: Map<string, string>): void {
    // Вызывается из $effect в AppShell: чтение entries внутри не должно
    // становиться зависимостью эффекта, иначе запись entries вызовет цикл.
    untrack(() => {
      const normalized = internalRoute(path);
      if (normalized === null) {
        return;
      }
      const route = routePath(normalized);
      if (!allowed.has(route) || !isRecordable(route)) {
        return;
      }

      const now = Date.now();
      const next = this.entries
        .filter((item) => item.path !== route && now - item.visitedAt <= TTL_MS)
        .map((item) => ({ ...item, title: titles.get(item.path) ?? item.title }));

      next.unshift({ path: route, title: titles.get(route) ?? route, visitedAt: now });
      const limited = next.slice(0, LIMIT);
      this.entries = limited;
      this.persist(limited);
    });
  }

  clear(): void {
    this.entries = [];
    try {
      localStorage.removeItem(this.key());
    } catch {
      // приватный режим
    }
  }

  clearUser(userId: number): void {
    try {
      localStorage.removeItem(`${PREFIX}${userId}`);
    } catch {
      // приватный режим
    }
    if (this.userId === userId) {
      this.entries = [];
    }
  }
}

export const recentPages = new RecentPagesStore();
