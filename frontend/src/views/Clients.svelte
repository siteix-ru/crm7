<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { pendingClients } from '../lib/api/clients';
  import { getClientFacets, listClients, listManagers } from '../lib/api/requests';
  import type { ClientItem, ManagerItem, PendingClient } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import { countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDate } from '../lib/format';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';
  import { withReturnTo } from '../lib/navigation-context';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import {
    VIEW_FIELDS,
    OPTIONAL_KEYS,
    normalizeViewFields,
    initialViewMode,
    listColumns,
    tileFields,
    clientMetaFields,
    type ViewMode
  } from '../lib/views/client-fields';

  const filterDefaults = {
    states: [] as string[],
    managers: [] as string[],
    cities: [] as string[],
    from: '',
    to: '',
    sort: 'name_asc',
    view: 'list',
    viewExplicit: false,
    q: '',
    page: 1,
    per_page: 20,
    fields: { list: [...OPTIONAL_KEYS], tiles: [...OPTIONAL_KEYS] }
  };

  const filterCountDefaults = {
    states: filterDefaults.states,
    managers: filterDefaults.managers,
    cities: filterDefaults.cities,
    from: filterDefaults.from,
    to: filterDefaults.to
  };

  const stateOptions = [
    { value: 'active', label: 'Активные' },
    { value: 'pending', label: 'Ожидают подтверждения' },
    { value: 'blocked', label: 'Заблокированные' }
  ];

  const sortRows = [
    { field: 'name', label: 'Название' },
    { field: 'created', label: 'Дата регистрации' },
    { field: 'requests', label: 'Заявки' }
  ];

  let managerItems = $state<ManagerItem[]>([]);
  let clientCities = $state<string[]>([]);

  const managerOptions = $derived([
    ...managerItems.map((manager) => ({ value: String(manager.id), label: manager.name })),
    { value: 'none', label: 'Без менеджера' }
  ]);
  const cityOptions = $derived(clientCities.map((city) => ({ value: city, label: city })));

  const initialFilters = loadFilters('clients', filterDefaults);
  const savedPerPage = [0, 10, 20, 50, 100].includes(Number(initialFilters.per_page))
    ? Number(initialFilters.per_page)
    : 20;

  let clients = $state<ClientItem[]>([]);
  let total = $state(0);
  let page = $state(Math.max(1, Math.trunc(Number(initialFilters.page) || 1)));
  let perPage = $state(savedPerPage);
  let pending = $state<PendingClient[]>([]);
  let pendingExpanded = $state(false);
  let loading = $state(true);
  let error = $state('');
  let search = $state(String(initialFilters.q ?? ''));
  let searchInput = $state(String(initialFilters.q ?? ''));
  let filters = $state({ ...initialFilters });
  let draft = $state({ ...initialFilters });
  let viewMode = $state<'list' | 'tiles'>(
    initialViewMode(initialFilters.view, initialFilters.viewExplicit, window.innerWidth)
  );
  let viewExplicit = $state(initialFilters.viewExplicit === true);
  let listAnnouncement = $state('');
  let fieldsByView = $state<Record<ViewMode, string[]>>(
    normalizeViewFields(initialFilters.fields)
  );

  function isFieldVisible(key: string, mode: ViewMode = viewMode): boolean {
    return VIEW_FIELDS.find((field) => field.key === key)?.required === true ||
      fieldsByView[mode].includes(key);
  }
  let filtersRef: FiltersModal | null = $state(null);

  let sortOpen = $state(false);
  let viewOpen = $state(false);

  const currentFullRoute = $derived(
    `${router.current.path}${router.current.query.toString() ? `?${router.current.query.toString()}` : ''}`
  );
  const canConfirm = $derived(auth.can('clients.confirm'));
  const canCreateClient = $derived(auth.level >= 10);
  const filtersActive = $derived(countActive(filters, filterCountDefaults) > 0);
  const sortActive = $derived(filters.sort !== filterDefaults.sort);

  function sameValues(left: string[], right: string[]): boolean {
    return left.length === right.length && left.every((value) => right.includes(value));
  }

  const filterDirty = $derived(
    !sameValues(draft.states, filters.states) ||
      !sameValues(draft.managers, filters.managers) ||
      !sameValues(draft.cities, filters.cities) ||
      draft.from !== filters.from ||
      draft.to !== filters.to
  );
  let viewportWidth = $state(typeof window === 'undefined' ? 1440 : window.innerWidth);
  const columns = $derived(listColumns(fieldsByView.list, viewportWidth));
  const hiddenListFields = $derived(
    OPTIONAL_KEYS.filter((key) => fieldsByView.list.includes(key) && !columns.some((field) => field.key === key))
  );
  const showRowMeta = $derived(hiddenListFields.length > 0 || viewportWidth <= 720);
  const viewColumns = $derived(
    [
      'minmax(170px, 1.6fr)',
      ...columns.map((field) => field.width ?? 'minmax(120px, 1fr)'),
      '64px',
      ...(viewportWidth > 720 ? ['minmax(120px, 150px)'] : [])
    ].join(' ')
  );
  const headFields = $derived([
    VIEW_FIELDS.find((field) => field.key === 'name')!,
    ...columns,
    VIEW_FIELDS.find((field) => field.key === 'requests')!,
    ...(viewportWidth > 720 ? [VIEW_FIELDS.find((field) => field.key === 'status')!] : [])
  ]);
  const viewLabel = $derived(viewMode === 'tiles' ? 'Плитки' : 'Список');

  onMount(() => {
    void load();
    void loadPending();
    void loadFilterOptions();

    const breakpoints = [720, 900, 1024, 1200].map((width) =>
      window.matchMedia(`(min-width: ${width + 1}px)`)
    );
    const update = (): void => {
      viewportWidth = window.innerWidth;
    };
    breakpoints.forEach((query) => query.addEventListener('change', update));
    update();
    return () => breakpoints.forEach((query) => query.removeEventListener('change', update));
  });

  async function loadFilterOptions(): Promise<void> {
    try {
      const [managers, facets] = await Promise.all([listManagers(), getClientFacets()]);
      managerItems = managers.items;
      clientCities = facets.cities;
    } catch {
      // Сохраняем прежние опции — пустые фасеты выглядят как «нет данных».
    }
  }

  let loadSeq = 0;
  let pendingSeq = 0;
  let searchAbort: AbortController | null = null;

  async function loadPending(): Promise<void> {
    if (!canConfirm) {
      pending = [];
      return;
    }
    const seq = ++pendingSeq;
    try {
      const data = await pendingClients();
      if (seq === pendingSeq) {
        pending = data.items;
      }
    } catch {
      // Ожидающие — не основной список: сохраняем прежнее состояние.
    }
  }

  function abortSearch(): void {
    searchAbort?.abort();
    searchAbort = null;
  }

  async function load(signal?: AbortSignal): Promise<{ ok: boolean; total: number }> {
    const seq = ++loadSeq;
    loading = true;
    error = '';

    try {
      const data = await listClients(search, page, perPage, {
        state: filters.states.length > 0 ? filters.states.join(',') : undefined,
        managers: filters.managers.length > 0 ? filters.managers.join(',') : undefined,
        cities: filters.cities.length > 0 ? filters.cities.join(',') : undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        sort: filters.sort,
        signal
      });
      if (seq !== loadSeq) {
        return { ok: false, total: 0 };
      }
      const maxPage = Math.max(1, Math.ceil(data.total / perPage));
      if (page > maxPage) {
        page = maxPage;
        saveClientFilters();
        return load(signal);
      }
      clients = data.items;
      total = data.total;
      return { ok: true, total: data.total };
    } catch (cause) {
      if (seq !== loadSeq) {
        return { ok: false, total: 0 };
      }
      if (cause instanceof DOMException && cause.name === 'AbortError') {
        return { ok: false, total: 0 };
      }
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиентов';
      return { ok: false, total: 0 };
    } finally {
      if (seq === loadSeq) {
        loading = false;
      }
    }
  }

  function goToPage(next: number): void {
    page = next;
    saveClientFilters();
    void load();
  }

  function changePerPage(value: number): void {
    perPage = value;
    page = 1;
    saveClientFilters();
    void load();
  }

  function rowMeta(client: ClientItem): Array<{ key: string; label: string; value: string }> {
    // Показываем ровно те выбранные поля, для которых нет отдельной колонки.
    return clientMetaFields(client, hiddenListFields, formatDate);
  }

  function middleEllipsis(value: string, keep = 14): string {
    return value.length <= keep * 2 + 1 ? value : `${value.slice(0, keep)}…${value.slice(-keep)}`;
  }

  function clientInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
      return '?';
    }

    const first = parts[0][0] ?? '';
    const second = parts.length > 1 ? parts[1][0] ?? '' : '';

    return (first + second).toUpperCase();
  }

  async function applySearch(signal?: AbortSignal): Promise<void> {
    page = 1;
    saveClientFilters();
    await load(signal);
  }

  async function submitSearch(): Promise<void> {
    abortSearch();
    searchAbort = new AbortController();
    search = searchInput.trim();
    addHistory('clients', search);
    await applySearch(searchAbort.signal);
  }

  function clearAll(): void {
    abortSearch();
    search = '';
    searchInput = '';
    page = 1;
    filters = {
      ...filters,
      states: [],
      managers: [],
      cities: [],
      from: '',
      to: ''
    };
    saveClientFilters();
    void applySearch();
  }

  function saveClientFilters(): void {
    saveFilters('clients', {
      states: [...filters.states],
      managers: [...filters.managers],
      cities: [...filters.cities],
      from: filters.from,
      to: filters.to,
      sort: filters.sort,
      view: viewMode,
      viewExplicit,
      q: search,
      page,
      per_page: perPage,
      fields: {
        list: [...fieldsByView.list],
        tiles: [...fieldsByView.tiles]
      }
    });
  }

  function openView(): void {
    listAnnouncement = '';
    viewOpen = true;
  }

  function announceList(text: string): void {
    listAnnouncement = '';
    requestAnimationFrame(() => {
      listAnnouncement = text;
    });
  }

  function setViewMode(next: 'list' | 'tiles'): void {
    if (viewMode === next) {
      return;
    }
    viewMode = next;
    viewExplicit = true;
    saveClientFilters();
    announceList(`Вид: ${next === 'tiles' ? 'плитки' : 'список'}. Найдено: ${total}`);
  }

  function toggleViewField(key: string, mode: ViewMode): void {
    if (VIEW_FIELDS.find((field) => field.key === key)?.required) {
      return;
    }
    fieldsByView = {
      ...fieldsByView,
      [mode]: fieldsByView[mode].includes(key)
        ? fieldsByView[mode].filter((item) => item !== key)
        : OPTIONAL_KEYS.filter((item) => item === key || fieldsByView[mode].includes(item))
    };
    saveClientFilters();
  }

  function resetView(): void {
    // На узком экране сбрасываем к мобильному дефолту — плиткам.
    viewMode = window.innerWidth <= 720 ? 'tiles' : 'list';
    viewExplicit = false;
    fieldsByView = { list: [...OPTIONAL_KEYS], tiles: [...OPTIONAL_KEYS] };
    saveClientFilters();
    announceList(`Вид сброшен: ${viewLabel}. Найдено: ${total}`);
  }

  async function applyFilterDraft(): Promise<string> {
    filters = {
      ...filters,
      states: [...draft.states],
      managers: [...draft.managers],
      cities: [...draft.cities],
      from: draft.from,
      to: draft.to
    };
    page = 1;
    saveClientFilters();
    const result = await load();
    return result.ok
      ? `Фильтры применены. Найдено: ${result.total}`
      : 'Не удалось применить фильтры. Попробуйте ещё раз.';
  }

  async function resetFilterDraft(): Promise<string> {
    draft = {
      ...draft,
      states: [],
      managers: [],
      cities: [],
      from: '',
      to: ''
    };
    const result = await applyFilterDraft();
    return result.startsWith('Не удалось') ? result : `Фильтры сброшены. ${result}`;
  }
  async function applySortTerms(terms: string[]): Promise<void> {
    sortOpen = false;
    const next = terms.length > 0 ? terms.join(',') : 'name_asc';

    if (next === filters.sort) {
      return;
    }

    filters = { ...filters, sort: next };
    page = 1;
    saveClientFilters();
    // Анонс берём из результата загрузки: total к этому моменту ещё старый.
    const result = await load();
    announceList(
      result.ok
        ? `Сортировка применена. Найдено: ${result.total}`
        : 'Не удалось применить сортировку. Попробуйте ещё раз.'
    );
  }
</script>

<section class="page page-wide">
  <div class="sr-only" role="status" aria-live="polite" aria-hidden={sortOpen || viewOpen}>
    {listAnnouncement}
  </div>

  <PageToolbar title="Клиенты" actionsLabel="Действия со списком клиентов">
    {#snippet actions()}
      <ToolbarMenu
        label="Действия со списком клиентов"
        search={{
          value: search,
          historyKey: 'clients',
          title: 'Поиск по клиентам',
          placeholder: 'Имя, компания, ИНН, телефон',
          suggestions: clients.map((client) => client.name),
          onsearch: async (query) => {
            searchInput = query;
            await submitSearch();
          },
          onclear: () => {
            abortSearch();
            searchInput = '';
            search = '';
            void applySearch();
          },
          onstop: abortSearch
        }}
        items={[
          {
            id: 'sort',
            label: 'Сортировка',
            icon: 'sort',
            checked: sortActive,
            dialog: true,
            onclick: () => {
              listAnnouncement = '';
              sortOpen = true;
            }
          },
          {
            id: 'view',
            label: `Вид: ${viewLabel}`,
            icon: 'view',
            dialog: true,
            onclick: openView
          },
          {
            id: 'filters',
            label: 'Фильтры',
            icon: 'filter',
            dialog: true,
            count: filtersActive ? countActive(filters, filterCountDefaults) : 0,
            onclick: () => filtersRef?.show()
          },
          ...(canCreateClient
            ? [
                { id: 'sep', label: '', separator: true, onclick: () => {} },
                {
                  id: 'create',
                  label: 'Новый клиент',
                  icon: 'plus',
                  onclick: () => router.navigate('/clients/new')
                }
              ]
            : [])
        ]}
      />
    {/snippet}
  </PageToolbar>

  <SortDialog
    open={sortOpen}
    fields={sortRows}
    sort={filters.sort}
    defaultValue="name_asc"
    onclose={() => (sortOpen = false)}
    onapply={applySortTerms}
  />
  <Modal open={viewOpen} title="Вид" size="md" announcement={listAnnouncement} onclose={() => (viewOpen = false)}>
    <div class="filters-form view-modal">
      <fieldset class="filter-group view-mode-group" aria-label="Отображение">
        <div class="view-mode-grid">
          <label class="view-mode-card" class:active={viewMode === 'list'}>
            <input
              type="radio"
              name="client-view"
              value="list"
              checked={viewMode === 'list'}
              data-autofocus={viewMode === 'list' ? '' : undefined}
              onchange={() => setViewMode('list')}
            />
            <Icon name="list" size={18} />
            <span>Список</span>
          </label>
          <label class="view-mode-card" class:active={viewMode === 'tiles'}>
            <input
              type="radio"
              name="client-view"
              value="tiles"
              checked={viewMode === 'tiles'}
              data-autofocus={viewMode === 'tiles' ? '' : undefined}
              onchange={() => setViewMode('tiles')}
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

          {#each VIEW_FIELDS as field (field.key)}
            <span class="view-field-name">
              {field.label}{#if field.required}<span class="view-field-required"> •</span>{/if}
            </span>
            <label class="view-field-check" title={field.required ? 'Обязательное поле' : undefined}>
              <input
                type="checkbox"
                checked={isFieldVisible(field.key, 'list')}
                disabled={field.required}
                aria-label={`${field.label}: список`}
                onchange={() => toggleViewField(field.key, 'list')}
              />
            </label>
            <label class="view-field-check" title={field.required ? 'Обязательное поле' : undefined}>
              <input
                type="checkbox"
                checked={isFieldVisible(field.key, 'tiles')}
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
          <Button size="lg" onclick={() => (viewOpen = false)}>Готово</Button>
        </div>
      </div>
    </div>
  </Modal>

  <FiltersModal
    bind:this={filtersRef}
    trigger={false}
    size="md"
    dirty={filterDirty}
    resetLabel="Сбросить всё"
    title="Фильтры"
    count={countActive(filters, filterCountDefaults)}
    onopen={() => {
      draft = {
        ...filters,
        states: [...filters.states],
        managers: [...filters.managers],
        cities: [...filters.cities]
      };
    }}
    onapply={applyFilterDraft}
    onreset={resetFilterDraft}
  >
    <div class="filter-group">
      <span class="filter-label">Статусы</span>
      <MultiSelect
        size="lg"
        label="Статусы"
        options={stateOptions}
        bind:values={draft.states}
        allLabel="Все статусы"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Менеджеры</span>
      <MultiSelect
        size="lg"
        label="Менеджеры"
        options={managerOptions}
        bind:values={draft.managers}
        allLabel="Все менеджеры"
        emptyLabel="Нет менеджеров"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Города</span>
      <MultiSelect
        size="lg"
        label="Города"
        options={cityOptions}
        bind:values={draft.cities}
        allLabel="Все города"
        emptyLabel="Нет городов"
      />
    </div>

    <fieldset class="filter-group">
      <legend class="filter-label">Дата регистрации</legend>
      <div class="view-period">
        <label class="filter-field">
          <span>Дата регистрации с</span>
          <input type="date" bind:value={draft.from} max={draft.to || undefined} />
        </label>
        <label class="filter-field">
          <span>Дата регистрации по</span>
          <input type="date" bind:value={draft.to} min={draft.from || undefined} />
        </label>
      </div>
    </fieldset>
  </FiltersModal>


  {#if error}
    <div class="alert" role="alert">{error}</div>
  {/if}

  {#if canConfirm && pending.length > 0}
    <div class="pending">
      <h2 aria-label={`Ожидают подтверждения: ${pending.length}`}>
        Ожидают подтверждения <span class="count">{pending.length}</span>
      </h2>
      <p class="hint">Откройте карточку клиента — подтверждение и отклонение там</p>
      <div class="list">
        {#each (pendingExpanded ? pending : pending.slice(0, 3)) as item (item.id)}
          <a class="card pending-card" href={`#${withReturnTo(`/clients/${item.id}`, currentFullRoute)}`}>
            <div class="info">
              <span class="name link">{item.name}</span>
              <div class="meta">
                {#if item.company}<span>{item.company}</span>{/if}
                {#if item.inn}<span>ИНН {item.inn}</span>{/if}
                {#if item.city}<span>{item.city}</span>{/if}
                {#if item.address}<span>{item.address}</span>{/if}
                {#if item.phone}<span>{item.phone}</span>{/if}
                {#if item.email}<span>{item.email}</span>{/if}
              </div>
              <div class="manager">Зарегистрировался: {item.registered_at}</div>
            </div>
            <span class="state">ожидает подтверждения</span>
          </a>
        {/each}
      </div>
      {#if pending.length > 3}
        <button type="button" class="pending-more" onclick={() => (pendingExpanded = !pendingExpanded)}>
          {pendingExpanded ? 'Свернуть' : `Показать все (${pending.length})`}
        </button>
      {/if}
    </div>
  {/if}

  {#if loading}
    <ListSkeleton rows={Math.min(perPage, 10)} />
  {:else if clients.length === 0}
    <EmptyState
      icon="clients"
      title={search || filtersActive ? 'Ничего не найдено' : 'Клиентов пока нет'}
      description={search || filtersActive
        ? 'Измените запрос или сбросьте фильтры.'
        : 'Добавьте первого клиента.'}
    >
      {#snippet action()}
        {#if canCreateClient}
          <Button onclick={() => router.navigate('/clients/new')}>Новый клиент</Button>
        {/if}
        {#if search || filtersActive}
          <Button variant="ghost" onclick={clearAll}>Сбросить поиск и фильтры</Button>
        {/if}
      {/snippet}
    </EmptyState>
  {:else}
    {#if viewMode === 'list'}
      <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
      <div class="client-table-scroll" role="region" aria-label="Клиенты, прокрутка таблицы" tabindex="0">
      <div class="client-table" role="table" aria-label="Клиенты" style:--ct-columns={viewColumns}>
        <div class="ct-row ct-head" role="row">
          {#each headFields as field (field.key)}
            <span class={field.headClass} role="columnheader">{field.headLabel ?? field.label}</span>
          {/each}
        </div>

        {#each clients as client (client.id)}
          <div
            class="ct-row"
            class:pending={client.reg_state === 'pending'}
            class:blocked={!client.active}
            role="row"
          >
            <div class="ct-name" role="cell">
              <a
                class="name link row-link"
                href={`#${withReturnTo(`/clients/${client.id}`, currentFullRoute)}`}
                aria-describedby={showRowMeta ? `client-meta-${client.id}` : undefined}
              >{client.name}</a
              >
              {#if showRowMeta}
                <span id={`client-meta-${client.id}`} class="ct-sub ct-meta">
                  {#each rowMeta(client) as field (field.key)}
                    <span class="meta-item">
                      <span class="meta-label">{field.label}:</span> {field.value}
                    </span>
                  {/each}
                  {#if viewportWidth <= 720}
                  <span class="meta-item">
                    <span class="meta-label">Статус:</span>
                    {#if client.reg_state === 'pending'}
                      <span class="state">ожидает подтверждения</span>
                    {:else if !client.active}
                      <span class="state blocked-chip">заблокирован</span>
                    {:else if client.transfer_to_me}
                      <span class="state transfer-chip">→ вам передают</span>
                    {:else}
                      активен
                    {/if}
                  </span>
                  {/if}
                </span>
              {:else if client.inn}
                <span class="ct-sub">ИНН {client.inn}</span>
              {/if}
            </div>

            {#each columns as field (field.key)}
              {#if field.kind === 'email'}
                <span class={`${field.cellClass ?? ''} ct-muted`} role="cell" title={field.title?.(client)}>
                  {#if client.email}
                    <span class="ct-email-line" title={client.email}>
                      <span class="sr-only">Email: {client.email}</span>
                      <span aria-hidden="true">{middleEllipsis(client.email)}</span>
                    </span>
                  {/if}
                  {#if client.city || client.address}
                    <span class="ct-email-line" title={[client.city, client.address].filter(Boolean).join(', ')}>
                      <span class="sr-only">Адрес: </span>{[client.city, client.address].filter(Boolean).join(', ')}
                    </span>
                  {/if}
                  {#if !client.email && !client.city && !client.address}—{/if}
                </span>
              {:else}
                <span
                  class={[field.cellClass, field.ellipsis ? 'ellipsis' : '', field.muted ? 'ct-muted' : ''].filter(Boolean).join(' ')}
                  role="cell"
                  title={field.title?.(client)}
                >{field.value?.(client) || '—'}</span>
              {/if}
            {/each}

            <span class="num" role="cell">{client.requests_total}</span>

            {#if viewportWidth > 720}
            <div class="ct-state" role="cell">
              {#if client.reg_state === 'pending'}
                <span class="state">ожидает подтверждения</span>
              {/if}
              {#if !client.active}
                <span class="state blocked-chip">заблокирован</span>
              {/if}
              {#if client.transfer_to_me}
                <span class="state transfer-chip">→ вам передают</span>
              {/if}
              {#if client.reg_state !== 'pending' && client.active && !client.transfer_to_me}
                <span class="ct-muted">—</span>
              {/if}
            </div>
            {/if}
          </div>
        {/each}
      </div>
      </div>
    {:else}
      <div class="tiles-grid" role="list" aria-label="Клиенты">
        {#each clients as client (client.id)}
          <div
            class="tile"
            role="listitem"
            class:pending={client.reg_state === 'pending'}
            class:blocked={!client.active}
          >
            <div class="tile-head">
              <span
                class="avatar"
                aria-hidden="true"
                style:--avatar-hue={Number(client.id) % 360}
              >{clientInitials(client.name)}</span>
              <div class="tile-title">
                <a
                  class="name link ellipsis row-link"
                  href={`#${withReturnTo(`/clients/${client.id}`, currentFullRoute)}`}
                  aria-label={[
                    client.name,
                    client.reg_state === 'pending' ? 'ожидает подтверждения' : '',
                    !client.active ? 'заблокирован' : '',
                    client.transfer_to_me ? 'вам передают' : ''
                  ]
                    .filter(Boolean)
                    .join(', ')}
                  title={client.name}
                >{client.name}</a
                >
                {#each tileFields(fieldsByView.tiles, 'title') as field (field.key)}
                  <span class={`${field.tileClass ?? ''} ellipsis`} title={field.title?.(client)}>
                    {field.value?.(client) || field.empty || '—'}
                  </span>
                {/each}
              </div>
            </div>

            {#if client.reg_state === 'pending' || !client.active || client.transfer_to_me}
              <div class="tile-chips">
                {#if client.reg_state === 'pending'}
                  <span class="state">ожидает подтверждения</span>
                {/if}
                {#if !client.active}
                  <span class="state blocked-chip">заблокирован</span>
                {/if}
                {#if client.transfer_to_me}
                  <span class="state transfer-chip">→ вам передают</span>
                {/if}
              </div>
            {/if}

            <div class="tile-rows">
              {#if isFieldVisible('company', 'tiles') && client.inn}
                <div class="tile-row"><span>ИНН</span><span>{client.inn}</span></div>
              {/if}
              {#each tileFields(fieldsByView.tiles, 'row') as field (field.key)}
                {@const value = field.value?.(client) ?? ''}
                {#if value !== '' && value !== '—'}
                  <div class="tile-row" class:tile-row-primary={field.primary}>
                    <span>{field.label}</span>
                    <span class="ellipsis" title={value}>
                      <span class="sr-only">{value}</span>
                      <span aria-hidden="true">{middleEllipsis(value, 10)}</span>
                    </span>
                  </div>
                {/if}
              {/each}
            </div>

            <div class="tile-foot">
              <div class="tile-foot-info">
                {#each tileFields(fieldsByView.tiles, 'foot') as field (field.key)}
                {@const value = field.value?.(client) ?? ''}
                {#if value !== '—'}
                  <span class={`${field.tileClass ?? ''} ellipsis`}>
                    {field.key === 'registered' ? `с ${value}` : value || field.empty || '—'}
                  </span>
                {/if}
              {/each}
              </div>
              {#if client.requests_total > 0}
                <span class="tile-badge">
                  <span class="sr-only">Заявок: </span>{client.requests_total}
                </span>
              {/if}
            </div>
          </div>
        {/each}
      </div>
    {/if}

    <Pager
      {page}
      {perPage}
      {total}
      loading={loading}
      onchange={goToPage}
      onperpage={changePerPage}
    />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .pending .count {
    display: inline-block;
    min-width: 20px;
    padding: 1px 8px;
    margin-left: 4px;
    border-radius: 999px;
    background: var(--primary);
    color: var(--on-primary);
    font-size: 12px;
    text-align: center;
  }

  .pending-card {
    border-color: color-mix(in srgb, var(--primary) 35%, var(--border));
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .pending-more {
    align-self: flex-start;
    padding: var(--space-1) var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--primary);
    font: inherit;
    font-size: var(--font-sm);
    cursor: pointer;
  }

  .state.blocked-chip {
    border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
    color: var(--danger);
  }

  .state.transfer-chip {
    border-color: color-mix(in srgb, var(--primary) 45%, var(--border));
    color: var(--primary);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }


  .view-field-table {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 76px 76px;
    align-items: center;
    gap: var(--space-1) var(--space-3);
  }

  .view-field-head {
    color: var(--muted);
    font-size: 12px;
    text-align: center;
  }

  .view-field-name {
    min-width: 0;
    color: var(--text);
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .view-field-required {
    color: var(--muted);
  }

  .view-field-check {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: var(--control-height);
    border-radius: var(--radius-sm);
    cursor: pointer;
  }

  .view-field-check:has(input:disabled) {
    cursor: default;
  }

  .view-field-check input {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--primary);
  }

  .state {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--muted);
    white-space: nowrap;
  }

  .link {
    color: inherit;
    text-decoration: none;
  }

  .card:hover .name {
    color: var(--primary);
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

  .ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .client-table-scroll {
    overflow-x: auto;
    border-radius: var(--radius-md);
  }

  .client-table-scroll:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
  }

  .client-table {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .ct-row {
    position: relative;
    display: grid;
    grid-template-columns: var(
      --ct-columns,
      minmax(170px, 1.6fr) minmax(120px, 1.1fr) minmax(150px, 1.3fr)
        minmax(110px, 1fr) 64px 104px minmax(120px, 150px)
    );
    align-items: center;
    gap: var(--space-3);
    padding: 10px 16px;
    border-bottom: 1px solid var(--border-secondary);
    font-size: 14px;
    cursor: pointer;
    transition: background 0.12s ease;
  }

  .ct-row:last-child {
    border-bottom: none;
  }

  .ct-row:hover {
    background: var(--fill-tertiary);
  }

  .row-link::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
  }

  .row-link:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
  }

  .ct-row:has(.row-link:focus-visible),
  .tile:has(.row-link:focus-visible) {
    background: var(--fill-hover);
  }

  .ct-row:has(.row-link:focus-visible) .ellipsis,
  .ct-row:has(.row-link:focus-visible) .ct-email-line,
  .ct-row:has(.row-link:focus-visible) .ct-name .name,
  .ct-row:has(.row-link:focus-visible) .ct-meta,
  .tile:has(.row-link:focus-visible) .ellipsis {
    overflow: visible;
    white-space: normal;
    text-overflow: clip;
  }

  .tile:has(.row-link:focus-visible) {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
  }

  .ct-row.pending {
    background: color-mix(in srgb, var(--primary) 5%, var(--surface));
  }

  .ct-row.blocked {
    background: color-mix(in srgb, var(--danger) 5%, var(--surface));
  }

  .ct-head {
    padding-top: 9px;
    padding-bottom: 9px;
    border-bottom-width: 2px;
    background: var(--bg);
    color: var(--muted);
    font-size: 12px;
    cursor: default;
  }

  .ct-head:hover {
    background: var(--bg);
  }

  .ct-head .num {
    text-align: right;
  }

  .ct-name {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .ct-name .name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ct-sub {
    font-size: var(--font-xs);
    color: var(--muted);
    white-space: nowrap;
  }

  .ct-meta {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-1) var(--space-2);
    line-height: 1.3;
  }

  .meta-item {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .meta-label {
    color: var(--text-description);
  }

  .ct-phone,
  .ct-email {
    min-width: 0;
    font-size: var(--font-sm);
  }

  .ct-email {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .ct-email-line {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ct-manager {
    font-size: 13px;
    color: var(--text);
    min-width: 0;
  }

  .ct-date {
    font-size: 13px;
    white-space: nowrap;
  }

  .ct-state {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .ct-muted {
    color: var(--muted);
  }

  .ct-row .num {
    text-align: right;
    font-weight: 600;
  }

  .tiles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
    gap: var(--space-3);
  }

  .tile {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    cursor: pointer;
    transition: background 0.12s ease, border-color 0.12s ease;
  }

  .tile:hover {
    background: var(--fill-tertiary);
    border-color: var(--primary);
  }

  .tile.pending {
    border-color: color-mix(in srgb, var(--primary) 35%, var(--border));
    background: color-mix(in srgb, var(--primary) 5%, var(--surface));
  }

  .tile.blocked {
    border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
    background: color-mix(in srgb, var(--danger) 5%, var(--surface));
  }

  .tile-head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
  }

  .avatar {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: var(--control-height-lg);
    height: var(--control-height-lg);
    border-radius: 50%;
    background: color-mix(
      in srgb,
      hsl(var(--avatar-hue, 210) 70% 50%) 16%,
      var(--surface)
    );
    color: var(--text);
    font-size: var(--font-md);
    font-weight: 600;
    letter-spacing: 0.02em;
  }

  .tile-title {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .tile-title .name {
    font-size: var(--font-md);
  }

  .tile-company {
    font-size: var(--font-xs);
    color: var(--muted);
  }

  .tile-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .tile-rows {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-height: 20px;
  }

  .tile-row {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr);
    gap: var(--space-2);
    font-size: var(--font-sm);
  }

  .tile-row-primary > span:last-child {
    color: var(--text);
    font-weight: 500;
  }

  .tile-row > span:first-child {
    color: var(--muted);
    font-size: var(--font-xs);
  }

  .tile-row > span:last-child {
    min-width: 0;
    white-space: nowrap;
  }

  .tile-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-top: auto;
    padding-top: var(--space-2);
    border-top: 1px solid var(--border-secondary);
    font-size: 13px;
    color: var(--muted);
  }

  .tile-foot-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .tile-manager {
    min-width: 0;
  }

  .tile-badge {
    flex: 0 0 auto;
    min-width: 24px;
    padding: 1px 8px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--primary) 10%, var(--surface));
    color: var(--primary);
    font-size: 12px;
    font-weight: 600;
    text-align: center;
  }

  .tile-date {
    font-size: 12px;
    color: var(--muted);
  }

  @media (max-width: 720px) {
    .tile {
      gap: var(--space-2);
      padding: var(--space-3);
    }

    .ct-row {
      gap: var(--space-2);
      padding: var(--space-3);
    }

    .ct-name {
      gap: var(--space-1);
    }

    .ct-head .num {
      text-align: right;
    }
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .card {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    cursor: pointer;
    transition: background 0.12s ease, border-color 0.12s ease;
  }

  .card:hover {
    background: var(--fill-tertiary);
    border-color: var(--primary);
  }

  .info {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .name {
    font-size: 16px;
    font-weight: 500;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .manager {
    font-size: 13px;
    color: var(--muted);
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }



</style>
