// @ts-ignore — тестовый Node API, не часть browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

function source(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8');
}

const app = source('../App.svelte');
const loaders = source('../lib/views-loaders.ts');
const stocks = source('./Stocks.svelte');
const page = source('./StockImport.svelte');

describe('отдельная страница импорта остатков', () => {
  it('имеет собственный маршрут и ленивый загрузчик', () => {
    expect(app).toContain("route === '/stocks/import'");
    expect(app).toContain('views.stockImport');
    expect(loaders).toContain("stockImport: () => import('../views/StockImport.svelte')");
  });

  it('не оставляет модалку и иконку импорта в STK-LIST', () => {
    expect(stocks).not.toContain('openImport');
    expect(stocks).not.toContain('importOpen');
    expect(stocks).not.toContain('Импорт остатков из 1С');
    expect(stocks).not.toContain('Импорт из 1С');
  });

  it('разделяет форму и историю закладками с URL-состоянием', () => {
    expect(page).toContain('<PageToolbar');
    expect(page).toContain('role="tablist"');
    expect(page).toContain('aria-selected={tab === \'history\'}');
    expect(page).toContain('router.navigate(`/stocks/import?tab=${next}`, true)');
    expect(page).toContain("tabFromUrl === 'history'");
    expect(page).toContain('aria-controls="import-panel-upload"');
    expect(page).toContain('role="tabpanel"');
    expect(page).toContain('tabindex={tab === \'history\' ? 0 : -1}');
    expect(page).toContain('{#snippet tabs()}');
    expect(page).toContain('void focusTab(\'history\')');
  });

  it('keeps the import result and confirmation consistent', () => {
    expect(page).toContain("selectTab('history', true)");
    expect(page).toContain('{@render confirmBlock()}');
    expect(page).toContain('pendingMapping');
    expect(page).toContain('runImport(pendingMapping ?? undefined)');
    expect(page).not.toContain('Проверить и загрузить');
  });

  it('переиспользует существующие API и показывает историю', () => {
    expect(page).toContain('importLevels(');
    expect(page).toContain('importHistory(');
    expect(page).toContain('needs_mapping');
    expect(page).toContain('jobs.length');
    expect(page).toContain('updates.length');
  });

  it('использует современную form-разметку с доступными полями', () => {
    expect(page).toContain('<label for="import-file">');
    expect(page).toContain('<label for="import-date">');
    expect(page).toContain('role="alert"');
    expect(page).toContain('role="status"');
    expect(page).toContain('import-layout');
  });

  it('проверяет права на маршруте', () => {
    expect(app).toContain("!auth.can('stocks.import')");
    expect(app).toContain('!appSettings.stockEditEnabled');
  });
});
