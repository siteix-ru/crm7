<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    listStaff,
    staffLevelTitle,
    staffStateTitle,
    type StaffUser
  } from '../lib/api/staff';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDateTime } from '../lib/format';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  const filterDefaults = {
    level: '',
    state: '',
    sort: 'level_desc',
    view: 'list'
  };

  const filterCountDefaults = {
    level: filterDefaults.level,
    state: filterDefaults.state
  };

  const roleOptions = [
    { value: '90', label: 'Технический администратор' },
    { value: '50', label: 'Администратор' },
    { value: '10', label: 'Менеджер' }
  ];

  const stateOptions = [
    { value: 'active', label: 'Активные' },
    { value: 'blocked', label: 'Заблокированные' }
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
    level: initialFilters.level,
    state: initialFilters.state,
    view: initialFilters.view
  });
  let viewMode = $state<'list' | 'tiles'>(initialFilters.view === 'tiles' ? 'tiles' : 'list');
  let sortOpen = $state(false);
  let viewOpen = $state(false);
  let helpOpen = $state(false);

  const canCreate = $derived(auth.can('users.manage') && !appSettings.demoMode);
  const filtersActive = $derived(countActive(filters, filterCountDefaults) > 0);
  const sortActive = $derived(filters.sort !== filterDefaults.sort);

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listStaff({
        q: search,
        level: filters.level !== '' ? Number(filters.level) : undefined,
        state: filters.state || undefined,
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
    search = searchInput.trim();

    if (search !== '') {
      addHistory('staff', search);
    }

    page = 1;
    void load();
  }

  function applySearch(): void {
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
    draft = { level: filters.level, state: filters.state, view: viewMode };
    viewOpen = true;
  }

  function toggleRole(value: string): void {
    draft = { ...draft, level: draft.level === value ? '' : value };
  }

  function toggleState(value: string): void {
    draft = { ...draft, state: draft.state === value ? '' : value };
  }

  function applyView(): void {
    filters = { ...filters, level: draft.level, state: draft.state };
    viewMode = draft.view === 'tiles' ? 'tiles' : 'list';
    filters = { ...filters, view: viewMode };
    saveFilters('staff', filters);
    viewOpen = false;
    page = 1;
    void load();
  }

  function resetView(): void {
    draft = { level: '', state: '', view: 'list' };
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

<section class="page">
  <div class="filters">
    <div class="head">
      <h1>Сотрудники</h1>
    </div>

    <form
      class="search"
      onsubmit={(event) => {
        event.preventDefault();
        submitSearch();
      }}
    >
      <SearchInput
        bind:value={searchInput}
        historyKey="staff"
        placeholder="Поиск: ФИО, логин, телефон, e-mail, отдел, город, офис"
        onclear={() => {
          search = '';
          applySearch();
        }}
        onpick={submitSearch}
      />
    </form>

    <div class="filter-icons">
      <button
        type="button"
        class="sort-button"
        class:active={sortActive}
        title="Сортировка"
        aria-label="Сортировка"
        onclick={() => (sortOpen = true)}
      >
        <Icon name="sort" size={16} />
      </button>

      <button
        type="button"
        class="sort-button"
        class:active={viewMode === 'tiles' || filtersActive}
        title="Вид и фильтры"
        aria-label="Вид и фильтры"
        onclick={openView}
      >
        <Icon name="view" size={16} />
        {#if filtersActive}
          <span class="icon-badge" aria-hidden="true"></span>
        {/if}
      </button>

      <span class="toolbar-divider" aria-hidden="true"></span>

      {#if canCreate}
        <button
          type="button"
          class="sort-button"
          title="Добавить сотрудника"
          aria-label="Добавить сотрудника"
          onclick={() => router.navigate('/staff/new')}
        >
          <Icon name="plus" size={16} />
        </button>

        <span class="toolbar-divider" aria-hidden="true"></span>
      {/if}

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
  </div>

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
    <div class="center"><Spinner size={26} /></div>
  {:else if items.length === 0}
    <div class="empty">
      <h2>Сотрудники не найдены</h2>
      <p>Измените поиск или сбросьте фильтры.</p>
      <Button variant="ghost" onclick={resetFilters}>Сбросить поиск и фильтры</Button>
    </div>
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
</section>

<SortDialog
  open={sortOpen}
  fields={sortFields}
  sort={filters.sort}
  defaultValue={filterDefaults.sort}
  onclose={() => (sortOpen = false)}
  onapply={applySort}
/>

<Modal open={viewOpen} title="Вид и фильтры" onclose={() => (viewOpen = false)}>
  <div class="view-options">
    <label class="radio">
      <input type="radio" name="staff-view" value="list" bind:group={draft.view} />
      <span>Списком</span>
    </label>
    <label class="radio">
      <input type="radio" name="staff-view" value="tiles" bind:group={draft.view} />
      <span>Плитками</span>
    </label>
  </div>

  <div class="filter-group">
    <span class="filter-label">Роль</span>
    <div class="chips">
      {#each roleOptions as option (option.value)}
        <button
          type="button"
          class="chip"
          class:on={draft.level === option.value}
          aria-pressed={draft.level === option.value}
          onclick={() => toggleRole(option.value)}
        >
          {option.label}
        </button>
      {/each}
    </div>
  </div>

  <div class="filter-group">
    <span class="filter-label">Состояние</span>
    <div class="chips">
      {#each stateOptions as option (option.value)}
        <button
          type="button"
          class="chip"
          class:on={draft.state === option.value}
          aria-pressed={draft.state === option.value}
          onclick={() => toggleState(option.value)}
        >
          {option.label}
        </button>
      {/each}
    </div>
  </div>

  <div class="modal-actions">
    <Button variant="ghost" onclick={resetView}>Сбросить</Button>
    <Button variant="ghost" onclick={() => (viewOpen = false)}>Отмена</Button>
    <Button onclick={applyView}>Применить</Button>
  </div>
</Modal>

<Modal open={helpOpen} title="Как пользоваться" onclose={() => (helpOpen = false)}>
  <p class="help-text">
    Здесь собраны сотрудники: менеджеры, администраторы и технические администраторы. Поиск ищет по ФИО, логину,
    телефону, e-mail, компании и ИНН.
  </p>
  <p class="help-text">
    Нажмите на строку, чтобы открыть карточку. В карточке можно изменить данные, роль, типы цен,
    заблокировать сотрудника или сбросить пароль.
  </p>
  <p class="help-text">
    Добавлять сотрудников может технический администратор. Администратор видит список и может редактировать
    сотрудников, но не назначает роли.
  </p>
  <div class="modal-actions">
    <Button onclick={() => (helpOpen = false)}>Понятно</Button>
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

  .head h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
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
    width: 22px;
    height: 22px;
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

  .center {
    display: flex;
    justify-content: center;
    padding: var(--space-6);
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .empty h2 {
    margin: 0 0 var(--space-2);
    font-size: 16px;
  }

  .empty p {
    margin: 0 0 var(--space-4);
    color: var(--muted);
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

  .view-options {
    display: flex;
    gap: var(--space-4);
    margin-bottom: var(--space-4);
  }

  .radio {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    cursor: pointer;
  }

  .filter-group {
    margin-bottom: var(--space-3);
  }

  .filter-label {
    display: block;
    margin-bottom: var(--space-2);
    color: var(--muted);
    font-size: 13px;
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .chip {
    padding: 5px 11px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-description);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease, background 0.12s ease;
  }

  .chip:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .chip.on {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 10%, var(--surface));
    color: var(--primary);
    font-weight: 500;
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .help-text {
    color: var(--text);
    font-size: 14px;
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
