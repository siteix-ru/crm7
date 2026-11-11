<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    acceptTransfer,
    activateClient,
    assignClient,
    blockClient,
    cancelTransfer,
    changeClientLogin,
    claimClient,
    declineTransfer,
    deleteClientAvatar,
    getClientCard,
    getClientInterests,
    rejectClient,
    resetClientPassword,
    revokeClientSessions,
    sendClientPasswordLink,
    unblockClient,
    updateClientContacts,
    uploadClientAvatar
  } from '../lib/api/clients';
  import { startThread } from '../lib/api/chat';
  import { listDrafts, type RequestDraftSummary } from '../lib/api/drafts';
  import { listPriceTypes, setUserPriceTypes } from '../lib/api/prices';
  import { listManagers, listRequests, requestFilters } from '../lib/api/requests';
  import {
    convertUser,
    getUserConversion,
    staffLevelTitle,
    type UserConversionPreview
  } from '../lib/api/staff';
  import type {
    ClientCard,
    ClientInterest,
    ManagerItem,
    PriceType,
    RequestItem,
    RequestStatus
  } from '../lib/api/types';
  import { formatDate, formatDateTime, formatRelative } from '../lib/format';
  import { tooltip } from '../lib/actions/tooltip';
  import Button from '../lib/components/ui/Button.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import { tablist } from '../lib/a11y/tablist';
  import { swipeTabs } from '../lib/a11y/swipe';
  import Modal from '../lib/components/ui/Modal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SortDialog from '../lib/components/ui/SortDialog.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { avatars } from '../lib/stores/avatar.svelte';
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
  let avatarBusy = $state(false);
  let avatarInput: HTMLInputElement | null = $state(null);
  let chatBusy = $state(false);
  type TabId = 'requests' | 'account';
  type ActionKind = 'approve' | 'decline' | 'block' | 'unblock';
  type ContactField =
    | 'name'
    | 'position'
    | 'company'
    | 'inn'
    | 'city'
    | 'address'
    | 'phone'
    | 'email';

  interface ContactFieldMeta {
    key: ContactField;
    label: string;
    title: string;
    hint: string;
    type: 'email' | 'tel' | 'text';
    maxlength: number;
    inputmode?: 'numeric';
  }

  const contactFields: ReadonlyArray<ContactFieldMeta> = [
    {
      key: 'name',
      label: 'Имя',
      title: 'Изменить имя',
      hint: 'Имя клиента или контактного лица.',
      type: 'text',
      maxlength: 255
    },
    {
      key: 'position',
      label: 'Должность',
      title: 'Изменить должность',
      hint: 'Должность контактного лица.',
      type: 'text',
      maxlength: 255
    },
    {
      key: 'company',
      label: 'Компания',
      title: 'Изменить компанию',
      hint: 'Название организации клиента.',
      type: 'text',
      maxlength: 255
    },
    {
      key: 'inn',
      label: 'ИНН',
      title: 'Изменить ИНН',
      hint: '10 цифр для организации, 12 — для ИП.',
      type: 'text',
      maxlength: 12,
      inputmode: 'numeric'
    },
    {
      key: 'city',
      label: 'Город',
      title: 'Изменить город',
      hint: 'Город клиента или организации.',
      type: 'text',
      maxlength: 128
    },
    {
      key: 'address',
      label: 'Адрес',
      title: 'Изменить адрес',
      hint: 'Адрес доставки или юридический адрес.',
      type: 'text',
      maxlength: 255
    },
    {
      key: 'phone',
      label: 'Телефон',
      title: 'Изменить телефон',
      hint: 'Контактный телефон для связи с клиентом.',
      type: 'tel',
      maxlength: 255
    },
    {
      key: 'email',
      label: 'E-mail',
      title: 'Изменить e-mail',
      hint: 'На этот адрес приходят письма о доступе, сбросе пароля и заявках.',
      type: 'email',
      maxlength: 255
    }
  ];

  const contactGroups: ReadonlyArray<{ title: string; fields: ReadonlyArray<ContactFieldMeta> }> = [
    {
      title: 'Контактное лицо',
      fields: contactFields.filter((field) => field.key === 'name' || field.key === 'position')
    },
    {
      title: 'Компания',
      fields: contactFields.filter((field) => field.key === 'company' || field.key === 'inn')
    },
    {
      title: 'Адрес',
      fields: contactFields.filter((field) => field.key === 'city' || field.key === 'address')
    },
    {
      title: 'Контакты',
      fields: contactFields.filter((field) => field.key === 'phone' || field.key === 'email')
    }
  ];

  let priceTypes = $state<PriceType[]>([]);
  let priceTypesLoading = $state(false);
  let priceBusy = $state(false);
  let priceTypesOpen = $state(false);
  let priceTypesDraft = $state<string[]>([]);
  let priceTypesError = $state('');
  let tab = $state<TabId>('requests');
  let actionKind = $state<ActionKind | null>(null);
  let actionComment = $state('');
  let passwordConfirmOpen = $state(false);
  let passwordLinkBusy = $state(false);
  let sessionsConfirmOpen = $state(false);
  let sessionsBusy = $state(false);
  let passwordResult = $state<{ login: string; password: string; emailSent: boolean; email: string | null } | null>(
    null
  );
  let copied = $state<'' | 'login' | 'password'>('');
  let copyTimer: ReturnType<typeof setTimeout> | undefined;
  let noticeTimer: ReturnType<typeof setTimeout> | undefined;

  $effect(() => {
    if (!actionNotice) {
      return;
    }

    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => {
      actionNotice = '';
    }, 6000);

    return () => clearTimeout(noticeTimer);
  });

  let clock = $state(Date.now());

  $effect(() => {
    const timer = setInterval(() => {
      clock = Date.now();
    }, 60000);

    return () => clearInterval(timer);
  });

  let contactEditField = $state<ContactField | ''>('');
  let contactEditValue = $state('');
  let contactEditBusy = $state(false);
  const activeContactField = $derived(
    contactFields.find((field) => field.key === contactEditField) ?? null
  );
  let loginOpen = $state(false);
  let loginBusy = $state(false);
  let loginForm = $state({ login: '' });
  let convertOpen = $state(false);
  let convertRole = $state('10');
  let convertPreview = $state<UserConversionPreview | null>(null);
  let convertLoading = $state(false);
  let convertError = $state('');
  let convertSaving = $state(false);
  let managerOpen = $state(false);
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
  let interestsError = $state('');
  let interestsSeq = 0;
  let interestWarehouseOptions = $state<string[]>([]);
  let interestsWarehouses = $state<string[]>([]);
  let interestsFrom = $state('');
  let interestsTo = $state('');
  let interestsFiltersOpen = $state(false);
  let interestsFilterDraft = $state({
    warehouses: [] as string[],
    from: '',
    to: ''
  });
  let interestsSortOpen = $state(false);
  let interestsSearchTimer: ReturnType<typeof setTimeout> | undefined;
  let interestsOpen = $state(false);
  let interestPreview = $state<ClientInterest[]>([]);
  let interestPreviewLoading = $state(false);
  let interestPreviewError = $state('');
  let requestsStates = $state<string[]>([]);
  let requestsStatuses = $state<string[]>([]);
  let requestsFrom = $state('');
  let requestsTo = $state('');
  let requestsFiltersOpen = $state(false);
  let requestsSort = $state('created_desc');
  let requestsQuery = $state('');
  let requestsInput = $state('');
  let requestsItems = $state<RequestItem[]>([]);
  let requestsTotal = $state(0);
  let requestsPage = $state(1);
  let requestsPerPage = $state(20);
  let requestsLoading = $state(false);
  let requestsError = $state('');
  let requestsSeq = 0;
  let clientDrafts = $state<RequestDraftSummary[]>([]);
  let clientDraftsSeq = 0;
  let requestsFilterDraft = $state({
    states: [] as string[],
    statuses: [] as string[],
    from: '',
    to: ''
  });
  let requestsSortOpen = $state(false);
  let statuses = $state<RequestStatus[]>([]);
  let managerForm = $state({ manager_id: '', comment: '' });
  let managerCommentOpen = $state(false);
  let requestsSearchTimer: ReturnType<typeof setTimeout> | undefined;

  $effect(() => {
    const value = requestsInput.trim();

    clearTimeout(requestsSearchTimer);
    requestsSearchTimer = setTimeout(() => {
      if (value !== requestsQuery) {
        requestsQuery = value;
        requestsPage = 1;
        void loadRequests();
      }
    }, 350);

    return () => clearTimeout(requestsSearchTimer);
  });

  $effect(() => {
    const value = interestsInput.trim();

    clearTimeout(interestsSearchTimer);
    interestsSearchTimer = setTimeout(() => {
      if (value !== interestsQuery) {
        interestsQuery = value;
    interestsPage = 1;
    interestsFiltersOpen = false;
    void loadInterests();
      }
    }, 350);

    return () => clearTimeout(interestsSearchTimer);
  });

  const canConfirm = $derived(auth.can('clients.confirm'));
  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const canAssignPrices = $derived(pricesEnabled && auth.can('prices.assign'));
  const priceTypeOptions = $derived(
    priceTypes.map((type) => ({ value: String(type.id), label: type.title }))
  );
  const allPriceTypeTitles = $derived(priceTypes.map((type) => type.title).join(', '));
  const assignedPriceTypeTitles = $derived(
    (card?.client.price_types ?? []).map((type) => type.title).join(', ')
  );
  const canConvertToStaff = $derived(
    card?.can.convert_to_staff === true && !appSettings.demoMode
  );
  const canOpenAccount = $derived(
    Boolean(
      card
      && (card.can.reset_password
        || card.can.assign
        || card.can.manage
        || card.can.claim
        || card.can.convert_to_staff
        || (pricesEnabled && auth.can('prices.assign')))
    )
  );
  const cardSwipe = {
    prev: () => {
      if (tab === 'account') {
        showTab('requests');
      }
    },
    next: () => {
      if (tab === 'requests' && canOpenAccount) {
        showTab('account');
      }
    }
  };

  const canEditContacts = $derived(
    card
      ? card.client.reg_state === 'pending'
        ? canConfirm
        : card.can.access
      : false
  );
  const avatarUrl = $derived(avatars.url(id));
  const canEditAvatar = $derived(card?.can.access === true && !appSettings.demoMode);
  const canOpenChat = $derived(
    Boolean(
      card
      && appSettings.chatEnabled
      && auth.can('chat.use')
      && card.can.access
      && card.client.active
      && card.client.reg_state === 'active'
    )
  );
  const requestStateOptions = $derived([
    { value: 'open', label: `Открыто (${card?.stats.open ?? 0})` },
    { value: 'closed', label: `Закрыто (${card?.stats.closed ?? 0})` },
    { value: 'overdue', label: `Просрочено (${card?.stats.overdue ?? 0})` }
  ]);
  const requestStatusOptions = $derived(
    statuses.map((status) => ({
      value: status.code,
      label: `${status.title} (${card?.stats.by_status[status.code] ?? 0})`
    }))
  );
  const displayName = $derived(card?.client.name || card?.client.company || `Клиент #${id}`);
  const accessHelp = $derived.by(() => {
    if (!card) {
      return '';
    }

    const base = card.client.active
      ? 'Клиент может входить в кабинет. Блокировка завершит его сеансы.'
      : 'Доступ к кабинету заблокирован.';

    return card.client.email && appSettings.mailConfigured
      ? `${base} При блокировке или разблокировке клиенту придёт письмо на ${card.client.email}.`
      : base;
  });
  const pricesHelp =
    'Отмеченные типы цен определяют, какие цены доступны клиенту. При оформлении заявки применяется минимальная цена среди отмеченных типов.';
  const managerHelp =
    'За клиентом закрепляется менеджер, который ведёт его заявки. Можно закрепить или сменить менеджера, а также открепить клиента от менеджера.';
  const accountHistory = $derived(
    card
      ? [...card.history].sort((a, b) => (a.created_at < b.created_at ? 1 : -1))
      : []
  );
  const requestsActiveFilters = $derived(
    (requestsStates.length > 0 ? 1 : 0)
    + (requestsStatuses.length > 0 ? 1 : 0)
    + (requestsFrom ? 1 : 0)
    + (requestsTo ? 1 : 0)
  );

  const requestSortFields = [
    { field: 'created', label: 'Дата' },
    { field: 'number', label: 'Номер' },
    { field: 'subject', label: 'Тема' }
  ];

  const interestsActiveFilters = $derived(
    (interestsWarehouses.length > 0 ? 1 : 0)
    + (interestsFrom ? 1 : 0)
    + (interestsTo ? 1 : 0)
  );

  const interestSortFields = [
    { field: 'name', label: 'Номенклатура' },
    { field: 'warehouse', label: 'Склад' },
    { field: 'orders', label: 'Заказы' },
    { field: 'qty', label: 'Количество' },
    { field: 'last', label: 'Последний заказ' }
  ];

  const eventTitles: Record<string, string> = {
    registered: 'Регистрация',
    activated: 'Регистрация подтверждена',
    rejected: 'Регистрация отклонена',
    password_reset: 'Сброс пароля',
    password_changed: 'Смена пароля',
    login_changed: 'Логин изменён',
    sessions_revoked: 'Завершены сессии',
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
    consent: 'Согласие на обработку данных',
    converted_to_staff: 'Переведён в сотрудники',
    converted_to_client: 'Переведён в клиенты'
  };

  function eventTone(event: string): '' | 'success' | 'danger' | 'warning' {
    if (event === 'activated' || event === 'unblocked') {
      return 'success';
    }

    if (event === 'blocked' || event === 'rejected') {
      return 'danger';
    }

    if (
      event === 'password_reset'
      || event === 'password_changed'
      || event === 'login_changed'
      || event === 'price_types'
    ) {
      return 'warning';
    }

    return '';
  }

  const stateTitles: Record<string, string> = {
    active: 'активен',
    pending: 'ожидает подтверждения',
    rejected: 'отклонён'
  };

  onMount(() => {
    void load();
    void avatars.load(id);
    void loadInterests();
    void loadInterestPreview();
    void loadRequests();
    void loadClientDrafts();
    void loadStatuses();
  });

  $effect(() => {
    if (tab === 'account' && !canOpenAccount) {
      tab = 'requests';
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
      error = '';
    }

    try {
      card = await getClientCard(id);
    } catch (cause) {
      const message = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиента';

      if (!silent || card === null) {
        error = message;
      } else {
        actionError = message;
      }
    } finally {
      if (!silent) {
        loading = false;
      }
    }
  }

  async function loadInterests(): Promise<void> {
    const seq = ++interestsSeq;
    interestsLoading = true;
    interestsError = '';

    try {
      const data = await getClientInterests(id, {
        q: interestsQuery,
        sort: interestsSort,
        warehouses: interestsWarehouses.length > 0 ? interestsWarehouses : undefined,
        from: interestsFrom || undefined,
        to: interestsTo || undefined,
        page: interestsPage,
        perPage: interestsPerPage
      });

      if (seq !== interestsSeq) {
        return;
      }

      interests = data.items;
      interestsTotal = data.total;
      interestWarehouseOptions = data.warehouses;
    } catch (cause) {
      if (seq !== interestsSeq) {
        return;
      }

      interestsError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить интересы';
    } finally {
      if (seq === interestsSeq) {
        interestsLoading = false;
      }
    }
  }

  async function loadInterestPreview(): Promise<void> {
    interestPreviewLoading = true;
    interestPreviewError = '';

    try {
      const data = await getClientInterests(id, { sort: 'qty_desc', page: 1, perPage: 20 });
      interestPreview = data.items;
    } catch (cause) {
      interestPreviewError =
        cause instanceof ApiError ? cause.message : 'Не удалось загрузить интересы';
    } finally {
      interestPreviewLoading = false;
    }
  }

  async function onAvatarSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
      return;
    }

    avatarBusy = true;
    actionError = '';
    actionNotice = '';

    try {
      await uploadClientAvatar(id, file);
      await avatars.refresh(id);
      actionNotice = 'Фото клиента обновлено';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить фото';
    } finally {
      avatarBusy = false;

      if (avatarInput) {
        avatarInput.value = '';
      }
    }
  }

  async function openClientChat(): Promise<void> {
    if (!card || chatBusy) {
      return;
    }

    chatBusy = true;
    actionError = '';

    try {
      const thread = await startThread(card.client.id);
      router.navigate(`/chat?thread=${thread.id}`);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось открыть чат';
    } finally {
      chatBusy = false;
    }
  }

  function startClientRequest(): void {
    if (!card) {
      return;
    }

    router.navigate(`/requests/new?client=${card.client.id}`);
  }

  async function removeClientAvatar(): Promise<void> {
    if (!confirm('Удалить фото клиента?')) {
      return;
    }

    avatarBusy = true;
    actionError = '';
    actionNotice = '';

    try {
      await deleteClientAvatar(id);
      await avatars.refresh(id);
      actionNotice = 'Фото клиента удалено';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось удалить фото';
    } finally {
      avatarBusy = false;
    }
  }

  function openInterestsFilters(): void {
    interestsFilterDraft = {
      warehouses: [...interestsWarehouses],
      from: interestsFrom,
      to: interestsTo
    };
    interestsFiltersOpen = true;
  }

  function resetInterestsFilters(): void {
    interestsFilterDraft = { warehouses: [], from: '', to: '' };
    interestsWarehouses = [];
    interestsFrom = '';
    interestsTo = '';
    interestsPage = 1;
    interestsFiltersOpen = false;
    void loadInterests();
  }

  function resetInterestsSearchAndFilters(): void {
    interestsInput = '';
    interestsQuery = '';
    interestsWarehouses = [];
    interestsFrom = '';
    interestsTo = '';
    interestsPage = 1;
    void loadInterests();
  }

  function applyInterestsFilters(): void {
    interestsWarehouses = [...interestsFilterDraft.warehouses];
    interestsFrom = interestsFilterDraft.from;
    interestsTo = interestsFilterDraft.to;
    interestsPage = 1;
    void loadInterests();
  }

  function openInterestsSort(): void {
    interestsSortOpen = true;
  }

  function applyInterestsSort(terms: string[]): void {
    interestsSort = terms.length > 0 ? terms.join(',') : 'qty_desc';
    interestsPage = 1;
    interestsSortOpen = false;
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
    const seq = ++requestsSeq;
    requestsLoading = true;
    requestsError = '';

    try {
      const data = await listRequests({
        client_id: id,
        states: requestsStates.length > 0 ? requestsStates : undefined,
        statuses: requestsStatuses.length > 0 ? requestsStatuses : undefined,
        from: requestsFrom || undefined,
        to: requestsTo || undefined,
        sort: requestsSort || undefined,
        q: requestsQuery || undefined,
        page: requestsPage,
        per_page: requestsPerPage
      });

      if (seq !== requestsSeq) {
        return;
      }

      requestsItems = data.items;
      requestsTotal = data.total;
    } catch (cause) {
      if (seq !== requestsSeq) {
        return;
      }

      requestsError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявки';
    } finally {
      if (seq === requestsSeq) {
        requestsLoading = false;
      }
    }
  }

  async function loadClientDrafts(): Promise<void> {
    const seq = ++clientDraftsSeq;

    try {
      const data = await listDrafts(id);

      if (seq !== clientDraftsSeq) {
        return;
      }

      clientDrafts = data.items;
    } catch {
      if (seq !== clientDraftsSeq) {
        return;
      }

      clientDrafts = [];
    }
  }

  function showTab(next: TabId): void {
    if (next === 'account' && !canOpenAccount) {
      return;
    }

    tab = next;

    if (next === 'account') {
      void loadPriceTypes();
    }
  }

  function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
      return '?';
    }

    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[1][0] ?? '' : '')).toUpperCase();
  }

  function openRequestsFilters(): void {
    requestsFilterDraft = {
      states: [...requestsStates],
      statuses: [...requestsStatuses],
      from: requestsFrom,
      to: requestsTo
    };
    requestsFiltersOpen = true;
  }

  function resetRequestsFilters(): void {
    requestsFilterDraft = { states: [], statuses: [], from: '', to: '' };
    requestsStates = [];
    requestsStatuses = [];
    requestsFrom = '';
    requestsTo = '';
    requestsPage = 1;
    requestsFiltersOpen = false;
    void loadRequests();
  }

  function resetRequestsSearchAndFilters(): void {
    requestsInput = '';
    requestsQuery = '';
    requestsStates = [];
    requestsStatuses = [];
    requestsFrom = '';
    requestsTo = '';
    requestsPage = 1;
    void loadRequests();
  }

  function applyRequestsFilters(): void {
    requestsStates = [...requestsFilterDraft.states];
    requestsStatuses = [...requestsFilterDraft.statuses];
    requestsFrom = requestsFilterDraft.from;
    requestsTo = requestsFilterDraft.to;
    requestsPage = 1;
    requestsFiltersOpen = false;
    void loadRequests();
  }

  function openRequestsSort(): void {
    requestsSortOpen = true;
  }

  function applyRequestsSort(terms: string[]): void {
    requestsSort = terms.length > 0 ? terms.join(',') : 'created_desc';
    requestsPage = 1;
    requestsSortOpen = false;
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
    actionNotice = '';
  }

  function closeAction(): void {
    if (busy) {
      return;
    }

    actionKind = null;
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
      actionError = '';
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
    actionError = '';
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

  async function confirmRevokeSessions(): Promise<void> {
    sessionsBusy = true;
    actionError = '';
    actionNotice = '';

    try {
      await revokeClientSessions(id);
      sessionsConfirmOpen = false;
      actionNotice = 'Все сессии клиента завершены';
      await load(true);
    } catch (cause) {
      sessionsConfirmOpen = false;
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось завершить сессии';
    } finally {
      sessionsBusy = false;
    }
  }

  async function confirmPasswordLink(): Promise<void> {
    passwordLinkBusy = true;
    actionError = '';
    actionNotice = '';

    try {
      const result = await sendClientPasswordLink(id);
      passwordConfirmOpen = false;
      actionNotice = `Ссылка для сброса пароля отправлена на ${result.email}`;
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось отправить ссылку';
    } finally {
      passwordLinkBusy = false;
    }
  }

  function startContactEdit(field: ContactField): void {
    if (!card || contactEditBusy) {
      return;
    }

    contactEditField = field;
    contactEditValue = card.client[field] ?? '';
    actionError = '';
    actionNotice = '';
  }

  function cancelContactEdit(): void {
    if (contactEditBusy) {
      return;
    }

    contactEditField = '';
    contactEditValue = '';
  }

  function validateContactValue(field: ContactField, value: string): string {
    if (field === 'name' && value.length < 2) {
      return 'Укажите имя — минимум 2 символа';
    }

    if (field === 'email' && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
      return 'Некорректный e-mail';
    }

    if (field === 'inn' && value !== '' && !/^\d{10}(\d{2})?$/.test(value)) {
      return 'ИНН — 10 или 12 цифр';
    }

    return '';
  }

  async function saveContactField(): Promise<void> {
    if (!card || contactEditField === '' || contactEditBusy) {
      return;
    }

    const field = contactEditField;
    const value = contactEditValue.trim();
    const validationError = validateContactValue(field, value);

    if (validationError !== '') {
      actionError = validationError;
      return;
    }

    const data = {
      name: card.client.name,
      company: card.client.company,
      inn: card.client.inn,
      position: card.client.position,
      city: card.client.city,
      address: card.client.address,
      phone: card.client.phone,
      email: card.client.email
    };
    data[field] = value;
    contactEditBusy = true;
    actionError = '';

    try {
      await updateClientContacts(id, data);
      contactEditField = '';
      contactEditValue = '';
      actionNotice = 'Контакты сохранены';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить контакты';
    } finally {
      contactEditBusy = false;
    }
  }

  function openLoginEdit(): void {
    if (!card) {
      return;
    }

    loginForm = { login: card.client.login };
    actionError = '';
    actionNotice = '';
    loginOpen = true;
  }

  function isValidLogin(value: string): boolean {
    return /^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/.test(value);
  }

  async function saveLogin(): Promise<void> {
    loginBusy = true;
    actionError = '';

    try {
      await changeClientLogin(id, loginForm.login.trim());
      loginOpen = false;
      actionNotice = 'Логин изменён';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить логин';
    } finally {
      loginBusy = false;
    }
  }

  async function loadPriceTypes(): Promise<void> {
    if (!pricesEnabled || priceTypes.length > 0) {
      return;
    }

    priceTypesLoading = true;

    try {
      priceTypes = (await listPriceTypes()).items;
    } catch {
      priceTypes = [];
    } finally {
      priceTypesLoading = false;
    }
  }

  function openPriceTypes(): void {
    if (!card || priceBusy || priceTypes.length === 0) {
      return;
    }

    priceTypesDraft = card.client.price_types.map((type) => String(type.id));
    priceTypesError = '';
    priceTypesOpen = true;
  }

  function cancelPriceTypes(): void {
    if (priceBusy) {
      return;
    }

    priceTypesOpen = false;
    priceTypesError = '';
  }

  async function savePriceTypes(): Promise<void> {
    if (!card || priceBusy) {
      return;
    }

    const ids = priceTypesDraft.map(Number).filter((value) => Number.isFinite(value));

    if (ids.length === 0) {
      priceTypesError = 'Выберите хотя бы один тип цены';
      return;
    }

    priceBusy = true;
    priceTypesError = '';
    actionError = '';
    actionNotice = '';

    try {
      const result = await setUserPriceTypes(card.client.id, ids);
      card = {
        ...card,
        client: {
          ...card.client,
          price_types: result.price_types
        }
      };
      actionNotice = 'Типы цен обновлены';
      priceTypesOpen = false;
    } catch (cause) {
      priceTypesError = cause instanceof ApiError ? cause.message : 'Не удалось обновить типы цен';
    } finally {
      priceBusy = false;
    }
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
    managerCommentOpen = false;
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

  async function claimSelf(): Promise<void> {
    await runAction(() => claimClient(id), 'Клиент взят себе');
  }

  async function openConvert(): Promise<void> {
    convertOpen = true;
    convertRole = '10';
    convertPreview = null;
    convertError = '';
    convertLoading = true;

    try {
      convertPreview = await getUserConversion(id);
    } catch (cause) {
      convertError = cause instanceof ApiError ? cause.message : 'Не удалось проверить связи';
    } finally {
      convertLoading = false;
    }
  }

  async function confirmConvert(): Promise<void> {
    convertSaving = true;
    convertError = '';

    try {
      await convertUser(id, Number(convertRole));
      convertOpen = false;
      router.navigate(`/staff/${id}`);
    } catch (cause) {
      convertError = cause instanceof ApiError ? cause.message : 'Не удалось перевести в сотрудника';
    } finally {
      convertSaving = false;
    }
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
  {#if card && card.client.reg_state === 'pending' && canConfirm}
    <div class="head">
      <div class="head-actions">
        <Button variant="ghost" disabled={busy} onclick={() => openAction('decline')}>Отклонить</Button>
        <Button loading={busy} onclick={() => openAction('approve')}>Подтвердить регистрацию</Button>
      </div>
    </div>
  {/if}

  {#if actionError}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span class="notify-text">{actionError}</span>
      <button
        type="button"
        class="notify-close"
        aria-label="Закрыть уведомление"
        onclick={() => (actionError = '')}
      >
        <Icon name="close" size={14} />
      </button>
    </div>
  {/if}
  {#if actionNotice}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span class="notify-text">{actionNotice}</span>
      <button
        type="button"
        class="notify-close"
        aria-label="Закрыть уведомление"
        onclick={() => (actionNotice = '')}
      >
        <Icon name="close" size={14} />
      </button>
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
      <a class="back" href="#/clients" title="К списку клиентов" aria-label="К списку клиентов">
        <Icon name="arrow-left" size={16} />
      </a>
      {#if canEditAvatar}
        <div class="avatar-edit">
          <button
            type="button"
            class="avatar avatar-btn"
            title={avatarUrl ? 'Заменить фото клиента' : 'Добавить фото клиента'}
            aria-label={avatarUrl ? 'Заменить фото клиента' : 'Добавить фото клиента'}
            aria-busy={avatarBusy}
            disabled={avatarBusy}
            onclick={() => avatarInput?.click()}
          >
            {#if avatarUrl}
              <img src={avatarUrl} alt="" />
            {:else}
              {initials(displayName)}
            {/if}
            <span class="avatar-overlay">
              {#if avatarBusy}
                <Spinner size={16} />
              {:else}
                <Icon name="camera" size={16} />
              {/if}
            </span>
          </button>
          {#if avatarUrl}
            <button
              type="button"
              class="avatar-remove"
              title="Удалить фото клиента"
              aria-label="Удалить фото клиента"
              disabled={avatarBusy}
              onclick={() => void removeClientAvatar()}
            >
              <Icon name="close" size={12} />
            </button>
          {/if}
          <input
            bind:this={avatarInput}
            class="avatar-input"
            type="file"
            accept=".jpg,.jpeg,.png,.webp"
            onchange={onAvatarSelected}
            disabled={avatarBusy}
          />
        </div>
      {:else}
        <span class="avatar" aria-hidden="true">
          {#if avatarUrl}
            <img src={avatarUrl} alt="" />
          {:else}
            {initials(displayName)}
          {/if}
        </span>
      {/if}

      <div class="hero-main">
        <h1>{displayName}</h1>
        <span class="hero-role">Клиент</span>
      </div>

      <div class="head-right">
        <div class="hero-status">
          <span class="state" class:on={card.client.reg_state === 'active' && card.client.active}>
            {#if card.client.reg_state === 'active' && !card.client.active}
              заблокирован
            {:else}
              {stateTitles[card.client.reg_state] ?? card.client.reg_state}
            {/if}
          </span>
        </div>

        <div class="hero-actions">
          {#if appSettings.salesEnabled && auth.can('requests.create')}
            <button
              type="button"
              class="hero-tab"
              title="Создать заявку для клиента"
              aria-label="Создать заявку для клиента"
              onclick={startClientRequest}
            >
              <Icon name="file-plus" size={18} />
            </button>
          {/if}

          <div class="hero-tabs" role="tablist" use:tablist aria-label="Разделы клиента">
            <button
              type="button"
              class="hero-tab"
              class:active={tab === 'requests'}
              role="tab"
              aria-selected={tab === 'requests'}
              title={card.stats.open > 0 ? `Заявки, открытых: ${card.stats.open}` : 'Заявки'}
              aria-label={card.stats.open > 0 ? `Заявки, открытых: ${card.stats.open}` : 'Заявки'}
              onclick={() => showTab('requests')}
            >
              <Icon name="requests" size={18} />
              {#if card.stats.open > 0}
                <span class="tab-count">{card.stats.open > 99 ? '99+' : card.stats.open}</span>
              {/if}
            </button>
            {#if canOpenAccount}
              <button
                type="button"
                class="hero-tab"
                class:active={tab === 'account'}
                role="tab"
                aria-selected={tab === 'account'}
                title="Профиль"
                aria-label="Профиль"
                onclick={() => showTab('account')}
              >
                <Icon name="staff" size={18} />
              </button>
            {/if}
          </div>

          {#if canOpenChat}
            <button
              type="button"
              class="hero-tab"
              title="Открыть чат с клиентом"
              aria-label="Открыть чат с клиентом"
              disabled={chatBusy}
              onclick={() => void openClientChat()}
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

    {#snippet interestsResults()}
      {#if interestsError}
        <div class="list-error">
          <span>{interestsError}</span>
          <Button size="sm" variant="ghost" onclick={() => void loadInterests()}>
            Повторить
          </Button>
        </div>
      {/if}

      {#if interestsLoading && interests.length === 0}
        <div class="center"><Spinner size={22} /></div>
      {:else if interests.length === 0 && !interestsError}
        <div class="empty-state">
          <p class="empty">
            {interestsQuery || interestsActiveFilters > 0
              ? 'Ничего не найдено'
              : 'Номенклатуры пока нет'}
          </p>
          {#if interestsQuery || interestsActiveFilters > 0}
            <Button size="sm" variant="ghost" onclick={resetInterestsSearchAndFilters}>
              Сбросить поиск и фильтры
            </Button>
          {/if}
        </div>
      {:else}
        <div class="table-scroll">
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
    {/snippet}

    {#if tab === 'requests'}
      <div class="requests-layout" use:swipeTabs={cardSwipe}>
        <div class="requests-main">
          <div class="card">
            <div class="card-head">
              <h2>Заявки клиента</h2>
            </div>
            <div class="block-toolbar">
              <div class="search">
                <SearchInput
                  bind:value={requestsInput}
                  historyKey="client-requests"
                  placeholder="Номер, тема, текст"
                />
              </div>

              <button
                type="button"
                class="toolbar-btn"
                class:active={requestsActiveFilters > 0}
                title="Фильтры"
                aria-label={requestsActiveFilters > 0
                  ? `Фильтры, активно: ${requestsActiveFilters}`
                  : 'Фильтры'}
                onclick={openRequestsFilters}
              >
                <Icon name="filter" size={16} />
                {#if requestsActiveFilters > 0}
                  <span class="filter-badge">{requestsActiveFilters}</span>
                {/if}
              </button>

              <button
                type="button"
                class="toolbar-btn"
                class:active={requestsSort !== 'created_desc'}
                title="Сортировка"
                aria-label="Сортировка"
                onclick={openRequestsSort}
              >
                <Icon name="sort" size={16} />
              </button>
            </div>

            {#if requestsError}
              <div class="list-error">
                <span>{requestsError}</span>
                <Button size="sm" variant="ghost" onclick={() => void loadRequests()}>
                  Повторить
                </Button>
              </div>
            {/if}

            {#if requestsLoading && requestsItems.length === 0}
              <div class="center"><Spinner size={22} /></div>
            {:else if requestsItems.length === 0 && !requestsError}
              <div class="empty-state">
                <p class="empty">
                  {requestsQuery || requestsActiveFilters > 0 ? 'Ничего не найдено' : 'Заявок нет'}
                </p>
                {#if requestsQuery || requestsActiveFilters > 0}
                  <Button size="sm" variant="ghost" onclick={resetRequestsSearchAndFilters}>
                    Сбросить поиск и фильтры
                  </Button>
                {/if}
              </div>
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
        </div>

        <div class="requests-side">
          {#if clientDrafts.length > 0}
            <div class="card">
              <div class="card-head">
                <h2>Черновики</h2>
              </div>
              <div class="request-history drafts-list">
                {#each clientDrafts as draft (draft.id)}
                  <a class="history-row" href={`#/requests/new?draft=${draft.id}`}>
                    <div class="history-top">
                      <span class="history-number">{draft.subject || 'Без темы'}</span>
                    </div>
                    <div class="draft-meta">
                      Изменён {formatDateTime(draft.updated_at)}
                      {#if draft.items_count > 0}· позиций: {draft.items_count}{/if}
                    </div>
                  </a>
                {/each}
              </div>
            </div>
          {/if}

          <div class="card">
            <div class="card-head">
              <h2>Целевой интерес к номенклатуре</h2>
              {#if interestPreview.length > 0}
                <button type="button" class="link-btn" onclick={() => (interestsOpen = true)}>
                  Все интересы →
                </button>
              {/if}
            </div>
            {#if interestPreviewLoading && interestPreview.length === 0}
              <div class="center"><Spinner size={18} /></div>
            {:else if interestPreviewError}
              <div class="list-error">
                <span>{interestPreviewError}</span>
                <Button size="sm" variant="ghost" onclick={() => void loadInterestPreview()}>
                  Повторить
                </Button>
              </div>
            {:else if interestPreview.length === 0}
              <p class="empty">Номенклатуры пока нет</p>
            {:else}
              <div class="request-history interest-preview">
                {#each interestPreview as item (item.name + '|' + item.unit)}
                  <div class="history-row">
                    <div class="history-top">
                      <span class="history-number">{item.name}{item.unit ? ` (${item.unit})` : ''}</span>
                      <span class="history-date">{formatDate(item.last_at)}</span>
                    </div>
                    <div class="history-subject">{item.warehouse_name || '—'}</div>
                    <div class="history-meta">
                      Заказов: {item.orders} · Количество: {item.total_qty}
                    </div>
                  </div>
                {/each}
              </div>
            {/if}
          </div>
        </div>
      </div>
    {/if}

    {#if tab === 'account' && canOpenAccount}
      <div class="account" use:swipeTabs={cardSwipe}>
        <div class="account-main">
        <div class="card">
          <div class="card-head">
            <h2>Личные данные</h2>
          </div>
          <div class="field-groups">
            {#each contactGroups as group (group.title)}
              <div class="field-group">
                <span class="field-group-title">{group.title}</span>
                <div class="rows">
                  {#each group.fields as field (field.key)}
                    <div class="row">
                      <span class="row-label">{field.label}</span>
                      <span class="row-value">{card.client[field.key] || '—'}</span>
                      {#if canEditContacts}
                        <button
                          type="button"
                          class="icon-btn"
                          title={`Изменить: ${field.label}`}
                          aria-label={`Изменить ${field.label}`}
                          disabled={busy || contactEditBusy}
                          onclick={() => startContactEdit(field.key)}
                        >
                          <Icon name="edit" size={16} />
                        </button>
                      {/if}
                    </div>
                  {/each}
                </div>
              </div>
            {/each}
          </div>
        </div>

        {#if card.can.access && card.client.reg_state === 'active'}
        <div class="card">
          <div class="card-head">
            <h2>Доступ</h2>
            <div class="card-head-tools">
              <HelpTip text={accessHelp} />
            </div>
          </div>
          <div class="rows">
            <div class="row">
              <span class="row-label">Роль</span>
              <span class="row-value">Клиент</span>
            </div>
            {#if canConvertToStaff}
              <div class="row">
                <span class="row-label">Учётная запись</span>
                <span class="row-value">Клиент</span>
                <button
                  type="button"
                  class="icon-btn"
                  title="Сделать сотрудником"
                  aria-label="Сделать сотрудником"
                  disabled={busy}
                  onclick={() => void openConvert()}
                >
                  <Icon name="staff" size={16} />
                </button>
              </div>
            {/if}
            <div class="row">
              <span class="row-label">Состояние</span>
              <span class="row-value">
                <span class="state" class:on={card.client.active}>
                  {card.client.active ? 'Активен' : 'Заблокирован'}
                </span>
              </span>
              {#if !appSettings.demoMode}
                <button
                  type="button"
                  class="icon-btn"
                  title={card.client.active ? 'Заблокировать' : 'Разблокировать'}
                  aria-label={card.client.active ? 'Заблокировать' : 'Разблокировать'}
                  disabled={busy}
                  onclick={() => openAction(card?.client.active ? 'block' : 'unblock')}
                >
                  <Icon name={card.client.active ? 'close-circle' : 'check-circle'} size={16} />
                </button>
              {/if}
            </div>
            <div class="row">
              <span class="row-label">Регистрация</span>
              <span class="row-value">{formatDateTime(card.client.registered_at)}</span>
            </div>
            <div class="row">
              <span class="row-label">Последняя активность</span>
              <span class="row-value">
                {card.client.last_seen_at
                  ? formatRelative(card.client.last_seen_at, clock)
                  : '—'}
              </span>
            </div>
          </div>
        </div>
        {/if}

        <div class="card">
          <div class="card-head">
            <h2>Безопасность</h2>
          </div>
          <div class="rows">
            <div class="row">
              <span class="row-label">Логин</span>
              <span class="row-value">{card.client.login}</span>
              {#if card.can.access && !appSettings.demoMode}
                <button
                  type="button"
                  class="icon-btn"
                  title="Изменить логин"
                  aria-label="Изменить логин"
                  disabled={busy}
                  onclick={openLoginEdit}
                >
                  <Icon name="edit" size={16} />
                </button>
              {/if}
            </div>
            <div class="row">
              <span class="row-label">Пароль</span>
              <span class="row-value">Сброс завершит активные сессии</span>
              {#if card.can.reset_password && card.client.reg_state === 'active'}
                <button
                  type="button"
                  class="icon-btn"
                  title="Сбросить пароль"
                  aria-label="Сбросить пароль"
                  disabled={busy}
                  onclick={() => {
                    actionError = '';
                    passwordConfirmOpen = true;
                  }}
                >
                  <Icon name="key" size={16} />
                </button>
              {/if}
            </div>
            {#if card.can.revoke_sessions}
              <div class="row">
                <span class="row-label">Сессии</span>
                <span class="row-value">Завершить все сессии клиента</span>
                <button
                  type="button"
                  class="icon-btn"
                  title="Завершить все сессии"
                  aria-label="Завершить все сессии"
                  disabled={busy}
                  onclick={() => {
                    actionError = '';
                    sessionsConfirmOpen = true;
                  }}
                >
                  <Icon name="logout" size={16} />
                </button>
              </div>
            {/if}
          </div>
        </div>

        </div>

        <div class="account-side">
          <div class="card">
            <div class="card-head">
              <h2>Менеджер</h2>
              <div class="card-head-tools">
                <HelpTip text={managerHelp} />
              </div>
            </div>
            <div class="manager-row">
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
              {#if card.can.assign}
                <div class="manager-actions">
                  <button
                    type="button"
                    class="icon-btn"
                    title="Редактировать менеджера"
                    aria-label="Редактировать менеджера"
                    disabled={busy}
                    onclick={openAssign}
                  >
                    <Icon name="edit" size={16} />
                  </button>
                </div>
              {/if}
            </div>
            {#if card.can.claim}
              <div class="account-actions">
                <Button disabled={busy} onclick={() => void claimSelf()}>Взять себе</Button>
              </div>
            {/if}
          </div>

          {#if canAssignPrices}
          <div class="card">
            <div class="card-head">
              <h2>Типы цен</h2>
              <div class="card-head-tools">
                {#if priceBusy}
                  <Spinner size={16} />
                {/if}
                <HelpTip text={pricesHelp} />
              </div>
            </div>
            {#if priceTypesLoading && priceTypes.length === 0}
              <div class="price-loading"><Spinner size={18} /></div>
            {:else if priceTypes.length === 0}
              <p class="empty">Типы цен не настроены</p>
            {:else}
              <div class="price-types-row">
                <span class="price-types-value">{assignedPriceTypeTitles || '—'}</span>
                <button
                  type="button"
                  class="icon-btn"
                  title="Изменить типы цен"
                  aria-label="Изменить типы цен"
                  disabled={priceBusy}
                  onclick={openPriceTypes}
                >
                  <Icon name="edit" size={16} />
                </button>
              </div>
            {/if}
          </div>
          {/if}

          <div class="card">
            <div class="card-head">
              <h2>История профиля</h2>
            </div>
            {#if accountHistory.length === 0}
              <p class="empty">Событий пока нет</p>
            {:else}
              <ul class="account-events">
                {#each accountHistory.slice(0, 20) as item (item.id)}
                  <li class={eventTone(item.event)}>
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

    <Modal open={convertOpen} title="Сделать сотрудником" onclose={() => (convertOpen = false)}>
      <p class="hint">
        Учётная запись останется той же: ID, заявки, история и переписка сохранятся. Клиент
        получит доступ к разделам персонала, а все активные сессии будут завершены.
      </p>
      <label class="edit-label">
        <span>Роль</span>
        <select bind:value={convertRole}>
          <option value="10">{staffLevelTitle(10)}</option>
          <option value="50">{staffLevelTitle(50)}</option>
          <option value="90">{staffLevelTitle(90)}</option>
        </select>
      </label>
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
        <Button variant="ghost" disabled={convertSaving} onclick={() => (convertOpen = false)}>
          Отмена
        </Button>
        <Button
          loading={convertSaving}
          disabled={convertLoading || convertPreview?.can !== true}
          onclick={() => void confirmConvert()}
        >
          Сделать сотрудником
        </Button>
      </div>
    </Modal>

    <Modal
      open={sessionsConfirmOpen}
      title="Завершить все сессии?"
      onclose={() => (sessionsConfirmOpen = false)}
    >
      <p class="hint">
        Все активные сессии клиента будут завершены. Для входа потребуется снова ввести логин и
        пароль.
      </p>
      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span class="notify-text">{actionError}</span>
        </div>
      {/if}
      <div class="modal-actions">
        <Button variant="ghost" disabled={sessionsBusy} onclick={() => (sessionsConfirmOpen = false)}>
          Отмена
        </Button>
        <Button loading={sessionsBusy} onclick={() => void confirmRevokeSessions()}>
          Завершить сессии
        </Button>
      </div>
    </Modal>

    <Modal open={passwordConfirmOpen} title="Сбросить пароль?" onclose={() => (passwordConfirmOpen = false)}>
      <p class="hint">
        Текущий пароль перестанет действовать, все сеансы клиента будут завершены. Новый пароль
        будет показан один раз — сохраните его и передайте клиенту.
      </p>
      {#if card.client.email && appSettings.mailConfigured && auth.can('mail.recovery')}
        <p class="hint">
          Или отправьте клиенту ссылку для самостоятельной смены пароля на {card.client.email}.
        </p>
      {/if}

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span class="notify-text">{actionError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button
          variant="ghost"
          disabled={busy || passwordLinkBusy}
          onclick={() => (passwordConfirmOpen = false)}
        >
          Отмена
        </Button>
        {#if card.client.email && appSettings.mailConfigured && auth.can('mail.recovery')}
          <Button
            variant="ghost"
            loading={passwordLinkBusy}
            disabled={busy}
            onclick={() => void confirmPasswordLink()}
          >
            Отправить ссылку
          </Button>
        {/if}
        <Button
          loading={busy}
          disabled={passwordLinkBusy}
          onclick={() => void confirmPasswordReset()}
        >
          Сбросить пароль
        </Button>
      </div>
    </Modal>

    <Modal
      open={passwordResult !== null}
      title="Новый пароль"
      persistent
      closeButton={false}
      onclose={closePasswordResult}
    >
      <p class="hint">
        Пароль показывается один раз. После закрытия окна посмотреть его нельзя — только сбросить
        заново. Попросите клиента сменить пароль в профиле после входа.
      </p>

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span class="notify-text">{actionError}</span>
        </div>
      {/if}

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

    <Modal
      open={requestsFiltersOpen}
      title="Фильтры"
      onclose={() => (requestsFiltersOpen = false)}
    >
      <div class="filters-form">
        <div class="filter-group">
          <span class="filter-label">Состояние</span>
          <MultiSelect
            label="Состояние"
            options={requestStateOptions}
            bind:values={requestsFilterDraft.states}
            allLabel="Все состояния"
            emptyValuesLabel="Все состояния"
          />
        </div>

        <div class="filter-group">
          <span class="filter-label">Статус</span>
          <MultiSelect
            label="Статус"
            options={requestStatusOptions}
            bind:values={requestsFilterDraft.statuses}
            allLabel="Все статусы"
            emptyValuesLabel="Все статусы"
            maxHeight="280px"
          />
        </div>

        <div class="view-period">
          <label class="filter-field">
            <span>Период с</span>
            <input
              type="date"
              bind:value={requestsFilterDraft.from}
              max={requestsFilterDraft.to || undefined}
            />
          </label>
          <label class="filter-field">
            <span>Период по</span>
            <input
              type="date"
              bind:value={requestsFilterDraft.to}
              min={requestsFilterDraft.from || undefined}
            />
          </label>
        </div>

        <div class="modal-actions">
          <Button variant="ghost" onclick={resetRequestsFilters}>Сбросить</Button>
        <div class="modal-buttons">
          <Button variant="ghost" onclick={() => (requestsFiltersOpen = false)}>Отмена</Button>
          <Button onclick={applyRequestsFilters}>Применить</Button>
        </div>
        </div>
      </div>
    </Modal>

    <SortDialog
      open={requestsSortOpen}
      fields={requestSortFields}
      sort={requestsSort}
      defaultValue="created_desc"
      onclose={() => (requestsSortOpen = false)}
      onapply={applyRequestsSort}
    />

    <Modal
      open={interestsOpen}
      title="Целевой интерес к номенклатуре"
      wide
      onclose={() => (interestsOpen = false)}
    >
      <p class="hint">Что клиент заказывал — для будущих целевых продаж (без отменённых заявок)</p>

      <div class="block-toolbar">
        <div class="search">
          <SearchInput
            bind:value={interestsInput}
            historyKey="client-interests"
            placeholder="Поиск номенклатуры"
          />
        </div>

        <button
          type="button"
          class="toolbar-btn"
          class:active={interestsActiveFilters > 0}
          title="Фильтры"
          aria-label={interestsActiveFilters > 0
            ? `Фильтры, активно: ${interestsActiveFilters}`
            : 'Фильтры'}
          onclick={openInterestsFilters}
        >
          <Icon name="filter" size={16} />
          {#if interestsActiveFilters > 0}
            <span class="filter-badge">{interestsActiveFilters}</span>
          {/if}
        </button>

        <button
          type="button"
          class="toolbar-btn"
          class:active={interestsSort !== 'qty_desc'}
          title="Сортировка"
          aria-label="Сортировка"
          onclick={openInterestsSort}
        >
          <Icon name="sort" size={16} />
        </button>
      </div>

      {@render interestsResults()}
    </Modal>

    <Modal
      open={interestsFiltersOpen}
      title="Фильтры"
      onclose={() => (interestsFiltersOpen = false)}
    >
      <p class="sort-hint">Если ничего не выбрано — показываются все позиции.</p>

      <div class="filter-group">
        <span class="filter-group-label">Склад</span>
        <div class="filter-chips">
          <button
            type="button"
            class="filter-chip"
            class:on={interestsFilterDraft.warehouses.length === 0}
            aria-pressed={interestsFilterDraft.warehouses.length === 0}
            onclick={() => (interestsFilterDraft.warehouses = [])}
          >
            Все
          </button>
          {#each interestWarehouseOptions as warehouse (warehouse)}
            <button
              type="button"
              class="filter-chip"
              class:on={interestsFilterDraft.warehouses.includes(warehouse)}
              aria-pressed={interestsFilterDraft.warehouses.includes(warehouse)}
              onclick={() => (interestsFilterDraft.warehouses = interestsFilterDraft.warehouses.includes(warehouse)
                ? interestsFilterDraft.warehouses.filter((value) => value !== warehouse)
                : [...interestsFilterDraft.warehouses, warehouse])}
            >
              {warehouse}
            </button>
          {/each}
        </div>
        {#if interestWarehouseOptions.length === 0}
          <p class="hint">Склады появятся после заявок клиента.</p>
        {/if}
      </div>

      <div class="filter-period">
        <label class="filter-field">
          <span>Период с</span>
          <input type="date" bind:value={interestsFilterDraft.from} max={interestsFilterDraft.to} />
        </label>
        <label class="filter-field">
          <span>Период по</span>
          <input type="date" bind:value={interestsFilterDraft.to} min={interestsFilterDraft.from} />
        </label>
      </div>

      <div class="modal-actions">
        <Button variant="ghost" onclick={resetInterestsFilters}>Сбросить</Button>
      <div class="modal-buttons">
        <Button variant="ghost" onclick={() => (interestsFiltersOpen = false)}>Отмена</Button>
        <Button onclick={applyInterestsFilters}>Применить</Button>
      </div>
      </div>
    </Modal>

    <SortDialog
      open={interestsSortOpen}
      fields={interestSortFields}
      sort={interestsSort}
      defaultValue="qty_desc"
      onclose={() => (interestsSortOpen = false)}
      onapply={applyInterestsSort}
    />

    <Modal
      open={contactEditField !== ''}
      title={activeContactField?.title ?? 'Изменить контакт'}
      onclose={cancelContactEdit}
    >
      {#if activeContactField}
        <div class="form">
          <label class="edit-label">
            <span>{activeContactField.label}</span>
            <input
              bind:value={contactEditValue}
              type={activeContactField.type}
              inputmode={activeContactField.inputmode}
              maxlength={activeContactField.maxlength}
              onkeydown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault();
                  void saveContactField();
                }
              }}
            />
          </label>
        </div>
        <p class="hint">{activeContactField.hint}</p>
      {/if}

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span class="notify-text">{actionError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={contactEditBusy} onclick={cancelContactEdit}>Отмена</Button>
        <Button
          loading={contactEditBusy}
          disabled={!activeContactField}
          onclick={() => void saveContactField()}
        >
          Сохранить
        </Button>
      </div>
    </Modal>

    <Modal open={priceTypesOpen} title="Типы цен" onclose={cancelPriceTypes}>
      {#if priceTypes.length === 0}
        <p class="empty">Типы цен не настроены</p>
      {:else}
        <MultiSelect
          label="Типы цен"
          options={priceTypeOptions}
          bind:values={priceTypesDraft}
          allLabel={allPriceTypeTitles}
          emptyLabel="Типы цен не настроены"
          emptyValuesLabel="Не выбрано"
          maxHeight="240px"
          disabled={priceBusy}
        />
      {/if}

      {#if priceTypesError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span class="notify-text">{priceTypesError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={priceBusy} onclick={cancelPriceTypes}>Отмена</Button>
        <Button
          loading={priceBusy}
          disabled={priceTypes.length === 0}
          onclick={() => void savePriceTypes()}
        >
          Сохранить
        </Button>
      </div>
    </Modal>

    <Modal open={loginOpen} title="Изменить логин" onclose={() => (loginOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Новый логин</span>
          <input
            bind:value={loginForm.login}
            maxlength="64"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
          />
        </label>
      </div>
      <p class="hint">
        Логин — 3–64 символа: латиница, цифры, точка, дефис, подчёркивание или @. Все активные
        сеансы клиента будут завершены.
      </p>

      {#if actionError}
        <div class="alert" role="alert">
          <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
          <span>{actionError}</span>
        </div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={loginBusy} onclick={() => (loginOpen = false)}>Отмена</Button>
        <Button
          loading={loginBusy}
          disabled={!isValidLogin(loginForm.login.trim())
            || loginForm.login.trim().toLowerCase() === card.client.login.toLowerCase()}
          onclick={() => void saveLogin()}
        >
          Сохранить
        </Button>
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
        {#if managerCommentOpen}
          <label class="edit-label">
            <span>Комментарий</span>
            <input bind:value={managerForm.comment} placeholder="Например: закрепляю за собой" maxlength="500" />
          </label>
        {:else}
          <button
            type="button"
            class="link-btn manager-comment-toggle"
            onclick={() => (managerCommentOpen = true)}
          >
            Добавить комментарий
          </button>
        {/if}
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

  .notify-text {
    flex: 1;
  }

  .notify-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
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

  .list-error {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--error-border);
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--error-text);
    font-size: 13px;
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

  .table-scroll {
    overflow-x: auto;
  }

  .interests {
    display: flex;
    flex-direction: column;
    min-width: 620px;
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

  .hero-actions {
    display: flex;
    align-items: center;
    gap: var(--space-1);
  }

  .hero-tabs {
    display: flex;
    align-items: center;
    gap: var(--space-1);
  }

  .hero-tab {
    position: relative;
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

  .hero-tab:hover {
    color: var(--primary);
    background: var(--bg);
  }

  .hero-tab:disabled {
    opacity: 0.6;
    cursor: default;
  }

  .hero-tab.active {
    border-color: var(--primary-border);
    background: var(--primary-bg);
    color: var(--primary);
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
    margin-top: auto;
    padding-top: var(--space-3);
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

  .edit-label input:focus,
  .edit-label select:focus,
  .edit-label textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
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
    flex-wrap: wrap;
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

  .card:has(.pager-wrap) {
    display: flex;
    flex-direction: column;
  }

  .state {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid transparent;
    color: var(--muted);
  }

  .state.on {
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

  .hero-main {
    flex: 1 1 auto;
    min-width: 0;
  }

  .hero h1 {
    margin: 0;
    min-width: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    overflow-wrap: anywhere;
  }

  .hero-role {
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

  .hero-status {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .hero-status .state {
    padding: 0 6px;
    border: none;
    border-radius: 0;
    font-size: 13px;
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
    position: relative;
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    overflow: hidden;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-family: inherit;
    font-size: 18px;
    font-weight: 600;
  }

  .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar-edit {
    position: relative;
    flex: 0 0 auto;
  }

  .avatar-btn {
    padding: 0;
    border: none;
    cursor: pointer;
  }

  .avatar-btn:disabled {
    cursor: default;
  }

  .avatar-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    opacity: 0;
    transition: opacity 0.15s ease;
  }

  .avatar-btn:hover .avatar-overlay,
  .avatar-btn:focus-visible .avatar-overlay,
  .avatar-btn:disabled .avatar-overlay {
    opacity: 1;
  }

  .avatar-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--danger);
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
  }

  .avatar-remove:hover {
    background: var(--danger);
    color: #fff;
  }

  .avatar-remove:disabled {
    opacity: 0.6;
    cursor: default;
  }

  .avatar-input {
    display: none;
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

  .card-head-tools {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease, background 0.12s ease;
  }

  .icon-btn:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .icon-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
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

  .manager-comment-toggle {
    align-self: flex-start;
  }

  .mini-sub {
    font-size: 12px;
    color: var(--muted);
  }

  .manager-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .manager-row .manager-box {
    flex: 1 1 auto;
    min-width: 0;
  }

  .manager-actions {
    display: flex;
    flex: 0 0 auto;
    gap: var(--space-1);
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

  .price-types-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .price-types-value {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .price-loading {
    display: flex;
    justify-content: center;
    padding: var(--space-3);
  }

  .account {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
    gap: var(--space-5);
    align-items: start;
  }

  .account-main,
  .account-side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    min-width: 0;
    position: sticky;
    top: var(--space-4);
    align-self: start;
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

  .account-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }

  .field-groups {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .field-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .field-group-title {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
  }

  .rows {
    display: flex;
    flex-direction: column;
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

  .copy-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .copy-row code {
    padding: 3px 10px;
    border-radius: var(--radius-sm);
    background: var(--bg);
    font-size: 14px;
    font-weight: 500;
    overflow-wrap: anywhere;
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
    position: absolute;
    top: -5px;
    right: -5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
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

  .requests-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: var(--space-5);
    align-items: start;
  }

  .requests-main,
  .requests-side {
    min-width: 0;
  }

  .requests-side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    position: sticky;
    top: var(--space-4);
    align-self: start;
  }

  .toolbar-btn {
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
    transition: border-color 0.12s ease, color 0.12s ease, background 0.12s ease;
  }

  .toolbar-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .toolbar-btn.active {
    border-color: var(--primary);
    background: var(--primary-bg);
    color: var(--primary);
  }

  .filter-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
  }

  .filter-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .filters-form .filter-group {
    display: block;
    margin-bottom: 0;
  }

  .filter-group-label {
    font-size: 13px;
    color: var(--muted);
  }

  .filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .filter-chip {
    padding: 5px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    font: inherit;
    font-size: 13px;
    color: var(--text);
    cursor: pointer;
  }

  .filter-chip:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .filter-chip.on {
    border-color: var(--primary);
    background: var(--primary-bg);
    color: var(--primary);
  }

  .filter-period {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-bottom: var(--space-2);
  }

  .filter-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  .filter-field input {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .sort-hint {
    margin: 0 0 var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .request-history {
    display: flex;
    flex-direction: column;
    max-height: min(520px, 60vh);
    overflow-y: auto;
    padding-right: var(--space-2);
  }

  .interest-preview {
    max-height: min(320px, 38vh);
  }

  .history-row {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: var(--space-3) 0;
    border-bottom: 1px solid var(--border-secondary);
    color: inherit;
    text-decoration: none;
  }

  .history-row:first-child {
    padding-top: 0;
  }

  .history-row:last-child {
    padding-bottom: 0;
    border-bottom: none;
  }

  .history-row:hover .history-number {
    color: var(--primary);
  }

  .history-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .history-number {
    font-size: 13px;
    font-weight: 600;
  }

  .history-date {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .history-subject {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
  }

  .history-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    font-size: 12px;
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

  .drafts-list {
    max-height: min(320px, 38vh);
  }

  .draft-meta {
    color: var(--muted);
    font-size: 12px;
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

  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-4) 0;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  @media (max-width: 1024px) {
    .account,
    .requests-layout {
      grid-template-columns: minmax(0, 1fr);
    }

    .account-side,
    .requests-side {
      position: static;
    }
  }

  @media (max-width: 640px) {
    .hero {
      align-items: flex-start;
    }
  }
</style>
