import { beforeEach, describe, expect, it } from 'vitest';
import {
  allowedRoutesFromNav,
  clearLocalNavigationState,
  fallbackRoute,
  internalRoute,
  normalizeReturnTo,
  withReturnTo
} from './navigation-context';

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

describe('Phase 2C.1 safe internal routes', () => {
  it('accepts internal hash and plain routes with safe query', () => {
    expect(internalRoute('/requests')).toBe('/requests');
    expect(internalRoute('#/requests?scope=mine&page=2')).toBe('/requests?scope=mine&page=2');
    expect(internalRoute('  /clients/7  ')).toBe('/clients/7');
  });

  it('rejects external URLs, unsafe schemes, malformed paths and oversized values', () => {
    for (const value of [
      'https://example.com',
      '//evil.example',
      'javascript:alert(1)',
      'data:text/html,x',
      '/requests\\evil',
      '/requests\n',
      '/requests?a=<script>',
      `/${'a'.repeat(200)}`,
      '',
      42
    ]) {
      expect(internalRoute(value)).toBeNull();
    }
  });

  it('validates returnTo against an allowlist and rejects cycles', () => {
    const allowed = new Set(['/requests', '/clients', '/requests/new']);
    expect(normalizeReturnTo('#/requests?scope=mine', allowed, '/requests/42')).toBe('/requests?scope=mine');
    expect(normalizeReturnTo('/clients/7', allowed, '/requests')).toBeNull();
    expect(normalizeReturnTo('/requests', allowed, '/requests')).toBeNull();
    expect(normalizeReturnTo('https://evil.test/', allowed, '/requests')).toBeNull();
    expect(normalizeReturnTo('/requests', null, '/requests/42')).toBe('/requests');
  });

  it('builds an allowlist from visibleNav and picks a safe fallback', () => {
    const nodes = [{ route: '#/requests' }, { route: '#/stocks' }, { route: 'javascript:alert(1)' }];
    const allowed = allowedRoutesFromNav(nodes);
    expect(allowed.has('/requests')).toBe(true);
    expect(allowed.has('/stocks')).toBe(true);
    expect(allowed.has('javascript:alert(1)')).toBe(false);
    expect(allowed.has('/profile')).toBe(true);
    expect(fallbackRoute(new Set(['/stocks', '/profile']))).toBe('/stocks');
    expect(fallbackRoute(new Set(['/profile']))).toBeNull();
  });

  it('attaches returnTo without inventing routes', () => {
    expect(withReturnTo('/requests/42', '/requests?scope=mine&page=2')).toBe(
      '/requests/42?returnTo=%2Frequests%3Fscope%3Dmine%26page%3D2'
    );
    expect(withReturnTo('/requests/42?tab=history', '/requests')).toBe(
      '/requests/42?tab=history&returnTo=%2Frequests'
    );
    expect(withReturnTo('javascript:alert(1)', '/requests')).toBe('javascript:alert(1)');
  });

  it('clears only the current user navigation state on logout', () => {
    storage.set('crm.nav2.accordion.7', '{}');
    storage.set('crm.nav2.rail.expanded', '1');
    storage.set('crm.nav2.prefs.7', '{}');
    storage.set('crm.filters.7.requests', '{}');
    storage.set('crm.filters.8.requests', '{}');
    storage.set('crm_token', 'secret');

    clearLocalNavigationState(7);

    expect([...storage.keys()]).toEqual(['crm.filters.8.requests', 'crm_token']);
  });

  it('does not store secrets in navigation state', () => {
    const route = withReturnTo('/requests/42', '/requests?q=password%3Dsecret&token=abc');
    expect(route).not.toContain('secret');
    expect(route).not.toContain('token');
    expect(internalRoute('/requests?password=123')).toBeNull();
  });
});
