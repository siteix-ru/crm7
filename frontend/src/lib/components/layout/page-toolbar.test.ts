// @ts-ignore — тестовый Node API, не часть browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import toolbar from './PageToolbar.svelte?raw';
import menu from './ToolbarMenu.svelte?raw';

function view(name: string): string {
  return readFileSync(new URL(`../../../views/${name}`, import.meta.url), 'utf8');
}

function ui(name: string): string {
  return readFileSync(new URL(`../ui/${name}`, import.meta.url), 'utf8');
}

describe('PageToolbar', () => {
  it('keeps one header row with title and actions snippet', () => {
    expect(toolbar).toContain('<header class="page-toolbar" aria-labelledby={titleId}>');
    expect(toolbar).toContain('{@render actions()}');
    expect(toolbar).toContain('{@render leading()}');
    expect(toolbar).toContain('justify-content: space-between');
    expect(toolbar).toContain('flex-wrap: wrap');
  });

  it('defines a single h1 style for all pages', () => {
    expect(toolbar).toContain('<h1 id={titleId}>');
    expect(toolbar).toContain('font-size: var(--font-xl)');
    expect(toolbar).toContain('font-weight: 600');
    expect(toolbar).toContain('line-height: 1.4');
  });

  it('gives actions and view switcher their own named regions', () => {
    expect(toolbar).toContain('role="group" aria-label={actionsLabel}');
    expect(toolbar).toContain('role="group" aria-label={tabsLabel}');
    expect(toolbar).toContain('{@render tabs()}');
    expect(toolbar).toContain('{@render primary()}');
  });

  it('keeps mobile actions beside the title with the description below', () => {
    expect(toolbar).toMatch(/@media \(max-width: 720px\)[\s\S]*?\.toolbar-title \{\s*flex: 1 1 0;/);
    expect(toolbar).toMatch(/\.toolbar-actions \{\s*width: auto;/);
  });

  it('does not introduce a background or a divider', () => {
    expect(toolbar).not.toContain('border-bottom');
    expect(toolbar).not.toContain('background: var(--surface)');
    expect(toolbar).not.toContain('box-shadow');
  });

  it('is used by all migrated pages, without legacy page headers', () => {
    for (const name of [
      'Requests.svelte',
      'Clients.svelte',
      'Staff.svelte',
      'Stocks.svelte',
      'Reports.svelte',
      'StockImport.svelte',
      'Warehouses.svelte',
      'Substitutions.svelte',
      'StockLots.svelte',
      'AdminAudit.svelte',
      'MyReports.svelte',
      'Notifications.svelte',
      'Admin.svelte',
      'Cart.svelte',
      'PriceTypes.svelte',
      'StockGroups.svelte',
      'StockTypes.svelte'
    ]) {
      const source = view(name);
      expect(source).toContain('<PageToolbar');
      expect(source).not.toContain('<div class="page-hero"');
      expect(source).not.toContain('<div class="page-head"');
    }
  });
});

describe('ToolbarMenu', () => {
  it('owns keyboard navigation, focus return and a guarded search', () => {
    expect(menu).toContain('aria-haspopup="menu"');
    expect(menu).toContain('role="menuitemcheckbox"');
    expect(menu).toContain("['ArrowDown', 'ArrowUp', 'Home', 'End']");
    expect(menu).toContain('getHistory(search.historyKey)');
    expect(menu).toContain('15_000');
    expect(menu).toContain('function stopSearch()');
    expect(menu).toContain('aria-label="Очистить поиск"');
  });

  it('is the only toolbar control host on list pages', () => {
    for (const name of [
      'Requests.svelte',
      'Clients.svelte',
      'Staff.svelte',
      'Stocks.svelte',
      'Reports.svelte',
      'Warehouses.svelte',
      'Substitutions.svelte',
      'StockLots.svelte',
      'AdminAudit.svelte',
      'MyReports.svelte',
      'Notifications.svelte',
      'PriceTypes.svelte',
      'StockGroups.svelte',
      'StockTypes.svelte'
    ]) {
      expect(view(name)).toContain('<ToolbarMenu');
    }
  });

  it('moves every search into the shared modal with a history key', () => {
    for (const [name, key] of [
      ['Requests.svelte', 'requests'],
      ['Clients.svelte', 'clients'],
      ['Staff.svelte', 'staff'],
      ['Stocks.svelte', 'stocks'],
      ['AdminAudit.svelte', 'admin-audit']
    ]) {
      const source = view(name);
      expect(source).toContain('search={{');
      expect(source).toContain(key === 'staff' ? "'staff'" : `historyKey: '${key}'`);
      expect(source).not.toContain('role="search"');
    }
  });

  it('splits CLI-LIST view and filters into two dialogs', () => {
    const clients = view('Clients.svelte');
    expect(clients).toContain('title="Вид"');
    expect(clients).toContain('title="Фильтры"');
    expect(clients).not.toContain('Вид и фильтры');
    expect(clients).toContain("id: 'view'");
    expect(clients).toContain("id: 'filters'");
    expect(clients).toContain('view-field-table');
    expect(clients).toContain('disabled={field.required}');
    expect(clients).toContain('toggleViewField(field.key, \'tiles\')');
    expect(clients).toContain('function setViewMode(');
    expect(clients).toContain('function applyFilterDraft()');
    expect(clients).not.toContain('clients-filters');
    expect(clients.match(/size="md"/g)?.length).toBe(2);
    expect(clients).not.toContain('filterAnnouncement');
    expect(clients).toContain('dirty={filterDirty}');
    expect(clients).toContain('resetLabel="Сбросить всё"');
    expect(clients).toContain('>Дата регистрации</legend>');
    expect(clients).toContain('role="table"');
    expect(clients).toContain('row-link');
    expect(clients).toContain('function clearAll()');
    expect(clients).toContain('ListSkeleton rows={Math.min(perPage, 10)}');
    expect(clients).toContain('pending.slice(0, 3)');
    expect(clients).toContain("from '../lib/views/client-fields'");
    expect(clients).toContain('hiddenListFields');
    expect(clients).toContain('ct-phone');
    expect(clients).toContain('role="cell"');
    expect(clients).toContain('<span class="sr-only">Email: {client.email}</span>');
    expect(clients).toContain('function middleEllipsis(');
    expect(clients).toContain('class="ct-email-line"');
    expect(clients).toContain('withReturnTo(`/clients/${client.id}`, currentFullRoute)');
    expect(clients).toContain('<SortDialog');
    expect(clients).toContain('onapply={applySortTerms}');
    const sort = ui('SortDialog.svelte');
    expect(sort).toContain('class="dir-toggle"');
    expect(sort).toContain('function cycle(');
    expect(sort).not.toContain('dragField');
    expect(sort).not.toContain('aria-pressed');
    expect(clients).toContain('per_page: perPage');
    expect(clients).toContain('listColumns(fieldsByView.list, viewportWidth)');
    expect(clients).toContain('{#each columns as field (field.key)}');
    expect(clients).toContain('tileFields(fieldsByView.tiles, ');
    expect(clients).toContain('field.value?.(client)');
    expect(clients).toContain('<span class="num" role="cell">{client.requests_total}</span>');
    expect(clients).toContain('ожидает подтверждения');
    expect(clients).toContain('--avatar-hue');
    expect(clients).toContain('viewExplicit');
    expect(clients).toContain('client-table-scroll');
    expect(clients).toContain('announceList(');
    expect(clients).toContain('function rowMeta(');
    expect(clients).toContain('clientMetaFields(client, hiddenListFields, formatDate)');
    expect(clients).toContain('<span class="meta-label">{field.label}:</span>');
    expect(clients).toContain('aria-describedby={showRowMeta ? `client-meta-${client.id}` : undefined}');
    expect(clients).toContain('class="ct-sub ct-meta"');
  });

  it('describes dialog menu items and shows active filter counts', () => {
    expect(menu).toContain('aria-haspopup={item.dialog ? \'dialog\' : undefined}');
    expect(menu.match(/aria-haspopup=\{item.dialog \? 'dialog' : undefined\}/g)?.length).toBe(2);
    expect(menu).toContain('class="menu-item-count"');
    expect(menu).toContain('trigger()?.focus()');
    expect(menu).toContain('onpointerdown={onWindowPointerDown}');
  });

  it('drives wave-1 directory tables from descriptors', () => {
    for (const name of [
      'StockTypes.svelte',
      'PriceTypes.svelte',
      'StockGroups.svelte',
      'Warehouses.svelte'
    ]) {
      expect(view(name)).toContain('{#each columns as column (column.key)}');
    }
    const warehouses = view('Warehouses.svelte');
    expect(warehouses).toContain('role="table"');
    expect(warehouses).toContain('role="columnheader"');
    expect(warehouses).toContain('role="cell"');
    expect(warehouses).toContain('aria-describedby={`warehouse-meta-${item.id}`}');
    expect(warehouses).toContain("label: 'Номенклатуры'");
    expect(warehouses).toContain('@media (max-width: 719.98px)');

    const audit = view('AdminAudit.svelte');
    expect(audit).toContain('role="list"');
    expect(audit).toContain('role="alert"');
    expect(audit).not.toContain('role="table"');
    expect(audit).toContain('suggestions: audit.map((entry) => entry.action)');
  });

  it('splits Staff view and filters into separate dialogs', () => {
    const staff = view('Staff.svelte');
    expect(staff).toContain('title="Вид"');
    expect(staff).toContain('<FiltersModal');
    expect(staff).not.toContain('Вид и фильтры');
    expect(staff).toContain('role="table"');
    expect(staff).toContain('listStaffFields(');
    expect(staff).toContain('saveStaffFilters()');
    expect(staff).toContain('maxPage(data.total, perPage)');
    expect(staff).toContain('withReturnTo(`/staff/${staff.id}`');
  });

  it('splits Requests view and filters into separate dialogs', () => {
    const requests = view('Requests.svelte');
    expect(requests).toContain('title="Вид"');
    expect(requests).toContain('<FiltersModal');
    expect(requests).not.toContain('Вид и фильтры');
    expect(requests).toContain("role={viewMode === 'list' ? 'table' : undefined}");
    expect(requests).toContain('listRequestFields(');
    expect(requests).toContain('requestTileFields(');
    expect(requests).toContain('onstop: abortSearch');
    expect(requests).not.toContain('function headerSort(');
  });

  it('keeps STK-LOTS actions in one menu', () => {
    const lots = view('StockLots.svelte');
    expect(lots).toContain("label: 'Обновить список'");
    expect(lots).toContain("label: 'Конвертация остатков'");
    expect(lots).toContain("label: 'Поступление'");
    expect(lots).not.toContain('class="create-button"');
  });
});
