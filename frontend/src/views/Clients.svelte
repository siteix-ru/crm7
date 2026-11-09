<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { pendingClients } from '../lib/api/clients';
  import { getClientFacets, listClients, listManagers } from '../lib/api/requests';
  import type { ClientItem, ManagerItem, PendingClient } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDate } from '../lib/format';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';

  const filterDefaults = {
    states: [] as string[],
    managers: [] as string[],
    cities: [] as string[],
    from: '',
    to: '',
    sort: 'name_asc',
    view: 'list'
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

  let clients = $state<ClientItem[]>([]);
  let total = $state(0);
  let page = $state(1);
  let perPage = $state(20);
  let pending = $state<PendingClient[]>([]);
  let loading = $state(true);
  let error = $state('');
  let search = $state('');
  let searchInput = $state('');
  const initialFilters = loadFilters('clients', filterDefaults);
  let filters = $state({ ...initialFilters });
  let draft = $state({ ...initialFilters });
  let viewMode = $state<'list' | 'tiles'>(initialFilters.view === 'tiles' ? 'tiles' : 'list');
  let sortOpen = $state(false);
  let viewOpen = $state(false);
  let helpOpen = $state(false);
  let sortDraft = $state<string[]>(['name_asc']);
  let viewDraft = $state<'list' | 'tiles'>('list');
  let dragSortField = $state<string | null>(null);
  let dragSortOver = $state<string | null>(null);

  const canConfirm = $derived(auth.can('clients.confirm'));
  const canCreateClient = $derived(auth.level >= 10);
  const filtersActive = $derived(countActive(filters, filterCountDefaults) > 0);
  const sortActive = $derived(filters.sort !== filterDefaults.sort);

  onMount(() => {
    void load();
    void loadFilterOptions();
  });

  async function loadFilterOptions(): Promise<void> {
    try {
      const [managers, facets] = await Promise.all([listManagers(), getClientFacets()]);
      managerItems = managers.items;
      clientCities = facets.cities;
    } catch {
      managerItems = [];
      clientCities = [];
    }
  }

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listClients(search, page, perPage, {
        state: filters.states.length > 0 ? filters.states.join(',') : undefined,
        managers: filters.managers.length > 0 ? filters.managers.join(',') : undefined,
        cities: filters.cities.length > 0 ? filters.cities.join(',') : undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        sort: filters.sort
      });
      clients = data.items;
      total = data.total;

      if (canConfirm) {
        pending = (await pendingClients()).items;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиентов';
    } finally {
      loading = false;
    }
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

  function openClient(client: ClientItem): void {
    router.navigate(`/clients/${client.id}`);
  }

  function onClientKey(event: KeyboardEvent, client: ClientItem): void {
    if (event.target !== event.currentTarget) {
      return;
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openClient(client);
    }
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

  function applySearch(): void {
    page = 1;
    void load();
  }

  function submitSearch(): void {
    search = searchInput.trim();
    addHistory('clients', search);
    applySearch();
  }

  function saveClientFilters(): void {
    saveFilters('clients', {
      states: [...filters.states],
      managers: [...filters.managers],
      cities: [...filters.cities],
      from: filters.from,
      to: filters.to,
      sort: filters.sort,
      view: viewMode
    });
  }

  function openView(): void {
    viewDraft = viewMode;
    draft = {
      ...filters,
      states: [...filters.states],
      managers: [...filters.managers],
      cities: [...filters.cities]
    };
    viewOpen = true;
  }

  function sameValues(left: string[], right: string[]): boolean {
    return left.length === right.length && left.every((value) => right.includes(value));
  }

  function applyView(): void {
    viewOpen = false;

    const filtersChanged =
      !sameValues(draft.states, filters.states) ||
      !sameValues(draft.managers, filters.managers) ||
      !sameValues(draft.cities, filters.cities) ||
      draft.from !== filters.from ||
      draft.to !== filters.to;
    const viewChanged = viewDraft !== viewMode;

    if (!filtersChanged && !viewChanged) {
      return;
    }

    if (filtersChanged) {
      filters = {
        ...filters,
        states: [...draft.states],
        managers: [...draft.managers],
        cities: [...draft.cities],
        from: draft.from,
        to: draft.to
      };
    }

    viewMode = viewDraft;
    saveClientFilters();
    page = 1;
    void load();
  }

  function resetView(): void {
    draft = {
      states: [],
      managers: [],
      cities: [],
      from: '',
      to: '',
      sort: filters.sort,
      view: 'list'
    };
    viewDraft = 'list';
  }

  function parseSort(value: string): string[] {
    const parts = value
      .split(',')
      .map((part) => part.trim())
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
    sortDraft = parseSort(filters.sort);
    sortOpen = true;
  }

  function applySort(): void {
    sortOpen = false;

    const next = sortDraft.length > 0 ? sortDraft.join(',') : 'name_asc';

    if (next === filters.sort) {
      return;
    }

    filters = { ...filters, sort: next };
    saveClientFilters();
    page = 1;
    void load();
  }
</script>

<section class="page">
  <div class="page-hero">
  <div class="filters">
    <div class="head">
      <h1>Клиенты</h1>
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
        historyKey="clients"
        placeholder="Поиск: имя, компания, ИНН, телефон"
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
        onclick={openSort}
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

      {#if canCreateClient}
        <button
          type="button"
          class="sort-button"
          title="Создать клиента"
          aria-label="Создать клиента"
          onclick={() => router.navigate('/clients/new')}
        >
          <span class="create-client-icon">
            <Icon name="user" size={16} />
            <span class="create-client-plus" aria-hidden="true">
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
  </div>
  </div>

  <Modal open={sortOpen} title="Сортировка" onclose={() => (sortOpen = false)}>
    <p class="sort-hint">
      Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку, чтобы
      добавить условие, и повторно — чтобы убрать.
    </p>
    <div class="sort-list" role="list">
      {#each sortRows as column (column.field)}
        {@const active = sortOrderIndex(column.field) > 0}
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
            <span class="sort-order">{sortOrderIndex(column.field)}</span>
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
              <input type="radio" name="client-view" value="list" bind:group={viewDraft} />
              <Icon name="list" size={18} />
              <span>Список</span>
            </label>
            <label class="view-mode-card" class:active={viewDraft === 'tiles'}>
              <input type="radio" name="client-view" value="tiles" bind:group={viewDraft} />
              <Icon name="tiles" size={18} />
              <span>Плитки</span>
            </label>
          </div>
        </div>

    <div class="filter-group">
      <span class="filter-label" title="Если ничего не выбрано — активные и ожидающие подтверждения">
        Статусы
      </span>
      <MultiSelect
        label="Статусы"
        options={stateOptions}
        bind:values={draft.states}
        allLabel="Все статусы"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Менеджеры</span>
      <MultiSelect
        label="Менеджеры"
        options={managerOptions}
        bind:values={draft.managers}
        allLabel="Все менеджеры"
        emptyLabel="Нет менеджеров"
        maxHeight="280px"
      />
    </div>

    <div class="filter-group">
      <span class="filter-label">Города</span>
      <MultiSelect
        label="Города"
        options={cityOptions}
        bind:values={draft.cities}
        allLabel="Все города"
        emptyLabel="Нет городов"
        maxHeight="280px"
      />
    </div>

    <div class="view-period">
      <label class="filter-field">
        <span>Регистрация с</span>
        <input type="date" bind:value={draft.from} max={draft.to || undefined} />
      </label>
      <label class="filter-field">
        <span>Регистрация по</span>
        <input type="date" bind:value={draft.to} min={draft.from || undefined} />
      </label>
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
    <div class="help-list">
      <p>Статусы клиентов:</p>
      <ul>
        <li>
          <span class="dot pending"></span>ожидает подтверждения регистрации — клиент сам зарегистрировался;
          подтверждение и отклонение — в карточке клиента;
        </li>
        <li>
          <span class="dot blocked"></span>заблокирован — доступ к сайту отключён;
        </li>
        <li>
          <span class="dot transfer"></span>передают вам — клиент передан от другого менеджера.
        </li>
      </ul>
      <p>
        Поиск идёт по имени, компании, ИНН и телефону. Кнопка со стрелками — последовательная
        сортировка (несколько условий сверху вниз); кнопка «Вид и фильтры» — строкой-таблица или
        плитки и фильтры по состоянию, менеджеру и дате регистрации.
      </p>
    </div>
  </Modal>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if canConfirm && pending.length > 0}
    <div class="pending">
      <h2>Ожидают подтверждения <span class="count">{pending.length}</span></h2>
      <p class="hint">Откройте карточку клиента — подтверждение и отклонение там</p>
      <div class="list">
        {#each pending as item (item.id)}
          <!-- svelte-ignore a11y_click_events_have_key_events -->
          <!-- svelte-ignore a11y_no_static_element_interactions -->
          <div class="card pending-card" onclick={() => router.navigate(`/clients/${item.id}`)}>
            <div class="info">
              <a
                class="name link"
                href={`#/clients/${item.id}`}
                onclick={(event) => event.stopPropagation()}>{item.name}</a
              >
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
          </div>
        {/each}
      </div>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if clients.length === 0}
    <div class="empty">Клиенты не найдены</div>
  {:else}
    {#if viewMode === 'list'}
      <div class="client-table">
        <div class="ct-row ct-head">
          <span>Клиент</span>
          <span class="ct-company">Компания</span>
          <span class="ct-contacts">Контакты</span>
          <span class="ct-manager">Менеджер</span>
          <span class="num">Заявки</span>
          <span class="ct-date">Регистрация</span>
          <span>Статус</span>
        </div>

        {#each clients as client (client.id)}
          <div
            class="ct-row"
            class:pending={client.reg_state === 'pending'}
            class:blocked={!client.active}
            role="button"
            tabindex="0"
            aria-label={`Открыть клиента: ${client.name}`}
            onclick={() => openClient(client)}
            onkeydown={(event) => onClientKey(event, client)}
          >
            <div class="ct-name">
              <a
                class="name link"
                href={`#/clients/${client.id}`}
                title={client.name}
                onclick={(event) => event.stopPropagation()}>{client.name}</a
              >
              {#if client.inn}<span class="ct-sub">ИНН {client.inn}</span>{/if}
            </div>

            <span class="ct-company ellipsis" title={client.company || undefined}>
              {client.company || '—'}
            </span>

            <div class="ct-contacts">
              {#if client.phone}<span title={client.phone}>{client.phone}</span>{/if}
              {#if client.email}
                <span class="ellipsis" title={client.email}>{client.email}</span>
              {/if}
              {#if client.city || client.address}
                <span
                  class="ct-muted ellipsis"
                  title={[client.city, client.address].filter(Boolean).join(', ')}
                >
                  {[client.city, client.address].filter(Boolean).join(', ')}
                </span>
              {/if}
              {#if !client.phone && !client.email && !client.city && !client.address}
                <span class="ct-muted">—</span>
              {/if}
            </div>

            <span class="ct-manager ellipsis" title={client.manager?.name}>
              {client.manager?.name ?? '—'}
            </span>

            <span class="num">{client.requests_total}</span>

            <span class="ct-date ct-muted">
              {client.registered_at ? formatDate(client.registered_at) : '—'}
            </span>

            <div class="ct-state">
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
          </div>
        {/each}
      </div>
    {:else}
      <div class="tiles-grid">
        {#each clients as client (client.id)}
          <div
            class="tile"
            class:pending={client.reg_state === 'pending'}
            class:blocked={!client.active}
            role="button"
            tabindex="0"
            aria-label={`Открыть клиента: ${client.name}`}
            onclick={() => openClient(client)}
            onkeydown={(event) => onClientKey(event, client)}
          >
            <div class="tile-head">
              <span class="avatar" aria-hidden="true">{clientInitials(client.name)}</span>
              <div class="tile-title">
                <a
                  class="name link ellipsis"
                  href={`#/clients/${client.id}`}
                  title={client.name}
                  onclick={(event) => event.stopPropagation()}>{client.name}</a
                >
                <span class="tile-company ellipsis" title={client.company || undefined}>
                  {client.company || 'Без компании'}
                </span>
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
              {#if client.inn}
                <div class="tile-row"><span>ИНН</span><span>{client.inn}</span></div>
              {/if}
              {#if client.city}
                <div class="tile-row"><span>Город</span><span class="ellipsis" title={client.city}>{client.city}</span></div>
              {/if}
              {#if client.address}
                <div class="tile-row">
                  <span>Адрес</span>
                  <span class="ellipsis" title={client.address}>{client.address}</span>
                </div>
              {/if}
              {#if client.phone}
                <div class="tile-row"><span>Телефон</span><span>{client.phone}</span></div>
              {/if}
              {#if client.email}
                <div class="tile-row">
                  <span>Email</span>
                  <span class="ellipsis" title={client.email}>{client.email}</span>
                </div>
              {/if}
            </div>

            <div class="tile-foot">
              <div class="tile-foot-info">
                <span class="tile-manager ellipsis">
                  {client.manager ? `менеджер: ${client.manager.name}` : 'без менеджера'}
                </span>
                {#if client.registered_at}
                  <span class="tile-date">с {formatDate(client.registered_at)}</span>
                {/if}
              </div>
              <span class="tile-badge" title={`Заявок: ${client.requests_total}`}>
                {client.requests_total}
              </span>
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

  .head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
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
    color: #fff;
    font-size: 12px;
    text-align: center;
  }

  .pending-card {
    border-color: color-mix(in srgb, var(--primary) 35%, var(--border));
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .state.blocked-chip {
    border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
    color: var(--danger);
  }

  .state.transfer-chip {
    border-color: color-mix(in srgb, var(--primary) 45%, var(--border));
    color: var(--primary);
  }

  .dot {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    border: 1px solid var(--border);
  }

  .dot.pending {
    background: color-mix(in srgb, var(--primary) 35%, #fff);
    border-color: var(--primary);
  }

  .dot.blocked {
    background: color-mix(in srgb, var(--danger) 30%, #fff);
    border-color: var(--danger);
  }

  .dot.transfer {
    background: color-mix(in srgb, var(--primary) 55%, #fff);
    border-color: var(--primary);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
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

  .icon-badge {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--primary);
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

  .sort-row-label {
    flex: 1;
    min-width: 0;
    font-size: 14px;
  }

  .sort-row.active .sort-row-label {
    color: var(--text);
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

  .ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .client-table {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
  }

  .ct-row {
    display: grid;
    grid-template-columns:
      minmax(170px, 1.6fr) minmax(120px, 1.1fr) minmax(150px, 1.3fr)
      minmax(110px, 1fr) 64px 104px minmax(120px, 150px);
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
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
  }

  .ct-contacts {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    font-size: 13px;
    color: var(--text);
  }

  .ct-contacts span {
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
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-3);
  }

  .tile {
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
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-size: 14px;
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
    font-size: 15px;
  }

  .tile-company {
    font-size: 12px;
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
    grid-template-columns: 76px minmax(0, 1fr);
    gap: var(--space-2);
    font-size: 13px;
  }

  .tile-row > span:first-child {
    color: var(--muted);
    font-size: 12px;
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

  @media (max-width: 1200px) {
    .ct-row {
      grid-template-columns:
        minmax(160px, 1.6fr) minmax(150px, 1.3fr) minmax(110px, 1fr)
        64px 104px minmax(110px, 140px);
    }

    .ct-row .ct-company {
      display: none;
    }
  }

  @media (max-width: 900px) {
    .ct-row {
      grid-template-columns: minmax(150px, 1.6fr) minmax(110px, 1fr) 64px minmax(110px, 140px);
    }

    .ct-row .ct-contacts,
    .ct-row .ct-date {
      display: none;
    }
  }

  @media (max-width: 640px) {
    .ct-row {
      grid-template-columns: minmax(130px, 1fr) 56px minmax(100px, 130px);
      gap: var(--space-2);
      padding: 10px 12px;
    }

    .ct-row .ct-manager {
      display: none;
    }

    .ct-head .num {
      text-align: right;
    }
  }

  .help-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    font-size: 14px;
    color: var(--text);
  }

  .help-list ul {
    margin: 0;
    padding-left: var(--space-5);
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .help-list li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
  }

  .help-list .dot {
    flex: 0 0 auto;
    margin-top: 5px;
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

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--muted);
    background: var(--surface);
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
