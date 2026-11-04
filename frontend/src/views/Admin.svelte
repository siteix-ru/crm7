<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    blockUser,
    createUser,
    listModules,
    listRoles,
    listUsers,
    resetUserPassword,
    saveModules,
    saveRole,
    type ModuleItem,
    type ModuleRole,
    unblockUser,
    updateUser
  } from '../lib/api/admin';
  import { convertUser } from '../lib/api/staff';
  import type { AssignedPriceType } from '../lib/api/types';
  import { listLegalDocuments, saveLegalDocument, type LegalDocument } from '../lib/api/legal';
  import type { AdminUser, RoleCapability, RoleInfo } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import { addHistory } from '../lib/search-history';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import Button from '../lib/components/ui/Button.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import PriceTypesPicker from '../lib/components/ui/PriceTypesPicker.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';

  type AdminSection = 'modules' | 'roles' | 'docs';

  let { section = null, onsaved }: { section?: AdminSection | null; onsaved?: () => void } =
    $props();

  const embedded = $derived(section !== null);

  const levelTitles = $derived<Record<number, string>>({
    90: 'Технический администратор',
    50: 'Администратор',
    10: 'Менеджер',
    5: 'Клиент',
    1: 'Гость'
  });

  const adminCoreCapabilities = ['users.manage', 'roles.manage', 'settings.manage'];

  const userFilterDefaults = { level: 0, state: '' };

  let tab = $state<'users' | 'modules' | 'roles' | 'docs'>('roles');

  $effect(() => {
    if (section !== null && tab !== section) {
      tab = section;
    }
  });

  let users = $state<AdminUser[]>([]);
let usersTotal = $state(0);
let usersPage = $state(1);
let usersPerPage = $state(20);
  let userQuery = $state('');
  const initialUserFilters = loadFilters('admin-users', userFilterDefaults);
  let userFilters = $state({ ...initialUserFilters });
  let userDraft = $state({ ...initialUserFilters });
  let usersLoading = $state(true);

  let editing = $state<AdminUser | null>(null);
  let pricePicker = $state<AdminUser | null>(null);
  let showCreate = $state(false);
  let form = $state({
    name: '',
    email: '',
    phone: '',
    company: '',
    inn: '',
    position: '',
    level: 5
  });
  let editForm = $state({ name: '', phone: '', company: '', inn: '', position: '', level: 5 });

  let roles = $state<RoleInfo[]>([]);
  let roleCatalog = $state<RoleCapability[]>([]);
  let roleDraft = $state<Record<number, string[]>>({});
  let roleBusy = $state(0);
  let roleNotice = $state('');
  let roleError = $state('');
  let docs = $state<LegalDocument[]>([]);  let docCode = $state('');
  let docTitle = $state('');
  let docBody = $state('');
  let docBusy = $state(false);
  let docNotice = $state('');

  let error = $state('');
  let message = $state('');
  let busy = $state(false);

  let modules = $state<ModuleItem[]>([]);
  let moduleRoles = $state<ModuleRole[]>([]);

  const moduleOrder = [
    'requests',
    'clients',
    'manager_assign',
    'staff',
    'substitutions',
    'registration',
    'chat',
    'notifications',
    'mail',
    'stock_edit',
    'warehouses',
    'reports'
  ];

  const sortedModules = $derived(
    [...modules].sort((a, b) => {
      const ai = moduleOrder.indexOf(a.code);
      const bi = moduleOrder.indexOf(b.code);

      return (ai === -1 ? moduleOrder.length : ai) - (bi === -1 ? moduleOrder.length : bi);
    })
  );
  const moduleRoleOptions = $derived(
    moduleRoles.map((role) => ({ value: String(role.level), label: role.title }))
  );
  let modulesLoading = $state(false);
  let modulesBusy = $state(false);
  let modulesNotice = $state('');
  let modulesError = $state('');

  const canManageUsers = $derived(auth.can('users.manage'));
  const canManageRoles = $derived(auth.can('roles.manage'));
  const pricesEnabled = $derived(appSettings.pricesEnabled);

  onMount(() => {
    if (section !== null) {
      void openTab(section);

      return;
    }

    if (canManageUsers) {
      void loadRoles();
    } else if (canManageRoles) {
      tab = 'modules';
      void loadModules();
    }
  });

  async function loadUsers(): Promise<void> {
    usersLoading = true;
    error = '';

    try {
      const data = await listUsers({
        q: userQuery,
        level: userFilters.level,
        state: userFilters.state,
        page: usersPage,
        per_page: usersPerPage
      });

      users = data.items;
      usersTotal = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить пользователей';
    } finally {
      usersLoading = false;
    }
  }

  function submitUsersSearch(): void {
    usersPage = 1;
    addHistory('admin-users', userQuery);
    void loadUsers();
  }

  function applyUserFilterDraft(): void {
    userFilters = { ...userDraft };
    saveFilters('admin-users', userFilters);
    usersPage = 1;
    void loadUsers();
  }

  function resetUserFilters(): void {
    userDraft = { ...userFilterDefaults };
    userFilters = { ...userFilterDefaults };
    clearFilters('admin-users');
    usersPage = 1;
    void loadUsers();
  }

  async function submitCreate(): Promise<void> {
    busy = true;
    error = '';
    message = '';

    try {
      const result = await createUser({ ...form });

      message = result.password
        ? `Создан ${result.login}. Пароль: ${result.password} (показывается один раз)`
        : `Создан ${result.login}`;

      form = { name: '', email: '', phone: '', company: '', inn: '', position: '', level: 5 };
      showCreate = false;
      await loadUsers();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать пользователя';
    } finally {
      busy = false;
    }
  }

  function startEdit(user: AdminUser): void {
    editing = user;
    editForm = {
      name: user.name,
      phone: user.phone,
      company: user.company,
      inn: user.inn,
      position: user.position,
      level: user.level
    };
  }

  async function submitEdit(): Promise<void> {
    if (editing === null) {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      const levelChanged = editForm.level !== editing.level;
      const crossesClientBoundary = levelChanged && ((editing.level === 5) !== (editForm.level === 5));

      if (crossesClientBoundary) {
        await updateUser(editing.id, { ...editForm, level: editing.level });
        await convertUser(editing.id, editForm.level);
      } else {
        await updateUser(editing.id, { ...editForm });
      }

      message = 'Данные сохранены';
      editing = null;
      await loadUsers();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить';
    } finally {
      busy = false;
    }
  }

  function onPriceTypesSaved(userId: number, assigned: AssignedPriceType[]): void {
    users = users.map((user) =>
      user.id === userId ? { ...user, price_types: assigned } : user
    );

    if (editing !== null && editing.id === userId) {
      editing = { ...editing, price_types: assigned };
    }
  }

  async function toggleBlock(user: AdminUser): Promise<void> {
    busy = true;
    error = '';
    message = '';

    try {
      if (user.active) {
        await blockUser(user.id);
        message = `${user.name}: доступ заблокирован`;
      } else {
        await unblockUser(user.id);
        message = `${user.name}: доступ восстановлен`;
      }

      await loadUsers();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось изменить доступ';
    } finally {
      busy = false;
    }
  }

  async function resetPassword(user: AdminUser): Promise<void> {
    if (!confirm(`Сбросить пароль пользователю ${user.name}?`)) {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      const result = await resetUserPassword(user.id);
      message = `${user.name}: новый пароль ${result.password} (показывается один раз)`;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сбросить пароль';
    } finally {
      busy = false;
    }
  }

  function selectDoc(document: LegalDocument): void {
    docCode = document.code;
    docTitle = document.title;
    docBody = document.body;
    docNotice = '';
  }

  const docPlaceholders = $derived([...new Set(docBody.match(/\[[^\]]+\]/g) ?? [])]);

  async function saveDoc(): Promise<void> {
    docBusy = true;
    docNotice = '';
    error = '';

    try {
      const result = await saveLegalDocument(docCode, docTitle, docBody);
      docs = result.items;
      docNotice = 'Документ сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить документ';
    } finally {
      docBusy = false;
    }
  }

  async function openTab(
    next: 'users' | 'modules' | 'roles' | 'docs'
  ): Promise<void> {
    tab = next;

    if (next === 'modules' && modules.length === 0) {
      await loadModules();
    }

    if (next === 'docs' && docs.length === 0) {
      try {
        docs = (await listLegalDocuments()).items;

        if (docs.length > 0) {
          selectDoc(docs[0]);
        }
      } catch {
        // некритично
      }
    }

    if (next === 'roles') {
      await loadRoles();
    }
  }

  async function loadModules(): Promise<void> {
    modulesLoading = true;
    modulesError = '';
    modulesNotice = '';

    try {
      const data = await listModules();
      modules = data.modules;
      moduleRoles = data.roles;
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить модули';
    } finally {
      modulesLoading = false;
    }
  }

  function toggleModuleEnabled(code: string): void {
    const current = modules.find((module) => module.code === code);

    if (
      code === 'warehouses' &&
      current?.enabled &&
      !confirm(
        'Отключение модуля «Склады» необратимо: остатки и цены будут объединены по номенклатуре, остальные склады удалены. Продолжить?'
      )
    ) {
      return;
    }

    modules = modules.map((module) =>
      module.code === code ? { ...module, enabled: !module.enabled } : module
    );
    modulesNotice = '';
  }

  function setModuleLevels(code: string, values: string[]): void {
    const levels = values
      .map(Number)
      .filter((level) => Number.isFinite(level));

    modules = modules.map((module) =>
      module.code === code ? { ...module, levels } : module
    );
    modulesNotice = '';
  }

  function setModuleGroupLevels(code: string, groupCode: string, values: string[]): void {
    const levels = values
      .map(Number)
      .filter((level) => Number.isFinite(level));

    modules = modules.map((module) => {
      if (module.code !== code) {
        return module;
      }

      const groups = module.groups.map((group) =>
        group.code === groupCode ? { ...group, levels } : group
      );
      const allLevels = [...new Set(groups.flatMap((group) => group.levels))].sort((a, b) => a - b);

      return { ...module, groups, levels: allLevels };
    });
    modulesNotice = '';
  }

  async function saveModulesDraft(): Promise<void> {
    modulesBusy = true;
    modulesError = '';
    modulesNotice = '';

    try {
      const data = await saveModules(modules);
      modules = data.modules;
      moduleRoles = data.roles;
      modulesNotice = 'Сохранено';
      await appSettings.load();
      onsaved?.();
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить модули';
    } finally {
      modulesBusy = false;
    }
  }

  async function loadRoles(): Promise<void> {
    roleError = '';
    roleNotice = '';

    try {
      const data = await listRoles();
      roles = data.items;
      roleCatalog = data.catalog;
      roleDraft = Object.fromEntries(data.items.map((role) => [role.level, [...role.capabilities]]));
    } catch (cause) {
      roleError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить роли';
    }
  }

  function roleSelected(role: RoleInfo): string[] {
    return roleDraft[role.level] ?? role.capabilities;
  }

  function isRoleDirty(role: RoleInfo): boolean {
    const selected = roleDraft[role.level];

    if (!selected) {
      return false;
    }

    const next = [...selected].sort();
    const current = [...role.capabilities].sort();

    return next.length !== current.length || next.some((code, index) => code !== current[index]);
  }

  function toggleCapability(role: RoleInfo, code: string): void {
    const selected = roleSelected(role);
    const next = selected.includes(code)
      ? selected.filter((item) => item !== code)
      : [...selected, code];

    roleDraft = { ...roleDraft, [role.level]: next };
  }

  const capabilityGroupTitles: Record<string, string> = {
    requests: 'Заявки',
    clients: 'Клиенты',
    stocks: 'Склады',
    reports: 'Отчёты',
    chat: 'Чат',
    notifications: 'Уведомления',
    mail: 'Почта',
    users: 'Пользователи',
    roles: 'Роли',
    audit: 'Аудит',
    settings: 'Настройки'
  };

  const capabilityGroupOrder = Object.keys(capabilityGroupTitles);

  const catalogGroups = $derived.by(() => {
    const groups = new Map<string, RoleCapability[]>();

    for (const capability of roleCatalog) {
      const key = capability.code.split('.')[0];
      const bucket = groups.get(key) ?? [];
      bucket.push(capability);
      groups.set(key, bucket);
    }

    return [...groups.entries()]
      .map(([key, items]) => ({
        key,
        title: capabilityGroupTitles[key] ?? key,
        items: [...items].sort((a, b) => a.title.localeCompare(b.title, 'ru'))
      }))
      .sort(
        (a, b) => capabilityGroupOrder.indexOf(a.key) - capabilityGroupOrder.indexOf(b.key)
      );
  });

  let openGroups = $state<Record<string, boolean>>({});

  function groupKey(level: number, key: string): string {
    return `${level}:${key}`;
  }

  function toggleGroup(level: number, key: string): void {
    const id = groupKey(level, key);
    openGroups = { ...openGroups, [id]: !(openGroups[id] ?? false) };
  }

  function allGroupsOpen(level: number): boolean {
    return catalogGroups.every((group) => openGroups[groupKey(level, group.key)] ?? false);
  }

  function toggleAllGroups(level: number): void {
    const next = !allGroupsOpen(level);
    const updated = { ...openGroups };

    for (const group of catalogGroups) {
      updated[groupKey(level, group.key)] = next;
    }

    openGroups = updated;
  }

  function resetRole(role: RoleInfo): void {
    roleDraft = { ...roleDraft, [role.level]: [...role.capabilities] };
  }

  async function saveRoleDraft(role: RoleInfo): Promise<void> {
    roleBusy = role.level;
    roleError = '';
    roleNotice = '';

    try {
      const data = await saveRole(role.level, roleSelected(role));
      roles = data.items;
      roleCatalog = data.catalog;
      roleDraft = {
        ...roleDraft,
        [role.level]: [...(data.items.find((item) => item.level === role.level)?.capabilities ?? [])]
      };
      roleNotice = `Права роли «${role.title}» сохранены`;
    } catch (cause) {
      roleError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить права роли';
    } finally {
      roleBusy = 0;
    }
  }

  function userStateLabel(user: AdminUser): string {
    if (!user.active) {
      return user.reg_state === 'pending' ? 'ожидает' : 'заблокирован';
    }

    return 'активен';
  }
</script>

<svelte:element this={embedded ? 'div' : 'section'} class="page">
  {#if !embedded}
    <div class="page-hero">
      <div class="head">
        <h1>Администрирование</h1>
      </div>
    </div>

    <div class="tabs tab-scroll">
      {#if canManageRoles}
        <button type="button" class:active={tab === 'modules'} onclick={() => void openTab('modules')}>Модули</button>
      {/if}
      <button type="button" class:active={tab === 'roles'} onclick={() => void openTab('roles')}>Роли</button>
      {#if auth.can('settings.manage')}
        <button type="button" class:active={tab === 'docs'} onclick={() => void openTab('docs')}>Документы</button>
      {/if}
    </div>
  {/if}

  {#if error}<div class="alert">{error}</div>{/if}
  {#if message}<div class="notice">{message}</div>{/if}

  {#if tab === 'modules'}
    {#if modulesError}<div class="alert">{modulesError}</div>{/if}
    {#if modulesNotice}<div class="notice">{modulesNotice}</div>{/if}

    {#if modulesLoading}
      <div class="center"><Spinner /></div>
    {:else}
      <p class="hint">
        Выключенный модуль скрывается в интерфейсе и блокируется на сервере. В группах доступа выберите
        роли, которым доступны отдельные возможности модуля. Изменения применяются после сохранения.
      </p>

      <div class="modules">
        {#each sortedModules as module (module.code)}
          <div
            class="module-card"
            class:off={!module.enabled}
            class:has-groups={module.groups.length > 1}
          >
            <div class="module-head">
              <span class="module-title">{module.title}</span>
              <div class="module-actions">
                {#if module.capabilities.length > 0}
                  <HelpTip
                    text="Выберите роли для каждой группы доступа. Пустой список скрывает возможность у всех. Изменения применяются после сохранения."
                  />
                {/if}
                <label
                  class="switch"
                  title={module.enabled ? 'Выключить модуль' : 'Включить модуль'}
                >
                  <input
                    type="checkbox"
                    checked={module.enabled}
                    disabled={!canManageRoles}
                    aria-label={module.enabled ? `Выключить модуль «${module.title}»` : `Включить модуль «${module.title}»`}
                    onchange={() => toggleModuleEnabled(module.code)}
                  />
                  <span class="switch-track" aria-hidden="true"></span>
                </label>
              </div>
            </div>

            <p class="module-desc">{module.description}</p>

            {#if module.groups.length > 1}
              <div class="module-groups" class:off={!module.enabled}>
                {#each module.groups as group (group.code)}
                  <div class="module-group">
                    <span class="module-group-title">{group.title}</span>
                    {#if group.description}
                      <p class="module-group-desc">{group.description}</p>
                    {/if}
                    <MultiSelect
                      label={group.title}
                      options={moduleRoleOptions}
                      values={group.levels.map(String)}
                      onchange={(values) => setModuleGroupLevels(module.code, group.code, values)}
                      allLabel="Все роли"
                      emptyLabel="Нет ролей"
                      emptyValuesLabel="Никому"
                      maxHeight="240px"
                      showCount
                      disabled={!canManageRoles || !module.enabled}
                    />
                  </div>
                {/each}
              </div>
            {:else if module.capabilities.length > 0}
              <div class="module-roles" class:off={!module.enabled}>
                <span class="module-roles-label">Доступные роли</span>
                <MultiSelect
                  label="Доступные роли"
                  options={moduleRoleOptions}
                  values={module.levels.map(String)}
                  onchange={(values) => setModuleLevels(module.code, values)}
                  allLabel="Все роли"
                  emptyLabel="Нет ролей"
                  emptyValuesLabel="Никому"
                  maxHeight="240px"
                  showCount
                  disabled={!canManageRoles || !module.enabled}
                />
              </div>
            {/if}
          </div>
        {/each}
      </div>

      {#if !canManageRoles}
        <p class="hint">Менять права ролей может только технический администратор.</p>
      {/if}

      <p class="hint">
        Настройки системы и журнал закреплены за техническим администратором и не входят в модули.
      </p>

      <div class="actions">
        <Button loading={modulesBusy} onclick={() => void saveModulesDraft()}>Сохранить</Button>
      </div>
    {/if}
  {/if}

  {#if tab === 'users' && canManageUsers}
    <div class="filters">
      <form
        class="search"
        onsubmit={(event) => {
          event.preventDefault();
          submitUsersSearch();
        }}
      >
        <SearchInput
          bind:value={userQuery}
          historyKey="admin-users"
          placeholder="Поиск: имя, логин, e-mail, телефон, ИНН"
          onclear={() => {
            usersPage = 1;
            void loadUsers();
          }}
          onpick={submitUsersSearch}
        />
        <Button type="submit" variant="ghost">Найти</Button>
      </form>

      <FiltersModal
        count={countActive(userFilters, userFilterDefaults)}
        onopen={() => (userDraft = { ...userFilters })}
        onapply={applyUserFilterDraft}
        onreset={resetUserFilters}
      >
        <label class="filter-field">
          <span>Роль</span>
          <select bind:value={userDraft.level}>
            <option value={0}>Все роли</option>
            <option value={90}>Технический администратор</option>
            <option value={50}>Администратор</option>
            <option value={10}>Менеджер</option>
                <option value={5}>Клиент</option>
          </select>
        </label>

        <label class="filter-field">
          <span>Состояние</span>
          <select bind:value={userDraft.state}>
            <option value="">Все состояния</option>
            <option value="active">Активные</option>
            <option value="blocked">Заблокированные</option>
            <option value="pending">Ожидают подтверждения</option>
          </select>
        </label>
      </FiltersModal>

      <Button onclick={() => (showCreate = !showCreate)} disabled={appSettings.demoMode}>
        {showCreate ? 'Отменить' : 'Создать пользователя'}
      </Button>
    </div>

    {#if appSettings.demoMode}
      <div class="alert">В демо-режиме управление пользователями (создание, правка, пароли, блокировка) недоступно.</div>
    {/if}

    {#if showCreate && !appSettings.demoMode}
      <div class="card form">
        <h2>Новый пользователь</h2>
        <div class="grid">
          <label><span>ФИО</span><input bind:value={form.name} /></label>
          <label><span>E-mail</span><input bind:value={form.email} /></label>
          <label><span>Телефон</span><input bind:value={form.phone} /></label>
          <label><span>Компания</span><input bind:value={form.company} /></label>
          <label><span>ИНН</span><input bind:value={form.inn} /></label>
          <label><span>Должность</span><input bind:value={form.position} /></label>
          <label>
            <span>Роль</span>
            {#if canManageRoles}
              <select bind:value={form.level}>
                <option value={5}>Клиент</option>
                <option value={10}>Менеджер</option>
                <option value={50}>Администратор</option>
                <option value={90}>Технический администратор</option>
              </select>
            {:else}
              <span class="readonly-value">{levelTitles[Number(form.level)]}</span>
            {/if}
          </label>
        </div>
        <div class="actions">
          <Button loading={busy} onclick={() => void submitCreate()}>Создать</Button>
        </div>
      </div>
    {/if}

    {#if editing && !appSettings.demoMode}
      <div class="card form">
        <h2>Изменение: {editing.name}</h2>
        <div class="grid">
          <label><span>ФИО</span><input bind:value={editForm.name} /></label>
          <label><span>Телефон</span><input bind:value={editForm.phone} /></label>
          <label><span>Компания</span><input bind:value={editForm.company} /></label>
          <label><span>ИНН</span><input bind:value={editForm.inn} /></label>
          <label><span>Должность</span><input bind:value={editForm.position} /></label>
          <label>
            <span>Роль</span>
            {#if canManageRoles}
              <select bind:value={editForm.level}>
                <option value={5}>Клиент</option>
                <option value={10}>Менеджер</option>
                <option value={50}>Администратор</option>
                <option value={90}>Технический администратор</option>
              </select>
            {:else}
              <span class="readonly-value">{levelTitles[Number(editForm.level)]}</span>
            {/if}
          </label>
        </div>
        <div class="actions">
          {#if pricesEnabled}
            <Button variant="ghost" onclick={() => (pricePicker = editing)}>Типы цен…</Button>
          {/if}
          <Button loading={busy} onclick={() => void submitEdit()}>Сохранить</Button>
          <Button variant="ghost" onclick={() => (editing = null)}>Отмена</Button>
        </div>
      </div>
    {/if}

    {#if usersLoading}
      <div class="center"><Spinner size={26} /></div>
    {:else}
      <div class="users">
        {#each users as user (user.id)}
          <div class="user" class:blocked={!user.active}>
            <div class="info">
              <div class="row">
                <span class="name">{user.name}</span>
                <span class="role">{levelTitles[user.level] ?? user.level}</span>
                <span class="state" class:off={!user.active}>{userStateLabel(user)}</span>
              </div>
              <div class="meta">
                <span>{user.login}</span>
                {#if user.company}<span>{user.company}</span>{/if}
                {#if user.inn}<span>ИНН {user.inn}</span>{/if}
                {#if user.phone}<span>{user.phone}</span>{/if}
                {#if pricesEnabled && user.price_types.length > 0}
                  <span>цены: {user.price_types.map((type) => type.title).join(', ')}</span>
                {/if}
                {#if user.last_seen_at}<span>был(а) {formatDateTime(user.last_seen_at)}</span>{/if}
              </div>
            </div>
            <div class="row-actions">
              {#if !appSettings.demoMode}
                <Button variant="ghost" onclick={() => startEdit(user)}>Изменить</Button>
                {#if pricesEnabled}
                  <Button variant="ghost" onclick={() => (pricePicker = user)}>Типы цен</Button>
                {/if}
                <Button variant="ghost" onclick={() => void resetPassword(user)}>Сбросить пароль</Button>
                <Button variant="ghost" onclick={() => void toggleBlock(user)}>
                  {user.active ? 'Заблокировать' : 'Разблокировать'}
                </Button>
              {/if}
            </div>
          </div>
        {/each}
      </div>

      <Pager
        page={usersPage}
        perPage={usersPerPage}
        total={usersTotal}
        loading={usersLoading}
        onchange={(next) => {
          usersPage = next;
          void loadUsers();
        }}
        onperpage={(next) => {
          usersPerPage = next;
          usersPage = 1;
          void loadUsers();
        }}
      />
    {/if}
  {/if}

  <PriceTypesPicker
    open={pricePicker !== null}
    userId={pricePicker?.id ?? 0}
    userName={pricePicker?.name ?? ''}
    assigned={pricePicker?.price_types ?? []}
    onclose={() => (pricePicker = null)}
    onsaved={(assigned) => onPriceTypesSaved(pricePicker?.id ?? 0, assigned)}
  />

  {#if tab === 'docs'}
    <div class="docs">
      <div class="doc-tabs tab-scroll">
        {#each docs as document (document.code)}
          <button
            type="button"
            class:active={document.code === docCode}
            onclick={() => selectDoc(document)}
          >
            {document.title}
          </button>
        {/each}
      </div>

      {#if docCode}
        <div class="card form">
          <label><span>Заголовок</span><input bind:value={docTitle} /></label>
          <label>
            <span>Текст документа (редактируется администратором)</span>
            <textarea bind:value={docBody} rows="18"></textarea>
          </label>
          {#if docPlaceholders.length > 0}
            <div class="alert">
              В документе остались незаполненные реквизиты:
              {docPlaceholders.join(', ')}. Замените их данными оператора — для 152-ФЗ политика
              должна содержать наименование, адрес и контакты.
            </div>
          {/if}
          {#if docNotice}<div class="notice">{docNotice}</div>{/if}
          <div class="actions">
            <Button loading={docBusy} onclick={() => void saveDoc()}>Сохранить</Button>
            <Button variant="ghost" onclick={() => router.navigate(`/legal/${docCode}`)}>Открыть страницу</Button>
          </div>
        </div>
      {/if}
    </div>
  {/if}

  {#if tab === 'roles'}
    {#if roleError}
      <div class="alert">{roleError}</div>
    {/if}
    {#if roleNotice}
      <div class="notice">{roleNotice}</div>
    {/if}

    <div class="roles">
      {#each roles as role (role.level)}
        {@const selected = roleSelected(role)}
        <div class="card">
          <div class="role-head">
            <h2>{role.title} <span class="level">уровень {role.level}</span></h2>
            {#if canManageRoles}
              <div class="role-actions">
                <span class="role-count">{selected.length} из {roleCatalog.length}</span>
                <Button variant="ghost" onclick={() => toggleAllGroups(role.level)}>
                  {allGroupsOpen(role.level) ? 'Свернуть всё' : 'Развернуть всё'}
                </Button>
                <Button variant="ghost" disabled={!isRoleDirty(role)} onclick={() => resetRole(role)}>
                  Сбросить
                </Button>
                <Button
                  loading={roleBusy === role.level}
                  disabled={!isRoleDirty(role)}
                  onclick={() => void saveRoleDraft(role)}
                >
                  Сохранить
                </Button>
              </div>
            {/if}
          </div>

          {#if canManageRoles}
            <div class="caps-edit">
              {#each catalogGroups as group (group.key)}
                {@const isOpen = openGroups[groupKey(role.level, group.key)] ?? false}
                {@const groupSelected = group.items.filter((item) => selected.includes(item.code)).length}
                <div class="cap-group" class:open={isOpen}>
                  <button
                    type="button"
                    class="cap-group-head"
                    aria-expanded={isOpen}
                    onclick={() => toggleGroup(role.level, group.key)}
                  >
                    <span class="chev" class:open={isOpen} aria-hidden="true"></span>
                    <span class="cap-group-title">{group.title}</span>
                    <span class="cap-group-count">{groupSelected} из {group.items.length}</span>
                  </button>

                  {#if isOpen}
                    <div class="cap-group-body">
                      {#each group.items as capability (capability.code)}
                        {@const core = role.level >= 90 && adminCoreCapabilities.includes(capability.code)}
                        <label class="cap-check" class:core title={core ? 'Обязательное право администратора' : ''}>
                          <input
                            type="checkbox"
                            checked={selected.includes(capability.code)}
                            disabled={core}
                            onchange={() => toggleCapability(role, capability.code)}
                          />
                          <span class="cap-title">{capability.title}</span>
                          <code>{capability.code}</code>
                        </label>
                      {/each}
                    </div>
                  {/if}
                </div>
              {/each}
            </div>
          {:else}
            <div class="caps">
              {#each role.capabilities as capability (capability)}
                <span class="cap">{capability}</span>
              {/each}
            </div>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</svelte:element>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .modules {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .module-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    transition: opacity 0.15s ease, background 0.15s ease;
  }

  .module-card.off {
    background: var(--fill-tertiary);
    border-color: var(--border-secondary);
  }

  .module-card.has-groups {
    grid-column: 1 / -1;
  }

  .module-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .module-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    transition: color 0.15s ease;
  }

  .module-card.off .module-title {
    color: var(--text-description);
  }

  .module-actions {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    flex: 0 0 auto;
  }

  .module-desc {
    margin: 0;
    font-size: 13px;
    line-height: 1.45;
    color: var(--text-description);
  }

  .module-roles {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    margin-top: auto;
  }

  .module-roles.off,
  .module-groups.off {
    opacity: 0.55;
  }

  .module-roles-label {
    font-size: 13px;
    color: var(--text-description);
  }

  .module-groups {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--space-3);
    margin-top: auto;
  }

  .module-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
  }

  .module-group-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    transition: color 0.15s ease;
  }

  .module-card.off .module-group-title {
    color: var(--text-description);
  }

  .module-group-desc {
    margin: 0;
    font-size: 12px;
    line-height: 1.4;
    color: var(--text-description);
  }

  .switch {
    position: relative;
    flex: 0 0 40px;
    width: 40px;
    height: 22px;
  }

  .switch input {
    position: absolute;
    inset: 0;
    z-index: 1;
    margin: 0;
    opacity: 0;
    cursor: pointer;
  }

  .switch-track {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: var(--border);
    transition: background 0.15s ease;
  }

  .switch-track::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    transition: transform 0.15s ease;
  }

  .switch input:checked + .switch-track {
    background: var(--primary);
  }

  .switch input:checked + .switch-track::after {
    transform: translateX(18px);
  }

  .switch input:focus-visible + .switch-track {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  .switch input:disabled {
    cursor: not-allowed;
  }

  .switch input:disabled + .switch-track {
    opacity: 0.55;
  }

  @media (max-width: 980px) {
    .modules {
      grid-template-columns: 1fr;
    }
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-2);
    font-size: 16px;
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .tabs button {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
  }

  .search {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    flex: 1 1 260px;
    min-width: 0;
    max-width: 420px;
  }

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
      max-width: none;
    }
  }

  .filters select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
  }


  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-3);
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  label input,
  label select,
  label textarea {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: inherit;
    background: var(--surface);
    color: var(--text);
  }

  label textarea {
    resize: vertical;
    line-height: 1.5;
  }

  .readonly-value {
    display: flex;
    align-items: center;
    min-height: 34px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
    color: var(--text);
    font: inherit;
  }

  .docs {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .doc-tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .doc-tabs button {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .doc-tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .users {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .user {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    transition: background 0.12s ease, border-color 0.12s ease;
  }

  .user:hover {
    background: var(--bg);
    border-color: var(--primary);
  }

  .user.blocked {
    opacity: 0.7;
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .name {
    font-weight: 500;
  }

  .role {
    font-size: 12px;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 1px 8px;
  }

  .state {
    font-size: 12px;
    color: #1e6b3a;
  }

  .state.off {
    color: #8c1d18;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .row-actions {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .roles {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .level {
    font-size: 12px;
    color: var(--muted);
    font-weight: 400;
  }

  .caps {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .cap {
    font-size: 12px;
    padding: 2px 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--muted);
  }

  .role-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
    margin-bottom: var(--space-2);
  }

  .role-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .role-count {
    font-size: 12px;
    color: var(--muted);
  }

  .caps-edit {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cap-group {
    border-bottom: 1px solid var(--border);
  }

  .cap-group:last-child {
    border-bottom: none;
  }

  .cap-group-head {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .cap-group-head:hover {
    color: var(--primary);
  }

  .cap-group-title {
    flex: 1;
    font-weight: 500;
    font-size: 13px;
  }

  .cap-group-count {
    font-size: 12px;
    color: var(--muted);
  }

  .chev {
    width: 7px;
    height: 7px;
    border: solid currentColor;
    border-width: 0 1.5px 1.5px 0;
    transform: rotate(-45deg);
    transition: transform 0.15s ease;
  }

  .chev.open {
    transform: rotate(45deg);
  }

  .cap-group-body {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 6px 16px;
    padding: 2px 0 10px 17px;
  }

  .cap-check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    padding: 4px 0;
    cursor: pointer;
  }

  .cap-check.core {
    cursor: not-allowed;
    opacity: 0.75;
  }

  .cap-check input {
    flex: none;
  }

  .cap-title {
    flex: 1;
  }

  .cap-check code {
    font-size: 11px;
    color: var(--muted);
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #e7f5ec;
    color: #1e6b3a;
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

</style>
