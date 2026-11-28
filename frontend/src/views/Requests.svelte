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
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { filterStorageKey } from '../lib/stores/filter-prefs.svelte';
  import { maxPage } from '../lib/views/pagination';
  import {
    REQUEST_FIELDS,
    REQUEST_OPTIONAL_KEYS,
    normalizeRequestFields,
    initialRequestViewMode,
    listRequestFields,
    hiddenRequestFields,
    requestTileFields,
    requestMetaFields,
    type ViewMode
  } from '../lib/views/request-fields';
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
    viewExplicit: false,
    fields: { list: [...REQUEST_OPTIONAL_KEYS], tiles: [...REQUEST_OPTIONAL_KEYS] },
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
  let loadSeq = 0;
  let searchAbort: AbortController | null = null;

  let legacyView = false;
  try {
    const raw = JSON.parse(
      localStorage.getItem(filterStorageKey('requests', auth.user?.id ?? null)) ?? '{}'
    );
    legacyView =
      (raw.view === 'list' || raw.view === 'tiles') && raw.viewExplicit === undefined;
  } catch {
    legacyView = false;
  }
  const restoredViewExplicit = legacyView ? true : initialFilters.viewExplicit === true;
  let viewportWidth = $state(typeof window === 'undefined' ? 1440 : window.innerWidth);
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

  let viewMode = $state<'list' | 'tiles'>(
    initialRequestViewMode(initialFilters.view, legacyView ? undefined : restoredViewExplicit, window.innerWidth)
  );
  let viewExplicit = $state(restoredViewExplicit);
  let fieldsByView = $state<Record<ViewMode, string[]>>(
    normalizeRequestFields(initialFilters.fields)
  );
  let viewOpen = $state(false);
  let filtersOpen = $state(false);
  let filtersRef: FiltersModal | null = $state(null);
  let viewDraft = $state<{ view: ViewMode; fields: Record<ViewMode, string[]> }>({
    view: initialRequestViewMode(initialFilters.view, legacyView ? undefined : restoredViewExplicit, window.innerWidth),
    fields: normalizeRequestFields(initialFilters.fields)
  });
  let sortOpen = $state(false);
  let listAnnouncement = $state('');

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
  const columns = $derived(listRequestFields(fieldsByView.list, viewportWidth, isStaff));
  const headFields = $derived([
    REQUEST_FIELDS.find((field) => field.key === 'number')!,
    REQUEST_FIELDS.find((field) => field.key === 'subject')!,
    ...columns,
    REQUEST_FIELDS.find((field) => field.key === 'status')!
  ]);
  const hiddenFields = $derived(hiddenRequestFields(fieldsByView.list, viewportWidth, isStaff));
  const viewColumns = $derived(
    ['140px', 'minmax(0, 1.6fr)', ...columns.map((field) => field.width ?? 'minmax(120px, 1fr)'), '120px'].join(' ')
  );
  const showRowMeta = $derived(hiddenFields.length > 0 || viewportWidth <= 720);
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

    const breakpoints = [720, 900, 1024, 1200].map((width) =>
      window.matchMedia(`(min-width: ${width + 1}px)`)
    );
    const update = (): void => {
      viewportWidth = window.innerWidth;
    };
    breakpoints.forEach((query) => query.addEventListener('change', update));
    return () => breakpoints.forEach((query) => query.removeEventListener('change', update));
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
    saveFilters('requests', {
      ...filters,
      view: viewMode,
      viewExplicit,
      fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles] },
      per_page: perPage,
      q: search,
      page
    });
  }

  function abortSearch(): void {
    searchAbort?.abort();
    searchAbort = null;
  }

  function announceList(text: string): void {
    listAnnouncement = '';
    requestAnimationFrame(() => {
      listAnnouncement = text;
    });
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

  function applySortTerms(terms: string[]): void {
    filters.sort = terms.length > 0 ? terms.join(',') : filterDefaults.sort;
    changeSort();
  }

  function applySortAndClose(terms: string[]): void {
    sortOpen = false;
    applySortTerms(terms);
  }

  function changeSort(): void {
    persist();
    void load(true);
  }

  function openView(): void {
    viewDraft = {
      view: viewMode,
      fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles] }
    };
    viewOpen = true;
  }

  function applyView(): void {
    viewOpen = false;
  }

  function resetView(): void {
    viewMode = initialRequestViewMode('list', false, viewportWidth);
    viewExplicit = false;
    fieldsByView = {
      list: [...REQUEST_OPTIONAL_KEYS],
      tiles: [...REQUEST_OPTIONAL_KEYS]
    };
    viewDraft = {
      view: viewMode,
      fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles] }
    };
    persist();
    announceList(`Вид сброшен. Найдено: ${total}`);
  }

  function setViewModeNow(mode: ViewMode): void {
    viewMode = mode;
    viewExplicit = true;
    viewDraft = { ...viewDraft, view: mode };
    persist();
    announceList(`Вид: ${mode === 'tiles' ? 'плитки' : 'список'}. Найдено: ${total}`);
  }

  function isRequestFieldVisible(key: string, mode: ViewMode): boolean {
    return (
      REQUEST_FIELDS.find((field) => field.key === key)?.required === true ||
      viewDraft.fields[mode].includes(key)
    );
  }

  function toggleViewField(key: string, mode: ViewMode): void {
    const next = fieldsByView[mode].includes(key)
      ? fieldsByView[mode].filter((item) => item !== key)
      : REQUEST_OPTIONAL_KEYS.filter((item) => item === key || fieldsByView[mode].includes(item));
    fieldsByView = { ...fieldsByView, [mode]: next };
    viewDraft = { ...viewDraft, fields: { ...viewDraft.fields, [mode]: [...next] } };
    persist();
    announceList(`Вид обновлён. Найдено: ${total}`);
  }

  function openFilters(): void {
    draft = {
      ...filters,
      statusCodes: [...filters.statusCodes],
      scopes: [...filters.scopes],
      warehouses: [...filters.warehouses],
      overdue: [...filters.overdue]
    };
    warehouseDraft = filters.warehouses.map(String);
    selectedClient =
      filters.clientId > 0
        ? { id: filters.clientId, name: filters.clientName || `Клиент #${filters.clientId}` }
        : null;
    clientQuery = selectedClient?.name ?? '';
    clientResults = [];
    clientOpen = false;
    filtersRef?.show();
  }

  async function applyFilterDraft(): Promise<string> {
    const period = clampPeriod(draft.from, draft.to);
    const nextScopes = [...draft.scopes].sort();
    const nextStatuses = [...draft.statusCodes].sort();
    const nextWarehouses = warehouseDraft.map(Number).sort((a, b) => a - b);
    const nextOverdue = [...draft.overdue].sort();

    filters = {
      ...filters,
      from: period.from,
      to: period.to,
      scopes: nextScopes,
      statusCodes: nextStatuses,
      warehouses: nextWarehouses,
      overdue: nextOverdue,
      clientId: selectedClient?.id ?? 0,
      clientName: selectedClient?.name ?? ''
    };
    page = 1;
    persist();
    const result = await load(false);
    if (result.stale) {
      return '';
    }
    return result.ok
      ? `Фильтры применены. Найдено: ${result.total}`
      : 'Не удалось применить фильтры. Попробуйте ещё раз.';
  }

  async function resetFilterDraft(): Promise<string> {
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
    const result = await applyFilterDraft();
    if (result === '') {
      return '';
    }
    return result.startsWith('Не удалось') ? result : `Фильтры сброшены. ${result}`;
  }

  async function load(
    reset: boolean,
    signal?: AbortSignal
  ): Promise<{ ok: boolean; total: number; stale: boolean }> {
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
        per_page: perPage,
        signal
      });

      if (seq !== loadSeq) {
        return { ok: false, total: 0, stale: true };
      }

      const pagesCount = maxPage(data.total, perPage);
      if (page > pagesCount) {
        page = pagesCount;
        persist();
        return load(false, signal);
      }

      items = data.items;
      total = data.total;
      persist();
      return { ok: true, total: data.total, stale: false };
    } catch (cause) {
      if (seq !== loadSeq) {
        return { ok: false, total: 0, stale: true };
      }
      if (cause instanceof DOMException && cause.name === 'AbortError') {
        return { ok: false, total: 0, stale: true };
      }
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявки';
      return { ok: false, total: 0, stale: false };
    } finally {
      if (seq === loadSeq) {
        loading = false;
      }
    }
  }

  async function applyFilters(): Promise<void> {
    abortSearch();
    const controller = new AbortController();
    searchAbort = controller;
    search = searchInput.trim();
    addHistory('requests', search);
    page = 1;
    persist();
    const result = await load(false, controller.signal);
    if (controller.signal.aborted || result.stale) {
      return;
    }
    announceList(result.ok ? `Найдено: ${result.total}` : 'Не удалось выполнить поиск');
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
    persist();
    await load(false);
  }

  function changePerPage(): void {
    page = 1;
    persist();
    void load(false);
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
  <div class="sr-only" role="status" aria-live="polite" aria-hidden={viewOpen}>
    {listAnnouncement}
  </div>

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
          onsearch: async (query) => {
            searchInput = query;
            await applyFilters();
          },
          onclear: () => {
            searchInput = '';
            void applyFilters();
          },
          onstop: abortSearch
        }}
        items={[
          {
            id: 'sort',
            label: 'Сортировка',
            icon: 'sort',
            checked: filters.sort !== filterDefaults.sort,
            dialog: true,
            onclick: openSort
          },
          {
            id: 'view',
            label: `Вид: ${viewMode === 'tiles' ? 'Плитки' : 'Список'}`,
            icon: 'view',
            dialog: true,
            checked: viewMode === 'tiles' ||
              fieldsByView.list.length !== REQUEST_OPTIONAL_KEYS.length ||
              fieldsByView.tiles.length !== REQUEST_OPTIONAL_KEYS.length,
            onclick: openView
          },
          {
            id: 'filters',
            label: 'Фильтры',
            icon: 'filter',
            dialog: true,
            count: activeFilterCount > 0 ? activeFilterCount : 0,
            onclick: openFilters
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
    <div class="levels-wrap" role={viewMode === 'list' ? 'table' : undefined} aria-label={viewMode === 'list' ? 'Заявки' : undefined}>
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
                {#each requestTileFields(fieldsByView.tiles, isStaff, viewportWidth) as field (field.key)}
                  {#if field.rows(item).length > 0}
                    <span class="meta-item">
                      <span class="meta-text">{field.rows(item)[0].label}: {field.rows(item)[0].value}</span>
                    </span>
                  {/if}
                {/each}
              </div>
            </a>
          {/each}
        </div>
      {:else}
        <div class="levels-head" class:compact={!isStaff} role="row" style:--ct-columns={viewColumns}>
          {#each headFields as field (field.key)}
            <span class="head-cell {field.headClass ?? ''}" role="columnheader">
              {field.headLabel ?? field.label}
            </span>
          {/each}
        </div>

        <div class="levels" class:compact={!isStaff} role="rowgroup" style:--ct-columns={viewColumns}>
          {#each items as item (item.id)}
            <div class="card" role="row">
              <div class="cell col-number number" role="cell">
                <a
                  class="row-link"
                  href={`#${withReturnTo(`/requests/${item.id}`, currentFullRoute)}`}
                  aria-describedby={showRowMeta ? `request-meta-${item.id}` : undefined}
                >{item.number}</a>
                {#if showRowMeta}
                  <span id={`request-meta-${item.id}`} class="row-meta">
                    {#each requestMetaFields(item, hiddenFields, isStaff) as field (field.label)}
                      <span><span class="cell-label">{field.label}:</span> {field.value}</span>
                    {/each}
                  </span>
                {/if}
              </div>
              <span class="cell col-subject subject" role="cell">{item.subject}</span>
              {#each columns as field (field.key)}
                {#if field.key === 'status'}
                  <span class="cell col-status" role="cell">
                    <span class="status-text" style={`--status: ${item.status.color}`}>{item.status.title}</span>
                  </span>
                {:else if field.key === 'overdue'}
                  <span class="cell col-overdue" class:is-empty={!item.is_overdue} role="cell">
                    {#if item.is_overdue}<span class="overdue">Просрочена</span>{:else}—{/if}
                  </span>
                {:else}
                  <span class="cell {field.cellClass ?? ''}" role="cell">{field.value(item)}</span>
                {/if}
              {/each}
              <span class="cell col-status" role="cell">
                <span class="status-text" style={`--status: ${item.status.color}`}>{item.status.title}</span>
              </span>
            </div>
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

    <Modal
    open={viewOpen}
    title="Вид"
    size="md"
    announcement={listAnnouncement}
    onclose={() => (viewOpen = false)}
  >
    <div class="filters-form view-modal">
      <fieldset class="filter-group view-mode-group" aria-label="Отображение">
        <div class="view-mode-grid">
          <label class="view-mode-card" class:active={viewDraft.view === 'list'}>
            <input
              type="radio"
              name="req-view"
              value="list"
              checked={viewDraft.view === 'list'}
              onchange={() => setViewModeNow('list')}
            />
            <Icon name="list" size={18} />
            <span>Список</span>
          </label>
          <label class="view-mode-card" class:active={viewDraft.view === 'tiles'}>
            <input
              type="radio"
              name="req-view"
              value="tiles"
              checked={viewDraft.view === 'tiles'}
              onchange={() => setViewModeNow('tiles')}
            />
            <Icon name="tiles" size={18} />
            <span>Плитки</span>
          </label>
        </div>
      </fieldset>

      <fieldset class="filter-group">
        <legend class="filter-label">Поля в списке и карточках</legend>
        <div class="view-field-table">
          <span></span>
          <span class="view-field-head">Список</span>
          <span class="view-field-head">Плитки</span>
          {#each REQUEST_FIELDS as field (field.key)}
            <span class="view-field-name">
              {field.label}{#if field.required}<span class="view-field-required"> •</span>{/if}
            </span>
            <label class="view-field-check" title={field.required ? 'Обязательное поле' : undefined}>
              <input
                type="checkbox"
                checked={isRequestFieldVisible(field.key, 'list')}
                disabled={field.required}
                aria-label={`${field.label}: список`}
                onchange={() => toggleViewField(field.key, 'list')}
              />
            </label>
            <label class="view-field-check" title={field.required ? 'Обязательное поле' : undefined}>
              <input
                type="checkbox"
                checked={isRequestFieldVisible(field.key, 'tiles')}
                disabled={field.required}
                aria-label={`${field.label}: плитки`}
                onchange={() => toggleViewField(field.key, 'tiles')}
              />
            </label>
          {/each}
        </div>
        <p class="hint">Обязательные поля (•) отключить нельзя — настройки видов независимы.</p>
      </fieldset>

      <div class="modal-actions">
        <Button variant="text" size="lg" onclick={resetView}>Сбросить</Button>
        <div class="modal-buttons">
          <Button size="lg" onclick={applyView}>Закрыть</Button>
        </div>
      </div>
    </div>
  </Modal>

  <FiltersModal
    bind:this={filtersRef}
    trigger={false}
    count={activeFilterCount}
    onapply={applyFilterDraft}
    onreset={resetFilterDraft}
  >
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
        size="lg"
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
          size="lg"
        />
      </div>
    {/if}

    {#if isStaff}
      <div class="filter-group">
        <span class="filter-label">Клиент</span>
        {#if selectedClient}
          <div class="client-picked">
            <span class="client-picked-name" title={selectedClient.name}>{selectedClient.name}</span>
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
        size="lg"
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
          size="lg"
        />
      </div>
    {/if}
  </FiltersModal>


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
    font-size: var(--font-sm);
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
    font-size: var(--font-md);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .draft-meta {
    font-size: var(--font-xs);
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
    font-size: var(--font-sm);
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
    font-size: var(--font-md);
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
    font-size: var(--font-xs);
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--muted);
  }

  .tile-date {
    flex: 0 0 auto;
    font-size: var(--font-xs);
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
    font-size: var(--font-md);
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
    font-size: var(--font-xs);
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
    font-size: var(--font-xs);
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
    font-size: var(--font-md);
  }

  .row-link {
    color: var(--text);
    font-weight: 600;
    text-decoration: none;
  }

  .row-link:hover {
    color: var(--primary);
  }

  .row-link:focus-visible {
    outline: 2px solid var(--focus-soft);
    outline-offset: -2px;
  }

  .row-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1) var(--space-3);
    font-size: var(--font-xs);
    font-weight: 400;
    white-space: normal;
  }

  .view-field-table {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 72px 72px;
    align-items: center;
    gap: var(--space-1) var(--space-2);
  }

  .view-field-head {
    font-size: var(--font-xs);
    color: var(--text-description);
    text-align: center;
  }

  .view-field-name {
    font-size: var(--font-sm);
  }

  .view-field-required {
    color: var(--primary);
    font-weight: 600;
  }

  .view-field-check {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: var(--control-height-lg);
  }

  .hint {
    margin: var(--space-2) 0 0;
    font-size: var(--font-xs);
    color: var(--text-description);
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
      grid-template-columns: var(--ct-columns);
      align-items: stretch;
      padding: 0;
      background: var(--fill-tertiary);
      border: 1px solid var(--border-secondary);
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      font-size: var(--font-md);
      font-weight: 600;
      color: var(--text);
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
      font-size: var(--font-xs);
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

    .levels:not(.tiles) {
      gap: 0;
      background: var(--surface);
      border: 1px solid var(--border-secondary);
      border-radius: 0 0 var(--radius-md) var(--radius-md);
      overflow: hidden;
    }

    .levels:not(.tiles) .card {
      display: grid;
      grid-template-columns: var(--ct-columns);
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

  @media (max-width: 1023.98px) {

    .levels:not(.tiles) .cell-label {
      display: inline;
    }

    .levels:not(.tiles) .card {
      display: flex;
      flex-wrap: wrap;
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
      font-size: var(--font-sm);
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
      font-size: var(--font-sm);
    }

    .levels:not(.tiles) .col-overdue.is-empty {
      display: none;
    }
  }

  .status-text {
    color: var(--status, var(--text));
    font-size: var(--font-sm);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
  }

  .date {
    font-size: var(--font-sm);
    color: var(--muted);
    white-space: nowrap;
  }

  .number {
    font-size: var(--font-sm);
    color: var(--muted);
    font-weight: 600;
    white-space: nowrap;
  }

  .subject {
    font-size: var(--font-md);
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .overdue {
    font-size: var(--font-xs);
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
    height: var(--control-height);
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: var(--font-md);
    box-sizing: border-box;
  }

  .client-filter-input:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: inset 0 0 0 2px var(--focus-soft);
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
    font-size: var(--font-md);
  }

  .cf-meta {
    color: var(--muted);
    font-size: var(--font-xs);
  }

  .cf-empty {
    padding: 10px 12px;
    color: var(--muted);
    font-size: var(--font-sm);
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
    font-size: var(--font-md);
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
    font-size: var(--font-sm);
    line-height: 1;
    cursor: pointer;
  }

  .client-picked-clear:hover {
    border-color: var(--danger);
    color: var(--danger);
  }



</style>
