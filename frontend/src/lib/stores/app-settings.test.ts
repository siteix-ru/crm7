import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { apiRequestMock } = vi.hoisted(() => ({ apiRequestMock: vi.fn() }));

vi.mock('../api/client', () => ({ apiRequest: apiRequestMock }));
vi.mock('./auth.svelte', () => ({ auth: { user: { id: 42 } } }));

import { appSettings } from './app-settings.svelte';

const settings = {
  sales_enabled: true,
  email_export_enabled: true,
  mail_configured: false,
  mail_enabled: true,
  stock_reserve_enabled: false,
  stock_allow_zero: false,
  prices_enabled: true,
  groups_enabled: true,
  prices_configured: true,
  groups_configured: true,
  requests_enabled: true,
  clients_enabled: true,
  chat_enabled: true,
  notifications_enabled: true,
  staff_enabled: true,
  registration_enabled: true,
  stock_edit_enabled: true,
  lots_enabled: true,
  cart_enabled: true,
  substitutions_enabled: true,
  manager_assign_enabled: true,
  warehouses_enabled: true,
  reports_enabled: true,
  single_name: 'Основной склад',
  app_title: 'CRM7',
  app_description: '',
  photo_ratio: 'square',
  photo_fit: 'contain',
  demo_mode: false
};

describe('app settings boot gate', () => {
  beforeEach(() => {
    appSettings.reset();
    apiRequestMock.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('marks settings loaded only after a valid response', async () => {
    apiRequestMock.mockResolvedValue({ ...settings });

    await appSettings.load();

    expect(appSettings.loaded).toBe(true);
    expect(appSettings.loadedFor).toBe(42);
    expect(appSettings.error).toBeNull();
    expect(appSettings.clientsEnabled).toBe(true);
    expect(appSettings.requestsEnabled).toBe(true);
  });

  it('keeps the gate closed on HTTP error and keeps flags restrictive', async () => {
    apiRequestMock.mockRejectedValue(new Error('HTTP 500'));

    await appSettings.load();

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toContain('HTTP 500');
    expect(appSettings.clientsEnabled).toBe(false);
    expect(appSettings.requestsEnabled).toBe(false);
    expect(appSettings.reportsEnabled).toBe(false);
  });

  it('keeps the gate closed on network error', async () => {
    apiRequestMock.mockRejectedValue(new Error('network'));

    await appSettings.load();

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toContain('network');
  });

  it('rejects a null response', async () => {
    apiRequestMock.mockResolvedValue(null);

    await appSettings.load();

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toContain('Некорректный ответ');
  });

  it('rejects a partial response with a missing required boolean', async () => {
    const partial = { ...settings } as Record<string, unknown>;
    delete partial.clients_enabled;
    apiRequestMock.mockResolvedValue(partial);

    await appSettings.load();

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toContain('Некорректный ответ');
    expect(appSettings.clientsEnabled).toBe(false);
  });

  it('rejects a response with a wrong field type', async () => {
    apiRequestMock.mockResolvedValue({ ...settings, requests_enabled: 'yes' });

    await appSettings.load();

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toContain('Некорректный ответ');
  });

  it('reports a timeout and does not mark settings loaded', async () => {
    vi.useFakeTimers();
    apiRequestMock.mockImplementation(
      (_path: string, options: { signal?: AbortSignal }) =>
        new Promise((_resolve, reject) => {
          options.signal?.addEventListener('abort', () => reject(new Error('aborted')));
        })
    );

    const pending = appSettings.load();

    await vi.advanceTimersByTimeAsync(5000);
    await pending;

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toContain('таймаут');
  });

  it('does not auto-retry after an error but retry() can recover', async () => {
    apiRequestMock.mockRejectedValueOnce(new Error('HTTP 503'));
    await appSettings.load();
    expect(appSettings.loaded).toBe(false);

    await appSettings.load();
    expect(apiRequestMock).toHaveBeenCalledTimes(1);

    apiRequestMock.mockResolvedValueOnce({ ...settings });
    await appSettings.retry();

    expect(appSettings.loaded).toBe(true);
    expect(appSettings.error).toBeNull();
  });

  it('reset closes the gate and clears the error', async () => {
    apiRequestMock.mockRejectedValue(new Error('network'));
    await appSettings.load();
    expect(appSettings.error).not.toBeNull();

    appSettings.reset();

    expect(appSettings.loaded).toBe(false);
    expect(appSettings.error).toBeNull();
    expect(appSettings.clientsEnabled).toBe(false);
  });
});
