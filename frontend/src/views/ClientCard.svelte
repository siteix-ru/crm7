<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    acceptTransfer,
    activateClient,
    assignClient,
    blockClient,
    cancelTransfer,
    claimClient,
    declineTransfer,
    getClientCard,
    getClientInterests,
    rejectClient,
    resetClientPassword,
    transferClient,
    unblockClient,
    updateClientContacts
  } from '../lib/api/clients';
  import { listManagers, listRequests, requestFilters } from '../lib/api/requests';
  import type {
    AssignedPriceType,
    ClientCard,
    ClientInterest,
    ManagerItem,
    RequestItem,
    RequestStatus
  } from '../lib/api/types';
  import { todayIso } from '../lib/period';
  import { formatDate, formatDateTime } from '../lib/format';
  import { tooltip } from '../lib/actions/tooltip';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import PriceTypesPicker from '../lib/components/ui/PriceTypesPicker.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let card = $state<ClientCard | null>(null);
  let loading = $state(true);
  let error = $state('');
  let actionError = $state('');
  let actionNotice = $state('');
  let busy = $state(false);
  type TabId = 'summary' | 'requests' | 'interests' | 'history' | 'account';
  type ActionKind = 'approve' | 'decline' | 'block' | 'unblock';

  let editOpen = $state(false);
  let pricePickerOpen = $state(false);
  let tab = $state<TabId>('summary');
  let actionKind = $state<ActionKind | null>(null);
  let actionComment = $state('');
  let passwordConfirmOpen = $state(false);
  let passwordResult = $state<{ login: string; password: string; emailSent: boolean; email: string | null } | null>(
    null
  );
  let accessDraft = $state<'active' | 'blocked'>('active');
  let copied = $state<'' | 'login' | 'password'>('');
  let copyTimer: ReturnType<typeof setTimeout> | undefined;
  let editBusy = $state(false);
  let editForm = $state({ name: '', company: '', inn: '', position: '', phone: '', email: '' });
  let managerOpen = $state(false);
  let transferOpen = $state(false);
  let managerBusy = $state(false);
  let managers = $state<ManagerItem[]>([]);
  let interests = $state<ClientInterest[]>([]);
  let interestsTotal = $state(0);
  let interestsPage = $state(1);
  let interestsPerPage = $state(20);
  let interestsQuery = $state('');
  let interestsInput = $state('');
  let interestsSort = $state('qty_desc');
  let interestsLoading = $state(false);
  let requestsState = $state<'' | 'open' | 'closed' | 'overdue'>('');
  let requestsStatus = $state('');
  let requestsQuery = $state('');
  let requestsInput = $state('');
  let requestsItems = $state<RequestItem[]>([]);
  let requestsTotal = $state(0);
  let requestsPage = $state(1);
  let requestsPerPage = $state(20);
  let requestsLoading = $state(false);
  let historyPage = $state(1);
  let historyPerPage = $state(20);
  let statuses = $state<RequestStatus[]>([]);
  let managerForm = $state({ manager_id: '', comment: '' });
  let transferForm = $state({
    to_manager_id: '',
    permanent: true,
    date_from: '',
    date_to: '',
    comment: ''
  });

  const canConfirm = $derived(auth.can('clients.confirm'));
  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const canAssignPrices = $derived(pricesEnabled && auth.can('prices.assign'));
  const canManage = $derived(card?.can.manage ?? false);
  const transferInProgress = $derived(
    card?.transfer !== null && card?.transfer !== undefined
      && ['pending', 'scheduled'].includes(card.transfer.status)
  );
  const canStartTransfer = $derived(canManage && !transferInProgress);
  const canOpenAccount = $derived(
    Boolean(
      card
      && (card.can.reset_password
        || card.can.assign
        || card.can.manage
        || (pricesEnabled && auth.can('prices.assign')))
    )
  );
  const displayName = $derived(card?.client.name || card?.client.company || '');
  const currentAccess = $derived(card?.client.active ? 'active' : 'blocked');
  const recentRequests = $derived(card?.requests.slice(0, 5) ?? []);
  const topInterests = $derived(card?.interests.slice(0, 5) ?? []);
  const lastPasswordReset = $derived(
    card?.history.find((item) => item.event === 'password_reset' || item.event === 'password_changed') ?? null
  );
  const accountHistory = $derived(
    card
      ? [...card.history].sort((a, b) => (a.created_at < b.created_at ? 1 : -1))
      : []
  );

  const eventTitles: Record<string, string> = {
    registered: 'Регистрация',
    activated: 'Регистрация подтверждена',
    rejected: 'Регистрация отклонена',
    password_reset: 'Сброс пароля',
    password_changed: 'Смена пароля',
    manager_assigned: 'Назначен менеджер',
    manager_claimed: 'Менеджер взял клиента',
    manager_unassigned: 'Менеджер снят',
    manager_transfer_requested: 'Запрошена передача клиента',
    manager_transfer_accepted: 'Передача клиента принята',
    manager_transfer_started: 'Передача вступила в силу',
    manager_transfer_declined: 'Передача клиента отклонена',
    manager_transfer_cancelled: 'Передача клиента отменена',
    manager_transfer_expired: 'Временная передача завершена',
    manager_released: 'Передан в пул (без менеджера)',
    price_types: 'Типы цен изменены',
    updated: 'Профиль изменён',
    profile_updated: 'Профиль изменён',
    avatar_updated: 'Фото профиля обновлено',
    avatar_removed: 'Фото профиля удалено',
    created: 'Создан администратором',
    blocked: 'Доступ заблокирован',
    unblocked: 'Доступ восстановлен',
    consent: 'Согласие на обработку данных'
  };

  const timeline = $derived(
    card
      ? [
          ...card.history.map((item) => ({
            id: `h${item.id}`,
            title: eventTitles[item.event] ?? item.event,
            actor: item.actor?.name ?? '',
            comment: item.comment,
            created_at: item.created_at
          })),
          ...(card.views ?? []).map((item) => ({
            id: `v${item.id}`,
            title: 'Открыл(а) профиль клиента',
            actor: item.user.name,
            comment: '',
            created_at: item.created_at
          }))
        ].sort((a, b) => (a.created_at < b.created_at ? 1 : -1))
      : []
  );

  const stateTitles: Record<string, string> = {
    active: 'активен',
    pending: 'ожидает подтверждения',
    rejected: 'отклонён'
  };

  const historyItems = $derived(
    timeline.slice((historyPage - 1) * historyPerPage, historyPage * historyPerPage)
  );

  onMount(() => {
    void load();
    void loadInterests();
    void loadRequests();
    void loadStatuses();
  });

  $effect(() => {
    if (tab === 'account' && !canOpenAccount) {
      tab = 'summary';
    }
  });

  async function loadStatuses(): Promise<void> {
    try {
      const data = await requestFilters();
      statuses = data.statuses;
    } catch {
      statuses = [];
    }
  }

  async function load(silent = false): Promise<void> {
    if (!silent) {
      loading = true;
    }

    error = '';

    try {
      card = await getClientCard(id);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиента';
    } finally {
      loading = false;
    }
  }

  async function loadInterests(): Promise<void> {
    interestsLoading = true;

    try {
      const data = await getClientInterests(id, {
        q: interestsQuery,
        sort: interestsSort,
        page: interestsPage,
        perPage: interestsPerPage
      });

      interests = data.items;
      interestsTotal = data.total;
    } catch {
      interests = [];
      interestsTotal = 0;
    } finally {
      interestsLoading = false;
    }
  }

  function applyInterestsSearch(): void {
    interestsQuery = interestsInput.trim();
    interestsPage = 1;
    void loadInterests();
  }

  function changeInterestsSort(): void {
    interestsPage = 1;
    void loadInterests();
  }

  function changeInterestsPage(next: number): void {
    interestsPage = next;
    void loadInterests();
  }

  function changeInterestsPerPage(value: number): void {
    interestsPerPage = value;
    interestsPage = 1;
    void loadInterests();
  }

  async function loadRequests(): Promise<void> {
    requestsLoading = true;

    try {
      const data = await listRequests({
        client_id: id,
        state: requestsState || undefined,
        status: requestsStatus || undefined,
        q: requestsQuery || undefined,
        page: requestsPage,
        per_page: requestsPerPage
      });

      requestsItems = data.items;
      requestsTotal = data.total;
    } catch {
      requestsItems = [];
      requestsTotal = 0;
    } finally {
      requestsLoading = false;
    }
  }

  function openRequests(state: '' | 'open' | 'closed' | 'overdue'): void {
    requestsState = state;
    requestsStatus = '';
    requestsQuery = '';
    requestsInput = '';
    requestsPage = 1;
    tab = 'requests';
    void loadRequests();
  }

  function showTab(next: TabId): void {
    if (next === 'account' && !canOpenAccount) {
      return;
    }

    tab = next;
  }

  function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
      return '?';
    }

    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[1][0] ?? '' : '')).toUpperCase();
  }

  function changeRequestsState(value: string): void {
    requestsState = value as '' | 'open' | 'closed' | 'overdue';
    requestsPage = 1;
    void loadRequests();
  }

  function changeRequestsStatus(value: string): void {
    requestsStatus = value;
    requestsPage = 1;
    void loadRequests();
  }

  function applyRequestsSearch(): void {
    requestsQuery = requestsInput.trim();
    requestsPage = 1;
    void loadRequests();
  }

  function changeRequestsPage(next: number): void {
    requestsPage = next;
    void loadRequests();
  }

  function changeRequestsPerPage(value: number): void {
    requestsPerPage = value;
    requestsPage = 1;
    void loadRequests();
  }

  function changeHistoryPage(next: number): void {
    historyPage = next;
  }

  function changeHistoryPerPage(value: number): void {
    historyPerPage = value;
    historyPage = 1;
  }

  async function runAction<T>(action: () => Promise<T>, notice: string): Promise<T | null> {
    busy = true;
    actionError = '';
    actionNotice = '';

    try {
      const result = await action();
      actionNotice = notice;
      await load(true);

      return result;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось выполнить действие';

      return null;
    } finally {
      busy = false;
    }
  }

  function openAction(kind: ActionKind): void {
    actionKind = kind;
    actionComment = '';
    actionError = '';
  }

  function closeAction(): void {
    if (busy) {
      return;
    }

    if (actionKind === 'block' || actionKind === 'unblock') {
      accessDraft = currentAccess;
    }

    actionKind = null;
  }

  function applyAccess(): void {
    if (accessDraft === currentAccess) {
      return;
    }

    openAction(accessDraft === 'blocked' ? 'block' : 'unblock');
  }

  async function confirmAction(): Promise<void> {
    if (actionKind === null) {
      return;
    }

    const comment = actionComment.trim();

    if (actionKind === 'decline' && comment.length < 3) {
      actionError = 'Укажите причину отклонения (минимум 3 символа)';
      return;
    }

    const action = actionKind;
    actionKind = null;

    if (action === 'approve') {
      await runAction(() => activateClient(id, comment), 'Регистрация подтверждена');
    } else if (action === 'decline') {
      await runAction(() => rejectClient(id, comment), 'Регистрация отклонена');
    } else if (action === 'block') {
      const result = await runAction(() => blockClient(id, comment), 'Клиент заблокирован');
      reportAccessEmail(result, 'Клиент заблокирован');
    } else {
      const result = await runAction(() => unblockClient(id, comment), 'Клиент активирован');
      reportAccessEmail(result, 'Клиент активирован');
    }
  }

  function reportAccessEmail(
    result: { email_sent: boolean; email: string | null } | null,
    base: string
  ): void {
    if (!result) {
      return;
    }

    if (result.email_sent && result.email) {
      actionNotice = `${base}. Письмо отправлено на ${result.email}`;
    } else if (result.email) {
      actionNotice = `${base}. Письмо не отправлено — почта не настроена`;
    } else {
      actionNotice = `${base}. E-mail не указан — письмо не отправлено`;
    }
  }

  async function copyText(text: string, kind: 'login' | 'password'): Promise<void> {
    try {
      await navigator.clipboard.writeText(text);
      copied = kind;
      clearTimeout(copyTimer);
      copyTimer = setTimeout(() => (copied = ''), 2000);
    } catch {
      actionError = 'Не удалось скопировать — выделите и скопируйте вручную';
    }
  }

  function closePasswordResult(): void {
    passwordResult = null;
    copied = '';
  }

  async function confirmPasswordReset(): Promise<void> {
    busy = true;
    actionError = '';
    actionNotice = '';

    try {
      const result = await resetClientPassword(id);
      passwordConfirmOpen = false;
      passwordResult = {
        login: result.login,
        password: result.password,
        emailSent: result.email_sent,
        email: result.email
      };
      await load(true);
    } catch (cause) {
      passwordConfirmOpen = false;
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось сбросить пароль';
    } finally {
      busy = false;
    }
  }

  function openEdit(): void {
    if (!card) {
      return;
    }

    editForm = {
      name: card.client.name,
      company: card.client.company,
      inn: card.client.inn,
      position: card.client.position,
      phone: card.client.phone,
      email: card.client.email
    };
    actionError = '';
    actionNotice = '';
    editOpen = true;
  }

  async function saveEdit(): Promise<void> {
    editBusy = true;
    actionError = '';

    try {
      await updateClientContacts(id, { ...editForm });
      editOpen = false;
      actionNotice = 'Контакты сохранены';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить контакты';
    } finally {
      editBusy = false;
    }
  }

  function onPriceTypesSaved(assigned: AssignedPriceType[]): void {
    if (card === null) {
      return;
    }

    const defaultType = assigned.find((type) => type.is_default) ?? null;
    card = {
      ...card,
      client: { ...card.client, price_types: assigned, price_type_id: defaultType?.id ?? null }
    };
    actionNotice = 'Типы цен обновлены';
  }

  async function loadManagers(): Promise<void> {
    if (managers.length > 0) {
      return;
    }

    try {
      managers = (await listManagers()).items;
    } catch {
      managers = [];
    }
  }

  function openAssign(): void {
    managerForm = { manager_id: card?.manager ? String(card.manager.id) : '', comment: '' };
    actionError = '';
    actionNotice = '';
    managerOpen = true;
    void loadManagers();
  }

  async function saveAssign(): Promise<void> {
    managerBusy = true;
    actionError = '';

    try {
      const managerId = managerForm.manager_id === '' ? null : Number(managerForm.manager_id);
      await assignClient(id, managerId, managerForm.comment.trim());
      managerOpen = false;
      actionNotice = managerId === null ? 'Менеджер снят' : 'Менеджер назначен';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось назначить менеджера';
    } finally {
      managerBusy = false;
    }
  }

  function openTransfer(): void {
    transferForm = {
      to_manager_id: '',
      permanent: true,
      date_from: todayIso(),
      date_to: '',
      comment: ''
    };
    actionError = '';
    actionNotice = '';
    transferOpen = true;
    void loadManagers();
  }

  async function saveTransfer(): Promise<void> {
    managerBusy = true;
    actionError = '';

    try {
      if (transferForm.date_from === '') {
        throw new ApiError('validation_error', 'Укажите дату передачи', 422);
      }

      if (!transferForm.permanent && transferForm.date_to === '') {
        throw new ApiError('validation_error', 'Укажите дату окончания периода', 422);
      }

      await transferClient(
        id,
        transferForm.to_manager_id === '' ? null : Number(transferForm.to_manager_id),
        transferForm.date_from,
        transferForm.permanent ? null : transferForm.date_to,
        transferForm.comment.trim()
      );
      transferOpen = false;
      actionNotice = transferForm.to_manager_id === ''
        ? 'Клиент передан в пул'
        : 'Передача отправлена сотруднику';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось передать клиента';
    } finally {
      managerBusy = false;
    }
  }

  async function claimSelf(): Promise<void> {
    await runAction(() => claimClient(id), 'Вы взяли клиента');
  }

  async function acceptCurrentTransfer(): Promise<void> {
    if (!card?.transfer) {
      return;
    }

    await runAction(() => acceptTransfer(card!.transfer!.id), 'Передача принята');
  }

  async function declineCurrentTransfer(): Promise<void> {
    if (!card?.transfer) {
      return;
    }

    await runAction(() => declineTransfer(card!.transfer!.id), 'Передача отклонена');
  }

  async function cancelCurrentTransfer(): Promise<void> {
    if (!card?.transfer) {
      return;
    }

    await runAction(() => cancelTransfer(card!.transfer!.id), 'Передача отменена');
  }
</script>

<section class="page">
  <div class="head">
    <Button variant="ghost" onclick={() => router.navigate('/clients')}>← Клиенты</Button>

    {#if card}
      <div class="head-actions">
        {#if card.client.reg_state === 'pending'}
          {#if canConfirm}
            <Button variant="ghost" disabled={busy} onclick={() => openAction('decline')}>Отклонить</Button>
            <Button loading={busy} onclick={() => openAction('approve')}>Подтвердить регистрацию</Button>
          {/if}
        {:else}
          {#if card.can.claim}
            <Button loading={busy} onclick={() => void claimSelf()}>Взять себе</Button>
          {/if}
          {#if canManage}
            <Button variant="ghost" disabled={busy} onclick={openEdit}>Редактировать</Button>
            {#if canStartTransfer}
              <Button variant="ghost" disabled={busy} onclick={openTransfer}>Передать</Button>
            {/if}
            {#if card.client.active}
              <Button variant="danger" disabled={busy} onclick={() => openAction('block')}>Заблокировать</Button>
            {:else}
              <Button variant="ghost" disabled={busy} onclick={() => openAction('unblock')}>Разблокировать</Button>
            {/if}
          {/if}
        {/if}
      </div>
    {/if}
  </div>

  {#if actionError}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{actionError}</span>
    </div>
  {/if}
  {#if actionNotice}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{actionNotice}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {:else if card}
    <div class="hero">
      <span class="avatar" aria-hidden="true">{initials(displayName)}</span>
      <h1>{displayName}</h1>

      <div class="hero-status">
        <span class="state" class:on={card.client.reg_state === 'active' && card.client.active}>
          {#if card.client.reg_state === 'active' && !card.client.active}
            заблокирован
          {:else}
            {stateTitles[card.client.reg_state] ?? card.client.reg_state}
          {/if}
        </span>
        {#if card.transfer && card.transfer.status !== 'active'}
          <span class="state transfer-chip">
            {card.transfer.status === 'scheduled' ? 'передача запланирована' : 'передача ожидает'}
          </span>
        {/if}
      </div>
    </div>

    {#if card.transfer && card.transfer.status !== 'active'}
      <div class="transfer" class:incoming={card.transfer.incoming}>
        <div class="transfer-text">
          <strong>Передача клиента:</strong>
          {card.transfer.from?.name ?? '—'} →
          {card.transfer.to?.name ?? 'в пул (без менеджера)'}
          {#if card.transfer.date_from}
            · с {formatDate(card.transfer.date_from)}
          {/if}
          {#if card.transfer.date_to}
            до {formatDate(card.transfer.date_to)}
          {/if}
          {#if card.transfer.comment}<span class="transfer-comment">{card.transfer.comment}</span>{/if}
          {#if card.transfer.status === 'scheduled'}
            <span class="transfer-note">вступит в силу с {formatDate(card.transfer.date_from ?? '')}</span>
          {:else if card.transfer.incoming}
            <span class="transfer-note">ожидает вашего решения</span>
          {:else}
            <span class="transfer-note">ожидает решения получателя</span>
          {/if}
        </div>
        <div class="transfer-actions">
          {#if card.transfer.can_accept}
            <Button loading={busy} onclick={() => void acceptCurrentTransfer()}>Принять</Button>
            <Button variant="ghost" disabled={busy} onclick={() => void declineCurrentTransfer()}>
              Отклонить
            </Button>
          {/if}
          {#if card.transfer.can_cancel}
            <Button variant="ghost" disabled={busy} onclick={() => void cancelCurrentTransfer()}>
              Отменить передачу
            </Button>
          {/if}
        </div>
      </div>
    {/if}

    <div class="card-tabs tab-scroll">
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'summary'}
        onclick={() => showTab('summary')}
      >
        Сводная
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'requests'}
        onclick={() => showTab('requests')}
      >
        Заявки{#if card.stats.open > 0}<span class="tab-count">{card.stats.open}</span>{/if}
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'interests'}
        onclick={() => showTab('interests')}
      >
        Интересы{#if interestsTotal > 0}<span class="tab-count muted">{interestsTotal}</span>{/if}
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'history'}
        onclick={() => showTab('history')}
      >
        История
      </button>
      {#if canOpenAccount}
        <button
          type="button"
          class="card-tab"
          class:active={tab === 'account'}
          onclick={() => showTab('account')}
        >
          Учётная запись
        </button>
      {/if}
    </div>

    {#if tab === 'summary'}
      <div class="summary">
        <div class="summary-col">
          <div class="card">
            <div class="card-head">
              <h2>Контакты</h2>
              {#if canManage}
                <Button variant="ghost" onclick={openEdit}>Изменить</Button>
              {/if}
            </div>
            <dl class="contacts">
              <div><dt>E-mail</dt><dd>{card.client.email || '—'}</dd></div>
              <div><dt>Телефон</dt><dd>{card.client.phone || '—'}</dd></div>
              <div><dt>Компания</dt><dd>{card.client.company || '—'}</dd></div>
              <div><dt>Должность</dt><dd>{card.client.position || '—'}</dd></div>
              <div><dt>ИНН</dt><dd>{card.client.inn || '—'}</dd></div>
            </dl>
          </div>

          <div class="card">
            <div class="card-head">
              <h2>Последние заявки</h2>
              <button type="button" class="link-btn" onclick={() => showTab('requests')}>
                Все заявки →
              </button>
            </div>
            {#if recentRequests.length === 0}
              <p class="empty">У клиента пока нет заявок</p>
            {:else}
              <div class="mini-list">
                {#each recentRequests as item (item.id)}
                  <a class="mini-row" href={`#/requests/${item.id}`}>
                    <span class="mini-main">
                      <span class="mini-title">{item.subject}</span>
                      <span class="mini-sub">{item.number} · {formatDateTime(item.created_at)}</span>
                    </span>
                    <StatusBadge title={item.status.title} color={item.status.color} />
                  </a>
                {/each}
              </div>
            {/if}
          </div>

          <div class="card">
            <div class="card-head">
              <h2>Интересы</h2>
              <button type="button" class="link-btn" onclick={() => showTab('interests')}>
                Все интересы →
              </button>
            </div>
            {#if topInterests.length === 0}
              <p class="empty">Интерес появится после первой заявки</p>
            {:else}
              <div class="mini-list">
                {#each topInterests as item (item.name + '|' + item.unit)}
                  <div class="mini-row">
                    <span class="mini-main">
                      <span class="mini-title">{item.name}</span>
                      <span class="mini-sub">{item.warehouse_name || '—'} · заказов: {item.orders}</span>
                    </span>
                    <span class="mini-qty">{item.total_qty}</span>
                  </div>
                {/each}
              </div>
            {/if}
          </div>
        </div>

        <div class="summary-col">
          <div class="card">
            <h2>Статистика</h2>
            <div class="tiles">
              <button type="button" class="tile" onclick={() => openRequests('')}>
                <span class="value">{card.stats.total}</span><span class="label">всего заявок</span>
              </button>
              <button type="button" class="tile" onclick={() => openRequests('open')}>
                <span class="value">{card.stats.open}</span><span class="label">открыто</span>
              </button>
              <button type="button" class="tile" onclick={() => openRequests('closed')}>
                <span class="value">{card.stats.closed}</span><span class="label">закрыто</span>
              </button>
              <button type="button" class="tile" onclick={() => openRequests('overdue')}>
                <span class="value warn">{card.stats.overdue}</span><span class="label">просрочено</span>
              </button>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <h2>Менеджер</h2>
              {#if canOpenAccount}
                <button type="button" class="link-btn" onclick={() => showTab('account')}>
                  Управление →
                </button>
              {/if}
            </div>
            {#if card.manager}
              <div class="manager-box">
                <a class="manager-name link" href={`#/users/${card.manager.id}`}>{card.manager.name}</a>
                {#if card.manager.assigned_at}
                  <span class="mini-sub">с {formatDate(card.manager.assigned_at)}</span>
                {/if}
                {#if card.manager.assigned_by}
                  <span class="mini-sub">назначил(а): {card.manager.assigned_by.name}</span>
                {/if}
                {#if card.manager.temporary_until}
                  <span class="state transfer-chip">
                    временно до {formatDate(card.manager.temporary_until)}
                  </span>
                {/if}
              </div>
            {:else}
              <p class="empty">Менеджер не назначен</p>
            {/if}
          </div>

          <div class="card">
            <div class="card-head">
              <h2>Учётная запись</h2>
              {#if canOpenAccount}
                <button type="button" class="link-btn" onclick={() => showTab('account')}>
                  Открыть →
                </button>
              {/if}
            </div>
            <dl class="contacts compact">
              <div><dt>Регистрация</dt><dd>{formatDateTime(card.client.registered_at)}</dd></div>
              <div>
                <dt>Последняя активность</dt>
                <dd>{card.client.last_seen_at ? formatDateTime(card.client.last_seen_at) : '—'}</dd>
              </div>
              {#if pricesEnabled}
                <div>
                  <dt>Типы цен</dt>
                  <dd>
                    {#if card.client.price_types.length > 0}
                      {card.client.price_types
                        .map((type) => (type.is_default ? `${type.title} (по умолч.)` : type.title))
                        .join(', ')}
                    {:else}
                      —
                    {/if}
                  </dd>
                </div>
              {/if}
            </dl>
          </div>
        </div>
      </div>
    {/if}

    {#if tab === 'interests'}
      <div class="card">
        <h2>Целевой интерес к номенклатуре</h2>
      <p class="hint">Что клиент заказывал — для будущих целевых продаж (без отменённых заявок)</p>

      <div class="block-toolbar">
        <form
          class="search"
          onsubmit={(event) => {
            event.preventDefault();
            applyInterestsSearch();
          }}
        >
          <SearchInput
            bind:value={interestsInput}
            historyKey="client-interests"
            placeholder="Поиск номенклатуры"
            onclear={applyInterestsSearch}
            onpick={applyInterestsSearch}
          />
          <Button type="submit" variant="ghost">Найти</Button>
        </form>

        <select bind:value={interestsSort} onchange={changeInterestsSort}>
          <option value="qty_desc">Количество: по убыванию</option>
          <option value="qty_asc">Количество: по возрастанию</option>
          <option value="orders_desc">Заказов: больше</option>
          <option value="orders_asc">Заказов: меньше</option>
          <option value="last_desc">Последний заказ: новые</option>
          <option value="last_asc">Последний заказ: старые</option>
          <option value="name_asc">Название: А–Я</option>
          <option value="name_desc">Название: Я–А</option>
        </select>
      </div>

      {#if interestsLoading && interests.length === 0}
        <div class="center"><Spinner size={22} /></div>
      {:else if interests.length === 0}
        <p class="empty">{interestsQuery ? 'Ничего не найдено' : 'Номенклатуры пока нет'}</p>
      {:else}
        <div class="interests">
          <div class="tr th">
            <span>Номенклатура</span>
            <span>Склад</span>
            <span class="num">Заказов</span>
            <span class="num">Количество</span>
            <span>Последний раз</span>
          </div>
          {#each interests as item (item.name + '|' + item.unit)}
            <div class="tr" use:tooltip={item.description}>
              <span class="name">{item.name}{item.unit ? ` (${item.unit})` : ''}</span>
              <span>{item.warehouse_name || '—'}</span>
              <span class="num">{item.orders}</span>
              <span class="num">{item.total_qty}</span>
              <span>{formatDateTime(item.last_at)}</span>
            </div>
          {/each}
        </div>

        <div class="pager-wrap">
          <Pager
            page={interestsPage}
            perPage={interestsPerPage}
            total={interestsTotal}
            loading={interestsLoading}
            onchange={changeInterestsPage}
            onperpage={changeInterestsPerPage}
          />
        </div>
      {/if}
      </div>
    {/if}

    {#if tab === 'history'}
      <div class="card">
        <h2>История</h2>
        {#if timeline.length === 0}
          <p class="empty">Событий нет</p>
        {:else}
          <ol class="timeline">
            {#each historyItems as item (item.id)}
              <li>
                <div class="event">{item.title}</div>
                <div class="when">
                  {formatDateTime(item.created_at)}
                  {#if item.actor}· {item.actor}{/if}
                </div>
                {#if item.comment}<div class="comment">{item.comment}</div>{/if}
              </li>
            {/each}
          </ol>

          <div class="pager-wrap">
            <Pager
              page={historyPage}
              perPage={historyPerPage}
              total={timeline.length}
              onchange={changeHistoryPage}
              onperpage={changeHistoryPerPage}
            />
          </div>
        {/if}
      </div>
    {/if}

    {#if tab === 'requests'}
      <div class="card">
        <h2>Заявки клиента</h2>
        <div class="block-toolbar">
        <form
          class="search"
          onsubmit={(event) => {
            event.preventDefault();
            applyRequestsSearch();
          }}
        >
          <SearchInput
            bind:value={requestsInput}
            historyKey="client-requests"
            placeholder="Номер, тема, текст"
            onclear={applyRequestsSearch}
            onpick={applyRequestsSearch}
          />
          <Button type="submit" variant="ghost">Найти</Button>
        </form>

        <select
          value={requestsState}
          onchange={(event) => changeRequestsState(event.currentTarget.value)}
        >
          <option value="">Все состояния</option>
          <option value="open">Открыто ({card.stats.open})</option>
          <option value="closed">Закрыто ({card.stats.closed})</option>
          <option value="overdue">Просрочено ({card.stats.overdue})</option>
        </select>

        <select
          value={requestsStatus}
          onchange={(event) => changeRequestsStatus(event.currentTarget.value)}
        >
          <option value="">Все статусы</option>
          {#each statuses as status (status.code)}
            <option value={status.code}>
              {status.title} ({card.stats.by_status[status.code] ?? 0})
            </option>
          {/each}
        </select>
      </div>

      {#if requestsLoading && requestsItems.length === 0}
        <div class="center"><Spinner size={22} /></div>
      {:else if requestsItems.length === 0}
        <p class="empty">Заявок нет</p>
      {:else}
        <div class="requests">
          {#each requestsItems as item (item.id)}
            <a class="request" href={`#/requests/${item.id}`}>
              <div class="line">
                <span class="number">{item.number}</span>
                <StatusBadge title={item.status.title} color={item.status.color} />
                {#if item.is_overdue}<span class="overdue">просрочена</span>{/if}
              </div>
              <div class="subject">{item.subject}</div>
              <div class="when">
                {formatDateTime(item.created_at)}
                {#if item.manager}· менеджер: {item.manager.name}{/if}
              </div>
            </a>
          {/each}
        </div>

        <div class="pager-wrap">
          <Pager
            page={requestsPage}
            perPage={requestsPerPage}
            total={requestsTotal}
            loading={requestsLoading}
            onchange={changeRequestsPage}
            onperpage={changeRequestsPerPage}
          />
        </div>
      {/if}
      </div>
    {/if}

    {#if tab === 'account' && canOpenAccount}
      <div class="account">
        <div class="account-main">
        <div class="card">
          <h2>Вход в кабинет</h2>
          <dl class="contacts">
            <div>
              <dt>Логин</dt>
              <dd class="copy-row">
                <code>{card.client.login}</code>
                <button
                  type="button"
                  class="copy-btn"
                  onclick={() => void copyText(card!.client.login, 'login')}
                >
                  {copied === 'login' ? 'Скопировано' : 'Копировать'}
                </button>
              </dd>
            </div>
            <div><dt>E-mail</dt><dd>{card.client.email || 'не указан'}</dd></div>
            <div>
              <dt>Статус</dt>
              <dd>
                {#if card.client.reg_state === 'active' && !card.client.active}
                  заблокирован
                {:else}
                  {stateTitles[card.client.reg_state] ?? card.client.reg_state}
                {/if}
              </dd>
            </div>
            <div><dt>Регистрация</dt><dd>{formatDateTime(card.client.registered_at)}</dd></div>
            <div>
              <dt>Последняя активность</dt>
              <dd>{card.client.last_seen_at ? formatDateTime(card.client.last_seen_at) : '—'}</dd>
            </div>
            <div>
              <dt>Последний сброс пароля</dt>
              <dd>
                {#if lastPasswordReset}
                  {formatDateTime(lastPasswordReset.created_at)}
                  {#if lastPasswordReset.actor}· {lastPasswordReset.actor.name}{/if}
                {:else}
                  —
                {/if}
              </dd>
            </div>
          </dl>
        </div>

        <div class="card">
          <h2>Пароль</h2>
          <p class="hint">
            Сброс завершит все активные сеансы клиента. Новый пароль будет показан один раз и
            одновременно отправлен на e-mail, если он указан.
          </p>
          {#if !card.can.reset_password}
            <p class="hint">Недостаточно прав для управления паролем клиента.</p>
          {:else if card.client.reg_state !== 'active'}
            <p class="hint">Сброс пароля доступен после подтверждения регистрации.</p>
          {:else}
            <div class="account-actions">
              <Button variant="ghost" disabled={busy} onclick={() => (passwordConfirmOpen = true)}>
                Сбросить пароль
              </Button>
            </div>
            {#if card.client.email && appSettings.mailConfigured}
              <p class="hint">Копия пароля будет отправлена на {card.client.email}.</p>
            {:else if card.client.email}
              <p class="hint">Почта не настроена — пароль будет только показан.</p>
            {:else}
              <p class="hint">E-mail не указан — пароль будет только показан.</p>
            {/if}
          {/if}
        </div>

        <div class="card">
          <div class="card-head">
            <h2>Типы цен</h2>
            {#if canAssignPrices}
              <Button variant="ghost" disabled={busy} onclick={() => (pricePickerOpen = true)}>
                Изменить
              </Button>
            {/if}
          </div>
          {#if !pricesEnabled}
            <p class="empty">Типы цен отключены в настройках</p>
          {:else if card.client.price_types.length === 0}
            <p class="empty">Не назначены — клиент видит базовые цены</p>
          {:else}
            <div class="chips">
              {#each card.client.price_types as type (type.id)}
                <span class="chip" class:on={type.is_default}>
                  {type.title}{#if type.is_default} · по умолчанию{/if}
                </span>
              {/each}
            </div>
          {/if}
        </div>

        <div class="card">
          <div class="card-head">
            <h2>Менеджер</h2>
            {#if card.can.assign}
              <Button variant="ghost" disabled={busy} onclick={openAssign}>Назначить</Button>
            {/if}
          </div>
          {#if card.manager}
            <div class="manager-box">
              <a class="manager-name link" href={`#/users/${card.manager.id}`}>{card.manager.name}</a>
              {#if card.manager.assigned_at}
                <span class="mini-sub">с {formatDate(card.manager.assigned_at)}</span>
              {/if}
              {#if card.manager.assigned_by}
                <span class="mini-sub">назначил(а): {card.manager.assigned_by.name}</span>
              {/if}
              {#if card.manager.temporary_until}
                <span class="state transfer-chip">
                  временно до {formatDate(card.manager.temporary_until)}
                </span>
              {/if}
            </div>
          {:else}
            <p class="empty">Менеджер не назначен</p>
          {/if}
          {#if canStartTransfer}
            <div class="account-actions">
              <Button variant="ghost" disabled={busy} onclick={openTransfer}>Передать клиента</Button>
            </div>
          {/if}
        </div>

        <div class="card">
          <h2>Доступ</h2>
          <p class="hint">
            {#if card.client.reg_state === 'pending'}
              Регистрация ожидает подтверждения — доступ появится после подтверждения.
            {:else if card.client.reg_state === 'rejected'}
              Регистрация отклонена — доступ к кабинету закрыт.
            {:else if card.client.active}
              Клиент может входить в кабинет. Блокировка завершит его сеансы.
            {:else}
              Доступ к кабинету заблокирован.
            {/if}
          </p>
          {#if card.can.access && card.client.reg_state === 'active'}
            <div class="access-choice" role="radiogroup" aria-label="Доступ клиента">
              <button
                type="button"
                class="access-option"
                class:active={accessDraft === 'active'}
                role="radio"
                aria-checked={accessDraft === 'active'}
                onclick={() => (accessDraft = 'active')}
              >
                Активен
              </button>
              <button
                type="button"
                class="access-option"
                class:active={accessDraft === 'blocked'}
                role="radio"
                aria-checked={accessDraft === 'blocked'}
                onclick={() => (accessDraft = 'blocked')}
              >
                Заблокирован
              </button>
            </div>
            <div class="account-actions">
              <Button disabled={busy || accessDraft === currentAccess} onclick={applyAccess}>
                Применить
              </Button>
            </div>
            {#if card.client.email && appSettings.mailConfigured}
              <p class="hint">После изменения клиенту придёт письмо на {card.client.email}.</p>
            {/if}
          {/if}
        </div>
        </div>

        <div class="account-side">
          <div class="card">
            <div class="card-head">
              <h2>История учётной записи</h2>
              {#if accountHistory.length > 10}
                <button type="button" class="link-btn" onclick={() => showTab('history')}>
                  Вся история →
                </button>
              {/if}
            </div>
            {#if accountHistory.length === 0}
              <p class="empty">Событий пока нет</p>
            {:else}
              <ul class="account-events">
                {#each accountHistory.slice(0, 10) as item (item.id)}
                  <li>
                    <span class="event">{eventTitles[item.event] ?? item.event}</span>
                    <span class="when">
                      {formatDateTime(item.created_at)}
                      {#if item.actor}· {item.actor.name}{/if}
                    </span>
                    {#if item.comment}<span class="comment">{item.comment}</span>{/if}
                  </li>
                {/each}
              </ul>
            {/if}
          </div>
        </div>
      </div>
    {/if}

    <Modal
      open={actionKind !== null}
      title={actionKind === 'approve'
        ? 'Подтвердить регистрацию?'
        : actionKind === 'decline'
          ? 'Отклонить регистрацию?'
          : actionKind === 'block'
            ? 'Заблокировать клиента?'
            : 'Разблокировать клиента?'}
      onclose={closeAction}
    >
      <p class="hint">
        {#if actionKind === 'approve'}
          Клиент получит доступ в личный кабинет и сможет создавать заявки.
        {:else if actionKind === 'decline'}
          Клиент не сможет войти в кабинет. Укажите причину — она сохранится в истории.
        {:else if actionKind === 'block'}
          Клиент не сможет войти в кабинет, все активные сеансы будут завершены.
        {:else}
          Клиент снова сможет входить в кабинет.
        {/if}
      </p>

      {#if actionKind !== 'unblock'}
        <label class="edit-label">
          <span>
            {#if actionKind === 'decline'}
              Причина отклонения
            {:else if actionKind === 'approve'}
              Комментарий (необязательно)
            {:else}
              Причина блокировки (необязательно)
            {/if}
          </span>
          <textarea bind:value={actionComment} rows="3"></textarea>
        </label>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={busy} onclick={closeAction}>Отмена</Button>
        <Button
          variant={actionKind === 'block' || actionKind === 'decline' ? 'danger' : undefined}
          loading={busy}
          disabled={actionKind === 'decline' && actionComment.trim().length < 3}
          onclick={() => void confirmAction()}
        >
          {actionKind === 'approve'
            ? 'Подтвердить'
            : actionKind === 'decline'
              ? 'Отклонить'
              : actionKind === 'block'
                ? 'Заблокировать'
                : 'Разблокировать'}
        </Button>
      </div>
    </Modal>

    <Modal open={passwordConfirmOpen} title="Сбросить пароль?" onclose={() => (passwordConfirmOpen = false)}>
      <p class="hint">
        Текущий пароль перестанет действовать, все сеансы клиента будут завершены. Новый пароль
        будет показан один раз — сохраните его и передайте клиенту.
      </p>
      <div class="modal-actions">
        <Button variant="ghost" disabled={busy} onclick={() => (passwordConfirmOpen = false)}>
          Отмена
        </Button>
        <Button loading={busy} onclick={() => void confirmPasswordReset()}>Сбросить пароль</Button>
      </div>
    </Modal>

    <Modal
      open={passwordResult !== null}
      title="Новый пароль"
      onclose={closePasswordResult}
    >
      <p class="hint">
        Пароль показывается один раз. После закрытия окна посмотреть его нельзя — только сбросить
        заново. Попросите клиента сменить пароль в профиле после входа.
      </p>

      {#if passwordResult?.emailSent}
        <p class="hint">Копия пароля отправлена на {passwordResult.email}.</p>
      {:else if passwordResult?.email}
        <p class="hint">Письмо не отправлено — почта не настроена.</p>
      {:else}
        <p class="hint">E-mail не указан — передайте пароль клиенту лично.</p>
      {/if}

      <dl class="contacts compact">
        <div>
          <dt>Логин</dt>
          <dd class="copy-row">
            <code>{passwordResult?.login}</code>
            <button type="button" class="copy-btn" onclick={() => void copyText(passwordResult?.login ?? '', 'login')}>
              {copied === 'login' ? 'Скопировано' : 'Копировать'}
            </button>
          </dd>
        </div>
        <div>
          <dt>Пароль</dt>
          <dd class="copy-row">
            <code>{passwordResult?.password}</code>
            <button type="button" class="copy-btn" onclick={() => void copyText(passwordResult?.password ?? '', 'password')}>
              {copied === 'password' ? 'Скопировано' : 'Копировать'}
            </button>
          </dd>
        </div>
      </dl>

      <div class="modal-actions">
        <Button onclick={closePasswordResult}>Готово</Button>
      </div>
    </Modal>

    <Modal open={editOpen} title="Редактирование клиента" onclose={() => (editOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Имя</span>
          <input bind:value={editForm.name} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>Компания</span>
          <input bind:value={editForm.company} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>ИНН</span>
          <input bind:value={editForm.inn} maxlength="12" />
        </label>
        <label class="edit-label">
          <span>Должность</span>
          <input bind:value={editForm.position} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>Телефон</span>
          <input bind:value={editForm.phone} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>E-mail</span>
          <input bind:value={editForm.email} maxlength="255" />
        </label>
      </div>

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span>{actionError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={editBusy} onclick={() => (editOpen = false)}>Отмена</Button>
        <Button loading={editBusy} onclick={() => void saveEdit()}>Сохранить</Button>
      </div>
    </Modal>

    <Modal open={managerOpen} title="Назначить менеджера" onclose={() => (managerOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Сотрудник</span>
          <select bind:value={managerForm.manager_id}>
            <option value="">— без менеджера —</option>
            {#each managers as manager (manager.id)}
              <option value={String(manager.id)}>
                {manager.name}{manager.level >= 50 ? ' (администратор)' : ''}
              </option>
            {/each}
          </select>
        </label>
        <label class="edit-label">
          <span>Комментарий</span>
          <input bind:value={managerForm.comment} placeholder="Например: закрепляю за собой" maxlength="500" />
        </label>
      </div>

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span>{actionError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={managerBusy} onclick={() => (managerOpen = false)}>Отмена</Button>
        <Button loading={managerBusy} onclick={() => void saveAssign()}>Назначить</Button>
      </div>
    </Modal>

    <Modal open={transferOpen} title="Передать клиента" onclose={() => (transferOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Кому</span>
          <select bind:value={transferForm.to_manager_id}>
            <option value="">— в пул (без менеджера) —</option>
            {#each managers.filter((item) => item.id !== card?.manager?.id) as manager (manager.id)}
              <option value={String(manager.id)}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label class="edit-label">
          <span>Срок</span>
          <div class="period">
            <label class="radio">
              <input type="radio" bind:group={transferForm.permanent} value={true} /> навсегда
            </label>
            <label class="radio">
              <input type="radio" bind:group={transferForm.permanent} value={false} /> на период
            </label>
          </div>
        </label>

        <label class="edit-label">
          <span>Дата передачи</span>
          <input type="date" bind:value={transferForm.date_from} min={todayIso()} />
        </label>

        <label class="edit-label">
          <span>Дата окончания</span>
          <input
            type="date"
            bind:value={transferForm.date_to}
            min={transferForm.date_from || todayIso()}
            disabled={transferForm.permanent || transferForm.to_manager_id === ''}
          />
        </label>

        <label class="edit-label">
          <span>Комментарий</span>
          <input bind:value={transferForm.comment} placeholder="Причина передачи" maxlength="500" />
        </label>
      </div>

      <p class="hint">
        {#if transferForm.to_manager_id === ''}
          Клиент вернётся в пул — любой менеджер сможет его взять.
        {:else if card?.can.assign}
          Изменение вступит в силу с даты передачи.
        {:else}
          Сотрудник получит уведомление и сможет принять или отклонить передачу; менеджер сменится с
          даты передачи.
        {/if}
      </p>

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span>{actionError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={managerBusy} onclick={() => (transferOpen = false)}>Отмена</Button>
        <Button loading={managerBusy} onclick={() => void saveTransfer()}>Передать</Button>
      </div>
    </Modal>

    <PriceTypesPicker
      open={pricePickerOpen}
      userId={card.client.id}
      userName={card.client.name}
      assigned={card.client.price_types}
      onclose={() => (pricePickerOpen = false)}
      onsaved={onPriceTypesSaved}
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
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
    flex-wrap: wrap;
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

  .hint {
    margin: 0 0 var(--space-3);
    color: var(--muted);
    font-size: 13px;
  }

  .interests {
    display: flex;
    flex-direction: column;
  }

  .block-toolbar {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
    margin-bottom: var(--space-3);
  }

  .block-toolbar .search {
    display: flex;
    gap: var(--space-2);
    flex: 1 1 auto;
    min-width: 0;
    max-width: none;
  }

  .block-toolbar select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
    color: var(--text);
  }

  .card-tabs {
    display: flex;
    gap: var(--space-6);
    border-bottom: 1px solid var(--border-secondary);
    margin-bottom: var(--space-4);
  }

  .card-tab {
    padding: 12px 0;
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font: inherit;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
    transition: color 0.2s ease;
    white-space: nowrap;
  }

  .card-tab:hover {
    color: var(--primary);
  }

  .card-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .interests .tr {
    display: grid;
    grid-template-columns: minmax(160px, 2fr) minmax(120px, 1fr) 90px 110px 140px;
    gap: var(--space-2);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    align-items: center;
  }

  .interests .tr:not(.th) {
    transition: background 0.12s ease;
  }

  .interests .tr:not(.th):hover {
    background: var(--bg);
  }

  .interests .tr.th {
    color: var(--muted);
    font-size: 12px;
    border-bottom-width: 2px;
  }

  .interests .name {
    font-weight: 500;
  }

  .interests .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .pager-wrap {
    margin-top: var(--space-3);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .edit-label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  .edit-label input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .edit-label select {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .period {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    padding-top: 4px;
  }

  .radio {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .manager-name {
    font-weight: 500;
  }

  .transfer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-left: 3px solid var(--muted);
    border-radius: var(--radius-md);
    background: var(--surface);
    font-size: 14px;
  }

  .transfer.incoming {
    border-left-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .transfer-text {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  .transfer-comment {
    color: var(--muted);
    font-size: 13px;
  }

  .transfer-note {
    color: var(--primary);
    font-size: 13px;
  }

  .transfer-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
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

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .state {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--muted);
  }

  .state.on {
    border-color: var(--success-border);
    color: var(--success-text);
  }

  .contacts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
    margin: 0;
  }

  .contacts dt {
    font-size: 12px;
    color: var(--muted);
  }

  .contacts dd {
    margin: 2px 0 0;
    font-size: 14px;
  }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-3);
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
  }

  .tile:hover {
    border-color: var(--primary);
  }

  .tile:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  .value {
    font-size: 22px;
    font-weight: 600;
  }

  .value.warn {
    color: var(--warning-text);
  }

  .label {
    font-size: 12px;
    color: var(--muted);
  }

  .hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    flex-wrap: wrap;
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .hero-status {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

  .avatar {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-size: 22px;
    font-weight: 600;
    letter-spacing: 0.02em;
  }

  .summary {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .summary-col {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .card-head h2 {
    margin: 0;
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

  .mini-list {
    display: flex;
    flex-direction: column;
  }

  .mini-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 8px 0;
    border-bottom: 1px solid var(--border-secondary);
    color: inherit;
    text-decoration: none;
    font-size: 14px;
  }

  .mini-row:last-child {
    border-bottom: none;
  }

  a.mini-row:hover .mini-title {
    color: var(--primary);
  }

  .mini-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .mini-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .mini-sub {
    font-size: 12px;
    color: var(--muted);
  }

  .mini-qty {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }

  .manager-box {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
    font-size: 14px;
  }

  .contacts.compact {
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .chip {
    padding: 2px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 12px;
    color: var(--text);
    background: var(--surface);
  }

  .chip.on {
    border-color: var(--primary);
    color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, var(--surface));
  }

  .account {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .account-main,
  .account-side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    min-width: 0;
  }

  .account-events {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .account-events li {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-left: var(--space-3);
    border-left: 2px solid var(--border-secondary);
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

  .summary .tiles {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .account-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }

  .access-choice {
    display: inline-flex;
    padding: 2px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
  }

  .access-option {
    padding: 6px 14px;
    border: none;
    border-radius: calc(var(--radius-sm) - 1px);
    background: transparent;
    font: inherit;
    font-size: 14px;
    color: var(--text-description);
    cursor: pointer;
    transition: background 0.12s ease, color 0.12s ease;
  }

  .access-option:hover {
    color: var(--text);
  }

  .access-option.active {
    background: var(--surface);
    color: var(--text);
    font-weight: 600;
    box-shadow: var(--shadow-sm);
  }

  .copy-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .copy-row code {
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    background: var(--bg);
    font-size: 13px;
  }

  .copy-btn {
    padding: 2px 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 12px;
    color: var(--text);
    cursor: pointer;
  }

  .copy-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .tab-count {
    display: inline-block;
    min-width: 18px;
    margin-left: 6px;
    padding: 0 5px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-size: 11px;
    line-height: 17px;
    text-align: center;
  }

  .tab-count.muted {
    background: var(--bg);
    color: var(--muted);
  }

  .state.transfer-chip {
    border-color: color-mix(in srgb, var(--primary) 45%, var(--border));
    color: var(--primary);
  }

  .edit-label textarea {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
    resize: vertical;
  }

  @media (max-width: 900px) {
    .summary,
    .account {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  @media (max-width: 640px) {
    .avatar {
      width: 52px;
      height: 52px;
      font-size: 18px;
    }

    .hero {
      align-items: flex-start;
    }

    .hero-status {
      width: 100%;
      margin-left: 0;
    }
  }

  .timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .timeline li {
    border-left: 2px solid var(--border);
    padding-left: var(--space-3);
  }

  .event {
    font-weight: 500;
    font-size: 14px;
  }

  .when {
    font-size: 12px;
    color: var(--muted);
  }

  .comment {
    margin-top: 2px;
    font-size: 13px;
  }

  .requests {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .request {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: inherit;
  }

  .request:hover {
    border-color: var(--primary);
    background: var(--bg);
  }

  .line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
  }

  .overdue {
    font-size: 12px;
    color: var(--warning-text);
  }

  .subject {
    font-size: 14px;
    font-weight: 500;
  }

  .empty {
    color: var(--muted);
    margin: 0;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
