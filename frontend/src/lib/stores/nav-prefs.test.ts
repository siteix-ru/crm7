import { beforeEach, describe, expect, it } from 'vitest';
import { navPrefs } from './nav-prefs.svelte';

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

describe('Phase 4 personalization', () => {
  it('uses safe defaults for missing or corrupted preferences', () => {
    storage.set('crm.nav2.prefs.7', '{broken');
    navPrefs.setUser(7);
    expect(navPrefs.railExpanded).toBe(false);
    expect(navPrefs.density).toBe('comfortable');
  });

  it('stores rail and density settings per user', () => {
    navPrefs.setUser(7);
    navPrefs.setRailExpanded(true);
    navPrefs.setDensity('compact');
    expect(JSON.parse(storage.get('crm.nav2.prefs.7') ?? '{}')).toEqual({
      railExpanded: true,
      density: 'compact'
    });

    navPrefs.setUser(8);
    expect(navPrefs.railExpanded).toBe(false);
    expect(navPrefs.density).toBe('comfortable');

    navPrefs.setUser(7);
    expect(navPrefs.railExpanded).toBe(true);
    expect(navPrefs.density).toBe('compact');
  });

  it('toggles density and keeps the value within the supported set', () => {
    navPrefs.setUser(9);
    navPrefs.toggleDensity();
    expect(navPrefs.density).toBe('compact');
    navPrefs.toggleDensity();
    expect(navPrefs.density).toBe('comfortable');
  });

  it('does not keep settings for a signed-out user', () => {
    navPrefs.setUser(10);
    navPrefs.setDensity('compact');
    navPrefs.setUser(null);
    expect(navPrefs.density).toBe('comfortable');
    expect(navPrefs.railExpanded).toBe(false);
  });
});
