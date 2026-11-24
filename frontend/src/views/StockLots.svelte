<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import {
    convertStockLots,
    listStockLevelsForLots,
    listStockLots,
    receiveStockLot,
    type StockLotFilters,
    type StockLotRow
  } from '../lib/api/stock-lots';
  import { ApiError } from '../lib/api/client';
  import { listWarehouses } from '../lib/api/stocks';
  import type { StockWarehouse } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Select from '../lib/components/ui/Select.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { formatDate } from '../lib/format';
  import { router } from '../lib/router.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import {
    formatLotQuantity,
    lotStatusLabel,
    lotStatusTone,
    parseDistribution
  } from '../lib/stocks/lot-format';

  type LotLevel = Awaited<ReturnType<typeof listStockLevelsForLots>>[number];

  interface ConvertLine {
    key: number;
    lot_code: string;
    quantity: string;
    expires_at: string;
  }

  const statusOptions = [
    { value: '', label: 'Все статусы' },
    { value: 'active', label: 'В наличии' },
    { value: 'reserved', label: 'Зарезервировано' },
    { value: 'expired', label: 'Истёк срок' },
    { value: 'empty', label: 'Пустая' }
  ];

  let warehouses = $state<StockWarehouse[]>([]);
  let levels = $state<LotLevel[]>([]);
  let rows = $state<StockLotRow[]>([]);
  let total = $state(0);
  let page = $state(1);
  let perPage = $state(20);
  let loading = $state(true);
  let error = $state('');
  let referenceError = $state('');
  let notice = $state('');
  let loadSeq = 0;

  let itemId = $state(0);
  let warehouseId = $state(0);
  let lotCode = $state('');
  let expiresBefore = $state('');
  let expiresAfter = $state('');
  let status = $state('');

  const canEdit = $derived(appSettings.stockEditEnabled && auth.can('stocks.edit'));

  let receiveOpen = $state(false);
  let receiveLevelId = $state(0);
  let receiveLotCode = $state('');
  let receiveQuantity = $state('');
  let receiveExpiresAt = $state('');
  let receiveKey = $state('');
  let receiveBusy = $state(false);
  let receiveError = $state('');

  let convertOpen = $state(false);
  let convertLevelId = $state(0);
  let convertLines = $state<ConvertLine[]>([]);
  let convertKey = $state('');
  let convertBusy = $state(false);
  let convertError = $state('');
  let convertConfirm = $state(false);
  let lineSeq = 0;

  const convertibleLevels = $derived(
    levels.filter(
      (level) => level.track_lots && Number.parseFloat(level.physical_quantity) > 0
    )
  );
  const selectedConvertLevel = $derived(
    convertibleLevels.find((level) => level.id === convertLevelId) ?? null
  );
  const convertExpected = $derived(selectedConvertLevel?.physical_quantity ?? '0');
  const distribution = $derived(
    parseDistribution(
      convertLines.map((line) => ({ lot_code: line.lot_code, quantity: line.quantity })),
      convertExpected
    )
  );
  const showDistributionError = $derived(
    convertLines.some((line) => line.lot_code.trim() !== '' || line.quantity.trim() !== '') &&
      !distribution.ok
  );
  const receiveCodes = $derived.by(() => {
    const codes = new Set<string>();

    for (const row of rows) {
      if (row.stock_level_id === receiveLevelId && row.lot_code.trim() !== '') {
        codes.add(row.lot_code.trim());
      }
    }

    return [...codes].sort((a, b) => a.localeCompare(b, 'ru-RU'));
  });

  function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }

    return `lot-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }

  function isPositiveQuantity(raw: string): boolean {
    const text = raw.trim();

    if (!/^\d{1,13}(?:\.\d{1,5})?$/.test(text)) {
      return false;
    }

    const [whole, fraction = ''] = text.split('.');
    const scaled = BigInt(whole) * 100000n + BigInt((fraction + '00000').slice(0, 5));

    return scaled > 0n;
  }

  function toneColor(tone: ReturnType<typeof lotStatusTone>): string {
    switch (tone) {
      case 'success':
        return 'var(--success-text)';
      case 'processing':
        return 'var(--primary)';
      case 'warning':
        return 'var(--warning-text)';
      default:
        return 'var(--muted)';
    }
  }

  function blankLine(): ConvertLine {
    return { key: ++lineSeq, lot_code: '', quantity: '', expires_at: '' };
  }

  function applyQuery(query: URLSearchParams): void {
    const readId = (name: string): number => {
      const value = Number(query.get(name) ?? 0);

      return Number.isFinite(value) && value > 0 ? Math.trunc(value) : 0;
    };

    itemId = readId('item_id');
    warehouseId = readId('warehouse_id');
    lotCode = query.get('lot_code') ?? '';
    expiresBefore = query.get('expires_before') ?? '';
    expiresAfter = query.get('expires_after') ?? '';
    status = query.get('status') ?? '';
  }

  async function loadReferences(): Promise<void> {
    try {
      const [warehouseData, levelData] = await Promise.all([
        listWarehouses(),
        listStockLevelsForLots()
      ]);

      warehouses = warehouseData.items ?? [];
      levels = levelData;
    } catch (cause) {
      referenceError =
        cause instanceof ApiError ? cause.message : 'Не удалось загрузить товары и склады';
    }
  }

  async function loadLots(targetPage = page): Promise<void> {
    const current = ++loadSeq;

    loading = true;
    error = '';

    const filters: StockLotFilters = {
      item_id: itemId > 0 ? itemId : undefined,
      warehouse_id: warehouseId > 0 ? warehouseId : undefined,
      lot_code: lotCode.trim() || undefined,
      expires_before: expiresBefore || undefined,
      expires_after: expiresAfter || undefined,
      status: status || undefined,
      page: targetPage,
      per_page: perPage
    };

    try {
      const data = await listStockLots(filters);

      if (current !== loadSeq) {
        return;
      }

      rows = data.items ?? [];
      total = data.total ?? 0;
      page = data.page ?? targetPage;
      perPage = data.per_page ?? perPage;
    } catch (cause) {
      if (current !== loadSeq) {
        return;
      }

      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить складские партии';
      rows = [];
      total = 0;
    } finally {
      if (current === loadSeq) {
        loading = false;
      }
    }
  }

  function applyFilters(): void {
    notice = '';
    void loadLots(1);
  }

  function resetFilters(): void {
    itemId = 0;
    warehouseId = 0;
    lotCode = '';
    expiresBefore = '';
    expiresAfter = '';
    status = '';
    applyFilters();
  }

  function openReceive(): void {
    receiveLevelId = itemId > 0 && levels.some((level) => level.id === itemId) ? itemId : 0;
    receiveLotCode = '';
    receiveQuantity = '';
    receiveExpiresAt = '';
    receiveKey = newIdempotencyKey();
    receiveError = '';
    receiveBusy = false;
    receiveOpen = true;
  }

  function openConvert(): void {
    convertLevelId =
      itemId > 0 && convertibleLevels.some((level) => level.id === itemId) ? itemId : 0;
    convertLines = [blankLine()];
    convertKey = newIdempotencyKey();
    convertConfirm = false;
    convertError = '';
    convertBusy = false;
    convertOpen = true;
  }

  async function submitReceive(): Promise<void> {
    if (receiveBusy) {
      return;
    }

    const code = receiveLotCode.trim();
    const quantity = receiveQuantity.trim();

    if (receiveLevelId <= 0 || code === '' || !isPositiveQuantity(quantity)) {
      receiveError = 'Заполните склад, код партии и корректное количество';
      return;
    }

    receiveBusy = true;
    receiveError = '';

    try {
      await receiveStockLot({
        stock_level_id: receiveLevelId,
        lot_code: code,
        quantity,
        expires_at: receiveExpiresAt || undefined,
        idempotency_key: receiveKey.trim() || undefined
      });
      receiveOpen = false;
      notice = 'Партия принята';
      await loadLots();
    } catch (cause) {
      receiveError = cause instanceof ApiError ? cause.message : 'Не удалось принять партию';
    } finally {
      receiveBusy = false;
    }
  }

  async function submitConvert(): Promise<void> {
    if (convertBusy) {
      return;
    }

    if (convertLevelId <= 0) {
      convertError = 'Выберите товар для конвертации';
      return;
    }

    if (!distribution.ok) {
      convertError = distribution.error ?? 'Проверьте распределение';
      return;
    }

    if (!convertConfirm) {
      convertError = 'Подтвердите конвертацию';
      return;
    }

    convertBusy = true;
    convertError = '';

    try {
      await convertStockLots({
        stock_level_id: convertLevelId,
        lots: convertLines.map((line) => ({
          lot_code: line.lot_code.trim(),
          quantity: line.quantity.trim(),
          expires_at: line.expires_at || undefined
        })),
        idempotency_key: convertKey.trim() || undefined
      });
      convertOpen = false;
      notice = 'Остатки конвертированы в партии';
      await loadLots();
    } catch (cause) {
      convertError = cause instanceof ApiError ? cause.message : 'Не удалось конвертировать остатки';
    } finally {
      convertBusy = false;
    }
  }

  onMount(() => {
    void loadReferences();
  });

  // `null` for "not observed yet": an empty query string is a valid first load.
  let lastQueryKey: string | null = null;

  $effect(() => {
    const query = router.current.query;
    const key = query.toString();

    if (key === lastQueryKey) {
      return;
    }

    lastQueryKey = key;
    applyQuery(query);
    untrack(() => {
      void loadLots(1);
    });
  });
</script>

<div class="page">
  <header class="page-head">
    <div>
      <h1>Складские партии</h1>
      <p>Приёмка товаров, сроки годности и распределение остатков по партиям.</p>
    </div>
    <div class="head-actions">
      <Button variant="ghost" onclick={() => void loadLots()} title="Обновить список">
        <Icon name="refresh" size={16} />
        Обновить
      </Button>
      {#if canEdit}
        <Button onclick={openReceive}>
          <Icon name="plus" size={16} />
          Поступление
        </Button>
        <Button variant="ghost" onclick={openConvert}>
          <Icon name="substitutions" size={16} />
          Конвертация остатков
        </Button>
      {/if}
    </div>
  </header>

  {#if notice}
    <div class="notice" role="status">
      <Icon name="check-circle" size={16} />
      <span>{notice}</span>
      <button type="button" class="notice-close" aria-label="Скрыть сообщение" onclick={() => (notice = '')}>
        <Icon name="close" size={14} />
      </button>
    </div>
  {/if}

  {#if error}
    <div class="alert" role="alert">
      <span>{error}</span>
      <Button variant="text" size="sm" onclick={() => void loadLots()}>Повторить</Button>
    </div>
  {/if}

  {#if referenceError}
    <div class="alert" role="alert">{referenceError}</div>
  {/if}

  <section class="filters" aria-label="Фильтры партий">
    <Select label="Товар" bind:value={itemId}>
      <option value={0}>Все товары</option>
      {#each levels as level (level.id)}
        <option value={level.id}>{level.name} · {level.warehouse_name}</option>
      {/each}
    </Select>
    <Select label="Склад" bind:value={warehouseId}>
      <option value={0}>Все склады</option>
      {#each warehouses as warehouse (warehouse.id)}
        <option value={warehouse.id}>{warehouse.name}</option>
      {/each}
    </Select>
    <Input label="Код партии" bind:value={lotCode} placeholder="Например, LOT-24-001" />
    <Input label="Срок годности до" type="date" bind:value={expiresBefore} ariaLabel="Срок годности до" />
    <Input label="Срок годности с" type="date" bind:value={expiresAfter} ariaLabel="Срок годности с" />
    <Select label="Статус" bind:value={status}>
      {#each statusOptions as option (option.value)}
        <option value={option.value}>{option.label}</option>
      {/each}
    </Select>
    <div class="filter-actions">
      <Button onclick={applyFilters}><Icon name="filter" size={16} /> Применить</Button>
      <Button variant="ghost" onclick={resetFilters}>Сбросить</Button>
    </div>
  </section>

  <section class="results" aria-label="Список складских партий">
    {#if loading}
      <ListSkeleton rows={6} />
    {:else if rows.length === 0}
      <EmptyState
        icon="tags"
        title="Партии не найдены"
        description="Измените фильтры или примите первую партию."
      >
        {#snippet action()}
          {#if canEdit}
            <Button onclick={openReceive}>Принять партию</Button>
          {:else}
            <Button variant="ghost" onclick={resetFilters}>Сбросить фильтры</Button>
          {/if}
        {/snippet}
      </EmptyState>
    {:else}
      <div class="table-scroll">
        <table class="lots-table">
          <thead>
            <tr>
              <th>Товар</th>
              <th>Склад</th>
              <th>Код партии</th>
              <th>Дата поступления</th>
              <th>Срок годности</th>
              <th class="num">Физическое</th>
              <th class="num">Зарезервировано</th>
              <th class="num">Доступно</th>
              <th>Статус</th>
            </tr>
          </thead>
          <tbody>
            {#each rows as row (row.id)}
              <tr>
                <td>{row.item_name}</td>
                <td>{row.warehouse_name}</td>
                <td><span class="code">{row.lot_code}</span></td>
                <td>{formatDate(row.received_at)}</td>
                <td>{formatDate(row.expires_at)}</td>
                <td class="num">{formatLotQuantity(row.quantity_total)}</td>
                <td class="num">{formatLotQuantity(row.quantity_reserved)}</td>
                <td class="num">{formatLotQuantity(row.quantity_available)}</td>
                <td>
                  <StatusBadge
                    title={lotStatusLabel(row.status)}
                    color={toneColor(lotStatusTone(row.status))}
                  />
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>

  <Pager
    {page}
    {perPage}
    {total}
    {loading}
    onchange={(next) => {
      page = next;
      void loadLots(next);
    }}
  />
</div>

<Modal open={receiveOpen} title="Приёмка партии" onclose={() => (receiveOpen = false)}>
  <form class="modal-form" onsubmit={(event) => { event.preventDefault(); void submitReceive(); }}>
    <Select label="Товар и склад" bind:value={receiveLevelId}>
      <option value={0}>Выберите товар</option>
      {#each levels as level (level.id)}
        <option value={level.id}>{level.name} · {level.warehouse_name}</option>
      {/each}
    </Select>
    <Input label="Код партии" bind:value={receiveLotCode} list="receive-lot-codes" placeholder="LOT-24-001" />
    <datalist id="receive-lot-codes">
      {#each receiveCodes as code (code)}
        <option value={code}></option>
      {/each}
    </datalist>
    <div class="form-grid">
      <Input
        label="Количество"
        bind:value={receiveQuantity}
        inputmode="decimal"
        step="0.00001"
        placeholder="0.00000"
      />
      <Input label="Срок годности" type="date" bind:value={receiveExpiresAt} />
    </div>
    <div class="key-field">
      <span class="key-label">Ключ идемпотентности</span>
      <code>{receiveKey || '—'}</code>
      <Button
        variant="text"
        size="sm"
        onclick={() => (receiveKey = newIdempotencyKey())}
        title="Сгенерировать новый ключ"
      >
        Новый ключ
      </Button>
    </div>
    {#if receiveError}
      <div class="alert" role="alert">{receiveError}</div>
    {/if}
    <div class="modal-actions">
      <Button variant="ghost" onclick={() => (receiveOpen = false)} disabled={receiveBusy}>Отмена</Button>
      <Button type="submit" loading={receiveBusy} disabled={receiveBusy}>Принять партию</Button>
    </div>
  </form>
</Modal>

<Modal open={convertOpen} title="Конвертация остатков в партии" wide onclose={() => (convertOpen = false)}>
  <form class="modal-form" onsubmit={(event) => { event.preventDefault(); void submitConvert(); }}>
    <Select label="Товар и склад" bind:value={convertLevelId}>
      <option value={0}>Выберите товар</option>
      {#each convertibleLevels as level (level.id)}
        <option value={level.id}>{level.name} · {level.warehouse_name}</option>
      {/each}
    </Select>

    {#if convertibleLevels.length === 0}
      <p class="hint">Нет остатков с включённым учётом партий для конвертации.</p>
    {/if}

    <div class="remainder">
      <span>Требуется распределить:</span>
      <strong>{formatLotQuantity(convertExpected)}</strong>
    </div>

    <div class="lines">
      {#each convertLines as line, index (line.key)}
        <div class="line">
          <Input label={`Код партии ${index + 1}`} bind:value={line.lot_code} placeholder="LOT-24-001" />
          <Input
            label="Количество"
            bind:value={line.quantity}
            inputmode="decimal"
            step="0.00001"
            placeholder="0.00000"
          />
          <Input label="Срок годности" type="date" bind:value={line.expires_at} />
          <button
            type="button"
            class="remove-line"
            aria-label={`Удалить строку ${index + 1}`}
            title="Удалить строку"
            disabled={convertLines.length === 1}
            onclick={() => (convertLines = convertLines.filter((item) => item.key !== line.key))}
          >
            <Icon name="trash" size={16} />
          </button>
        </div>
      {/each}
    </div>

    <Button
      variant="text"
      onclick={() => (convertLines = [...convertLines, blankLine()])}
      disabled={convertLevelId <= 0}
    >
      <Icon name="plus" size={16} /> Добавить партию
    </Button>

    {#if showDistributionError}
      <div class="alert" role="alert">{distribution.error}</div>
    {/if}
    {#if convertError}
      <div class="alert" role="alert">{convertError}</div>
    {/if}

    <label class="confirm">
      <input type="checkbox" bind:checked={convertConfirm} disabled={convertBusy} />
      <span>Подтверждаю конвертацию</span>
    </label>

    <div class="key-field">
      <span class="key-label">Ключ идемпотентности</span>
      <code>{convertKey || '—'}</code>
      <Button
        variant="text"
        size="sm"
        onclick={() => (convertKey = newIdempotencyKey())}
        title="Сгенерировать новый ключ"
      >
        Новый ключ
      </Button>
    </div>

    <div class="modal-actions">
      <Button variant="ghost" onclick={() => (convertOpen = false)} disabled={convertBusy}>Отмена</Button>
      <Button
        type="submit"
        loading={convertBusy}
        disabled={convertBusy || !convertConfirm || !distribution.ok || convertLevelId <= 0}
      >
        Конвертировать
      </Button>
    </div>
  </form>
</Modal>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    flex: 1;
    min-width: 0;
  }

  .page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  h1 {
    margin: 0 0 var(--space-1);
    font-size: 22px;
    font-weight: 600;
  }

  .page-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .head-actions,
  .filter-actions,
  .modal-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .alert {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--danger-bg);
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--error-text);
    font-size: 13px;
  }

  .notice {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--success-border);
    border-radius: var(--radius-sm);
    background: var(--success-bg);
    color: var(--success-text);
    font-size: 13px;
  }

  .notice-close {
    display: inline-flex;
    margin-left: auto;
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    cursor: pointer;
  }

  .filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: var(--space-3);
    align-items: end;
    padding: var(--space-4);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .filter-actions {
    grid-column: 1 / -1;
  }

  .results {
    min-width: 0;
  }

  .table-scroll {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .lots-table {
    width: 100%;
    min-width: 980px;
    border-collapse: collapse;
    font-size: 13px;
  }

  .lots-table th,
  .lots-table td {
    padding: var(--space-2) var(--space-3);
    border-bottom: 1px solid var(--border-secondary);
    text-align: left;
    white-space: nowrap;
  }

  .lots-table th {
    color: var(--muted);
    font-weight: 500;
    background: var(--fill-tertiary);
  }

  .lots-table tbody tr:last-child td {
    border-bottom: none;
  }

  .lots-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .code {
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  }

  .modal-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .key-field {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .key-label {
    color: var(--muted);
    font-size: 12px;
  }

  .key-field code {
    font-size: 12px;
    overflow-wrap: anywhere;
  }

  .remainder {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    background: var(--primary-bg);
    color: var(--primary);
    font-size: 13px;
  }

  .lines {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    max-height: 320px;
    overflow-y: auto;
  }

  .line {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1fr) 32px;
    gap: var(--space-2);
    align-items: end;
  }

  .remove-line {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--danger);
    cursor: pointer;
  }

  .remove-line:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .confirm {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 13px;
    cursor: pointer;
  }

  .modal-actions {
    justify-content: flex-end;
    padding-top: var(--space-2);
    border-top: 1px solid var(--border-secondary);
  }

  @media (max-width: 720px) {
    .filters {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      padding: var(--space-3);
    }

    .form-grid,
    .line {
      grid-template-columns: minmax(0, 1fr);
    }

    .remove-line {
      justify-self: end;
    }

    .modal-actions :global(button) {
      flex: 1 1 0;
    }
  }
</style>
