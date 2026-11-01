<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { pendingClients } from '../lib/api/clients';
  import { listClients } from '../lib/api/requests';
  import type { ClientItem, PendingClient } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDate } from '../lib/format';
  import { addHistory } from '../lib/search-history';
  import { router } from '../lib/router.svelte';

  const filterDefaults = {
    states: '',
    manager: '',
    from: '',
    to: '',
    sort: 'name_asc',
    view: 'list'
  };

  const filterCountDefaults = {
    states: filterDefaults.states,
    manager: filterDefaults.manager,
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
  const filtersActive = $derived(countActive(filters, filterCountDefaults) > 0);
  const sortActive = $derived(filters.sort !== filterDefaults.sort);

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listClients(search, page, perPage, {
        state: filters.states || undefined,
        manager: filters.manager || undefined,
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
      states: filters.states,
      manager: filters.manager,
      from: filters.from,
      to: filters.to,
      sort: filters.sort,
      view: viewMode
    });
  }

  function openView(): void {
    viewDraft = viewMode;
    draft = { ...filters };
    viewOpen = true;
  }

  function toggleState(code: string): void {
    const current = draft.states ? draft.states.split(',').filter(Boolean) : [];
    const next = current.includes(code)
      ? current.filter((item) => item !== code)
      : [...current, code];
    draft.states = next.join(',');
  }

  function toggleManager(value: 'mine' | 'none'): void {
    draft.manager = draft.manager === value ? '' : value;
  }

  function applyView(): void {
    viewOpen = false;

    const filtersChanged =
      draft.states !== filters.states ||
      draft.manager !== filters.manager ||
      draft.from !== filters.from ||
      draft.to !== filters.to;
    const viewChanged = viewDraft !== viewMode;

    if (!filtersChanged && !viewChanged) {
      return;
    }

    if (filtersChanged) {
      filters = {
        ...filters,
        states: draft.states,
        manager: draft.manager,
        from: draft.from,
        to: draft.to
      };
    }

    viewMode = viewDraft;
    saveClientFilters();
    page = 1;
    void load();
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
    <div class="view-options">
      <label class="radio">
        <input type="radio" name="client-view" value="list" bind:group={viewDraft} />
        <span>Списком (строками)</span>
      </label>
      <label class="radio">
        <input type="radio" name="client-view" value="tiles" bind:group={viewDraft} />
        <span>Плитками</span>
      </label>
    </div>

    <div class="sort-status">
      <span
        class="sort-status-label"
        title="Если ничего не выбрано — показываются активные и ожидающие подтверждения"
      >Статус:</span>
      <div class="status-opts">
        {#each stateOptions as option (option.value)}
          <button
            type="button"
            class="status-opt"
            class:on={draft.states.split(',').includes(option.value)}
            aria-pressed={draft.states.split(',').includes(option.value)}
            onclick={() => toggleState(option.value)}
          >
            {option.label}
          </button>
        {/each}
      </div>
    </div>

    <div class="sort-status">
      <span class="sort-status-label" title="Если ничего не выбрано — все менеджеры">Менеджер:</span>
      <div class="status-opts">
        <button
          type="button"
          class="status-opt"
          class:on={draft.manager === 'mine'}
          aria-pressed={draft.manager === 'mine'}
          onclick={() => toggleManager('mine')}
        >
          Мои клиенты
        </button>
        <button
          type="button"
          class="status-opt"
          class:on={draft.manager === 'none'}
          aria-pressed={draft.manager === 'none'}
          onclick={() => toggleManager('none')}
        >
          Без менеджера
        </button>
      </div>
    </div>

    <div class="view-period">
      <label class="filter-field">
        <span>Регистрация с</span>
        <input type="date" bind:value={draft.from} max={draft.to} />
      </label>
      <label class="filter-field">
        <span>Регистрация по</span>
        <input type="date" bind:value={draft.to} min={draft.from} />
      </label>
    </div>

    <div class="modal-actions">
      <Button variant="ghost" onclick={() => (viewOpen = false)}>Отмена</Button>
      <Button onclick={applyView}>Применить</Button>
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
    <div class="list" class:tiles={viewMode === 'tiles'}>
      {#each clients as client (client.id)}
        <!-- svelte-ignore a11y_click_events_have_key_events -->
        <!-- svelte-ignore a11y_no_static_element_interactions -->
        <div
          class="card"
          class:tile={viewMode === 'tiles'}
          class:pending={client.reg_state === 'pending'}
          class:blocked={!client.active}
          onclick={() => router.navigate(`/clients/${client.id}`)}
        >
          <div class="info">
            <div class="name-row">
              <a
                class="name link"
                href={`#/clients/${client.id}`}
                onclick={(event) => event.stopPropagation()}>{client.name}</a
              >
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
            <div class="meta">
              {#if client.company}<span>{client.company}</span>{/if}
              {#if client.inn}<span>ИНН {client.inn}</span>{/if}
              {#if client.phone}<span>{client.phone}</span>{/if}
              {#if client.email}<span>{client.email}</span>{/if}
            </div>
            <div class="manager">
              {#if client.manager}
                менеджер: {client.manager.name}
              {:else}
                без менеджера
              {/if}
              · заявок: {client.requests_total}
              {#if client.registered_at}· зарегистрирован: {formatDate(client.registered_at)}{/if}
            </div>
          </div>
        </div>
      {/each}
    </div>

    <Pagination
      page={page}
      perPage={perPage}
      total={total}
      loading={loading}
      perPageOptions={[20, 50, 100]}
      always
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

  .pending-card,
  .card.pending {
    border-color: color-mix(in srgb, var(--primary) 35%, var(--border));
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .card.blocked {
    border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
    background: color-mix(in srgb, var(--danger) 6%, var(--surface));
  }

  .name-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
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
    color: var(--text);
    font-weight: 600;
  }

  .sort-row:not(.active) .sort-row-label {
    color: var(--muted);
    font-weight: 400;
  }

  .sort-row .dir-btn {
    margin-left: auto;
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
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .dir-btn.on {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
  }

  .view-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    max-height: 340px;
    overflow-y: auto;
  }

  .radio {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .sort-status {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-top: var(--space-4);
    padding-top: var(--space-4);
    border-top: 1px solid var(--border-secondary);
  }

  .sort-status-label {
    font-size: 14px;
    color: var(--text-description);
  }

  .status-opts {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .status-opt {
    padding: 0 12px;
    height: 28px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease, background 0.12s ease;
  }

  .status-opt:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .status-opt.on {
    border-color: var(--primary);
    background: var(--primary);
    color: #fff;
    font-weight: 500;
  }

  .status-opt.on:hover:not(:disabled) {
    border-color: var(--primary-hover);
    background: var(--primary-hover);
    color: #fff;
  }

  .view-period {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: var(--space-4);
    padding-top: var(--space-4);
    border-top: 1px solid var(--border-secondary);
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .list.tiles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  }

  .list.tiles .card {
    flex-direction: column;
    align-items: stretch;
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
