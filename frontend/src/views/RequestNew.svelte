<script lang="ts">
  import { onDestroy, onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { createRequest, getRequest, listClients, previewRequestPrices, type CreateRequestItem, type CreateRequestPayload } from '../lib/api/requests';
  import { getClientCard } from '../lib/api/clients';
  import { listLevels } from '../lib/api/stocks';
  import {
    createDraft,
    deleteDraft,
    getDraft,
    updateDraft,
    type RequestDraftPayload
  } from '../lib/api/drafts';
  import type { ClientItem } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { router } from '../lib/router.svelte';
  import { ITEMS_SORT_OPTIONS, sortItems, type ItemsSortKey } from '../lib/items-sort';
  import { formatPrice } from '../lib/format';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import ItemsPicker from '../lib/components/requests/ItemsPicker.svelte';

  let subject = $state('');
  let body = $state('');
  let descriptionOpen = $state(false);
let paramsOpen = $state(true);
  let priority = $state(2);
  let clientId = $state(0);
  let error = $state('');
  let loading = $state(false);
  let itemRows = $state<CreateRequestItem[]>([]);
  let stockMap = $state<Record<number, number>>({});
  let itemsSort = $state<ItemsSortKey>('natural');
  let pickerOpen = $state(false);
  let draftId = $state<number | null>(null);
  let draftLoading = $state(false);
  let draftBusy = $state(false);
  let draftSaving = $state(false);
  let draftSavedAt = $state<Date | null>(null);
  let draftDeleted = $state(false);
  let lastSaved = $state('');
  let submitting = $state(false);
  let saveTimer: ReturnType<typeof setTimeout> | null = null;

  let priceRows = $state<{ price: number | null; sum: number | null }[]>([]);
  let priceTotal = $state<number | null>(null);
  let priceType = $state<{ id: number; title: string } | null>(null);
  let priceTypeId = $state(0);
  let priceTypes = $state<{ id: number; title: string }[]>([]);
  let previewTimer: ReturnType<typeof setTimeout> | null = null;

  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const itemsPayload = $derived(JSON.stringify(itemRows));

  type PickedClient = {
    id: number;
    name: string;
    company: string;
    inn: string;
    phone: string;
    email: string;
  };

  let clientQuery = $state('');
  let clientResults = $state<ClientItem[]>([]);
  let clientOpen = $state(false);
  let clientSearching = $state(false);
  let selectedClient = $state<PickedClient | null>(null);
  let clientTimer: ReturnType<typeof setTimeout> | null = null;

  const canPickClient = $derived(auth.can('clients.view.all') || auth.can('clients.view.own'));
  const canCreateClient = $derived(auth.level >= 10);
  const clientRequired = $derived(auth.level >= 10);
  const hasStockErrors = $derived(itemRows.some((row) => stockError(row)));
  const totalQuantityText = $derived(
    itemRows
      .reduce((sum, row) => sum + Number(row.quantity || 0), 0)
      .toLocaleString('ru-RU', { maximumFractionDigits: 3 })
  );

  const clientReady = $derived(clientId > 0);
  const priceTypeRequired = $derived(
    pricesEnabled && priceTypes.length > 1 && priceTypeId === 0
  );

  const PRISTINE_PAYLOAD = JSON.stringify({
    subject: '',
    body: '',
    priority: 2,
    price_type_id: 0,
    client_id: 0,
    items: []
  });

  const draftPayload = $derived(
    JSON.stringify({
      subject: subject.trim(),
      body: body.trim(),
      priority,
      price_type_id: priceTypeId,
      client_id: clientId,
      items: itemRows
    })
  );

  const draftStatusText = $derived.by(() => {
    if (draftSaving) {
      return 'Сохранение…';
    }

    if (draftDeleted) {
      return 'Черновик удалён';
    }

    if (draftSavedAt !== null) {
      const time = `${String(draftSavedAt.getHours()).padStart(2, '0')}:${String(draftSavedAt.getMinutes()).padStart(2, '0')}`;
      const today = new Date();

      return draftSavedAt.toDateString() === today.toDateString()
        ? `Черновик сохранён в ${time}`
        : `Черновик сохранён ${draftSavedAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })} в ${time}`;
    }

    return '';
  });

  $effect(() => {
    const payload = draftPayload;

    if (!appSettings.salesEnabled) {
      return;
    }

    if (draftLoading || submitting || draftSaving || payload === lastSaved || payload === PRISTINE_PAYLOAD) {
      return;
    }

    if (saveTimer !== null) {
      clearTimeout(saveTimer);
    }

    saveTimer = setTimeout(() => void autoSave(payload), draftId === null ? 0 : 1500);
  });

  $effect(() => {
    const payload = itemsPayload;
    void clientId;
    void priceTypeId;
    void pricesEnabled;

    if (previewTimer !== null) {
      clearTimeout(previewTimer);
      previewTimer = null;
    }

    if (!pricesEnabled) {
      priceRows = [];
      priceTotal = null;
      priceType = null;
      priceTypes = [];

      return;
    }

    if (draftLoading || submitting) {
      return;
    }

    if (clientRequired && clientId === 0) {
      priceRows = [];
      priceTotal = null;
      priceType = null;
      priceTypes = [];

      return;
    }

    previewTimer = setTimeout(() => void refreshPrices(payload), 350);
  });

  async function refreshPrices(payload: string): Promise<void> {
    const rows = JSON.parse(payload) as CreateRequestItem[];

    try {
      const preview = await previewRequestPrices(rows, clientId, priceTypeId);

      if (!preview.enabled) {
        priceRows = [];
        priceTotal = null;
        priceType = null;
        priceTypes = [];

        return;
      }

      const types = preview.types ?? [];
      priceTypes = types;

      const availableIds = types.map((type) => type.id);
      let nextTypeId = priceTypeId;

      if (nextTypeId > 0 && !availableIds.includes(nextTypeId)) {
        nextTypeId = 0;
      }

      if (nextTypeId === 0 && availableIds.length === 1) {
        nextTypeId = availableIds[0];
      }

      if (nextTypeId !== priceTypeId) {
        priceTypeId = nextTypeId;

        if (nextTypeId > 0) {
          void refreshPrices(payload);
        }

        return;
      }

      if (nextTypeId === 0 && availableIds.length > 1) {
        priceRows = [];
        priceTotal = null;
        priceType = null;

        return;
      }

      priceRows = preview.items;
      priceTotal = preview.total;
      priceType = preview.type;
    } catch (cause) {
      if (cause instanceof ApiError && cause.code === 'price_type_invalid') {
        priceTypeId = 0;
      }

      // предпросмотр цен не критичен: сервер пересчитает цены при создании заявки
    }
  }

  onDestroy(() => {
    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    saveDraftOnPageHide();

    if (previewTimer !== null) {
      clearTimeout(previewTimer);
      previewTimer = null;
    }

    if (clientTimer !== null) {
      clearTimeout(clientTimer);
      clientTimer = null;
    }
  });

  onMount(() => {
    window.addEventListener('pagehide', saveDraftOnPageHide);

    return () => window.removeEventListener('pagehide', saveDraftOnPageHide);
  });

  onMount(() => {
    const query = router.current.query;
    const requestedDraft = Number(query.get('draft') ?? 0);

    if (requestedDraft > 0) {
      void openDraft(requestedDraft);

      return;
    }

    const copyId = Number(query.get('copy') ?? 0);

    if (copyId > 0) {
      void openCopy(copyId);

      return;
    }

    if (query.get('from') === 'cart' && cart.items.length > 0) {
      itemRows = cart.items.map((item) => ({
        warehouse_id: item.warehouseId,
        warehouse_name: item.warehouseName,
        stock_level_id: item.stockLevelId ?? null,
        name: item.name,
        unit: item.unit,
        quantity: item.quantity
      }));
    }

    const requestedClient = Number(query.get('client') ?? 0);

    if (requestedClient > 0) {
      void getClientCard(requestedClient)
        .then((data) => {
          selectedClient = { ...data.client };
          clientId = data.client.id;
          clientQuery = clientLabel(selectedClient);
          clientResults = [];
          clientOpen = false;
        })
        .catch(() => {
          // если клиент недоступен, останется обычный поиск
        });
    }
  });

  function changeRowQuantity(target: CreateRequestItem, delta: number): void {
    itemRows = itemRows.map((row) =>
      row === target
        ? { ...row, quantity: Math.max(0, Number(row.quantity ?? 0) + delta) }
        : row
    );
  }

  function removeItemRow(target: CreateRequestItem): void {
    itemRows = itemRows.filter((row) => row !== target);
  }

  async function openCopy(sourceId: number): Promise<void> {
    draftLoading = true;
    error = '';

    try {
      const detail = await getRequest(sourceId);

      subject = detail.request.subject;
      body = detail.request.body ?? '';
      descriptionOpen = body.trim() !== '';
      priority = detail.request.priority ?? 2;
      clientId = detail.request.client.id;
      selectedClient = {
        id: detail.request.client.id,
        name: detail.request.client.name,
        company: '',
        inn: detail.request.client.inn,
        phone: detail.request.client.phone,
        email: detail.request.client.email
      };
      clientQuery = clientLabel(selectedClient);
      itemRows = detail.items.map((item) => ({
        warehouse_id: item.warehouse_id,
        warehouse_name: item.warehouse_name,
        stock_level_id: item.stock_level_id,
        name: item.name,
        unit: item.unit,
        description: item.description,
        quantity: item.quantity
      }));
      const copiedTypeIds = new Set(
        detail.items
          .map((item) => item.price_type_id)
          .filter((id): id is number => id !== null && id > 0)
      );
      priceTypeId = copiedTypeIds.size === 1 ? [...copiedTypeIds][0] : 0;
      lastSaved = draftPayload;

      await loadStock();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось скопировать заявку';
    } finally {
      draftLoading = false;
    }
  }

  async function loadStock(): Promise<void> {
    const warehouseIds = Array.from(
      new Set(
        itemRows
          .filter((row) => row.stock_level_id != null && row.warehouse_id != null)
          .map((row) => row.warehouse_id as number)
      )
    );
    const map: Record<number, number> = {};

    for (const warehouseId of warehouseIds) {
      try {
        const data = await listLevels([warehouseId], { show_zero: true }, 1, 200);

        for (const level of data.items) {
          map[level.id] = level.quantity;
        }
      } catch {
        // склад недоступен — номенклатура останется непроверенной
      }
    }

    stockMap = map;
  }

  function availableStock(row: CreateRequestItem): number | null {
    if (row.stock_level_id == null) {
      return null;
    }

    const available = stockMap[row.stock_level_id];

    return available === undefined ? null : available;
  }

  function stockError(row: CreateRequestItem): boolean {
    if (appSettings.allowZeroStock) {
      return false;
    }

    const available = availableStock(row);

    return available !== null && available < Number(row.quantity ?? 0);
  }

  function stockErrorText(row: CreateRequestItem): string {
    const available = availableStock(row);

    if (available === null) {
      return 'нет остатка';
    }

    return available <= 0
      ? 'нет в наличии'
      : `остаток: ${available.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}`;
  }

  function payload(): CreateRequestPayload {
    const base: CreateRequestPayload = {
      subject: subject.trim(),
      body: body.trim(),
      priority,
      price_type_id: priceTypeId > 0 ? priceTypeId : undefined,
      items: itemRows
    };

    return { ...base, client_id: clientId > 0 ? clientId : undefined };
  }

  function clientLabel(client: PickedClient): string {
    return client.company ? `${client.name} — ${client.company}` : client.name;
  }

  async function searchClients(query: string): Promise<void> {
    clientSearching = true;

    try {
      const data = await listClients(query, 1, 15);
      clientResults = data.items.filter((client) => client.active);
    } catch {
      clientResults = [];
    } finally {
      clientSearching = false;
    }
  }

  function onClientInput(): void {
    if (selectedClient !== null && clientQuery.trim() !== clientLabel(selectedClient)) {
      selectedClient = null;
      clientId = 0;
    }

    clientOpen = true;

    if (clientTimer !== null) {
      clearTimeout(clientTimer);
    }

    clientTimer = setTimeout(() => void searchClients(clientQuery.trim()), 250);
  }

  function onClientFocus(): void {
    clientOpen = true;

    if (clientResults.length === 0 && !clientSearching) {
      void searchClients(clientQuery.trim());
    }
  }

  function selectClient(client: ClientItem): void {
    selectedClient = {
      id: client.id,
      name: client.name,
      company: client.company,
      inn: client.inn,
      phone: client.phone,
      email: client.email
    };
    clientId = client.id;
    clientQuery = clientLabel(selectedClient);
    clientResults = [];
    clientOpen = false;
  }

  function clearClient(): void {
    selectedClient = null;
    clientId = 0;
    clientQuery = '';
    clientResults = [];
    clientOpen = true;
    void searchClients('');
  }

  function onClientKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
      event.preventDefault();

      if (clientOpen && clientResults.length > 0) {
        selectClient(clientResults[0]);
      }
    } else if (event.key === 'Escape') {
      clientOpen = false;
    }
  }

  async function openDraft(id: number): Promise<void> {
    draftLoading = true;
    error = '';

    try {
      const draft = await getDraft(id);

      subject = draft.subject;
      body = draft.body;
      descriptionOpen = body.trim() !== '';
      priority = draft.priority;
      priceTypeId = draft.price_type_id ?? 0;
      clientId = draft.client_id;
      itemRows = draft.items;
      draftId = draft.id;
      draftDeleted = false;
      syncDraftUrl(draft.id);

      if (clientId > 0) {
        void getClientCard(clientId)
          .then((card) => {
            selectedClient = { ...card.client };
            clientQuery = clientLabel(selectedClient);
          })
          .catch(() => {
            selectedClient = null;
            clientQuery = '';
          });
      } else {
        selectedClient = null;
        clientQuery = '';
      }

      const updatedAt = new Date(draft.updated_at.replace(' ', 'T'));
      draftSavedAt = Number.isNaN(updatedAt.getTime()) ? new Date() : updatedAt;
      lastSaved = draftPayload;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось открыть черновик';
    } finally {
      draftLoading = false;
    }
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';

    if (!appSettings.salesEnabled) {
      error = 'Продажи отключены: оформление заявок недоступно';
      return;
    }

    if (hasStockErrors) {
      error = 'Есть номенклатура без достаточного остатка — удалите её из состава заявки';
      return;
    }

    if (clientRequired && !clientReady) {
      error = 'Выберите клиента, к которому привязать заявку';
      return;
    }

    if (priceTypeRequired) {
      error = 'Выберите тип цены для заявки';
      return;
    }

    loading = true;
    submitting = true;

    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    try {
      const data = await createRequest(payload());

      if (draftId !== null) {
        try {
          await deleteDraft(draftId);
        } catch {
          // черновик можно удалить позже
        }
      }

      if (router.current.query.get('from') === 'cart') {
        cart.clear();
      }

      router.navigate(`/requests/${data.request.id}`);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать заявку';
    } finally {
      loading = false;
      submitting = false;
    }
  }

  async function autoSave(payload: string): Promise<boolean> {
    if (submitting) {
      return false;
    }

    draftSaving = true;

    try {
      const data = JSON.parse(payload) as {
        subject: string;
        body: string;
        priority: number;
        price_type_id: number;
        client_id: number;
        items: CreateRequestItem[];
      };

      const saved = draftId === null
        ? await createDraft(data)
        : await updateDraft(draftId, data);

      draftId = saved.id;
      draftDeleted = false;
      lastSaved = payload;
      draftSavedAt = new Date();
      syncDraftUrl(saved.id);

      return true;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить черновик';

      return false;
    } finally {
      draftSaving = false;
    }
  }

  function syncDraftUrl(id: number | null): void {
    if (typeof window === 'undefined') {
      return;
    }

    const hash = id !== null ? `#/requests/new?draft=${id}` : '#/requests/new';

    if (window.location.hash === hash) {
      return;
    }

    const url = new URL(window.location.href);
    url.hash = hash;
    window.history.replaceState(null, '', url.toString());
  }

  async function flushDraft(): Promise<boolean> {
    if (!appSettings.salesEnabled || draftLoading || submitting) {
      return true;
    }

    if (draftPayload === lastSaved || draftPayload === PRISTINE_PAYLOAD || draftSaving) {
      return true;
    }

    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    return autoSave(draftPayload);
  }

  function saveDraftOnPageHide(): void {
    if (!appSettings.salesEnabled || draftLoading || submitting || draftSaving) {
      return;
    }

    if (draftPayload === lastSaved || draftPayload === PRISTINE_PAYLOAD) {
      return;
    }

    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    const data = JSON.parse(draftPayload) as RequestDraftPayload;

    if (draftId === null) {
      void createDraft(data, true).catch(() => {});
    } else {
      void updateDraft(draftId, data, true).catch(() => {});
    }
  }

  async function openClientCard(): Promise<void> {
    if (selectedClient === null || draftLoading || submitting) {
      return;
    }

    const targetId = selectedClient.id;

    if (appSettings.salesEnabled) {
      const saved = await flushDraft();

      if (!saved) {
        return;
      }
    }

    router.navigate(`/clients/${targetId}`);
  }

  async function removeDraft(): Promise<void> {
    if (draftId === null || !confirm('Удалить черновик?')) {
      return;
    }

    draftBusy = true;
    error = '';

    try {
      await deleteDraft(draftId);
      draftId = null;
      subject = '';
      body = '';
      descriptionOpen = false;
      priority = 2;
      priceTypeId = 0;
      priceTypes = [];
      priceRows = [];
      priceTotal = null;
      priceType = null;
      clientId = 0;
      selectedClient = null;
      clientQuery = '';
      clientResults = [];
      itemRows = [];
      lastSaved = '';
      draftSavedAt = null;
      draftDeleted = true;
      syncDraftUrl(null);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить черновик';
    } finally {
      draftBusy = false;
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="head">
      <a class="back" href="#/requests" title="К списку заявок" aria-label="К списку заявок">
        <Icon name="arrow-left" size={16} />
      </a>
      <div class="head-text">
        <h1>{draftId === null ? 'Новая заявка' : 'Черновик заявки'}</h1>
        <p class="sub">Оформление заявки и добавление номенклатуры</p>
      </div>
      {#if canCreateClient || draftId !== null}
        <div class="head-actions">
          {#if canCreateClient}
            <a
              class="page-icon-btn"
              href="#/clients/new"
              title="Создать клиента"
              aria-label="Создать клиента"
            >
              <span class="create-client-icon">
                <Icon name="user" size={16} />
                <span class="create-client-plus" aria-hidden="true">
                  <Icon name="plus" size={10} />
                </span>
              </span>
            </a>
          {/if}

          {#if draftId !== null}
            <button
              type="button"
              class="page-icon-btn danger"
              title="Удалить черновик"
              aria-label="Удалить черновик"
              disabled={draftBusy}
              onclick={() => void removeDraft()}
            >
              <Icon name="trash" size={18} />
            </button>
          {/if}
        </div>
      {/if}
    </div>
  </div>

  {#if draftLoading}
    <div class="card"><div class="center"><Spinner size={24} /></div></div>
  {:else}
    {#if !appSettings.salesEnabled}
        <div class="alert sales-off">
          <span class="alert-icon"><Icon name="info" size={16} /></span>
          <span>
            Продажи отключены: оформление заявок недоступно. Остатки складов можно смотреть в разделе
            «Номенклатура».
          </span>
        </div>
      {/if}

    <form class="form" onsubmit={submit}>
      {#if hasStockErrors}
        <div class="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span>
            Номенклатура, отмеченная красным, отсутствует на складе. Удалите её из состава заявки или
            обратитесь к администратору, чтобы разрешить работу с отрицательными остатками.
          </span>
        </div>
      {/if}

      {#if error}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span>{error}</span>
        </div>
      {/if}

      <div class="layout">
        <div class="main">
      <section class="card">
        <div class="card-head">
          <h2>
            Состав заявки
            {#if itemRows.length > 0}<span class="items-count">{itemRows.length}</span>{/if}
          </h2>

          <div class="items-head-actions">
            {#if itemRows.length > 1}
              <select class="items-sort" bind:value={itemsSort} aria-label="Сортировка номенклатуры">
                {#each ITEMS_SORT_OPTIONS as option (option.value)}
                  <option value={option.value}>{option.label}</option>
                {/each}
              </select>
            {/if}

            <button
              type="button"
              class="add-btn"
              title="Добавить номенклатуру"
              disabled={!appSettings.salesEnabled || priceTypeRequired}
              onclick={() => (pickerOpen = true)}
            >
              <Icon name="plus" size={14} />
              <span class="add-label">Добавить</span>
            </button>
          </div>
        </div>

        {#if itemRows.length === 0}
          <button
            type="button"
            class="items-empty"
            disabled={!appSettings.salesEnabled || priceTypeRequired}
            onclick={() => (pickerOpen = true)}
          >
            <span class="items-empty-icon"><Icon name="stocks" size={26} /></span>
            <span class="items-empty-title">Состав пуст</span>
            <span class="items-empty-text">Добавьте номенклатуру из складских остатков</span>
            <span class="items-empty-cta"><Icon name="plus" size={14} /> Добавить номенклатуру</span>
          </button>
        {:else}
          <div class="item-rows" class:no-price={!pricesEnabled}>
            <div class="item-head">
              <span>Номенклатура</span>
              <span class="num">Кол-во</span>
              {#if pricesEnabled}
                <span class="num">Цена</span>
                <span class="num">Сумма</span>
              {/if}
              <span></span>
            </div>

            {#each sortItems(itemRows, itemsSort) as row (itemRows.indexOf(row))}
              {@const rowIndex = itemRows.indexOf(row)}
              {@const rowPrice = priceRows[rowIndex]}
              <div class="item-row" class:stock-error={stockError(row)}>
                <div class="ir-main">
                  <span class="ir-name" title={row.name}>{row.name}</span>
                  {#if row.warehouse_name}<span class="ir-wh">{row.warehouse_name}</span>{/if}
                  {#if stockError(row)}
                    <span class="ir-stock-warn">{stockErrorText(row)}</span>
                  {/if}
                </div>

                <div class="ir-qty-box">
                  <button
                    type="button"
                    class="step"
                    aria-label={`Уменьшить: ${row.name}`}
                    onclick={() => changeRowQuantity(row, -1)}
                  >
                    −
                  </button>
                  <input
                    class="text-input ir-qty"
                    type="number"
                    min="0"
                    step="any"
                    bind:value={row.quantity}
                    aria-label={`Количество: ${row.name}`}
                  />
                  {#if row.unit}<span class="ir-unit">{row.unit}</span>{/if}
                  <button
                    type="button"
                    class="step"
                    aria-label={`Увеличить: ${row.name}`}
                    onclick={() => changeRowQuantity(row, 1)}
                  >
                    +
                  </button>
                </div>

                {#if pricesEnabled}
                  <span class="ir-price" class:missing={!rowPrice || rowPrice.price === null}>
                    {formatPrice(rowPrice?.price ?? null)}
                  </span>
                  <span class="ir-sum">{formatPrice(rowPrice?.sum ?? null)}</span>
                {/if}

                <button
                  type="button"
                  class="ir-del"
                  aria-label={`Убрать: ${row.name}`}
                  title="Убрать из состава"
                  onclick={() => removeItemRow(row)}
                >
                  <Icon name="trash" size={14} />
                </button>
              </div>
            {/each}
          </div>

          <div class="items-foot">
            <span>
              Позиций: <strong>{itemRows.length}</strong> · Единиц:
              <strong>{totalQuantityText}</strong>
            </span>
          </div>
        {/if}
      </section>
        </div>

        <aside class="side">
          {#if canPickClient}
            <section class="card client-card">
              <div class="card-head">
                <h2>Клиент{#if clientRequired} <span class="req">— обязательно</span>{/if}</h2>
              </div>
              <div class="stack">
                {#if selectedClient}
                  <div class="client-selected">
                    <button
                      type="button"
                      class="cs-main cs-link"
                      title="Открыть карточку клиента"
                      onclick={() => void openClientCard()}
                    >
                      <span class="cs-name">
                        {selectedClient.name}{selectedClient.company ? ` — ${selectedClient.company}` : ''}
                      </span>
                      <span class="cs-meta">
                        {#if selectedClient.inn}ИНН {selectedClient.inn}{/if}
                        {#if selectedClient.inn && (selectedClient.phone || selectedClient.email)} · {/if}
                        {selectedClient.phone || selectedClient.email || ''}
                      </span>
                    </button>
                    <button
                      type="button"
                      class="cs-clear"
                      aria-label="Сменить клиента"
                      title="Сменить клиента"
                      onclick={clearClient}
                    >
                      ✕
                    </button>
                  </div>
                {:else}
                  <div class="client-search">
                    <input
                      class="text-input"
                      type="text"
                      bind:value={clientQuery}
                      placeholder="Имя, компания, ИНН, телефон или e-mail"
                      autocomplete="off"
                      oninput={onClientInput}
                      onfocus={onClientFocus}
                      onkeydown={onClientKeydown}
                    />

                    {#if clientOpen}
                      <div class="client-list" role="listbox">
                        {#if clientSearching}
                          <div class="cl-empty">Поиск…</div>
                        {:else if clientResults.length === 0}
                          <div class="cl-empty">Ничего не найдено — уточните запрос</div>
                        {:else}
                          {#each clientResults as client (client.id)}
                            <button
                              type="button"
                              class="cl-item"
                              role="option"
                              aria-selected="false"
                              onmousedown={(event) => event.preventDefault()}
                              onclick={() => selectClient(client)}
                            >
                              <span class="cl-name">
                                {client.name}{client.company ? ` — ${client.company}` : ''}
                              </span>
                              <span class="cl-meta">
                                {#if client.inn}ИНН {client.inn}{/if}
                                {#if client.inn && client.phone} · {/if}
                                {client.phone ?? ''}
                                {#if (client.inn || client.phone) && client.email} · {/if}
                                {client.email ?? ''}
                              </span>
                            </button>
                          {/each}
                        {/if}
                      </div>
                    {/if}
                  </div>

                  {#if clientRequired}
                    <span class="hint">Выберите клиента из списка</span>
                  {/if}
                {/if}
              </div>
            </section>
          {/if}

          <section class="card params-card" class:collapsed={!paramsOpen}>
            <div class="card-head params-head">
              <h2>Параметры</h2>
              <button
                type="button"
                class="params-toggle"
                aria-expanded={paramsOpen}
                aria-controls="params-body"
                title={paramsOpen ? 'Свернуть параметры' : 'Развернуть параметры'}
                onclick={() => (paramsOpen = !paramsOpen)}
              >
                <span class="chev">
                  <Icon name="arrow-down" size={14} />
                </span>
              </button>
            </div>
            <div class="stack params-body" id="params-body">
              <Input label="Тема заявки" bind:value={subject} placeholder="Коротко: что нужно" />

              <label class="field">
                <span class="label">Приоритет</span>
                <select bind:value={priority}>
                  <option value={1}>Низкий</option>
                  <option value={2}>Обычный</option>
                  <option value={3}>Высокий</option>
                </select>
              </label>

              {#if pricesEnabled && priceTypes.length > 0}
                <label class="field">
                  <span class="label">Тип цены</span>
                  <select bind:value={priceTypeId} class:invalid={priceTypeRequired}>
                    {#if priceTypes.length > 1 && priceTypeId === 0}
                      <option value={0} disabled>— выберите тип цены —</option>
                    {/if}
                    {#each priceTypes as type (type.id)}
                      <option value={type.id}>{type.title}</option>
                    {/each}
                  </select>
                  {#if priceTypeRequired}
                    <span class="field-error">Выберите тип цены для заявки</span>
                  {/if}
                </label>
              {/if}

              {#if descriptionOpen}
                <label class="field">
                  <span class="label">Описание</span>
                  <textarea bind:value={body} rows="5" placeholder="Детали: объект, сроки, количество"></textarea>
                </label>
                <button type="button" class="link-btn" onclick={() => (descriptionOpen = false)}>
                  Свернуть описание
                </button>
              {:else}
                <button type="button" class="link-btn" onclick={() => (descriptionOpen = true)}>
                  <Icon name="plus" size={14} />
                  Добавить описание
                </button>
              {/if}
            </div>
          </section>

          <section class="card">
            <div class="card-head"><h2>Итого</h2></div>
            {#if priceTypeRequired}
              <p class="muted">Выберите тип цены, чтобы увидеть стоимость.</p>
            {:else if pricesEnabled && itemRows.length > 0}
              <div class="total-row">
                <span class="muted">Цены: {priceType?.title ?? 'по минимальной цене'}</span>
                <strong>Итого: {formatPrice(priceTotal)}</strong>
              </div>
            {:else}
              <p class="muted">Добавьте номенклатуру, чтобы рассчитать стоимость.</p>
            {/if}

            <div class="submit-block">
              {#if draftStatusText}
                <span class="draft-status">{draftStatusText}</span>
              {/if}
              <Button
                type="submit"
                loading={loading}
                disabled={!appSettings.salesEnabled || (clientRequired && !clientReady) || hasStockErrors || priceTypeRequired}
              >
                Создать заявку
              </Button>
            </div>
          </section>
        </aside>
      </div>
    </form>
    {/if}

  <ItemsPicker
    open={pickerOpen}
    items={itemRows}
    clientId={clientId}
    priceTypeId={priceTypeId}
    onclose={() => (pickerOpen = false)}
    onchange={(next) => {
      itemRows = next;
    }}
  />

</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
  }

  .card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .card-head h2 {
    margin: 0;
    font-size: 16px;
  }

  .add-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    height: 32px;
    padding: 0 15px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: var(--primary);
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.15s ease;
  }

  .add-btn:hover:not(:disabled) {
    background: var(--primary-hover);
  }

  .add-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .add-btn:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .params-toggle {
    display: none;
  }

  .chev {
    display: inline-flex;
    transition: transform 0.15s ease;
  }

  .params-toggle[aria-expanded='true'] .chev {
    transform: rotate(180deg);
  }

  .draft-status {
    color: var(--muted);
    font-size: 13px;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .main,
  .side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    min-width: 0;
  }

  .stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .field select.invalid {
    border-color: var(--danger);
    border-bottom-width: 2px;
    box-shadow: 0 0 0 2px var(--danger-bg);
  }

  .field-error {
    color: var(--danger);
    font-size: 12px;
  }

  .client-search {
    position: relative;
  }

  .create-client-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .create-client-plus {
    position: absolute;
    right: -6px;
    bottom: -5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
  }

  .client-search .text-input {
    width: 100%;
  }

  .client-list {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 320px;
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
  }

  .cl-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    padding: 8px 12px;
    border: none;
    border-bottom: 1px solid var(--border);
    background: none;
    text-align: left;
    font: inherit;
    color: inherit;
    cursor: pointer;
  }

  .cl-item:last-child {
    border-bottom: none;
  }

  .cl-item:hover {
    background: var(--bg);
  }

  .cl-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
  }

  .cl-meta {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    color: var(--muted);
  }

  .cl-empty {
    padding: 10px 12px;
    font-size: 13px;
    color: var(--muted);
  }

  .client-card {
    padding: var(--space-4);
  }

  .client-card .card-head {
    margin-bottom: var(--space-2);
  }

  .client-card .stack {
    gap: var(--space-2);
  }

  .client-selected {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 0;
  }

  .cs-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
  }

  .cs-link {
    padding: 0;
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .cs-link:hover .cs-name {
    color: var(--primary);
    text-decoration: underline;
  }

  .cs-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
  }

  .cs-meta {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    color: var(--muted);
  }

  .cs-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
  }

  .cs-clear:hover {
    border-color: var(--danger);
    color: var(--danger);
  }

  .items-head-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
  }

  .link-btn {
    display: inline-flex;
    align-items: center;
    align-self: flex-start;
    gap: 6px;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
  }

  .link-btn:hover {
    color: var(--primary-hover);
    text-decoration: underline;
  }

  .items-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    margin-left: 6px;
    padding: 0 7px;
    border-radius: 999px;
    background: var(--bg);
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    vertical-align: middle;
  }

  .items-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    padding: var(--space-6) var(--space-3);
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
    background: var(--bg);
    color: var(--text);
    font: inherit;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .items-empty:hover:not(:disabled) {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 4%, var(--surface));
  }

  .items-empty:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .items-empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--primary);
  }

  .items-empty-title {
    font-size: 15px;
    font-weight: 600;
  }

  .items-empty-text {
    font-size: 13px;
    color: var(--muted);
  }

  .items-empty-cta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    color: var(--primary);
    font-size: 13px;
    font-weight: 500;
  }

  .items-sort {
    max-width: 250px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    font: inherit;
    font-size: 13px;
  }

  .label {
    font-size: 13px;
    color: var(--muted);
  }

  .req {
    color: var(--danger);
    font-size: 12px;
  }

  .hint {
    color: var(--text-description);
    font-size: 12px;
  }

  select,
  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: inherit;
    outline: none;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .text-input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    outline: none;
  }

  .text-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .muted {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-4) 0;
  }

  .item-rows {
    display: flex;
    flex-direction: column;
  }

  .item-head,
  .item-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 150px 100px 110px 28px;
    align-items: center;
    gap: var(--space-2);
  }

  .item-rows.no-price .item-head,
  .item-rows.no-price .item-row {
    grid-template-columns: minmax(0, 1fr) 150px 28px;
  }

  .item-head {
    padding: 0 0 6px;
    border-bottom: 1px solid var(--border-secondary);
    color: var(--muted);
    font-size: 12px;
  }

  .item-head .num {
    text-align: right;
  }

  .item-rows .item-row:last-child {
    border-bottom: none;
  }

  .item-row {
    padding: 8px 0;
    border-bottom: 1px solid var(--border-secondary);
    font-size: 14px;
    transition: background-color 0.12s ease;
  }

  .item-row:hover {
    background: var(--fill-tertiary);
  }

  .item-row.stock-error {
    padding-inline: 8px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
  }

  .ir-stock-warn {
    color: var(--danger);
    font-size: 12px;
    white-space: nowrap;
  }

  .ir-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .ir-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ir-wh {
    font-size: 12px;
    color: var(--muted);
  }

  .ir-qty-box {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  .ir-qty {
    width: 52px;
    height: 28px;
    padding: 0 6px;
    text-align: center;
  }

  .ir-unit {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .ir-price,
  .ir-sum {
    text-align: right;
    white-space: nowrap;
    font-size: 13px;
  }

  .ir-sum {
    font-weight: 600;
  }

  .ir-price.missing {
    color: var(--muted);
  }

  .items-foot {
    display: flex;
    justify-content: flex-end;
    padding-top: var(--space-2);
    color: var(--muted);
    font-size: 12px;
  }

  .items-foot strong {
    color: var(--text);
    font-weight: 600;
  }

  .total-row {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .total-row strong {
    font-size: 16px;
  }

  .submit-block {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }

  .submit-block :global(button) {
    width: 100%;
  }

  .ir-del {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    line-height: 1;
    cursor: pointer;
  }

  .ir-del:hover {
    border-color: var(--danger);
    color: var(--danger);
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    color: var(--text);
    font-size: 14px;
  }

  .alert.sales-off {
    border-color: var(--warning);
    background: var(--warning-bg);
    margin-bottom: var(--space-3);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .alert.sales-off .alert-icon {
    color: var(--warning-text);
  }

  @media (max-width: 900px) {
    .layout {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 640px) {
    .item-head {
      display: none;
    }

    .item-row,
    .item-rows.no-price .item-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px var(--space-2);
    }

    .ir-main {
      flex: 1 1 100%;
    }

    .ir-del {
      margin-left: auto;
    }
  }

  @media (max-width: 720px) {
    .head .back {
      align-self: flex-start;
    }

    .add-label {
      display: none;
    }

    .add-btn {
      width: var(--control-height);
      padding: 0;
    }

    .params-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      padding: 0;
      border: none;
      border-radius: var(--radius-sm);
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      transition: color 0.12s ease, background 0.12s ease;
    }

    .params-toggle:hover {
      background: rgba(0, 0, 0, 0.05);
      color: var(--text);
    }

    .params-toggle:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px var(--focus-ring);
    }

    .params-card.collapsed .params-body {
      display: none;
    }

    .params-card.collapsed .params-head {
      margin-bottom: 0;
    }

    .params-card.collapsed {
      padding-top: 10px;
      padding-bottom: 10px;
    }
  }
</style>
