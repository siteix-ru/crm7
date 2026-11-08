<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    emailLevels,
    exportLevels,
    importHistory,
    importLevels,
    listLevels,
    listWarehouses,
    searchCounts,
    type ImportPriceType,
    type StockFilters
  } from '../lib/api/stocks';
  import type {
    PriceListItem,
    StockImportJob,
    StockLevel,
    StockUpdate,
    StockWarehouse,
    ItemGroup
  } from '../lib/api/types';
  import { listItemGroups } from '../lib/api/groups';
  import { listPriceTypes } from '../lib/api/prices';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import PhotoGallery from '../lib/components/stocks/PhotoGallery.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { addHistory } from '../lib/search-history';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { router } from '../lib/router.svelte';
  import { config } from '../lib/config';
  import { formatDate, formatDateTime, formatPrice, stockQuantityText } from '../lib/format';
  import { groupStockLevels, sortStockColumns } from '../lib/stocks/columns';

  function todayLocal(): string {
    const now = new Date();

    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }

  let warehouses = $state<StockWarehouse[]>([]);
  let levels = $state<StockLevel[]>([]);
  let jobs = $state<StockImportJob[]>([]);
  let updates = $state<StockUpdate[]>([]);
  let selectedIds = $state<number[]>([]);
  const stockFilterDefaults = {
    sort: 'name_asc',
    group_ids: null as number[] | null,
    group_no_group: true,
    per_page_list: 20,
    per_page_tiles: 12,
    view: 'list',
    photos: true,
    price_types: [] as number[],
    warehouse_ids: null as number[] | null,
    query: '',
    page: 1,
    active: 'all',
    qty_op: '',
    qty: ''
  };
  const initialStockFilters = loadFilters('stocks', stockFilterDefaults);
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
  let canImport = $state(false);
  let canEdit = $state(false);
  let busy = $state(false);
  let file = $state<File | null>(null);
  let actualDate = $state(todayLocal());
  let importOpen = $state(false);
  let importConfirm = $state(false);
  let importError = $state('');
  let importTab = $state<'import' | 'history'>('import');
  let importMappingColumns = $state<string[]>([]);
  let importMappingTypes = $state<ImportPriceType[]>([]);
  let importMapping = $state<Record<string, number>>({});
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
  const canManageWarehouses = $derived(
    warehousesEnabled &&
      (auth.can('stocks.import') || auth.can('stocks.edit') || auth.can('stocks.manage'))
  );
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
    { value: 'active', label: 'Активированные' },
    { value: 'inactive', label: 'Деактивированные' }
  ];
  const initialQtyOpRaw = String(initialStockFilters.qty_op ?? '');
  const initialQtyOp: '' | 'gt' | 'lt' =
    initialQtyOpRaw === 'gt' || initialQtyOpRaw === 'lt' ? initialQtyOpRaw : '';
  const initialQtyValue =
    initialQtyOp !== '' && Number.isFinite(Number(initialStockFilters.qty))
      ? String(Number(initialStockFilters.qty))
      : '';
  let qtyOp = $state<'' | 'gt' | 'lt'>(initialQtyOp);
  let qty = $state(initialQtyValue);
  let statusDraft = $state<string[]>([]);
  let qtyOpDraft = $state<'' | 'gt' | 'lt'>(initialQtyOp);
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
  let viewDraft = $state<'list' | 'tiles' | 'columns'>('list');
  let photoDraft = $state<boolean>(true);
  let sortOpen = $state(false);
  let sortDraft = $state<string[]>(parseSort(initialStockFilters.sort));
  let dragSortField = $state<string | null>(null);
  let dragSortOver = $state<string | null>(null);
  let groupOpen = $state(false);
  let groupDraft = $state<number[]>([]);
  let groupNoGroupDraft = $state<boolean>(true);
  let hasUngrouped = $state(true);
  let warehouseOpen = $state(false);
  let warehouseDraft = $state<number[]>([]);
  let helpOpen = $state(false);
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
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
      left: direction * Math.max(180, el.clientWidth * 0.8),
      behavior: 'smooth'
    });
  }

  function toggleColumnSort(field: 'name' | number): void {
    const base = field === 'name' ? 'name' : `warehouse_${field}`;
    const current = parseSort(stockSort);
    const existing = current.find((term) => term.startsWith(`${base}_`));
    const direction = existing?.endsWith('_asc') ? 'desc' : 'asc';
    const next = [
      `${base}_${direction}`,
      ...current.filter((term) => !term.startsWith(`${base}_`))
    ];
    const value = next.join(',');

    if (value === stockSort) {
      return;
    }

    stockSort = value;
    sortDraft = next;
    page = 1;
    saveStockFilters();
  }

  function syncColumnsFromHead(): void {
    if (columnsScrollEl && columnsHeadScrollEl) {
      columnsScrollEl.scrollLeft = columnsHeadScrollEl.scrollLeft;
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
  const sortRows = $derived.by(() => {
    const available = sortFields.filter((item) => item.field !== 'price' || pricesEnabled);
    const activeRows = sortDraft
      .map((term) => available.find((item) => term.startsWith(`${item.field}_`)))
      .filter((item): item is (typeof available)[number] => item !== undefined);
    const inactiveRows = available.filter((item) => sortOrderIndex(item.field) === 0);

    return [...activeRows, ...inactiveRows];
  });
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
      q: query,
      sort: stockSort,
      show_zero: appSettings.allowZeroStock
    };

    if (canDeactivate && stockActive !== 'all') {
      result.active = stockActive;
    }

    if (groupsEnabled && groupFilterActive) {
      result.group_ids = stockGroups;
      result.no_group = stockNoGroup;
    }

    if (qtyOp !== '' && qty !== '' && Number.isFinite(Number(qty))) {
      result.qty_op = qtyOp;
      result.qty = String(Number(qty));
    }

    return result;
  });

  const hasFilters = $derived(
    query !== '' ||
      groupFilterActive ||
      (canDeactivate && stockActive !== 'all') ||
      (qtyOp !== '' && qty !== '')
  );
  const selectedWarehouses = $derived(warehouses.filter((item) => selectedIds.includes(item.id)));
  const headDate = $derived(
    selectedWarehouses.length === 1 ? selectedWarehouses[0].actual_date : null
  );

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
      warehouseId: level.warehouse_id,
      warehouseName: level.warehouse_name || warehouse?.name || '',
      name: level.name,
      unit: level.unit,
      quantity: 1,
      stockLevelId: level.id,
      price: level.price ?? null
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
      canImport = data.can_import && appSettings.stockEditEnabled;
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
    qtyOp = '';
    qty = '';
    priceTypeFilter = [];
    page = 1;
    saveStockFilters();
    void applyFilters();
  }

  async function applyFilters(): Promise<void> {
    page = 1;
    void refreshCounts();
    await loadLevels();
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

  function sortOrderIndex(field: string): number {
    return sortDraft.findIndex((value) => value.startsWith(`${field}_`)) + 1;
  }

  function moveSortDraft(fromField: string, toField: string): void {
    const fromIndex = sortDraft.findIndex((value) => value.startsWith(`${fromField}_`));
    const toIndex = sortDraft.findIndex((value) => value.startsWith(`${toField}_`));

    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
      return;
    }

    const next = [...sortDraft];
    const [moved] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, moved);
    sortDraft = next;
  }

  function startSortDrag(event: DragEvent, field: string): void {
    dragSortField = field;

    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', field);
    }
  }

  function overSortRow(event: DragEvent, field: string): void {
    if (dragSortField === null || sortOrderIndex(field) === 0) {
      return;
    }

    event.preventDefault();
    dragSortOver = field;
  }

  function dropSortRow(event: DragEvent, field: string): void {
    event.preventDefault();

    if (dragSortField !== null && sortOrderIndex(field) > 0) {
      moveSortDraft(dragSortField, field);
    }

    dragSortField = null;
    dragSortOver = null;
  }

  function endSortDrag(): void {
    dragSortField = null;
    dragSortOver = null;
  }

  function toggleSortDir(field: string, dir: 'asc' | 'desc'): void {
    const value = `${field}_${dir}`;
    const other = `${field}_${dir === 'asc' ? 'desc' : 'asc'}`;
    const otherIndex = sortDraft.indexOf(other);

    if (sortDraft.includes(value)) {
      sortDraft = sortDraft.filter((item) => item !== value);
      return;
    }

    if (otherIndex >= 0) {
      const next = [...sortDraft];
      next[otherIndex] = value;
      sortDraft = next;
      return;
    }

    sortDraft = [...sortDraft, value];
  }

  function openSort(): void {
    const allowed = new Set(sortFields.map((item) => item.field));
    const terms = parseSort(stockSort).filter((term) =>
      [...allowed].some((field) => term.startsWith(`${field}_`))
    );

    sortDraft = terms.length > 0 ? terms : ['name_asc'];
    sortOpen = true;
  }

  function applySort(): void {
    sortOpen = false;

    const next = sortDraft.length > 0 ? sortDraft.join(',') : 'name_asc';

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
      sort: stockSort,
      group_ids: stockGroups,
      group_no_group: stockNoGroup,
      per_page_list: perPageList,
      per_page_tiles: perPageTiles,
      view: viewMode,
      photos: showPhotos,
      price_types: priceTypeFilter,
      warehouse_ids: selectedIds,
      query,
      page,
      active: stockActive,
      qty_op: qtyOp,
      qty
    });
  }

  function openView(): void {
    viewDraft = viewMode;
    photoDraft = showPhotos;
    statusDraft = [
      ...(statusActive ? ['active'] : []),
      ...(statusInactive ? ['inactive'] : [])
    ];
    const selectedPrices =
      priceTypeFilter.length > 0
        ? priceTypeFilter
        : availablePriceTypes.map((type) => type.id);
    priceTypesDraft = selectedPrices.map(String);
    qtyOpDraft = qtyOp;
    qtyDraft = qty;
    viewOpen = true;
  }

  function resetView(): void {
    viewDraft = 'list';
    photoDraft = true;
    statusDraft = ['active', 'inactive'];
    priceTypesDraft = availablePriceTypes.map((type) => String(type.id));
    qtyOpDraft = '';
    qtyDraft = '';
  }

  function applyView(): void {
    viewOpen = false;

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
    const nextQty =
      qtyOpDraft !== '' && qtyNumber !== null && Number.isFinite(qtyNumber)
        ? String(qtyNumber)
        : '';
    const nextQtyOp = nextQty !== '' ? qtyOpDraft : '';
    const qtyChanged = nextQtyOp !== qtyOp || nextQty !== qty;
    const currentFilter = [...priceTypeFilter].sort((a, b) => a - b);
    const priceChanged =
      nextFilter.length !== currentFilter.length ||
      nextFilter.some((id, index) => id !== currentFilter[index]);
    const photoChanged = photoDraft !== showPhotos;
    const viewChanged = viewDraft !== viewMode;

    if (!statusChanged && !qtyChanged && !priceChanged && !photoChanged && !viewChanged) {
      return;
    }

    viewMode = viewDraft;
    showPhotos = photoDraft;

    if (viewChanged) {
      stockSort = 'name_asc';
      sortDraft = ['name_asc'];
    }

    priceTypeFilter = nextFilter;
    qtyOp = nextQtyOp;
    qty = nextQty;

    if (statusChanged) {
      statusActive = nextStatusActive;
      statusInactive = nextStatusInactive;
    }

    saveStockFilters();

    if (statusChanged || qtyChanged || viewChanged) {
      if (viewChanged) {
        perPage = viewMode === 'tiles' ? perPageTiles : perPageList;
      }

      page = 1;

      if (statusChanged || qtyChanged) {
        void applyFilters();
      } else {
        void loadLevels();
      }
    }
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

  async function loadLevels(silent = false): Promise<void> {
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
        viewMode === 'columns' ? 0 : perPage
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
      if (seq === loadSeq && !silent) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить остатки';
      }
    } finally {
      if (seq === loadSeq) {
        loadingLevels = false;
      }
    }
  }

  async function runSearch(record: boolean): Promise<void> {
    query = searchInput.trim();

    if (record && query !== '') {
      addHistory('stocks', query);
    }

    await applyFilters();
    saveStockFilters();
  }

  async function search(): Promise<void> {
    clearTimeout(searchTimer);
    await runSearch(true);
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

  async function doImport(mapping?: Record<string, number>): Promise<void> {
    if (file === null) {
      importError = 'Выберите файл (xls, xlsx или csv)';
      return;
    }

    importConfirm = false;
    busy = true;
    importError = '';
    message = '';

    try {
      const result = await importLevels(file, actualDate, mapping);

      if (result.status === 'needs_mapping') {
        importMappingColumns = result.columns;
        importMappingTypes = result.types;
        importMapping = Object.fromEntries(result.columns.map((column) => [column, 0]));
        return;
      }

      message =
        `Импорт: загружено ${result.rows_imported} строк` +
        (result.rows_created > 0 ? `, номенклатуры: ${result.rows_created}` : '') +
        (result.warehouses_created > 0 ? `, новых складов ${result.warehouses_created}` : '') +
        (result.rows_zeroed > 0 ? `, обнулено ${result.rows_zeroed}` : '') +
        (result.rows_skipped > 0 ? `, пропущено ${result.rows_skipped}` : '') +
        (result.prices_imported > 0 ? `, цен ${result.prices_imported}` : '');

      if (result.errors.length > 0) {
        message += `. ${result.errors.slice(0, 3).join('; ')}`;
      }

      file = null;
      importOpen = false;
      importMappingColumns = [];
      importMapping = {};
      await init();
      await loadHistory();
    } catch (cause) {
      importError = cause instanceof ApiError ? cause.message : 'Не удалось импортировать файл';
    } finally {
      busy = false;
    }
  }

  async function loadHistory(): Promise<void> {
    try {
      const data = await importHistory();
      jobs = data.jobs;
      updates = data.updates;
    } catch {
      // некритично
    }
  }

  function selectImportTab(tab: 'import' | 'history'): void {
    importTab = tab;

    if (tab === 'history') {
      void loadHistory();
    }
  }

  function openImport(tab: 'import' | 'history' = 'import'): void {
    selectImportTab(tab);
    file = null;
    importError = '';
    importConfirm = false;
    importMappingColumns = [];
    importMapping = {};
    importOpen = true;
  }

  function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    file = input.files?.[0] ?? null;
    importConfirm = false;
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
    <div class="notice" role="status">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  <div class="hero page-hero">
    <div class="filters">
    <div class="head">
      <h1>Номенклатура</h1>
      {#if headDate}
        <p class="head-subtitle">Остатки на {formatDate(headDate)}</p>
      {/if}
    </div>

    {#if !loading && warehouses.length > 0}
      <form
        class="search"
        onsubmit={(event) => {
          event.preventDefault();
          void search();
        }}
      >
        <SearchInput
          bind:value={searchInput}
          historyKey="stocks"
          placeholder="Поиск номенклатуры"
          onclear={() => void search()}
          onpick={() => void search()}
        />
      </form>

      <div class="filter-icons">
        <button
          type="button"
          class="sort-button"
          class:active={stockSort !== stockFilterDefaults.sort}
          title="Сортировка"
          aria-label="Сортировка"
          onclick={openSort}
        >
            <Icon name="sort" size={16} />
        </button>

        {#if warehousesEnabled}
          <button
            type="button"
            class="sort-button"
            class:active={selectedIds.length !== warehouses.length}
            title="Склад"
            aria-label={selectedIds.length !== warehouses.length
              ? `Выбрать склад, выбрано: ${selectedIds.length}`
              : 'Выбрать склад'}
            onclick={openWarehouse}
          >
            <Icon name="stocks" size={16} />
            {#if selectedIds.length !== warehouses.length}
              <span class="icon-badge" aria-hidden="true">{selectedIds.length}</span>
            {/if}
          </button>
        {/if}

        {#if groupsEnabled}
          <button
            type="button"
            class="sort-button"
            class:active={groupFilterActive}
            title="Группы"
            aria-label="Группы"
            onclick={openGroup}
          >
            <Icon name="group" size={16} />
          </button>
        {/if}

        <button
          type="button"
          class="sort-button"
          class:active={viewMode !== 'list'}
          title="Вид"
          aria-label="Вид: списком или плитками"
          onclick={openView}
        >
          <Icon name="view" size={16} />
        </button>

        <span class="toolbar-divider" aria-hidden="true"></span>

        {#if auth.can('settings.manage') || canManageWarehouses}
          {#if auth.can('settings.manage')}
            <button
              type="button"
              class="sort-button"
              title="Управление группами номенклатуры"
              aria-label="Управление группами номенклатуры"
              onclick={() => router.navigate('/stocks/groups')}
            >
              <Icon name="tags" size={16} />
            </button>
            <button
              type="button"
              class="sort-button"
              title="Управление типами номенклатуры"
              aria-label="Управление типами номенклатуры"
              onclick={() => router.navigate('/stocks/types')}
            >
              <Icon name="types" size={16} />
            </button>
            {#if pricesEnabled}
              <button
                type="button"
                class="sort-button"
                title="Управление типами цен"
                aria-label="Управление типами цен"
                onclick={() => router.navigate('/stocks/price-types')}
              >
                <Icon name="price" size={16} />
              </button>
            {/if}
          {/if}

          {#if canManageWarehouses}
            <button
              type="button"
              class="sort-button"
              title="Управление складами"
              aria-label="Управление складами"
              onclick={() => router.navigate('/stocks/warehouses')}
            >
              <Icon name="warehouse" size={16} />
            </button>
          {/if}

          <span class="toolbar-divider" aria-hidden="true"></span>
        {/if}

        {#if canEdit}
          <button
            type="button"
            class="sort-button"
            title="Добавить номенклатуру"
            aria-label="Добавить номенклатуру"
            onclick={() => router.navigate('/stocks/items/new')}
          >
            <span class="create-stock-icon">
              <Icon name="stocks" size={16} />
              <span class="create-stock-plus" aria-hidden="true">
                <Icon name="plus" size={10} />
              </span>
            </span>
          </button>
        {/if}

        <span class="toolbar-divider" aria-hidden="true"></span>

        {#if canImport}
          <button
            type="button"
            class="sort-button"
            title="Импорт остатков из 1С"
            aria-label="Импорт остатков из 1С"
            onclick={() => openImport('import')}
          >
            <Icon name="import" size={16} />
          </button>
        {/if}

        <button
          type="button"
          class="sort-button"
          title="Экспорт"
          aria-label="Экспорт остатков"
          disabled={busy || selectedIds.length === 0}
          onclick={() => (exportOpen = true)}
        >
          <Icon name="export" size={16} />
        </button>

        <span class="toolbar-divider" aria-hidden="true"></span>

        <button
          type="button"
          class="sort-button"
          title="Как пользоваться"
          aria-label="Как пользоваться страницей"
          onclick={() => (helpOpen = true)}
        >
          <Icon name="help" size={16} />
        </button>
      </div>
    {/if}
    </div>
  </div>

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if warehouses.length === 0}
    <div class="empty">Нет доступных складов</div>
  {:else}
    <Modal
      open={importOpen}
      title="Импорт остатков из 1С"
      bodyMinHeight={372}
      onclose={() => (importOpen = false)}
    >
      <div class="tabs" role="tablist">
        <button
          type="button"
          role="tab"
          class="tab"
          class:active={importTab === 'import'}
          aria-selected={importTab === 'import'}
          onclick={() => selectImportTab('import')}
        >
          Импорт
        </button>
        <button
          type="button"
          role="tab"
          class="tab"
          class:active={importTab === 'history'}
          aria-selected={importTab === 'history'}
          onclick={() => selectImportTab('history')}
        >
          История
        </button>
      </div>

      {#if importTab === 'import'}
        {#if importMappingColumns.length > 0}
          <p class="import-hint">
            В файле есть типы цен, которых нет у нас. Укажите, как распределить цены — выбор запомнится и
            применится в следующий раз автоматически.
          </p>
          {#each importMappingColumns as column (column)}
            <div class="import-field">
              <span class="import-label">{column}</span>
              <select bind:value={importMapping[column]}>
                <option value={0}>Не импортировать</option>
                {#each importMappingTypes as type (type.id)}
                  <option value={type.id}>{type.title}</option>
                {/each}
              </select>
            </div>
          {/each}
          {#if importError}
            <div class="alert">
              <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
              <span>{importError}</span>
            </div>
          {/if}
          <div class="modal-actions">
            <Button variant="ghost" onclick={() => (importMappingColumns = [])}>Назад</Button>
            <Button loading={busy} onclick={() => void doImport(importMapping)}>Продолжить импорт</Button>
          </div>
        {:else}
          <p class="import-hint">
            Файл Excel (xls/xlsx) в формате 1С: строки «Склад …» и далее «товар; ед.; остаток на складе».
            CSV: те же три колонки либо «склад; товар; ед.; остаток на складе». Импорт заменяет остатки целиком:
            номенклатура, которой нет в файле, обнуляется и скрывается, пока в настройках системы не разрешены
            нулевые остатки. Новые склады из файла добавляются автоматически.
          </p>
          <div class="import-field">
            <label class="import-label" for="import-file">Файл</label>
            <input id="import-file" type="file" accept=".xls,.xlsx,.csv,.txt" onchange={onFileChange} />
          </div>
          <div class="import-field">
            <label class="import-label" for="import-date">Дата остатков</label>
            <input id="import-date" type="date" bind:value={actualDate} />
          </div>
          {#if importError}
            <div class="alert">
              <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
              <span>{importError}</span>
            </div>
          {/if}
          {#if importConfirm}
            <div class="import-confirm">
              <strong>Заменить остатки?</strong>
              <p>
                Файл <b>{file?.name}</b> от {actualDate} заменит остатки номенклатуры. Позиции,
                которых нет в файле, будут обнулены.
              </p>
              <div class="modal-actions">
                <Button variant="ghost" onclick={() => (importConfirm = false)}>Отмена</Button>
                <Button loading={busy} onclick={() => void doImport()}>Импортировать</Button>
              </div>
            </div>
          {:else}
            <div class="modal-actions">
              <Button variant="ghost" onclick={() => (importOpen = false)}>Отмена</Button>
              <Button loading={busy} disabled={file === null} onclick={() => (importConfirm = true)}>
                Загрузить
              </Button>
            </div>
          {/if}
        {/if}
      {:else if jobs.length === 0 && updates.length === 0}
        <p class="empty">Импортов ещё не было</p>
      {:else}
        <div class="history">
          {#each jobs as job (job.id)}
            <div class="job">
              <div class="row">
                <span class="file">{job.file_name}</span>
                <span class="status" class:failed={job.status === 'failed'}>
                  {job.status === 'done' ? 'загружен' : job.status === 'failed' ? 'ошибка' : 'в работе'}
                </span>
              </div>
              <div class="job-meta">
                <span>{formatDateTime(job.created_at)}</span>
                <span>{job.user}</span>
                <span>строк: {job.rows_imported} из {job.rows_total}</span>
                {#if job.rows_skipped > 0}<span class="warn">пропущено: {job.rows_skipped}</span>{/if}
                <span>на дату {job.actual_date}</span>
              </div>
              {#if job.errors}<div class="errors">{job.errors}</div>{/if}
            </div>
          {/each}
          {#each updates as update (`legacy-${update.id}`)}
            <div class="job">
              <div class="row">
                <span class="file">{update.file || 'обновление остатков'}</span>
                <span class="status">{update.status || 'загружено'}</span>
              </div>
              <div class="job-meta">
                <span>{formatDateTime(update.created_at)}</span>
                <span>строк: {update.rows_count}</span>
                {#if update.actual_date}<span>на дату {update.actual_date}</span>{/if}
              </div>
            </div>
          {/each}
        </div>
      {/if}
    </Modal>

    <Modal open={sortOpen} title="Сортировка" onclose={() => (sortOpen = false)}>
      <p class="sort-hint">
        Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку,
        чтобы добавить условие, и повторно — чтобы убрать.
      </p>

      <div class="sort-list" role="list">
        {#each sortRows as item (item.field)}
          {@const active = sortOrderIndex(item.field) > 0}
          <div
            class="sort-row"
            class:active
            class:drag-over={dragSortOver === item.field && dragSortField !== item.field}
            role="listitem"
            ondragover={(event) => overSortRow(event, item.field)}
            ondragleave={() => {
              if (dragSortOver === item.field) {
                dragSortOver = null;
              }
            }}
            ondrop={(event) => dropSortRow(event, item.field)}
          >
            <span
              class="sort-drag"
              class:off={!active}
              draggable={active}
              title={active ? 'Перетащите, чтобы изменить порядок' : undefined}
              aria-hidden="true"
              ondragstart={(event) => startSortDrag(event, item.field)}
              ondragend={endSortDrag}
            >
              <Icon name="holder" size={14} />
            </span>
            {#if active}
              <span class="sort-order">{sortOrderIndex(item.field)}</span>
            {/if}
            <span class="sort-row-label">{item.label}</span>
            <button
              type="button"
              class="dir-btn"
              class:on={sortDraft.includes(`${item.field}_asc`)}
              title="По возрастанию"
              aria-label={`${item.label}: по возрастанию`}
              onclick={() => toggleSortDir(item.field, 'asc')}
            >
              <Icon name="arrow-up" size={16} />
            </button>
            <button
              type="button"
              class="dir-btn"
              class:on={sortDraft.includes(`${item.field}_desc`)}
              title="По убыванию"
              aria-label={`${item.label}: по убыванию`}
              onclick={() => toggleSortDir(item.field, 'desc')}
            >
              <Icon name="arrow-down" size={16} />
            </button>
          </div>
        {/each}
      </div>
      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (sortDraft = ['name_asc'])}>Сбросить</Button>
        <div class="modal-buttons">
          <Button variant="ghost" onclick={() => (sortOpen = false)}>Отмена</Button>
          <Button onclick={applySort}>Применить</Button>
        </div>
      </div>
    </Modal>

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

    <Modal open={warehouseOpen} title="Склады" onclose={() => (warehouseOpen = false)}>
      <div class="sort-options">
        <div class="options-head">
          <button type="button" class="link-btn" onclick={selectAllWarehouses}>Отметить все</button>
          <button type="button" class="link-btn" onclick={clearAllWarehouses}>Снять все</button>
        </div>

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
    </Modal>

    <Modal open={viewOpen} title="Вид и фильтры" onclose={() => (viewOpen = false)}>
      <div class="filters-form">
        <div class="view-options">
        <label class="radio">
          <input type="radio" name="stock-view" value="list" bind:group={viewDraft} />
          <span>Список</span>
        </label>
        <label class="radio">
          <input type="radio" name="stock-view" value="tiles" bind:group={viewDraft} />
          <span>Плитка</span>
        </label>
        <label class="radio">
          <input type="radio" name="stock-view" value="columns" bind:group={viewDraft} />
          <span>Колоночный вид</span>
        </label>
      </div>

      <div class="filter-group">
        <span class="filter-label">Фото в номенклатуре</span>
        <div class="view-options">
          <label class="radio">
            <input type="radio" name="stock-photos" value={true} bind:group={photoDraft} />
            <span>С фото</span>
          </label>
          <label class="radio">
            <input type="radio" name="stock-photos" value={false} bind:group={photoDraft} />
            <span>Без фото</span>
          </label>
        </div>
      </div>

      {#if canDeactivate}
        <div class="filter-group">
          <span class="filter-label" title="Если ничего не выбрано — показываются все">Статусы</span>
          <MultiSelect
            label="Статусы"
            options={statusOptions}
            bind:values={statusDraft}
            allLabel="Все статусы"
          />
        </div>
      {/if}

      <div class="filter-group">
        <span class="filter-label">Остаток</span>
        <div class="filter-fields">
          <label class="filter-field">
            <span>Условие</span>
            <select bind:value={qtyOpDraft}>
              <option value="">Любой</option>
              <option value="gt">Больше</option>
              <option value="lt">Меньше</option>
            </select>
          </label>
          <label class="filter-field">
            <span>Значение</span>
            <input
              type="number"
              min="0"
              step="any"
              bind:value={qtyDraft}
              disabled={qtyOpDraft === ''}
              placeholder="Например, 10"
            />
          </label>
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
          />
        </div>
      {/if}

        <div class="modal-actions">
          <Button variant="ghost" onclick={resetView}>Сбросить</Button>
          <Button variant="ghost" onclick={() => (viewOpen = false)}>Отмена</Button>
          <Button onclick={applyView}>Применить</Button>
        </div>
      </div>
    </Modal>

    <Modal open={helpOpen} title="Как пользоваться" onclose={() => (helpOpen = false)} wide>
      <div class="help">
        <p class="help-intro">
          Эта страница показывает, сколько товаров есть на складах. Ниже — краткая справка по
          кнопкам и возможностям страницы.
        </p>

        <div class="help-block">
          <h3>Кнопки сверху</h3>
          <ul>
            <li>
              <b>Значок склада</b> — выбрать склады. Отметьте галочками один или сразу несколько:
              тогда товары покажутся вместе, а у каждой строки будет написан склад. Если не
              выбрать ни одного — список будет пустым.
            </li>
            <li>
              <b>Значок сортировки</b> (стрелочки ↑↓) — расставить товары по порядку. Можно
              задать несколько условий: например, «сначала по складу, потом по цене». Стрелка
              вверх — от меньшего к большему, вниз — наоборот.
            </li>
            {#if groupsEnabled}
              <li>
                <b>Значок групп</b> (слои) — оставить только нужные группы товаров. Галочками
                отметь группы; «Без группы» — товары без группы. По умолчанию отмечены все.
              </li>
            {/if}
            <li>
              <b>Значок вида</b> (квадратики) — показать товары списком или плитками, включить или
              скрыть фото, а также задать фильтры: статусы, остаток и типы цен. На телефоне плитки
              идут в 2 столбика, на компьютере — в 3. Выбор запоминается.
            </li>
            {#if canEdit}
              <li>
                <b>Карандаш</b> — в карточке номенклатуры открывает страницу редактирования: там можно
                изменить параметры и управлять фото (до 10 штук, jpg/png/webp, до 5 МБ).
              </li>
            {/if}
            <li>
              <b>Значок экспорта</b> (стрелка вниз) — скачать список товаров файлом: Excel,
              CSV, TXT или PDF. Можно отправить файл на почту, если почта настроена.
            </li>
            {#if canImport}
              <li>
                <b>Импорт из 1С</b> — загрузить свежие остатки из файла; вкладка «История» в этом
                окне показывает, когда и что загружали.
              </li>
            {/if}
          </ul>
        </div>

        <div class="help-block">
          <h3>Поиск</h3>
          <p>
            Поле слева — это поиск. Напишите часть названия (например, <b>iPhone</b>) — список сам
            покажет подходящие товары, нажимать ничего не надо. Крестик в поле очищает поиск.
            Если щёлкнуть по пустому полю, покажутся прошлые запросы.
          </p>
        </div>

        <div class="help-block">
          <h3>Сколько показывать</h3>
          <p>
            Внизу списка есть «Показывать»: 10, 20, 50, 100 или «Все». Так вы решаете, сколько
            строк видно за раз. Выбор запоминается.
          </p>
        </div>

        {#if canCreate}
          <div class="help-block">
            <h3>Как заказать товар (сделать заявку)</h3>
            <ol>
              <li>Найдите нужный товар через поиск.</li>
              <li>Нажмите в строке товара <b>«Добавить в корзину»</b> — товар попадёт в заявку.</li>
              <li>Нужно больше — нажмите <b>«+»</b>; <b>«−»</b> — уменьшить количество.</li>
              <li>
                Когда всё выбрано, откройте <b>корзину</b> (значок корзины вверху, там видно число
                товаров) и отправьте заявку.
              </li>
            </ol>
            <p class="help-note">
              Чтобы убрать товар из заявки: нажимайте «−», пока количество не станет 0, либо
              удалите его на странице корзины.
            </p>
          </div>
        {/if}

        <div class="help-block">
          <h3>Что означают отметки</h3>
          <ul>
            <li><b>«0 шт»</b> — товара на складе нет.</li>
            <li><b>«под заказ»</b> — товар можно заказать даже в минус (если так разрешено).</li>
            {#if pricesEnabled}
              <li><b>Цена</b> — сколько товар стоит для вас.</li>
            {/if}
            {#if groupsEnabled}
              <li><b>Группа</b> — к какой группе относится товар.</li>
            {/if}
          </ul>
        </div>

        {#if canEdit}
          <div class="help-block">
            <h3>Для администратора</h3>
            <ul>
              <li><b>Плюс</b> — добавить новую номенклатуру.</li>
              <li>
                <b>Название склада</b> меняется в разделе «Склады»: карточка склада →
                «Редактировать».
              </li>
              <li><b>Карандаш</b> в карточке номенклатуры — открыть редактирование номенклатуры.</li>
            </ul>
          </div>
        {/if}

        {#if canImport}
          <div class="help-block">
            <h3>Для менеджера</h3>
            <ul>
              <li><b>«Импорт остатков из 1С»</b> — загрузить свежие остатки из файла.</li>
            </ul>
          </div>
        {/if}
      </div>
    </Modal>

    {#if loadingLevels}
      <div class="center"><Spinner size={26} /></div>
    {:else if selectedIds.length === 0}
      <div class="empty">Нет выбранных складов — нет номенклатуры</div>
    {:else if levels.length === 0}
      <div class="empty">
        <span>Ничего не найдено</span>
        {#if hasFilters || groupFilterEmpty}
          <Button variant="ghost" onclick={resetFilters}>Сбросить фильтры</Button>
        {/if}
      </div>
    {:else}
      <div class="levels-wrap">
      {#if viewMode === 'columns'}
        <div class="columns-scroll-shell">
          {#if columnsCanScroll}
            <div class="columns-scroll-bar">
              <span class="columns-scroll-label">Склады</span>
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
              <table class="columns-table columns-table-head" aria-label="Заголовок таблицы остатков">
                <colgroup>
                  <col style="width: 280px" />
                  {#each selectedWarehouses as warehouse (warehouse.id)}
                    <col style="width: 220px" />
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
          <div class="columns-table-wrap" bind:this={columnsScrollEl}>
            <table class="columns-table" aria-label="Остатки по складам">
              <colgroup>
                <col style="width: 280px" />
                {#each selectedWarehouses as warehouse (warehouse.id)}
                  <col style="width: 220px" />
                {/each}
              </colgroup>
              <tbody>
              {#each columnPageRows as row (row.key)}
                {@const first = row.levels.values().next().value}
                <tr>
                  <th scope="row" class="columns-name">
                    <button
                      type="button"
                      class="name"
                      title="Открыть номенклатуру"
                      onclick={() => first && openItem(first)}
                    >
                      {row.name}
                    </button>
                    {#if groupsEnabled && row.group_title}
                      <span class="column-group">{row.group_title}</span>
                    {/if}
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
                          <span class="column-qty">{stockQuantityText(cell.quantity, cell.unit)}</span>
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
                            {:else}
                              <Button
                                variant="text"
                                size="sm"
                                title="Добавить в корзину"
                                ariaLabel={`Добавить в корзину: ${row.name}`}
                                onclick={() => addToCart(cell)}
                              >
                                <Icon name="cart" size={16} /> В корзину
                              </Button>
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
          <!-- svelte-ignore a11y_click_events_have_key_events -->
          <!-- svelte-ignore a11y_no_static_element_interactions -->
          <div
            class="level"
            class:cart-over={cartQty > 0 && cartQty > level.quantity}
            class:inactive={level.active === false}
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
              <!-- svelte-ignore a11y_click_events_have_key_events -->
              <!-- svelte-ignore a11y_no_static_element_interactions -->
              <div class="cell col-photo" onclick={(event) => event.stopPropagation()}>
                <PhotoGallery
                  photos={level.photos ?? []}
                  variant={viewMode === 'tiles' ? 'tile' : 'list'}
                  flush={viewMode === 'tiles'}
                  onopen={() => openItem(level)}
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
              {/if}
            </div>
            {#if (warehousesEnabled && level.warehouse_name) || (groupsEnabled && level.group_title)}
              <div class="meta col-meta">
                {#if warehousesEnabled && level.warehouse_name}<span class="wh">{level.warehouse_name}</span>{/if}
                {#if groupsEnabled && level.group_title}<span class="group">{level.group_title}</span>{/if}
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
                    <span class="add add-empty">
                      <Button
                        variant="text"
                        size="sm"
                        title="Добавить в корзину"
                        ariaLabel={`Добавить в корзину: ${level.name}`}
                        onclick={() => addToCart(level)}
                      >
                        <Icon name="cart" size={16} /> Добавить
                      </Button>
                    </span>
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

  .page:has(.levels.tiles),
  .page:has(.columns-table) {
    max-width: 1600px;
  }

  .head {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  .head-subtitle {
    margin: 0;
    font-size: 14px;
    color: var(--text-description);
  }

  .tabs {
    display: flex;
    gap: var(--space-6);
    margin-bottom: var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
  }

  .tab {
    position: relative;
    padding: 10px 0;
    border: none;
    background: none;
    font: inherit;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .tab:hover {
    color: var(--primary);
  }

  .tab.active {
    color: var(--primary);
  }

  .tab.active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 2px;
    background: var(--primary);
  }

  .import-hint {
    margin: 0 0 var(--space-4);
    font-size: 13px;
    line-height: 1.5;
    color: var(--text-description);
  }

  .import-confirm {
    padding: var(--space-3);
    border: 1px solid var(--warning);
    border-radius: var(--radius-sm);
    background: var(--warning-bg);
  }

  .import-confirm p {
    margin: var(--space-2) 0 var(--space-3);
    font-size: 13px;
    line-height: 1.45;
    color: var(--text);
  }

  .import-field {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-bottom: var(--space-4);
  }

  .import-label {
    font-size: 14px;
    color: var(--text);
  }

  .import-field input[type='file'],
  .import-field input[type='date'],
  .import-field select {
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 14px;
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

  .hero {
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
    align-items: center;
    justify-content: flex-start;
  }

  .search {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    flex: 1 1 220px;
    max-width: 360px;
    min-width: 0;
  }

  .filter-icons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
  }

  .create-stock-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .create-stock-plus {
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

  .sort-button {
    position: relative;
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
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  .icon-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 10px;
    line-height: 16px;
    text-align: center;
  }

  .sort-button:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .sort-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .sort-button.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .toolbar-divider {
    width: 1px;
    height: 24px;
    margin: 0 var(--space-1);
    background: var(--border-secondary);
  }

  .sort-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .options-head {
    display: flex;
    gap: var(--space-4);
    margin-bottom: var(--space-1);
    padding-bottom: var(--space-2);
    border-bottom: 1px solid var(--border-secondary);
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

  .sort-hint {
    margin: 0 0 var(--space-2);
    font-size: 13px;
    color: var(--text-description);
  }

  .sort-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 340px;
    overflow-y: auto;
  }

  .sort-row.active .sort-row-label {
    color: var(--text);
    font-weight: 600;
  }

  .sort-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
  }

  .sort-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
  }

  .sort-row.active {
    border-color: var(--primary-border);
    background: var(--primary-bg);
  }

  .sort-row.drag-over {
    border-color: var(--primary);
    box-shadow: inset 0 2px 0 var(--primary);
  }

  .sort-drag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 18px;
    height: 24px;
    color: var(--text-description);
    cursor: grab;
  }

  .sort-drag:active {
    cursor: grabbing;
  }

  .sort-drag.off {
    visibility: hidden;
    cursor: default;
  }

  .sort-row-label {
    flex: 1;
    min-width: 0;
    font-size: 14px;
  }

  .dir-btn {
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
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
  }

  .dir-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .dir-btn.on {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
  }

  .sort-option {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
    cursor: pointer;
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

  .help-intro {
    margin: 0 0 var(--space-4);
    font-size: 14px;
    color: var(--muted);
  }

  .help-block {
    margin-bottom: var(--space-4);
  }

  .help-block:last-child {
    margin-bottom: 0;
  }

  .help-block h3 {
    margin: 0 0 var(--space-2);
    font-size: 15px;
  }

  .help-block p {
    margin: 0;
    font-size: 14px;
  }

  .help ul,
  .help ol {
    margin: 0;
    padding-left: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 14px;
  }

  .help-note {
    margin: var(--space-2) 0 0;
    font-size: 13px;
    color: var(--muted);
  }

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
      max-width: none;
    }
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
    justify-content: space-between;
    gap: var(--space-2);
    padding-inline: 2px;
  }

  .columns-scroll-label {
    color: var(--muted);
    font-size: 12px;
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

  .columns-table th,
  .columns-table td {
    padding: 10px 12px;
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

  .columns-table tbody tr:last-child th,
  .columns-table tbody tr:last-child td {
    border-bottom: none;
  }

  .columns-table tbody tr:hover {
    background: var(--fill-tertiary);
  }

  .columns-table .columns-name-head,
  .columns-table .columns-name {
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
    background: var(--surface);
  }

  .columns-name {
    font-weight: 500;
  }

  .columns-name .name {
    display: block;
    max-width: 100%;
    text-align: left;
    line-height: 1.35;
  }

  .column-group {
    display: block;
    margin-top: 2px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 400;
  }

  .columns-cell {
    min-width: 190px;
  }

  .columns-cell.cart-over {
    background: var(--danger-bg);
  }

  .column-main {
    display: flex;
    flex-direction: column;
    gap: 4px;
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

  .column-qty {
    font-weight: 600;
    white-space: nowrap;
  }

  .column-main .price-lines {
    gap: 2px;
  }

  .column-main .price-line {
    font-size: 12px;
  }

  .column-cart {
    margin-top: 8px;
    padding-top: 6px;
    border-top: 1px dashed var(--border-secondary);
  }

  .column-cart .add {
    width: 100%;
    justify-content: center;
  }

  .column-empty {
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

  .col-photo,
  .col-name,
  .col-meta,
  .col-price,
  .col-stock,
  .col-cart {
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

    .levels-head .col-stock,
    .levels-head .col-price {
      justify-content: flex-end;
    }

    .levels-head .col-stock .sort-badge,
    .levels-head .col-price .sort-badge {
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

    .levels:not(.tiles) .level > .cell,
    .levels:not(.tiles) .level > .name-col,
    .levels:not(.tiles) .level > .col-meta,
    .levels:not(.tiles) .level > .level-bottom > .stat {
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

    .levels:not(.tiles) .col-meta .wh,
    .levels:not(.tiles) .col-meta .group {
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .levels:not(.tiles) .col-stock,
    .levels:not(.tiles) .col-price {
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
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-4);
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    overflow: visible;
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
      justify-content: flex-start;
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
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .levels.tiles .level:hover {
    border-color: var(--border-secondary);
    box-shadow: var(--shadow-md);
  }

  .levels.tiles .level:last-child {
    border-bottom: 1px solid var(--border-secondary);
  }

  .levels.tiles .cell.col-photo {
    width: 100%;
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
    overflow: hidden;
    font-weight: 600;
    line-height: 1.3;
  }

  .levels.tiles .col-meta {
    padding: 4px var(--space-3) 0;
  }

  .levels.tiles .meta {
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    font-size: 12px;
  }

  .levels.tiles .meta .wh,
  .levels.tiles .meta .group {
    max-width: 100%;
    color: var(--text-description);
    font-size: 12px;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
  }

  .levels.tiles .price-lines {
    gap: 0;
  }

  .levels.tiles .col-stock .quantity {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
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

  .history {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .job {
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
  }

  .job .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .file {
    font-weight: 500;
  }

  .status {
    font-size: 12px;
    color: var(--success-text);
  }

  .status.failed {
    color: var(--error-text);
  }

  .job-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .warn {
    color: var(--warning-text);
  }

  .errors {
    margin-top: 4px;
    font-size: 12px;
    color: var(--error-text);
    white-space: pre-wrap;
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
