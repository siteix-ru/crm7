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

  const columns = [
    { field: 'name', label: 'Название' },
    { field: 'address', label: 'Адрес' },
    { field: 'positions', label: 'Остаток' },
    { field: 'active', label: 'Доступен' }
  ];

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
      <div class="levels-wrap">
        <div class="levels-head">
          {#each columns as column (column.field)}
            <div class="levels-head-cell col-{column.field}">
              <span class="levels-head-label">{column.label}</span>
            </div>
          {/each}
        </div>

        <div class="levels">
          {#each paged as item (item.id)}
            <!-- svelte-ignore a11y_click_events_have_key_events -->
            <!-- svelte-ignore a11y_no_static_element_interactions -->
            <div class="level" onclick={() => router.navigate(`/stocks/warehouses/${item.id}/edit`)}>
              <div class="name-col col-name">
                <div class="name-line">
                  <a
                    class="name"
                    href={`#/stocks/warehouses/${item.id}/edit`}
                    title="Открыть склад"
                    onclick={(event) => event.stopPropagation()}
                  >
                    {item.name}
                  </a>
                </div>
                <div class="meta">
                  {#if item.type !== 'main'}<span>{typeTitle(item.type)}</span>{/if}
                  {#if item.responsible_name}<span>Ответственный: {item.responsible_name}</span>{/if}
                </div>
              </div>

              <div class="level-bottom">
                <div class="stat col-address">
                  <span class="stat-label">Адрес</span>
                  <span class="value">{item.address ?? '—'}</span>
                </div>
                <div class="stat col-positions">
                  <span class="stat-label">Номенклатуры</span>
                  <span class="value">{item.positions}</span>
                </div>
                <div class="stat col-active">
                  <span class="stat-label">Доступен</span>
                  <span class="state" class:on={item.active}>
                    {item.active ? 'Доступен' : 'Выключен'}
                  </span>
                </div>
              </div>
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
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 12px var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
    font-size: 14px;
    cursor: pointer;
    transition: background 0.12s ease;
  }

  .level:hover {
    background: var(--fill-tertiary);
  }

  .level:last-child {
    border-bottom: none;
  }

  .name-col {
    display: flex;
    flex-direction: column;
    gap: 2px;
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
    font-size: 13px;
    color: var(--text-description);
  }

  .level-bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 6px var(--space-3);
    min-width: 0;
  }

  .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .stat.col-positions {
    align-items: flex-end;
    text-align: right;
  }

  .stat-label {
    font-size: 12px;
    color: var(--text-description);
  }

  .value {
    color: var(--text);
  }

  .state {
    align-self: flex-start;
    color: var(--text-description);
    font-size: 13px;
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
    gap: 4px;
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
      font-size: 14px;
      font-weight: 600;
      color: var(--text);
    }

    .levels-head-cell {
      position: relative;
      padding: 12px var(--space-4);
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
    .levels .level > .level-bottom > .stat {
      padding: 12px var(--space-4);
    }

    .levels .level-bottom {
      display: contents;
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
    .levels .level {
      flex-wrap: wrap;
      row-gap: 6px;
      column-gap: var(--space-3);
    }

    .levels .name-col {
      flex: 1 1 100%;
    }

    .levels .level-bottom {
      display: contents;
    }

    .levels .stat {
      flex-direction: row;
      align-items: baseline;
      gap: 4px;
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
    font-size: 14px;
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
