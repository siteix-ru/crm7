<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    blockStaff,
    changeStaffLogin,
    convertUser,
    getStaff,
    getStaffClients,
    getStaffHistory,
    getUserConversion,
    resetStaffPassword,
    revokeStaffSessions,
    staffLevelTitle,
    staffStateTitle,
    STAFF_LEVELS,
    unblockStaff,
    updateStaff,
    type StaffClient,
    type StaffHistoryItem,
    type StaffUser,
    type UserConversionPreview
  } from '../lib/api/staff';
  import { startThread } from '../lib/api/chat';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import { formatDateTime } from '../lib/format';
  import Button from '../lib/components/ui/Button.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import { tablist } from '../lib/a11y/tablist';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Select from '../lib/components/ui/Select.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  const editableFields = [
    { key: 'name', label: 'ФИО', maxlength: 255 },
    { key: 'position', label: 'Должность', maxlength: 255 },
    { key: 'department', label: 'Отдел', maxlength: 255 },
    { key: 'city', label: 'Город', maxlength: 128 },
    { key: 'office', label: 'Офис', maxlength: 128 },
    { key: 'email', label: 'E-mail', maxlength: 255 },
    { key: 'phone', label: 'Телефон', maxlength: 32 }
  ] as const;

  type EditableFieldKey = (typeof editableFields)[number]['key'];

  const historyLabels: Record<string, string> = {
    created: 'Сотрудник создан',
    updated: 'Данные изменены',
    blocked: 'Заблокирован',
    unblocked: 'Разблокирован',
    password_reset: 'Пароль сброшен',
    login_changed: 'Логин изменён',
    credentials_sent: 'Данные для входа отправлены',
    sessions_revoked: 'Завершены все сессии',
    profile_updated: 'Профиль обновлён',
    password_changed: 'Пароль изменён',
    avatar_updated: 'Аватар обновлён',
    avatar_deleted: 'Аватар удалён',
    converted_to_client: 'Переведён в клиенты',
    converted_to_staff: 'Переведён в сотрудники'
  };

  let staff = $state<StaffUser | null>(null);
  let loading = $state(true);
  let error = $state('');
  let actionError = $state('');
  let actionNotice = $state('');
  let tab = $state<'profile'>('profile');
  let historyOpen = $state(false);
  let history = $state<StaffHistoryItem[]>([]);
  let historyLoading = $state(false);
  let historyError = $state('');
  let clients = $state<StaffClient[]>([]);
  let clientsLoading = $state(true);
  let clientsError = $state('');
  let clientsOpen = $state(false);
  let confirmKind = $state<'' | 'block' | 'unblock' | 'reset' | 'sessions'>('');
  let busy = $state(false);
  let password = $state('');
  let passwordOpen = $state(false);
  let copied = $state(false);
  let chatBusy = $state(false);

  const canChat = $derived(
    Boolean(
      staff
      && appSettings.chatEnabled
      && auth.can('chat.use')
      && (auth.user?.level ?? 0) >= 10
      && auth.user?.id !== id
    )
  );

  let loginOpen = $state(false);
  let loginDraft = $state('');
  let loginError = $state('');
  let loginSaving = $state(false);

  let fieldOpen = $state(false);
  let fieldKey = $state<EditableFieldKey | null>(null);
  let fieldDraft = $state('');
  let fieldError = $state('');
  let fieldSaving = $state(false);

  let roleOpen = $state(false);
  let roleDraft = $state('');
  let roleError = $state('');
  let roleSaving = $state(false);

  let convertOpen = $state(false);
  let convertPreview = $state<UserConversionPreview | null>(null);
  let convertLoading = $state(false);
  let convertError = $state('');
  let convertSaving = $state(false);


  const canEdit = $derived(staff?.can.edit === true && !appSettings.demoMode);
  const canBlock = $derived(staff?.can.block === true && !appSettings.demoMode);
  const canReset = $derived(staff?.can.reset_password === true && !appSettings.demoMode);
  const canChangeLogin = $derived(staff?.can.change_login === true && !appSettings.demoMode);
  const canRevokeSessions = $derived(
    staff?.can.revoke_sessions === true && !appSettings.demoMode
  );
  const canChangeRole = $derived(staff?.can.change_level === true && !appSettings.demoMode);
  const canConvertToClient = $derived(
    staff?.can.convert_to_client === true
    && auth.can('roles.manage')
    && auth.level >= 90
    && !appSettings.demoMode
  );
  const previewClients = $derived(clients.slice(0, 20));
  const hasSecurity = $derived(canChangeLogin || canReset || canRevokeSessions);
  const adminHint = $derived(
    staff?.is_last_superadmin
      ? 'Это последний активный технический администратор — его нельзя понизить или заблокировать.'
      : staff?.is_last_admin
        ? 'Это последний активный администратор — его нельзя заблокировать.'
        : ''
  );

  const confirmTitle = $derived(
    confirmKind === 'reset'
      ? 'Сбросить пароль'
      : confirmKind === 'sessions'
        ? 'Завершить все сессии'
        : confirmKind === 'block'
          ? 'Заблокировать сотрудника'
          : 'Разблокировать сотрудника'
  );

  function eventTone(event: string): '' | 'success' | 'danger' | 'warning' {
    if (event === 'unblocked' || event === 'converted_to_staff') {
      return 'success';
    }

    if (event === 'blocked' || event === 'converted_to_client') {
      return 'danger';
    }

    if (
      event === 'password_reset'
      || event === 'password_changed'
      || event === 'login_changed'
      || event === 'sessions_revoked'
      || event === 'profile_updated'
    ) {
      return 'warning';
    }

    return '';
  }

  onMount(() => {
    void load();
    void loadHistory();
    void loadClients();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      staff = await getStaff(id);
      roleDraft = String(staff.level);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сотрудника';
    } finally {
      loading = false;
    }
  }

  async function loadHistory(): Promise<void> {
    if (history.length > 0 || historyLoading) {
      return;
    }

    historyLoading = true;
    historyError = '';

    try {
      history = (await getStaffHistory(id)).items;
    } catch (cause) {
      historyError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить историю';
    } finally {
      historyLoading = false;
    }
  }

  async function openChat(): Promise<void> {
    if (!staff || chatBusy) {
      return;
    }

    chatBusy = true;
    actionError = '';

    try {
      const thread = await startThread(staff.id);
      router.navigate(`/chat?thread=${thread.id}`);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось открыть чат';
    } finally {
      chatBusy = false;
    }
  }

  async function openHistory(): Promise<void> {
    historyOpen = true;
    await loadHistory();
  }

  async function loadClients(): Promise<void> {
    clientsLoading = true;
    clientsError = '';

    try {
      clients = (await getStaffClients(id)).items;
    } catch (cause) {
      clientsError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиентов';
    } finally {
      clientsLoading = false;
    }
  }

  function openField(key: EditableFieldKey): void {
    if (staff === null) {
      return;
    }

    fieldKey = key;
    fieldDraft = staff[key];
    fieldError = '';
    fieldOpen = true;
  }

  async function saveField(): Promise<void> {
    if (staff === null || fieldKey === null) {
      return;
    }

    fieldSaving = true;
    fieldError = '';

    try {
      await updateStaff(staff.id, {
        name: staff.name,
        email: staff.email,
        phone: staff.phone,
        position: staff.position,
        department: staff.department,
        city: staff.city,
        office: staff.office,
        [fieldKey]: fieldDraft.trim()
      });
      fieldOpen = false;
      history = [];
      void loadHistory();
      await load();
      actionNotice = 'Данные сохранены';
    } catch (cause) {
      fieldError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить изменения';
    } finally {
      fieldSaving = false;
    }
  }

  function openRole(): void {
    if (staff === null) {
      return;
    }

    roleDraft = String(staff.level);
    roleError = '';
    roleOpen = true;
  }

  async function saveRole(): Promise<void> {
    if (staff === null) {
      return;
    }

    roleSaving = true;
    roleError = '';

    try {
      await updateStaff(staff.id, {
        name: staff.name,
        email: staff.email,
        phone: staff.phone,
        position: staff.position,
        department: staff.department,
        city: staff.city,
        office: staff.office,
        level: Number(roleDraft)
      });
      roleOpen = false;
      history = [];
      void loadHistory();
      await load();
      actionNotice = 'Роль изменена';
    } catch (cause) {
      roleError = cause instanceof ApiError ? cause.message : 'Не удалось изменить роль';
    } finally {
      roleSaving = false;
    }
  }

  async function openConvert(): Promise<void> {
    if (staff === null) {
      return;
    }

    convertOpen = true;
    convertPreview = null;
    convertError = '';
    convertLoading = true;

    try {
      convertPreview = await getUserConversion(staff.id);
    } catch (cause) {
      convertError = cause instanceof ApiError ? cause.message : 'Не удалось проверить связи';
    } finally {
      convertLoading = false;
    }
  }

  async function confirmConvert(): Promise<void> {
    if (staff === null) {
      return;
    }

    convertSaving = true;
    convertError = '';

    try {
      await convertUser(staff.id, 5);
      convertOpen = false;
      router.navigate(`/clients/${staff.id}`);
    } catch (cause) {
      convertError = cause instanceof ApiError ? cause.message : 'Не удалось перевести в клиента';
    } finally {
      convertSaving = false;
    }
  }

  async function runConfirm(): Promise<void> {
    if (confirmKind === '' || staff === null) {
      return;
    }

    busy = true;
    actionError = '';
    actionNotice = '';

    try {
      if (confirmKind === 'block' || confirmKind === 'unblock') {
        const block = confirmKind === 'block';
        await (block ? blockStaff(staff.id) : unblockStaff(staff.id));
        confirmKind = '';
        history = [];
      void loadHistory();
        await load();
        actionNotice = block ? 'Сотрудник заблокирован' : 'Сотрудник разблокирован';
      } else if (confirmKind === 'sessions') {
        await revokeStaffSessions(staff.id);
        confirmKind = '';
        history = [];
      void loadHistory();
        actionNotice = 'Все сессии сотрудника завершены';
      } else {
        const result = await resetStaffPassword(staff.id);
        confirmKind = '';
        history = [];
      void loadHistory();
        password = result.password;
        passwordOpen = true;
      }
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось выполнить действие';
    } finally {
      busy = false;
    }
  }

  async function copyPassword(): Promise<void> {
    try {
      await navigator.clipboard.writeText(password);
      copied = true;
      setTimeout(() => (copied = false), 2000);
    } catch {
      actionError = 'Не удалось скопировать пароль';
    }
  }

  function openLogin(): void {
    if (staff === null) {
      return;
    }

    loginDraft = staff.login;
    loginError = '';
    loginOpen = true;
  }

  async function saveLogin(): Promise<void> {
    if (staff === null) {
      return;
    }

    loginError = '';
    const login = loginDraft.trim();

    if (!/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/.test(login)) {
      loginError = '3–64 символа: латиница, цифры, точка, дефис, подчёркивание или @';
      return;
    }

    loginSaving = true;

    try {
      await changeStaffLogin(staff.id, login);
      loginOpen = false;
      history = [];
      void loadHistory();
      await load();
      actionNotice = 'Логин изменён';
    } catch (cause) {
      loginError = cause instanceof ApiError ? cause.message : 'Не удалось изменить логин';
    } finally {
      loginSaving = false;
    }
  }

  function initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }

  function fieldLabel(): string {
    return editableFields.find((field) => field.key === fieldKey)?.label ?? '';
  }
</script>

<section class="page">
  <div class="hero">
    <a class="back" href="#/staff" title="К списку сотрудников" aria-label="К списку сотрудников">
      <Icon name="arrow-left" size={16} />
    </a>

    {#if staff}
      <span class="avatar">{initials(staff.name)}</span>
      <div class="identity">
        <h1>{staff.name}</h1>
        <span class="role">{staffLevelTitle(staff.level)}</span>
      </div>
    {:else}
      <h1>Сотрудник</h1>
    {/if}

    <div class="head-right">
      {#if staff}
        <span class="state" class:on={staff.active}>{staffStateTitle(staff.active)}</span>
      {/if}
      <div class="head-actions">
        <div class="bookmarks" role="tablist" use:tablist aria-label="Разделы сотрудника">
          <button
            type="button"
            class="bookmark"
            class:active={tab === 'profile'}
            role="tab"
            aria-selected={tab === 'profile'}
            title="Профиль"
            aria-label="Профиль"
            onclick={() => (tab = 'profile')}
          >
            <Icon name="staff" size={18} />
          </button>
        </div>

        {#if canChat}
          <button
            type="button"
            class="bookmark"
            title="Написать в чат"
            aria-label="Написать в чат"
            disabled={chatBusy}
            onclick={() => void openChat()}
          >
            {#if chatBusy}
              <Spinner size={16} />
            {:else}
              <Icon name="chat" size={18} />
            {/if}
          </button>
        {/if}
      </div>
    </div>
  </div>

  {#if actionError}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span class="notify-text">{actionError}</span>
      <button type="button" class="notify-close" aria-label="Закрыть" onclick={() => (actionError = '')}>
        <Icon name="close" size={14} />
      </button>
    </div>
  {/if}

  {#if actionNotice}
    <div class="notice" role="status">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span class="notify-text">{actionNotice}</span>
      <button type="button" class="notify-close" aria-label="Закрыть" onclick={() => (actionNotice = '')}>
        <Icon name="close" size={14} />
      </button>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if !staff}
    <div class="empty">
      <h2>Сотрудник не найден</h2>
      <p>{error || 'Возможно, запись удалена или недоступна.'}</p>
      <Button variant="ghost" onclick={() => (window.location.hash = '#/staff')}>
        К списку сотрудников
      </Button>
    </div>
  {:else}
    <div class="layout">
      <div class="main">
        <section class="card">
          <h2>Личные данные</h2>
          <div class="rows">
            {#each editableFields as field (field.key)}
              <div class="row">
                <span class="row-label">{field.label}</span>
                <span class="row-value">{staff[field.key] || '—'}</span>
                {#if canEdit}
                  <button
                    type="button"
                    class="icon-btn"
                    title={`Изменить: ${field.label}`}
                    aria-label={`Изменить ${field.label}`}
                    onclick={() => openField(field.key)}
                  >
                    <Icon name="edit" size={16} />
                  </button>
                {/if}
              </div>
            {/each}
          </div>
        </section>

        <section class="card">
          <div class="card-head">
            <h2>Доступ</h2>
            {#if adminHint}
              <HelpTip text={adminHint} />
            {/if}
          </div>
          <div class="rows">
            <div class="row">
              <span class="row-label">Роль</span>
              <span class="row-value">{staffLevelTitle(staff.level)}</span>
              {#if canChangeRole}
                <button
                  type="button"
                  class="icon-btn"
                  title="Изменить роль"
                  aria-label="Изменить роль"
                  onclick={openRole}
                >
                  <Icon name="edit" size={16} />
                </button>
              {/if}
            </div>
            {#if canConvertToClient}
              <div class="row">
                <span class="row-label">Учётная запись</span>
                <span class="row-value">Сотрудник</span>
                <button
                  type="button"
                  class="icon-btn"
                  title="Сделать клиентом"
                  aria-label="Сделать клиентом"
                  onclick={() => void openConvert()}
                >
                  <Icon name="clients" size={16} />
                </button>
              </div>
            {/if}
            <div class="row">
              <span class="row-label">Состояние</span>
              <span class="row-value">
                <span class="state" class:on={staff.active}>{staffStateTitle(staff.active)}</span>
              </span>
              {#if canBlock}
                <button
                  type="button"
                  class="icon-btn"
                  title={staff.active ? 'Заблокировать' : 'Разблокировать'}
                  aria-label={staff.active ? 'Заблокировать' : 'Разблокировать'}
                  onclick={() => (confirmKind = staff?.active ? 'block' : 'unblock')}
                >
                  <Icon name={staff.active ? 'close-circle' : 'check-circle'} size={16} />
                </button>
              {/if}
            </div>
            <div class="row">
              <span class="row-label">Создан</span>
              <span class="row-value">{formatDateTime(staff.created_at)}</span>
            </div>
            <div class="row">
              <span class="row-label">Последний вход</span>
              <span class="row-value">{formatDateTime(staff.last_seen_at)}</span>
            </div>
          </div>
        </section>

        {#if hasSecurity}
          <section class="card">
            <h2>Безопасность</h2>
            <div class="rows">
              {#if canChangeLogin}
                <div class="row">
                  <span class="row-label">Логин</span>
                  <span class="row-value">{staff.login}</span>
                  <button
                    type="button"
                    class="icon-btn"
                    title="Изменить логин"
                    aria-label="Изменить логин"
                    onclick={openLogin}
                  >
                    <Icon name="edit" size={16} />
                  </button>
                </div>
              {/if}
              {#if canReset}
                <div class="row">
                  <span class="row-label">Пароль</span>
                  <span class="row-value">Сброс завершит активные сессии</span>
                  <button
                    type="button"
                    class="icon-btn"
                    title="Сбросить пароль"
                    aria-label="Сбросить пароль"
                    onclick={() => (confirmKind = 'reset')}
                  >
                    <Icon name="key" size={16} />
                  </button>
                </div>
              {/if}
              {#if canRevokeSessions}
                <div class="row">
                  <span class="row-label">Сессии</span>
                  <span class="row-value">Завершить все сессии сотрудника</span>
                  <button
                    type="button"
                    class="icon-btn"
                    title="Завершить все сессии"
                    aria-label="Завершить все сессии"
                    onclick={() => (confirmKind = 'sessions')}
                  >
                    <Icon name="logout" size={16} />
                  </button>
                </div>
              {/if}
            </div>
          </section>
        {/if}
      </div>

      <aside class="side">
        <section class="card">
          <div class="card-head">
            <h2>Клиенты менеджера</h2>
            {#if clients.length > 0}
              <button type="button" class="link-btn" onclick={() => (clientsOpen = true)}>
                Все клиенты ({clients.length}) →
              </button>
            {/if}
          </div>

          {#if clientsLoading}
            <div class="center"><Spinner size={18} /></div>
          {:else if clientsError}
            <p class="form-error">{clientsError}</p>
          {:else if clients.length === 0}
            <p class="empty-text">Закреплённых клиентов нет</p>
          {:else}
            <div class="clients-list">
              {#each previewClients as client (client.id)}
                <a class="client-row" href={`#/clients/${client.id}`}>
                  <span class="client-main">
                    <span class="client-name">{client.name}</span>
                    <span class="client-company">{client.company || client.login}</span>
                  </span>
                  <span class="client-contact">{client.email || client.phone || '—'}</span>
                  <span class="client-requests">{client.requests_total} заявок</span>
                  <span class="state" class:on={client.active}>
                    {client.active ? 'Активен' : 'Заблокирован'}
                  </span>
                </a>
              {/each}
            </div>
          {/if}
        </section>

        <section class="card">
          <div class="card-head">
            <h2>История профиля</h2>
            {#if history.length > 0}
              <button type="button" class="link-btn" onclick={() => void openHistory()}>
                Вся история →
              </button>
            {/if}
          </div>
          {#if historyLoading && history.length === 0}
            <div class="center"><Spinner size={18} /></div>
          {:else if historyError}
            <p class="form-error">{historyError}</p>
          {:else if history.length === 0}
            <p class="empty-text">Событий пока нет</p>
          {:else}
            <ul class="account-events">
              {#each history.slice(0, 20) as item (item.id)}
                <li class={eventTone(item.event)}>
                  <span class="event">{historyLabels[item.event] ?? item.event}</span>
                  <span class="when">
                    {formatDateTime(item.created_at)}
                    {#if item.actor}· {item.actor.name}{/if}
                  </span>
                  {#if item.comment}<span class="comment">{item.comment}</span>{/if}
                </li>
              {/each}
            </ul>
          {/if}
        </section>
      </aside>
    </div>
  {/if}
</section>

<Modal open={fieldOpen} title={`Изменить: ${fieldLabel()}`} onclose={() => (fieldOpen = false)}>
  <Input
    label={fieldLabel()}
    bind:value={fieldDraft}
    type={fieldKey === 'phone' ? 'tel' : fieldKey === 'email' ? 'email' : 'text'}
    inputmode={fieldKey === 'phone' ? 'tel' : fieldKey === 'email' ? 'email' : undefined}
    maxlength={editableFields.find((field) => field.key === fieldKey)?.maxlength}
    error={fieldError}
  />
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (fieldOpen = false)}>Отмена</Button>
    <Button loading={fieldSaving} onclick={saveField}>Сохранить</Button>
  </div>
</Modal>

<Modal open={roleOpen} title="Изменить роль" onclose={() => (roleOpen = false)}>
  <Select label="Роль" bind:value={roleDraft}>
    {#each STAFF_LEVELS as level (level)}
      <option value={String(level)}>{staffLevelTitle(level)}</option>
    {/each}
  </Select>
  {#if roleError}
    <p class="form-error">{roleError}</p>
  {/if}
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (roleOpen = false)}>Отмена</Button>
    <Button loading={roleSaving} onclick={saveRole}>Сохранить</Button>
  </div>
</Modal>

<Modal open={convertOpen} title="Сделать клиентом" onclose={() => (convertOpen = false)}>
  <p class="hint">
    Учётная запись останется той же: ID, заявки, история и переписка сохранятся. Сотрудник
    потеряет доступ к разделам персонала и всем активным сессиям.
  </p>
  {#if convertLoading}
    <div class="center"><Spinner size={22} /></div>
  {:else if convertPreview}
    {#if convertPreview.can}
      <p class="hint">Связей, мешающих переводу, нет.</p>
      {#if convertPreview.counts.stock_acl > 0 || convertPreview.counts.price_types > 0 || convertPreview.counts.drafts > 0}
        <p class="hint">
          Будет очищено: складской доступ — {convertPreview.counts.stock_acl}, типы цен —
          {convertPreview.counts.price_types}, черновики — {convertPreview.counts.drafts}.
        </p>
      {/if}
    {:else}
      <ul class="convert-blockers">
        {#each convertPreview.blockers as blocker (blocker.code)}
          <li>{blocker.message}</li>
        {/each}
      </ul>
    {/if}
  {/if}
  {#if convertError}
    <p class="form-error">{convertError}</p>
  {/if}
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (convertOpen = false)}>Отмена</Button>
    <Button
      variant="danger"
      loading={convertSaving}
      disabled={convertLoading || convertPreview?.can !== true}
      onclick={() => void confirmConvert()}
    >
      Сделать клиентом
    </Button>
  </div>
</Modal>

<Modal open={clientsOpen} title="Клиенты менеджера" wide onclose={() => (clientsOpen = false)}>
  <p class="hint">Всего клиентов: {clients.length}</p>
  <div class="clients-list modal-list">
    {#each clients as client (client.id)}
      <a class="client-row" href={`#/clients/${client.id}`}>
        <span class="client-main">
          <span class="client-name">{client.name}</span>
          <span class="client-company">{client.company || client.login}</span>
        </span>
        <span class="client-contact">{client.email || client.phone || '—'}</span>
        <span class="client-requests">{client.requests_total} заявок</span>
        <span class="state" class:on={client.active}>
          {client.active ? 'Активен' : 'Заблокирован'}
        </span>
      </a>
    {/each}
  </div>
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (clientsOpen = false)}>Закрыть</Button>
  </div>
</Modal>

<Modal open={historyOpen} title="История профиля" wide onclose={() => (historyOpen = false)}>
  {#if historyLoading && history.length === 0}
    <div class="center"><Spinner size={22} /></div>
  {:else if historyError}
    <p class="form-error">{historyError}</p>
  {:else if history.length === 0}
    <p class="empty-text">Событий пока нет</p>
  {:else}
    <div class="history">
      {#each history as item (item.id)}
        <div class="history-item">
          <div class="history-top">
            <strong>{historyLabels[item.event] ?? item.event}</strong>
            <span>{formatDateTime(item.created_at)}</span>
          </div>
          {#if item.comment}
            <p>{item.comment}</p>
          {/if}
          {#if item.actor}
            <span class="history-actor">Автор: {item.actor.name}</span>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (historyOpen = false)}>Закрыть</Button>
  </div>
</Modal>

<Modal open={confirmKind !== ''} title={confirmTitle} onclose={() => (confirmKind = '')}>
  <p>
    {#if confirmKind === 'reset'}
      Сгенерировать новый пароль? Все активные сессии сотрудника будут завершены, пароль
      покажется один раз.
    {:else if confirmKind === 'sessions'}
      Завершить все активные сессии сотрудника? Ему придётся войти в систему заново.
    {:else if confirmKind === 'block'}
      Сотрудник не сможет войти в систему, пока его не разблокируют. Активные сессии будут
      завершены.
    {:else}
      Сотрудник снова сможет войти в систему.
    {/if}
  </p>
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (confirmKind = '')}>Отмена</Button>
    <Button
      variant={confirmKind === 'block' ? 'danger' : 'primary'}
      loading={busy}
      onclick={runConfirm}
    >
      {confirmKind === 'reset'
        ? 'Сбросить пароль'
        : confirmKind === 'sessions'
          ? 'Завершить сессии'
          : confirmKind === 'block'
            ? 'Заблокировать'
            : 'Разблокировать'}
    </Button>
  </div>
</Modal>

<Modal open={loginOpen} title="Изменить логин" onclose={() => (loginOpen = false)}>
  <Input label="Логин" bind:value={loginDraft} maxlength={64} error={loginError} />
  <p class="hint">3–64 символа: латиница, цифры, точка, дефис, подчёркивание или @.</p>
  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (loginOpen = false)}>Отмена</Button>
    <Button loading={loginSaving} onclick={saveLogin}>Сохранить</Button>
  </div>
</Modal>

<Modal open={passwordOpen} title="Новый пароль" closeButton={false} persistent onclose={() => {}}>
  <p>Передайте пароль сотруднику. После закрытия окна посмотреть его будет нельзя.</p>
  <div class="password-box">
    <code>{password}</code>
    <Button variant="ghost" onclick={copyPassword}>{copied ? 'Скопировано' : 'Копировать'}</Button>
  </div>
  <div class="modal-actions">
    <Button onclick={() => (passwordOpen = false)}>Готово</Button>
  </div>
</Modal>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .hero {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 32px;
    height: 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    text-decoration: none;
    transition: color 0.12s ease, border-color 0.12s ease;
  }

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-size: 18px;
    font-weight: 600;
  }

  .identity {
    flex: 1 1 auto;
    min-width: 0;
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    overflow-wrap: anywhere;
  }

  .role {
    display: block;
    margin-top: 2px;
    color: var(--muted);
    font-size: 13px;
  }

  .head-right {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-left: auto;
  }

  .state {
    padding: 0 6px;
    color: var(--muted);
    font-size: 13px;
  }

  .state.on {
    color: var(--success-text);
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-1);
  }

  .bookmarks {
    display: flex;
    align-items: center;
    gap: var(--space-1);
  }

  .bookmark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease, background 0.12s ease;
  }

  .bookmark:hover {
    color: var(--primary);
    background: var(--bg);
  }

  .bookmark.active {
    border-color: var(--primary-border);
    background: var(--primary-bg);
    color: var(--primary);
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

  .alert-icon,
  .notice-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
  }

  .alert-icon {
    color: var(--danger);
  }

  .notice-icon {
    color: var(--success);
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

  .layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .layout.single {
    grid-template-columns: 1fr;
  }

  .main,
  .side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .card h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .card-head h2 {
    margin: 0;
  }

  .rows {
    display: flex;
    flex-direction: column;
  }

  .clients-list {
    display: flex;
    flex-direction: column;
    max-height: 360px;
    overflow-y: auto;
    padding-right: 4px;
  }

  .clients-list.modal-list {
    max-height: 60vh;
  }

  .client-row {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(140px, 1fr) 90px 110px;
    gap: var(--space-3);
    align-items: center;
    padding: 9px 8px;
    border-bottom: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    color: var(--text);
    text-decoration: none;
    transition: background 0.12s ease;
  }

  .client-row:last-child {
    border-bottom: none;
  }

  .client-row:hover {
    background: var(--fill-hover);
  }

  .client-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .client-name {
    overflow: hidden;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .client-company,
  .client-contact {
    overflow: hidden;
    color: var(--muted);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .client-requests {
    color: var(--text-description);
    font-size: 12px;
    white-space: nowrap;
  }

  .side .client-row {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .side .client-contact,
  .side .client-requests {
    display: none;
  }

  @media (max-width: 720px) {
    .client-row {
      grid-template-columns: minmax(0, 1fr) 100px;
    }

    .client-contact,
    .client-requests {
      display: none;
    }
  }

  .row {
    display: grid;
    grid-template-columns: minmax(140px, 0.8fr) minmax(0, 1.4fr) auto;
    gap: var(--space-3);
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .row:last-child {
    border-bottom: none;
  }

  .row-label {
    color: var(--muted);
    font-size: 13px;
  }

  .row-value {
    overflow-wrap: anywhere;
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .icon-btn:hover {
    background: var(--bg);
    color: var(--primary);
  }

  .hint {
    margin: var(--space-3) 0 0;
    color: var(--muted);
    font-size: 13px;
  }

  .form-error {
    margin: var(--space-2) 0 0;
    color: var(--danger);
    font-size: 13px;
  }

  .convert-blockers {
    margin: var(--space-2) 0 0;
    padding-left: 18px;
    color: var(--danger);
    font-size: 13px;
  }

  .link-btn {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    font-size: 13px;
    color: var(--primary);
    cursor: pointer;
  }

  .link-btn:hover {
    text-decoration: underline;
  }

  .empty-text {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .account-events {
    list-style: none;
    margin: 0;
    padding: 0 var(--space-2) 0 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    max-height: min(360px, 50vh);
    overflow-y: auto;
  }

  .account-events li {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-left: var(--space-3);
    border-left: 1px solid var(--border-secondary);
  }

  .account-events li::before {
    content: '';
    position: absolute;
    top: 5px;
    left: -4px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--border);
  }

  .account-events li.success::before {
    background: var(--success);
  }

  .account-events li.danger::before {
    background: var(--danger);
  }

  .account-events li.warning::before {
    background: var(--warning);
  }

  .account-events .event {
    font-size: 14px;
    font-weight: 500;
  }

  .account-events .when {
    font-size: 12px;
    color: var(--muted);
  }

  .account-events .comment {
    font-size: 13px;
  }

  .history {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .history-item {
    padding: 12px 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .history-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .history-top span,
  .history-actor {
    color: var(--muted);
    font-size: 12px;
  }

  .history-item p {
    margin: var(--space-2) 0 0;
    font-size: 13px;
  }

  .history-actor {
    display: block;
    margin-top: var(--space-2);
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .password-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .password-box code {
    overflow-wrap: anywhere;
    font-family: ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', monospace;
    font-size: 15px;
  }

  @media (max-width: 900px) {
    .layout {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 600px) {
    .row {
      grid-template-columns: minmax(110px, 0.8fr) minmax(0, 1.4fr) auto;
    }
  }
</style>
