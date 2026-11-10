<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    addWarehouseUser,
    createWarehouse,
    deleteWarehouse,
    getWarehouse,
    listWarehouseCandidates,
    listWarehouseUsers,
    removeWarehouseUser,
    updateWarehouse,
    type WarehouseCandidate,
    type WarehouseCardData,
    type WarehouseType,
    type WarehouseUser
  } from '../lib/api/stocks';
  import { listManagers } from '../lib/api/requests';
  import { listPriceTypes } from '../lib/api/prices';
  import type { ManagerItem, PriceType } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const isNew = $derived(id <= 0);

  const roleOptions = [
    { level: 90, title: 'Технический администратор' },
    { level: 50, title: 'Администратор' },
    { level: 10, title: 'Менеджер' }
  ];

  const levelOptions = [
    { level: 90, title: 'Технический администратор' },
    { level: 50, title: 'Администратор' },
    { level: 10, title: 'Менеджер' },
    { level: 5, title: 'Клиент' }
  ];

  const typeOptions: { value: WarehouseType; title: string }[] = [
    { value: 'main', title: 'Основной' },
    { value: 'transit', title: 'Транзитный' },
    { value: 'returns', title: 'Возвраты' },
    { value: 'reserve', title: 'Резерв' },
    { value: 'defect', title: 'Брак' }
  ];

  let warehouse = $state<WarehouseCardData | null>(null);
  let users = $state<WarehouseUser[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let notice = $state('');
  let baseline = $state('');

  let tab = $state<'main' | 'contacts' | 'prices' | 'access'>(
    auth.can('stocks.warehouses.edit')
      ? 'main'
      : auth.can('stocks.warehouses.access')
        ? 'access'
        : 'main'
  );
  const canCreateWarehouse = $derived(auth.can('stocks.warehouses.create'));
  const canEditWarehouse = $derived(
    auth.can('stocks.warehouses.edit') || (isNew && canCreateWarehouse)
  );
  const canDeleteWarehouse = $derived(auth.can('stocks.warehouses.delete'));
  const canManageAccess = $derived(auth.can('stocks.warehouses.access'));
  let priceTypes = $state<PriceType[]>([]);
  let priceTypeIds = $state<number[]>([]);

  let name = $state('');
  let name1c = $state('');
  let address = $state('');
  let contactName = $state('');
  let phone = $state('');
  let email = $state('');
  let note = $state('');
  let type = $state<WarehouseType>('main');
  let stockNum = $state('0');
  let sort = $state('500');
  let isDefault = $state(false);
  let allowOrders = $state(true);
  let inReports = $state(true);
  let responsibleSid = $state('');
  let levels = $state<number[]>([]);
  let active = $state(true);

  let respOpen = $state(false);
  let respQuery = $state('');

  const responsibleCandidates = $derived(
    managers.filter((manager) => manager.level === 10 || manager.level === 50)
  );

  const filteredManagers = $derived(
    respQuery.trim() === ''
      ? responsibleCandidates
      : responsibleCandidates.filter((manager) =>
          `${manager.name} ${manager.login}`.toLowerCase().includes(respQuery.trim().toLowerCase())
        )
  );

  const dirty = $derived(formSnapshot() !== baseline);
  const canSave = $derived(isNew || dirty);

  let addOpen = $state(false);
  let candidates = $state<WarehouseCandidate[]>([]);
  let candTotal = $state(0);
  let candLoading = $state(false);
  let candQuery = $state('');
  let candRoles = $state<number[]>([90, 50, 10]);
  let candSort = $state('name_asc');
  let addBusy = $state(false);

  $effect(() => {
    if (!addOpen) {
      return;
    }

    const query = candQuery;
    const roles = candRoles.join(',');
    const sort = candSort;
    const timer = setTimeout(() => void loadCandidates(query, roles, sort), 300);

    return () => clearTimeout(timer);
  });

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';
    notice = '';

    try {
      if (isNew) {
        levels = [50, 10, 5];
        sort = '500';
      } else {
        const data = await getWarehouse(id);
        applyWarehouse(data.warehouse);
        users = (await listWarehouseUsers(id)).users;
      }

      managers = (await listManagers()).items;

      if (appSettings.pricesEnabled) {
        priceTypes = (await listPriceTypes().catch(() => ({ items: [] }))).items;

        if (isNew) {
          priceTypeIds = priceTypes.map((type) => type.id);
        }
      }

      if (isNew) {
        baseline = formSnapshot();
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склад';
    } finally {
      loading = false;
    }
  }

  function applyWarehouse(data: WarehouseCardData): void {
    warehouse = data;
    name = data.name;
    name1c = data.name_1c;
    address = data.address ?? '';
    contactName = data.contact_name ?? '';
    phone = data.phone ?? '';
    email = data.email ?? '';
    note = data.note ?? '';
    type = data.type;
    stockNum = String(data.stock_num);
    sort = String(data.sort);
    isDefault = data.is_default;
    allowOrders = data.allow_orders;
    inReports = data.in_reports;
    responsibleSid = data.responsible?.sid ?? '';
    levels = [...data.levels];
    active = data.active;
    priceTypeIds = [...(data.price_type_ids ?? [])];
    baseline = formSnapshot();
  }

  function formSnapshot(): string {
    return JSON.stringify({
      name,
      name1c,
      address,
      contactName,
      phone,
      email,
      note,
      type,
      stockNum,
      sort,
      isDefault,
      allowOrders,
      inReports,
      responsibleSid,
      levels: [...levels].sort((a, b) => a - b),
      active,
      priceTypeIds: appSettings.pricesEnabled ? [...priceTypeIds].sort((a, b) => a - b) : []
    });
  }

  async function removeWarehouse(): Promise<void> {
    if (!warehouse || !confirm(`Удалить склад <${warehouse.name}>?`)) {
      return;
    }

    busy = true;
    error = '';

    try {
      await deleteWarehouse(id);
      router.navigate('/stocks/warehouses');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить склад';
    } finally {
      busy = false;
    }
  }

  async function save(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      const payload = {
        name,
        name_1c: name1c,
        address,
        contact_name: contactName,
        phone,
        email,
        note,
        type,
        stock_num: Number(stockNum) || 0,
        sort: Number(sort) || 0,
        levels,
        active,
        is_default: isDefault,
        allow_orders: allowOrders,
        in_reports: inReports,
        responsible_sid: responsibleSid,
        ...(appSettings.pricesEnabled ? { price_type_ids: priceTypeIds } : {})
      };

      if (isNew) {
        const created = await createWarehouse(payload);
        router.navigate(`/stocks/warehouses/${created.warehouse.id}/edit`);
        return;
      }

      const data = await updateWarehouse(id, payload);

      applyWarehouse(data.warehouse);
      notice = 'Сохранено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить склад';
    } finally {
      busy = false;
    }
  }

  function toggleLevel(level: number): void {
    levels = levels.includes(level) ? levels.filter((item) => item !== level) : [...levels, level];
  }

  function toggleAllPriceTypes(checked: boolean): void {
    priceTypeIds = checked ? priceTypes.map((priceType) => priceType.id) : [];
  }

  function togglePriceType(id: number): void {
    priceTypeIds = priceTypeIds.includes(id)
      ? priceTypeIds.filter((item) => item !== id)
      : [...priceTypeIds, id];
  }

  function responsibleLabel(): string {
    const manager = managers.find((item) => item.sid === responsibleSid);

    return manager ? manager.name || manager.login : '— не назначен —';
  }

  function openResponsible(): void {
    respQuery = '';
    respOpen = true;
  }

  function pickResponsible(manager: ManagerItem): void {
    responsibleSid = manager.sid;

    if (contactName.trim() === '') {
      contactName = manager.name;
    }

    if (phone.trim() === '') {
      phone = manager.phone;
    }

    if (email.trim() === '') {
      email = manager.email;
    }

    respOpen = false;
  }

  function clearResponsible(): void {
    responsibleSid = '';
    respOpen = false;
  }

  function typeTitle(value: WarehouseType): string {
    return typeOptions.find((option) => option.value === value)?.title ?? value;
  }

  async function loadCandidates(query: string, roles: string, sort: string): Promise<void> {
    candLoading = true;

    try {
      const data = await listWarehouseCandidates(id, {
        q: query,
        roles: roles === '' ? [] : roles.split(',').map(Number),
        sort
      });

      candidates = data.items;
      candTotal = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить пользователей';
    } finally {
      candLoading = false;
    }
  }

  function toggleRole(level: number): void {
    candRoles = candRoles.includes(level)
      ? candRoles.filter((item) => item !== level)
      : [...candRoles, level];
  }

  function setCandSort(field: string): void {
    candSort = candSort === `${field}_asc` ? `${field}_desc` : `${field}_asc`;
  }

  function candSortTerm(field: string): 'asc' | 'desc' | null {
    if (candSort === `${field}_asc`) {
      return 'asc';
    }

    if (candSort === `${field}_desc`) {
      return 'desc';
    }

    return null;
  }

  function roleTitle(level: number): string {
    return levelOptions.find((role) => role.level === level)?.title ?? String(level);
  }

  function openAdd(): void {
    candQuery = '';
    candSort = 'name_asc';
    addOpen = true;
  }

  async function addCandidate(candidate: WarehouseCandidate): Promise<void> {
    addBusy = true;
    error = '';

    try {
      users = (await addWarehouseUser(id, candidate.sid)).users;
      candidates = candidates.map((item) =>
        item.sid === candidate.sid ? { ...item, has_access: true } : item
      );
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось добавить доступ';
    } finally {
      addBusy = false;
    }
  }

  async function removeUser(user: WarehouseUser): Promise<void> {
    if (!confirm(`Убрать доступ для «${user.full_name || user.login}»?`)) {
      return;
    }

    busy = true;
    error = '';

    try {
      users = (await removeWarehouseUser(id, user.user_sid)).users;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось убрать доступ';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="head">
      <a
        class="back"
        href="#/stocks/warehouses"
        title="К списку складов"
      >
        <Icon name="arrow-left" size={16} />
      </a>
      <div class="head-title">
        <h1>{isNew ? 'Новый склад' : (warehouse?.name ?? 'Склад')}</h1>
        <p class="sub">
          {#if isNew}
            Заполните данные склада
          {:else if warehouse}
            {typeTitle(warehouse.type)}{#if warehouse.is_default} · По умолчанию{/if}
          {:else}
            Загрузка…
          {/if}
        </p>
      </div>
      <div class="head-actions">
        <span class="status-badge" class:off={!isNew && warehouse !== null && !warehouse.active}>
          {#if isNew}
            Новый
          {:else if warehouse}
            {warehouse.active ? 'Активен' : 'Отключён'}
          {:else}
            Загрузка…
          {/if}
        </span>
        <div class="tab-icons" role="tablist" aria-label="Разделы склада">
          {#if canEditWarehouse}
          <button
            type="button"
            class:active={tab === 'main'}
            role="tab"
            aria-selected={tab === 'main'}
            title="Основное"
            aria-label="Основное"
            onclick={() => (tab = 'main')}
          >
            <Icon name="overview" size={18} />
          </button>
          <button
            type="button"
            class:active={tab === 'contacts'}
            role="tab"
            aria-selected={tab === 'contacts'}
            title="Контакты"
            aria-label="Контакты"
            onclick={() => (tab = 'contacts')}
          >
            <Icon name="user" size={18} />
          </button>
          {#if appSettings.pricesEnabled}
            <button
              type="button"
              class:active={tab === 'prices'}
              role="tab"
              aria-selected={tab === 'prices'}
              title="Цены"
              aria-label="Цены"
              onclick={() => (tab = 'prices')}
            >
              <Icon name="price" size={18} />
            </button>
          {/if}
          {/if}
          {#if canManageAccess}
          <button
            type="button"
            class:active={tab === 'access'}
            role="tab"
            aria-selected={tab === 'access'}
            title="Доступ"
            aria-label="Доступ"
            onclick={() => (tab = 'access')}
          >
            <Icon name="key" size={18} />
          </button>
          {/if}
        </div>
      </div>
    </div>
  </div>


  {#if loading}
    <div class="center"><Spinner /></div>
  {:else if !isNew && !warehouse}
    <div class="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error || 'Склад не найден'}</span>
    </div>
  {:else}
    {#if error}
      <div class="alert">
        <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
        <span>{error}</span>
      </div>
    {/if}
    {#if notice}
      <div class="notice">
        <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
        <span>{notice}</span>
      </div>
    {/if}

        {#if tab === 'main' && canEditWarehouse}
      <div class="card">
        <h2>Основное</h2>
        <div class="grid">
          <Input label="Название" bind:value={name} />
          <Input label="Название в 1С" bind:value={name1c} placeholder="Если отличается от названия в 1С" />
          <label class="field">
            <span class="field-label">Тип склада</span>
            <select bind:value={type}>
              {#each typeOptions as option (option.value)}
                <option value={option.value}>{option.title}</option>
              {/each}
            </select>
          </label>
          <div class="field">
            <span class="field-label">Ответственный</span>
            <button type="button" class="picker" onclick={openResponsible}>
              <span class="picker-value" class:picker-empty={responsibleSid === ''}>
                {responsibleLabel()}
              </span>
              <span class="picker-icon"><Icon name="arrow-down" size={14} /></span>
            </button>
            <span class="field-hint">
              Контактное лицо склада. Контакты подставляются в заказ, если не заполнены вручную.
            </span>
          </div>
        </div>

        <details class="advanced">
          <summary>Дополнительно</summary>
          <div class="grid">
            <Input label="Номер склада" type="number" bind:value={stockNum} />
            <Input label="Порядок в списке" type="number" bind:value={sort} />
          </div>
        </details>

        <h2>Статус и видимость</h2>
        <div class="flags">
          {#if appSettings.requestsEnabled}
            <label class="checkbox">
              <input type="checkbox" bind:checked={allowOrders} />
              Принимать заказы
            </label>
          {/if}
          {#if appSettings.reportsEnabled}
            <label class="checkbox">
              <input type="checkbox" bind:checked={inReports} />
              Показывать в отчётах
            </label>
          {/if}
          <label class="checkbox">
            <input type="checkbox" bind:checked={active} />
            Активен
          </label>
        </div>

        <p class="hint">
          Отключённый склад не отображается в остатках. Заказы и отчёты можно отключить отдельно.
        </p>
      </div>
    {:else if tab === 'contacts' && canEditWarehouse}
      <div class="card">
        <h2>Размещение</h2>
        <div class="grid">
          <Input label="Адрес" bind:value={address} placeholder="Город, улица, дом" />
        </div>

        <h2>Контакты</h2>
        <p class="hint contacts-hint">
          Контакты подставляются в заказ. Пустые поля можно заполнить вручную.
        </p>
        <div class="grid">
          <Input label="Контактное лицо (для связи)" bind:value={contactName} />
          <Input label="Телефон" bind:value={phone} />
          <Input label="E-mail" bind:value={email} />
        </div>

        <h2>Комментарий</h2>
        <label class="field">
          <textarea
            bind:value={note}
            rows="3"
            aria-label="Комментарий"
            placeholder="Внутренняя заметка о складе"
          ></textarea>
        </label>
      </div>
    {:else if tab === 'prices' && canEditWarehouse}
      <div class="card">
        <h2>Цены склада</h2>
        {#if priceTypes.length === 0}
          <p class="hint">Типы цен ещё не настроены. Добавьте их в справочнике типов цен.</p>
        {:else}
          <div class="price-toolbar">
            <p class="hint">Выберите типы цен, доступные на складе.</p>
            <div class="inline-actions">
              <button type="button" class="link-btn" onclick={() => toggleAllPriceTypes(true)}>Выбрать все</button>
              <button type="button" class="link-btn" onclick={() => toggleAllPriceTypes(false)}>Снять все</button>
            </div>
          </div>
          <div class="price-options">
            {#each priceTypes as priceType (priceType.id)}
              <label class="checkbox">
                <input
                  type="checkbox"
                  checked={priceTypeIds.includes(priceType.id)}
                  onchange={() => togglePriceType(priceType.id)}
                />
                {priceType.title}
              </label>
            {/each}
          </div>
        {/if}
      </div>
    {:else if tab === 'access' && canManageAccess}
      <div class="card">
        <h2>Роли с доступом</h2>
        <p class="hint">
          Роли, которые видят склад и могут работать с его остатками.
        </p>
        <div class="flags">
          {#each levelOptions as role (role.level)}
            <label class="checkbox">
              <input
                type="checkbox"
                checked={levels.includes(role.level)}
                onchange={() => toggleLevel(role.level)}
              />
              {role.title}
            </label>
          {/each}
        </div>

        {#if !isNew}
          <div class="row-head users-head">
            <div>
              <h2>Сотрудники с доступом</h2>
              <p class="hint">Добавление и удаление применяются сразу.</p>
            </div>
            <Button onclick={openAdd}>Добавить</Button>
          </div>

          {#if users.length === 0}
            <div class="empty">У склада пока нет сотрудников с доступом</div>
          {:else}
            <div class="users">
              {#each users as user (user.id)}
                <div class="user">
                  <div class="who">
                    <strong>{user.full_name || user.login}</strong>
                    <span class="login">{user.login}</span>
                    {#if !user.active}<span class="tag">Отключён</span>{/if}
                  </div>
                  <button
                    type="button"
                    class="icon-btn"
                    title="Убрать доступ"
                    aria-label={`Убрать доступ: ${user.full_name || user.login}`}
                    onclick={() => void removeUser(user)}
                  >
                    <Icon name="trash" size={16} />
                  </button>
                </div>
              {/each}
            </div>
          {/if}
        {/if}
      </div>
    {:else}
      <div class="card">
        <p class="hint">Нет прав на просмотр или редактирование склада.</p>
      </div>
    {/if}

    {#if canEditWarehouse || canDeleteWarehouse}
    <div class="edit-actions">
      {#if canDeleteWarehouse && !isNew}
        <Button variant="danger" loading={busy} onclick={() => void removeWarehouse()}>
          Удалить
        </Button>
      {/if}
      {#if canEditWarehouse}
        <span class="save-state" class:dirty={dirty}>
          {#if isNew}
            Заполните данные склада
          {:else if dirty}
            Есть несохранённые изменения
          {:else}
            Все изменения сохранены
          {/if}
        </span>
        <Button loading={busy} disabled={!canSave} onclick={() => void save()}>
          {isNew ? 'Создать склад' : 'Сохранить'}
        </Button>
      {/if}
    </div>
    {/if}
  {/if}
</section>

<Modal open={addOpen} title="Добавить доступ к складу" wide onclose={() => (addOpen = false)}>
  <div class="cand-toolbar">
    <form
      class="cand-search"
      onsubmit={(event) => {
        event.preventDefault();
      }}
    >
      <SearchInput
        bind:value={candQuery}
        historyKey="warehouse-access"
        placeholder="Поиск по имени или логину"
      />
    </form>

    <div class="cand-roles">
      {#each roleOptions as role (role.level)}
        <label class="checkbox">
          <input
            type="checkbox"
            checked={candRoles.includes(role.level)}
            onchange={() => toggleRole(role.level)}
          />
          {role.title}
        </label>
      {/each}
    </div>
  </div>

  {#if candLoading}
    <div class="center"><Spinner /></div>
  {:else if candidates.length === 0}
    <div class="empty">Нет подходящих пользователей</div>
  {:else}
    <div class="cand-table">
      <div class="cand-head">
        <button type="button" class="cand-th" onclick={() => setCandSort('name')}>
          ФИО
          {#if candSortTerm('name')}
            <Icon name={candSortTerm('name') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
        <button type="button" class="cand-th" onclick={() => setCandSort('login')}>
          Логин
          {#if candSortTerm('login')}
            <Icon name={candSortTerm('login') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
        <button type="button" class="cand-th" onclick={() => setCandSort('level')}>
          Роль
          {#if candSortTerm('level')}
            <Icon name={candSortTerm('level') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
        <button type="button" class="cand-th cand-th-access" onclick={() => setCandSort('access')}>
          Доступ
          {#if candSortTerm('access')}
            <Icon name={candSortTerm('access') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
      </div>

      {#each candidates as candidate (candidate.id)}
        <div class="cand-row">
          <span class="cand-name">{candidate.name}</span>
          <span class="cand-login">{candidate.login}</span>
          <span class="cand-role">{roleTitle(candidate.level)}</span>
          {#if candidate.has_access}
            <span class="tag on">есть доступ</span>
          {:else}
            <button
              type="button"
              class="cand-add"
              disabled={addBusy}
              onclick={() => void addCandidate(candidate)}
            >
              Добавить
            </button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}

  <div class="modal-actions">
    <span class="cand-total">Найдено: {candTotal}</span>
    <Button variant="ghost" onclick={() => (addOpen = false)}>Закрыть</Button>
  </div>
</Modal>

<Modal open={respOpen} title="Ответственный за склад" wide onclose={() => (respOpen = false)}>
  <div class="cand-toolbar">
    <form
      class="cand-search"
      onsubmit={(event) => {
        event.preventDefault();
      }}
    >
      <SearchInput
        bind:value={respQuery}
        historyKey="warehouse-responsible"
        placeholder="Поиск по имени или логину"
      />
    </form>
  </div>

  {#if filteredManagers.length === 0}
    <div class="empty">Нет подходящих пользователей</div>
  {:else}
    <div class="cand-table">
      <div class="cand-head">
        <span>ФИО</span>
        <span>Логин</span>
        <span>Роль</span>
        <span>Выбор</span>
      </div>

      {#each filteredManagers as manager (manager.id)}
        <div class="cand-row">
          <span class="cand-name">{manager.name || manager.login}</span>
          <span class="cand-login">{manager.login}</span>
          <span class="cand-role">{roleTitle(manager.level)}</span>
          {#if manager.sid === responsibleSid}
            <span class="tag on">текущий</span>
          {:else}
            <button type="button" class="cand-add" onclick={() => pickResponsible(manager)}>
              Выбрать
            </button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}

  <div class="modal-actions">
    <span class="cand-total">Найдено: {filteredManagers.length}</span>
    {#if responsibleSid}
      <Button variant="ghost" onclick={clearResponsible}>Снять</Button>
    {/if}
    <Button variant="ghost" onclick={() => (respOpen = false)}>Закрыть</Button>
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

  .head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .head-title {
    flex: 1 1 auto;
    min-width: 0;
  }

  .head-title h1 {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-left: auto;
  }

  .status-badge {
    display: inline-flex;
    align-items: center;
    height: 24px;
    padding: 0 8px;
    border: 1px solid var(--success-border);
    border-radius: 999px;
    background: var(--success-bg);
    color: var(--success-text);
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
  }

  .status-badge.off {
    border-color: var(--border);
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .tab-icons {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .tab-icons button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text-description);
    cursor: pointer;
    transition: color 0.15s ease, background-color 0.15s ease;
  }

  .tab-icons button:hover {
    background: var(--fill-tertiary);
    color: var(--text);
  }

  .tab-icons button.active {
    background: none;
    color: var(--primary);
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--control-height);
    height: var(--control-height);
    flex: 0 0 auto;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    background: var(--surface);
  }

  .back:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .sub {
    margin: 2px 0 0;
    font-size: 14px;
    color: var(--text-description);
  }

  @media (max-width: 640px) {
    .head-actions {
      width: 100%;
      margin-left: 0;
      justify-content: space-between;
    }
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-4);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--space-3);
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

  .field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .field-label {
    font-size: 14px;
    color: var(--text);
  }

  .field-hint {
    font-size: 12px;
    line-height: 1.4;
    color: var(--text-description);
  }

  .contacts-hint {
    margin: 0 0 var(--space-3);
  }

  select,
  textarea {
    width: 100%;
    min-height: var(--control-height);
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  textarea {
    min-height: 72px;
    padding: 6px 11px;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .picker {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    width: 100%;
    min-height: var(--control-height);
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .picker:hover {
    border-color: var(--primary);
  }

  .picker:focus-visible {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .picker-value {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .picker-empty {
    color: var(--text-description);
  }

  .picker-icon {
    display: inline-flex;
    flex: 0 0 auto;
    color: var(--text-description);
  }

  .flags {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .advanced {
    padding-top: var(--space-3);
    border-top: 1px solid var(--border-secondary);
  }

  .advanced summary {
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .advanced[open] summary {
    margin-bottom: var(--space-3);
  }

  .edit-actions {
    position: sticky;
    bottom: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: var(--space-3);
    padding: var(--space-3) 0;
    border-top: 1px solid var(--border-secondary);
    background: var(--bg);
  }

  .edit-actions :global(button.danger) {
    margin-right: auto;
  }

  .save-state {
    margin-right: auto;
    font-size: 13px;
    color: var(--text-description);
  }

  .save-state.dirty {
    color: var(--warning-text);
  }

  .price-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .price-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--space-2) var(--space-4);
  }

  .inline-actions {
    display: flex;
    gap: var(--space-3);
  }

  .link-btn {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
  }

  .link-btn:hover {
    color: var(--primary-hover);
  }

  .users-head {
    align-items: flex-start;
    margin-top: var(--space-2);
  }

  .users-head .hint {
    margin-top: 2px;
  }

  .row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .users {
    display: flex;
    flex-direction: column;
  }

  .user {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px 0;
  }

  .user + .user {
    border-top: 1px solid var(--border-secondary);
  }

  .who {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    min-width: 0;
  }

  .login {
    font-size: 13px;
    color: var(--text-description);
  }

  .tag {
    justify-self: start;
    font-size: 12px;
    line-height: 20px;
    padding: 0 7px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--control-height);
    height: var(--control-height);
    flex: 0 0 auto;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text-description);
    cursor: pointer;
    transition: color 0.2s ease, background-color 0.2s ease;
  }

  .icon-btn:hover {
    color: var(--error-text);
    background: var(--fill-hover);
  }

  .cand-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-3);
  }

  .cand-search {
    flex: 1 1 220px;
    max-width: 320px;
    min-width: 0;
  }

  .cand-roles {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .cand-table {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .cand-head,
  .cand-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 0.7fr) 130px 110px;
    align-items: center;
    gap: var(--space-3);
    padding: 8px var(--space-3);
  }

  .cand-head {
    background: var(--fill-tertiary);
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .cand-row {
    border-top: 1px solid var(--border-secondary);
    font-size: 14px;
  }

  .cand-row:hover {
    background: var(--fill-tertiary);
  }

  .cand-th {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .cand-th:hover {
    color: var(--primary);
  }

  .cand-name {
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cand-login,
  .cand-role {
    color: var(--text-description);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cand-row .tag {
    justify-self: stretch;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 24px;
    padding: 0 7px;
    border-radius: var(--radius-sm);
    font-size: 14px;
  }

  .cand-add {
    padding: 0 7px;
    height: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--primary);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
    transition: color 0.2s ease, border-color 0.2s ease;
  }

  .cand-add:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .cand-add:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .cand-total {
    margin-right: auto;
    font-size: 13px;
    color: var(--text-description);
  }

  .modal-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
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
