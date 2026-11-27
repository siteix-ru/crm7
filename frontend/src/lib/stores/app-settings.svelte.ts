import { apiRequest } from '../api/client';
import { auth } from './auth.svelte';

export interface NavNode {
  id: string;
  label: string;
  iconId: string;
  group: string;
  route: string;
  capabilities: string[];
  presentation: { rail: boolean; topbar: boolean; bottom: boolean; drawer: boolean };
  order: number;
  type: string;
  badge?: { source: string };
  parent?: string;
}

interface AppSettingsResponse {
  visibleNav?: unknown;
  sales_enabled: boolean;
  email_export_enabled: boolean;
  mail_configured: boolean;
  mail_enabled: boolean;
  stock_reserve_enabled: boolean;
  stock_allow_zero: boolean;
  prices_enabled: boolean;
  groups_enabled: boolean;
  prices_configured: boolean;
  groups_configured: boolean;
  requests_enabled: boolean;
  clients_enabled: boolean;
  chat_enabled: boolean;
  notifications_enabled: boolean;
  staff_enabled: boolean;
  registration_enabled: boolean;
  stock_edit_enabled: boolean;
  lots_enabled: boolean;
  cart_enabled: boolean;
  substitutions_enabled: boolean;
  manager_assign_enabled: boolean;
  warehouses_enabled: boolean;
  reports_enabled: boolean;
  single_name: string;
  app_title: string;
  app_description: string;
  photo_ratio: string;
  photo_fit: string;
  demo_mode: boolean;
}

const REQUIRED_BOOLEAN_SETTINGS = [
  'sales_enabled',
  'email_export_enabled',
  'mail_configured',
  'mail_enabled',
  'stock_reserve_enabled',
  'stock_allow_zero',
  'prices_enabled',
  'groups_enabled',
  'prices_configured',
  'groups_configured',
  'requests_enabled',
  'clients_enabled',
  'chat_enabled',
  'notifications_enabled',
  'staff_enabled',
  'registration_enabled',
  'stock_edit_enabled',
  'lots_enabled',
  'cart_enabled',
  'substitutions_enabled',
  'manager_assign_enabled',
  'warehouses_enabled',
  'reports_enabled',
  'demo_mode'
] as const;

function normalizeNav(value: unknown): NavNode[] | null {
  if (!Array.isArray(value) || value.length === 0) {
    return null;
  }

  const nodes = value
    .filter((item): item is Record<string, unknown> => item !== null && typeof item === 'object')
    .filter((item) =>
      typeof item.id === 'string' && item.id !== '' &&
      typeof item.label === 'string' && item.label !== '' &&
      typeof item.iconId === 'string' && item.iconId !== '' &&
      typeof item.group === 'string' && item.group !== '' &&
      typeof item.route === 'string' && item.route.startsWith('#/') &&
      Array.isArray(item.capabilities) &&
      item.presentation !== null && typeof item.presentation === 'object' &&
      typeof item.order === 'number' &&
      typeof item.type === 'string'
    )
    .map((item): NavNode => ({
      id: item.id as string,
      label: item.label as string,
      iconId: item.iconId as string,
      group: item.group as string,
      route: item.route as string,
      capabilities: (item.capabilities as unknown[]).filter((c): c is string => typeof c === 'string'),
      presentation: item.presentation as NavNode['presentation'],
      order: item.order as number,
      type: item.type as string,
      ...(item.badge !== undefined ? { badge: item.badge as NavNode['badge'] } : {}),
      ...(typeof item.parent === 'string' ? { parent: item.parent } : {})
    }));

  return nodes.length > 0 ? nodes : null;
}

class AppSettingsStore {
  // Пока настройки не подтверждены сервером, функциональность закрыта.
  salesEnabled = $state(false);

  emailExportEnabled = $state(false);

  mailConfigured = $state(false);

  mailEnabled = $state(false);

  stockReserveEnabled = $state(false);

  allowZeroStock = $state(false);

  pricesEnabled = $state(false);

  groupsEnabled = $state(false);

  pricesConfigured = $state(false);

  groupsConfigured = $state(false);

  requestsEnabled = $state(true);

  clientsEnabled = $state(true);

  chatEnabled = $state(true);

  notificationsEnabled = $state(false);

  staffEnabled = $state(false);

  registrationEnabled = $state(false);

  stockEditEnabled = $state(false);

  lotsEnabled = $state(true);

  cartEnabled = $state(false);

  substitutionsEnabled = $state(false);

  managerAssignEnabled = $state(false);

  warehousesEnabled = $state(false);

  reportsEnabled = $state(false);

  visibleNav = $state<NavNode[]>([]);

  navError = $state<string | null>(null);

  singleName = $state('Основной склад');

  appTitle = $state('CRM7');

  appDescription = $state('');

  photoRatio = $state('square');

  photoFit = $state('contain');

  demoMode = $state(false);

  loadedFor = $state<number | null>(null);

  loading = $state(false);

  error = $state<string | null>(null);

  private attemptedFor: number | null = null;

  get loaded(): boolean {
    return this.loadedFor !== null;
  }

  get photoAspect(): string {
    return this.photoRatio === 'landscape'
      ? '4 / 3'
      : this.photoRatio === 'portrait'
        ? '3 / 4'
        : this.photoRatio === 'square'
          ? '1 / 1'
          : 'auto';
  }

  get photoFactor(): number {
    return this.photoRatio === 'landscape' ? 4 / 3 : this.photoRatio === 'portrait' ? 3 / 4 : 1;
  }

  async load(): Promise<void> {
    const userId = auth.user?.id ?? null;

    if (userId === null || this.loading) {
      return;
    }

    // После ошибки не перезагружаем автоматически в бесконечном цикле —
    // повтор инициирует пользователь через retry().
    if (this.attemptedFor === userId && this.loadedFor !== userId) {
      return;
    }

    this.attemptedFor = userId;
    this.loading = true;
    this.error = null;

    let timedOut = false;
    const controller = new AbortController();
    const timeout = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, 5000);

    try {
      const data = await apiRequest<AppSettingsResponse>('/settings/app', {
        auth: true,
        signal: controller.signal
      });

      if (data === null || typeof data !== 'object'
        || REQUIRED_BOOLEAN_SETTINGS.some((key) => typeof data[key] !== 'boolean')) {
        throw new Error('Некорректный ответ настроек приложения');
      }

      this.salesEnabled = data.sales_enabled;
      this.emailExportEnabled = data.email_export_enabled;
      this.mailConfigured = data.mail_configured;
      this.mailEnabled = data.mail_enabled;
      this.stockReserveEnabled = data.stock_reserve_enabled;
      this.allowZeroStock = data.stock_allow_zero;
      this.pricesEnabled = data.prices_enabled;
      this.groupsEnabled = data.groups_enabled;
      this.pricesConfigured = data.prices_configured;
      this.groupsConfigured = data.groups_configured;
      this.requestsEnabled = data.requests_enabled;
      this.clientsEnabled = data.clients_enabled;
      this.chatEnabled = data.chat_enabled;
      this.notificationsEnabled = data.notifications_enabled;
      this.staffEnabled = data.staff_enabled;
      this.registrationEnabled = data.registration_enabled;
      this.stockEditEnabled = data.stock_edit_enabled;
      this.lotsEnabled = data.lots_enabled !== false;
      this.cartEnabled = data.cart_enabled;
      this.substitutionsEnabled = data.substitutions_enabled;
      this.managerAssignEnabled = data.manager_assign_enabled;
      this.warehousesEnabled = data.warehouses_enabled;
      this.reportsEnabled = data.reports_enabled;

      const nav = normalizeNav(data.visibleNav);
      this.visibleNav = nav ?? [];
      this.navError = nav === null ? 'Навигация недоступна. Повторите попытку.' : null;

      this.singleName = (data.single_name || 'Основной склад').trim() || 'Основной склад';
      this.appTitle = (data.app_title || 'CRM7').trim() || 'CRM7';
      this.appDescription = (data.app_description || '').trim();
      this.photoRatio = ['dynamic', 'square', 'landscape', 'portrait'].includes(data.photo_ratio)
        ? data.photo_ratio
        : 'square';
      this.photoFit = data.photo_fit === 'cover' ? 'cover' : 'contain';
      this.demoMode = data.demo_mode;
      this.loadedFor = userId;
      this.error = null;
    } catch (cause) {
      // Не отмечаем настройки загруженными: boot gate остаётся закрытым,
      // а значения functional-флагов остаются разрешающими только после
      // успешной проверки ответа.
      this.loadedFor = null;
      this.error = timedOut
        ? 'Не удалось загрузить настройки: таймаут. Повторите попытку.'
        : cause instanceof Error && cause.message
          ? `Не удалось загрузить настройки: ${cause.message}`
          : 'Не удалось загрузить настройки. Повторите попытку.';
      this.visibleNav = [];
      this.navError = this.error;
    } finally {
      clearTimeout(timeout);
      this.loading = false;
    }
  }

  retry(): Promise<void> {
    this.attemptedFor = null;

    return this.load();
  }

  set(
    salesEnabled: boolean,
    emailExportEnabled = this.emailExportEnabled,
    mailConfigured = this.mailConfigured,
    stockReserveEnabled = this.stockReserveEnabled,
    allowZeroStock = this.allowZeroStock,
    pricesEnabled = this.pricesEnabled,
    groupsEnabled = this.groupsEnabled,
    requestsEnabled = this.requestsEnabled,
    substitutionsEnabled = this.substitutionsEnabled,
    managerAssignEnabled = this.managerAssignEnabled,
    warehousesEnabled = this.warehousesEnabled,
    singleName = this.singleName,
    appTitle = this.appTitle,
    appDescription = this.appDescription,
    photoRatio = this.photoRatio,
    photoFit = this.photoFit
  ): void {
    this.salesEnabled = salesEnabled;
    this.emailExportEnabled = emailExportEnabled;
    this.mailConfigured = mailConfigured;
    this.stockReserveEnabled = stockReserveEnabled;
    this.allowZeroStock = allowZeroStock;
    this.pricesEnabled = pricesEnabled;
    this.groupsEnabled = groupsEnabled;
    this.requestsEnabled = requestsEnabled;
    this.cartEnabled = requestsEnabled;
    this.substitutionsEnabled = substitutionsEnabled;
    this.managerAssignEnabled = managerAssignEnabled;
    this.warehousesEnabled = warehousesEnabled;
    this.singleName = singleName;
    this.appTitle = appTitle;
    this.appDescription = appDescription;
    this.photoRatio = photoRatio;
    this.photoFit = photoFit;
  }

  reset(): void {
    this.salesEnabled = false;
    this.emailExportEnabled = false;
    this.mailConfigured = false;
    this.mailEnabled = false;
    this.stockReserveEnabled = false;
    this.allowZeroStock = false;
    this.pricesEnabled = false;
    this.groupsEnabled = false;
    this.pricesConfigured = false;
    this.groupsConfigured = false;
    this.requestsEnabled = false;
    this.clientsEnabled = false;
    this.chatEnabled = false;
    this.notificationsEnabled = false;
    this.staffEnabled = false;
    this.registrationEnabled = false;
    this.stockEditEnabled = false;
    this.lotsEnabled = false;
    this.cartEnabled = false;
    this.substitutionsEnabled = false;
    this.managerAssignEnabled = false;
    this.warehousesEnabled = false;
    this.reportsEnabled = false;
    this.visibleNav = [];
    this.navError = null;
    this.singleName = 'Основной склад';
    this.appTitle = 'CRM7';
    this.appDescription = '';
    this.photoRatio = 'square';
    this.photoFit = 'contain';
    this.demoMode = false;
    this.loadedFor = null;
    this.loading = false;
    this.error = null;
    this.attemptedFor = null;
  }
}

export const appSettings = new AppSettingsStore();
