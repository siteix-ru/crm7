import { describe, expect, it } from 'vitest';
import {
  canRunQuickAction,
  quickActions,
  type QuickActionContext
} from './quick-actions';

function context(overrides: Partial<QuickActionContext> = {}): QuickActionContext {
  return {
    navIds: new Set(['requests', 'chat', 'settings']),
    capabilities: ['requests.create', 'chat.use', 'settings.manage'],
    requestsEnabled: true,
    salesEnabled: true,
    chatEnabled: true,
    ...overrides
  };
}

describe('Phase 2C.2 Quick Actions availability', () => {
  it('exposes only the three safe navigation commands', () => {
    expect(quickActions(context()).map((action) => action.id)).toEqual([
      'new-request',
      'open-chat',
      'open-settings'
    ]);
    expect(quickActions(context()).map((action) => action.route)).toEqual([
      '/requests/new',
      '/chat',
      '/settings'
    ]);
  });

  it('hides actions without the required capability', () => {
    expect(quickActions(context({ capabilities: [] }))).toEqual([]);
    expect(
      quickActions(context({ capabilities: ['requests.create'] })).map((action) => action.id)
    ).toEqual(['new-request']);
    expect(
      quickActions(context({ capabilities: ['chat.use'] })).map((action) => action.id)
    ).toEqual(['open-chat']);
    expect(
      quickActions(context({ capabilities: ['mail.send'] })).map((action) => action.id)
    ).toEqual(['open-settings']);
  });

  it('hides actions whose section is absent from the current visibleNav', () => {
    expect(
      quickActions(context({ navIds: new Set(['chat']) })).map((action) => action.id)
    ).toEqual(['open-chat']);
    expect(quickActions(context({ navIds: new Set() }))).toEqual([]);
  });

  it('respects disabled modules and never invents routes', () => {
    const disabled = quickActions(
      context({ requestsEnabled: false, salesEnabled: false, chatEnabled: false })
    );
    expect(disabled.map((action) => action.id)).toEqual(['open-settings']);
    for (const action of quickActions(context())) {
      expect(action.route.startsWith('/')).toBe(true);
    }
  });

  it('re-checks permission immediately before execution', () => {
    const allowed = context();
    expect(canRunQuickAction('new-request', allowed)).toBe(true);

    const revoked = context({ capabilities: [] });
    expect(canRunQuickAction('new-request', revoked)).toBe(false);
    const moduleOff = context({ salesEnabled: false });
    expect(canRunQuickAction('new-request', moduleOff)).toBe(false);
    const navGone = context({ navIds: new Set(['chat', 'settings']) });
    expect(canRunQuickAction('new-request', navGone)).toBe(false);
    expect(canRunQuickAction('open-settings' as never, context())).toBe(true);
    expect(canRunQuickAction('unknown' as never, allowed)).toBe(false);
  });

  it('matches the demo role matrix', () => {
    const root = context();
    const manager = context({
      navIds: new Set(['requests', 'chat']),
      capabilities: ['requests.create', 'chat.use']
    });
    const client = context({
      navIds: new Set(['about', 'notifications', 'cart', 'requests', 'chat']),
      capabilities: ['chat.use', 'requests.create']
    });

    expect(quickActions(root)).toHaveLength(3);
    expect(quickActions(manager).map((action) => action.id)).toEqual(['new-request', 'open-chat']);
    expect(quickActions(client).map((action) => action.id)).toEqual(['new-request', 'open-chat']);
  });
});
