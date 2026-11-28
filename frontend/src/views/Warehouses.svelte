<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    listWarehouseDirectory,
    type WarehouseDirectoryItem,
    type WarehouseType
  } from '../lib/api/stocks';
  import { config } from '../lib/config';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { router } from '../lib/router.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { directoryValue, type DirectoryField } from '../lib/views/directory-fields';

  const canCreateWarehouse = $derived(auth.can('stocks.warehouses.create'));

  const typeTitles: Record<WarehouseType, string> = {
    main: 'Основной',
    transit: 'Транзитный',
    returns: 'Возвраты',
    reserve: 'Резерв',
    defect: 'Брак'
  };

  function typeTitle(value: WarehouseType): string {
    return typeTitles[value] ?? value;
  }

  const columns: DirectoryField<WarehouseDirectoryItem>[] = [
    { key: 'name', label: 'Название', value: (row) => row.name },
    { key: 'address', label: 'Адрес', value: (row) => row.address || '—' },
    { key: 'positions', label: 'Номенклатуры', value: (row) => String(row.positions) },
    {
      key: 'active',
      label: 'Доступен',
      on: (row) => row.active,
      value: (row) => (row.active ? 'Доступен' : 'Выключен')
    }
  ];
  const detailColumns = columns.filter((column) => column.key !== 'name');

  let items = $state<WarehouseDirectoryItem[]>([]);
  let loading = $state(true);
  let error = $state('');
  let message = $state('');
  let loadSeq = 0;
  const initialFilters = loadFilters('warehouses', { per_page: 20, page: 1 });

  let page = $state<number>(typeof initialFilters.page === 'number' ? initialFilters.page : 1);
  let perPage = $state<number>(
    [10, 20, 50, 100].includes(Number(initialFilters.per_page)) ||
      Number(initialFilters.per_page) === 0
      ? Number(initialFilters.per_page)
      : 20
  );

  const sorted = $derived([...items].sort((a, b) => a.name.localeCompare(b.name, 'ru')));

  const pages = $derived(perPage <= 0 ? 1 : Math.max(1, Math.ceil(sorted.length / perPage)));
  const paged = $derived(
    perPage <= 0 ? sorted : sorted.slice((page - 1) * perPage, (page - 1) * perPage + perPage)
  );

  $effect(() => {
    if (!loading && page > pages) {
      page = pages;
      persist();
    }
  });

  onMount(() => {
    void load();

    const timer = setInterval(() => void load(true), config.listPollMs);

    return () => clearInterval(timer);
  });

  async function load(silent = false): Promise<void> {
    const seq = ++loadSeq;

    if (!silent) {
      loading = true;
      error = '';
    }

    try {
      const data = (await listWarehouseDirectory()).items;

      if (seq !== loadSeq) {
        return;
      }

      items = data;

      if (!silent) {
        error = '';
      }
    } catch (cause) {
      if (seq === loadSeq && !silent) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склады';
      }
    } finally {
      if (seq === loadSeq && !silent) {
        loading = false;
      }
    }
  }

  function persist(): void {
    saveFilters('warehouses', { per_page: perPage, page });
  }

  function goToPage(next: number): void {
    if (!Number.isFinite(next)) {
      return;
    }

    page = Math.max(1, Math.trunc(next));
    persist();
  }

  function changePerPage(): void {
    page = 1;
    persist();
  }

</script>

<section class="page">
  <PageToolbar
    title="Управление складами"
    description="Склады хранят остатки и используются в заказах. Скрытый склад не показывается в остатках."
    actionsLabel="Действия со складами"
  >
    {#snippet actions()}
      <ToolbarMenu
        label="Действия со складами"
        items={canCreateWarehouse
          ? [
              {
                id: 'create',
                label: 'Новый склад',
                icon: 'plus',
                onclick: () => router.navigate('/stocks/warehouses/new')
              }
            ]
          : []}
      />
    {/snippet}
  </PageToolbar>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  {#if loading}
    <ListSkeleton rows={5} />
  {:else}
    {#if sorted.length === 0}
      <EmptyState icon="warehouse" title="Складов пока нет" description="Добавьте первый склад." />
    {:else}
      <div class="levels-wrap" role="table" aria-label="Склады">
        <div class="levels-head" role="row">
          {#each columns as column (column.key)}
            <div class="levels-head-cell col-{column.key}" role="columnheader">
              {column.label}
            </div>
          {/each}
        </div>

        <div class="levels">
          {#each paged as item (item.id)}
            <div class="level" role="row">
              <div class="name-col col-name" role="cell">
                <a
                  class="name"
                  href={`#/stocks/warehouses/${item.id}/edit`}
                  aria-describedby={`warehouse-meta-${item.id}`}
                  title="Открыть склад"
                >
                  {item.name}
                </a>
                <span id={`warehouse-meta-${item.id}`} class="meta">
                  {#if item.type !== 'main'}<span>{typeTitle(item.type)}</span>{/if}
                  {#if item.responsible_name}<span>Ответственный: {item.responsible_name}</span>{/if}
                </span>
              </div>

              {#each detailColumns as column (column.key)}
                <div class="stat col-{column.key}" role="cell">
                  <span class="stat-label">{column.label}</span>
                  <span class="value" class:state={column.key === 'active'} class:on={column.on?.(item)}>
                    {directoryValue(column, item)}
                  </span>
                </div>
              {/each}

              <a
                class="row-overlay"
                href={`#/stocks/warehouses/${item.id}/edit`}
                tabindex="-1"
                aria-hidden="true"
              ></a>
            </div>
          {/each}
        </div>
      </div>
      <Pager
        {page}
        {perPage}
        total={sorted.length}
        loading={loading}
        onchange={goToPage}
        onperpage={(value) => {
          perPage = value;
          changePerPage();
        }}
      />
    {/if}
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .levels-wrap {
    display: flex;
    flex-direction: column;
  }

  .levels {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .level {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
    font-size: var(--font-md);
    cursor: pointer;

  }

  .level:hover {
    background: var(--fill-tertiary);
  }

  .level:last-child {
    border-bottom: none;
  }

  .row-overlay {
    position: absolute;
    inset: 0;
  }

  .name-col {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
  }

  .name-line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
  }

  .name {
    font-weight: 600;
    color: var(--text);
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .level:hover .name {
    color: var(--primary);
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: var(--font-sm);
    color: var(--text-description);
  }

  .stat {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
  }

  .stat.col-positions {
    align-items: flex-end;
    text-align: right;
  }

  .stat-label {
    font-size: var(--font-xs);
    color: var(--text-description);
  }

  .value {
    color: var(--text);
  }

  .state {
    align-self: flex-start;
    color: var(--text-description);
    font-size: var(--font-sm);
    white-space: nowrap;
  }

  .state.on {
    color: var(--success-text);
  }

  .levels-head {
    display: none;
  }

  .levels-head-cell {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    min-width: 0;
    padding: 0;
    text-align: left;
  }

  .levels-head-label {
    min-width: 0;
    line-height: 1.25;
  }

  @media (min-width: 720px) {
    .levels-head {
      position: sticky;
      top: 0;
      z-index: 2;
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.2fr) 140px 110px;
      align-items: stretch;
      padding: 0;
      background: var(--fill-tertiary);
      border: 1px solid var(--border-secondary);
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      font-size: var(--font-md);
      font-weight: 600;
      color: var(--text);
    }

    .levels-head-cell {
      position: relative;
      padding: var(--space-3) var(--space-4);
    }

    .levels-head-cell:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 50%;
      right: 0;
      width: 1px;
      height: 1.6em;
      background: var(--border-secondary);
      transform: translateY(-50%);
    }

    .levels-head .col-positions {
      justify-content: flex-end;
    }

    .levels {
      border-top: none;
      border-radius: 0 0 var(--radius-md) var(--radius-md);
    }

    .levels .level {
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.2fr) 140px 110px;
      align-items: center;
      padding: 0;
    }

    .levels .level > .name-col,
    .levels .level > .stat {
      padding: var(--space-3) var(--space-4);
    }

    .levels .stat-label {
      display: none;
    }

    .levels .meta {
      display: none;
    }

    .levels .col-positions {
      align-items: flex-end;
      text-align: right;
    }
  }

  @media (max-width: 719.98px) {
    /* Шапка остаётся в accessibility-дереве, хотя визуально не занимает место. */
    .levels-head {
      position: absolute;
      display: flex;
      width: 1px;
      height: 1px;
      margin: -1px;
      padding: 0;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
      border: 0;
    }

    .levels .level {
      flex-wrap: wrap;
      row-gap: 6px;
      column-gap: var(--space-3);
    }

    .levels .name-col {
      flex: 1 1 100%;
    }

    .levels .stat {
      flex-direction: row;
      align-items: baseline;
      gap: var(--space-1);
    }

    .levels .stat-label::after {
      content: ':';
    }

    .levels .stat.col-address {
      flex: 1 1 auto;
      min-width: 0;
    }

    .levels .stat.col-address .value {
      overflow-wrap: anywhere;
    }

    .levels .stat.col-positions {
      align-items: baseline;
      text-align: left;
    }

    .levels .stat.col-active {
      margin-left: auto;
    }

    .levels .stat.col-active .stat-label {
      display: none;
    }
  }

  .alert,
  .notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: var(--font-md);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .notice-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--success);
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--text-description);
    background: var(--surface);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

</style>
