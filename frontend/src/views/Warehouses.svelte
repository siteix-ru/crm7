<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    listWarehouseDirectory,
    type WarehouseDirectoryItem,
    type WarehouseType
  } from '../lib/api/stocks';
  import { config } from '../lib/config';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';

  const canCreateWarehouse = $derived(auth.can('stocks.warehouses.create'));

  const typeTitles: Record<WarehouseType, string> = {
    main: 'Основной',
    transit: 'Транзитный',
    returns: 'Возвраты',
    reserve: 'Резерв',
    defect: 'Брак'
  };

  function typeTitle(value: WarehouseType): string {
    return typeTitles[value] ?? value;
  }

  const sortFields = [
    { field: 'name', label: 'Название' },
    { field: 'address', label: 'Адрес' },
    { field: 'positions', label: 'Остаток' },
    { field: 'active', label: 'Статус' }
  ];

  let items = $state<WarehouseDirectoryItem[]>([]);
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let message = $state('');
  let loadSeq = 0;
  const warehouseFilterDefaults = {
    sort: 'name_asc',
    view: 'list',
    per_page: 20,
    query: '',
    page: 1,
    active: 'all'
  };
  const initialWarehouseFilters = loadFilters('warehouses', warehouseFilterDefaults);

  const initialStatus = String(initialWarehouseFilters.active ?? 'all');
  let statusActive = $state(initialStatus !== 'inactive');
  let statusInactive = $state(initialStatus !== 'active');
  const activeFilter = $derived(
    statusActive && !statusInactive ? 'active' : statusInactive && !statusActive ? 'inactive' : 'all'
  );
  const statusOptions = [
    { value: 'active', label: 'Активированные' },
    { value: 'inactive', label: 'Деактивированные' }
  ];
  let statusDraft = $state<string[]>([]);

  let query = $state(String(initialWarehouseFilters.query));
  let searchInput = $state(String(initialWarehouseFilters.query));
  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  $effect(() => {
    const term = searchInput.trim();
    clearTimeout(searchTimer);

    if (term === query) {
      return;
    }

    searchTimer = setTimeout(() => {
      query = term;
      page = 1;
      addHistory('warehouses', term);
      persist();
    }, 400);

    return () => clearTimeout(searchTimer);
  });

  let viewMode = $state<'list' | 'tiles'>(initialWarehouseFilters.view === 'tiles' ? 'tiles' : 'list');
  let sort = $state<string>(initialWarehouseFilters.sort);
  let page = $state<number>(typeof initialWarehouseFilters.page === 'number' ? initialWarehouseFilters.page : 1);
  let perPage = $state<number>(
    [10, 20, 50, 100].includes(Number(initialWarehouseFilters.per_page)) ||
      Number(initialWarehouseFilters.per_page) === 0
      ? Number(initialWarehouseFilters.per_page)
      : 20
  );

  let sortOpen = $state(false);
  let sortDraft = $state<string[]>(['name_asc']);
  let dragSortField = $state<string | null>(null);
  let dragSortOver = $state<string | null>(null);
  const sortRows = $derived.by(() => {
    const activeRows = sortDraft
      .map((term) => sortFields.find((item) => term.startsWith(`${item.field}_`)))
      .filter((item): item is (typeof sortFields)[number] => item !== undefined);
    const inactiveRows = sortFields.filter((item) => sortDraftIndex(item.field) === 0);

    return [...activeRows, ...inactiveRows];
  });
  let viewOpen = $state(false);
  let viewDraft = $state<'list' | 'tiles'>('list');
  let helpOpen = $state(false);

  const filtered = $derived.by(() => {
    const term = query.trim().toLowerCase();

    return items.filter((item) => {
      if (activeFilter === 'active' && !item.active) {
        return false;
      }

      if (activeFilter === 'inactive' && item.active) {
        return false;
      }

      if (term === '') {
        return true;
      }

      return `${item.name} ${item.address ?? ''}`.toLowerCase().includes(term);
    });
  });

  const sorted = $derived.by(() => {
    const terms = sort.split(',').filter((term) => term !== '');

    return [...filtered].sort((a, b) => {
      for (const term of terms) {
        const [field, dir] = term.split('_');
        let result = 0;

        switch (field) {
          case 'name':
            result = a.name.localeCompare(b.name, 'ru');
            break;
          case 'address':
            result = (a.address ?? '').localeCompare(b.address ?? '', 'ru');
            break;
          case 'positions':
            result = a.positions - b.positions;
            break;
          case 'users':
            result = a.users - b.users;
            break;
          case 'active':
            result = Number(a.active) - Number(b.active);
            break;
        }

        if (result !== 0) {
          return dir === 'desc' ? -result : result;
        }
      }

      return 0;
    });
  });

  const pages = $derived(perPage <= 0 ? 1 : Math.max(1, Math.ceil(sorted.length / perPage)));
  const paged = $derived(
    perPage <= 0 ? sorted : sorted.slice((page - 1) * perPage, (page - 1) * perPage + perPage)
  );

  $effect(() => {
    if (!loading && page > pages) {
      page = pages;
      persist();
    }
  });

  onMount(() => {
    void load();

    const timer = setInterval(() => void load(true), config.listPollMs);

    return () => clearInterval(timer);
  });

  async function load(silent = false): Promise<void> {
    const seq = ++loadSeq;

    if (!silent) {
      loading = true;
      error = '';
    }

    try {
      const data = (await listWarehouseDirectory()).items;

      if (seq !== loadSeq) {
        return;
      }

      items = data;

      if (!silent) {
        error = '';
      }
    } catch (cause) {
      if (seq === loadSeq && !silent) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склады';
      }
    } finally {
      if (seq === loadSeq && !silent) {
        loading = false;
      }
    }
  }

  function persist(): void {
    saveFilters('warehouses', {
      sort,
      view: viewMode,
      per_page: perPage,
      query,
      page,
      active: activeFilter
    });
  }

  function sortTerm(field: string): 'asc' | 'desc' | null {
    const terms = sort.split(',');

    if (terms.includes(`${field}_asc`)) {
      return 'asc';
    }

    if (terms.includes(`${field}_desc`)) {
      return 'desc';
    }

    return null;
  }

  function sortIndex(field: string): number {
    return sort.split(',').findIndex((term) => term.startsWith(`${field}_`)) + 1;
  }

  function sortDraftIndex(field: string): number {
    return sortDraft.findIndex((term) => term.startsWith(`${field}_`)) + 1;
  }

  function moveSortDraft(fromField: string, toField: string): void {
    const fromIndex = sortDraft.findIndex((term) => term.startsWith(`${fromField}_`));
    const toIndex = sortDraft.findIndex((term) => term.startsWith(`${toField}_`));

    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
      return;
    }

    const next = [...sortDraft];
    const [moved] = next.splice(fromIndex, 1);
    const insertAt = fromIndex < toIndex ? toIndex - 1 : toIndex;
    next.splice(insertAt, 0, moved);
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
    if (dragSortField === null || sortDraftIndex(field) === 0) {
      return;
    }

    event.preventDefault();
    dragSortOver = field;
  }

  function dropSortRow(event: DragEvent, field: string): void {
    event.preventDefault();

    if (dragSortField !== null && sortDraftIndex(field) > 0) {
      moveSortDraft(dragSortField, field);
    }

    dragSortField = null;
    dragSortOver = null;
  }

  function endSortDrag(): void {
    dragSortField = null;
    dragSortOver = null;
  }

  function headerSort(field: string): void {
    const terms = sort.split(',').filter((term) => term !== '');
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

    sort = next.length > 0 ? next.join(',') : 'name_asc';
    page = 1;
    persist();
  }

  function openSort(): void {
    sortDraft = sort.split(',').filter((term) => term !== '');
    sortOpen = true;
  }

  function applySort(): void {
    sortOpen = false;
    sort = sortDraft.length > 0 ? sortDraft.join(',') : 'name_asc';
    page = 1;
    persist();
  }

  function toggleSortDir(field: string, dir: 'asc' | 'desc'): void {
    const term = `${field}_${dir}`;

    sortDraft = sortDraft.includes(term)
      ? sortDraft.filter((item) => item !== term)
      : [...sortDraft.filter((item) => !item.startsWith(`${field}_`)), term];
  }

  function openView(): void {
    viewDraft = viewMode;
    statusDraft = [
      ...(statusActive ? ['active'] : []),
      ...(statusInactive ? ['inactive'] : [])
    ];
    viewOpen = true;
  }

  function resetView(): void {
    viewDraft = 'list';
    statusDraft = ['active', 'inactive'];
  }

  function applyView(): void {
    const nextStatusActive = statusDraft.length === 0 || statusDraft.includes('active');
    const nextStatusInactive = statusDraft.length === 0 || statusDraft.includes('inactive');
    const statusChanged =
      nextStatusActive !== statusActive || nextStatusInactive !== statusInactive;

    viewMode = viewDraft;
    viewOpen = false;

    if (statusChanged) {
      statusActive = nextStatusActive;
      statusInactive = nextStatusInactive;
      page = 1;
    }

    persist();
  }

  function goToPage(next: number): void {
    if (!Number.isFinite(next)) {
      return;
    }

    page = Math.max(1, Math.trunc(next));
    persist();
  }

  function changePerPage(): void {
    page = 1;
    persist();
  }

  function applySearch(): void {
    query = searchInput.trim();
    page = 1;
    addHistory('warehouses', query);
    persist();
  }

</script>

<section class="page">
  {#if error}
    <div class="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  <div class="page-hero">
  <div class="filters">
    <div class="head">
      <button
        type="button"
        class="back"
        title="Назад к номенклатуре"
        aria-label="Назад к номенклатуре"
        onclick={() => router.navigate('/stocks')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
      <h1>Управление складами</h1>
    </div>

    {#if !loading}
      <form
        class="search"
        onsubmit={(event) => {
          event.preventDefault();
          applySearch();
        }}
      >
        <SearchInput
          bind:value={searchInput}
          historyKey="warehouses"
          placeholder="Поиск склада"
          onclear={applySearch}
          onpick={applySearch}
        />
      </form>

      <div class="filter-icons">
        <button
          type="button"
          class="sort-button"
          class:active={sort !== 'name_asc'}
          title="Сортировка"
          aria-label="Сортировка"
          onclick={openSort}
        >
          <Icon name="sort" size={16} />
        </button>

        <button
          type="button"
          class="sort-button"
          class:active={viewMode === 'tiles'}
          title="Вид"
          aria-label="Вид: списком или плитками"
          onclick={openView}
        >
          <Icon name="view" size={16} />
        </button>

        {#if canCreateWarehouse}
        <span class="toolbar-divider" aria-hidden="true"></span>

        <button
          type="button"
          class="sort-button"
          title="Добавить склад"
          aria-label="Добавить склад"
          onclick={() => router.navigate('/stocks/warehouses/new')}
        >
          <span class="create-warehouse-icon">
            <Icon name="warehouse" size={16} />
            <span class="create-warehouse-plus" aria-hidden="true">
              <Icon name="plus" size={10} />
            </span>
          </span>
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
    {/if}
  </div>
  </div>

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else}
    {#if sorted.length === 0}
      <div class="empty">Склады не найдены</div>
    {:else}
      <div class="levels-wrap">
        {#if viewMode === 'list'}
          <div class="levels-head">
            {#each sortFields as column (column.field)}
              <button
                type="button"
                class="levels-head-cell col-{column.field}"
                onclick={() => headerSort(column.field)}
              >
                <span class="levels-head-label">{column.label}</span>
                {#if sortIndex(column.field) > 0}
                  <span class="sort-badge">
                    <Icon name={sortTerm(column.field) === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                    <span class="sort-num">{sortIndex(column.field)}</span>
                  </span>
                {/if}
              </button>
            {/each}
          </div>
        {/if}

        <div class="levels" class:tiles={viewMode === 'tiles'}>
          {#each paged as item (item.id)}
            <!-- svelte-ignore a11y_click_events_have_key_events -->
            <!-- svelte-ignore a11y_no_static_element_interactions -->
            <div class="level" onclick={() => router.navigate(`/stocks/warehouses/${item.id}/edit`)}>
              <div class="name-col col-name">
                <div class="name-line">
                  <a
                    class="name"
                    href={`#/stocks/warehouses/${item.id}/edit`}
                    title="Открыть склад"
                    onclick={(event) => event.stopPropagation()}
                  >
                    {item.name}
                  </a>
                </div>
                <div class="meta">
                  {#if item.type !== 'main'}<span>{typeTitle(item.type)}</span>{/if}
                  {#if item.responsible_name}<span>Ответственный: {item.responsible_name}</span>{/if}
                </div>
              </div>

              <div class="level-bottom">
                <div class="stat col-address">
                  <span class="stat-label">Адрес</span>
                  <span class="value">{item.address ?? '—'}</span>
                </div>
                <div class="stat col-positions">
                  <span class="stat-label">Номенклатуры</span>
                  <span class="value">{item.positions}</span>
                </div>
                <div class="stat col-active">
                  <span class="stat-label">Статус</span>
                  <span class="tag" class:on={item.active}>{item.active ? 'активен' : 'скрыт'}</span>
                </div>
              </div>
            </div>
          {/each}
        </div>
      </div>

      <Pager
        {page}
        {perPage}
        total={sorted.length}
        loading={loading}
        onchange={goToPage}
        onperpage={(value) => {
          perPage = value;
          changePerPage();
        }}
      />
    {/if}
  {/if}
</section>

<Modal open={sortOpen} title="Сортировка" onclose={() => (sortOpen = false)}>
  <p class="sort-hint">
    Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку, чтобы добавить
    условие, и повторно — чтобы убрать.
  </p>
  <div class="sort-list" role="list">
    {#each sortRows as column (column.field)}
      {@const active = sortDraftIndex(column.field) > 0}
      <div
        class="sort-row"
        class:active
        class:drag-over={dragSortOver === column.field && dragSortField !== column.field}
        role="listitem"
        ondragover={(event) => overSortRow(event, column.field)}
        ondragleave={() => {
          if (dragSortOver === column.field) {
            dragSortOver = null;
          }
        }}
        ondrop={(event) => dropSortRow(event, column.field)}
      >
        <span
          class="sort-drag"
          class:off={!active}
          draggable={active}
          title={active ? 'Перетащите, чтобы изменить порядок' : undefined}
          aria-hidden="true"
          ondragstart={(event) => startSortDrag(event, column.field)}
          ondragend={endSortDrag}
        >
          <Icon name="holder" size={14} />
        </span>
        {#if active}
          <span class="sort-order">{sortDraftIndex(column.field)}</span>
        {/if}
        <span class="sort-row-label">{column.label}</span>
        <button
          type="button"
          class="dir-btn"
          class:on={sortDraft.includes(`${column.field}_asc`)}
          title="По возрастанию"
          aria-label={`${column.label}: по возрастанию`}
          onclick={() => toggleSortDir(column.field, 'asc')}
        >
          <Icon name="arrow-up" size={16} />
        </button>
        <button
          type="button"
          class="dir-btn"
          class:on={sortDraft.includes(`${column.field}_desc`)}
          title="По убыванию"
          aria-label={`${column.label}: по убыванию`}
          onclick={() => toggleSortDir(column.field, 'desc')}
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

<Modal open={viewOpen} title="Вид и фильтры" onclose={() => (viewOpen = false)}>
  <div class="filters-form view-modal">
      <div class="view-filters-body">
              <div class="filter-group view-mode-group">
          <span class="filter-label">Вид</span>
          <div class="view-mode-grid">
            <label class="view-mode-card" class:active={viewDraft === 'list'}>
              <input type="radio" name="wh-view" value="list" bind:group={viewDraft} />
              <Icon name="list" size={18} />
              <span>Список</span>
            </label>
            <label class="view-mode-card" class:active={viewDraft === 'tiles'}>
              <input type="radio" name="wh-view" value="tiles" bind:group={viewDraft} />
              <Icon name="tiles" size={18} />
              <span>Плитки</span>
            </label>
          </div>
        </div>

    <div class="filter-group">
      <span class="filter-label" title="Если ничего не выбрано — показываются все">Статусы</span>
      <MultiSelect
        label="Статусы"
        options={statusOptions}
        bind:values={statusDraft}
        allLabel="Все статусы"
      />
    </div>

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

<Modal open={helpOpen} title="Как пользоваться" onclose={() => (helpOpen = false)} wide>
  <div class="help">
    <p>
      Здесь собраны склады системы. Раздел доступен сотрудникам с правами на импорт из 1С и
      редактирование номенклатуры.
    </p>
    <div class="help-block">
      <h3>Список и плитки</h3>
      <p>
        Кнопка «Вид и фильтры» переключает таблицу и плитки и задаёт показ активированных и
        деактивированных складов. В таблице клик по заголовку колонки сортирует, повторный клик
        меняет направление; кнопка «Сортировка» задаёт несколько условий.
      </p>
    </div>
    <div class="help-block">
      <h3>Карточка склада</h3>
      <p>
        Клик по складу открывает карточку: название, адрес, активность. На вкладке «Доступ» —
        список пользователей с персональным доступом к складу.
      </p>
    </div>
    <div class="help-block">
      <h3>Импорт</h3>
      <p>
        Новые склады из файла 1С добавляются автоматически. Скрытый (неактивный) склад не виден
        пользователям, но импорт на него идёт и остатки обновляются.
      </p>
    </div>
  </div>
</Modal>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .page:has(.levels.tiles) {
    max-width: 1600px;
  }

  .head {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: var(--space-2);
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

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  h3 {
    margin: 0 0 4px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
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

  .create-warehouse-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .create-warehouse-plus {
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

  .levels-wrap {
    display: flex;
    flex-direction: column;
  }

  .levels {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
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

  .name-col {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .name-line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
  }

  .name {
    font-weight: 600;
    color: var(--text);
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .level:hover .name {
    color: var(--primary);
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--text-description);
  }

  .level-bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 6px var(--space-3);
    min-width: 0;
  }

  .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .stat.col-positions {
    align-items: flex-end;
    text-align: right;
  }

  .stat-label {
    font-size: 12px;
    color: var(--text-description);
  }

  .value {
    color: var(--text);
  }

  .tag {
    align-self: flex-start;
    font-size: 12px;
    line-height: 20px;
    padding: 0 7px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .tag.on {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success-text);
  }

  .levels-head {
    display: none;
  }

  .levels-head-cell {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .levels-head-cell:hover {
    color: var(--primary);
  }

  .levels-head-label {
    min-width: 0;
    line-height: 1.25;
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
      z-index: 2;
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.2fr) 140px 110px;
      align-items: stretch;
      padding: 0;
      background: var(--fill-tertiary);
      border: 1px solid var(--border-secondary);
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

    .levels-head .col-positions {
      justify-content: flex-end;
    }

    .levels-head .col-positions .sort-badge {
      order: -1;
    }

    .levels:not(.tiles) {
      border-top: none;
      border-radius: 0 0 var(--radius-md) var(--radius-md);
    }

    .levels:not(.tiles) .level {
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(0, 1.2fr) 140px 110px;
      align-items: center;
      padding: 0;
    }

    .levels:not(.tiles) .level > .name-col,
    .levels:not(.tiles) .level > .level-bottom > .stat {
      padding: 12px var(--space-4);
    }

    .levels:not(.tiles) .level-bottom {
      display: contents;
    }

    .levels:not(.tiles) .stat-label {
      display: none;
    }

    .levels:not(.tiles) .meta {
      display: none;
    }

    .levels:not(.tiles) .col-positions {
      align-items: flex-end;
      text-align: right;
    }
  }

  @media (max-width: 719.98px) {
    .levels:not(.tiles) .level {
      flex-wrap: wrap;
      row-gap: var(--space-2);
    }

    .levels:not(.tiles) .name-col {
      flex: 1 1 100%;
    }

    .levels:not(.tiles) .level-bottom {
      flex: 1 1 100%;
      justify-content: flex-start;
    }
  }

  .levels.tiles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: var(--space-4);
    background: none;
    border: none;
    border-radius: 0;
    overflow: visible;
  }

  .levels.tiles .level {
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    gap: var(--space-2);
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .levels.tiles .level:hover {
    box-shadow: var(--shadow-md);
  }

  .levels.tiles .level:last-child {
    border-bottom: 1px solid var(--border-secondary);
  }

  .levels.tiles .level-bottom {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--space-2) var(--space-3);
  }

  .levels.tiles .col-address {
    grid-column: 1 / -1;
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

  .sort-row.active .sort-row-label {
    color: var(--text);
    font-weight: 600;
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

  .checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .help {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    font-size: 14px;
    color: var(--text);
  }

  .help p {
    margin: 0;
    color: var(--text-description);
  }

  .help-block {
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--fill-tertiary);
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
