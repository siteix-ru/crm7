import { apiRequest } from '../api/client';
import { auth } from './auth.svelte';

interface AppSettingsResponse {
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

class AppSettingsStore {
  salesEnabled = $state(true);

  emailExportEnabled = $state(true);

  mailConfigured = $state(false);

  mailEnabled = $state(true);

  stockReserveEnabled = $state(false);

  allowZeroStock = $state(false);

  pricesEnabled = $state(false);

  groupsEnabled = $state(false);

  pricesConfigured = $state(false);

  groupsConfigured = $state(false);

  requestsEnabled = $state(true);

  clientsEnabled = $state(true);

  chatEnabled = $state(true);

  notificationsEnabled = $state(true);

  staffEnabled = $state(true);

  registrationEnabled = $state(true);

  stockEditEnabled = $state(true);

  cartEnabled = $state(true);

  substitutionsEnabled = $state(true);

  managerAssignEnabled = $state(true);

  warehousesEnabled = $state(true);

  reportsEnabled = $state(true);

  singleName = $state('Основной склад');

  appTitle = $state('CRM7');

  appDescription = $state('');

  photoRatio = $state('square');

  photoFit = $state('contain');

  demoMode = $state(false);

  loadedFor = $state<number | null>(null);

  loading = $state(false);

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

    this.loading = true;

    const safetyTimer = setTimeout(() => {
      if (this.loadedFor !== userId) {
        this.loadedFor = userId;
      }
    }, 5000);

    try {
      const data = await apiRequest<AppSettingsResponse>('/settings/app', { auth: true });
      this.salesEnabled = Boolean(data.sales_enabled);
      this.emailExportEnabled = data.email_export_enabled !== false;
      this.mailConfigured = data.mail_configured === true;
      this.mailEnabled = data.mail_enabled !== false;
      this.stockReserveEnabled = data.stock_reserve_enabled === true;
      this.allowZeroStock = data.stock_allow_zero === true;
      this.pricesEnabled = data.prices_enabled === true;
      this.groupsEnabled = data.groups_enabled === true;
      this.pricesConfigured = data.prices_configured === true;
      this.groupsConfigured = data.groups_configured === true;
      this.requestsEnabled = data.requests_enabled !== false;
      this.clientsEnabled = data.clients_enabled !== false;
      this.chatEnabled = data.chat_enabled !== false;
      this.notificationsEnabled = data.notifications_enabled !== false;
      this.staffEnabled = data.staff_enabled !== false;
      this.registrationEnabled = data.registration_enabled !== false;
      this.stockEditEnabled = data.stock_edit_enabled !== false;
      this.cartEnabled = this.requestsEnabled;
      this.substitutionsEnabled = data.substitutions_enabled !== false;
      this.managerAssignEnabled = data.manager_assign_enabled !== false;
      this.warehousesEnabled = data.warehouses_enabled !== false;
      this.reportsEnabled = data.reports_enabled !== false;
      this.singleName = (data.single_name || 'Основной склад').trim() || 'Основной склад';
      this.appTitle = (data.app_title || 'CRM7').trim() || 'CRM7';
      this.appDescription = (data.app_description || '').trim();
      this.photoRatio = ['dynamic', 'square', 'landscape', 'portrait'].includes(data.photo_ratio)
        ? data.photo_ratio
        : 'square';
      this.photoFit = data.photo_fit === 'cover' ? 'cover' : 'contain';
      this.demoMode = data.demo_mode === true;
      this.loadedFor = userId;
    } catch {
      // служебная настройка: интерфейс не блокируем при сбое загрузки
      this.loadedFor = userId;
    } finally {
      clearTimeout(safetyTimer);
      this.loading = false;
    }
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
    this.salesEnabled = true;
    this.emailExportEnabled = true;
    this.mailConfigured = false;
    this.mailEnabled = true;
    this.stockReserveEnabled = false;
    this.allowZeroStock = false;
    this.pricesEnabled = false;
    this.groupsEnabled = false;
    this.pricesConfigured = false;
    this.groupsConfigured = false;
    this.requestsEnabled = true;
    this.clientsEnabled = true;
    this.chatEnabled = true;
    this.notificationsEnabled = true;
    this.staffEnabled = true;
    this.registrationEnabled = true;
    this.stockEditEnabled = true;
    this.cartEnabled = true;
    this.substitutionsEnabled = true;
    this.managerAssignEnabled = true;
    this.warehousesEnabled = true;
    this.reportsEnabled = true;
    this.singleName = 'Основной склад';
    this.appTitle = 'CRM7';
    this.appDescription = '';
    this.photoRatio = 'square';
    this.photoFit = 'contain';
    this.demoMode = false;
    this.loadedFor = null;
    this.loading = false;
  }
}

export const appSettings = new AppSettingsStore();
