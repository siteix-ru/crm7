<script lang="ts">
  import { listLevels, listWarehouses, searchCounts, type StockFilters } from '../../api/stocks';
  import { listItemGroups } from '../../api/groups';
  import type { CreateRequestItem } from '../../api/requests';
  import type { ItemGroup, StockLevel } from '../../api/types';
  import { appSettings } from '../../stores/app-settings.svelte';
  import { formatPrice, stockQuantityText } from '../../format';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import Modal from '../ui/Modal.svelte';
  import SearchInput from '../ui/SearchInput.svelte';
  import Spinner from '../ui/Spinner.svelte';

  interface Props {
    open: boolean;
    items: CreateRequestItem[];
    onclose: () => void;
    onchange?: (items: CreateRequestItem[]) => Promise<boolean | void> | boolean | void;
    clientId?: number;
    priceTypeId?: number;
  }

  const MAX_ITEMS = 50;
  const PER_PAGE = 50;

  let { open, items, onclose, onchange, clientId = 0, priceTypeId = 0 }: Props = $props();

  let warehouses = $state<{ id: number; name: string; positions: number }[]>([]);
  let groups = $state<ItemGroup[]>([]);
  let hasUngrouped = $state(false);
  let activeId = $state(0);
  let groupChoice = $state('all');
  let searchInput = $state('');
  let query = $state('');
  let levels = $state<StockLevel[]>([]);
  let counts = $state<Record<number, number>>({});
  let total = $state(0);
  let page = $state(1);
  let loading = $state(false);
  let loadingMore = $state(false);
  let error = $state('');
  let draft = $state<CreateRequestItem[]>([]);
  let initialized = $state(false);
  let applying = $state(false);
  let applyError = $state('');

  let loadSeq = 0;
  let loadedClientId = -1;
  let loadedPriceTypeId = -1;

  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const groupsEnabled = $derived(appSettings.groupsEnabled);
  const pickedTotal = $derived(draft.reduce((sum, item) => sum + Number(item.quantity || 0), 0));
  const activeWarehouse = $derived(warehouses.find((warehouse) => warehouse.id === activeId) ?? null);
  const limitReached = $derived(draft.length >= MAX_ITEMS);

  $effect(() => {
    if (!open) {
      initialized = false;

      return;
    }

    if (!initialized) {
      initialized = true;
      void init();
    }
  });

  $effect(() => {
    void clientId;
    void priceTypeId;

    if (
      open
      && initialized
      && (loadedClientId !== clientId || loadedPriceTypeId !== priceTypeId)
    ) {
      loadedClientId = clientId;
      loadedPriceTypeId = priceTypeId;
      void refresh();
    }
  });

  $effect(() => {
    const term = searchInput.trim();

    if (term === query) {
      return;
    }

    const timer = setTimeout(() => {
      query = term;
      void refresh();
    }, 320);

    return () => clearTimeout(timer);
  });

  function currentFilters(): StockFilters {
    const filters: StockFilters = {
      q: query,
      show_zero: appSettings.allowZeroStock
    };

    if (groupChoice === 'none') {
      filters.no_group = true;
    } else if (groupChoice !== 'all') {
      filters.group_id = Number(groupChoice);
    }

    return filters;
  }

  async function init(): Promise<void> {
    loadedClientId = clientId;
    loadedPriceTypeId = priceTypeId;
    draft = items.map((item) => ({ ...item }));
    searchInput = '';
    query = '';
    error = '';
    applyError = '';

    if (warehouses.length === 0) {
      try {
        warehouses = (await listWarehouses()).items.map((warehouse) => ({
          id: warehouse.id,
          name: warehouse.name,
          positions: warehouse.positions
        }));
      } catch {
        warehouses = [];
      }
    }

    if (groupsEnabled && groups.length === 0) {
      try {
        const data = await listItemGroups();
        groups = data.items;
        hasUngrouped = data.has_ungrouped;
      } catch {
        groups = [];
      }
    }

    if (activeId === 0 || !warehouses.some((warehouse) => warehouse.id === activeId)) {
      activeId = warehouses[0]?.id ?? 0;
    }

    await refresh();
  }

  async function refresh(): Promise<void> {
    const seq = ++loadSeq;

    if (activeId === 0) {
      levels = [];
      total = 0;
      loading = false;

      return;
    }

    loading = true;
    error = '';

    const filters = currentFilters();

    try {
      const [listResult, countsResult] = await Promise.allSettled([
        listLevels([activeId], filters, 1, PER_PAGE, clientId, priceTypeId),
        searchCounts(filters)
      ]);

      if (seq !== loadSeq) {
        return;
      }

      if (listResult.status === 'fulfilled') {
        levels = listResult.value.items;
        total = listResult.value.total;
        page = 1;
      } else {
        levels = [];
        total = 0;
        error = 'Не удалось загрузить номенклатуру';
      }

      if (countsResult.status === 'fulfilled') {
        counts = Object.fromEntries(
          Object.entries(countsResult.value.counts).map(([id, value]) => [Number(id), Number(value)])
        );
      }
    } catch {
      if (seq === loadSeq) {
        error = 'Не удалось загрузить номенклатуру';
      }
    } finally {
      if (seq === loadSeq) {
        loading = false;
        loadingMore = false;
      }
    }
  }

  async function loadMore(): Promise<void> {
    if (loadingMore || loading || levels.length >= total) {
      return;
    }

    const seq = loadSeq;
    const nextPage = page + 1;
    loadingMore = true;

    try {
      const result = await listLevels([activeId], currentFilters(), nextPage, PER_PAGE, clientId, priceTypeId);

      if (seq !== loadSeq) {
        return;
      }

      levels = [...levels, ...result.items];
      total = result.total;
      page = nextPage;
    } catch {
      if (seq === loadSeq) {
        error = 'Не удалось загрузить ещё';
      }
    } finally {
      if (seq === loadSeq) {
        loadingMore = false;
      }
    }
  }

  function selectWarehouse(event: Event): void {
    const value = Number((event.currentTarget as HTMLSelectElement).value);

    if (value === activeId) {
      return;
    }

    activeId = value;
    void refresh();
  }

  function selectGroup(event: Event): void {
    groupChoice = (event.currentTarget as HTMLSelectElement).value;
    void refresh();
  }

  function submitSearch(): void {
    query = searchInput.trim();
    void refresh();
  }

  function resetFilters(): void {
    searchInput = '';
    query = '';
    groupChoice = 'all';
    void refresh();
  }

  function warehouseCount(id: number, fallback: number): number {
    return counts[id] ?? fallback;
  }

  function key(warehouseId: number | null, name: string): string {
    return `${warehouseId ?? 0}|${name.trim().toLowerCase()}`;
  }

  function quantity(level: StockLevel): number {
    const levelKey = key(activeId, level.name);
    const found = draft.find((item) => key(item.warehouse_id, item.name) === levelKey);

    return found ? Number(found.quantity) : 0;
  }

  function canAdd(level: StockLevel, picked: number): boolean {
    return picked > 0 || !limitReached;
  }

  function change(level: StockLevel, delta: number): void {
    const levelKey = key(activeId, level.name);
    const index = draft.findIndex((item) => key(item.warehouse_id, item.name) === levelKey);

    if (index === -1) {
      if (delta <= 0 || limitReached) {
        return;
      }

      const warehouse = warehouses.find((item) => item.id === activeId);

      draft = [
        ...draft,
        {
          name: level.name,
          unit: level.unit,
          description: level.description,
          quantity: delta,
          warehouse_id: activeId,
          warehouse_name: warehouse?.name ?? '',
          stock_level_id: level.id
        }
      ];

      return;
    }

    const next = Number(draft[index].quantity) + delta;

    if (next <= 0) {
      draft = draft.filter((_, position) => position !== index);
    } else {
      draft = draft.map((item, position) =>
        position === index ? { ...item, quantity: next } : item
      );
    }
  }

  function clearDraft(): void {
    draft = [];
  }

  async function apply(): Promise<void> {
    if (applying) {
      return;
    }

    applying = true;
    applyError = '';

    try {
      const result = await onchange?.(draft.map((item) => ({ ...item })));

      if (result === false) {
        applyError = 'Не удалось сохранить состав. Проверьте остатки и попробуйте снова.';

        return;
      }

      onclose();
    } catch (cause) {
      applyError = cause instanceof Error ? cause.message : 'Не удалось сохранить состав';
    } finally {
      applying = false;
    }
  }
</script>

<Modal {open} title="Складские остатки" wide {onclose}>
  {#if warehouses.length === 0}
    <div class="picker-empty">
      <p class="muted">Нет доступных складов. Обратитесь к администратору.</p>
    </div>
  {:else}
    <div class="picker-toolbar">
      <div class="picker-row-filters">
        <label class="picker-field picker-field-wh">
          <span class="picker-field-label">Склад</span>
          <select value={activeId} onchange={selectWarehouse} aria-label="Склад">
            {#each warehouses as warehouse (warehouse.id)}
              <option value={warehouse.id}>
                {warehouse.name} ({warehouseCount(warehouse.id, warehouse.positions)})
              </option>
            {/each}
          </select>
        </label>

        <form
          class="picker-search"
          onsubmit={(event) => {
            event.preventDefault();
            submitSearch();
          }}
        >
          <SearchInput
            bind:value={searchInput}
            historyKey="request-items"
            icon
            placeholder="Название или часть названия"
            onclear={() => {
              query = '';
              void refresh();
            }}
            onpick={() => submitSearch()}
          />
        </form>

        {#if groupsEnabled && groups.length > 0}
          <label class="picker-field picker-field-group">
            <span class="picker-field-label">Группа</span>
            <select value={groupChoice} onchange={selectGroup} aria-label="Группа номенклатуры">
              <option value="all">Все группы</option>
              {#if hasUngrouped}
                <option value="none">Без группы</option>
              {/if}
              {#each groups as group (group.id)}
                <option value={String(group.id)}>{group.title}</option>
              {/each}
            </select>
          </label>
        {/if}
      </div>

      <div class="picker-status" aria-live="polite">
        <span>
          Найдено: <strong>{total}</strong>
          {#if activeWarehouse}
            · склад: <strong>{activeWarehouse.name}</strong>
          {/if}
        </span>
        <span class:limit={limitReached}>
          Выбрано: <strong>{draft.length}</strong> из {MAX_ITEMS}
          {#if pickedTotal > 0}· всего единиц: {pickedTotal.toLocaleString('ru-RU')}{/if}
        </span>
      </div>
    </div>

    {#if error}
      <div class="picker-error" role="alert">
        <span>{error}</span>
        <Button variant="ghost" onclick={() => void refresh()}>Повторить</Button>
      </div>
    {/if}

    {#if loading}
      <div class="center"><Spinner size={22} /></div>
    {:else if levels.length === 0 && !error}
      <div class="picker-empty">
        <p class="muted">
          {query !== ''
            ? `По запросу «${query}» ничего не найдено`
            : 'Номенклатура не найдена'}
        </p>
        {#if query !== '' || groupChoice !== 'all'}
          <Button variant="ghost" onclick={resetFilters}>Сбросить поиск</Button>
        {/if}
      </div>
    {:else}
      <div class="picker-table" class:no-price={!pricesEnabled} role="list">
        <div class="picker-head">
          <span>Название</span>
          <span class="num">Остаток</span>
          {#if pricesEnabled}
            <span class="num">Цена</span>
          {/if}
          <span class="num">В заявке</span>
        </div>
        {#each levels as level (level.id)}
          {@const picked = quantity(level)}
          {@const overStock = !appSettings.allowZeroStock && picked > level.quantity}
          <div
            class="picker-row"
            class:picked={picked > 0}
            class:over-stock={overStock}
            role="listitem"
          >
            <button
              type="button"
              class="pr-name"
              class:addable={picked === 0 && canAdd(level, picked)}
              disabled={picked === 0 && !canAdd(level, picked)}
              aria-label={picked === 0 ? `Добавить в заявку: ${level.name}` : undefined}
              onclick={() => {
                if (picked === 0) {
                  change(level, 1);
                }
              }}
            >
              <span class="pr-title">{level.name}</span>
              {#if groupsEnabled && level.group_title && groupChoice === 'all'}
                <span class="pr-group">{level.group_title}</span>
              {/if}
            </button>

            <span class="pr-stock" class:warn={overStock}>
              {stockQuantityText(level.quantity, level.unit)}
              {#if overStock}
                <span class="pr-warn">больше остатка</span>
              {/if}
            </span>

            {#if pricesEnabled}
              <span class="pr-price" class:missing={level.price === null}>
                {formatPrice(level.price)}
              </span>
            {/if}

            <span class="pr-cart">
              {#if picked > 0}
                <span class="pr-controls">
                  <button
                    type="button"
                    class="step"
                    aria-label={`Уменьшить: ${level.name}`}
                    onclick={() => change(level, -1)}
                  >
                    −
                  </button>
                  <span class="pr-qty">
                    {picked.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                  </span>
                  <button
                    type="button"
                    class="step"
                    aria-label={`Увеличить: ${level.name}`}
                    onclick={() => change(level, 1)}
                  >
                    +
                  </button>
                </span>
              {:else}
                <button
                  type="button"
                  class="step add-step"
                  aria-label={`Добавить в заявку: ${level.name}`}
                  disabled={limitReached}
                  onclick={() => change(level, 1)}
                >
                  +
                </button>
              {/if}
            </span>
          </div>
        {/each}
      </div>

      {#if levels.length < total}
        <div class="picker-more">
          <Button variant="ghost" loading={loadingMore} onclick={() => void loadMore()}>
            Показать ещё ({levels.length} из {total})
          </Button>
        </div>
      {/if}
    {/if}

    {#if applyError}
      <div class="picker-error" role="alert">
        <span>{applyError}</span>
      </div>
    {/if}

    <div class="picker-footer">
      <span class="picker-footer-info">
        {#if limitReached}
          Достигнут лимит: {MAX_ITEMS}
        {:else}
          Выбрано: {draft.length}
        {/if}
      </span>
      <div class="picker-footer-actions">
        {#if draft.length > 0}
          <Button variant="ghost" disabled={applying} onclick={clearDraft}>Очистить</Button>
        {/if}
        <Button variant="ghost" disabled={applying} onclick={onclose}>Отмена</Button>
        <Button loading={applying} disabled={applying} onclick={apply}>
          <Icon name="check" size={14} />
          Готово{#if draft.length > 0} · {draft.length}{/if}
        </Button>
      </div>
    </div>
  {/if}
</Modal>

<style>
  .muted {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-5) 0;
  }

  .picker-toolbar {
    position: sticky;
    top: 0;
    z-index: 3;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin: 0;
    padding: var(--space-1) 0 var(--space-2);
    background: var(--surface);
    border-bottom: 1px solid var(--border-secondary);
  }

  .picker-row-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-2);
  }

  .picker-field {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .picker-field-label {
    font-size: 11px;
    line-height: 1.2;
    color: var(--text-description);
    letter-spacing: 0.02em;
  }

  .picker-field select {
    height: 32px;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 13px;
    outline: none;
  }

  .picker-field select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(5, 145, 255, 0.1);
  }

  .picker-field-wh select {
    min-width: 190px;
    max-width: 260px;
  }

  .picker-field-group select {
    min-width: 150px;
    max-width: 220px;
  }

  .picker-search {
    display: flex;
    flex: 1 1 220px;
    min-width: 160px;
    align-items: flex-end;
  }

  .picker-search :global(.wrap) {
    width: 100%;
  }

  .picker-status {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: var(--space-2);
    font-size: 12px;
    color: var(--muted);
  }

  .picker-status strong {
    color: var(--text);
    font-weight: 600;
  }

  .picker-status .limit strong,
  .picker-status .limit {
    color: var(--danger);
  }

  .picker-error {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin: var(--space-2) 0;
    padding: 8px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    font-size: 13px;
  }

  .picker-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-6) var(--space-3);
    text-align: center;
  }

  .picker-table {
    display: flex;
    flex-direction: column;
    max-width: 100%;
  }

  .picker-head,
  .picker-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 120px 110px 148px;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 6px;
    border-bottom: 1px solid var(--border-secondary);
  }

  .picker-table.no-price .picker-head,
  .picker-table.no-price .picker-row {
    grid-template-columns: minmax(0, 1fr) 120px 148px;
  }

  .picker-head {
    padding-bottom: 6px;
    border-bottom-width: 2px;
    color: var(--muted);
    font-size: 12px;
  }

  .picker-head .num {
    text-align: right;
  }

  .picker-row:last-child {
    border-bottom: none;
  }

  .picker-row:hover {
    background: var(--fill-tertiary);
  }

  .picker-row.picked {
    background: color-mix(in srgb, var(--primary) 6%, transparent);
  }

  .picker-row.over-stock {
    background: color-mix(in srgb, var(--danger) 5%, transparent);
  }

  .pr-name {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: default;
  }

  .pr-name.addable {
    cursor: pointer;
  }

  .pr-name.addable:hover .pr-title {
    color: var(--primary);
    text-decoration: underline;
  }

  .pr-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
  }

  .pr-group {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    color: var(--muted);
  }

  .pr-stock {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    font-size: 13px;
    white-space: nowrap;
  }

  .pr-stock.warn {
    font-weight: 600;
    color: var(--danger);
  }

  .pr-warn {
    color: var(--danger);
    font-weight: 500;
    font-size: 12px;
  }

  .pr-price {
    justify-self: end;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
  }

  .pr-price.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .pr-cart {
    display: inline-flex;
    justify-self: end;
  }

  .pr-controls {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  .pr-qty {
    min-width: 44px;
    text-align: center;
    font-weight: 500;
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .step:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }

  .add-step {
    color: var(--primary);
    border-color: var(--primary);
  }

  .picker-more {
    display: flex;
    justify-content: center;
    padding: var(--space-3) 0;
  }

  .picker-footer {
    position: sticky;
    bottom: 0;
    z-index: 3;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin: 0;
    padding: var(--space-3) 0 var(--space-1);
    background: var(--surface);
    border-top: 1px solid var(--border-secondary);
  }

  .picker-footer-info {
    font-size: 13px;
    color: var(--muted);
  }

  .picker-footer-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-left: auto;
  }

  @media (max-width: 640px) {
    .picker-row-filters {
      align-items: stretch;
    }

    .picker-field-wh,
    .picker-field-wh select,
    .picker-field-group,
    .picker-field-group select,
    .picker-search {
      flex: 1 1 100%;
      max-width: none;
      min-width: 0;
    }

    .picker-head,
    .picker-row {
      grid-template-columns: minmax(0, 1fr) 80px 76px 108px;
      gap: var(--space-2);
    }

    .picker-table.no-price .picker-head,
    .picker-table.no-price .picker-row {
      grid-template-columns: minmax(0, 1fr) 80px 108px;
    }

    .picker-footer-actions {
      width: 100%;
    }

    .picker-footer-actions :global(button) {
      flex: 1;
    }
  }
</style>
