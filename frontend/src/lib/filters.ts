import { filterStorageKey, filterPrefs } from './stores/filter-prefs.svelte';
import { auth } from './stores/auth.svelte';

function keyFor(key: string): string {
  return filterStorageKey(key, auth.user?.id ?? null);
}

export function loadFilters<T extends Record<string, unknown>>(key: string, defaults: T): T {
  try {
    const raw = localStorage.getItem(keyFor(key));

    if (raw === null) {
      return { ...defaults };
    }

    const parsed: unknown = JSON.parse(raw);

    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return { ...defaults };
    }

    const result: Record<string, unknown> = { ...defaults };

    for (const field of Object.keys(defaults)) {
      const value = (parsed as Record<string, unknown>)[field];

      if (value === undefined || value === null) {
        continue;
      }

      if (typeof value === typeof defaults[field]) {
        result[field] = value;
      }
    }

    return result as T;
  } catch {
    return { ...defaults };
  }
}

export function saveFilters(key: string, value: Record<string, unknown>): void {
  try {
    localStorage.setItem(keyFor(key), JSON.stringify(value));
  } catch {
    // приватный режим - молча пропускаем
  }

  filterPrefs.write(key, value);
}

export function clearFilters(key: string): void {
  try {
    localStorage.removeItem(keyFor(key));
  } catch {
    // приватный режим - молча пропускаем
  }

  filterPrefs.remove(key);
}

export function countActive<T extends Record<string, unknown>>(value: T, defaults: T): number {
  return Object.keys(defaults).filter(
    (field) => String(value[field] ?? '') !== String(defaults[field] ?? '')
  ).length;
}
