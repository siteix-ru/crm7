<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    getStaffFacets,
    listStaff,
    staffLevelTitle,
    staffStateTitle,
    type StaffUser
  } from '../lib/api/staff';
  import { listManagers } from '../lib/api/requests';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { countActive, loadFilters, saveFilters } from '../lib/filters';
  import { filterStorageKey } from '../lib/stores/filter-prefs.svelte';
  import { formatDateTime } from '../lib/format';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';
  import { withReturnTo } from '../lib/navigation-context';
  import { maxPage } from '../lib/views/pagination';
  import {
    isLegacyStaffView,
    STAFF_FIELDS,
    STAFF_OPTIONAL_KEYS,
    normalizeStaffFields,
    initialStaffViewMode,
    listStaffFields,
    hiddenStaffFields,
    staffTileFields,
    staffMetaFields,
    type ViewMode
  } from '../lib/views/staff-fields';
  import Button from '../lib/components/ui/Button.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import Substitutions from './Substitutions.svelte';

  let { initialTab = 'staff' }: { initialTab?: 'staff' | 'substitutions' } = $props();

  function initialTabValue(): 'staff' | 'substitutions' {
    return initialTab === 'substitutions' ? 'substitutions' : 'staff';
  }

  const filterDefaults = {
    levels: [] as string[],
    states: [] as string[],
    cities: [] as string[],
    departments: [] as string[],
    offices: [] as string[],
    positions: [] as string[],
    sort: 'level_desc',
    view: 'list',
    viewExplicit: false,
    q: '',
    page: 1,
    per_page: 20,
    fields: { list: [...STAFF_OPTIONAL_KEYS], tiles: [...STAFF_OPTIONAL_KEYS] }
  };

  const filterCountDefaults = {
    levels: filterDefaults.levels,
    states: filterDefaults.states,
    cities: filterDefaults.cities,
    departments: filterDefaults.departments,
    offices: filterDefaults.offices,
    positions: filterDefaults.positions
  };

  const substFilterDefaults = {
    statuses: [] as string[],
    managerIds: [] as string[],
    substituteIds: [] as string[],
    periodFrom: '',
    periodTo: ''
  };

  function cloneSubstFilters(
    source: typeof substFilterDefaults = substFilterDefaults
  ): typeof substFilterDefaults {
    return {
      statuses: [...source.statuses],
      managerIds: [...source.managerIds],
      substituteIds: [...source.substituteIds],
      periodFrom: source.periodFrom,
      periodTo: source.periodTo
    };
  }

  const roleOptions = [
    { value: '90', label: 'Технический администратор' },
    { value: '50', label: 'Администратор' },
    { value: '10', label: 'Менеджер' }
  ];

  const stateOptions = [
    { value: 'active', label: 'Активные' },
    { value: 'blocked', label: 'Заблокированные' }
  ];

  const substitutionStatusOptions = [
    { value: 'active', label: 'Активные' },
    { value: 'finished', label: 'Завершённые' }
  ];

  const sortFields = [
    { field: 'name', label: 'ФИО' },
    { field: 'level', label: 'Роль' },
    { field: 'department', label: 'Отдел' },
    { field: 'city', label: 'Город' },
    { field: 'office', label: 'Офис' },
    { field: 'created', label: 'Дата создания' },
    { field: 'last_seen', label: 'Последний вход' }
  ];

  const initialFilters = loadFilters('staff', filterDefaults);

  // Legacy-записи сохраняли view без viewExplicit: считаем их явным выбором пользователя.
  let legacyView = false;
  try {
    const raw = JSON.parse(
      localStorage.getItem(filterStorageKey('staff', auth.user?.id ?? null)) ?? '{}'
    );
    legacyView = isLegacyStaffView(raw);
  } catch {
    legacyView = false;
  }
  const restoredViewExplicit = legacyView ? true : initialFilters.viewExplicit === true;

  let items = $state<StaffUser[]>([]);
  let total = $state(0);
  let page = $state(Math.max(1, Math.trunc(Number(initialFilters.page) || 1)));
  let perPage = $state(
    [0, 10, 20, 50, 100].includes(Number(initialFilters.per_page))
      ? Number(initialFilters.per_page)
      : 20
  );
  let loading = $state(true);
  let error = $state('');
  let listAnnouncement = $state('');
  let search = $state(String(initialFilters.q ?? ''));
  let searchInput = $state(String(initialFilters.q ?? ''));
  let filters = $state({ ...initialFilters });
  let draft = $state({
    levels: [...initialFilters.levels],
    states: [...initialFilters.states],
    cities: [...initialFilters.cities],
    departments: [...initialFilters.departments],
    offices: [...initialFilters.offices],
    positions: [...initialFilters.positions]
  });
  let facets = $state({
    cities: [] as string[],
    departments: [] as string[],
    offices: [] as string[],
    positions: [] as string[]
  });
  const cityOptions = $derived(facets.cities.map((city) => ({ value: city, label: city })));
  const departmentOptions = $derived(
    facets.departments.map((department) => ({ value: department, label: department }))
  );
  const officeOptions = $derived(
    facets.offices.map((office) => ({ value: office, label: office }))
  );
  const positionOptions = $derived(
    facets.positions.map((position) => ({ value: position, label: position }))
  );
  let viewportWidth = $state(typeof window === 'undefined' ? 1440 : window.innerWidth);
  let viewMode = $state<'list' | 'tiles'>(
    initialStaffViewMode(initialFilters.view, legacyView ? undefined : restoredViewExplicit, window.innerWidth)
  );
  let viewExplicit = $state(restoredViewExplicit);
  let fieldsByView = $state<Record<ViewMode, string[]>>(
    normalizeStaffFields(initialFilters.fields)
  );
  let sortOpen = $state(false);
  let viewOpen = $state(false);
  let filtersRef: FiltersModal | null = $state(null);
  let viewDraft = $state<{ view: ViewMode; fields: Record<ViewMode, string[]> }>({
    view: initialStaffViewMode(initialFilters.view, legacyView ? undefined : restoredViewExplicit, window.innerWidth),
    fields: normalizeStaffFields(initialFilters.fields)
  });

  const columns = $derived(listStaffFields(fieldsByView.list, viewportWidth));
  const headFields = $derived([
    STAFF_FIELDS.find((field) => field.key === 'name')!,
    ...columns,
    STAFF_FIELDS.find((field) => field.key === 'status')!
  ]);
  const hiddenFields = $derived(hiddenStaffFields(fieldsByView.list, viewportWidth));
  const showRowMeta = $derived(hiddenFields.length > 0 || viewportWidth <= 720);
  const viewColumns = $derived(
    ['minmax(220px, 1.5fr)', ...columns.map((field) => field.width ?? 'minmax(120px, 1fr)'), '90px'].join(' ')
  );
  const currentFullRoute = $derived(
    `${router.current.path}${router.current.query.toString() ? `?${router.current.query.toString()}` : ''}`
  );
  let tab = $state<'staff' | 'substitutions'>(initialTabValue());
  let substSearch = $state('');
  let substSearchInput = $state('');
  let substSort = $state('date_desc');
  let substView = $state<'list' | 'tiles'>('list');
  let substFilters = $state(cloneSubstFilters());
  let substDraft = $state(cloneSubstFilters());
  let substManagers = $state<{ id: number; name: string }[]>([]);

  const canCreate = $derived(auth.can('users.manage') && !appSettings.demoMode);
  const canAddSubstitution = $derived(auth.can('requests.view.all'));
  const filtersActive = $derived(countActive(filters, filterCountDefaults) > 0);
  const substFiltersActive = $derived(countActive(substFilters, substFilterDefaults) > 0);
  const sortActive = $derived(filters.sort !== filterDefaults.sort);
  const substSortActive = $derived(substSort !== 'date_desc');
  const substManagerOptions = $derived(
    substManagers.map((manager) => ({ value: String(manager.id), label: manager.name }))
  );
  const showSubstitutionsTab = $derived(
    auth.can('clients.assign') && appSettings.substitutionsEnabled
  );

  const substitutionSortFields = [
    { field: 'date', label: 'Дата начала' },
    { field: 'manager', label: 'Кто отсутствует' },
    { field: 'substitute', label: 'Заместитель' },
    { field: 'status', label: 'Статус' },
    { field: 'requests', label: 'Заявки' }
  ];

  $effect(() => {
    if (!showSubstitutionsTab && tab === 'substitutions') {
      tab = 'staff';
    }
  });

  onMount(() => {
    void load();
    void loadFacets();
    void loadSubstManagers();

    const breakpoints = [720, 900, 1024, 1200].map((width) =>
      window.matchMedia(`(min-width: ${width + 1}px)`)
    );
    const update = (): void => {
      viewportWidth = window.innerWidth;
    };
    breakpoints.forEach((query) => query.addEventListener('change', update));
    return () => breakpoints.forEach((query) => query.removeEventListener('change', update));
  });

  async function loadSubstManagers(): Promise<void> {
    try {
      substManagers = (await listManagers()).items;
    } catch {
      substManagers = [];
    }
  }

  async function loadFacets(): Promise<void> {
    try {
      facets = await getStaffFacets();
    } catch {
      facets = { cities: [], departments: [], offices: [], positions: [] };
    }
  }

  let loadSeq = 0;
  let searchAbort: AbortController | null = null;

  function abortSearch(): void {
    searchAbort?.abort();
    searchAbort = null;
  }

  function saveStaffFilters(): void {
    saveFilters('staff', {
      ...filters,
      q: search,
      page,
      per_page: perPage,
      view: viewMode,
      viewExplicit,
      fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles] }
    });
  }

  async function load(signal?: AbortSignal): Promise<{ ok: boolean; total: number }> {
    const seq = ++loadSeq;
    loading = true;
    error = '';

    try {
      const data = await listStaff({
        q: search,
        levels:
          filters.levels.length > 0 ? filters.levels.map((value) => Number(value)) : undefined,
        states: filters.states.length > 0 ? filters.states : undefined,
        cities: filters.cities.length > 0 ? filters.cities : undefined,
        departments: filters.departments.length > 0 ? filters.departments : undefined,
        offices: filters.offices.length > 0 ? filters.offices : undefined,
        positions: filters.positions.length > 0 ? filters.positions : undefined,
        sort: filters.sort,
        page,
        perPage,
        signal
      });
      if (seq !== loadSeq) {
        return { ok: false, total: 0 };
      }
      const pagesCount = maxPage(data.total, perPage);
      if (page > pagesCount) {
        page = pagesCount;
        saveStaffFilters();
        return load(signal);
      }
      items = data.items;
      total = data.total;
      return { ok: true, total: data.total };
    } catch (cause) {
      if (seq !== loadSeq) {
        return { ok: false, total: 0 };
      }
      if (cause instanceof DOMException && cause.name === 'AbortError') {
        return { ok: false, total: 0 };
      }
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сотрудников';
      return { ok: false, total: 0 };
    } finally {
      if (seq === loadSeq) {
        loading = false;
      }
    }
  }

  function announceList(text: string): void {
    listAnnouncement = '';
    requestAnimationFrame(() => {
      listAnnouncement = text;
    });
  }

  async function submitSearch(): Promise<void> {
    if (tab === 'substitutions') {
      substSearch = substSearchInput.trim();
      return;
    }

    abortSearch();
    const controller = new AbortController();
    searchAbort = controller;
    search = searchInput.trim();

    if (search !== '') {
      addHistory('staff', search);
    }

    page = 1;
    saveStaffFilters();
    const result = await load(controller.signal);
    if (controller.signal.aborted) {
      return;
    }
    announceList(result.ok ? `Найдено: ${result.total}` : 'Не удалось выполнить поиск');
  }

  function applySearch(): void {
    if (tab === 'substitutions') {
      substSearch = '';
      substSearchInput = '';
      return;
    }

    abortSearch();
    page = 1;
    saveStaffFilters();
    void load();
  }

  function goToPage(next: number): void {
    page = next;
    saveStaffFilters();
    void load();
  }

  function changePerPage(value: number): void {
    perPage = value;
    page = 1;
    saveStaffFilters();
    void load();
  }

  function openView(): void {
    if (tab === 'substitutions') {
      viewDraft = { view: substView, fields: viewDraft.fields };
    } else {
      viewDraft = {
        view: viewMode,
        fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles] }
      };
    }
    viewOpen = true;
  }

  function applyView(): void {
    if (tab === 'substitutions') {
      substView = viewDraft.view;
    }
    viewOpen = false;
  }

  function resetView(): void {
    if (tab === 'substitutions') {
      substView = 'list';
      viewDraft = { view: substView, fields: viewDraft.fields };
      return;
    }

    viewMode = initialStaffViewMode('list', false, viewportWidth);
    viewExplicit = false;
    fieldsByView = {
      list: [...STAFF_OPTIONAL_KEYS],
      tiles: [...STAFF_OPTIONAL_KEYS]
    };
    viewDraft = {
      view: viewMode,
      fields: { list: [...fieldsByView.list], tiles: [...fieldsByView.tiles] }
    };
    saveStaffFilters();
    announceList(`Вид сброшен. Найдено: ${total}`);
  }

  function isStaffFieldVisible(key: string, mode: ViewMode): boolean {
    return (
      STAFF_FIELDS.find((field) => field.key === key)?.required === true ||
      viewDraft.fields[mode].includes(key)
    );
  }

  function toggleViewField(key: string, mode: ViewMode): void {
    const next = fieldsByView[mode].includes(key)
      ? fieldsByView[mode].filter((item) => item !== key)
      : STAFF_OPTIONAL_KEYS.filter((item) => item === key || fieldsByView[mode].includes(item));
    fieldsByView = { ...fieldsByView, [mode]: next };
    viewDraft = {
      ...viewDraft,
      fields: { ...viewDraft.fields, [mode]: [...next] }
    };
    saveStaffFilters();
    announceList(`Вид обновлён. Найдено: ${total}`);
  }

  function setViewModeNow(mode: ViewMode): void {
    if (tab === 'substitutions') {
      substView = mode;
      viewDraft = { ...viewDraft, view: mode };
      return;
    }
    viewMode = mode;
    viewExplicit = true;
    viewDraft = { ...viewDraft, view: mode };
    saveStaffFilters();
    announceList(`Вид: ${mode === 'tiles' ? 'плитки' : 'список'}. Найдено: ${total}`);
  }

  function openFilters(): void {
    if (tab === 'substitutions') {
      substDraft = cloneSubstFilters(substFilters);
    } else {
      draft = {
        levels: [...filters.levels],
        states: [...filters.states],
        cities: [...filters.cities],
        departments: [...filters.departments],
        offices: [...filters.offices],
        positions: [...filters.positions]
      };
    }
    filtersRef?.show();
  }

  async function applyFilterDraft(): Promise<string> {
    if (tab === 'substitutions') {
      substFilters = cloneSubstFilters(substDraft);
      return 'Фильтры применены';
    }

    filters = {
      ...filters,
      levels: [...draft.levels],
      states: [...draft.states],
      cities: [...draft.cities],
      departments: [...draft.departments],
      offices: [...draft.offices],
      positions: [...draft.positions]
    };
    page = 1;
    saveStaffFilters();
    const result = await load();
    return result.ok
      ? `Фильтры применены. Найдено: ${result.total}`
      : 'Не удалось применить фильтры. Попробуйте ещё раз.';
  }

  async function resetFilterDraft(): Promise<string> {
    if (tab === 'substitutions') {
      substDraft = cloneSubstFilters();
      substFilters = cloneSubstFilters();
      return 'Фильтры сброшены';
    }

    draft = {
      levels: [],
      states: [],
      cities: [],
      departments: [],
      offices: [],
      positions: []
    };
    const result = await applyFilterDraft();
    return result.startsWith('Не удалось') ? result : `Фильтры сброшены. ${result}`;
  }

  function resetFilters(): void {
    filters = { ...filterDefaults };
    draft = {
      levels: [],
      states: [],
      cities: [],
      departments: [],
      offices: [],
      positions: []
    };
    viewMode = initialStaffViewMode('list', false, viewportWidth);
    viewExplicit = false;
    fieldsByView = {
      list: [...STAFF_OPTIONAL_KEYS],
      tiles: [...STAFF_OPTIONAL_KEYS]
    };
    page = 1;
    saveStaffFilters();
    void load();
  }

  function setSort(next: string): void {
    if (next === filters.sort) {
      return;
    }

    filters = { ...filters, sort: next };
    page = 1;
    saveStaffFilters();
    void load();
  }

  function applySort(terms: string[]): void {
    sortOpen = false;

    if (tab === 'substitutions') {
      substSort = terms.length > 0 ? terms.join(',') : 'date_desc';
      return;
    }

    setSort(terms.length > 0 ? terms.join(',') : filterDefaults.sort);
  }

  function openStaff(staff: StaffUser): void {
    router.navigate(`/staff/${staff.id}`);
  }

  function initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }
</script>

<section class="page page-wide">
  <div class="sr-only" role="status" aria-live="polite" aria-hidden={viewOpen}>
    {listAnnouncement}
  </div>

  <PageToolbar title={tab === 'staff' ? 'Сотрудники' : 'Замещения'}>
    {#snippet actions()}
      <ToolbarMenu
        label="Действия со списком"
        search={{
          value: tab === 'staff' ? search : substSearch,
          historyKey: tab === 'staff' ? 'staff' : 'substitutions',
          title: tab === 'staff' ? 'Поиск по сотрудникам' : 'Поиск по замещениям',
          placeholder: tab === 'staff'
            ? 'Имя, логин, телефон, e-mail, отдел, город, офис'
            : 'Кто, заместитель, причина, даты',
          suggestions: tab === 'staff' ? items.map((user) => user.name) : [],
          onsearch: async (query) => {
            if (tab === 'staff') {
              searchInput = query;
            } else {
              substSearchInput = query;
            }
            await submitSearch();
          },
          onclear: () => {
            abortSearch();
            if (tab === 'staff') {
              searchInput = '';
              search = '';
              page = 1;
              saveStaffFilters();
              void load();
            } else {
              substSearchInput = '';
              substSearch = '';
            }
          },
          onstop: abortSearch
        }}
        items={[
          {
            id: 'sort',
            label: 'Сортировка',
            icon: 'sort',
            checked: tab === 'staff' ? sortActive : substSortActive,
            dialog: true,
            onclick: () => (sortOpen = true)
          },
          {
            id: 'view',
            label: `Вид: ${tab === 'staff' ? (viewMode === 'tiles' ? 'Плитки' : 'Список') : (substView === 'tiles' ? 'Плитки' : 'Список')}`,
            icon: 'view',
            dialog: true,
            checked: tab === 'staff'
              ? viewMode === 'tiles' ||
                fieldsByView.list.length !== STAFF_OPTIONAL_KEYS.length ||
                fieldsByView.tiles.length !== STAFF_OPTIONAL_KEYS.length
              : substView === 'tiles',
            onclick: openView
          },
          {
            id: 'filters',
            label: 'Фильтры',
            icon: 'filter',
            dialog: true,
            count: tab === 'staff'
              ? (filtersActive ? countActive(filters, filterCountDefaults) : 0)
              : (substFiltersActive ? countActive(substFilters, substFilterDefaults) : 0),
            onclick: openFilters
          },
          ...(showSubstitutionsTab && tab === 'staff'
            ? [
                {
                  id: 'substitutions',
                  label: 'Замещения',
                  icon: 'substitutions',
                  onclick: () => router.navigate('/substitutions')
                }
              ]
            : []),
          ...((tab === 'staff' ? canCreate : canAddSubstitution)
            ? [
                { id: 'sep', label: '', separator: true, onclick: () => {} },
                {
                  id: 'create',
                  label: tab === 'staff' ? 'Новый сотрудник' : 'Новое замещение',
                  icon: 'plus',
                  onclick: () =>
                    router.navigate(tab === 'staff' ? '/staff/new' : '/staff/substitutions/new')
                }
              ]
            : [])
        ]}
      />
    {/snippet}
  </PageToolbar>

  {#if tab === 'substitutions'}
    <Substitutions
      embedded
      search={substSearch}
      sort={substSort}
      view={substView}
      statuses={substFilters.statuses}
      managerIds={substFilters.managerIds}
      substituteIds={substFilters.substituteIds}
      periodFrom={substFilters.periodFrom}
      periodTo={substFilters.periodTo}
    />
  {:else}
  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span class="notify-text">{error}</span>
      <button type="button" class="notify-close" aria-label="Закрыть" onclick={() => (error = '')}>
        <Icon name="close" size={14} />
      </button>
    </div>
  {/if}

  {#if loading}
    <ListSkeleton rows={6} />
  {:else if items.length === 0}
    <EmptyState
      icon="staff"
      title="Сотрудники не найдены"
      description="Измените поиск или сбросьте фильтры."
    >
      {#snippet action()}
        <Button variant="ghost" onclick={resetFilters}>Сбросить поиск и фильтры</Button>
      {/snippet}
    </EmptyState>
  {:else if viewMode === 'tiles'}
    <div class="tiles-grid">
      {#each items as staff (staff.id)}
        <a class="tile" href={`#${withReturnTo(`/staff/${staff.id}`, currentFullRoute)}`}>
          <div class="tile-head">
            <span class="avatar">{initials(staff.name)}</span>
            <div class="tile-title">
              <span class="name">{staff.name}</span>
              <span class="login">{staff.login}</span>
            </div>
          </div>
          {#each staffTileFields(fieldsByView.tiles) as field (field.key)}
            {#each field.rows(staff) as row (row.label)}
              <div class="tile-row"><span>{row.label}</span><strong>{row.value}</strong></div>
            {/each}
          {/each}
          <div class="tile-footer">
            <span class="state" class:on={staff.active}>{staffStateTitle(staff.active)}</span>
          </div>
        </a>
      {/each}
    </div>
  {:else}
    <div class="list" role="table" aria-label="Сотрудники" style:--st-columns={viewColumns}>
      <div class="row row-head" role="row">
        {#each headFields as field (field.key)}
          <span class={field.headClass} role="columnheader">{field.headLabel ?? field.label}</span>
        {/each}
      </div>

      {#each items as staff (staff.id)}
        <div class="row" role="row">
          <div class="who" role="cell">
            <span class="avatar">{initials(staff.name)}</span>
            <span class="who-text">
              <a
                class="name link row-link"
                href={`#${withReturnTo(`/staff/${staff.id}`, currentFullRoute)}`}
                aria-describedby={showRowMeta ? `staff-meta-${staff.id}` : undefined}
              >{staff.name}</a>
              <span class="login">{staff.login}</span>
              {#if showRowMeta}
                <span id={`staff-meta-${staff.id}`} class="row-meta">
                  {#each staffMetaFields(staff, hiddenFields) as field (field.label)}
                    <span><span class="muted">{field.label}:</span> {field.value}</span>
                  {/each}
                </span>
              {/if}
            </span>
          </div>

          {#each columns as field (field.key)}
            <span class={field.cellClass} role="cell">{field.value(staff)}</span>
          {/each}

          <span role="cell">
            <span class="state" class:on={staff.active}>{staffStateTitle(staff.active)}</span>
          </span>
        </div>
      {/each}

    </div>
  {/if}

  {#if !loading && items.length > 0}
    <Pager
      {page}
      {perPage}
      {total}
      {loading}
      onchange={goToPage}
      onperpage={changePerPage}
    />
  {/if}
  {/if}
</section>

<SortDialog
  open={sortOpen}
  fields={tab === 'staff' ? sortFields : substitutionSortFields}
  sort={tab === 'staff' ? filters.sort : substSort}
  defaultValue={tab === 'staff' ? filterDefaults.sort : 'date_desc'}
  onclose={() => (sortOpen = false)}
  onapply={applySort}
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
            name="staff-view"
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
            name="staff-view"
            value="tiles"
            checked={viewDraft.view === 'tiles'}
            onchange={() => setViewModeNow('tiles')}
          />
          <Icon name="tiles" size={18} />
          <span>Плитки</span>
        </label>
      </div>
    </fieldset>

    {#if tab === 'staff'}
      <fieldset class="filter-group">
        <legend class="filter-label">Поля в списке и карточках</legend>
        <div class="view-field-table">
          <span></span>
          <span class="view-field-head">Список</span>
          <span class="view-field-head">Плитки</span>
          {#each STAFF_FIELDS as field (field.key)}
            <span class="view-field-name">
              {field.label}{#if field.required}<span class="view-field-required"> •</span>{/if}
            </span>
            <label class="view-field-check" title={field.required ? 'Обязательное поле' : undefined}>
              <input
                type="checkbox"
                checked={isStaffFieldVisible(field.key, 'list')}
                disabled={field.required}
                aria-label={`${field.label}: список`}
                onchange={() => toggleViewField(field.key, 'list')}
              />
            </label>
            <label class="view-field-check" title={field.required ? 'Обязательное поле' : undefined}>
              <input
                type="checkbox"
                checked={isStaffFieldVisible(field.key, 'tiles')}
                disabled={field.required}
                aria-label={`${field.label}: плитки`}
                onchange={() => toggleViewField(field.key, 'tiles')}
              />
            </label>
          {/each}
        </div>
        <p class="hint">Обязательные поля (•) отключить нельзя — настройки видов независимы.</p>
      </fieldset>
    {/if}

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
  count={tab === 'staff' ? countActive(filters, filterCountDefaults) : countActive(substFilters, substFilterDefaults)}
  onapply={applyFilterDraft}
  onreset={resetFilterDraft}
>
  {#if tab === 'staff'}
    <div class="filter-group">
      <span class="filter-label">Роль</span>
      <MultiSelect label="Роль" options={roleOptions} bind:values={draft.levels} allLabel="Все роли" size="lg" />
    </div>

    <div class="filter-group">
      <span class="filter-label">Статусы</span>
      <MultiSelect label="Статусы" options={stateOptions} bind:values={draft.states} allLabel="Все статусы" size="lg" />
    </div>

    <div class="filter-fields">
      <label class="filter-field">
        <span>Город</span>
        <MultiSelect label="Город" options={cityOptions} bind:values={draft.cities} allLabel="Все города" emptyLabel="Нет городов" maxHeight="280px" size="lg" />
      </label>
      <label class="filter-field">
        <span>Отдел</span>
        <MultiSelect label="Отдел" options={departmentOptions} bind:values={draft.departments} allLabel="Все отделы" emptyLabel="Нет отделов" maxHeight="260px" size="lg" />
      </label>
      <label class="filter-field">
        <span>Офис</span>
        <MultiSelect label="Офис" options={officeOptions} bind:values={draft.offices} allLabel="Все офисы" emptyLabel="Нет офисов" maxHeight="260px" size="lg" />
      </label>
      <label class="filter-field">
        <span>Должность</span>
        <MultiSelect label="Должность" options={positionOptions} bind:values={draft.positions} allLabel="Все должности" emptyLabel="Нет должностей" maxHeight="260px" size="lg" />
      </label>
    </div>
  {:else}
    <div class="filter-fields">
      <label class="filter-field">
        <span>Период с</span>
        <input type="date" bind:value={substDraft.periodFrom} max={substDraft.periodTo || undefined} />
      </label>
      <label class="filter-field">
        <span>Период по</span>
        <input type="date" bind:value={substDraft.periodTo} min={substDraft.periodFrom || undefined} />
      </label>
    </div>

    <div class="filter-group">
      <span class="filter-label">Статусы</span>
      <MultiSelect label="Статусы" options={substitutionStatusOptions} bind:values={substDraft.statuses} allLabel="Все статусы" size="lg" />
    </div>

    <div class="filter-group">
      <span class="filter-label">Кто отсутствует</span>
      <MultiSelect label="Кто отсутствует" options={substManagerOptions} bind:values={substDraft.managerIds} allLabel="Все сотрудники" emptyLabel="Нет сотрудников" maxHeight="280px" size="lg" />
    </div>

    <div class="filter-group">
      <span class="filter-label">Заместитель</span>
      <MultiSelect label="Заместитель" options={substManagerOptions} bind:values={substDraft.substituteIds} allLabel="Все заместители" emptyLabel="Нет сотрудников" maxHeight="280px" size="lg" />
    </div>
  {/if}
</FiltersModal>


<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
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
    font-size: var(--font-md);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .notify-text {
    flex: 1;
  }

  .notify-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    margin: -2px -4px -2px 0;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .notify-close:hover {
    color: var(--text);
  }

  .list {
    overflow: hidden;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .row {
    display: grid;
    grid-template-columns: var(--st-columns, minmax(220px, 1.5fr) 90px);
    gap: var(--space-3);
    align-items: center;
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
    color: var(--text);
    text-decoration: none;
  }

  .row:last-child {
    border-bottom: none;
  }

  .row:not(.row-head):hover {
    background: var(--fill-hover);
  }

  .row-head {
    padding-top: 10px;
    padding-bottom: 10px;
    background: var(--fill-tertiary);
    color: var(--muted);
    font-size: var(--font-xs);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.02em;
  }

  .who {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
  }

  .avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-size: var(--font-sm);
    font-weight: 600;
  }

  .who-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .row-link {
    color: var(--text);
    text-decoration: none;
  }

  .row-link:hover {
    color: var(--primary);
  }

  .row-link:focus-visible {
    outline: 2px solid var(--focus-soft);
    outline-offset: -2px;
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

  .row-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1) var(--space-3);
    font-size: var(--font-xs);
    font-weight: 400;
    white-space: normal;
  }

  .name {
    overflow: hidden;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .login,
  .muted {
    overflow: hidden;
    color: var(--muted);
    font-size: var(--font-xs);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .role {
    font-size: var(--font-sm);
  }

  .contacts {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    font-size: var(--font-sm);
  }

  .seen {
    color: var(--muted);
    font-size: var(--font-sm);
  }

  .state {
    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border: 1px solid var(--border);
    border-radius: var(--radius-pill);
    color: var(--muted);
    font-size: var(--font-xs);
    white-space: nowrap;
  }

  .state.on {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success-text);
  }

  .tiles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
    gap: var(--space-3);
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;

  }

  .tile:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
  }

  .tile-head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
    margin-bottom: var(--space-1);
  }

  .tile-title {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .tile-row {
    display: flex;
    justify-content: space-between;
    gap: var(--space-2);
    font-size: var(--font-sm);
  }

  .tile-row span {
    color: var(--muted);
  }

  .tile-row strong {
    overflow: hidden;
    font-weight: 500;
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tile-footer {
    margin-top: var(--space-1);
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
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

  @media (max-width: 720px) {

    .list {
      border: none;
      background: transparent;
      box-shadow: none;
    }

    .row-head {
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

    .row {
      grid-template-columns: 1fr;
      gap: var(--space-2);
      margin-bottom: var(--space-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      background: var(--surface);
    }

    .row > :last-child {
      display: inline-flex;
    }

    .seen::before {
      content: 'Последний вход: ';
      color: var(--muted);
    }

    .role::before {
      content: 'Роль: ';
      color: var(--muted);
    }

    .notify-close {
      width: 32px;
      height: 32px;
    }
  }
</style>
