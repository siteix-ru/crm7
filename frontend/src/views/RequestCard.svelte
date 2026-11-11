<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { ApiError, downloadFromApi } from '../lib/api/client';
  import { listMessages, markThreadRead, sendMessage } from '../lib/api/chat';
  import {
    addActivity,
    assignRequest,
    claimRequest,
    getRequest,
    listActivityTypes,
    listManagers,
    listStatuses,
    transitionRequest,
    updateRequestItems,
    updateRequestMeta,
    type CreateRequestItem
  } from '../lib/api/requests';
  import type {
    ActivityType,
    ChatMessage,
    ClientCard,
    ManagerItem,
    RequestAttachment,
    RequestDetail,
    RequestItemRow,
    RequestStatus
  } from '../lib/api/types';
  import { getClientCard } from '../lib/api/clients';
  import ItemsPicker from '../lib/components/requests/ItemsPicker.svelte';
  import {
    deleteAttachment,
    downloadAttachment,
    listAttachments,
    uploadAttachment
  } from '../lib/api/attachments';
  import { router } from '../lib/router.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDate, formatDateTime, formatPrice, formatSize, priorityLabel } from '../lib/format';
  import { sortItems, type ItemsSortKey } from '../lib/items-sort';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let detail = $state<RequestDetail | null>(null);
  let statuses = $state<RequestStatus[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let loading = $state(true);
  let error = $state('');
  let actionError = $state('');
  let busy = $state(false);

  const showPrices = $derived(detail !== null && detail.prices?.enabled === true);
  const totalQuantity = $derived(
    detail ? detail.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0) : 0
  );
  const totalQuantityText = $derived(
    totalQuantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })
  );
  const descriptionLong = $derived((detail?.request.body.length ?? 0) > 400);

  let targetStatus = $state('');
  let transitionComment = $state('');
  let cardTab = $state<CardTab>('request');
  let helperOpen = $state(false);
  let helperToggleEl = $state<HTMLButtonElement | null>(null);
  let helperEl = $state<HTMLDivElement | null>(null);
  let statusOpen = $state(false);
  let transferOpen = $state(false);
  let shareWithClient = $state(false);
  let actionNotice = $state('');
  let activityTypes = $state<ActivityType[]>([]);

  type HistoryItem = {
    kind: 'comment' | 'activity' | 'status';
    id: number;
    at: string;
    user: { id: number; name: string };
    title: string;
    body: string;
    clientVisible: boolean;
    attachment: { id: number; name: string; size: number } | null;
  };

  const historyFeed = $derived.by((): HistoryItem[] => {
    if (!detail) return [];

    const items: HistoryItem[] = [
      ...detail.comments.map((comment) => ({
        kind: 'comment' as const,
        id: comment.id,
        at: comment.created_at,
        user: comment.user,
        title: 'Комментарий',
        body: comment.body,
        clientVisible: !comment.is_internal,
        attachment: null
      })),
      ...detail.activities.map((activity) => ({
        kind: 'activity' as const,
        id: activity.id,
        at: activity.created_at,
        user: activity.user,
        title: activity.type.title,
        body: activity.body,
        clientVisible: !activity.is_internal,
        attachment: activity.attachment
      })),
      ...detail.history.map((event) => ({
        kind: 'status' as const,
        id: event.id,
        at: event.created_at,
        user: event.user,
        title:
          event.from && event.to && event.from.code !== event.to.code
            ? `Статус: ${event.from.title} → ${event.to.title}`
            : 'Статус изменён',
        body: event.comment,
        clientVisible: true,
        attachment: null
      }))
    ];

    return items.sort((a, b) => (a.at === b.at ? b.id - a.id : a.at < b.at ? 1 : -1));
  });

  const historyCount = $derived(historyFeed.length);
  let chatUnread = $state(0);

  let priorityOpen = $state(false);
  let dueOpen = $state(false);
  let metaPriority = $state(2);
  let metaDue = $state('');
  let metaDueComment = $state('');
  let metaBusy = $state(false);

  let subjectOpen = $state(false);
  let bodyOpen = $state(false);
  let subjectDraft = $state('');
  let bodyDraft = $state('');

  let itemModalOpen = $state(false);
  let itemModalId = $state(0);
  let itemModalName = $state('');
  let itemModalUnit = $state('');
  let itemModalQty = $state(1);
  let expandedItems = $state<Set<number>>(new Set());
  let descriptionExpanded = $state(false);

  let deleteOpen = $state(false);
  let deleteItemId = $state(0);
  let deleteItemName = $state('');
  let deleteComment = $state('');
  let deleteFromQty = $state(false);
  let fileDeleteOpen = $state(false);
  let fileToDelete = $state<RequestAttachment | null>(null);

  function qtyText(item: RequestItemRow): string {
    const value = item.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 });

    return item.unit ? `${value} ${item.unit}` : value;
  }

  function toggleItemDescription(itemId: number): void {
    const next = new Set(expandedItems);

    if (next.has(itemId)) {
      next.delete(itemId);
    } else {
      next.add(itemId);
    }

    expandedItems = next;
  }

  function openItemModal(item: RequestItemRow): void {
    itemModalId = item.id;
    itemModalName = item.name;
    itemModalUnit = item.unit;
    itemModalQty = item.quantity;
    itemModalOpen = true;
  }

  function changeItemQty(delta: number): void {
    itemModalQty = Math.max(0, Number(itemModalQty ?? 0) + delta);
  }

  function itemPayload(overrides: Map<number, number>, skipId = 0): CreateRequestItem[] {
    if (!detail) {
      return [];
    }

    return detail.items
      .filter((item) => item.id !== skipId)
      .map((item) => ({
        warehouse_id: item.warehouse_id,
        warehouse_name: item.warehouse_name,
        stock_level_id: item.stock_level_id,
        name: item.name,
        unit: item.unit,
        description: item.description,
        quantity: overrides.has(item.id) ? Number(overrides.get(item.id)) : item.quantity
      }))
      .filter((item) => item.quantity > 0);
  }

  async function applyItems(
    items: CreateRequestItem[],
    comment = '',
    version = detail?.request.version ?? 0
  ): Promise<boolean> {
    if (!detail || busy) {
      return false;
    }

    busy = true;
    actionError = '';

    try {
      detail = await updateRequestItems(id, items, version, comment);
      actionNotice = 'Состав обновлён';

      return true;
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить состав');

      return false;
    } finally {
      busy = false;
    }
  }

  let pickerVersion = 0;

  async function persistItems(
    items: CreateRequestItem[],
    version = detail?.request.version ?? 0
  ): Promise<boolean> {
    if (!detail || busy) {
      return false;
    }

    busy = true;
    actionError = '';

    try {
      detail = await updateRequestItems(id, items, version, '');
      actionNotice = 'Состав обновлён';

      return true;
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить состав');

      return false;
    } finally {
      busy = false;
    }
  }

  function itemsMatchDetail(items: CreateRequestItem[]): boolean {
    if (!detail) {
      return false;
    }

    const current = detail.items.map((item) => ({
      name: item.name,
      unit: item.unit,
      quantity: item.quantity,
      warehouse_id: item.warehouse_id,
      warehouse_name: item.warehouse_name,
      description: item.description,
      stock_level_id: item.stock_level_id
    }));

    return JSON.stringify(current) === JSON.stringify(items);
  }

  async function applyPickerItems(items: CreateRequestItem[]): Promise<boolean> {
    const version = pickerVersion || detail?.request.version || 0;
    itemRows = items;

    if (itemsMatchDetail(items)) {
      return true;
    }

    return persistItems(items, version);
  }

  function closePicker(): void {
    pickerOpen = false;
  }

  async function saveItemQty(): Promise<void> {
    if (busy) {
      return;
    }

    const rawQuantity = String(itemModalQty ?? '').trim();

    if (rawQuantity === '') {
      return;
    }

    const quantity = Number(rawQuantity.replace(',', '.'));

    if (!Number.isFinite(quantity)) {
      return;
    }

    if (quantity <= 0) {
      deleteItemId = itemModalId;
      deleteItemName = itemModalName;
      deleteComment = '';
      deleteFromQty = true;
      itemModalOpen = false;
      deleteOpen = true;
      return;
    }

    const ok = await applyItems(itemPayload(new Map([[itemModalId, quantity]])));

    if (ok) {
      itemModalOpen = false;
    }
  }

  function removeItem(item: RequestItemRow): void {
    deleteItemId = item.id;
    deleteItemName = item.name;
    deleteComment = '';
    deleteFromQty = false;
    deleteOpen = true;
  }

  async function confirmDelete(): Promise<void> {
    if (busy) {
      return;
    }

    const ok = await applyItems(itemPayload(new Map(), deleteItemId), deleteComment.trim());

    if (ok) {
      deleteOpen = false;
    }
  }

  async function cancelDelete(): Promise<void> {
    deleteOpen = false;
    deleteFromQty = false;
  }
  const priorityOptions: { value: number; label: string }[] = [
    { value: 1, label: 'Низкий' },
    { value: 2, label: 'Обычный' },
    { value: 3, label: 'Высокий' }
  ];
  let itemsSort = $state<ItemsSortKey>('natural');
  let itemsSortOpen = $state(false);
  let itemsSortDraft = $state<ItemsSortKey>('natural');
  let copyOpen = $state(false);

  const itemsSortFields: { label: string; asc: ItemsSortKey; desc: ItemsSortKey }[] = [
    { label: 'Название', asc: 'name_asc', desc: 'name_desc' },
    { label: 'Склад', asc: 'warehouse_asc', desc: 'warehouse_desc' },
    { label: 'Количество', asc: 'qty_asc', desc: 'qty_desc' }
  ];

  let itemRows = $state<CreateRequestItem[]>([]);
  let pickerOpen = $state(false);

  const isStaff = $derived(auth.level >= 10);

  let transferTarget = $state(0);
  let transferComment = $state('');

  let attachments = $state<RequestAttachment[]>([]);
  let clientCard = $state<ClientCard | null>(null);
  let clientLoading = $state(false);
  let clientError = $state('');
  let uploading = $state(false);
  let uploadFile = $state<File | null>(null);
  let uploadInternal = $state(true);
  let fileComment = $state('');
  let fileCommentOpen = $state(false);

  let eventSubject = $state('');
  let eventComment = $state('');
  let commentOpen = $state(false);
  let fileOpen = $state(false);
  let selectedFile = $state<File | null>(null);
  let uploadedAttachment = $state<RequestAttachment | null>(null);

  const eventPresets = $derived(activityTypes.filter((type) => type.code !== 'custom'));

  const eventTypeCode = $derived.by(() => {
    const normalized = eventSubject.trim().toLowerCase();

    if (normalized !== '') {
      const preset = eventPresets.find(
        (type) => type.title.trim().toLowerCase() === normalized
      );

      if (preset) {
        return preset.code;
      }
    }

    return 'custom';
  });

  const canSaveEvent = $derived.by(() => {
    if (!detail || eventSubject.trim() === '') return false;

    return !(eventTypeCode.startsWith('other') && eventComment.trim().length < 3);
  });

  let chatLoading = $state(false);
  let chatBusy = $state(false);
  let chatBody = $state('');
  let chatMessages = $state<ChatMessage[]>([]);
  let chatListEl = $state<HTMLDivElement | null>(null);

  type CardTab = 'request' | 'client' | 'comments' | 'chat' | 'files';

  const AUTO_REFRESH_MS = 15000;
  let loadSeq = 0;
  let autoRefreshBusy = false;

  onMount(() => {
    void load();
    void listStatuses()
      .then((data) => {
        statuses = data;
      })
      .catch(() => {
        statuses = [];
      });
    void listManagers()
      .then((data) => {
        managers = data.items;
      })
      .catch(() => {
        managers = [];
      });
    void listActivityTypes()
      .then((data) => {
        activityTypes = data;
      })
      .catch(() => {
        activityTypes = [];
      });

    const timer = window.setInterval(() => {
      void autoRefresh();
    }, AUTO_REFRESH_MS);
    const onFocus = (): void => {
      void autoRefresh();
    };
    const onVisibility = (): void => {
      if (document.visibilityState === 'visible') {
        void autoRefresh();
      }
    };

    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onVisibility);

    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  });

  onMount(() => {
    const onPointerDown = (event: PointerEvent): void => {
      if (!helperOpen) {
        return;
      }

      const target = event.target;

      if (!(target instanceof Node)) {
        return;
      }

      if (helperEl?.contains(target) || helperToggleEl?.contains(target)) {
        return;
      }

      helperOpen = false;
    };
    const onKeyDown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape' && helperOpen) {
        helperOpen = false;
        helperToggleEl?.focus();
      }
    };

    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('pointerdown', onPointerDown, true);
      document.removeEventListener('keydown', onKeyDown);
    };
  });

  async function load(options: { silent?: boolean; force?: boolean } = {}): Promise<void> {
    const silent = options.silent === true;
    const force = options.force === true;
    const seq = ++loadSeq;
    const detailRef = detail;
    const previousVersion = detail?.request.version ?? null;

    if (!silent) {
      loading = true;
      error = '';
    }

    try {
      const data = await getRequest(id);

      if (seq !== loadSeq) {
        return;
      }

      if (silent && detail !== detailRef) {
        return;
      }

      if (silent && !force && !autoRefreshAllowed()) {
        return;
      }

      if (silent && previousVersion !== null && data.request.version < previousVersion) {
        return;
      }

      detail = data;
      chatUnread = detail.chat.unread ?? 0;

      if (
        silent
        && !force
        && previousVersion !== null
        && data.request.version > previousVersion
        && actionNotice === ''
        && actionError === ''
      ) {
        actionNotice = 'Заявка обновлена: есть изменения от других пользователей';
      }

      try {
        const attachmentData = await listAttachments(id);

        if (seq === loadSeq) {
          attachments = attachmentData.items;
        }
      } catch {
        if (seq === loadSeq) {
          attachments = [];
        }
      }

      error = '';
    } catch (cause) {
      if (seq !== loadSeq) {
        return;
      }

      if (silent && !(cause instanceof ApiError && cause.status === 404)) {
        return;
      }

      if (cause instanceof ApiError && cause.status === 404) {
        detail = null;
        attachments = [];
        error = 'Заявка удалена или недоступна';
        return;
      }

      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявку';
    } finally {
      if (seq === loadSeq && !silent) {
        loading = false;
      }
    }
  }

  function anyModalOpen(): boolean {
    return statusOpen
      || subjectOpen
      || bodyOpen
      || priorityOpen
      || dueOpen
      || transferOpen
      || itemModalOpen
      || deleteOpen
      || fileDeleteOpen
      || itemsSortOpen
      || copyOpen
      || pickerOpen;
  }

  function autoRefreshAllowed(): boolean {
    if (document.visibilityState === 'hidden') {
      return false;
    }

    if (loading || busy || uploading || chatBusy || chatLoading) {
      return false;
    }

    return !anyModalOpen();
  }

  async function autoRefresh(): Promise<void> {
    if (autoRefreshBusy || !autoRefreshAllowed()) {
      return;
    }

    autoRefreshBusy = true;

    try {
      await load({ silent: true });

      if (cardTab === 'chat' && detail && detail.chat.thread_id > 0) {
        const loaded = await loadChat(true);

        if (loaded && chatUnread > 0) {
          const lastId = chatMessages.reduce((max, message) => Math.max(max, message.id), 0);

          if (lastId > 0) {
            try {
              await markThreadRead(detail.chat.thread_id, lastId);
              chatUnread = 0;
            } catch {
              // Игнорируем
            }
          }
        }
      }
    } finally {
      autoRefreshBusy = false;
    }
  }

  function closeActionModals(): void {
    statusOpen = false;
    subjectOpen = false;
    bodyOpen = false;
    priorityOpen = false;
    dueOpen = false;
    transferOpen = false;
    itemModalOpen = false;
    deleteOpen = false;
    fileDeleteOpen = false;
    fileToDelete = null;
    itemsSortOpen = false;
    copyOpen = false;
    pickerOpen = false;
  }

  function handleActionError(cause: unknown, fallback: string): void {
    if (cause instanceof ApiError) {
      if (cause.status === 409) {
        actionError = cause.code === 'version_conflict'
          ? 'Заявка изменена другим пользователем. Данные обновлены — проверьте изменения и повторите действие.'
          : cause.message;
        closeActionModals();
        void load({ silent: true, force: true });
        return;
      }

      if (cause.status === 404) {
        detail = null;
        attachments = [];
        error = 'Заявка удалена или недоступна';
        return;
      }

      actionError = cause.message;
      return;
    }

    actionError = fallback;
  }

  function onUploadFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    uploadFile = input.files?.[0] ?? null;
    input.value = '';
  }

  function fileExtension(name: string): string {
    const parts = name.split('.');

    if (parts.length < 2) {
      return 'FILE';
    }

    const extension = (parts.pop() ?? '').toUpperCase();

    return extension.length > 4 ? extension.slice(0, 4) : extension;
  }

  function onFileDrop(event: DragEvent): void {
    event.preventDefault();
    uploadFile = event.dataTransfer?.files?.[0] ?? null;
  }

  function openFileComment(): void {
    fileCommentOpen = true;
  }

  function closeFileComment(): void {
    fileCommentOpen = false;
    fileComment = '';
  }

  async function submitFileUpload(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail || !uploadFile || uploading) return;

    uploading = true;
    actionError = '';

    try {
      const isInternal = isStaff ? uploadInternal : false;
      const list = await uploadAttachment(id, uploadFile, isInternal, fileComment.trim());
      attachments = list.items;
      uploadFile = null;
      uploadInternal = true;
      fileComment = '';
      fileCommentOpen = false;
      actionNotice = isInternal ? 'Внутренний файл загружен' : 'Файл загружен';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить файл';
    } finally {
      uploading = false;
    }
  }

  function requestFileDelete(attachment: RequestAttachment): void {
    fileToDelete = attachment;
    fileDeleteOpen = true;
  }

  async function confirmFileDelete(): Promise<void> {
    if (!fileToDelete || busy) {
      return;
    }

    const attachment = fileToDelete;
    busy = true;
    actionError = '';

    try {
      await deleteAttachment(attachment.id);
      attachments = attachments.filter((item) => item.id !== attachment.id);
      fileDeleteOpen = false;
      fileToDelete = null;
      actionNotice = 'Файл удалён';
    } catch (cause) {
      fileDeleteOpen = false;
      fileToDelete = null;
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось удалить файл';
    } finally {
      busy = false;
    }
  }

  function cancelFileDelete(): void {
    if (busy) {
      return;
    }

    fileDeleteOpen = false;
    fileToDelete = null;
  }

  async function downloadFile(attachment: RequestAttachment): Promise<void> {
    actionError = '';

    try {
      await downloadAttachment(attachment.id, attachment.name);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось скачать файл';
    }
  }

  async function claim(): Promise<void> {
    if (busy) {
      return;
    }

    busy = true;
    actionError = '';

    try {
      detail = await claimRequest(id);
      actionNotice = 'Заявка взята в работу';
    } catch (cause) {
      handleActionError(cause, 'Не удалось взять заявку');
    } finally {
      busy = false;
    }
  }

  function openTransfer(): void {
    if (!detail?.can.assign) {
      return;
    }

    transferTarget = 0;
    transferComment = '';
    transferOpen = true;
  }

  function toLocalInput(value: string | null): string {
    if (!value) {
      return '';
    }

    const date = new Date(value.replace(' ', 'T'));

    if (Number.isNaN(date.getTime())) {
      return '';
    }

    const pad = (part: number): string => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function openSubject(): void {
    if (!detail) {
      return;
    }

    subjectDraft = detail.request.subject;
    subjectOpen = true;
  }

  function openBody(): void {
    if (!detail) {
      return;
    }

    bodyDraft = detail.request.body;
    bodyOpen = true;
  }

  async function saveSubject(): Promise<void> {
    if (!detail || metaBusy) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(id, { subject: subjectDraft.trim() }, detail.request.version);
      subjectOpen = false;
      actionNotice = 'Название обновлено';
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить название');
    } finally {
      metaBusy = false;
    }
  }

  async function saveBody(): Promise<void> {
    if (!detail || metaBusy) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(id, { body: bodyDraft.trim() }, detail.request.version);
      bodyOpen = false;
      actionNotice = 'Описание обновлено';
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить описание');
    } finally {
      metaBusy = false;
    }
  }

  function openPriority(): void {
    if (!detail) {
      return;
    }

    metaPriority = detail.request.priority;
    priorityOpen = true;
  }

  function openDue(): void {
    if (!detail) {
      return;
    }

    metaDue = toLocalInput(detail.request.due_at);
    metaDueComment = '';
    dueOpen = true;
  }

  async function savePriority(): Promise<void> {
    if (!detail || metaBusy) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(id, { priority: metaPriority }, detail.request.version);
      priorityOpen = false;
      actionNotice = 'Приоритет обновлён';
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить приоритет');
    } finally {
      metaBusy = false;
    }
  }

  async function saveDue(): Promise<void> {
    if (!detail || metaBusy) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(
        id,
        { due_at: metaDue, comment: metaDueComment.trim() },
        detail.request.version
      );
      dueOpen = false;
      actionNotice = 'Срок обновлён';
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить срок');
    } finally {
      metaBusy = false;
    }
  }

  async function submitTransition(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail || busy) return;

    busy = true;
    actionError = '';

    try {
      detail = await transitionRequest(id, targetStatus, transitionComment, detail.request.version);
      targetStatus = '';
      transitionComment = '';
      statusOpen = false;
      actionNotice = 'Статус изменён';
    } catch (cause) {
      handleActionError(cause, 'Не удалось сменить статус');
    } finally {
      busy = false;
    }
  }

  function openStatus(): void {
    if (!detail) {
      return;
    }

    targetStatus = '';
    transitionComment = '';
    statusOpen = true;
  }

  const selectableStatuses = $derived(
    statuses.filter((status) => (detail?.can.transition_to ?? []).includes(status.code))
  );

  const transferableManagers = $derived(
    managers.filter((manager) => manager.id !== detail?.request.manager?.id)
  );

  async function submitTransfer(): Promise<void> {
    if (!detail || busy) return;

    busy = true;
    actionError = '';

    try {
      detail = await assignRequest(
        id,
        transferTarget,
        transferComment,
        detail.request.version
      );
      transferTarget = 0;
      transferComment = '';
      transferOpen = false;
      actionNotice = 'Заявка передана';
    } catch (cause) {
      handleActionError(cause, 'Не удалось передать заявку');
    } finally {
      busy = false;
    }
  }

  function returnToPrevious(): void {
    if (detail?.previous_manager) {
      transferTarget = detail.previous_manager.id;
    }
  }

  function openCopy(): void {
    copyOpen = true;
  }

  function confirmCopy(): void {
    copyOpen = false;
    router.navigate(`/requests/new?copy=${id}`);
  }

  function openItemsSort(): void {
    itemsSortDraft = itemsSort;
    itemsSortOpen = true;
  }

  function toggleItemsSort(key: ItemsSortKey): void {
    itemsSortDraft = itemsSortDraft === key ? 'natural' : key;
  }

  function resetItemsSort(): void {
    itemsSortDraft = 'natural';
  }

  function applyItemsSort(): void {
    itemsSort = itemsSortDraft;
    itemsSortOpen = false;
  }

  function openPicker(): void {
    if (!detail) {
      return;
    }

    pickerVersion = detail.request.version;
    itemRows = detail.items.map((item) => ({
      name: item.name,
      unit: item.unit,
      quantity: item.quantity,
      warehouse_id: item.warehouse_id,
      warehouse_name: item.warehouse_name,
      description: item.description,
      stock_level_id: item.stock_level_id
    }));
    pickerOpen = true;
  }

  async function scrollChatToBottom(smooth = false): Promise<void> {
    await tick();

    if (!chatListEl) {
      return;
    }

    chatListEl.scrollTo({
      top: chatListEl.scrollHeight,
      behavior: smooth ? 'smooth' : 'auto'
    });
  }

  async function loadChat(silent = false): Promise<boolean> {
    if (!detail || detail.chat.thread_id <= 0) {
      return false;
    }

    const followBottom =
      !chatListEl ||
      chatListEl.scrollHeight - chatListEl.clientHeight - chatListEl.scrollTop <= 24;

    if (!silent) {
      chatLoading = true;
    }

    try {
      const data = await listMessages(detail.chat.thread_id, 0, id);
      chatMessages = data.items;

      if (silent && followBottom) {
        void scrollChatToBottom();
      }

      return true;
    } catch (cause) {
      if (!silent) {
        actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сообщения';
      }

      return false;
    } finally {
      if (!silent) {
        chatLoading = false;
      }
    }
  }

  async function loadClientCard(): Promise<void> {
    if (!detail || !isStaff || clientLoading || clientCard !== null) {
      return;
    }

    clientLoading = true;
    clientError = '';

    try {
      clientCard = await getClientCard(detail.request.client.id);
    } catch (cause) {
      clientError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить данные клиента';
    } finally {
      clientLoading = false;
    }
  }

  async function openCardTab(next: CardTab): Promise<void> {
    cardTab = next;

    if (next === 'client') {
      await loadClientCard();
    }

    if (next === 'chat') {
      const loaded = await loadChat();
      await scrollChatToBottom();

      if (loaded && detail && detail.chat.thread_id > 0 && chatUnread > 0) {
        const lastId = chatMessages.reduce((max, message) => Math.max(max, message.id), 0);

        if (lastId > 0) {
          try {
            await markThreadRead(detail.chat.thread_id, lastId);
            chatUnread = 0;
          } catch {
            // Игнорируем
          }
        }
      }
    }
  }

  async function sendChat(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail || !chatBody.trim() || chatBusy) {
      return;
    }

    chatBusy = true;
    actionError = '';

    try {
      await sendMessage(detail.chat.thread_id, chatBody.trim(), [], id);
      chatBody = '';
      await loadChat();
      await scrollChatToBottom(true);
    } catch (cause) {
      handleActionError(cause, 'Не удалось отправить сообщение');
    } finally {
      chatBusy = false;
    }
  }

  async function downloadChatFile(attachmentId: number, name: string): Promise<void> {
    try {
      await downloadFromApi(`/chat/attachments/${attachmentId}`, name);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось скачать файл';
    }
  }

  function openComment(): void {
    commentOpen = true;
  }

  function closeComment(): void {
    commentOpen = false;
    eventComment = '';
  }

  async function openEventWithFile(): Promise<void> {
    await openCardTab('comments');
    openFile();
  }

  function openFile(): void {
    fileOpen = true;
  }

  function onEventFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    selectedFile = input.files?.[0] ?? null;
    input.value = '';
  }

  function clearSelectedEventFile(): void {
    selectedFile = null;
    fileOpen = false;
  }

  async function removeEventFile(): Promise<void> {
    const current = uploadedAttachment;

    if (current) {
      busy = true;
      actionError = '';

      try {
        await deleteAttachment(current.id);
        attachments = attachments.filter((item) => item.id !== current.id);
      } catch (cause) {
        handleActionError(cause, 'Не удалось убрать файл');
        return;
      } finally {
        busy = false;
      }
    }

    selectedFile = null;
    uploadedAttachment = null;
    fileOpen = false;
  }

  function resetEventForm(): void {
    eventSubject = '';
    eventComment = '';
    commentOpen = false;
    fileOpen = false;
    selectedFile = null;
    uploadedAttachment = null;
    shareWithClient = false;
  }

  async function saveEvent(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail || !canSaveEvent || busy) return;

    busy = true;
    actionError = '';

    const isInternal = detail.can.comment_internal ? !shareWithClient : false;

    try {
      let attachmentId = uploadedAttachment?.id ?? null;

      if (!attachmentId && selectedFile) {
        const before = new Set(attachments.map((item) => item.id));
        const list = await uploadAttachment(id, selectedFile, isInternal, '', false);
        attachments = list.items;
        const uploaded = list.items.find((item) => !before.has(item.id));

        if (!uploaded) {
          throw new Error('Не удалось определить загруженный файл');
        }

        uploadedAttachment = uploaded;
        selectedFile = null;
        attachmentId = uploaded.id;
      }

      detail = await addActivity(id, {
        type: eventTypeCode,
        subject: eventSubject.trim(),
        body: eventComment.trim(),
        isInternal,
        attachmentId: attachmentId ?? undefined
      });

      actionNotice = 'Событие добавлено';
      resetEventForm();
    } catch (cause) {
      handleActionError(cause, 'Не удалось добавить событие');
    } finally {
      busy = false;
    }
  }

</script>

<section class="page page-wide">
  <div class="page-hero">
    <div class="head">
      <button
        type="button"
        class="back"
        title="К списку заявок"
        aria-label="К списку заявок"
        onclick={() => router.navigate('/requests')}
      >
        <Icon name="arrow-left" size={16} />
      </button>

      {#if detail}
        <div class="head-text">
          <div class="hero-badges">
            <span class="number">{detail.request.number}</span>
            {#if detail.request.is_overdue}
              <span class="overdue-badge">Просрочена</span>
            {/if}
          </div>

          <h1>
            {#if detail.can.edit_text}
              <button
                type="button"
                class="text-edit title-edit"
                title="Редактировать название"
                onclick={openSubject}
              >
                {detail.request.subject}
              </button>
            {:else}
              {detail.request.subject}
            {/if}
          </h1>

        </div>

        <div class="head-actions">
          <span
            class="hero-status"
            style:color={detail.request.status.color ?? '#6b7280'}
          >
            {detail.request.status.title}
          </span>

          <div class="hero-tabs" aria-label="Разделы заявки">
            <button
              type="button"
              class="hero-tab"
              class:active={cardTab === 'request'}
              title={`Заявка${detail.items.length > 0 ? `: ${detail.items.length} поз.` : ''}`}
              aria-label={`Заявка${detail.items.length > 0 ? `, позиций: ${detail.items.length}` : ''}`}
              onclick={() => void openCardTab('request')}
            >
              <Icon name="overview" size={18} />
              {#if detail.items.length > 0}
                <span class="tab-count">{detail.items.length > 99 ? '99+' : detail.items.length}</span>
              {/if}
            </button>

            {#if isStaff}
              <button
                type="button"
                class="hero-tab"
                class:active={cardTab === 'client'}
                title="Клиент"
                aria-label="Клиент"
                onclick={() => void openCardTab('client')}
              >
                <Icon name="clients" size={18} />
              </button>
            {/if}

            {#if detail.can.comment || detail.can.activity}
              <button
                type="button"
                class="hero-tab"
                class:active={cardTab === 'comments'}
                title={`История${historyCount > 0 ? `: ${historyCount}` : ''}`}
                aria-label={`История${historyCount > 0 ? `, ${historyCount}` : ''}`}
                onclick={() => void openCardTab('comments')}
              >
                <Icon name="history" size={18} />
                {#if historyCount > 0}
                  <span class="tab-count">
                    {historyCount > 99 ? '99+' : historyCount}
                  </span>
                {/if}
              </button>
            {/if}

            <button
              type="button"
              class="hero-tab"
              class:active={cardTab === 'chat'}
              class:unread={chatUnread > 0}
              title="Чат по заявке"
              aria-label="Чат по заявке"
              onclick={() => void openCardTab('chat')}
            >
              <Icon name="chat" size={18} />
              {#if chatUnread > 0}
                <span class="hero-dot" title="Есть новые сообщения"></span>
              {/if}
            </button>

            <button
              type="button"
              class="hero-tab"
              class:active={cardTab === 'files'}
              title={`Файлы${attachments.length > 0 ? `: ${attachments.length}` : ''}`}
              aria-label={`Файлы${attachments.length > 0 ? `, ${attachments.length}` : ''}`}
              onclick={() => void openCardTab('files')}
            >
              <Icon name="files" size={18} />
              {#if attachments.length > 0}
                <span class="tab-count">
                  {attachments.length > 99 ? '99+' : attachments.length}
                </span>
              {/if}
            </button>
          </div>

          {#if (appSettings.salesEnabled && auth.can('requests.create')) || detail.can.claim}
            <span class="hero-divider" aria-hidden="true"></span>
          {/if}

          <div class="hero-actions">
            {#if appSettings.salesEnabled && auth.can('requests.create')}
              <button
                type="button"
                class="page-icon-btn"
                title="Копировать заявку"
                aria-label="Копировать заявку"
                onclick={openCopy}
              >
                <Icon name="copy" size={16} />
              </button>
            {/if}

            {#if detail.can.claim}
              <button
                type="button"
                class="page-icon-btn"
                title="Взять в работу"
                aria-label="Взять в работу"
                disabled={busy}
                onclick={claim}
              >
                {#if busy}
                  <Spinner size={16} />
                {:else}
                  <Icon name="check-circle" size={16} />
                {/if}
              </button>
            {/if}

          </div>
        </div>
      {:else}
        <div class="head-text">
          <h1>Заявка</h1>
        </div>
      {/if}
    </div>
  </div>

  {#if actionError}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{actionError}</span>
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
      <span>{actionNotice}</span>
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
    <div class="row">
      <Button variant="ghost" onclick={() => router.navigate('/requests')}>
        К списку заявок
      </Button>
    </div>
  {:else if detail}
    {#snippet eventItem(item: HistoryItem)}
      <div class="discussion-item">
        <span class="di-icon" class:activity={item.kind !== 'comment'}>
          <Icon
            name={item.kind === 'comment' ? 'comments' : item.kind === 'status' ? 'history' : 'check-circle'}
            size={14}
          />
        </span>
        <div class="di-main">
          <div class="di-head">
            <span class="di-kind" class:activity={item.kind !== 'comment'}>{item.title}</span>
            {#if isStaff && item.kind !== 'status' && item.clientVisible}
              <span class="di-shared" title="Доступно клиенту">Клиенту</span>
            {/if}
            <span class="di-author">{item.user.name}</span>
            <span class="di-time">{formatDateTime(item.at)}</span>
          </div>
          {#if item.body}
            <div class="di-body">{item.body}</div>
          {/if}
          {#if item.attachment}
            {@const attachment = item.attachment}
            <button
              type="button"
              class="file-link event-file-link"
              onclick={() => void downloadAttachment(attachment.id, attachment.name)}
            >
              <Icon name="files" size={14} />
              {attachment.name} ({formatSize(attachment.size)})
            </button>
          {/if}
        </div>
      </div>
    {/snippet}

    {#snippet historyAssignments(requestDetail: RequestDetail)}
      {#if requestDetail.assignments.length === 0}
        <p class="muted">Передач пока не было</p>
      {:else}
        <ul class="assignments">
          {#each requestDetail.assignments as record (record.id)}
            <li>
              <div class="t-head">
                <strong>{record.user.name}</strong>
                <span class="a-route">
                  {record.from?.name ?? 'нет'} → {record.to?.name ?? 'поиск менеджера'}
                </span>
                <span class="t-date">{formatDateTime(record.created_at)}</span>
              </div>
              {#if record.comment}
                <div class="t-comment">{record.comment}</div>
              {/if}
            </li>
          {/each}
        </ul>
      {/if}
    {/snippet}

    {#snippet historyViews(requestDetail: RequestDetail)}
      {#if requestDetail.views.length === 0}
        <p class="muted">Заявку ещё никто не открывал</p>
      {:else}
        <ul class="assignments">
          {#each requestDetail.views as view (view.id)}
            <li>
              <div class="t-head">
                <strong>{view.user.name}</strong>
                <span class="t-date">{formatDateTime(view.created_at)}</span>
              </div>
            </li>
          {/each}
        </ul>
      {/if}
    {/snippet}

    {#snippet eventComposer()}
      {#if detail?.can.closed}
        <div class="card event-composer-card">
          <p class="muted closed-section-hint">Заявка закрыта — новые события и комментарии недоступны.</p>
        </div>
      {:else}
      <div class="card event-composer-card">
        <div class="card-head event-card-head">
          <h2>Новое событие</h2>
          <button
            type="button"
            class="helper-toggle"
            title="Подсказка"
            aria-label="Подсказка по событиям"
            aria-expanded={helperOpen}
            aria-controls="event-helper"
            bind:this={helperToggleEl}
            onclick={() => (helperOpen = !helperOpen)}
          >
            <Icon name="info" size={16} />
          </button>

          {#if helperOpen}
            <div class="event-helper" id="event-helper" bind:this={helperEl}>
              <div class="event-helper-head">
                <Icon name="info" size={15} />
                <span>Как добавить событие</span>
              </div>
              <ul>
                <li>Тема обязательна, комментарий и файл — при необходимости.</li>
                <li>Тему можно выбрать из подсказок или ввести свою.</li>
                <li>Для темы «Другое» нужен комментарий от 3 символов.</li>
                {#if detail?.can.comment_internal}
                  <li>«Доступно клиенту» открывает событие и файл клиенту.</li>
                {:else if detail?.can.comment}
                  <li>Ваши события видны клиенту.</li>
                {:else}
                  <li>Ваши события и файлы видны сотрудникам.</li>
                {/if}
              </ul>
            </div>
          {/if}
        </div>

        <p class="event-composer-hint">
          Запись в истории заявки. Описание и файл прикрепятся к событию.
        </p>

        <form class="form composer" onsubmit={saveEvent}>
          <label class="field">
            <span>Тема</span>
            <input
              type="text"
              bind:value={eventSubject}
              list="event-topics"
              maxlength="255"
              placeholder="Например: звонок клиенту"
            />
            <datalist id="event-topics">
              {#each eventPresets as preset (preset.code)}
                <option value={preset.title}></option>
              {/each}
            </datalist>
          </label>

          <div class="event-optional-links">
            {#if !commentOpen}
              <button type="button" class="link-btn" onclick={openComment}>
                <Icon name="comments" size={14} />
                Добавить описание
              </button>
            {/if}
            {#if !fileOpen}
              <button type="button" class="link-btn" onclick={openFile}>
                <Icon name="file-plus" size={14} />
                Добавить файл
              </button>
            {/if}
          </div>

          {#if commentOpen}
            <div class="event-optional-field">
              <label class="field">
                <span>Описание</span>
                <textarea
                  bind:value={eventComment}
                  rows="3"
                  placeholder="Описание события (необязательно)"
                ></textarea>
              </label>
              <button type="button" class="link-btn" onclick={closeComment}>
                Убрать описание
              </button>
            </div>
          {/if}

          {#if fileOpen}
            <div class="event-optional-field">
              {#if uploadedAttachment}
                <div class="event-file-row">
                  <span class="file-link">
                    {uploadedAttachment.name} ({formatSize(uploadedAttachment.size)})
                  </span>
                  <button
                    type="button"
                    class="link-btn"
                    disabled={busy}
                    onclick={() => void removeEventFile()}
                  >
                    Убрать файл
                  </button>
                </div>
              {:else if selectedFile}
                <div class="event-file-row">
                  <span>{selectedFile.name} ({formatSize(selectedFile.size)})</span>
                  <button
                    type="button"
                    class="link-btn"
                    disabled={busy}
                    onclick={clearSelectedEventFile}
                  >
                    Убрать файл
                  </button>
                </div>
                <label class="upload">
                  <input
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
                    onchange={onEventFileSelected}
                    disabled={busy}
                  />
                  <span>Заменить файл</span>
                </label>
              {:else}
                <label class="upload">
                  <input
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
                    onchange={onEventFileSelected}
                    disabled={busy}
                  />
                  <span>Выбрать файл (до 10 МБ)</span>
                </label>
                <button type="button" class="link-btn" onclick={() => (fileOpen = false)}>
                  Скрыть
                </button>
              {/if}
              <span class="event-file-hint">Файл также появится во вкладке «Файлы».</span>
            </div>
          {/if}

          {#if detail?.can.comment_internal}
            <div class="visibility-row">
              <label class="visibility-toggle">
                <input type="checkbox" bind:checked={shareWithClient} />
                <span class="visibility-body">
                  <span class="visibility-title">Доступно клиенту</span>
                  <span class="visibility-hint">
                    {shareWithClient
                      ? 'Клиент увидит событие в карточке и чате'
                      : 'Событие видят только сотрудники'}
                  </span>
                </span>
              </label>
            </div>
          {/if}

          <div class="row">
            <Button type="submit" loading={busy} disabled={!canSaveEvent || busy}>
              Сохранить
            </Button>
          </div>
        </form>
      </div>
      {/if}
    {/snippet}

    <div class="layout" class:single={cardTab === 'chat' || cardTab === 'client' || cardTab === 'files'}>
      <div class="main">
    {#if cardTab === 'client'}
      <div class="client-tab">
        {#if clientError}
          <div class="alert" role="alert">{clientError}</div>
        {/if}

        <div class="card">
          <div class="card-head">
            <h2>Клиент</h2>
            <a class="link-btn" href={`#/clients/${detail.request.client.id}`}>
              Открыть карточку →
            </a>
          </div>

          {#if clientLoading && clientCard === null}
            <p class="muted client-loading"><Spinner size={16} /> Загружаем данные клиента…</p>
          {:else}
            <dl class="client-facts">
              <div>
                <dt>Название</dt>
                <dd>{clientCard?.client.company || detail.request.client.name}</dd>
              </div>
              <div>
                <dt>Контактное лицо</dt>
                <dd>
                  {clientCard?.client.name || detail.request.client.name}
                  {#if clientCard?.client.position}
                    <span class="muted"> · {clientCard.client.position}</span>
                  {/if}
                </dd>
              </div>
              <div>
                <dt>ИНН</dt>
                <dd>{clientCard?.client.inn || detail.request.client.inn || '—'}</dd>
              </div>
              <div>
                <dt>Менеджер</dt>
                <dd>{clientCard?.manager?.name ?? detail.request.manager?.name ?? '—'}</dd>
              </div>
              <div>
                <dt>Тип цен</dt>
                <dd>
                  {clientCard && clientCard.client.price_types.length > 0
                    ? clientCard.client.price_types.map((type) => type.title).join(', ')
                    : '—'}
                </dd>
              </div>
              <div>
                <dt>Статус</dt>
                <dd>
                  {clientCard === null
                    ? '—'
                    : clientCard.client.active
                      ? 'Активен'
                      : 'Заблокирован'}
                </dd>
              </div>
            </dl>
          {/if}
        </div>

        <div class="card">
          <div class="card-head">
            <h2>Контакты</h2>
          </div>
          <dl class="client-facts">
            <div>
              <dt>Телефон</dt>
              <dd>
                {#if clientCard?.client.phone || detail.request.client.phone}
                  <a class="side-contact" href={`tel:${clientCard?.client.phone || detail.request.client.phone}`}>
                    {clientCard?.client.phone || detail.request.client.phone}
                  </a>
                {:else}
                  —
                {/if}
              </dd>
            </div>
            <div>
              <dt>E-mail</dt>
              <dd>
                {#if clientCard?.client.email || detail.request.client.email}
                  <a class="side-contact" href={`mailto:${clientCard?.client.email || detail.request.client.email}`}>
                    {clientCard?.client.email || detail.request.client.email}
                  </a>
                {:else}
                  —
                {/if}
              </dd>
            </div>
            <div>
              <dt>Город</dt>
              <dd>{clientCard?.client.city || '—'}</dd>
            </div>
            <div>
              <dt>Адрес</dt>
              <dd>{clientCard?.client.address || '—'}</dd>
            </div>
            {#if clientCard?.client.registered_at}
              <div>
                <dt>Регистрация</dt>
                <dd>{formatDate(clientCard.client.registered_at)}</dd>
              </div>
            {/if}
          </dl>
        </div>

        {#if clientCard}
          <div class="card">
            <div class="card-head">
              <h2>Заявки</h2>
              <a class="link-btn" href={`#/clients/${detail.request.client.id}`}>
                Все заявки →
              </a>
            </div>
            <div class="client-stats">
              <div class="client-stat">
                <span class="client-stat-value">{clientCard.stats.total}</span>
                <span class="client-stat-label">всего</span>
              </div>
              <div class="client-stat">
                <span class="client-stat-value">{clientCard.stats.open}</span>
                <span class="client-stat-label">открыто</span>
              </div>
              <div class="client-stat">
                <span class="client-stat-value">{clientCard.stats.closed}</span>
                <span class="client-stat-label">закрыто</span>
              </div>
              <div class="client-stat" class:overdue={clientCard.stats.overdue > 0}>
                <span class="client-stat-value">{clientCard.stats.overdue}</span>
                <span class="client-stat-label">просрочено</span>
              </div>
            </div>
          </div>
        {/if}
      </div>
    {/if}

    {#if cardTab === 'request'}
      <div class="card items-card">
        <div class="items-head">
          <div class="items-title">
            <h2>Состав заявки</h2>
            {#if detail.items.length > 0}
              <span class="items-meta">
                {detail.items.length} поз. · {totalQuantityText} ед.
              </span>
            {/if}
          </div>

          <div class="items-head-right">
            {#if detail.items.length > 1}
              <button
                type="button"
                class="items-icon-btn"
                class:active={itemsSort !== 'natural'}
                title="Сортировка состава"
                aria-label="Сортировка состава"
                onclick={openItemsSort}
              >
                <Icon name="sort" size={16} />
              </button>
            {/if}

            {#if detail.can.edit_items && detail.items.length > 0}
              <button
                type="button"
                class="items-icon-btn"
                title="Добавить номенклатуру"
                aria-label="Добавить номенклатуру"
                onclick={openPicker}
              >
                <Icon name="plus" size={16} />
              </button>
            {/if}
          </div>
        </div>

        {#if detail.items.length === 0}
          <div class="items-empty">
            <span class="items-empty-icon"><Icon name="cart" size={20} /></span>
            <strong>Состав пуст</strong>
            {#if !detail.can.closed}
              <p class="muted">Добавьте номенклатуру из склада — цены и остатки подтянутся автоматически.</p>
              {/if}
            {#if detail.can.edit_items}
              <Button variant="ghost" onclick={openPicker}>
                <Icon name="plus" size={16} />
                Добавить из склада
              </Button>
            {/if}
          </div>
        {:else}
          <div class="items-table">
            <div class="it-row it-head" class:prices={showPrices}>
              <span>Название</span>
              <span class="it-qty-col">Количество</span>
              <span class="it-wh">Склад</span>
              {#if showPrices}
                <span class="it-price">Цена</span>
                <span class="it-sum">Сумма</span>
              {/if}
              <span class="it-del-col"></span>
            </div>

            {#each sortItems(detail.items, itemsSort) as item (item.id)}
              <div class="it-row" class:prices={showPrices}>
                <div class="it-name-col">
                  {#if item.description !== ''}
                    <button
                      type="button"
                      class="item-name has-desc"
                      title={expandedItems.has(item.id) ? 'Скрыть описание' : 'Показать описание'}
                      aria-expanded={expandedItems.has(item.id)}
                      onclick={() => toggleItemDescription(item.id)}
                    >
                      <span>{item.name}</span>
                      <Icon name={expandedItems.has(item.id) ? 'arrow-up' : 'arrow-down'} size={12} />
                    </button>

                    {#if expandedItems.has(item.id)}
                      <div class="it-desc">{item.description}</div>
                    {/if}
                  {:else}
                    <span class="item-name-static">{item.name}</span>
                  {/if}
                </div>

                {#if detail.can.edit_items}
                  <button
                    type="button"
                    class="item-qty"
                    title="Изменить количество"
                    onclick={() => openItemModal(item)}
                  >
                    {qtyText(item)}
                  </button>
                {:else}
                  <span class="item-qty-static">{qtyText(item)}</span>
                {/if}

                <span class="it-wh" title={item.warehouse_name || ''}>{item.warehouse_name || '—'}</span>

                {#if showPrices}
                  <span class="it-price" class:missing={item.price === null}>
                    {formatPrice(item.price)}
                  </span>
                  <span class="it-sum">{formatPrice(item.sum ?? null)}</span>
                {/if}

                {#if detail.can.edit_items}
                  <button
                    type="button"
                    class="it-del"
                    title="Удалить номенклатуру"
                    aria-label={`Удалить: ${item.name}`}
                    onclick={() => removeItem(item)}
                  >
                    <Icon name="trash" size={14} />
                  </button>
                {:else}
                  <span></span>
                {/if}
              </div>
            {/each}
          </div>

          {#if showPrices}
            <div class="items-total">
              <div class="items-total-meta">
                <span>{detail.items.length} поз.</span>
                <span>{totalQuantityText} ед.</span>
                {#if detail.prices.type}
                  <span>Цены: {detail.prices.type.title.toLowerCase()}</span>
                {/if}
              </div>
              <div class="items-total-sum">
                <span class="muted">Итого</span>
                <strong>{formatPrice(detail.prices.total)}</strong>
              </div>
            </div>
          {/if}
        {/if}
      </div>
    {/if}

    {#snippet eventList()}
      <div class="card event-list-card">
        <div class="card-head">
          <div class="events-title">
            <h2>История</h2>
            {#if historyCount > 0}
              <span class="events-count" title={`Всего записей: ${historyCount}`}>
                {historyCount}
              </span>
            {/if}
          </div>
        </div>

        {#if historyFeed.length === 0}
          <div class="events-empty">
            <span class="events-empty-icon"><Icon name="history" size={20} /></span>
            <strong>{detail?.can.closed ? 'Записей нет' : 'Записей пока нет'}</strong>
            {#if !detail?.can.closed}
              <span class="muted">Добавьте событие — оно появится здесь.</span>
            {/if}
          </div>
        {:else}
          <div class="discussion">
            {#each historyFeed as item (`${item.kind}-${item.id}`)}
              {@render eventItem(item)}
            {/each}
          </div>
        {/if}

        {#if detail && isStaff && detail.assignments.length > 0}
          <section class="history-section">
            <h3>Передачи</h3>
            {@render historyAssignments(detail)}
          </section>
        {/if}

        {#if detail && isStaff && detail.views.length > 0}
          <section class="history-section">
            <h3>Просмотры</h3>
            {@render historyViews(detail)}
          </section>
        {/if}
      </div>
    {/snippet}

    {#if cardTab === 'comments'}
      {@render eventList()}
    {/if}

    {#if cardTab === 'chat'}
      <div class="card chat-card">
        <div class="card-head">
          <h2>Чат по заявке</h2>
          {#if detail.chat.thread_id > 0}
            <a class="link-btn" href={`#/chat?thread=${detail.chat.thread_id}`}>
              Открыть диалог →
            </a>
          {/if}
        </div>
        <p class="muted chat-hint">
          Заявка {detail.request.number} · переписка с {isStaff ? 'клиентом' : 'менеджером'}
        </p>
        {#if detail.can.closed}
          <p class="muted chat-unavailable">Заявка закрыта — отправка сообщений недоступна</p>
        {/if}

        <div class="chat-body">
          {#if chatLoading}
            <div class="center chat-center"><Spinner size={22} /></div>
          {:else if chatMessages.length === 0}
            <p class="muted chat-empty">Сообщений по этой заявке пока нет</p>
          {:else}
            <div class="chat-list" bind:this={chatListEl}>
              {#each chatMessages as message (message.id)}
                <div class="chat-msg" class:mine={message.is_mine}>
                  <div class="cm-head">
                    <strong>{message.user.name}</strong>
                    <span class="t-date">{formatDateTime(message.created_at)}</span>
                  </div>
                  {#if message.body}
                    <div class="cm-body">{message.body}</div>
                  {/if}
                  {#if message.attachments.length > 0}
                    <div class="cm-files">
                      {#each message.attachments as file (file.id)}
                        <button
                          type="button"
                          class="file-link"
                          onclick={() => void downloadChatFile(file.id, file.name)}
                        >
                          {file.name} ({formatSize(file.size)})
                        </button>
                      {/each}
                    </div>
                  {/if}
                </div>
              {/each}
            </div>
          {/if}
        </div>

        {#if !detail.can.closed}
        {#if detail.chat.can_post}
          <form class="chat-form" onsubmit={sendChat}>
            <textarea
              bind:value={chatBody}
              rows="1"
              maxlength="4000"
              placeholder="Сообщение по заявке"
            ></textarea>
            <Button
              type="submit"
              loading={chatBusy}
              disabled={!chatBody.trim()}
              title="Отправить"
              ariaLabel="Отправить"
            >
              {#if !chatBusy}<Icon name="send" size={16} />{/if}
            </Button>
          </form>
        {:else}
          <p class="muted chat-unavailable">Отправка сообщений в этом диалоге недоступна</p>
        {/if}
        {/if}
      </div>
    {/if}

    {#if cardTab === 'files'}
      <div class="card">
        <div class="card-head">
          <h2>Файлы заявки</h2>
          {#if attachments.length > 0}
            <span class="muted">{attachments.length}</span>
          {/if}
        </div>

        {#if attachments.length === 0}
          <div class="files-empty">
            <Icon name="files" size={22} />
            <span class="files-empty-title">{detail.can.closed ? 'Файлов нет' : 'Файлов пока нет'}</span>
            {#if !detail.can.closed}
              <span class="files-empty-hint">Добавьте файл в блоке «Добавить файл»</span>
            {/if}
          </div>
        {/if}

        <ul class="files">
          {#each attachments as file (file.id)}
            <li>
              <div class="file-type" aria-hidden="true">{fileExtension(file.name)}</div>
              <div class="file-info">
                <div class="file-name-row">
                  <button
                    type="button"
                    class="file-link"
                    title={file.name}
                    onclick={() => void downloadFile(file)}
                  >
                    {file.name}
                  </button>
                  {#if isStaff && file.is_internal}
                    <span class="file-internal">Внутренний</span>
                  {/if}
                </div>
                <span class="file-meta">
                  {formatSize(file.size)} · {file.user.name} · {formatDateTime(file.created_at)}
                </span>
                {#if file.comment}
                  <div class="file-comment">{file.comment}</div>
                {/if}
              </div>
              <div class="file-actions">
                <button
                  type="button"
                  class="file-action"
                  title="Скачать файл"
                  aria-label="Скачать файл"
                  onclick={() => void downloadFile(file)}
                >
                  <Icon name="download" size={15} />
                </button>
                {#if file.can_delete}
                  <button
                    type="button"
                    class="file-action danger"
                    title="Удалить файл"
                    aria-label="Удалить файл"
                    disabled={busy}
                    onclick={() => requestFileDelete(file)}
                  >
                    <Icon name="trash" size={15} />
                  </button>
                {/if}
              </div>
            </li>
          {/each}
        </ul>
      </div>

        {#if detail.can.closed}
          <div class="card">
            <p class="muted closed-section-hint">Заявка закрыта — добавление файлов недоступно.</p>
          </div>
        {:else}
        <div class="card files-upload-card">
          <div class="card-head">
            <h2>Добавить файл</h2>
          </div>

          <p class="files-upload-hint">
            В истории появится запись «Прикреплён файл».
            <button type="button" class="link-btn" onclick={() => void openEventWithFile()}>
              Добавить событие с файлом →
            </button>
          </p>

          <form class="form files-upload-form" onsubmit={submitFileUpload}>
            <div class="files-upload-body">
              <div class="files-upload-left">
                {#if uploadFile}
                  <div class="files-selected">
                    <Icon name="files" size={16} />
                    <div class="files-selected-info">
                      <span class="files-selected-name" title={uploadFile.name}>{uploadFile.name}</span>
                      <span class="files-selected-size">{formatSize(uploadFile.size)}</span>
                    </div>
                    <button
                      type="button"
                      class="files-selected-remove"
                      title="Убрать файл"
                      aria-label="Убрать файл"
                      disabled={uploading}
                      onclick={() => (uploadFile = null)}
                    >
                      <Icon name="close" size={14} />
                    </button>
                  </div>
                  <label class="files-replace">
                    <input
                      type="file"
                      accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
                      onchange={onUploadFileSelected}
                      disabled={uploading}
                    />
                    <span>Заменить файл</span>
                  </label>
                {:else}
                  <label
                    class="files-dropzone"
                    ondragover={(event) => event.preventDefault()}
                    ondrop={onFileDrop}
                  >
                    <input
                      type="file"
                      accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
                      onchange={onUploadFileSelected}
                      disabled={uploading}
                    />
                    <span class="files-dropzone-icon"><Icon name="file-plus" size={22} /></span>
                    <span class="files-dropzone-title">Выбрать файл</span>
                    <span class="files-dropzone-hint">
                      или перетащите сюда · PDF, документы, архивы, до 10 МБ
                    </span>
                  </label>
                {/if}
              </div>

              <div class="files-upload-right">
                {#if fileCommentOpen}
                  <label class="field files-comment-field">
                    <textarea
                      bind:value={fileComment}
                      rows="2"
                      maxlength="1000"
                      placeholder="Комментарий к файлу"
                    ></textarea>
                  </label>
                {/if}

                {#if isStaff}
                  <label class="visibility-toggle">
                    <input type="checkbox" bind:checked={uploadInternal} />
                    <span class="visibility-body">
                      <span class="visibility-title">Внутренний файл</span>
                      <span class="visibility-hint">
                        {uploadInternal
                          ? 'Файл увидят только сотрудники'
                          : 'Файл будет доступен клиенту'}
                      </span>
                    </span>
                  </label>
                {/if}

                <div class="files-upload-actions">
                  <button
                    type="button"
                    class="link-btn files-comment-toggle"
                    onclick={fileCommentOpen ? closeFileComment : openFileComment}
                  >
                    <Icon name="comments" size={14} />
                    {fileCommentOpen ? 'Убрать комментарий' : 'Добавить комментарий'}
                  </button>

                  {#if uploadFile}
                    <Button type="submit" size="sm" loading={uploading} disabled={uploading}>
                      Загрузить
                    </Button>
                  {/if}
                </div>
              </div>
            </div>
          </form>
        </div>
        {/if}
    {/if}

      </div>

      {#if cardTab === 'request' || cardTab === 'comments'}
      <aside class="side">
        {#if cardTab === 'request'}
          <div class="card side-description-card">
            <div class="card-head">
              <h2>Описание</h2>
              {#if detail.can.edit_text}
                <button
                  type="button"
                  class="param-edit"
                  title={detail.request.body ? 'Изменить описание' : 'Добавить описание'}
                  aria-label={detail.request.body ? 'Изменить описание' : 'Добавить описание'}
                  onclick={openBody}
                >
                  <Icon name="edit" size={14} />
                </button>
              {/if}
            </div>
            {#if detail.request.body}
              <div
                class="overview-text side-description-text"
                class:clamped={descriptionLong && !descriptionExpanded}
              >
                {detail.request.body}
              </div>
              {#if descriptionLong}
                <button
                  type="button"
                  class="link-btn side-description-more"
                  onclick={() => (descriptionExpanded = !descriptionExpanded)}
                >
                  {descriptionExpanded ? 'Свернуть' : 'Показать полностью'}
                </button>
              {/if}
            {:else}
              <p class="muted">Описание не заполнено</p>
            {/if}
          </div>
        {/if}

        {#if cardTab === 'comments'}
          {@render eventComposer()}
        {/if}

        {#if cardTab !== 'comments'}
          <div class="card">
            <div class="card-head">
              <h2>Параметры</h2>
            </div>
          <dl class="side-info">
            <div>
              <dt>Менеджер</dt>
              <dd>
                {#if detail.request.manager}
                  {#if isStaff}
                    <a class="manager-link" href={`#/users/${detail.request.manager.id}`}>
                      {detail.request.manager.name}
                    </a>
                  {:else}
                    {detail.request.manager.name}
                  {/if}
                {:else}
                  <span class="muted">Не назначен</span>
                {/if}
              </dd>
              {#if detail.can.assign}
                <button
                  type="button"
                  class="param-edit"
                  title="Изменить менеджера"
                  aria-label="Изменить менеджера"
                  onclick={openTransfer}
                >
                  <Icon name="edit" size={14} />
                </button>
              {/if}
            </div>
            <div>
              <dt>Статус</dt>
              <dd>
                <StatusBadge
                  title={detail.request.status.title}
                  color={detail.request.status.color}
                />
              </dd>
              {#if detail.can.transition_to.length > 0}
                <button
                  type="button"
                  class="param-edit"
                  title="Изменить статус"
                  aria-label="Изменить статус"
                  onclick={openStatus}
                >
                  <Icon name="edit" size={14} />
                </button>
              {/if}
            </div>
            <div>
              <dt>Приоритет</dt>
              <dd>{priorityLabel(detail.request.priority)}</dd>
              {#if detail.can.meta}
                <button
                  type="button"
                  class="param-edit"
                  title="Изменить приоритет"
                  aria-label="Изменить приоритет"
                  onclick={openPriority}
                >
                  <Icon name="edit" size={14} />
                </button>
              {/if}
            </div>
            <div>
              <dt>Срок</dt>
              <dd class:overdue-text={detail.request.is_overdue}>
                {#if detail.request.due_at}
                  {formatDateTime(detail.request.due_at)}
                {:else}
                  <span class="muted">Не указан</span>
                {/if}
              </dd>
              {#if detail.can.meta}
                <button
                  type="button"
                  class="param-edit"
                  title="Изменить срок"
                  aria-label="Изменить срок"
                  onclick={openDue}
                >
                  <Icon name="edit" size={14} />
                </button>
              {/if}
            </div>
            <div>
              <dt>Создана</dt>
              <dd>{formatDateTime(detail.request.created_at)}</dd>
            </div>
            <div>
              <dt>Обновлена</dt>
              <dd>{formatDateTime(detail.request.updated_at)}</dd>
            </div>
            {#if detail.request.closed_at}
              <div>
                <dt>Закрыта</dt>
                <dd>{formatDateTime(detail.request.closed_at)}</dd>
              </div>
            {/if}
          </dl>
        </div>
        {/if}

      </aside>
      {/if}
    </div>

    <Modal open={itemModalOpen} title="Количество" onclose={() => (itemModalOpen = false)}>
      <div class="form">
        <p class="muted">{itemModalName}</p>

        <div class="qty-row">
          <button type="button" class="step" aria-label="Уменьшить" onclick={() => changeItemQty(-1)}>−</button>
          <input
            class="qty-input"
            type="number"
            min="0"
            step="any"
            bind:value={itemModalQty}
            aria-label="Количество"
          />
          <button type="button" class="step" aria-label="Увеличить" onclick={() => changeItemQty(1)}>+</button>
          {#if itemModalUnit}<span class="qty-unit">{itemModalUnit}</span>{/if}

          <div class="qty-actions">
            <Button loading={busy} onclick={() => void saveItemQty()}>Сохранить</Button>
            <Button variant="ghost" disabled={busy} onclick={() => (itemModalOpen = false)}>Отмена</Button>
          </div>
        </div>
      </div>
    </Modal>

    <Modal open={deleteOpen} title="Удаление номенклатуры" onclose={() => void cancelDelete()}>
      <div class="form">
        <p class="muted">Удалить номенклатуру «{deleteItemName}» из состава заявки?</p>

        <textarea
          bind:value={deleteComment}
          rows="2"
          placeholder="Комментарий (не обязательно)"
        ></textarea>

        <div class="row">
          <Button variant="danger" loading={busy} onclick={() => void confirmDelete()}>Удалить</Button>
          <Button variant="ghost" disabled={busy} onclick={() => void cancelDelete()}>Отмена</Button>
        </div>
      </div>
    </Modal>

    <Modal open={fileDeleteOpen} title="Удаление файла" onclose={() => void cancelFileDelete()}>
      <div class="form">
        <p class="muted">
          Удалить файл <strong class="file-delete-name">{fileToDelete?.name}</strong>?
          Это действие нельзя отменить.
        </p>

        <div class="row">
          <Button variant="danger" loading={busy} onclick={() => void confirmFileDelete()}>
            Удалить
          </Button>
          <Button variant="ghost" disabled={busy} onclick={() => void cancelFileDelete()}>
            Отмена
          </Button>
        </div>
      </div>
    </Modal>

    <Modal open={itemsSortOpen} title="Сортировка" onclose={() => (itemsSortOpen = false)}>
      <p class="sort-hint">Выберите поле и направление сортировки состава заявки.</p>

      <div class="sort-list" role="list">
        {#each itemsSortFields as field (field.label)}
          {@const active = itemsSortDraft === field.asc || itemsSortDraft === field.desc}
          <div class="sort-row" class:active role="listitem">
            <span class="sort-row-label">{field.label}</span>
            <button
              type="button"
              class="dir-btn"
              class:on={itemsSortDraft === field.asc}
              title={`${field.label}: по возрастанию`}
              aria-label={`${field.label}: по возрастанию`}
              onclick={() => toggleItemsSort(field.asc)}
            >
              <Icon name="arrow-up" size={16} />
            </button>
            <button
              type="button"
              class="dir-btn"
              class:on={itemsSortDraft === field.desc}
              title={`${field.label}: по убыванию`}
              aria-label={`${field.label}: по убыванию`}
              onclick={() => toggleItemsSort(field.desc)}
            >
              <Icon name="arrow-down" size={16} />
            </button>
          </div>
        {/each}
      </div>

      <div class="modal-actions">
        <Button variant="ghost" onclick={resetItemsSort}>Сбросить</Button>
        <div class="modal-buttons">
          <Button variant="ghost" onclick={() => (itemsSortOpen = false)}>Отмена</Button>
          <Button onclick={applyItemsSort}>Применить</Button>
        </div>
      </div>
    </Modal>

    <Modal open={copyOpen} title="Копировать заявку" onclose={() => (copyOpen = false)}>
      <div class="form">
        <p class="muted">
          Создать новую заявку на основе «{detail.request.number}»? Текущая заявка останется без
          изменений. Перенесутся тема, описание, клиент, приоритет и состав; файлы и история — нет.
        </p>

        <div class="row">
          <Button onclick={confirmCopy}>Создать копию</Button>
          <Button variant="ghost" onclick={() => (copyOpen = false)}>Отмена</Button>
        </div>
      </div>
    </Modal>

    <Modal open={statusOpen} title="Смена статуса" onclose={() => (statusOpen = false)}>
      <div class="form">
        <p class="muted">Текущий статус: {detail.request.status.title}</p>

        <form class="form" onsubmit={submitTransition}>
          <select bind:value={targetStatus} required>
            <option value="" disabled>Выберите статус</option>
            {#each selectableStatuses as status}
              <option value={status.code}>{status.title}</option>
            {/each}
          </select>

          <textarea
            bind:value={transitionComment}
            rows="3"
            placeholder="Комментарий к смене статуса (не обязательно)"
          ></textarea>

          {#if targetStatus === 'canceled'}
            <p class="muted">Для отмены укажите причину — минимум 3 символа.</p>
          {/if}

          <div class="row">
            <Button
              type="submit"
              loading={busy}
              disabled={!targetStatus
                || (targetStatus === 'canceled' && transitionComment.trim().length < 3)}
            >
              Сменить статус
            </Button>
          </div>
        </form>
      </div>
    </Modal>

    <Modal open={subjectOpen} title="Название заявки" onclose={() => (subjectOpen = false)}>
      <div class="form">
        <label class="field">
          <span>Название</span>
          <input type="text" bind:value={subjectDraft} placeholder="Коротко: что нужно" />
        </label>

        <div class="row">
          <Button
            loading={metaBusy}
            disabled={subjectDraft.trim() === ''}
            onclick={() => void saveSubject()}
          >
            Сохранить
          </Button>
        </div>
      </div>
    </Modal>

    <Modal open={bodyOpen} title="Описание заявки" onclose={() => (bodyOpen = false)}>
      <div class="form">
        <textarea bind:value={bodyDraft} rows="6" placeholder="Детали: объект, сроки, количество"></textarea>

        <div class="row">
          <Button loading={metaBusy} onclick={() => void saveBody()}>Сохранить</Button>
        </div>
      </div>
    </Modal>

    <Modal open={priorityOpen} title="Приоритет заявки" onclose={() => (priorityOpen = false)}>
      <div class="form">
        <div class="radio-list">
          {#each priorityOptions as option (option.value)}
            <label class="radio">
              <input type="radio" bind:group={metaPriority} value={option.value} />
              <span>{option.label}</span>
            </label>
          {/each}
        </div>

        <div class="row">
          <Button loading={metaBusy} onclick={() => void savePriority()}>Сохранить</Button>
        </div>
      </div>
    </Modal>

    <Modal open={dueOpen} title="Срок заявки" onclose={() => (dueOpen = false)}>
      <div class="form">
        <label class="field">
          <span>Дата и время</span>
          <input type="datetime-local" bind:value={metaDue} />
        </label>

        <textarea
          bind:value={metaDueComment}
          rows="2"
          placeholder="Комментарий к изменению срока (обязательно)"
        ></textarea>

        <div class="row">
          <Button
            loading={metaBusy}
            disabled={metaDue.trim() === '' || metaDueComment.trim().length < 3}
            onclick={() => void saveDue()}
          >
            Сохранить
          </Button>
        </div>
      </div>
    </Modal>

    <Modal open={transferOpen} title="Передать заявку" onclose={() => (transferOpen = false)}>
      <div class="form">
        <p class="muted">
          Текущий менеджер: {detail.request.manager?.name ?? 'не назначен'}
        </p>

        <select bind:value={transferTarget} required>
          <option value={0} disabled>Выберите менеджера</option>
          {#each transferableManagers as manager}
            <option value={manager.id}>{manager.name}</option>
          {/each}
        </select>

        <textarea
          bind:value={transferComment}
          rows="2"
          placeholder="Пояснение: почему передаёте (обязательно)"
        ></textarea>

        <div class="modal-actions">
          <div class="modal-buttons">
            <Button variant="ghost" disabled={busy} onclick={() => (transferOpen = false)}>
              Отмена
            </Button>
            {#if detail.previous_manager && detail.previous_manager.id !== detail.request.manager?.id}
              <Button variant="ghost" disabled={busy} onclick={returnToPrevious}>
                Вернуть: {detail.previous_manager.name}
              </Button>
            {/if}
            <Button
              loading={busy}
              disabled={transferTarget <= 0 || transferComment.trim().length < 3}
              onclick={submitTransfer}
            >
              Передать заявку
            </Button>
          </div>
        </div>
      </div>
    </Modal>

    <ItemsPicker
      open={pickerOpen}
      items={itemRows}
      clientId={detail.request.client.id}
      priceTypeId={detail.prices.type?.id ?? 0}
      onclose={closePicker}
      onchange={applyPickerItems}
    />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .layout {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(300px, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .layout.single {
    grid-template-columns: minmax(0, 1fr);
  }

  .client-tab {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .client-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--space-3) var(--space-5);
    margin: 0;
  }

  .client-facts div {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .client-facts dt {
    color: var(--muted);
    font-size: 12px;
  }

  .client-facts dd {
    margin: 0;
    color: var(--text);
    font-size: 14px;
    overflow-wrap: anywhere;
  }

  .client-loading {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin: 0;
  }

  .client-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: var(--space-3);
  }

  .client-stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--bg);
  }

  .client-stat-value {
    font-size: 20px;
    font-weight: 600;
  }

  .client-stat-label {
    color: var(--muted);
    font-size: 12px;
  }

  .client-stat.overdue .client-stat-value {
    color: var(--danger);
  }

  .main,
  .side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    min-width: 0;
  }

  @media (max-width: 1180px) {
    .layout {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .hero-badges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
  }

  .overdue-badge {
    padding: 2px 10px;
    border: 1px solid var(--error-border);
    border-radius: 999px;
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 12px;
    font-weight: 500;
  }

  .hero-tabs {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .hero-tab {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.12s ease;
  }

  .hero-tab:hover {
    background: var(--surface);
    color: var(--text);
  }

  .hero-tab.active {
    color: var(--primary);
  }

  .hero-tab.unread {
    color: var(--primary);
  }

  .tab-count {
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--warning);
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    line-height: 16px;
    text-align: center;
  }

  .hero-dot {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--primary);
  }

  .hero-status {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
  }

  .hero-divider {
    width: 1px;
    height: 20px;
    background: var(--border);
  }

  .hero-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .notify-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    margin-left: auto;
    padding: 0;
    border: none;
    background: none;
    color: var(--muted);
    cursor: pointer;
  }

  .notify-close:hover {
    color: var(--text);
  }

  .link-btn {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    font-size: 13px;
    color: var(--primary);
    cursor: pointer;
    white-space: nowrap;
  }

  .link-btn:hover {
    text-decoration: underline;
  }

  .overview-text {
    color: var(--text);
    font-size: 14px;
    line-height: 1.55;
    white-space: pre-wrap;
  }

  .side-description-text {
    overflow-wrap: anywhere;
  }

  .side-description-text.clamped {
    display: -webkit-box;
    -webkit-line-clamp: 8;
    line-clamp: 8;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .side-description-more {
    align-self: flex-start;
    font-size: 12px;
  }

  .side-contact {
    text-decoration: none;
  }

  .side-contact:hover {
    color: var(--primary);
  }

  .side-info {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin: 0;
  }

  .side-info > div {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .side-info dt {
    flex: 0 0 auto;
    color: var(--muted);
    font-size: 13px;
  }

  .side-info dd {
    flex: 1;
    min-width: 0;
    margin: 0;
    font-size: 14px;
    font-weight: 500;
    text-align: right;
  }

  .param-edit {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 50%;
    background: none;
    color: var(--muted);
    cursor: pointer;
  }

  .param-edit:hover {
    border-color: var(--border);
    background: var(--surface);
    color: var(--primary);
  }

  .manager-link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed transparent;
  }

  .manager-link:hover {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .notice,
  .alert {
    position: relative;
    z-index: 60;
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice-icon,
  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
  }

  .notice-icon {
    color: var(--success);
  }

  .alert-icon {
    color: var(--danger);
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

  .sort-hint {
    margin: 0 0 var(--space-2);
    color: var(--muted);
    font-size: 13px;
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

  .sort-row-label {
    flex: 1;
    min-width: 0;
    font-size: 14px;
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

  .items-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .items-title {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 2px var(--space-2);
    min-width: 0;
  }

  .items-meta {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .closed-section-hint {
    margin: 0;
    padding: var(--space-2) 0;
  }

  .items-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: var(--space-6) var(--space-4);
    text-align: center;
  }

  .items-empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    margin-bottom: 2px;
    border-radius: 50%;
    background: var(--bg);
    color: var(--muted);
  }

  .items-empty strong {
    font-size: 14px;
  }

  .items-empty p {
    max-width: 420px;
    margin: 0;
    font-size: 13px;
  }

  .items-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.12s ease, background 0.12s ease, border-color 0.12s ease;
  }

  .items-icon-btn:hover {
    border-color: var(--border);
    background: var(--surface);
    color: var(--primary);
  }

  .items-icon-btn.active {
    border-color: var(--border);
    background: var(--primary-bg);
    color: var(--primary);
  }

  .items-table {
    display: flex;
    flex-direction: column;
    overflow: visible;
  }

  .items-head-right {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .it-row {
    display: grid;
    grid-template-columns:
      minmax(0, 2fr) minmax(72px, 0.8fr) minmax(72px, 1fr) 32px;
    min-width: 0;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    transition: background 0.12s ease;
  }

  .it-row.prices {
    grid-template-columns:
      minmax(0, 2.2fr) minmax(64px, 0.8fr) minmax(96px, 1.1fr)
      minmax(72px, 0.8fr) minmax(76px, 0.9fr) 32px;
  }

  .it-row:not(.it-head):hover {
    background: var(--fill-tertiary);
  }

  .it-row:last-child {
    border-bottom: none;
  }

  .it-head {
    color: var(--muted);
    font-size: 12px;
    border-bottom-width: 2px;
  }

  .it-name-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
  }

  .item-name {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    max-width: 100%;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    overflow-wrap: anywhere;
  }

  .item-name span {
    overflow-wrap: anywhere;
  }

  .item-name.has-desc {
    border-bottom: 1px dashed var(--border);
  }

  .item-name:hover {
    color: var(--primary);
  }

  .item-name-static {
    overflow-wrap: anywhere;
  }

  .it-desc {
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--muted);
    font-size: 13px;
    white-space: pre-wrap;
  }

  .it-qty-col {
    text-align: right;
  }

  .item-qty,
  .item-qty-static {
    justify-self: end;
    font-weight: 500;
    white-space: nowrap;
  }

  .item-qty {
    padding: 4px 10px;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    background: none;
    font: inherit;
    font-weight: 500;
    color: inherit;
    cursor: pointer;
  }

  .item-qty:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .it-wh {
    min-width: 0;
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .it-price,
  .it-sum {
    text-align: right;
    white-space: nowrap;
    font-size: 13px;
    font-variant-numeric: tabular-nums;
  }

  .it-sum {
    font-weight: 600;
  }

  .it-price.missing {
    color: var(--muted);
  }

  .items-total {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--space-3);
    margin-top: var(--space-3);
    padding-top: var(--space-3);
    border-top: 1px solid var(--border);
  }

  .items-total-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px var(--space-3);
    color: var(--muted);
    font-size: 12px;
  }

  .items-total-sum {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
  }

  .items-total-sum strong {
    font-size: 18px;
    font-variant-numeric: tabular-nums;
  }

  @media (max-width: 860px) {
    .it-row.prices {
      grid-template-columns:
        minmax(0, 2fr) minmax(64px, 0.8fr)
        minmax(72px, 0.9fr) minmax(76px, 0.9fr) 32px;
      gap: var(--space-2);
    }

    .it-row.prices .it-wh {
      display: none;
    }
  }

  @media (max-width: 760px) {
    .items-table {
      overflow-x: visible;
    }

    .it-head {
      display: none;
    }

    .it-row,
    .it-row.prices {
      grid-template-columns: minmax(0, 1fr) auto 36px;
      gap: 4px var(--space-2);
      min-width: 0;
      padding: var(--space-3) 0;
    }

    .it-row {
      grid-template-areas:
        'name name del'
        'qty qty qty'
        'wh wh wh';
    }

    .it-row.prices {
      grid-template-areas:
        'name name del'
        'qty qty qty'
        'wh wh wh'
        'price sum sum';
    }

    .it-name-col {
      grid-area: name;
    }

    .item-qty,
    .item-qty-static {
      grid-area: qty;
      justify-self: start;
    }

    .item-qty::before,
    .item-qty-static::before {
      content: 'Кол-во: ';
      color: var(--muted);
      font-weight: 400;
    }

    .it-wh {
      grid-area: wh;
      display: block;
      min-width: 0;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
    }

    .it-row.prices .it-wh {
      display: block;
    }

    .it-wh::before {
      content: 'Склад: ';
      color: var(--muted);
    }

    .it-price {
      grid-area: price;
      min-width: 0;
      text-align: left;
      white-space: normal;
    }

    .it-price::before {
      content: 'Цена: ';
      color: var(--muted);
      font-weight: 400;
    }

    .it-sum {
      grid-area: sum;
      min-width: 0;
      text-align: right;
      white-space: normal;
    }

    .it-sum::before {
      content: 'Сумма: ';
      color: var(--muted);
      font-weight: 400;
    }

    .it-row .it-del {
      grid-area: del;
      align-self: start;
      width: 32px;
      height: 32px;
    }
  }

  .it-del {
    justify-self: end;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    line-height: 1;
    cursor: pointer;
  }

  .it-del:hover {
    border-color: var(--danger);
    color: var(--danger);
  }

  .qty-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .qty-actions {
    display: flex;
    gap: var(--space-2);
    margin-left: auto;
  }

  .qty-input {
    width: 110px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    text-align: center;
  }

  .qty-input:focus {
    border-color: var(--primary);
    outline: none;
  }

  .qty-unit {
    color: var(--muted);
    font-size: 13px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-4);
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .chat-card {
    display: flex;
    flex-direction: column;
    height: clamp(360px, calc(100vh - 230px), 760px);
    height: clamp(360px, calc(100dvh - 230px), 760px);
    overflow: hidden;
  }

  .chat-hint {
    flex: 0 0 auto;
    margin-top: -4px;
  }

  .chat-body {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
  }

  .chat-card .card-head {
    flex: 0 0 auto;
  }

  .chat-center,
  .chat-empty {
    display: flex;
    flex: 1 1 auto;
    align-items: center;
    justify-content: center;
    margin: 0;
  }

  .chat-empty {
    text-align: center;
  }

  .chat-list {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: var(--space-3);
    min-height: 0;
    overflow-y: auto;
    padding-right: 6px;
    scrollbar-width: thin;
  }

  .chat-msg {
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .chat-msg.mine {
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
    border-color: color-mix(in srgb, var(--primary) 30%, var(--border));
  }

  .cm-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: 4px;
  }

  .cm-body {
    white-space: pre-wrap;
    word-break: break-word;
  }

  .cm-files {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
  }

  .chat-form {
    display: flex;
    flex: 0 0 auto;
    align-items: flex-end;
    gap: var(--space-2);
    margin-top: 0;
    padding-top: var(--space-3);
    border-top: 1px solid var(--border);
  }

  .chat-form textarea {
    flex: 1 1 auto;
    min-width: 0;
    min-height: 36px;
    max-height: 120px;
    resize: none;
  }

  .chat-form :global(button) {
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    padding: 0;
  }

  .chat-unavailable {
    flex: 0 0 auto;
    margin: 0;
  }

  .row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .assignments {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .files {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .files li {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .files li:hover {
    border-color: var(--border-secondary);
    background: var(--bg);
  }

  .file-type {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 38px;
    height: 38px;
    border-radius: var(--radius-sm);
    background: var(--primary-bg);
    color: var(--primary);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.04em;
  }

  .file-info {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .file-name-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    min-width: 0;
  }

  .file-link {
    max-width: 100%;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .file-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .file-comment {
    margin-top: 4px;
    color: var(--text);
    font-size: 13px;
    line-height: 1.45;
    white-space: pre-wrap;
  }

  .file-actions {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 2px;
  }

  .file-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .file-action:hover:not(:disabled) {
    background: rgba(0, 0, 0, 0.06);
    color: var(--primary);
  }

  .file-action.danger:hover:not(:disabled) {
    color: var(--danger);
  }

  .file-action:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .files-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: var(--space-4) var(--space-2);
    color: var(--muted);
    text-align: center;
  }

  .files-empty-title {
    color: var(--text);
    font-size: 14px;
    font-weight: 500;
  }

  .files-empty-hint {
    font-size: 12px;
  }

  .upload {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    align-self: flex-start;
    padding: 8px 14px;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 13px;
    color: var(--muted);
  }

  .upload input {
    display: none;
  }

  .a-route {
    color: var(--primary);
    font-size: 13px;
  }

  h1 {
    margin: 0;
    font-size: 20px;
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
    margin-bottom: 2px;
  }

  dt {
    font-size: 12px;
    color: var(--muted);
  }

  dd {
    margin: 0;
    font-weight: 500;
  }

  .overdue-text {
    color: var(--danger);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  select,
  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: inherit;
    outline: none;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    border-color: var(--primary);
  }

  .t-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--space-2);
    font-size: 14px;
  }

  .t-date {
    color: var(--muted);
    font-size: 12px;
    margin-left: auto;
  }

  .t-comment {
    color: var(--text);
    font-size: 14px;
    white-space: pre-wrap;
  }

  .event-card-head {
    position: relative;
    align-items: center;
  }

  .event-composer-card .composer > .row :global(button.primary) {
    width: 100%;
  }

  .helper-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    margin-left: auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
  }

  .helper-toggle:hover,
  .helper-toggle[aria-expanded='true'] {
    border-color: var(--primary);
    color: var(--primary);
  }

  .event-helper {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 6px;
    width: min(360px, calc(100vw - 48px));
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    box-shadow: var(--shadow-md);
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
  }

  .event-helper-head {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text);
    font-size: 13px;
    font-weight: 600;
  }

  .event-helper ul {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 0;
    padding-left: 18px;
  }

  .visibility-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px var(--space-3);
  }

  .visibility-toggle {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .visibility-toggle:hover {
    border-color: var(--border);
    background: var(--surface);
  }

  .visibility-toggle:has(input:checked) {
    border-color: var(--primary);
  }

  .visibility-toggle input {
    flex: 0 0 auto;
    width: 16px;
    height: 16px;
    margin-top: 2px;
    accent-color: var(--primary);
    cursor: pointer;
  }

  .visibility-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .visibility-title {
    font-weight: 600;
    line-height: 1.3;
  }

  .visibility-hint {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.35;
  }

  .composer {
    margin-top: var(--space-2);
  }

  .event-optional-links {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .event-optional-links .link-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
  }

  .event-optional-links .link-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    text-decoration: none;
  }

  .event-optional-field {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
  }

  .event-optional-field > .link-btn {
    align-self: flex-start;
  }

  .event-file-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    font-size: 13px;
  }

  .file-internal {
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--bg);
    color: var(--muted);
    font-size: 11px;
  }

  .file-delete-name {
    color: var(--text);
    overflow-wrap: anywhere;
  }

  .files-upload-card {
    padding: var(--space-3);
    gap: var(--space-2);
  }

  .files-upload-form {
    gap: var(--space-2);
  }

  .files-upload-body {
    display: grid;
    grid-template-columns: minmax(0, 1.15fr) minmax(260px, 0.85fr);
    gap: var(--space-3);
    align-items: stretch;
  }

  .files-upload-left,
  .files-upload-right {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
  }

  .files-upload-left .files-dropzone {
    flex: 1;
    min-height: 150px;
  }

  .files-upload-right .files-upload-actions {
    margin-top: auto;
  }

  @media (max-width: 900px) {
    .files-upload-body {
      grid-template-columns: minmax(0, 1fr);
    }

    .files-upload-left .files-dropzone {
      min-height: 0;
    }
  }

  .files-upload-hint {
    margin: 0 0 var(--space-2);
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
  }

  .files-upload-hint .link-btn {
    display: inline;
    font-size: 12px;
    white-space: normal;
  }

  .event-composer-hint {
    margin: 0 0 var(--space-3);
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
  }

  .event-file-hint {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
  }

  .files-dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 20px 12px;
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
    background: var(--bg);
    color: var(--muted);
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
  }

  .files-dropzone:hover {
    border-color: var(--primary);
    background: var(--surface);
  }

  .files-dropzone input,
  .files-replace input {
    display: none;
  }

  .files-dropzone-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--surface);
    color: var(--primary);
    box-shadow: var(--shadow-sm);
  }

  .files-dropzone-title {
    margin-top: 2px;
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
  }

  .files-dropzone:hover .files-dropzone-title {
    color: var(--primary);
  }

  .files-dropzone-hint {
    max-width: 26ch;
    font-size: 12px;
    line-height: 1.35;
  }

  .files-selected {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--muted);
  }

  .files-selected-info {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
  }

  .files-selected-name {
    overflow: hidden;
    color: var(--text);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .files-selected-size {
    color: var(--muted);
    font-size: 11px;
  }

  .files-selected-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .files-selected-remove:hover:not(:disabled) {
    background: rgba(0, 0, 0, 0.06);
    color: var(--text);
  }

  .files-replace {
    display: inline-flex;
    align-self: flex-start;
    padding: 5px 9px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--primary);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
  }

  .files-replace:hover {
    border-color: var(--primary);
  }

  .files-upload-form .field {
    font-size: 12px;
  }

  .files-upload-form .field textarea {
    padding: 7px 10px;
    font-size: 13px;
  }

  .files-upload-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    padding-top: var(--space-2);
    border-top: 1px solid var(--border-secondary);
  }

  .files-comment-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
  }

  .files-comment-toggle:hover {
    border-color: var(--primary);
    color: var(--primary);
    text-decoration: none;
  }

  @media (max-width: 720px) {
    .files-upload-actions {
      flex-direction: column;
      align-items: stretch;
    }

    .files-upload-actions :global(button) {
      width: 100%;
    }
  }

  .event-file-link {
    display: inline-flex;
    align-items: center;
    align-self: flex-start;
    gap: 6px;
    margin-top: var(--space-2);
    font-size: 13px;
  }

  .discussion {
    display: flex;
    flex-direction: column;
  }

  .events-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
  }

  .events-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    padding: 0 6px;
    border: 1px solid var(--border-secondary);
    border-radius: 999px;
    background: var(--bg);
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
  }

  .history-section {
    margin-top: var(--space-4);
    padding-top: var(--space-3);
    border-top: 1px solid var(--border-secondary);
  }

  .history-section h3 {
    margin: 0 0 var(--space-3);
    font-size: 14px;
    color: var(--muted);
  }

  .events-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: var(--space-6) var(--space-4);
    text-align: center;
  }

  .events-empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    margin-bottom: 2px;
    border-radius: 50%;
    background: var(--bg);
    color: var(--muted);
  }

  .events-empty strong {
    font-size: 14px;
  }

  .events-empty .muted {
    font-size: 13px;
  }

  .discussion-item {
    display: grid;
    grid-template-columns: 26px minmax(0, 1fr);
    gap: var(--space-3);
    padding: var(--space-3) 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .discussion-item:last-child {
    border-bottom: none;
  }

  .di-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    margin-top: 1px;
    border-radius: 50%;
    background: var(--primary-bg);
    color: var(--primary);
  }

  .di-icon.activity {
    background: var(--success-bg);
    color: var(--success-text);
  }

  .di-main {
    min-width: 0;
  }

  .di-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px var(--space-2);
    font-size: 13px;
  }

  .di-kind {
    color: var(--primary);
    font-size: 12px;
    font-weight: 600;
  }

  .di-kind.activity {
    color: var(--success);
  }

  .di-shared {
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--success-bg);
    color: var(--success-text);
    font-size: 11px;
    font-weight: 500;
  }

  .di-author {
    font-weight: 500;
  }

  .di-time {
    margin-left: auto;
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .di-body {
    margin-top: 4px;
    color: var(--text);
    font-size: 14px;
    line-height: 1.45;
    white-space: pre-wrap;
  }

  .radio-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  .field input[type='text'] {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .field textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
    resize: vertical;
  }

  .field textarea:focus {
    border-color: var(--primary);
    outline: none;
  }

  .field input[type='text']:focus {
    border-color: var(--primary);
    outline: none;
  }

  h1 {
    margin: 0;
    overflow-wrap: anywhere;
  }

  .text-edit {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .title-edit {
    overflow-wrap: anywhere;
  }

  .title-edit:hover {
    color: var(--primary);
  }

  .radio {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
  }

  .muted {
    color: var(--muted);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  /*
   * Узкие экраны: действия шапки уходят на свою строку, вкладки занимают всю
   * ширину. Селекторы с .page-hero нужны, чтобы перебить базовые правила
   * из app.css (они грузятся раньше и имеют ту же специфичность).
   */
  @media (max-width: 400px) {
    .page-hero .head-actions {
      flex: 1 1 100%;
      flex-wrap: wrap;
      justify-content: space-between;
      margin-left: 0;
      gap: var(--space-2);
    }

    .page-hero .head-text h1 {
      font-size: 17px;
      line-height: 1.35;
    }

    .hero-status {
      order: 1;
    }

    .hero-actions {
      order: 2;
    }

    .hero-divider {
      display: none;
    }

    .hero-tabs {
      order: 3;
      flex: 1 1 100%;
      justify-content: space-between;
      gap: 4px;
    }

    .hero-tab {
      width: 34px;
      height: 34px;
    }

    .it-row.prices {
      grid-template-areas:
        'name name del'
        'qty qty qty'
        'wh wh wh'
        'price price price'
        'sum sum sum';
    }

    .it-row.prices .it-sum {
      justify-self: end;
    }
  }
</style>
