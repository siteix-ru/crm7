<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    emailLevels, exportLevels, listLevels, listWarehouses, searchCounts, type StockFilters
  } from '../lib/api/stocks';
  import type {
    PriceListItem, StockLevel, StockWarehouse, ItemGroup
  } from '../lib/api/types';
  import { listItemGroups } from '../lib/api/groups';
  import { listPriceTypes } from '../lib/api/prices';
  import Button from '../lib/components/ui/Button.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import { tablist } from '../lib/a11y/tablist';
  import Modal from '../lib/components/ui/Modal.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import PhotoGallery from '../lib/components/stocks/PhotoGallery.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { addHistory, getHistory } from '../lib/search-history';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { router } from '../lib/router.svelte';
  import { config } from '../lib/config';
  import { formatDate, formatPrice, stockQuantityText } from '../lib/format';
  import { groupStockLevels, sortStockColumns } from '../lib/stocks/columns';
  import {
    STOCK_FIELDS,
    STOCK_VIEW_MODES,
    normalizeStockFields,
    stockFields,
    type StockViewMode
  } from '../lib/views/stock-fields';

  let warehouses = $state<StockWarehouse[]>([]);
  let levels = $state<StockLevel[]>([]);
  let selectedIds = $state<number[]>([]);
  const stockFilterDefaults = {
    sort: 'name_asc', group_ids: null as number[] | null, group_no_group: true, per_page_list: 20, per_page_tiles: 12, view: 'list', photos: true, price_types: [] as number[], warehouse_ids: null as number[] | null, query: '', page: 1, active: 'all', qty_op: '', qty: '',
    fields: { list: [...STOCK_FIELDS.map((f) => f.key)], tiles: [...STOCK_FIELDS.map((f) => f.key)], columns: [...STOCK_FIELDS.map((f) => f.key)] }
  };
  const initialStockFilters = loadFilters('stocks', stockFilterDefaults);
  let fieldsByView = $state<Record<StockViewMode, string[]>>(
    normalizeStockFields(initialStockFilters.fields)
  );
  const initialWarehouseIds = Array.isArray(initialStockFilters.warehouse_ids)
    ? (initialStockFilters.warehouse_ids as number[])
        .map(Number)
        .filter((id) => Number.isFinite(id) && id > 0)
    : [];

  let query = $state(String(initialStockFilters.query));
  let searchInput = $state(String(initialStockFilters.query));
  let page = $state<number>(typeof initialStockFilters.page === 'number' ? initialStockFilters.page : 1);
  let total = $state(0);

  let loading = $state(true);
  let loadingLevels = $state(false);
  let error = $state('');
  let message = $state('');
  let listAnnouncement = $state('');

  function announceList(text: string): void {
    listAnnouncement = '';
    requestAnimationFrame(() => {
      listAnnouncement = text;
    });
  }
  let canEdit = $state(false);
  let busy = $state(false);
  let exportOpen = $state(false);
  let counts = $state<Record<string, number>>({});
  let priceTypeTitle = $state('');
  let priceTypeFilter = $state<number[]>(
    Array.isArray(initialStockFilters.price_types)
      ? (initialStockFilters.price_types as number[])
          .map(Number)
          .filter((id) => Number.isFinite(id) && id > 0)
      : []
  );
  let priceTypesDraft = $state<string[]>([]);

  let groups = $state<ItemGroup[]>([]);
  let groupsLoaded = $state(false);
  let groupsPromise: Promise<void> | null = null;
  let countsSeq = 0;

  const warehousesEnabled = $derived(appSettings.warehousesEnabled);
  const canExport = $derived(auth.can('stocks.export'));
  const sortFields = $derived.by(() => {
    const fields = [{ field: 'name', label: 'Номенклатура' }];

    if (viewMode === 'columns') {
      for (const warehouse of selectedWarehouses) {
        fields.push({ field: `warehouse_${warehouse.id}`, label: warehouse.name });
      }

      return fields;
    }

    fields.push({ field: 'qty', label: 'Остаток' });

    if (warehousesEnabled && viewMode === 'list') {
      fields.push({ field: 'warehouse', label: 'Склад' });
    }

    if (pricesEnabled) {
      fields.push({ field: 'price', label: 'Цена' });
    }

    return fields;
  });
  const groupsPreselected = Array.isArray(initialStockFilters.group_ids);
  let stockSort = $state(initialStockFilters.sort);
  let stockGroups = $state<number[]>(parseGroups(initialStockFilters.group_ids));
  let stockNoGroup = $state<boolean>(initialStockFilters.group_no_group !== false);
  const initialStatus = String(initialStockFilters.active ?? 'all');
  let statusActive = $state(initialStatus !== 'inactive');
  let statusInactive = $state(initialStatus !== 'active');
  const stockActive = $derived(
    statusActive && !statusInactive
      ? 'active'
      : statusInactive && !statusActive
        ? 'inactive'
        : 'all'
  );
  const canDeactivate = $derived(appSettings.stockEditEnabled && auth.can('stocks.deactivate'));
  const statusOptions = [
    { value: 'active', label: 'Активированные' }, { value: 'inactive', label: 'Деактивированные' }
  ];
  const QTY_OPS = ['gt', 'eq', 'lt'] as const;
  const qtyOpOptions = [
    { value: 'gt', label: 'Больше' }, { value: 'eq', label: 'Равно' }, { value: 'lt', label: 'Меньше' }
  ];

  function parseQtyOps(value: unknown): string[] {
    const raw = Array.isArray(value) ? value : String(value ?? '').split(',');
    const selected = new Set(raw.map((item) => String(item).trim()));

    return QTY_OPS.filter((op) => selected.has(op));
  }

  const initialQtyOps = parseQtyOps(initialStockFilters.qty_op);
  const initialQtyValue =
    initialQtyOps.length > 0 && Number.isFinite(Number(initialStockFilters.qty))
      ? String(Number(initialStockFilters.qty))
      : '';
  let qtyOps = $state<string[]>(initialQtyOps);
  let qty = $state(initialQtyValue);
  let statusDraft = $state<string[]>([]);
  let qtyOpsDraft = $state<string[]>(initialQtyOps);
  let qtyDraft = $state<string | number>(initialQtyValue);
  const PAGE_SIZES = [10, 20, 50, 100];
  const initialView: 'list' | 'tiles' | 'columns' =
    initialStockFilters.view === 'tiles'
      ? 'tiles'
      : initialStockFilters.view === 'columns'
        ? 'columns'
        : 'list';

  function normalizePageSize(value: unknown, fallback: number): number {
    const size = Number(value);

    return PAGE_SIZES.includes(size) || size === 0 ? size : fallback;
  }

  const initialPerPageList = normalizePageSize(initialStockFilters.per_page_list, 20);
  const initialPerPageTiles = normalizePageSize(initialStockFilters.per_page_tiles, 20);
  let viewMode = $state<'list' | 'tiles' | 'columns'>(initialView);
  let perPageList = $state<number>(initialPerPageList);
  let perPageTiles = $state<number>(initialPerPageTiles);
  let perPage = $state<number>(initialView === 'tiles' ? initialPerPageTiles : initialPerPageList);
  let showPhotos = $state<boolean>(initialStockFilters.photos !== false);
  let viewOpen = $state(false);
  let filtersRef: FiltersModal | null = $state(null);
  let moreOpen = $state(false);
  let moreEl = $state<HTMLElement | null>(null);

  function moreTrigger(): HTMLElement | null {
    return moreEl?.querySelector<HTMLElement>('button[aria-haspopup="menu"]') ?? null;
  }

  function closeMore(returnFocus: boolean): void {
    moreOpen = false;
    if (returnFocus) {
      requestAnimationFrame(() => moreTrigger()?.focus());
    }
  }

  function runMoreAction(run: () => void): void {
    moreOpen = false;
    run();
  }

  async function openMore(): Promise<void> {
    moreOpen = !moreOpen;
    if (!moreOpen) {
      return;
    }
    await tick();
    moreEl
      ?.querySelector<HTMLElement>(
        '[role="menuitem"]:not([disabled]), [role="menuitemcheckbox"]:not([disabled])'
      )
      ?.focus();
  }

  function onMoreKeydown(event: KeyboardEvent): void {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      return;
    }
    const items = Array.from(
      moreEl?.querySelectorAll<HTMLElement>(
        '[role="menuitem"]:not([disabled]), [role="menuitemcheckbox"]:not([disabled])'
      ) ?? []
    );
    if (items.length === 0) {
      return;
    }
    event.preventDefault();
    const current = items.indexOf(document.activeElement as HTMLElement);
    const next =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? items.length - 1
          : event.key === 'ArrowDown'
            ? (current + 1) % items.length
            : current <= 0
              ? items.length - 1
              : current - 1;
    items[next]?.focus();
  }

  onMount(() => {
    const onPointerDown = (event: PointerEvent): void => {
      if (!moreOpen) {
        return;
      }

      if (moreEl !== null && event.target instanceof Node && !moreEl.contains(event.target)) {
        closeMore(true);
      }
    };
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape' && moreOpen) {
        closeMore(true);
      }
    };

    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown, true);
      document.removeEventListener('keydown', onKeyDown);
    };
  });

  let viewDraft = $state<{
    view: StockViewMode;
    photo: boolean;
    fields: Record<StockViewMode, string[]>;
  }>({
    view: initialView,
    photo: initialStockFilters.photos !== false,
    fields: normalizeStockFields(initialStockFilters.fields)
  });
  let sortOpen = $state(false);
  let groupOpen = $state(false);
  let groupDraft = $state<number[]>([]);
  let groupNoGroupDraft = $state<boolean>(true);
  let hasUngrouped = $state(true);
  let warehouseOpen = $state(false);
  let warehouseDraft = $state<number[]>([]);
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  let searchAbort: AbortController | null = null;

  function abortSearch(): void {
    searchAbort?.abort();
    searchAbort = null;
  }
  let loadSeq = 0;

  const columnRows = $derived.by(() => groupStockLevels(levels));
  const columnTotal = $derived(columnRows.length);
  const columnSortTerms = $derived(parseSort(stockSort));
  const columnSort = $derived.by(() => {
    const term = columnSortTerms[0] ?? 'name_asc';
    const dir: 'asc' | 'desc' = term.endsWith('_desc') ? 'desc' : 'asc';
    const base = term.replace(/_(asc|desc)$/, '');
    const warehouseId = base.startsWith('warehouse_') ? Number(base.slice(10)) : 0;
    const field: 'name' | number = warehouseId > 0 ? warehouseId : 'name';

    return { field, dir };
  });
  const sortedColumnRows = $derived(sortStockColumns(columnRows, columnSortTerms));
  const columnPageRows = $derived.by(() => {
    if (perPage <= 0) {
      return sortedColumnRows;
    }

    const start = (page - 1) * perPage;

    return sortedColumnRows.slice(start, start + perPage);
  });
  const pagerTotal = $derived(viewMode === 'columns' ? columnTotal : total);

  let columnsScrollEl = $state<HTMLDivElement | null>(null);
  let columnsHeadScrollEl = $state<HTMLDivElement | null>(null);
  let columnsCanScroll = $state(false);
  let columnsAtStart = $state(true);
  let columnsAtEnd = $state(false);

  function updateColumnsScrollState(): void {
    const el = columnsScrollEl;

    if (!el) {
      columnsCanScroll = false;
      columnsAtStart = true;
      columnsAtEnd = true;
      return;
    }

    const head = columnsHeadScrollEl;

    if (head) {
      head.scrollLeft = el.scrollLeft;
    }

    const max = el.scrollWidth - el.clientWidth;

    columnsCanScroll = max > 1;
    columnsAtStart = el.scrollLeft <= 1;
    columnsAtEnd = el.scrollLeft >= max - 1;
  }

  function scrollColumns(direction: -1 | 1): void {
    const el = columnsScrollEl;

    if (!el) {
      return;
    }

    el.scrollBy({
      left: direction * Math.max(180, el.clientWidth * 0.8), behavior: 'smooth'
    });
  }

  function toggleColumnSort(field: 'name' | number): void {
    const base = field === 'name' ? 'name' : `warehouse_${field}`;
    const current = parseSort(stockSort);
    const existing = current.find((term) => term.startsWith(`${base}_`));
    const direction = existing?.endsWith('_asc') ? 'desc' : 'asc';
    const next = [
      `${base}_${direction}`, ...current.filter((term) => !term.startsWith(`${base}_`))
    ];
    const value = next.join(',');

    if (value === stockSort) {
      return;
    }

    stockSort = value;
    page = 1;
    saveStockFilters();
  }

  function syncColumnsFromHead(): void {
    if (
      columnsScrollEl
      && columnsHeadScrollEl
      && columnsScrollEl.scrollLeft !== columnsHeadScrollEl.scrollLeft
    ) {
      columnsScrollEl.scrollLeft = columnsHeadScrollEl.scrollLeft;
    }
  }

  function syncColumnsFromBody(): void {
    if (
      columnsScrollEl
      && columnsHeadScrollEl
      && columnsHeadScrollEl.scrollLeft !== columnsScrollEl.scrollLeft
    ) {
      columnsHeadScrollEl.scrollLeft = columnsScrollEl.scrollLeft;
    }
  }

  $effect(() => {
    const el = columnsScrollEl;

    if (!el || viewMode !== 'columns') {
      columnsCanScroll = false;
      return;
    }

    const update = () => updateColumnsScrollState();
    const observer = new ResizeObserver(update);

    update();
    observer.observe(el);

    const table = el.querySelector('table');

    if (table) {
      observer.observe(table);
    }

    el.addEventListener('scroll', update, { passive: true });

    return () => {
      observer.disconnect();
      el.removeEventListener('scroll', update);
    };
  });

  const canCreate = $derived(auth.can('requests.create') && appSettings.cartEnabled);
  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const groupsEnabled = $derived(appSettings.groupsEnabled);
  const availableSortFields = $derived(
    // Сервер понимает только `warehouse`, а не `warehouse_<id>` — не предлагаем неработающие поля.
    sortFields.filter(
      (item) =>
        (item.field !== 'price' || pricesEnabled) && !item.field.startsWith('warehouse_')
    )
  );
  let priceTypes = $state<{ id: number; title: string }[]>([]);
  let priceTypesLoaded = $state(false);
  const availablePriceTypes = $derived.by((): { id: number; title: string }[] => {
    if (priceTypes.length > 0) {
      return priceTypes;
    }

    const map = new Map<number, string>();

    for (const level of levels) {
      for (const row of level.price_list ?? []) {
        if (!map.has(row.id)) {
          map.set(row.id, row.title);
        }
      }
    }

    return [...map.entries()].map(([id, title]) => ({ id, title }));
  });
  const priceTypeOptions = $derived(
    availablePriceTypes.map((type) => ({ value: String(type.id), label: type.title }))
  );
  const listVars = $derived(
    `--photo: ${showPhotos ? '88px' : '0px'}; --price: ${pricesEnabled ? '150px' : '0px'}; --cart: ${canCreate ? '120px' : '0px'};`
  );

  $effect(() => {
    if (groupsEnabled && !groupsLoaded) {
      void loadGroups();
    }
  });

  $effect(() => {
    if (pricesEnabled && !priceTypesLoaded) {
      void loadPriceTypes();
    }
  });

  $effect(() => {
    const term = searchInput.trim();
    clearTimeout(searchTimer);

    if (term === query) {
      return;
    }

    searchTimer = setTimeout(() => {
      void runSearch(false);
    }, 400);

    return () => clearTimeout(searchTimer);
  });

  async function loadPriceTypes(): Promise<void> {
    try {
      const data = await listPriceTypes();
      priceTypes = data.items.map((type) => ({ id: type.id, title: type.title }));
    } catch {
      priceTypes = [];
    } finally {
      priceTypesLoaded = true;
    }
  }

  function loadGroups(): Promise<void> {
    if (groupsPromise !== null) {
      return groupsPromise;
    }

    groupsPromise = (async () => {
      try {
        const data = await listItemGroups();
        groups = data.items;
        hasUngrouped = data.has_ungrouped;
      } catch {
        groups = [];
      } finally {
        groupsLoaded = true;
      }

      if (!groupsPreselected && stockGroups.length === 0) {
        stockGroups = groups.map((item) => item.id);
      }

      if (!hasUngrouped) {
        stockNoGroup = false;
        groupNoGroupDraft = false;
      }
    })();

    return groupsPromise;
  }

  const groupFilterActive = $derived.by(() => {
    if (!groupsEnabled || groups.length === 0) {
      return false;
    }

    const allGroups = stockGroups.length === groups.length;

    return !(allGroups && (stockNoGroup || !hasUngrouped));
  });

  const groupFilterEmpty = $derived(
    groupsEnabled && groupFilterActive && stockGroups.length === 0 && !stockNoGroup
  );

  const filters = $derived.by((): StockFilters => {
    const result: StockFilters = {
      q: query, sort: stockSort, show_zero: appSettings.allowZeroStock
    };

    if (canDeactivate && stockActive !== 'all') {
      result.active = stockActive;
    }

    if (groupsEnabled && groupFilterActive) {
      result.group_ids = stockGroups;
      result.no_group = stockNoGroup;
    }

    if (qtyOps.length > 0 && qty !== '' && Number.isFinite(Number(qty))) {
      result.qty_op = qtyOps.join(',');
      result.qty = String(Number(qty));
    }

    return result;
  });

  const hasFilters = $derived(
    query !== '' ||
      groupFilterActive ||
      (canDeactivate && stockActive !== 'all') ||
      (qtyOps.length > 0 && qty !== '')
  );
  const selectedWarehouses = $derived(warehouses.filter((item) => selectedIds.includes(item.id)));
  function openItem(level: StockLevel): void {
    router.navigate(`/stocks/items/${level.id}`);
  }

  function priceRows(level: StockLevel): PriceListItem[] {
    if (level.price_list && level.price_list.length > 0) {
      return level.price_list;
    }

    if (level.price !== null || priceTypeTitle !== '') {
      return [{ id: 0, title: priceTypeTitle, price: level.price ?? null }];
    }

    return [];
  }

  function visiblePriceRows(level: StockLevel): PriceListItem[] {
    const rows = priceRows(level);

    if (priceTypeFilter.length === 0) {
      return rows;
    }

    return rows.filter((row) => priceTypeFilter.includes(row.id));
  }

  function addToCart(level: StockLevel): void {
    const warehouse = warehouses.find((item) => item.id === level.warehouse_id);

    cart.add({
      warehouseId: level.warehouse_id, warehouseName: level.warehouse_name || warehouse?.name || '', name: level.name, unit: level.unit, quantity: 1, stockLevelId: level.id, price: level.price ?? null
    });
  }

  function inCart(level: StockLevel): number {
    return cart.quantityOf(level.warehouse_id, level.name);
  }

  onMount(() => {
    void init();

    const timer = setInterval(() => {
      if (!loadingLevels) {
        void loadLevels(true);
      }
    }, config.listPollMs);

    return () => clearInterval(timer);
  });

  async function init(): Promise<void> {
    loading = true;
    error = '';

    try {
      if (groupsEnabled) {
        await loadGroups();
      }

      const data = await listWarehouses();
      warehouses = data.items;
      canEdit = data.can_edit && appSettings.stockEditEnabled;

      if (warehouses.length > 0) {
        const valid = new Set(warehouses.map((item) => item.id));
        const preferred = initialWarehouseIds.filter((id) => valid.has(id));

        await setWarehouses(preferred.length > 0 ? preferred : warehouses.map((item) => item.id), false);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склады';
    } finally {
      loading = false;
    }
  }

  async function setWarehouses(ids: number[], resetPage = true): Promise<void> {
    selectedIds = [...ids].sort((a, b) => a - b);

    if (resetPage) {
      page = 1;
    }

    query = searchInput.trim();
    saveStockFilters();
    void refreshCounts();
    await loadLevels();
  }

  async function refreshCounts(): Promise<void> {
    const seq = ++countsSeq;

    if (!hasFilters || groupFilterEmpty) {
      counts = {};
      return;
    }

    try {
      const data = await searchCounts(filters);

      if (seq === countsSeq) {
        counts = data.counts;
      }
    } catch {
      if (seq === countsSeq) {
        counts = {};
      }
    }
  }

  function resetFilters(): void {
    query = '';
    searchInput = '';
    stockGroups = groups.map((item) => item.id);
    stockNoGroup = hasUngrouped;
    statusActive = true;
    statusInactive = true;
    qtyOps = [];
    qty = '';
    priceTypeFilter = [];
    page = 1;
    saveStockFilters();
    void applyFilters();
  }

  async function applyFilters(signal?: AbortSignal): Promise<void> {
    page = 1;
    void refreshCounts();
    await loadLevels(false, signal);
  }

  function cartCount(warehouseId: number): number {
    return cart.items.filter((item) => item.warehouseId === warehouseId).length;
  }

  function parseGroups(value: unknown): number[] {
    if (Array.isArray(value)) {
      return value.map((item) => Number(item)).filter((item) => item > 0);
    }

    const single = Number(value);

    return single > 0 ? [single] : [];
  }

  function parseSort(value: string): string[] {
    const parts = value
      .split(',')
      .map((part) => (part.trim() === 'warehouse' ? 'warehouse_asc' : part.trim()))
      .filter((part) => part !== '');

    return parts.length > 0 ? parts : ['name_asc'];
  }

  function openSort(): void {
    sortOpen = true;
  }

  function applySortTerms(terms: string[]): void {
    sortOpen = false;
    const next = terms.length > 0 ? terms.join(',') : 'name_asc';

    if (next === stockSort) {
      return;
    }

    stockSort = next;

    if (viewMode === 'columns') {
      page = 1;
      saveStockFilters();
      return;
    }

    changeSort();
  }

  const parsedSort = $derived(parseSort(stockSort));

  function sortTerm(field: string): 'asc' | 'desc' | null {
    if (parsedSort.includes(`${field}_asc`)) {
      return 'asc';
    }

    if (parsedSort.includes(`${field}_desc`)) {
      return 'desc';
    }

    return null;
  }

  function sortIndex(field: string): number {
    return parsedSort.findIndex((term) => term.startsWith(`${field}_`)) + 1;
  }

  function headerSort(field: string): void {
    const terms = parseSort(stockSort);
    const asc = `${field}_asc`;
    const desc = `${field}_desc`;

    let next: string[];

    if (terms.includes(asc)) {
      next = terms.map((term) => (term === asc ? desc : term));
    } else if (terms.includes(desc)) {
      next = terms.filter((term) => term !== desc);
    } else {
      next = [...terms, asc];
    }

    const value = next.length > 0 ? next.join(',') : 'name_asc';

    if (value === stockSort) {
      return;
    }

    stockSort = value;
    changeSort();
  }

  function saveStockFilters(): void {
    saveFilters('stocks', {
      sort: stockSort, group_ids: stockGroups, group_no_group: stockNoGroup, per_page_list: perPageList, per_page_tiles: perPageTiles, view: viewMode, photos: showPhotos, price_types: priceTypeFilter, warehouse_ids: selectedIds, query, page, active: stockActive, qty_op: qtyOps.join(','), qty,
      fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles], columns: [...fieldsByView.columns] }
    });
  }

  function openView(): void {
    viewDraft = {
      view: viewMode,
      photo: showPhotos,
      fields: {
        list: [...fieldsByView.list],
        tiles: [...fieldsByView.tiles],
        columns: [...fieldsByView.columns]
      }
    };
    viewOpen = true;
  }

  function applyView(): void {
    viewOpen = false;
  }

  function setViewModeNow(mode: StockViewMode): void {
    if (mode === viewMode) {
      return;
    }
    viewMode = mode;
    viewDraft = { ...viewDraft, view: mode };
    stockSort = 'name_asc';
    perPage = mode === 'tiles' ? perPageTiles : perPageList;
    page = 1;
    saveStockFilters();
    announceList(`Вид: ${mode === 'tiles' ? 'плитки' : mode === 'columns' ? 'колонки' : 'список'}`);
    void loadLevels();
  }

  function setPhotosNow(value: boolean): void {
    showPhotos = value;
    viewDraft = { ...viewDraft, photo: value };
    saveStockFilters();
    announceList(value ? 'Фото включены' : 'Фото выключены');
  }

  function toggleViewField(key: string, mode: StockViewMode): void {
    const next = fieldsByView[mode].includes(key)
      ? fieldsByView[mode].filter((item) => item !== key)
      : STOCK_FIELDS.filter((field) => field.key === key || fieldsByView[mode].includes(field.key)).map(
          (field) => field.key
        );
    fieldsByView = { ...fieldsByView, [mode]: next };
    viewDraft = {
      ...viewDraft,
      fields: { ...viewDraft.fields, [mode]: [...next] }
    };
    saveStockFilters();
    announceList('Поля вида обновлены');
  }

  function isStockFieldVisible(key: string, mode: StockViewMode): boolean {
    return viewDraft.fields[mode].includes(key);
  }

  function resetView(): void {
    const defaults = STOCK_FIELDS.map((field) => field.key);
    fieldsByView = { list: [...defaults], tiles: [...defaults], columns: [...defaults] };
    viewDraft = {
      view: 'list',
      photo: true,
      fields: { list: [...defaults], tiles: [...defaults], columns: [...defaults] }
    };
    if (viewMode !== 'list') {
      viewMode = 'list';
      stockSort = 'name_asc';
      perPage = perPageList;
      page = 1;
      void loadLevels();
    }
    showPhotos = true;
    saveStockFilters();
    announceList('Вид сброшен');
  }

  function openFilters(): void {
    statusDraft = [
      ...(statusActive ? ['active'] : []),
      ...(statusInactive ? ['inactive'] : [])
    ];
    const selectedPrices =
      priceTypeFilter.length > 0
        ? priceTypeFilter
        : availablePriceTypes.map((type) => type.id);
    priceTypesDraft = selectedPrices.map(String);
    qtyOpsDraft = [...qtyOps];
    qtyDraft = qty;
    filtersRef?.show();
  }

  function applyFilterDraft(): string {
    const allIds = availablePriceTypes.map((type) => type.id);
    const selectedIds = priceTypesDraft
      .map(Number)
      .filter((id) => Number.isFinite(id) && id > 0);
    const nextFilter =
      selectedIds.length === 0 || selectedIds.length === allIds.length
        ? []
        : [...selectedIds].sort((a, b) => a - b);
    const nextStatusActive = statusDraft.length === 0 || statusDraft.includes('active');
    const nextStatusInactive = statusDraft.length === 0 || statusDraft.includes('inactive');
    const statusChanged =
      canDeactivate &&
      (nextStatusActive !== statusActive || nextStatusInactive !== statusInactive);
    const qtyRaw = String(qtyDraft ?? '').trim();
    const qtyNumber = qtyRaw === '' ? null : Number(qtyRaw);
    const nextQtyOps = parseQtyOps(qtyOpsDraft);
    const nextQty =
      nextQtyOps.length > 0 && qtyNumber !== null && Number.isFinite(qtyNumber)
        ? String(qtyNumber)
        : '';
    const qtyChanged =
      nextQtyOps.join(',') !== qtyOps.join(',') || nextQty !== qty;
    const currentFilter = [...priceTypeFilter].sort((a, b) => a - b);
    const priceChanged =
      nextFilter.length !== currentFilter.length ||
      nextFilter.some((id, index) => id !== currentFilter[index]);

    if (!statusChanged && !qtyChanged && !priceChanged) {
      return 'Изменений нет';
    }

    priceTypeFilter = nextFilter;
    qtyOps = nextQtyOps;
    qty = nextQty;

    if (statusChanged) {
      statusActive = nextStatusActive;
      statusInactive = nextStatusInactive;
    }

    page = 1;
    saveStockFilters();
    void applyFilters();
    return 'Фильтры применены';
  }

  function resetFilterDraft(): string {
    statusDraft = ['active', 'inactive'];
    priceTypesDraft = availablePriceTypes.map((type) => String(type.id));
    qtyOpsDraft = [];
    qtyDraft = '';
    return applyFilterDraft();
  }

  function changeSort(): void {
    saveStockFilters();
    void applyFilters();
  }

  function openGroup(): void {
    groupDraft = [...stockGroups];
    groupNoGroupDraft = hasUngrouped ? stockNoGroup : false;
    groupOpen = true;
  }

  function selectAllGroups(): void {
    groupDraft = groups.map((item) => item.id);

    if (hasUngrouped) {
      groupNoGroupDraft = true;
    }
  }

  function clearAllGroups(): void {
    groupDraft = [];
    groupNoGroupDraft = false;
  }

  function applyGroup(): void {
    groupOpen = false;

    const next = [...groupDraft].sort((a, b) => a - b);

    if (
      next.length === stockGroups.length &&
      next.every((id, index) => id === stockGroups[index]) &&
      groupNoGroupDraft === stockNoGroup
    ) {
      return;
    }

    stockGroups = next;
    stockNoGroup = groupNoGroupDraft;
    changeGroup();
  }

  function changeGroup(): void {
    saveStockFilters();
    void applyFilters();
  }

  function openWarehouse(): void {
    warehouseDraft = [...selectedIds];
    warehouseOpen = true;
  }

  function selectAllWarehouses(): void {
    warehouseDraft = warehouses.map((item) => item.id);
  }

  function clearAllWarehouses(): void {
    warehouseDraft = [];
  }

  function applyWarehouse(): void {
    warehouseOpen = false;

    const next = [...warehouseDraft].sort((a, b) => a - b);

    if (next.length === selectedIds.length && next.every((id, index) => id === selectedIds[index])) {
      return;
    }

    void setWarehouses(next);
  }

  async function loadLevels(silent = false, signal?: AbortSignal): Promise<void> {
    if (silent && loadingLevels) {
      return;
    }

    if (selectedIds.length === 0 || groupFilterEmpty) {
      ++loadSeq;
      levels = [];
      total = 0;
      loadingLevels = false;
      return;
    }

    const seq = ++loadSeq;

    if (!silent) {
      loadingLevels = true;
      error = '';
    }

    try {
      const data = await listLevels(
        selectedIds,
        filters,
        viewMode === 'columns' ? 1 : page,
        viewMode === 'columns' ? 0 : perPage,
        0,
        0,
        signal
      );

      if (seq !== loadSeq) {
        return;
      }

      levels = data.items;
      total = data.total;
      priceTypeTitle = data.prices?.type?.title ?? '';

      const pageTotal =
        viewMode === 'columns' ? groupStockLevels(data.items).length : data.total;
      const maxPage = perPage <= 0 ? 1 : Math.max(1, Math.ceil(pageTotal / perPage));

      if (page > maxPage) {
        page = maxPage;
        saveStockFilters();

        if (viewMode !== 'columns') {
          void loadLevels(silent);
          return;
        }
      }
    } catch (cause) {
      if (cause instanceof DOMException && cause.name === 'AbortError') {
        return;
      }
      if (seq === loadSeq && !silent) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить остатки';
      }
    } finally {
      if (seq === loadSeq) {
        loadingLevels = false;
      }
    }
  }

  async function runSearch(record: boolean, signal?: AbortSignal): Promise<void> {
    query = searchInput.trim();

    if (record && query !== '') {
      addHistory('stocks', query);
    }

    await applyFilters(signal);
    saveStockFilters();
  }

  async function search(): Promise<void> {
    clearTimeout(searchTimer);
    abortSearch();
    const controller = new AbortController();
    searchAbort = controller;
    await runSearch(true, controller.signal);
  }

  async function goToPage(target: number): Promise<void> {
    if (perPage <= 0 || !Number.isFinite(target)) {
      return;
    }

    const next = Math.max(1, Math.trunc(target));

    if (next === page) {
      return;
    }

    page = next;
    saveStockFilters();

    if (viewMode === 'columns') {
      return;
    }

    await loadLevels();
  }

  function changePerPage(): void {
    if (viewMode === 'tiles') {
      perPageTiles = perPage;
    } else {
      perPageList = perPage;
    }

    page = 1;
    saveStockFilters();

    if (viewMode === 'columns') {
      return;
    }

    void loadLevels();
  }

  async function doExport(format: string, byEmail: boolean): Promise<void> {
    exportOpen = false;

    if (selectedIds.length === 0) {
      error = 'Выберите хотя бы один склад';
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      if (byEmail) {
        const result = await emailLevels(selectedIds, filters, format);
        message = `Остатки отправлены на ${result.email}`;
      } else {
        await exportLevels(selectedIds, filters, format);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось выгрузить остатки';
    } finally {
      busy = false;
    }
  }

</script>

<section class="page page-wide">
  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice" role="status" aria-hidden={viewOpen}>
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  <div class="sr-only" role="status" aria-live="polite" aria-hidden={viewOpen}>
    {listAnnouncement}
  </div>

  <PageToolbar title="Номенклатура" actionsLabel="Действия со списком">
    {#snippet actions()}
      <ToolbarMenu
        label="Действия со списком"
        search={{
          value: query, historyKey: 'stocks', title: 'Поиск по номенклатуре', placeholder: 'Название, артикул или код', suggestions: levels.map((level) => level.name), onsearch: (next) => {
            clearTimeout(searchTimer);
            searchInput = next;
            return search();
          }, onstop: abortSearch, onclear: () => {
            abortSearch();
            clearTimeout(searchTimer);
            searchInput = '';
            query = '';
            page = 1;
            saveStockFilters();
            void loadLevels();
          }
        }}
        items={[
          {
            id: 'sort', label: 'Сортировка', icon: 'sort', dialog: true, checked: stockSort !== stockFilterDefaults.sort, onclick: openSort
          }, ...(warehousesEnabled && warehouses.length > 1
            ? [
                {
                  id: 'warehouse', label: 'Выбор складов', icon: 'stocks', dialog: true, checked: selectedIds.length !== warehouses.length, onclick: openWarehouse
                }
              ]
            : []), ...(groupsEnabled
            ? [
                {
                  id: 'groups', label: 'Группы', icon: 'group', dialog: true, checked: groupFilterActive, onclick: openGroup
                }
              ]
            : []), {
            id: 'view', label: 'Вид', icon: 'view', dialog: true, checked: viewMode !== 'list' || !showPhotos, onclick: openView
          }, {
            id: 'filters', label: 'Фильтры', icon: 'filter', dialog: true,
            count: (statusActive && statusInactive ? 0 : 1) +
              (priceTypeFilter.length > 0 ? 1 : 0) +
              (qtyOps.length > 0 || qty !== '' ? 1 : 0),
            onclick: openFilters
          }, { id: 'sep-1', label: '', separator: true, onclick: () => {} }, ...(auth.can('settings.manage')
            ? [
                ...(appSettings.groupsConfigured
                  ? [
                      {
                        id: 'group-settings', label: 'Группы номенклатуры', icon: 'tags', onclick: () => router.navigate('/stocks/groups')
                      }
                    ]
                  : []), ...(pricesEnabled
                  ? [
                      {
                        id: 'price-types', label: 'Типы цен', icon: 'price', onclick: () => router.navigate('/stocks/price-types')
                      }
                    ]
                  : [])
              ]
            : []), ...(canExport
            ? [
                {
                  id: 'export', label: 'Экспорт остатков', icon: 'export', disabled: busy || selectedIds.length === 0, onclick: () => (exportOpen = true)
                }
              ]
            : []), ...(canEdit
            ? [
                { id: 'sep-2', label: '', separator: true, onclick: () => {} }, {
                  id: 'create', label: 'Новая номенклатура', icon: 'plus', onclick: () => router.navigate('/stocks/items/new')
                }
              ]
            : [])
        ]}
      />
    {/snippet}
  </PageToolbar>

  {#if loading}
    <ListSkeleton rows={7} />
  {:else if warehouses.length === 0}
    <EmptyState
      icon="warehouse"
      title="Нет доступных складов"
      description="Обратитесь к администратору, чтобы получить доступ к складу."
    />
  {:else}

    <Modal open={groupOpen} title="Группы номенклатуры" onclose={() => (groupOpen = false)}>
      <div class="sort-options">
        <div class="options-head">
          <button type="button" class="link-btn" onclick={selectAllGroups}>Отметить все</button>
          <button type="button" class="link-btn" onclick={clearAllGroups}>Снять все</button>
        </div>

        {#if hasUngrouped}
          <label class="sort-option">
            <input type="checkbox" bind:checked={groupNoGroupDraft} />
            <span>Без группы</span>
          </label>
        {/if}

        {#each groups as group (group.id)}
          <label class="sort-option">
            <input type="checkbox" value={group.id} bind:group={groupDraft} />
            <span title={group.description || undefined}>{group.title}</span>
          </label>
        {/each}
      </div>
      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (groupOpen = false)}>Отмена</Button>
        <Button onclick={applyGroup}>Применить</Button>
      </div>
    </Modal>

    {#if warehouses.length > 1}
    <Modal open={warehouseOpen} title="Склады" onclose={() => (warehouseOpen = false)}>
      <div class="warehouse-modal">
        <div class="options-head">
          <button type="button" class="link-btn" onclick={selectAllWarehouses}>Отметить все</button>
          <button type="button" class="link-btn" onclick={clearAllWarehouses}>Снять все</button>
          <span class="options-count">Выбрано: {warehouseDraft.length} из {warehouses.length}</span>
        </div>

        <div class="warehouse-list">
        {#each warehouses as warehouse (warehouse.id)}
          <label class="sort-option warehouse-option">
            <input type="checkbox" value={warehouse.id} bind:group={warehouseDraft} />
            <span class="warehouse-option-name">{warehouse.name}</span>
            {#if warehouse.is_personal}<span class="personal" title="Персональный доступ">•</span>{/if}
            <span
              class="count"
              class:found={hasFilters && (counts[warehouse.id] ?? 0) > 0}
              class:zero={hasFilters && (counts[warehouse.id] ?? 0) === 0}
            >
              {hasFilters ? (counts[warehouse.id] ?? 0) : warehouse.positions}
            </span>
            {#if cartCount(warehouse.id) > 0}
              <span class="picked" title="Номенклатуры в корзине заявки">в заявке: {cartCount(warehouse.id)}</span>
            {/if}
          </label>
        {/each}
        </div>
      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (warehouseOpen = false)}>Отмена</Button>
        <Button onclick={applyWarehouse}>Применить</Button>
      </div>
      </div>
    </Modal>
    {/if}

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
                name="stock-view"
                value="list"
                checked={viewDraft.view === 'list'}
                data-autofocus={viewDraft.view === 'list' ? '' : undefined}
                onchange={() => setViewModeNow('list')}
              />
              <Icon name="list" size={18} />
              <span>Список</span>
            </label>
            <label class="view-mode-card" class:active={viewDraft.view === 'tiles'}>
              <input
                type="radio"
                name="stock-view"
                value="tiles"
                checked={viewDraft.view === 'tiles'}
                data-autofocus={viewDraft.view === 'tiles' ? '' : undefined}
                onchange={() => setViewModeNow('tiles')}
              />
              <Icon name="tiles" size={18} />
              <span>Плитка</span>
            </label>
            <label class="view-mode-card" class:active={viewDraft.view === 'columns'}>
              <input
                type="radio"
                name="stock-view"
                value="columns"
                checked={viewDraft.view === 'columns'}
                data-autofocus={viewDraft.view === 'columns' ? '' : undefined}
                onchange={() => setViewModeNow('columns')}
              />
              <Icon name="columns" size={18} />
              <span>Колонки</span>
            </label>
          </div>
        </fieldset>

        <fieldset class="filter-group">
          <legend class="filter-label">Фото в номенклатуре</legend>
          <div class="view-options">
            <label class="radio">
              <input
                type="radio"
                name="stock-photos"
                checked={viewDraft.photo}
                onchange={() => setPhotosNow(true)}
              />
              <span>С фото</span>
            </label>
            <label class="radio">
              <input
                type="radio"
                name="stock-photos"
                checked={!viewDraft.photo}
                onchange={() => setPhotosNow(false)}
              />
              <span>Без фото</span>
            </label>
          </div>
        </fieldset>

        <fieldset class="filter-group">
          <legend class="filter-label">Поля в списке и карточках</legend>
          <div class="view-field-table stock-view-fields">
            <span></span>
            {#each STOCK_VIEW_MODES as mode (mode)}
              <span class="view-field-head">
                {mode === 'list' ? 'Список' : mode === 'tiles' ? 'Плитки' : 'Колонки'}
              </span>
            {/each}
            {#each STOCK_FIELDS as field (field.key)}
              <span class="view-field-name">{field.label}</span>
              {#each STOCK_VIEW_MODES as mode (mode)}
                <label class="view-field-check">
                  <input
                    type="checkbox"
                    checked={isStockFieldVisible(field.key, mode)}
                    aria-label={`${field.label}: ${mode === 'list' ? 'список' : mode === 'tiles' ? 'плитки' : 'колонки'}`}
                    onchange={() => toggleViewField(field.key, mode)}
                  />
                </label>
              {/each}
            {/each}
          </div>
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
      onapply={applyFilterDraft}
      onreset={resetFilterDraft}
    >
      {#if canDeactivate}
        <div class="filter-group">
          <span class="filter-label" title="Если ничего не выбрано — показываются все">Статусы</span>
          <MultiSelect
            label="Статусы"
            options={statusOptions}
            bind:values={statusDraft}
            allLabel="Все статусы"
            size="lg"
          />
        </div>
      {/if}

      <div class="filter-group qty-filter-group">
        <span class="filter-label">Остаток</span>
        <div class="qty-filter-row">
          <MultiSelect
            label="Условие"
            options={qtyOpOptions}
            bind:values={qtyOpsDraft}
            allLabel="Любое"
            emptyLabel="Нет условий"
            maxHeight="180px"
            size="lg"
          />
          <input
            class="qty-filter-input"
            type="number"
            min="0"
            step="any"
            bind:value={qtyDraft}
            disabled={qtyOpsDraft.length === 0}
            placeholder="Количество"
            aria-label="Количество"
          />
        </div>
      </div>

      {#if pricesEnabled && availablePriceTypes.length > 1}
        <div class="filter-group">
          <span class="filter-label" title="Если ничего не выбрано — все типы цен">Типы цен</span>
          <MultiSelect
            label="Типы цен"
            options={priceTypeOptions}
            bind:values={priceTypesDraft}
            allLabel="Все типы цен"
            emptyLabel="Нет типов цен"
            maxHeight="280px"
            size="lg"
          />
        </div>
      {/if}
    </FiltersModal>
{#if loadingLevels}
      <ListSkeleton rows={6} />
    {:else if selectedIds.length === 0}
      <EmptyState
        icon="warehouse"
        title="Нет выбранных складов"
        description="Выберите хотя бы один склад в фильтре «Склад», чтобы увидеть номенклатуру."
      />
    {:else if levels.length === 0}
      <EmptyState
        icon="stocks"
        title="Ничего не найдено"
        description="Измените поиск или сбросьте фильтры."
      >
        {#snippet action()}
          {#if hasFilters || groupFilterEmpty}
            <Button variant="ghost" onclick={resetFilters}>Сбросить фильтры</Button>
          {/if}
        {/snippet}
      </EmptyState>
    {:else}
      <div class="levels-wrap">
      {#if viewMode === 'columns'}
        <div class="columns-scroll-shell">
          {#if columnsCanScroll}
            <div class="columns-scroll-bar">
              <div class="columns-scroll-controls">
                <button
                  type="button"
                  class="columns-scroll-btn"
                  title="Прокрутить склады влево"
                  aria-label="Прокрутить склады влево"
                  disabled={columnsAtStart}
                  onclick={() => scrollColumns(-1)}
                >
                  <Icon name="arrow-left" size={16} />
                </button>
                <button
                  type="button"
                  class="columns-scroll-btn"
                  title="Прокрутить склады вправо"
                  aria-label="Прокрутить склады вправо"
                  disabled={columnsAtEnd}
                  onclick={() => scrollColumns(1)}
                >
                  <Icon name="arrow-right" size={16} />
                </button>
              </div>
            </div>
          {/if}
          <div class="columns-sticky-head">
            <div class="columns-head-scroll" bind:this={columnsHeadScrollEl} onscroll={syncColumnsFromHead}>
              <table
                class="columns-table columns-table-head"
                style="--columns-count: {selectedWarehouses.length}"
                aria-label="Заголовок таблицы остатков"
              >
                <colgroup>
                  <col style="width: var(--columns-name-w)" />
                  {#each selectedWarehouses as warehouse (warehouse.id)}
                    <col style="width: var(--columns-wh-w)" />
                  {/each}
                </colgroup>
                <thead>
                  <tr>
                    <th
                      scope="col"
                      class="columns-name-head"
                      aria-sort={columnSort.field === 'name' ? (columnSort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                    >
                      <button
                        type="button"
                        class="columns-head-button"
                        title="Сортировать по названию"
                        onclick={() => toggleColumnSort('name')}
                      >
                        <span>Название</span>
                        {#if columnSort.field === 'name'}
                          <Icon name={columnSort.dir === 'asc' ? 'arrow-up' : 'arrow-down'} size={12} />
                        {/if}
                      </button>
                    </th>
                    {#each selectedWarehouses as warehouse (warehouse.id)}
                      <th
                        scope="col"
                        aria-sort={columnSort.field === warehouse.id ? (columnSort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                      >
                        <button
                          type="button"
                          class="columns-head-button"
                          title={`Сортировать по остатку: ${warehouse.name}`}
                          onclick={() => toggleColumnSort(warehouse.id)}
                        >
                          <span>{warehouse.name}</span>
                          {#if columnSort.field === warehouse.id}
                            <Icon name={columnSort.dir === 'asc' ? 'arrow-up' : 'arrow-down'} size={12} />
                          {/if}
                        </button>
                      </th>
                    {/each}
                  </tr>
                </thead>
              </table>
            </div>
          </div>
          <div class="columns-table-wrap" bind:this={columnsScrollEl} onscroll={syncColumnsFromBody}>
            <table
              class="columns-table"
              style="--columns-count: {selectedWarehouses.length}"
              aria-label="Остатки по складам"
            >
              <colgroup>
                <col style="width: var(--columns-name-w)" />
                {#each selectedWarehouses as warehouse (warehouse.id)}
                  <col style="width: var(--columns-wh-w)" />
                {/each}
              </colgroup>
              <tbody>
              {#each columnPageRows as row (row.key)}
                {@const first = row.levels.values().next().value}
                {@const total = [...row.levels.values()].reduce((sum, level) => sum + level.quantity, 0)}
                <tr>
                  <th scope="row" class="columns-name">
                    <div class="columns-name-inner">
                      {#if showPhotos}
                        <div class="columns-name-photo">
                          <PhotoGallery
                            photos={first?.photos ?? []}
                            variant="list"
                            label={row.name}
                          />
                        </div>
                      {/if}
                      <div class="columns-name-text">
                    <button
                      type="button"
                      class="name"
                      title="Открыть номенклатуру"
                      onclick={() => first && openItem(first)}
                    >
                      {row.name}
                    </button>
                    {#each stockFields(fieldsByView.columns) as field (field.key)}
                      {#if field.key === 'warehouse' && warehousesEnabled && first?.warehouse_name}
                        <span class="column-warehouse">{first.warehouse_name}</span>
                      {:else if field.key === 'group' && groupsEnabled && row.group_title}
                        <span class="column-group">{row.group_title}</span>
                      {:else if field.key === 'article' && field.value(first ?? ({} as StockLevel))}
                        <span class="column-article">{field.value(first ?? ({} as StockLevel))}</span>
                      {:else if field.key === 'unit' && row.unit}
                        <span class="column-unit">{row.unit}</span>
                      {:else if field.key === 'description' && first?.description}
                        <span class="column-description">{first.description}</span>
                      {/if}
                    {/each}
                    <span class="column-total">Всего: {stockQuantityText(total, row.unit)}</span>
                  </div>
                    </div>
                  </th>
                  {#each selectedWarehouses as warehouse (warehouse.id)}
                    {@const cell = row.levels.get(warehouse.id)}
                    <td class="columns-cell" class:cart-over={cell ? inCart(cell) > cell.quantity : false}>
                      {#if cell}
                        {@const cartQty = inCart(cell)}
                        <button
                          type="button"
                          class="column-main"
                          title={`Открыть ${row.name} на складе ${warehouse.name}`}
                          onclick={() => openItem(cell)}
                        >
                          <span class="column-stat">
                            <span class="column-label">Остаток</span>
                            <span class="column-qty" class:out-of-stock={cell.quantity <= 0}>
                              {stockQuantityText(cell.quantity, cell.unit)}
                            </span>
                          </span>
                          {#if pricesEnabled}
                            <span class="price-lines">
                              {#each visiblePriceRows(cell) as price (price.id)}
                                <span class="price-line">
                                  <span class="price-type">{price.title || 'Цена'}</span>
                                  <span class="price" class:missing={price.price === null}>
                                    {formatPrice(price.price)}
                                  </span>
                                </span>
                              {/each}
                            </span>
                          {/if}
                        </button>
                        {#if canCreate && cell.active !== false}
                          <!-- svelte-ignore a11y_click_events_have_key_events -->
                          <!-- svelte-ignore a11y_no_static_element_interactions -->
                          <div class="column-cart" onclick={(event) => event.stopPropagation()}>
                            {#if cartQty > 0}
                              <span class="column-cart-line">
                                <span class="column-label">В корзине</span>
                                <span class="add">
                                  <button
                                    type="button"
                                    class="step"
                                    aria-label={`Уменьшить: ${row.name}`}
                                    onclick={() => cart.decreaseAt(cell.warehouse_id, cell.name)}
                                  >
                                    −
                                  </button>
                                  <span class="qty" aria-label={`В корзине: ${row.name}`}>
                                    {cartQty.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                                  </span>
                                  <button
                                    type="button"
                                    class="step"
                                    aria-label={`Увеличить: ${row.name}`}
                                    onclick={() => cart.increaseAt(cell.warehouse_id, cell.name)}
                                  >
                                    +
                                  </button>
                                </span>
                              </span>
                            {:else}
                              <span class="column-add-row">
                                <span class="column-add-label">Добавить в корзину</span>
                                <button
                                  type="button"
                                  class="column-add-icon"
                                  title="Добавить в корзину"
                                  aria-label={`Добавить в корзину: ${row.name}`}
                                  onclick={() => addToCart(cell)}
                                >
                                  <Icon name="cart" size={16} />
                                </button>
                              </span>
                            {/if}
                          </div>
                        {/if}
                      {:else}
                        <span class="column-empty">—</span>
                      {/if}
                    </td>
                  {/each}
                </tr>
              {/each}
            </tbody>
          </table>
          </div>
        </div>
      {:else}
      {#if viewMode === 'list'}
        <div class="levels-head" style={listVars}>
          <button type="button" class="levels-head-cell col-name" onclick={() => headerSort('name')}>
            <span class="levels-head-label">Название</span>
            {#if sortIndex('name') > 0}
              <span class="sort-badge">
                <Icon name={sortTerm('name') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                <span class="sort-num">{sortIndex('name')}</span>
              </span>
            {/if}
          </button>
          <span class="levels-head-cell col-meta">
            {#if warehousesEnabled && groupsEnabled}
              <span class="levels-head-label">Склад / группа</span>
            {:else if warehousesEnabled}
              <span class="levels-head-label">Склад</span>
            {:else if groupsEnabled}
              <span class="levels-head-label">Группа</span>
            {/if}
          </span>
          {#if pricesEnabled}
            <button type="button" class="levels-head-cell col-price" onclick={() => headerSort('price')}>
              <span class="levels-head-label">Цена</span>
              {#if sortIndex('price') > 0}
                <span class="sort-badge">
                  <Icon name={sortTerm('price') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                  <span class="sort-num">{sortIndex('price')}</span>
                </span>
              {/if}
            </button>
          {/if}
          <button type="button" class="levels-head-cell col-stock" onclick={() => headerSort('qty')}>
            <span class="levels-head-label">Остаток</span>
            {#if sortIndex('qty') > 0}
              <span class="sort-badge">
                <Icon name={sortTerm('qty') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                <span class="sort-num">{sortIndex('qty')}</span>
              </span>
            {/if}
          </button>
          {#if canCreate}<span class="levels-head-cell head-cart col-cart">Корзина</span>{/if}
        </div>
      {/if}
      <div class="levels" class:tiles={viewMode === 'tiles'} style={listVars}>
        {#each levels as level (level.id)}
          {@const cartQty = inCart(level)}
          {@const visibleMetaFields = stockFields(fieldsByView[viewMode]).filter(
            (field) =>
              field.value(level) !== '' &&
              (field.key !== 'warehouse' || warehousesEnabled) &&
              (field.key !== 'group' || groupsEnabled)
          )}
          <!-- svelte-ignore a11y_click_events_have_key_events -->
          <!-- svelte-ignore a11y_no_static_element_interactions -->
          <div
            class="level"
            class:cart-over={cartQty > 0 && cartQty > level.quantity}
            class:inactive={level.active === false}
            class:out-of-stock={level.quantity <= 0}
            role="button"
            tabindex="0"
            aria-label={`Открыть номенклатуру: ${level.name}`}
            onclick={() => openItem(level)}
            onkeydown={(event) => {
              if (event.target !== event.currentTarget) {
                return;
              }

              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openItem(level);
              }
            }}
          >
            {#if showPhotos}
              <div class="cell col-photo">
                <PhotoGallery
                  photos={level.photos ?? []}
                  variant={viewMode === 'tiles' ? 'tile' : 'list'}
                  label={level.name}
                />
              </div>
            {/if}
            <div class="name-col col-name">
              <button
                type="button"
                class="name"
                class:has-desc={level.description !== ''}
                title="Подробнее о номенклатуре"
                onclick={(event) => {
                  event.stopPropagation();
                openItem(level);
                }}
              >
                {level.name}
              </button>

              {#if level.active === false}
                <div class="meta">
                  <span class="inactive-tag">деактивирована</span>
                </div>
              {:else if level.quantity <= 0}
                <span class="tile-badge out-of-stock">Нет в наличии</span>
              {/if}
            </div>
            {#if visibleMetaFields.length > 0}
              <div class="meta col-meta">
                {#each visibleMetaFields as field (field.key)}
                  <span
                    class="meta-chip"
                    class:wh={field.key === 'warehouse'}
                    class:group={field.key === 'group'}
                  >{field.value(level)}</span>
                {/each}
              </div>
            {/if}
            <div class="level-bottom">
              <div class="stat col-stock">
                <span class="stat-label">Остаток</span>
                <span class="quantity" class:on-order={level.quantity < 0}>
                  {stockQuantityText(level.quantity, level.unit)}
                </span>
              </div>

              {#if pricesEnabled}
                <div class="stat col-price">
                  <span class="stat-label">Цены</span>
                  <div class="price-lines">
                    {#each visiblePriceRows(level) as row (row.id)}
                      <span class="price-line">
                        <span class="price-type">{row.title || 'Цена'}</span>
                        <span class="price" class:missing={row.price === null}>
                          {formatPrice(row.price)}
                        </span>
                      </span>
                    {/each}
                  </div>
                </div>
              {/if}

              {#if canCreate && level.active !== false}
                <!-- svelte-ignore a11y_click_events_have_key_events -->
                <!-- svelte-ignore a11y_no_static_element_interactions -->
                <div class="stat cart-stat col-cart" onclick={(event) => event.stopPropagation()}>
                  {#if cartQty > 0}
                    <span class="stat-label">В корзине</span>
                    <span class="add">
                      <button
                        type="button"
                        class="step"
                        aria-label={`Уменьшить: ${level.name}`}
                        onclick={() => cart.decreaseAt(level.warehouse_id, level.name)}
                      >
                        −
                      </button>
                      <span class="qty" aria-label={`В корзине: ${level.name}`}>
                        {cartQty.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                      </span>
                      <button
                        type="button"
                        class="step"
                        aria-label={`Увеличить: ${level.name}`}
                        onclick={() => cart.increaseAt(level.warehouse_id, level.name)}
                      >
                        +
                      </button>
                    </span>
                  {:else}
                    {#if viewMode === 'tiles'}
                      <span class="column-add-row">
                        <span class="column-add-label">Добавить в корзину</span>
                        <button
                          type="button"
                          class="column-add-icon"
                          title="Добавить в корзину"
                          aria-label={`Добавить в корзину: ${level.name}`}
                          onclick={() => addToCart(level)}
                        >
                          <Icon name="cart" size={16} />
                        </button>
                      </span>
                    {:else}
                      <span class="add add-empty">
                        <Button
                          variant="text"
                          size="sm"
                          title="Добавить в корзину"
                          ariaLabel={`Добавить в корзину: ${level.name}`}
                          onclick={() => addToCart(level)}
                        >
                          <Icon name="cart" size={16} /> В корзину
                        </Button>
                      </span>
                    {/if}
                  {/if}
                </div>
              {/if}
            </div>
          </div>
        {/each}
      </div>
      {/if}
      </div>

      <Pager
        {page}
        {perPage}
        total={pagerTotal}
        loading={loading}
        perPageOptions={PAGE_SIZES}
        onchange={(next) => void goToPage(next)}
        onperpage={(value) => {
          perPage = value;
          changePerPage();
        }}
      />
    {/if}
  {/if}

  <SortDialog
    open={sortOpen}
    fields={availableSortFields}
    sort={stockSort}
    defaultValue="name_asc"
    onclose={() => (sortOpen = false)}
    onapply={applySortTerms}
  />

  <ExportModal
    open={exportOpen}
    title="Экспорт остатков по выбранным складам"
    email={auth.user?.email ?? ''}
    emailAllowed={appSettings.emailExportEnabled && appSettings.mailConfigured}
    onclose={() => (exportOpen = false)}
    onpick={(format, byEmail) => void doExport(format, byEmail)}
  />

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

  .page:has(.levels.tiles), .page:has(.columns-table) {
    max-width: 1600px;
  }

  .count {
    font-size: 12px;
    color: var(--muted);
  }

  .count.found {
    padding: 1px 7px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--primary) 12%, white);
    color: var(--primary);
    font-weight: 600;
  }

  .count.zero {
    opacity: 0.55;
  }

  .picked {
    font-size: 12px;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
  }

  .warehouse-option-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .personal {
    color: var(--primary);
    font-size: 16px;
    line-height: 1;
  }

  /* Второстепенные действия всегда в одном меню — без дублирования иконок в строке. */

  .sort-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .warehouse-modal {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-height: 0;
    max-height: min(68vh, 620px);
  }

  .warehouse-modal .options-head {
    flex: 0 0 auto;
  }

  .warehouse-list {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: var(--space-2);
    min-height: 0;
    overflow-y: auto;
    padding-right: var(--space-3);
    scrollbar-gutter: stable;
  }

  .warehouse-modal .modal-actions {
    flex: 0 0 auto;
    margin-top: var(--space-2);
    padding-top: var(--space-3);
    border-top: 1px solid var(--border-secondary);
  }

  :global(.dialog-body:has(.warehouse-modal)) {
    overflow: hidden;
  }

  /* Общие стили «Вид» живут в app.css; здесь только раскладка под три режима. */
  .stock-view-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) repeat(3, 72px);
    gap: var(--space-1) var(--space-2);
  }

  .view-field-head {
    font-size: var(--font-xs);
    color: var(--text-description);
    text-align: center;
  }

  .view-field-name {
    min-width: 0;
    overflow: hidden;
    font-size: var(--font-sm);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .qty-filter-group {
    display: block;
  }

  .qty-filter-group .filter-label {
    margin-bottom: var(--space-2);
  }

  .qty-filter-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
  }

  .qty-filter-row :global(.multi) {
    flex: 1 1 auto;
    min-width: 160px;
  }

  .qty-filter-input {
    width: 120px;
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

  .qty-filter-input:focus {
    border-color: var(--primary);
    outline: none;
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .qty-filter-input:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  /* Узкие экраны: «Остаток» складывается, чтобы не появлялся горизонтальный скролл. */
  @media (max-width: 420px) {
    .qty-filter-row {
      flex-wrap: wrap;
    }

    .qty-filter-row :global(.multi) {
      flex: 1 1 100%;
      min-width: 0;
    }

    .qty-filter-input {
      width: 100%;
    }
  }

  .options-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: var(--space-1);
    padding-bottom: var(--space-2);
    border-bottom: 1px solid var(--border-secondary);
  }

  .options-count {
    margin-left: auto;
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .link-btn {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    font-size: 13px;
    color: var(--primary);
    cursor: pointer;
  }

  .link-btn:hover {
    color: var(--primary-hover);
    text-decoration: underline;
  }

  .sort-option {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
    cursor: pointer;
  }

  .warehouse-option {
    padding: 7px 9px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    transition: background-color 0.15s ease, border-color 0.15s ease;
  }

  .warehouse-option:hover {
    background: var(--fill-tertiary);
  }

  .warehouse-option:has(input:checked) {
    border-color: color-mix(in srgb, var(--primary) 24%, var(--border-secondary));
    background: color-mix(in srgb, var(--primary) 5%, var(--surface));
  }

  .warehouse-option:focus-within {
    outline: 2px solid var(--focus-ring);
    outline-offset: 1px;
  }

  .sort-option input[type='checkbox'] {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--primary);
    cursor: pointer;
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

  .levels {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .levels-wrap {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .columns-scroll-shell {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .columns-scroll-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    padding-inline: 2px;
  }

  .columns-scroll-controls {
    display: flex;
    gap: 4px;
  }

  .columns-scroll-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
  }

  .columns-scroll-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .columns-scroll-btn:disabled {
    opacity: 0.4;
    cursor: default;
  }

  .columns-table-wrap {
    overflow: auto;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .columns-sticky-head {
    position: sticky;
    top: 0;
    z-index: 5;
    overflow: hidden;
    border: 1px solid var(--border-secondary);
    border-bottom: 1px solid var(--border-secondary);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    background: var(--surface);
  }

  .columns-head-scroll {
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
  }

  .columns-head-scroll::-webkit-scrollbar {
    display: none;
  }

  .columns-table {
    --columns-name-w: 280px;
    --columns-wh-w: 220px;
    width: max-content;
    min-width: 760px;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
  }

  .columns-table-head thead th {
    position: static;
    top: auto;
  }

  .columns-table-head .columns-name-head {
    position: sticky;
    left: 0;
  }

  .columns-table th, .columns-table td {
    padding: 6px 12px;
    border-bottom: 1px solid var(--border-secondary);
    text-align: left;
    vertical-align: top;
  }

  .columns-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 0;
    background: var(--surface);
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
  }

  .columns-head-button {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    padding: 12px var(--space-4);
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .columns-head-button:hover {
    color: var(--primary);
  }

  .columns-table-head thead th + th {
    box-shadow: inset 1px 0 0 var(--border-secondary);
  }

  .columns-table tbody tr:last-child th, .columns-table tbody tr:last-child td {
    border-bottom: none;
  }

  .columns-table tbody tr:nth-child(even) {
    background: color-mix(in srgb, var(--text) 2%, var(--surface));
  }

  .columns-table tbody tr:nth-child(even) .columns-name {
    background: color-mix(in srgb, var(--text) 2%, var(--surface));
  }

  .columns-table tbody tr:hover {
    background: color-mix(in srgb, var(--text) 4%, var(--surface));
  }

  .columns-table .columns-name-head, .columns-table .columns-name {
    position: sticky;
    left: 0;
    z-index: 1;
    min-width: 260px;
    max-width: 340px;
    background: var(--surface);
    box-shadow: 1px 0 0 var(--border-secondary);
  }

  .columns-table thead .columns-name-head {
    z-index: 3;
    background: var(--surface);
  }

  .columns-table tbody tr:hover .columns-name {
    background: color-mix(in srgb, var(--text) 4%, var(--surface));
  }

  .columns-name {
    font-weight: 500;
  }

  .columns-name-inner {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    min-width: 0;
  }

  .columns-name-photo {
    flex: 0 0 56px;
    width: 56px;
    max-width: 56px;
    overflow: hidden;
  }

  .columns-name-photo :global(.gallery) {
    width: 56px;
    max-width: 56px;
    height: 56px;
  }

  .columns-name-text {
    flex: 1 1 auto;
    min-width: 0;
  }

  .columns-name .name {
    display: block;
    max-width: 100%;
    text-align: left;
    line-height: 1.35;
  }

  .column-group,
  .column-warehouse,
  .column-article,
  .column-unit,
  .column-description {
    display: block;
    max-width: 100%;
    margin-top: 2px;
    overflow: hidden;
    color: var(--muted);
    font-size: var(--font-xs);
    font-weight: 400;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .columns-cell {
    min-width: 190px;
    box-shadow: inset 1px 0 0 var(--border-secondary);
  }

  .columns-cell.cart-over {
    background: var(--danger-bg);
  }

  .column-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .column-main:hover .column-qty {
    color: var(--primary);
  }

  .column-stat {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    white-space: nowrap;
  }

  .column-label {
    display: inline;
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
    line-height: 1.2;
    white-space: nowrap;
  }

  .column-qty {
    font-size: 14px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .column-qty.out-of-stock {
    color: var(--danger);
  }

  .column-total {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 12px;
    font-variant-numeric: tabular-nums;
  }

  .column-main .price-lines {
    gap: 1px;
  }

  .column-main .price-line {
    font-size: 12px;
    line-height: 1.2;
  }

  .column-cart {
    width: 100%;
    margin-top: 3px;
    white-space: nowrap;
  }

  .column-cart-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    white-space: nowrap;
  }

  .column-cart .add {
    width: auto;
    justify-content: flex-end;
  }

  .column-cart .qty {
    min-width: 22px;
    font-size: 14px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }

  .column-cart .step {
    width: 24px;
    height: 24px;
  }

  .column-add-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    padding: 0;
  }

  .column-add-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
  }

  .column-add-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--primary);
    cursor: pointer;
  }

  .column-add-icon:hover {
    background: var(--fill-tertiary);
    color: var(--primary-hover);
  }

  .column-main:focus-visible, .column-add-icon:focus-visible {
    outline: 2px solid var(--focus-ring);
    outline-offset: 1px;
  }

  .column-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    color: var(--muted);
  }

  .levels-head {
    display: none;
  }

  .cell {
    flex: 0 0 auto;
    min-width: 0;
  }

  .col-photo {
    grid-column: 1;
  }

  .col-name {
    grid-column: 2;
  }

  .col-meta {
    grid-column: 3;
  }

  .col-price {
    grid-column: 4;
  }

  .col-stock {
    grid-column: 5;
  }

  .col-cart {
    grid-column: 6;
  }

  .col-photo, .col-name, .col-meta, .col-price, .col-stock, .col-cart {
    grid-row: 1;
  }

  .levels-head-cell {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 4px;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
  }

  .levels-head-label {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  button.levels-head-cell {
    cursor: pointer;
  }

  button.levels-head-cell:hover {
    color: var(--primary);
  }

  .head-cart {
    justify-content: center;
    cursor: default;
  }

  .sort-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex: 0 0 auto;
    color: var(--primary);
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

  @media (min-width: 720px) {
    .levels-head {
      position: sticky;
      top: 0;
      z-index: 4;
      display: grid;
      grid-template-columns:
        var(--photo, 88px) minmax(0, 1fr) minmax(0, 180px) var(--price, 100px)
        minmax(0, 150px) var(--cart, 120px);
      align-items: stretch;
      gap: 0;
      padding: 0;
      background: var(--surface);
      border: 1px solid var(--border-secondary);
      border-bottom: 1px solid var(--border-secondary);
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

    .levels-head .col-stock, .levels-head .col-price {
      justify-content: flex-end;
    }

    .levels-head .col-stock .sort-badge, .levels-head .col-price .sort-badge {
      order: -1;
    }

    .levels:not(.tiles) {
      border-top: none;
      border-radius: 0 0 var(--radius-md) var(--radius-md);
    }

    .levels:not(.tiles) .level {
      display: grid;
      grid-template-columns:
        var(--photo, 88px) minmax(0, 1fr) minmax(0, 180px) var(--price, 100px)
        minmax(0, 150px) var(--cart, 120px);
      align-items: center;
      gap: 0;
      padding: 0;
    }

    .levels:not(.tiles) .level > .cell, .levels:not(.tiles) .level > .name-col, .levels:not(.tiles) .level > .col-meta, .levels:not(.tiles) .level > .level-bottom > .stat {
      padding: 12px var(--space-4);
    }

    .levels:not(.tiles) .level-bottom {
      display: contents;
    }

    .levels:not(.tiles) .stat-label {
      display: none;
    }

    .levels:not(.tiles) .name {
      font-weight: 600;
    }

    .levels:not(.tiles) .col-meta {
      flex-direction: column;
      align-items: flex-start;
      gap: 2px;
      font-size: 12px;
    }

    .levels:not(.tiles) .col-meta .wh, .levels:not(.tiles) .col-meta .group {
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .levels:not(.tiles) .col-stock, .levels:not(.tiles) .col-price {
      align-items: flex-end;
      text-align: right;
    }

    .levels:not(.tiles) .cart-stat {
      align-items: center;
    }

    .levels:not(.tiles) .cart-stat .add-empty {
      justify-content: center;
    }
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

  .level-bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 6px var(--space-3);
    min-width: 0;
  }

  .cart-stat {
    cursor: default;
  }

  .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .stat-label {
    font-size: 12px;
    line-height: 1.2;
    color: var(--muted);
    white-space: nowrap;
  }

  .levels.tiles {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-4);
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    overflow: visible;
  }

  @media (min-width: 480px) {
    .levels.tiles {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (min-width: 560px) {
    .levels.tiles {
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    }
  }

  @media (max-width: 719.98px) {
    .levels:not(.tiles) .level {
      flex-wrap: wrap;
      row-gap: var(--space-2);
    }

    .levels:not(.tiles) .name-col {
      flex: 1 1 60%;
    }

    .levels:not(.tiles) .level-bottom {
      flex: 1 1 100%;
      justify-content: space-between;
    }

    .levels:not(.tiles) .cart-stat {
      margin-left: auto;
    }
  }

  .levels.tiles .level {
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    gap: 0;
    padding: 0;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
    overflow: hidden;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
  }

  .levels.tiles .level:hover {
    border-color: var(--border-secondary);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
  }

  .levels.tiles .level:last-child {
    border-bottom: 1px solid var(--border-secondary);
  }

  .levels.tiles .cell.col-photo {
    --stock-tile-photo: 180px;
    width: min(100%, var(--stock-tile-photo));
    margin-inline: auto;
  }

  .levels.tiles .name-col {
    gap: var(--space-2);
    padding: var(--space-3) var(--space-3) 0;
  }

  .levels.tiles .name {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    min-height: 2.6em;
    overflow: hidden;
    font-weight: 600;
    line-height: 1.3;
  }

  .levels.tiles .col-meta {
    padding: 4px var(--space-3) 0;
  }

  .levels.tiles .meta {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    font-size: 12px;
  }

  .levels.tiles .meta .wh, .levels.tiles .meta .group {
    max-width: 100%;
    color: var(--text-description);
    font-size: 11px;
    line-height: 1.6;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .levels.tiles .meta-chip {
    display: inline-flex;
    max-width: 100%;
    padding: 0 7px;
    border-radius: 999px;
    background: var(--fill-tertiary);
  }

  .levels.tiles .tile-badge {
    display: inline-flex;
    width: fit-content;
    padding: 0 7px;
    border-radius: 999px;
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 11px;
    font-weight: 500;
    line-height: 1.6;
  }

  .levels.tiles .stat-label {
    font-size: 11px;
    line-height: 1.2;
    color: var(--muted);
  }

  .levels.tiles .col-price .stat-label {
    display: none;
  }

  .levels.tiles .level-bottom {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: var(--space-2);
    margin-top: auto;
    padding: var(--space-3);
  }

  .levels.tiles .col-price {
    order: 1;
  }

  .levels.tiles .col-stock {
    order: 2;
    flex-direction: row;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 2px var(--space-2);
    color: var(--text-description);
  }

  .levels.tiles .col-price .price {
    font-size: 16px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }

  .levels.tiles .price-lines {
    gap: 0;
  }

  .levels.tiles .col-stock .quantity {
    font-size: 14px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--text);
  }

  .levels.tiles .level.out-of-stock .quantity {
    color: var(--danger);
  }

  .levels.tiles .cart-stat {
    order: 3;
    flex: 0 0 auto;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    min-width: 0;
    margin: var(--space-2) calc(-1 * var(--space-3)) calc(-1 * var(--space-3));
    padding: var(--space-2) var(--space-3);
    border-top: 1px solid var(--border-secondary);
    background: var(--surface);
  }

  .levels.tiles .cart-stat .add {
    width: auto;
  }

  .levels.tiles .cart-stat .add-empty {
    width: 100%;
  }

  .name-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
  }

  .level.inactive {
    background: var(--danger-bg);
  }

  .inactive-tag {
    padding: 0 7px;
    border: 1px solid var(--danger);
    border-radius: 4px;
    background: var(--surface);
    font-size: 12px;
    line-height: 18px;
    color: var(--danger);
  }

  .name {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    overflow-wrap: anywhere;
  }

  .level:hover .name {
    color: var(--primary);
  }

  .level .quantity {
    font-weight: 500;
    white-space: nowrap;
  }

  .level .quantity.on-order {
    color: var(--warning-text);
    font-weight: 600;
  }

  .level .group {
    color: var(--muted);
    font-size: 12px;
  }

  .level .wh {
    color: var(--muted);
    font-size: 12px;
  }

  .level .price {
    font-weight: 600;
    white-space: nowrap;
  }

  .level .price.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .price-lines {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    min-width: 0;
  }

  .price-line {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    min-width: 0;
  }

  .price-lines .price {
    flex: 0 0 auto;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .price-lines .price.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .price-type {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--muted);
    font-size: 12px;
    font-weight: 400;
  }

  .levels .level.cart-over .qty {
    color: var(--danger);
  }

  .add {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex: 0 0 auto;
  }

  .cart-stat {
    min-width: 0;
  }

  .cart-stat .add {
    width: 100%;
    justify-content: center;
  }

  .cart-stat .add-empty {
    justify-content: center;
  }

  .qty {
    min-width: 26px;
    text-align: center;
    font-weight: 500;
    white-space: nowrap;
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: none;
    background: none;
    color: var(--text);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    color: var(--primary);
  }

  .alert, .notice {
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

  /* Телефон: действия в одну компактную строку. */


  /* Колоночный вид на телефоне: узкая колонка названия и компактные склады. */
  @media (max-width: 720px) {
    .columns-table {
      --columns-name-w: clamp(120px, 42vw, 200px);
      --columns-wh-w: 116px;
      width: calc(var(--columns-name-w) + var(--columns-count, 1) * var(--columns-wh-w));
      min-width: 100%;
    }

    .columns-head-button {
      padding: 10px var(--space-2);
    }

    .columns-head-button span {
      min-width: 0;
    }

    .columns-name {
      min-width: 0;
      max-width: none;
    }

    .columns-name-photo {
      display: none;
    }

    .columns-name .name {
      font-size: 13px;
    }

    .column-label, .column-add-label {
      display: none;
    }

    .columns-cell {
      min-width: 0;
    }
  }

</style>
