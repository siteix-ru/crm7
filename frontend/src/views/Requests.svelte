<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    emailRequests,
    exportRequests,
    listClients,
    listRequests,
    listStatuses,
    requestFilters,
    type RequestFilters,
    type RequestWarehouseOption
  } from '../lib/api/requests';
  import { listDrafts, deleteDraft, type RequestDraftSummary } from '../lib/api/drafts';
  import type { ClientItem, RequestItem, RequestStatus } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import { addHistory } from '../lib/search-history';
  import { withReturnTo } from '../lib/navigation-context';
  import Button from '../lib/components/ui/Button.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { formatDate, formatDateTime } from '../lib/format';
  import { allTimePeriod, clampPeriod, todayIso } from '../lib/period';

  const filterDefaults = {
    statusCodes: [] as string[],
    scopes: [] as string[],
    warehouses: [] as number[],
    overdue: [] as string[],
    clientId: 0,
    clientName: '',
    ...allTimePeriod(),
    periodVersion: 2,
    sort: 'created_desc',
    view: 'list',
    per_page: 20,
    q: '',
    page: 1
  };

  const scopeOptions = [
    { value: 'none', label: 'Без менеджера' },
    { value: 'mine', label: 'В работе у меня' },
    { value: 'others', label: 'В работе у других' }
  ];

  const overdueOptions = [
    { value: 'yes', label: 'Просроченные' },
    { value: 'no', label: 'Без просрочки' }
  ];

  const perPageOptions = [10, 20, 50, 100];

  let statuses = $state<RequestStatus[]>([]);
  let items = $state<RequestItem[]>([]);
  let total = $state(0);
  let loading = $state(true);
  let error = $state('');
  const initialFilters = loadFilters('requests', filterDefaults);

  // Saved filters from the previous 30-day default must not keep hiding older requests.
  if (initialFilters.periodVersion !== 2) {
    initialFilters.from = '';
    initialFilters.to = '';
    initialFilters.periodVersion = 2;
  }

  let filters = $state({ ...initialFilters });
  let draft = $state({ ...initialFilters });
  let page = $state(typeof initialFilters.page === 'number' && initialFilters.page > 0 ? initialFilters.page : 1);
  let perPage = $state(
    perPageOptions.includes(Number(initialFilters.per_page)) || Number(initialFilters.per_page) === 0
      ? Number(initialFilters.per_page)
      : 20
  );
  let search = $state(String(initialFilters.q ?? ''));
  let searchInput = $state(String(initialFilters.q ?? ''));
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  let loadSeq = 0;
  const currentFullRoute = $derived(
    `${router.current.path}${router.current.query.toString() ? `?${router.current.query.toString()}` : ''}`
  );
  let warehouses = $state<RequestWarehouseOption[]>([]);
  let warehouseDraft = $state<string[]>([]);
  let selectedClient = $state<{ id: number; name: string } | null>(null);
  let clientQuery = $state('');
  let clientResults = $state<ClientItem[]>([]);
  let clientSearching = $state(false);
  let clientOpen = $state(false);
  let clientMenuStyle = $state('');
  let clientInputEl = $state<HTMLInputElement | null>(null);
  let clientTimer: ReturnType<typeof setTimeout> | undefined;
  let exportOpen = $state(false);
  let exporting = $state(false);
  let message = $state('');

  $effect(() => {
    const term = searchInput.trim();
    clearTimeout(searchTimer);

    if (term === search) {
      return;
    }

    searchTimer = setTimeout(() => {
      search = term;
      void load(true);
      persist();
    }, 400);

    return () => clearTimeout(searchTimer);
  });

  $effect(() => {
    if (!clientOpen) {
      return;
    }

    void clientResults;
    updateClientMenuPosition();

    const handlePosition = () => updateClientMenuPosition();
    window.addEventListener('scroll', handlePosition, true);
    window.addEventListener('resize', handlePosition);

    return () => {
      window.removeEventListener('scroll', handlePosition, true);
      window.removeEventListener('resize', handlePosition);
    };
  });

  let viewMode = $state<'list' | 'tiles'>(initialFilters.view === 'tiles' ? 'tiles' : 'list');
  let viewOpen = $state(false);
  let viewDraft = $state<'list' | 'tiles'>('list');
  let sortOpen = $state(false);

  const sortFields = [
    { field: 'number', label: 'Номер' },
    { field: 'subject', label: 'Тема' },
    { field: 'client', label: 'Клиент', staffOnly: true },
    { field: 'manager', label: 'Менеджер' },
    { field: 'positions', label: 'Номенклатуры' },
    { field: 'status', label: 'Статус' },
    { field: 'due', label: 'Срок' },
    { field: 'priority', label: 'Приоритет' },
    { field: 'created', label: 'Дата создания' }
  ];

  let draftsOpen = $state(false);
  let drafts = $state<RequestDraftSummary[]>([]);
  let draftsLoading = $state(false);
  let draftsError = $state('');
  let draftBusy = $state(0);
  let draftCount = $state(0);

  const isStaff = $derived(auth.level >= 10);
  const statusOptions = $derived(
    statuses.map((status) => ({ value: status.code, label: status.title }))
  );
  const warehouseOptions = $derived(
    warehouses.map((warehouse) => ({ value: String(warehouse.id), label: warehouse.name }))
  );
  const emailAllowed = $derived(appSettings.emailExportEnabled && appSettings.mailConfigured);
  const availableSortFields = $derived(
    sortFields.filter((item) => !item.staffOnly || isStaff)
  );
  const activeFilterCount = $derived(
    (filters.statusCodes.length > 0 ? 1 : 0) +
      (filters.scopes.length > 0 ? 1 : 0) +
      (filters.warehouses.length > 0 ? 1 : 0) +
      (filters.overdue.length > 0 ? 1 : 0) +
      (filters.clientId > 0 ? 1 : 0) +
      (filters.from !== filterDefaults.from || filters.to !== filterDefaults.to ? 1 : 0)
  );
  const searchActive = $derived(search.trim() !== '');

  onMount(() => {
    void listStatuses()
      .then((data) => {
        statuses = data;
      })
      .catch(() => {
        statuses = [];
      });

    void requestFilters()
      .then((data) => {
        warehouses = data.warehouses;
      })
      .catch(() => {
        warehouses = [];
      });

    void refreshDraftCount();
    void load(false);
  });

  async function refreshDraftCount(): Promise<void> {
    try {
      draftCount = (await listDrafts()).items.length;
    } catch {
      draftCount = 0;
    }
  }

  function filterClientLabel(client: ClientItem): string {
    return client.company ? `${client.name} — ${client.company}` : client.name;
  }

  function updateClientMenuPosition(): void {
    if (clientInputEl === null) {
      return;
    }

    const rect = clientInputEl.getBoundingClientRect();
    const gap = 4;
    const viewportGap = 8;
    const width = Math.min(
      Math.max(rect.width, 320),
      window.innerWidth - viewportGap * 2
    );
    const left = Math.max(
      viewportGap,
      Math.min(rect.left, window.innerWidth - width - viewportGap)
    );
    const spaceBelow = window.innerHeight - rect.bottom - gap - viewportGap;
    const spaceAbove = rect.top - gap - viewportGap;
    const placeAbove = spaceBelow < 120 && spaceAbove > spaceBelow;
    const height = Math.max(100, Math.min(320, placeAbove ? spaceAbove : spaceBelow));

    clientMenuStyle = [
      'position: fixed',
      'z-index: 60',
      `left: ${left}px`,
      `width: ${width}px`,
      placeAbove
        ? `bottom: ${window.innerHeight - rect.top + gap}px`
        : `top: ${rect.bottom + gap}px`,
      `max-height: ${height}px`
    ].join('; ');
  }

  async function searchFilterClients(query: string): Promise<void> {
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

  function onClientFilterInput(): void {
    if (selectedClient !== null && clientQuery.trim() !== selectedClient.name) {
      selectedClient = null;
    }

    clientOpen = true;
    clearTimeout(clientTimer);
    clientTimer = setTimeout(() => void searchFilterClients(clientQuery.trim()), 250);
  }

  function onClientFilterFocus(): void {
    clientOpen = true;

    if (clientResults.length === 0 && !clientSearching) {
      void searchFilterClients(clientQuery.trim());
    }
  }

  function selectFilterClient(client: ClientItem): void {
    const label = filterClientLabel(client);
    selectedClient = { id: client.id, name: label };
    clientQuery = label;
    clientResults = [];
    clientOpen = false;
  }

  function clearFilterClient(): void {
    selectedClient = null;
    clientQuery = '';
    clientResults = [];
    clientOpen = true;
    void searchFilterClients('');
  }

  function onClientFilterKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
      event.preventDefault();

      if (clientOpen && clientResults.length > 0) {
        selectFilterClient(clientResults[0]);
      }
    } else if (event.key === 'Escape') {
      clientOpen = false;
    }
  }

  function handleClientFilterFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;
    const current = event.currentTarget as HTMLElement | null;

    if (next === null || current === null || !current.contains(next)) {
      clientOpen = false;
    }
  }

  function persist(): void {
    saveFilters('requests', { ...filters, view: viewMode, per_page: perPage, q: search, page });
  }

  function resetFilters(): void {
    const period = allTimePeriod();

    selectedClient = null;
    clientQuery = '';
    clientResults = [];
    clientOpen = false;
    draft = {
      ...draft,
      statusCodes: [],
      scopes: [],
      warehouses: [],
      overdue: [],
      clientId: 0,
      clientName: '',
      from: period.from,
      to: period.to
    };
    filters = {
      ...filters,
      statusCodes: [],
      scopes: [],
      warehouses: [],
      overdue: [],
      clientId: 0,
      clientName: '',
      from: period.from,
      to: period.to
    };
    persist();
    void load(true);
  }

  function resetFiltersAndSearch(): void {
    search = '';
    searchInput = '';
    resetFilters();
  }

  function openSort(): void {
    sortOpen = true;
  }

  function sortTerms(): string[] {
    return filters.sort.split(',').filter((term) => term !== '');
  }

  function sortIndex(field: string): number {
    return sortTerms().findIndex((term) => term === field || term.startsWith(`${field}_`)) + 1;
  }

  function applySortTerms(terms: string[]): void {
    filters.sort = terms.length > 0 ? terms.join(',') : filterDefaults.sort;
    changeSort();
  }

  function applySortAndClose(terms: string[]): void {
    sortOpen = false;
    applySortTerms(terms);
  }

  function sortTerm(field: string): 'asc' | 'desc' | null {
    const terms = sortTerms();

    if (terms.includes(`${field}_asc`) || terms.includes(field)) {
      return 'asc';
    }

    if (terms.includes(`${field}_desc`)) {
      return 'desc';
    }

    return null;
  }

  function headerSort(field: string): void {
    const terms = sortTerms();
    const asc = `${field}_asc`;
    const desc = `${field}_desc`;
    const isAsc = (term: string): boolean => term === asc || term === field;
    const isDefault = terms.length === 1 && terms[0] === filterDefaults.sort;
    let next: string[];

    if (terms.some(isAsc)) {
      next = terms.map((term) => (isAsc(term) ? desc : term));
    } else if (terms.includes(desc)) {
      next = terms.filter((term) => term !== desc);
    } else if (isDefault) {
      next = [asc];
    } else {
      next = [...terms, asc];
    }

    applySortTerms(next);
  }

  function changeSort(): void {
    persist();
    void load(true);
  }

  function openView(): void {
    viewDraft = viewMode;
    warehouseDraft = filters.warehouses.map(String);
    draft = {
      ...filters,
      statusCodes: [...filters.statusCodes],
      scopes: [...filters.scopes],
      warehouses: [...filters.warehouses],
      overdue: [...filters.overdue]
    };
    selectedClient =
      filters.clientId > 0
        ? {
            id: filters.clientId,
            name: filters.clientName || `Клиент #${filters.clientId}`
          }
        : null;
    clientQuery = selectedClient?.name ?? '';
    clientResults = [];
    clientOpen = false;
    viewOpen = true;
  }

  function resetView(): void {
    const period = allTimePeriod();

    selectedClient = null;
    clientQuery = '';
    clientResults = [];
    clientOpen = false;
    warehouseDraft = [];
    draft = {
      ...draft,
      statusCodes: [],
      scopes: [],
      warehouses: [],
      overdue: [],
      clientId: 0,
      clientName: '',
      from: period.from,
      to: period.to
    };
    viewDraft = 'list';
  }

  function applyView(): void {
    viewOpen = false;

    const period = clampPeriod(draft.from, draft.to);
    const nextScopes = [...draft.scopes].sort();
    const currentScopes = [...filters.scopes].sort();
    const nextStatuses = [...draft.statusCodes].sort();
    const currentStatuses = [...filters.statusCodes].sort();
    const nextWarehouses = warehouseDraft.map(Number).sort((a, b) => a - b);
    const currentWarehouses = [...filters.warehouses].sort((a, b) => a - b);
    const nextOverdue = [...draft.overdue].sort();
    const currentOverdue = [...filters.overdue].sort();
    const nextClientId = selectedClient?.id ?? 0;
    const nextClientName = selectedClient?.name ?? '';
    const clientChanged =
      nextClientId !== filters.clientId || nextClientName !== filters.clientName;
    const filtersChanged =
      period.from !== filters.from ||
      period.to !== filters.to ||
      nextScopes.length !== currentScopes.length ||
      nextScopes.some((value, index) => value !== currentScopes[index]) ||
      nextStatuses.length !== currentStatuses.length ||
      nextStatuses.some((value, index) => value !== currentStatuses[index]) ||
      nextWarehouses.length !== currentWarehouses.length ||
      nextWarehouses.some((value, index) => value !== currentWarehouses[index]) ||
      nextOverdue.length !== currentOverdue.length ||
      nextOverdue.some((value, index) => value !== currentOverdue[index]) ||
      clientChanged;

    viewMode = viewDraft;

    if (filtersChanged) {
      filters.from = period.from;
      filters.to = period.to;
      filters.scopes = nextScopes;
      filters.statusCodes = nextStatuses;
      filters.warehouses = nextWarehouses;
      filters.overdue = nextOverdue;
      filters.clientId = nextClientId;
      filters.clientName = nextClientName;
      persist();
      void load(true);
      return;
    }

    persist();
  }

  async function load(reset: boolean): Promise<void> {
    const seq = ++loadSeq;

    if (reset) {
      page = 1;
    }

    loading = true;
    error = '';

    try {
      const data = await listRequests({
        statuses: filters.statusCodes.length > 0 ? filters.statusCodes : undefined,
        manager_scopes: filters.scopes.length > 0 ? filters.scopes : undefined,
        q: search,
        warehouse_ids: filters.warehouses.length > 0 ? filters.warehouses : undefined,
        overdue: filters.overdue.length > 0 ? filters.overdue : undefined,
        client_id: filters.clientId > 0 ? filters.clientId : undefined,
        from: filters.from,
        to: filters.to,
        sort: filters.sort,
        page,
        per_page: perPage
      });

      if (seq !== loadSeq) {
        return;
      }

      items = data.items;
      total = data.total;

      const maxPage = Math.max(1, Math.ceil(data.total / perPage));

      if (page > maxPage) {
        page = maxPage;
        persist();
        void load(false);
        return;
      }

      persist();
    } catch (cause) {
      if (seq === loadSeq) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявки';
      }
    } finally {
      if (seq === loadSeq) {
        loading = false;
      }
    }
  }

  function applyFilters(): void {
    search = searchInput.trim();
    addHistory('requests', search);
    void load(true);
  }

  async function goToPage(target: number): Promise<void> {
    if (!Number.isFinite(target)) {
      return;
    }

    const next = Math.max(1, Math.trunc(target));

    if (next === page) {
      return;
    }

    page = next;
    await load(false);
  }

  function changePerPage(): void {
    page = 1;
    void load(true);
  }

  async function doExport(format: string, byEmail: boolean): Promise<void> {
    exportOpen = false;
    exporting = true;
    error = '';
    message = '';

    const payload: RequestFilters = {
      statuses: filters.statusCodes.length > 0 ? filters.statusCodes : undefined,
      manager_scopes: filters.scopes.length > 0 ? filters.scopes : undefined,
      q: search,
      warehouse_ids: filters.warehouses.length > 0 ? filters.warehouses : undefined,
      overdue: filters.overdue.length > 0 ? filters.overdue : undefined,
      client_id: filters.clientId > 0 ? filters.clientId : undefined,
      from: filters.from,
      to: filters.to,
      sort: filters.sort
    };

    try {
      if (byEmail) {
        const result = await emailRequests(payload, format);
        message = result.sent
          ? `Файл отправлен на ${result.email}`
          : 'Не удалось отправить файл';
      } else {
        await exportRequests(payload, format);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось выполнить экспорт';
    } finally {
      exporting = false;
    }
  }

  async function loadDrafts(): Promise<void> {
    draftsLoading = true;
    draftsError = '';

    try {
      drafts = (await listDrafts()).items;
      draftCount = drafts.length;
    } catch (cause) {
      draftsError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить черновики';
    } finally {
      draftsLoading = false;
    }
  }

  async function openDrafts(): Promise<void> {
    draftsOpen = true;

    await loadDrafts();
  }

  function openDraft(id: number): void {
    draftsOpen = false;
    router.navigate(`/requests/new?draft=${id}`);
  }

  async function removeDraft(id: number): Promise<void> {
    if (!confirm('Удалить черновик?')) {
      return;
    }

    draftBusy = id;
    draftsError = '';

    try {
      await deleteDraft(id);
      await loadDrafts();
    } catch (cause) {
      draftsError = cause instanceof ApiError ? cause.message : 'Не удалось удалить черновик';
    } finally {
      draftBusy = 0;
    }
  }
</script>

<section class="page page-wide">
  {#snippet headCell(className: string, label: string, field: string)}
    <button
      type="button"
      class="head-cell {className}"
      class:active={sortTerm(field) !== null}
      aria-label={sortTerm(field) === 'asc'
        ? `${label}: по возрастанию`
        : sortTerm(field) === 'desc'
          ? `${label}: по убыванию`
          : `${label}: сортировать`}
      onclick={() => headerSort(field)}
    >
      <span class="head-label">{label}</span>
      {#if sortTerm(field)}
        <span class="sort-ind" aria-hidden="true">
          <Icon name={sortTerm(field) === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          <span class="sort-num">{sortIndex(field)}</span>
        </span>
      {/if}
    </button>
  {/snippet}

  {#if !appSettings.salesEnabled}
    <div class="notice sales-off">
      <span class="notice-icon"><Icon name="info" size={16} /></span>
      <span>
        Продажи отключены: оформление новых заявок и черновиков недоступно, остатки складов доступны
        для просмотра.
      </span>
    </div>
  {/if}

  <PageToolbar title="Заявки" actionsLabel="Действия со списком заявок">
    {#snippet actions()}
      <ToolbarMenu
        label="Действия со списком заявок"
        search={{
          value: search,
          historyKey: 'requests',
          title: 'Поиск по заявкам',
          placeholder: 'Тема, клиент, номер, телефон, описание',
          suggestions: items.map((item) => item.subject),
          onsearch: (query) => {
            clearTimeout(searchTimer);
            searchInput = query;
            applyFilters();
          },
          onclear: () => {
            clearTimeout(searchTimer);
            searchInput = '';
            applyFilters();
          }
        }}
        items={[
          {
            id: 'sort',
            label: 'Сортировка',
            icon: 'sort',
            checked: filters.sort !== filterDefaults.sort,
            onclick: openSort
          },
          {
            id: 'view',
            label: 'Вид и фильтры',
            icon: 'view',
            checked: viewMode === 'tiles',
            onclick: openView
          },
          {
            id: 'drafts',
            label: `Черновики заявок${draftCount > 0 ? ` (${draftCount})` : ''}`,
            icon: 'requests',
            onclick: () => void openDrafts()
          },
          { id: 'sep-1', label: '', separator: true, onclick: () => {} },
          {
            id: 'export',
            label: 'Экспорт списка заявок',
            icon: 'export',
            disabled: exporting || total === 0,
            onclick: () => (exportOpen = true)
          },
          ...(appSettings.salesEnabled && auth.can('requests.create')
            ? [
                { id: 'sep-2', label: '', separator: true, onclick: () => {} },
                {
                  id: 'create',
                  label: 'Новая заявка',
                  icon: 'plus',
                  onclick: () => router.navigate('/requests/new')
                }
              ]
            : [])
        ]}
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
    <div class="notice" role="status">
      <span class="notice-icon success-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  {#if loading && items.length === 0}
    <ListSkeleton rows={6} />
  {:else if items.length === 0}
    {#if activeFilterCount > 0}
      <div class="empty">
        <p class="empty-title">Заявки не найдены</p>
        <p class="empty-hint">
          Включены фильтры — часть заявок может быть скрыта. Сбросьте фильтры, чтобы увидеть все
          заявки.
        </p>
        <Button variant="ghost" onclick={resetFiltersAndSearch}>Сбросить фильтры</Button>
      </div>
    {:else if searchActive}
      <div class="empty">
        <p class="empty-title">Заявки не найдены</p>
        <p class="empty-hint">Уточните запрос или очистите поиск.</p>
        <Button variant="ghost" onclick={resetFiltersAndSearch}>Очистить поиск</Button>
      </div>
    {:else}
      <EmptyState
        icon="requests"
        title="Заявок пока нет"
        description="Создайте первую заявку — кнопкой ниже или из корзины на складе."
      >
        {#snippet action()}
          <Button onclick={() => router.navigate('/requests/new')}>Новая заявка</Button>
        {/snippet}
      </EmptyState>
    {/if}
  {:else}
    <div class="levels-wrap">
      {#if viewMode === 'tiles'}
        <div class="tiles">
          {#each items as item (item.id)}
            <a
              class="tile"
              class:is-overdue={item.is_overdue}
              href={`#${withReturnTo(`/requests/${item.id}`, currentFullRoute)}`}
            >
              <div class="tile-head">
                <span class="tile-number">{item.number}</span>
                <span class="tile-date">{formatDateTime(item.created_at)}</span>
              </div>
              <div class="tile-subject" title={item.subject}>{item.subject}</div>
              <div class="tile-status">
                <span class="status-chip" style={`--status: ${item.status.color}`}>
                  <span class="status-chip-text">{item.status.title}</span>
                </span>
                {#if item.is_overdue}<span class="overdue">Просрочена</span>{/if}
              </div>
              <div class="tile-meta">
                {#if isStaff}
                  <span class="meta-item" title={item.client.name}>
                    <Icon name="clients" size={13} />
                    <span class="meta-text">{item.client.name}</span>
                  </span>
                {/if}
                <span
                  class="meta-item"
                  title={item.manager ? item.manager.name : 'Поиск менеджера'}
                >
                  <Icon name="staff" size={13} />
                  <span class="meta-text">
                    {#if item.manager}
                      {item.manager.name}
                    {:else}
                      <span class="search-badge">поиск менеджера</span>
                    {/if}
                  </span>
                </span>
                {#if item.due_at}
                  <span class="meta-item" class:is-overdue={item.is_overdue} title="Срок исполнения">
                    <Icon name="calendar" size={13} />
                    <span class="meta-text">до {formatDate(item.due_at)}</span>
                  </span>
                {/if}
                <span class="meta-item meta-count" title="Номенклатуры в заявке">
                  <Icon name="stocks" size={13} />
                  <span>{item.items_count} поз.</span>
                </span>
              </div>
            </a>
          {/each}
        </div>
      {:else}
        <div class="levels-head" class:compact={!isStaff}>
          {@render headCell('col-number', 'Номер', 'number')}
          {@render headCell('col-subject', 'Тема', 'subject')}
          {#if isStaff}{@render headCell('col-client', 'Клиент', 'client')}{/if}
          {@render headCell('col-manager', 'Менеджер', 'manager')}
          {@render headCell('col-count', 'Номенклатуры', 'positions')}
          {@render headCell('col-status', 'Статус', 'status')}
          {@render headCell('col-overdue', 'Просрочка', 'due')}
          {@render headCell('col-date', 'Дата', 'created')}
        </div>

        <div class="levels" class:compact={!isStaff}>
          {#each items as item (item.id)}
            <a class="card" href={`#${withReturnTo(`/requests/${item.id}`, currentFullRoute)}`}>
              <span class="cell col-number number">{item.number}</span>
              <span class="cell col-subject subject">{item.subject}</span>
              {#if isStaff}
                <span class="cell col-client">
                  <span class="cell-label">клиент: </span>{item.client.name}
                </span>
              {/if}
              <span class="cell col-manager">
                <span class="cell-label">менеджер: </span>
                {#if item.manager}
                  {item.manager.name}
                {:else}
                  <span class="search-badge">поиск менеджера</span>
                {/if}
              </span>
              <span class="cell col-count">
                <span class="cell-label">номенклатуры: </span>{item.items_count}
              </span>
              <span class="cell col-status">
                <span class="status-text" style={`color: ${item.status.color}`}>{item.status.title}</span>
              </span>
              <span class="cell col-overdue" class:is-empty={!item.is_overdue}>
                {#if item.is_overdue}<span class="overdue">Просрочена</span>{:else}—{/if}
              </span>
              <span class="cell col-date date">{formatDateTime(item.created_at)}</span>
            </a>
          {/each}
        </div>
      {/if}
    </div>

    <Pager
      {page}
      {perPage}
      {total}
      loading={loading}
      perPageOptions={perPageOptions}
      onchange={(next) => void goToPage(next)}
      onperpage={(value) => {
        perPage = value;
        changePerPage();
      }}
    />
  {/if}

  <SortDialog
    open={sortOpen}
    fields={availableSortFields}
    sort={filters.sort}
    defaultValue={filterDefaults.sort}
    onclose={() => (sortOpen = false)}
    onapply={applySortAndClose}
  />

  <Modal open={viewOpen} title="Вид и фильтры" onclose={() => (viewOpen = false)}>
    <div class="filters-form view-modal">
      <div class="view-filters-body">
        <div class="filter-group view-mode-group">
          <span class="filter-label">Вид</span>
          <div class="view-mode-grid">
            <label class="view-mode-card" class:active={viewDraft === 'list'}>
              <input type="radio" name="req-view" value="list" bind:group={viewDraft} />
              <Icon name="list" size={18} />
              <span>Список</span>
            </label>
            <label class="view-mode-card" class:active={viewDraft === 'tiles'}>
              <input type="radio" name="req-view" value="tiles" bind:group={viewDraft} />
              <Icon name="tiles" size={18} />
              <span>Плитки</span>
            </label>
          </div>
        </div>

    <div class="view-period">
      <label class="filter-field">
        <span>Период с</span>
        <input type="date" bind:value={draft.from} max={draft.to || undefined} />
      </label>
      <label class="filter-field">
        <span>Период по</span>
        <input type="date" bind:value={draft.to} min={draft.from || undefined} max={todayIso()} />
      </label>
    </div>

    <div class="filter-group">
      <span class="filter-label" title="Если ничего не выбрано — все статусы">Статусы</span>
      <MultiSelect
        label="Статусы"
        options={statusOptions}
        bind:values={draft.statusCodes}
        allLabel="Все статусы"
        emptyLabel="Нет статусов"
        maxHeight="300px"
      />
    </div>

    {#if warehouses.length > 0}
      <div class="filter-group">
        <span class="filter-label" title="Если ничего не выбрано — все склады">Склады</span>
        <MultiSelect
          label="Склады"
          options={warehouseOptions}
          bind:values={warehouseDraft}
          allLabel="Все склады"
          emptyLabel="Нет складов"
        />
      </div>
    {/if}

    {#if isStaff}
      <div class="filter-group">
        <span class="filter-label">Клиент</span>

        {#if selectedClient}
          <div class="client-picked">
            <span class="client-picked-name" title={selectedClient.name}>
              {selectedClient.name}
            </span>
            <button
              type="button"
              class="client-picked-clear"
              aria-label="Очистить клиента"
              title="Очистить клиента"
              onclick={clearFilterClient}
            >
              ✕
            </button>
          </div>
        {:else}
          <div class="client-filter" onfocusout={handleClientFilterFocusOut}>
            <input
              class="client-filter-input"
              type="text"
              bind:this={clientInputEl}
              bind:value={clientQuery}
              placeholder="Имя, компания, ИНН или телефон"
              autocomplete="off"
              aria-label="Клиент"
              oninput={onClientFilterInput}
              onfocus={onClientFilterFocus}
              onkeydown={onClientFilterKeydown}
            />

            {#if clientOpen}
              <div class="client-filter-menu" role="listbox" aria-label="Клиенты" style={clientMenuStyle}>
                {#if clientSearching}
                  <div class="cf-empty">Поиск…</div>
                {:else if clientResults.length === 0}
                  <div class="cf-empty">Ничего не найдено — уточните запрос</div>
                {:else}
                  {#each clientResults as client (client.id)}
                    <button
                      type="button"
                      class="cf-item"
                      role="option"
                      aria-selected="false"
                      onmousedown={(event) => event.preventDefault()}
                      onclick={() => selectFilterClient(client)}
                    >
                      <span class="cf-name">{filterClientLabel(client)}</span>
                      <span class="cf-meta">
                        {#if client.inn}ИНН {client.inn}{/if}
                        {#if client.inn && client.phone} · {/if}
                        {client.phone || client.email || ''}
                      </span>
                    </button>
                  {/each}
                {/if}
              </div>
            {/if}
          </div>
        {/if}
      </div>
    {/if}

    <div class="filter-group">
      <span class="filter-label" title="Если ничего не выбрано — все заявки">Просрочка</span>
      <MultiSelect
        label="Просрочка"
        options={overdueOptions}
        bind:values={draft.overdue}
        allLabel="Все заявки"
      />
    </div>

    {#if isStaff}
      <div class="filter-group">
        <span class="filter-label" title="Если ничего не выбрано — все заявки">Менеджеры</span>
        <MultiSelect
          label="Менеджеры"
          options={scopeOptions}
          bind:values={draft.scopes}
          allLabel="Все заявки"
        />
      </div>
    {/if}

      </div>

      <div class="modal-actions">
        <Button variant="text" size="lg" onclick={resetView}>Сбросить</Button>
        <div class="modal-buttons">
          <Button variant="ghost" size="lg" onclick={() => (viewOpen = false)}>Отмена</Button>
          <Button size="lg" onclick={applyView}>Применить</Button>
        </div>
      </div>
    </div>
  </Modal>

  <Modal open={draftsOpen} title="Черновики заявок" onclose={() => (draftsOpen = false)}>
    {#if draftsLoading}
      <div class="center"><Spinner size={22} /></div>
    {:else if drafts.length === 0}
      <p class="muted">Черновиков нет</p>
    {:else}
      <div class="draft-list">
        {#each drafts as draft (draft.id)}
          <div class="draft-row">
            <button
              type="button"
              class="draft-main"
              title="Открыть черновик"
              onclick={() => openDraft(draft.id)}
            >
              <span class="draft-name">{draft.subject || 'Без темы'}</span>
              <span class="draft-meta">
                номенклатуры: {draft.items_count} · обновлён {formatDateTime(draft.updated_at)}
              </span>
            </button>
            <button
              type="button"
              class="draft-del"
              aria-label={`Удалить черновик: ${draft.subject || 'Без темы'}`}
              disabled={draftBusy === draft.id}
              onclick={() => void removeDraft(draft.id)}
            >
              ✕
            </button>
          </div>
        {/each}
      </div>
    {/if}

    {#if draftsError}
      <div class="alert">{draftsError}</div>
    {/if}

    <div class="modal-actions">
      <div class="modal-buttons">
        <Button variant="ghost" onclick={() => (draftsOpen = false)}>Закрыть</Button>
      </div>
    </div>
  </Modal>

  <ExportModal
    open={exportOpen}
    title="Экспорт списка заявок"
    email={auth.user?.email ?? ''}
    emailAllowed={emailAllowed}
    onclose={() => (exportOpen = false)}
    onpick={(format, byEmail) => void doExport(format, byEmail)}
  />
</section>

<style>
  .muted {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .draft-list {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-3);
  }

  .draft-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
  }

  .draft-main {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .draft-main:hover .draft-name {
    color: var(--primary);
  }

  .draft-name {
    font-size: 14px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .draft-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .draft-del {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
  }

  .draft-del:hover:not(:disabled) {
    border-color: var(--danger);
    color: var(--danger);
  }

  .draft-del:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .page:has(.tiles) {
    max-width: 1600px;
  }

  .head {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
  }

  .sales-off {
    margin-bottom: var(--space-3);
  }

  .notice,
  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .notice.sales-off {
    border-color: var(--warning);
    background: var(--warning-bg);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice-icon,
  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
  }

  .notice-icon {
    color: var(--warning-text);
  }

  .success-icon {
    color: var(--success-text);
  }

  .alert-icon {
    color: var(--danger);
  }

  .levels-wrap {
    display: flex;
    flex-direction: column;
  }

  .levels-head {
    display: none;
  }

  .levels {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
    gap: var(--space-3);
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    padding: 14px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    text-decoration: none;
    color: inherit;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
  }

  .tile:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
  }

  .tile.is-overdue {
    box-shadow: inset 3px 0 0 var(--danger), var(--shadow-sm);
  }

  .tile.is-overdue:hover {
    box-shadow: inset 3px 0 0 var(--danger), var(--shadow-md);
  }

  .tile-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .tile-number {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--muted);
  }

  .tile-date {
    flex: 0 0 auto;
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
  }

  .tile-subject {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.7em;
    font-size: 15px;
    font-weight: 600;
    line-height: 1.35;
    overflow-wrap: anywhere;
  }

  .tile-status {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-1);
    min-width: 0;
  }

  .status-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
    padding: 2px 10px;
    border-radius: 999px;
    background: var(--bg);
    background: color-mix(in srgb, var(--status) 10%, var(--surface));
    color: var(--status);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.6;
  }

  .status-chip::before {
    content: '';
    flex: 0 0 auto;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
  }

  .status-chip-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tile-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
    margin-top: auto;
    padding-top: 8px;
    border-top: 1px solid var(--border-secondary);
    font-size: 12px;
    color: var(--muted);
  }

  .meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-width: 0;
    max-width: 100%;
  }

  .meta-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .meta-item.is-overdue {
    color: var(--danger);
  }

  .meta-count {
    margin-left: auto;
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    text-decoration: none;
    color: inherit;
  }

  .card:hover {
    border-color: var(--primary);
    background: var(--fill-tertiary);
  }

  .cell {
    min-width: 0;
    font-size: 14px;
  }

  .cell-label {
    display: none;
  }

  @media (min-width: 1024px) {
    .levels-head {
      position: sticky;
      top: 0;
      z-index: 2;
      display: grid;
      grid-template-columns: 140px minmax(0, 1.6fr) minmax(0, 1fr) 140px 110px 180px 120px 140px;
      align-items: stretch;
      padding: 0;
      background: var(--fill-tertiary);
      border: 1px solid var(--border-secondary);
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      font-size: 14px;
      font-weight: 600;
      color: var(--text);
    }

    .levels-head.compact {
      grid-template-columns: 140px minmax(0, 1.6fr) 140px 110px 180px 120px 140px;
    }

    .head-cell {
      position: relative;
      display: flex;
      align-items: center;
      gap: 4px;
      padding: 12px var(--space-4);
      min-width: 0;
      border: none;
      background: none;
      font: inherit;
      color: inherit;
      text-align: left;
      cursor: pointer;
    }

    .head-cell:hover {
      color: var(--primary);
    }

    .head-cell.active {
      color: var(--primary);
    }

    .head-label {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sort-ind {
      display: inline-flex;
      align-items: center;
      gap: 2px;
      flex: 0 0 auto;
    }

    .sort-num {
      min-width: 15px;
      height: 15px;
      padding: 0 4px;
      border-radius: 999px;
      background: color-mix(in srgb, var(--primary) 14%, white);
      font-size: 10px;
      line-height: 15px;
      text-align: center;
    }

    .head-cell:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 50%;
      right: 0;
      width: 1px;
      height: 1.6em;
      background: var(--border-secondary);
      transform: translateY(-50%);
    }

    .levels-head .col-count,
    .levels-head .col-overdue,
    .levels-head .col-date {
      justify-content: flex-end;
      text-align: right;
    }

    .levels-head .col-count .sort-ind,
    .levels-head .col-overdue .sort-ind,
    .levels-head .col-date .sort-ind {
      order: -1;
    }

    .levels:not(.tiles) {
      gap: 0;
      background: var(--surface);
      border: 1px solid var(--border-secondary);
      border-radius: 0 0 var(--radius-md) var(--radius-md);
      overflow: hidden;
    }

    .levels:not(.tiles) .card {
      display: grid;
      grid-template-columns: 140px minmax(0, 1.6fr) minmax(0, 1fr) 140px 110px 180px 120px 140px;
      align-items: center;
      gap: 0;
      padding: 0;
      border: none;
      border-bottom: 1px solid var(--border-secondary);
      border-radius: 0;
      box-shadow: none;
    }

    .levels:not(.tiles) .card > .cell {
      padding: 12px var(--space-4);
    }

    .levels:not(.tiles).compact .card {
      grid-template-columns: 140px minmax(0, 1.6fr) 140px 110px 180px 120px 140px;
    }

    .levels:not(.tiles) .card:last-child {
      border-bottom: none;
    }

    .levels:not(.tiles) .col-count,
    .levels:not(.tiles) .col-date,
    .levels:not(.tiles) .col-overdue {
      text-align: right;
    }

    .levels:not(.tiles) .col-status {
      display: flex;
      align-items: center;
      gap: var(--space-1);
      flex-wrap: wrap;
      overflow: hidden;
    }

    .levels:not(.tiles) .col-subject {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .levels:not(.tiles) .col-client,
    .levels:not(.tiles) .col-manager {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--muted);
    }

    .levels:not(.tiles) {
      border-top: none;
    }
  }

  /* Ноутбуки 1024–1280 px: оставляем таблицу, убираем второстепенную колонку. */
  @media (max-width: 1279.98px) and (min-width: 1024px) {
    .levels-head {
      grid-template-columns: 132px minmax(0, 1.8fr) minmax(0, 1fr) 124px 170px 116px 132px;
    }

    .levels-head.compact {
      grid-template-columns: 132px minmax(0, 1.8fr) 124px 170px 116px 132px;
    }

    .levels-head .col-count {
      display: none;
    }

    .levels:not(.tiles) .card {
      grid-template-columns: 132px minmax(0, 1.8fr) minmax(0, 1fr) 124px 170px 116px 132px;
    }

    .levels:not(.tiles).compact .card {
      grid-template-columns: 132px minmax(0, 1.8fr) 124px 170px 116px 132px;
    }

    .levels:not(.tiles) .col-count {
      display: none;
    }
  }

  @media (max-width: 1023.98px) {

    .levels:not(.tiles) .cell-label {
      display: inline;
    }

    .levels:not(.tiles) .card {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        'number date'
        'subject subject'
        'status overdue'
        'client client'
        'manager count';
      align-items: baseline;
      gap: 4px var(--space-3);
    }

    .levels:not(.tiles) .col-number {
      grid-area: number;
    }

    .levels:not(.tiles) .col-date {
      grid-area: date;
      justify-self: end;
    }

    .levels:not(.tiles) .col-subject {
      grid-area: subject;
      white-space: normal;
    }

    .levels:not(.tiles) .col-status {
      grid-area: status;
      display: flex;
      align-items: center;
      gap: var(--space-1);
      flex-wrap: wrap;
      min-width: 0;
    }

    .levels:not(.tiles) .col-overdue {
      grid-area: overdue;
      justify-self: end;
    }

    .levels:not(.tiles) .col-manager {
      grid-area: manager;
      color: var(--muted);
      font-size: 13px;
    }

    .levels:not(.tiles) .col-count {
      grid-area: count;
      justify-self: end;
      color: var(--muted);
    }

    .levels:not(.tiles) .col-client {
      grid-area: client;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--muted);
      font-size: 13px;
    }

    .levels:not(.tiles) .col-overdue.is-empty {
      display: none;
    }
  }

  .status-text {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
  }

  .date {
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
    white-space: nowrap;
  }

  .subject {
    font-size: 15px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .overdue {
    font-size: 12px;
    color: var(--danger);
    background: var(--danger-bg);
    border-radius: 999px;
    padding: 2px 10px;
  }

  .search-badge {
    color: var(--warning-text);
    font-weight: 500;
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--muted);
    background: var(--surface);
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
  }

  .empty-title {
    margin: 0;
    color: var(--text);
    font-weight: 600;
  }

  .empty-hint {
    margin: var(--space-2) 0 var(--space-4);
    max-width: 44ch;
    margin-inline: auto;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  .client-filter {
    position: relative;
  }

  .client-filter-input {
    width: 100%;
    height: 34px;
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    box-sizing: border-box;
  }

  .client-filter-input:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .client-filter-menu {
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
  }

  .cf-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    padding: 8px 12px;
    border: none;
    border-bottom: 1px solid var(--border);
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .cf-item:last-child {
    border-bottom: none;
  }

  .cf-item:hover {
    background: var(--bg);
  }

  .cf-name {
    font-size: 14px;
  }

  .cf-meta {
    color: var(--muted);
    font-size: 12px;
  }

  .cf-empty {
    padding: 10px 12px;
    color: var(--muted);
    font-size: 13px;
  }

  .client-picked {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 8px 6px 12px;
    border: 1px solid var(--primary);
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .client-picked-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
  }

  .client-picked-clear {
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

  .client-picked-clear:hover {
    border-color: var(--danger);
    color: var(--danger);
  }



</style>
