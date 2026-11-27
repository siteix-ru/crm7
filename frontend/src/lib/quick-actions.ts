/**
 * Phase 2C.2 — Quick Actions.
 *
 * Только безопасные навигационные команды: переход к уже существующей форме
 * или разделу. Никаких мутаций, сущностного поиска и новых прав.
 * Источник доступности — актуальный visibleNav + capabilities + module flags.
 */

export type QuickActionId = 'new-request' | 'open-chat' | 'open-settings';

export interface QuickAction {
  id: QuickActionId;
  label: string;
  iconId: string;
  route: string;
}

export interface QuickActionContext {
  navIds: Set<string>;
  capabilities: string[];
  requestsEnabled: boolean;
  salesEnabled: boolean;
  chatEnabled: boolean;
}

const SETTINGS_CAPABILITIES = ['settings.manage', 'mail.manage', 'mail.send', 'messengers.manage'];

const DEFINITIONS: QuickAction[] = [
  { id: 'new-request', label: 'Новая заявка', iconId: 'requests', route: '/requests/new' },
  { id: 'open-chat', label: 'Открыть чат', iconId: 'chat', route: '/chat' },
  { id: 'open-settings', label: 'Открыть настройки', iconId: 'settings', route: '/settings' }
];

function allowed(action: QuickAction, context: QuickActionContext): boolean {
  const has = (capability: string): boolean => context.capabilities.includes(capability);

  switch (action.id) {
    case 'new-request':
      return (
        context.navIds.has('requests') &&
        context.requestsEnabled &&
        context.salesEnabled &&
        has('requests.create')
      );
    case 'open-chat':
      return context.navIds.has('chat') && context.chatEnabled && has('chat.use');
    case 'open-settings':
      return context.navIds.has('settings') && SETTINGS_CAPABILITIES.some(has);
    default:
      return false;
  }
}

export function quickActions(context: QuickActionContext): QuickAction[] {
  return DEFINITIONS.filter((action) => allowed(action, context));
}

/** Повторная проверка непосредственно перед выполнением: клиентское состояние — не авторизация. */
export function canRunQuickAction(id: QuickActionId, context: QuickActionContext): boolean {
  const action = DEFINITIONS.find((item) => item.id === id);
  return action !== undefined && allowed(action, context);
}
