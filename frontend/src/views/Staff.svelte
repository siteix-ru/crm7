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
  import { formatDateTime } from '../lib/format';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
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
    view: 'list'
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

  let items = $state<StaffUser[]>([]);
  let total = $state(0);
  let page = $state(1);
  let perPage = $state(20);
  let loading = $state(true);
  let error = $state('');
  let search = $state('');
  let searchInput = $state('');
  let filters = $state({ ...initialFilters });
  let draft = $state({
    levels: [...initialFilters.levels],
    states: [...initialFilters.states],
    cities: [...initialFilters.cities],
    departments: [...initialFilters.departments],
    offices: [...initialFilters.offices],
    positions: [...initialFilters.positions],
    view: initialFilters.view
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
  let viewMode = $state<'list' | 'tiles'>(initialFilters.view === 'tiles' ? 'tiles' : 'list');
  let sortOpen = $state(false);
  let viewOpen = $state(false);
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

  async function load(): Promise<void> {
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
        perPage
      });
      items = data.items;
      total = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сотрудников';
    } finally {
      loading = false;
    }
  }

  function submitSearch(): void {
    if (tab === 'substitutions') {
      substSearch = substSearchInput.trim();
      return;
    }

    search = searchInput.trim();

    if (search !== '') {
      addHistory('staff', search);
    }

    page = 1;
    void load();
  }

  function applySearch(): void {
    if (tab === 'substitutions') {
      substSearch = '';
      substSearchInput = '';
      return;
    }

    page = 1;
    void load();
  }

  function goToPage(next: number): void {
    page = next;
    void load();
  }

  function changePerPage(value: number): void {
    perPage = value;
    page = 1;
    void load();
  }

  function openView(): void {
    if (tab === 'substitutions') {
      substDraft = cloneSubstFilters(substFilters);
      draft = {
        levels: [],
        states: [],
        cities: [],
        departments: [],
        offices: [],
        positions: [],
        view: substView
      };
    } else {
      draft = {
        levels: [...filters.levels],
        states: [...filters.states],
        cities: [...filters.cities],
        departments: [...filters.departments],
        offices: [...filters.offices],
        positions: [...filters.positions],
        view: viewMode
      };
    }

    viewOpen = true;
  }



  function applyView(): void {
    if (tab === 'substitutions') {
      substView = draft.view === 'tiles' ? 'tiles' : 'list';
      substFilters = cloneSubstFilters(substDraft);
      viewOpen = false;
      return;
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
    viewMode = draft.view === 'tiles' ? 'tiles' : 'list';
    filters = { ...filters, view: viewMode };
    saveFilters('staff', filters);
    viewOpen = false;
    page = 1;
    void load();
  }

  function resetView(): void {
    if (tab === 'substitutions') {
      substDraft = cloneSubstFilters();
      draft = { ...draft, view: 'list' };
      return;
    }

    draft = {
      levels: [],
      states: [],
      cities: [],
      departments: [],
      offices: [],
      positions: [],
      view: 'list'
    };
  }

  function resetFilters(): void {
    filters = { ...filterDefaults };
    viewMode = 'list';
    saveFilters('staff', filters);
    page = 1;
    void load();
  }

  function setSort(next: string): void {
    if (next === filters.sort) {
      return;
    }

    filters = { ...filters, sort: next };
    saveFilters('staff', filters);
    page = 1;
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
  <div class="page-hero">
  <div class="filters">
    <div class="head">
      {#if tab === 'substitutions'}
        <button
          type="button"
          class="back"
          title="К списку сотрудников"
          aria-label="К списку сотрудников"
          onclick={() => router.navigate('/staff')}
        >
          <Icon name="arrow-left" size={16} />
        </button>
      {/if}
      <h1>{tab === 'staff' ? 'Сотрудники' : 'Замещения'}</h1>
    </div>

    <form
      class="search"
      onsubmit={(event) => {
        event.preventDefault();
        submitSearch();
      }}
    >
      {#if tab === 'staff'}
        <SearchInput
          bind:value={searchInput}
          historyKey="staff"
          placeholder="Поиск: имя, логин, телефон, e-mail, отдел, город, офис"
          onclear={() => {
            search = '';
            applySearch();
          }}
          onpick={submitSearch}
        />
      {:else}
        <SearchInput
          bind:value={substSearchInput}
          historyKey="substitutions"
          placeholder="Поиск: кто, заместитель, причина, даты"
          onclear={applySearch}
          onpick={submitSearch}
        />
      {/if}
    </form>

    <div class="filter-icons">
      <button
        type="button"
        class="sort-button"
        class:active={tab === 'staff' ? sortActive : substSortActive}
        title="Сортировка"
        aria-label="Сортировка"
        onclick={() => (sortOpen = true)}
      >
        <Icon name="sort" size={16} />
      </button>

      <button
        type="button"
        class="sort-button"
        class:active={tab === 'staff'
          ? viewMode === 'tiles' || filtersActive
          : substView === 'tiles' || substFiltersActive}
        title="Вид и фильтры"
        aria-label="Вид и фильтры"
        onclick={openView}
      >
        <Icon name="view" size={16} />
        {#if (tab === 'staff' && filtersActive) || (tab === 'substitutions' && substFiltersActive)}
          <span class="icon-badge" aria-hidden="true"></span>
        {/if}
      </button>

      <span class="toolbar-divider" aria-hidden="true"></span>

      {#if showSubstitutionsTab && tab === 'staff'}
        <button
          type="button"
          class="sort-button"
          title="Замещения"
          aria-label="Замещения"
          onclick={() => router.navigate('/substitutions')}
        >
          <Icon name="substitutions" size={16} />
        </button>
      {/if}

      {#if tab === 'staff' ? canCreate : canAddSubstitution}
        <span class="toolbar-divider" aria-hidden="true"></span>

        <button
          type="button"
          class="create-button"
          title={tab === 'staff' ? 'Новый сотрудник' : 'Новое замещение'}
          aria-label={tab === 'staff' ? 'Новый сотрудник' : 'Новое замещение'}
          onclick={() =>
            router.navigate(tab === 'staff' ? '/staff/new' : '/staff/substitutions/new')}
        >
          <Icon name="plus" size={14} />
          {tab === 'staff' ? 'Новый сотрудник' : 'Новое замещение'}
        </button>
      {/if}
    </div>
  </div>
  </div>

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
        <button type="button" class="tile" onclick={() => openStaff(staff)}>
          <div class="tile-head">
            <span class="avatar">{initials(staff.name)}</span>
            <div class="tile-title">
              <span class="name">{staff.name}</span>
              <span class="login">{staff.login}</span>
            </div>
          </div>
          <div class="tile-row"><span>Роль</span><strong>{staffLevelTitle(staff.level)}</strong></div>
          <div class="tile-row"><span>Телефон</span><strong>{staff.phone || '—'}</strong></div>
          <div class="tile-row"><span>E-mail</span><strong>{staff.email || '—'}</strong></div>
          {#if staff.department || staff.city || staff.office}
            <div class="tile-row">
              <span>Организация</span>
              <strong>{[staff.department, staff.city, staff.office].filter(Boolean).join(' · ')}</strong>
            </div>
          {/if}
          <div class="tile-row">
            <span>Последний вход</span>
            <strong>{formatDateTime(staff.last_seen_at)}</strong>
          </div>
          <div class="tile-footer">
            <span class="state" class:on={staff.active}>{staffStateTitle(staff.active)}</span>
          </div>
        </button>
      {/each}
    </div>
  {:else}
    <div class="list">
      <div class="row row-head" aria-hidden="true">
        <span>Сотрудник</span>
        <span>Роль</span>
        <span>Контакты</span>
        <span>Последний вход</span>
        <span>Статус</span>
      </div>

      {#each items as staff (staff.id)}
        <a class="row" href={`#/staff/${staff.id}`} onclick={(event) => { event.preventDefault(); openStaff(staff); }}>
          <span class="who">
            <span class="avatar">{initials(staff.name)}</span>
            <span class="who-text">
              <span class="name">{staff.name}</span>
              <span class="login">{staff.login}</span>
            </span>
          </span>
          <span class="role">{staffLevelTitle(staff.level)}</span>
          <span class="contacts">
            <span>{staff.phone || 'Телефон не указан'}</span>
            <span class="muted">{staff.email || 'E-mail не указан'}</span>
            {#if staff.department || staff.city || staff.office}
              <span class="muted">
                {[staff.department, staff.city, staff.office].filter(Boolean).join(' · ')}
              </span>
            {/if}
          </span>
          <span class="seen">{formatDateTime(staff.last_seen_at)}</span>
          <span><span class="state" class:on={staff.active}>{staffStateTitle(staff.active)}</span></span>
        </a>
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
  title="Вид и фильтры"
  onclose={() => (viewOpen = false)}
>
  <div class="filters-form view-modal">
      <div class="view-filters-body">
              <div class="filter-group view-mode-group">
          <span class="filter-label">Вид</span>
          <div class="view-mode-grid">
            <label class="view-mode-card" class:active={draft.view === 'list'}>
              <input type="radio" name="staff-view" value="list" bind:group={draft.view} />
              <Icon name="list" size={18} />
              <span>Список</span>
            </label>
            <label class="view-mode-card" class:active={draft.view === 'tiles'}>
              <input type="radio" name="staff-view" value="tiles" bind:group={draft.view} />
              <Icon name="tiles" size={18} />
              <span>Плитки</span>
            </label>
          </div>
        </div>

  {#if tab === 'staff'}
    <div class="filter-group">
      <span class="filter-label">Роль</span>
      <MultiSelect
        label="Роль"
        options={roleOptions}
        bind:values={draft.levels}
        allLabel="Все роли"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Статусы</span>
      <MultiSelect
        label="Статусы"
        options={stateOptions}
        bind:values={draft.states}
        allLabel="Все статусы"
      />
    </div>

    <div class="filter-fields">
      <div class="filter-field">
        <span>Город</span>
        <MultiSelect
          label="Город"
          options={cityOptions}
          bind:values={draft.cities}
          allLabel="Все города"
          emptyLabel="Нет городов"
          maxHeight="280px"
        />
      </div>
      <div class="filter-field">
        <span>Отдел</span>
        <MultiSelect
          label="Отдел"
          options={departmentOptions}
          bind:values={draft.departments}
          allLabel="Все отделы"
          emptyLabel="Нет отделов"
          maxHeight="260px"
        />
      </div>
      <div class="filter-field">
        <span>Офис</span>
        <MultiSelect
          label="Офис"
          options={officeOptions}
          bind:values={draft.offices}
          allLabel="Все офисы"
          emptyLabel="Нет офисов"
          maxHeight="260px"
        />
      </div>
      <div class="filter-field">
        <span>Должность</span>
        <MultiSelect
          label="Должность"
          options={positionOptions}
          bind:values={draft.positions}
          allLabel="Все должности"
          emptyLabel="Нет должностей"
          maxHeight="260px"
        />
      </div>
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
      <MultiSelect
        label="Статусы"
        options={substitutionStatusOptions}
        bind:values={substDraft.statuses}
        allLabel="Все статусы"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Кто отсутствует</span>
      <MultiSelect
        label="Кто отсутствует"
        options={substManagerOptions}
        bind:values={substDraft.managerIds}
        allLabel="Все сотрудники"
        emptyLabel="Нет сотрудников"
        maxHeight="280px"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Заместитель</span>
      <MultiSelect
        label="Заместитель"
        options={substManagerOptions}
        bind:values={substDraft.substituteIds}
        allLabel="Все заместители"
        emptyLabel="Нет сотрудников"
        maxHeight="280px"
      />
    </div>

  {/if}

      </div>

      <div class="modal-actions">
      <Button variant="ghost" onclick={resetView}>Сбросить</Button>
    <div class="modal-buttons">
      <Button variant="ghost" onclick={() => (viewOpen = false)}>Отмена</Button>
      <Button onclick={applyView}>Применить</Button>
    </div>
    </div>
  </div>
</Modal>


<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .filters {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .head {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .head h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex: 0 0 auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
  }

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .search {
    flex: 1 1 320px;
    min-width: 220px;
    max-width: 520px;
  }

  .filter-icons {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
    flex-wrap: wrap;
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

  .sort-button:hover,
  .sort-button.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .toolbar-divider {
    width: 1px;
    height: 24px;
    background: var(--border-secondary);
  }

  .icon-badge {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--primary);
  }

  .create-button {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    height: var(--control-height);
    padding: 0 var(--space-3);
    border: none;
    border-radius: var(--radius-sm);
    background: var(--primary);
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.15s ease;
  }

  .create-button:hover {
    background: var(--primary-hover);
  }

  .create-button:active {
    background: var(--primary-active);
  }

  .create-button:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
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
    grid-template-columns: minmax(220px, 1.4fr) 130px minmax(220px, 1.3fr) 170px 130px;
    gap: var(--space-3);
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-secondary);
    color: var(--text);
    text-decoration: none;
    transition: background 0.12s ease;
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
    font-size: 12px;
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
    font-size: 13px;
    font-weight: 600;
  }

  .who-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
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
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .role {
    font-size: 13px;
  }

  .contacts {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    font-size: 13px;
  }

  .seen {
    color: var(--muted);
    font-size: 13px;
  }

  .state {
    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .state.on {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success-text);
  }

  .tiles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
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
    transition: border-color 0.12s ease, box-shadow 0.12s ease;
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
    font-size: 13px;
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


  @media (max-width: 980px) {
    .row {
      grid-template-columns: minmax(200px, 1.2fr) 110px minmax(180px, 1fr) 150px;
    }

    .row > :last-child {
      display: none;
    }

    .row-head > :last-child {
      display: none;
    }
  }

  @media (max-width: 720px) {
    .toolbar-divider {
      display: none;
    }

    .list {
      border: none;
      background: transparent;
      box-shadow: none;
    }

    .row-head {
      display: none;
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
  }
</style>
