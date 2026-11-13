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
  import Icon from '../lib/components/ui/Icon.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import PriceTypesPicker from '../lib/components/ui/PriceTypesPicker.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';

  type AdminSection = 'modules' | 'roles' | 'docs';

  let {
    section = null,
    onsaved,
    moduleCode = null,
    onmoduleschange
  }: {
    section?: AdminSection | null;
    onsaved?: () => void;
    moduleCode?: string | null;
    onmoduleschange?: (modules: ModuleItem[]) => void;
  } = $props();

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
  let modulesBaseline = $state('');
  let modulesSaved = $state(false);

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
  const visibleModules = $derived(
    moduleCode === null
      ? sortedModules
      : sortedModules.filter((module) => module.code === moduleCode)
  );
  const moduleRoleOptions = $derived(
    moduleRoles.map((role) => ({ value: String(role.level), label: role.title }))
  );
  const modulesDirty = $derived(modulesBaseline !== JSON.stringify(modules));
  let modulesLoading = $state(false);
  let modulesBusy = $state(false);
  let modulesNotice = $state('');
  let modulesError = $state('');

  const canManageUsers = $derived(auth.can('users.manage'));
  const canManageRoles = $derived(auth.can('roles.manage'));
  const pricesEnabled = $derived(appSettings.pricesConfigured);

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
    modulesSaved = false;

    try {
      const data = await listModules();
      modules = data.modules;
      moduleRoles = data.roles;
      modulesBaseline = JSON.stringify(data.modules);
      onmoduleschange?.(data.modules);
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить модули';
    } finally {
      modulesLoading = false;
    }
  }

  function toggleModuleEnabled(code: string): boolean {
    const current = modules.find((module) => module.code === code);

    if (code === 'warehouses' || !current || !canManageRoles) {
      return false;
    }

    const message = current.enabled
      ? `Выключить модуль «${current.title}»? Пользователи потеряют доступ к разделу.`
      : `Включить модуль «${current.title}»? Доступ к разделу появится у выбранных ролей.`;
    const warning =
      code === 'warehouses' && current.enabled
        ? ' Складские остатки и связи с заявками сохранятся.'
        : '';

    if (!confirm(message + warning)) {
      return false;
    }

    const nextModules = modules.map((module) =>
      module.code === code ? { ...module, enabled: !module.enabled } : module
    );
    modules = nextModules;
    modulesNotice = '';
    modulesSaved = false;
    onmoduleschange?.(nextModules);
    return true;
  }

  function setModuleSetting(code: string, key: string, value: string | number | boolean | number[]): void {
    modules = modules.map((module) =>
      module.code === code
        ? { ...module, settings: { ...module.settings, [key]: value } }
        : module
    );
    modulesNotice = '';
    modulesSaved = false;
  }

  function setModuleLevels(code: string, values: string[]): void {
    const levels = values
      .map(Number)
      .filter((level) => Number.isFinite(level));

    modules = modules.map((module) =>
      module.code === code ? { ...module, levels } : module
    );
    modulesNotice = '';
    modulesSaved = false;
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
    modulesSaved = false;
  }

  function moveModuleGroup(code: string, index: number, direction: -1 | 1): void {
    if (!canManageRoles) {
      return;
    }

    modules = modules.map((module) => {
      if (module.code !== code) {
        return module;
      }

      const target = index + direction;

      if (target < 0 || target >= module.groups.length) {
        return module;
      }

      const groups = [...module.groups];
      [groups[index], groups[target]] = [groups[target], groups[index]];

      return { ...module, groups };
    });
    modulesNotice = '';
    modulesSaved = false;
  }

  function resetModulesDraft(): void {
    if (!modulesBaseline) {
      return;
    }

    const nextModules = JSON.parse(modulesBaseline) as ModuleItem[];
    modules = nextModules;
    modulesNotice = '';
    modulesSaved = false;
    onmoduleschange?.(nextModules);
  }

  async function saveModulesDraft(): Promise<void> {
    modulesBusy = true;
    modulesError = '';
    modulesNotice = '';
    modulesSaved = false;

    try {
      const data = await saveModules(modules);
      modules = data.modules;
      moduleRoles = data.roles;
      modulesBaseline = JSON.stringify(data.modules);
      modulesNotice = 'Сохранено';
      modulesSaved = true;
      onmoduleschange?.(data.modules);
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
      <div class="module-page" class:embedded>
        {#each visibleModules as module (module.code)}
          {@const moduleSettings = module.settings ?? {}}
          <div
            class="module-card"
            class:off={!module.enabled}
            class:has-groups={module.groups.length > 0}
          >
            <div class="module-head">
              <div class="module-heading">
                <div class="module-title-row">
                  <span class="module-title">{module.title}</span>
                  <span class="module-status" class:on={module.enabled}>
                    {module.enabled ? 'Включён' : 'Отключён'}
                  </span>
                  {#if module.code === 'warehouses'}
                    <span class="module-required-badge">Обязательный</span>
                  {/if}
                </div>
                <p class="module-desc">{module.description}</p>
              </div>

              <div class="module-actions">
                {#if module.capabilities.length > 0}
                  <HelpTip
                    text="Роли определяют, какие действия доступны сотрудникам. Изменения вступают в силу после сохранения."
                  />
                {/if}
                <label
                  class="switch"
                  title={module.code === 'warehouses' ? 'Модуль «Склады» нельзя отключить' : module.enabled ? 'Выключить модуль' : 'Включить модуль'}
                >
                  <input
                    type="checkbox"
                    checked={module.enabled}
                    disabled={!canManageRoles || module.code === 'warehouses'}
                    aria-label={module.code === 'warehouses' ? 'Модуль «Склады» обязателен' : module.enabled ? `Выключить модуль ${module.title}` : `Включить модуль ${module.title}`}
                    onchange={(event) => {
                      const input = event.currentTarget as HTMLInputElement;

                      if (!toggleModuleEnabled(module.code)) {
                        input.checked = !input.checked;
                      }
                    }}
                  />
                  <span class="switch-track" aria-hidden="true"></span>
                </label>
              </div>
            </div>

            {#if !module.enabled}
              <div class="module-off-note">
                Модуль отключён. Настройки доступа сохраняются, но не применяются.
              </div>
            {/if}

            {#if module.code === 'requests' || module.code === 'stock_edit' || module.code === 'mail'}
              <div class="module-settings">
                <div class="module-access-head">
                  <h3>
                    {module.code === 'requests'
                      ? 'Параметры заявок'
                      : module.code === 'stock_edit'
                        ? 'Параметры номенклатуры'
                        : 'Параметры почты'}
                  </h3>
                </div>

                {#if module.code === 'requests'}
                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['sales.enabled'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'sales.enabled',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Оформление заявок и черновиков</span>
                  </label>

                  <div class="module-settings-grid">
                    <label class="module-setting-field">
                      <span>SLA: срок реакции, часов</span>
                      <input
                        type="number"
                        min="1"
                        max="168"
                        value={Number(moduleSettings['sla.reaction_hours'] ?? 2)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'sla.reaction_hours',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                    <label class="module-setting-field">
                      <span>SLA: срок решения, часов</span>
                      <input
                        type="number"
                        min="1"
                        max="720"
                        value={Number(moduleSettings['sla.resolution_hours'] ?? 24)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'sla.resolution_hours',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                  </div>

                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['stock.reserve_enabled'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'stock.reserve_enabled',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Уменьшать остатки складов при оформлении заявки</span>
                  </label>

                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['stock.allow_zero'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'stock.allow_zero',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Разрешать нулевой и отрицательный остаток</span>
                  </label>
                {:else if module.code === 'stock_edit'}
                  <div class="module-settings-grid">
                    <label class="module-setting-field">
                      <span>Формат фото</span>
                      <select
                        value={String(moduleSettings['stocks.photo_ratio'] ?? 'square')}
                        disabled={!canManageRoles || !module.enabled}
                        onchange={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_ratio',
                            event.currentTarget.value
                          )}
                      >
                        <option value="dynamic">По фото</option>
                        <option value="square">Квадрат 1:1</option>
                        <option value="landscape">Горизонтальный 4:3</option>
                        <option value="portrait">Вертикальный 3:4</option>
                      </select>
                    </label>
                    <label class="module-setting-field">
                      <span>Несовпадение пропорций</span>
                      <select
                        value={String(moduleSettings['stocks.photo_fit'] ?? 'contain')}
                        disabled={!canManageRoles || !module.enabled}
                        onchange={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_fit',
                            event.currentTarget.value
                          )}
                      >
                        <option value="contain">Вписывать без обрезки</option>
                        <option value="cover">Заполнять с обрезкой</option>
                      </select>
                    </label>
                  </div>

                  <div class="module-settings-grid">
                    <label class="module-setting-field">
                      <span>Фото: превью, px</span>
                      <input
                        type="number"
                        min="80"
                        max="4000"
                        value={Number(moduleSettings['stocks.photo_size_preview'] ?? 160)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_size_preview',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                    <label class="module-setting-field">
                      <span>Фото: карточка, px</span>
                      <input
                        type="number"
                        min="80"
                        max="4000"
                        value={Number(moduleSettings['stocks.photo_size_card'] ?? 600)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_size_card',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                    <label class="module-setting-field">
                      <span>Фото: максимум, px</span>
                      <input
                        type="number"
                        min="80"
                        max="4000"
                        value={Number(moduleSettings['stocks.photo_size_max'] ?? 1600)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_size_max',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                  </div>
                {:else}
                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['mail.export_enabled'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'mail.export_enabled',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Разрешить экспорт на электронную почту</span>
                  </label>

                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['mail.spf_checklist'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'mail.spf_checklist',
                          event.currentTarget.checked
                        )}
                    />
                    <span>SPF/DKIM/DMARC для домена настроены</span>
                  </label>
                {/if}
              </div>
            {/if}

            {#if module.groups.length > 0}
              <div class="module-access">
                <div class="module-access-head">
                  <h3>
                    {module.groups.length === 1 ? 'Группа доступа' : 'Группы доступа'}
                  </h3>
                </div>

                <div
                  class="module-groups"
                  class:off={!module.enabled}
                >
                  {#if module.code === 'stock_edit'}
                    <div class="module-group">
                      <div class="module-group-head">
                        <div class="module-group-heading">
                          <span class="module-group-title">Доступ к ценам</span>
                          <p class="module-group-desc">
                            Роли, которым показываются цены и доступны операции с ними.
                          </p>
                        </div>
                      </div>
                      <MultiSelect
                        label="Доступ к ценам"
                        options={moduleRoleOptions}
                        values={(Array.isArray(moduleSettings['prices.levels'])
                          ? moduleSettings['prices.levels']
                          : []
                        ).map(String)}
                        onchange={(values) =>
                          setModuleSetting(module.code, 'prices.levels', values.map(Number))}
                        allLabel="Все роли"
                        emptyLabel="Никому"
                        emptyValuesLabel="Никому"
                        maxHeight="240px"
                        disabled={!canManageRoles || !module.enabled}
                      />
                    </div>

                    <div class="module-group">
                      <div class="module-group-head">
                        <div class="module-group-heading">
                          <span class="module-group-title">Доступ к группам</span>
                          <p class="module-group-desc">
                            Роли, которым показываются группы и доступна фильтрация по ним.
                          </p>
                        </div>
                      </div>
                      <MultiSelect
                        label="Доступ к группам"
                        options={moduleRoleOptions}
                        values={(Array.isArray(moduleSettings['groups.levels'])
                          ? moduleSettings['groups.levels']
                          : []
                        ).map(String)}
                        onchange={(values) =>
                          setModuleSetting(module.code, 'groups.levels', values.map(Number))}
                        allLabel="Все роли"
                        emptyLabel="Никому"
                        emptyValuesLabel="Никому"
                        maxHeight="240px"
                        disabled={!canManageRoles || !module.enabled}
                      />
                    </div>
                  {/if}

                  {#each module.groups as group, index (group.code)}
                    <div class="module-group">
                      <div class="module-group-head">
                        <div class="module-group-heading">
                          <span class="module-group-title">{group.title}</span>
                          {#if group.description}
                            <p class="module-group-desc">{group.description}</p>
                          {/if}
                        </div>
                        <div class="module-group-order">
                          <button
                            type="button"
                            class="module-order-btn"
                            title="Выше"
                            aria-label="Выше"
                            disabled={!canManageRoles || index === 0}
                            onclick={() => moveModuleGroup(module.code, index, -1)}
                          >
                            <Icon name="arrow-up" size={14} />
                          </button>
                          <button
                            type="button"
                            class="module-order-btn"
                            title="Ниже"
                            aria-label="Ниже"
                            disabled={!canManageRoles || index === module.groups.length - 1}
                            onclick={() => moveModuleGroup(module.code, index, 1)}
                          >
                            <Icon name="arrow-down" size={14} />
                          </button>
                        </div>
                      </div>

                      <MultiSelect
                        label={group.title}
                        options={moduleRoleOptions}
                        values={group.levels.map(String)}
                        onchange={(values) => setModuleGroupLevels(module.code, group.code, values)}
                        allLabel="Все роли"
                        emptyLabel="Нет ролей"
                        emptyValuesLabel="Нет ролей"
                        maxHeight="240px"
                        disabled={!canManageRoles || !module.enabled}
                      />
                    </div>
                  {/each}
                </div>
              </div>
            {:else if module.capabilities.length > 0}
              <div class="module-access">
                <div class="module-access-head">
                  <h3>Доступ по ролям</h3>
                </div>

                <div
                  class="module-single-access"
                  class:off={!module.enabled}
                >
                  <div class="module-group module-group-single">
                    <div class="module-group-head">
                      <div class="module-group-heading">
                        <span class="module-group-title">Роли с доступом</span>
                        <p class="module-group-desc">
                          Отметьте роли, которым доступен модуль.
                        </p>
                      </div>
                    </div>

                    <MultiSelect
                      label="Роли с доступом"
                      options={moduleRoleOptions}
                      values={module.levels.map(String)}
                      onchange={(values) => setModuleLevels(module.code, values)}
                      allLabel="Все роли"
                      emptyLabel="Нет ролей"
                      emptyValuesLabel="Нет ролей"
                      maxHeight="240px"
                      disabled={!canManageRoles || !module.enabled}
                    />
                  </div>
                </div>
              </div>
            {/if}
          </div>
        {/each}

        {#if visibleModules.length === 0}
          <p class="hint">Модуль не найден</p>
        {/if}
      </div>

      {#if !canManageRoles}
        <p class="hint">Изменять доступ к модулям могут только администраторы.</p>
      {/if}

      <div
        class="module-footer"
      >
        <span class="module-save-state" class:dirty={modulesDirty}>
          {#if modulesDirty}
            Есть несохранённые изменения
          {:else if modulesSaved}
            Изменения сохранены
          {/if}
        </span>
        <div class="module-footer-actions">
          {#if modulesDirty}
            <Button variant="ghost" disabled={modulesBusy} onclick={resetModulesDraft}>
              Отменить
            </Button>
          {/if}
          <Button loading={modulesBusy} disabled={!modulesDirty} onclick={() => void saveModulesDraft()}>
            Сохранить
          </Button>
        </div>
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

  .module-page {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .module-page.embedded {
    grid-template-columns: minmax(0, 1fr);
  }

  @media (max-width: 720px) {
    .module-page {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .module-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-5);
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
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    padding-bottom: var(--space-3);
    border-bottom: 1px solid var(--border-secondary);
  }

  .module-heading {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }

  .module-title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }

  .module-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    transition: color 0.15s ease;
  }

  .module-card.off .module-title {
    color: var(--text-description);
  }

  .module-status {
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--fill-tertiary);
    color: var(--text-description);
    font-size: 11px;
    font-weight: 500;
  }

  .module-status.on {
    background: var(--success-bg);
    color: var(--success-text);
  }
  .module-required-badge {
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--primary-bg);
    color: var(--primary);
    font-size: 11px;
    font-weight: 500;
  }

  .module-off-note {
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    background: var(--warning-bg);
    color: var(--warning-text);
    font-size: 12px;
    line-height: 1.4;
  }

  .module-actions {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    flex: 0 0 auto;
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

  .module-desc {
    margin: 0;
    font-size: 13px;
    line-height: 1.45;
    color: var(--text-description);
  }

  .module-settings {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .module-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
  }

  .module-check {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-size: 13px;
    cursor: pointer;
  }

  .module-check input {
    width: 16px;
    height: 16px;
    accent-color: var(--primary);
  }

  .module-setting-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: var(--text-description);
    font-size: 12px;
  }

  .module-setting-field input,
  .module-setting-field select {
    width: 100%;
    height: 34px;
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 13px;
    box-sizing: border-box;
  }

  .module-setting-field input:focus,
  .module-setting-field select:focus {
    box-shadow: 0 0 0 2px var(--focus-ring);
    border-color: var(--primary);
    outline: none;
  }

  .module-setting-field input:disabled,
  .module-setting-field select:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  .module-access {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .module-access-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .module-access-head h3 {
    margin: 0;
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
  }

  .module-groups.off {
    opacity: 0.55;
  }

  .module-single-access {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-3);
    align-items: stretch;
  }

  .module-single-access.off {
    opacity: 0.55;
  }


  .module-groups {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-3);
  }

  .module-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .module-group-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .module-group-heading {
    display: flex;
    flex-direction: column;
    gap: 3px;
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
    max-width: 80ch;
    color: var(--text-description);
    font-size: 13px;
    line-height: 1.5;
  }

  .module-group-order {
    display: flex;
    flex: 0 0 auto;
    gap: 2px;
  }

  .module-order-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    cursor: pointer;
  }

  .module-order-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .module-order-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .module-footer {
    position: sticky;
    bottom: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-top: 1px solid var(--border-secondary);
    background: var(--surface);
  }

  .module-save-state {
    color: var(--muted);
    font-size: 12px;
  }

  .module-save-state.dirty {
    color: var(--warning-text);
  }

  .module-footer-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
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

  .filters select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
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
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  label input:focus,
  label select:focus,
  label textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
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
    color: var(--success-text);
  }

  .state.off {
    color: var(--error-text);
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
    background: var(--success-bg);
    color: var(--success-text);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  /* Телефон: панель сохранения держим над нижней навигацией. */
  @media (max-width: 720px) {
    .module-footer {
      bottom: calc(60px + env(safe-area-inset-bottom));
    }
  }

</style>
