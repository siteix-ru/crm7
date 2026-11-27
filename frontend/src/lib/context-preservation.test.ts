import { describe, expect, it } from 'vitest';
import router from './router.svelte.ts?raw';
import auth from './stores/auth.svelte.ts?raw';
import shell from './components/layout/AppShell.svelte?raw';
import requests from '../views/Requests.svelte?raw';
import notifications from '../views/Notifications.svelte?raw';
import requestCard from '../views/RequestCard.svelte?raw';
import clientCard from '../views/ClientCard.svelte?raw';
import requestNew from '../views/RequestNew.svelte?raw';
import filters from './filters.ts?raw';

describe('Phase 2C.1 context preservation contract', () => {
  it('restores scroll through history.state without adding history entries', () => {
    expect(router).toContain("history.scrollRestoration = 'manual'");
    expect(router).toContain("window.history.replaceState({ ...state, crmNavId: id }, '')");
    expect(router).toContain("window.history.replaceState({ ...state, crmScroll: position }, '')");
    expect(router).toContain('window.scrollY');
    expect(router).toContain('window.scrollTo(0, position)');
    expect(router).toContain("'scroll',");
    expect(router).not.toContain('history.pushState');
  });

  it('keeps the safe route allowlist for history without a topbar back control', () => {
    expect(shell).toContain('allowedRoutesFromNav(appSettings.visibleNav)');
    expect(shell).not.toContain('class="back-button"');
    expect(shell).not.toContain('router.navigate(backTarget)');
  });

  it('passes returnTo from real list and card transitions', () => {
    expect(requests).toContain('withReturnTo(');
    expect(notifications).toContain('withReturnTo(');
    expect(requestCard).toContain('withReturnTo(');
    expect(clientCard).toContain('withReturnTo(');
  });

  it('encodes active card tabs in the URL without duplicating history', () => {
    expect(requestCard).toContain("router.current.query.get('tab')");
    expect(requestCard).toContain('function syncTabUrl');
    expect(requestCard).toContain("router.navigate(`${router.current.path}?${params.toString()}`, true)");
    expect(clientCard).toContain("router.current.query.get('tab')");
    expect(clientCard).toContain('function syncTabUrl');
  });

  it('persists notification filters and reuses existing server drafts', () => {
    expect(notifications).toContain("loadFilters('notifications'");
    expect(notifications).toContain("saveFilters('notifications'");
    expect(requestNew).toContain('pagehide');
    expect(requestNew).toContain('flushDraft');
    expect(requestNew).toContain('?draft=');
  });

  it('isolates persisted state by user and clears it on logout', () => {
    expect(filters).toContain('filterStorageKey(');
    expect(filters).toContain('auth.user?.id');
    expect(auth).toContain('clearLocalNavigationState(userId)');
    expect(auth).toContain('filterPrefs.cancelPending()');
    expect(auth).toContain('clearHistory()');
  });
});
