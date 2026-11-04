<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError, downloadFromApi } from '../lib/api/client';
  import { listMessages, markThreadRead, sendMessage } from '../lib/api/chat';
  import {
    addActivity,
    addComment,
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
    ManagerItem,
    RequestAttachment,
    RequestDetail,
    RequestItemRow,
    RequestStatus
  } from '../lib/api/types';
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
  import { formatDateTime, formatPrice, formatSize, priorityLabel } from '../lib/format';
  import { ITEMS_SORT_OPTIONS, sortItems, type ItemsSortKey } from '../lib/items-sort';

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

  let targetStatus = $state('');
  let transitionComment = $state('');
  let cancelReason = $state('');
  let cardTab = $state<CardTab>('request');
  let historyTab = $state<'timeline' | 'assignments' | 'views'>('timeline');
  let cancelOpen = $state(false);
  let statusOpen = $state(false);
  let transferOpen = $state(false);
  let activityOpen = $state(false);
  let actionNotice = $state('');
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

  let deleteOpen = $state(false);
  let deleteItemId = $state(0);
  let deleteItemName = $state('');
  let deleteComment = $state('');
  let deleteFromQty = $state(false);

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

  async function applyItems(items: CreateRequestItem[], comment = ''): Promise<void> {
    if (!detail) {
      return;
    }

    busy = true;
    actionError = '';

    try {
      detail = await updateRequestItems(id, items, detail.request.version, comment);
      actionNotice = 'Состав обновлён';
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить состав');
    } finally {
      busy = false;
    }
  }

  let itemsApplyTimer: ReturnType<typeof setTimeout> | null = null;
  let itemsApplyBusy = false;
  let pendingItems: CreateRequestItem[] | null = null;

  async function persistItems(items: CreateRequestItem[]): Promise<boolean> {
    if (!detail) {
      return false;
    }

    busy = true;
    actionError = '';

    try {
      detail = await updateRequestItems(id, items, detail.request.version, '');
      actionNotice = 'Состав обновлён';

      return true;
    } catch (cause) {
      handleActionError(cause, 'Не удалось изменить состав');

      return false;
    } finally {
      busy = false;
    }
  }

  async function flushItemsApply(): Promise<void> {
    if (itemsApplyBusy || pendingItems === null || !detail) {
      return;
    }

    const next = pendingItems;
    pendingItems = null;
    itemsApplyBusy = true;

    const ok = await persistItems(next);

    itemsApplyBusy = false;

    if (!ok) {
      pendingItems = null;

      return;
    }

    if (pendingItems !== null) {
      void flushItemsApply();
    }
  }

  function scheduleItemsApply(items: CreateRequestItem[]): void {
    itemRows = items;
    pendingItems = items;

    if (itemsApplyTimer !== null) {
      clearTimeout(itemsApplyTimer);
    }

    itemsApplyTimer = setTimeout(() => {
      itemsApplyTimer = null;
      void flushItemsApply();
    }, 500);
  }

  function closePicker(): void {
    pickerOpen = false;

    if (itemsApplyTimer !== null) {
      clearTimeout(itemsApplyTimer);
      itemsApplyTimer = null;
    }

    void flushItemsApply();
  }

  async function saveItemQty(): Promise<void> {
    const quantity = Number(itemModalQty ?? 0);

    if (quantity <= 0) {
      deleteItemId = itemModalId;
      deleteItemName = itemModalName;
      deleteComment = '';
      deleteFromQty = true;
      itemModalOpen = false;
      deleteOpen = true;
      return;
    }

    await applyItems(itemPayload(new Map([[itemModalId, quantity]])));
    itemModalOpen = false;
  }

  function removeItem(item: RequestItemRow): void {
    deleteItemId = item.id;
    deleteItemName = item.name;
    deleteComment = '';
    deleteFromQty = false;
    deleteOpen = true;
  }

  async function confirmDelete(): Promise<void> {
    await applyItems(itemPayload(new Map(), deleteItemId), deleteComment.trim());
    deleteOpen = false;
  }

  async function cancelDelete(): Promise<void> {
    deleteOpen = false;
    deleteFromQty = false;
  }
  let activityOccurredAt = $state(localNow());

  const priorityOptions: { value: number; label: string }[] = [
    { value: 1, label: 'Низкий' },
    { value: 2, label: 'Обычный' },
    { value: 3, label: 'Высокий' }
  ];
  let itemsSort = $state<ItemsSortKey>('natural');

  let itemRows = $state<CreateRequestItem[]>([]);
  let pickerOpen = $state(false);

  const isStaff = $derived(auth.level >= 10);

  let transferTarget = $state(0);
  let transferComment = $state('');

  let activityTypes = $state<ActivityType[]>([]);
  let activityType = $state('');
  let activityBody = $state('');
  let attachments = $state<RequestAttachment[]>([]);
  let uploading = $state(false);

  let commentBody = $state('');

  let chatLoading = $state(false);
  let chatBusy = $state(false);
  let chatBody = $state('');
  let chatMessages = $state<ChatMessage[]>([]);

  type CardTab = 'request' | 'comments' | 'chat' | 'files' | 'history';

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

  async function load(options: { silent?: boolean; force?: boolean } = {}): Promise<void> {
    const silent = options.silent === true;
    const force = options.force === true;
    const seq = ++loadSeq;
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
      || cancelOpen
      || transferOpen
      || activityOpen
      || itemModalOpen
      || deleteOpen
      || pickerOpen;
  }

  function autoRefreshAllowed(): boolean {
    if (document.visibilityState === 'hidden') {
      return false;
    }

    if (loading || busy || uploading || chatBusy || chatLoading) {
      return false;
    }

    if (itemsApplyBusy || itemsApplyTimer !== null || pendingItems !== null) {
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
        await loadChat(true);

        if (chatUnread > 0) {
          chatUnread = 0;
          const lastId = chatMessages.reduce((max, message) => Math.max(max, message.id), 0);
          void markThreadRead(detail.chat.thread_id, lastId).catch(() => {});
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
    cancelOpen = false;
    transferOpen = false;
    activityOpen = false;
    itemModalOpen = false;
    deleteOpen = false;
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

  async function onFileSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    uploading = true;
    actionError = '';

    try {
      attachments = (await uploadAttachment(id, file)).items;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить файл';
    } finally {
      uploading = false;
      input.value = '';
    }
  }

  async function removeFile(attachment: RequestAttachment): Promise<void> {
    busy = true;
    actionError = '';

    try {
      await deleteAttachment(attachment.id);
      attachments = attachments.filter((item) => item.id !== attachment.id);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось удалить файл';
    } finally {
      busy = false;
    }
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

  function openActivity(): void {
    if (!detail?.can.activity) {
      return;
    }

    activityType = '';
    activityBody = '';
    activityOccurredAt = localNow();
    activityOpen = true;
  }

  function localNow(): string {
    const date = new Date();
    const pad = (part: number): string => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
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
    if (!detail) {
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
    if (!detail) {
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
    if (!detail) {
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
    if (!detail) {
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

    if (!detail) return;

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

  async function cancelRequest(): Promise<void> {
    if (!detail || cancelReason.trim().length < 3) {
      return;
    }

    busy = true;
    actionError = '';

    try {
      detail = await transitionRequest(id, 'canceled', cancelReason.trim(), detail.request.version);
      cancelReason = '';
      cancelOpen = false;
    } catch (cause) {
      handleActionError(cause, 'Не удалось отменить заявку');
    } finally {
      busy = false;
    }
  }

  const selectableStatuses = $derived(
    statuses.filter((status) => (detail?.can.transition_to ?? []).includes(status.code))
  );

  const transferableManagers = $derived(
    managers.filter((manager) => manager.id !== detail?.request.manager?.id)
  );

  async function submitTransfer(): Promise<void> {
    if (!detail) return;

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
      cardTab = 'history';
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

  type TimelineItem =
    | {
        kind: 'status';
        id: number;
        at: string;
        user: { id: number; name: string; level: number; position: string };
        from: { code: string; title: string } | null;
        to: { code: string; title: string } | null;
        comment: string;
      }
    | {
        kind: 'activity';
        id: number;
        at: string;
        user: { id: number; name: string; level: number; position: string };
        title: string;
        body: string;
      };

  const timeline = $derived.by((): TimelineItem[] => {
    if (!detail) return [];

    const items: TimelineItem[] = [
      ...detail.history.map(
        (event): TimelineItem => ({
          kind: 'status',
          id: event.id,
          at: event.created_at,
          user: event.user,
          from: event.from,
          to: event.to,
          comment: event.comment
        })
      ),
      ...detail.activities.map(
        (activity): TimelineItem => ({
          kind: 'activity',
          id: activity.id,
          at: activity.created_at,
          user: activity.user,
          title: activity.type.title,
          body: activity.body
        })
      )
    ];

    return items.sort((a, b) => (a.at === b.at ? b.id - a.id : a.at < b.at ? 1 : -1));
  });

  function openPicker(): void {
    if (!detail) {
      return;
    }

    if (itemsApplyTimer !== null) {
      clearTimeout(itemsApplyTimer);
      itemsApplyTimer = null;
    }

    pendingItems = null;
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

  async function loadChat(silent = false): Promise<void> {
    if (!detail || detail.chat.thread_id <= 0) {
      return;
    }

    if (!silent) {
      chatLoading = true;
    }

    try {
      const data = await listMessages(detail.chat.thread_id, 0, id);
      chatMessages = data.items;
    } catch (cause) {
      if (!silent) {
        actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сообщения';
      }
    } finally {
      if (!silent) {
        chatLoading = false;
      }
    }
  }

  async function openCardTab(next: CardTab): Promise<void> {
    cardTab = next;

    if (next === 'chat') {
      await loadChat();

      if (detail && detail.chat.thread_id > 0 && chatUnread > 0) {
        chatUnread = 0;
        const lastId = chatMessages.reduce((max, message) => Math.max(max, message.id), 0);

        try {
          await markThreadRead(detail.chat.thread_id, lastId);
        } catch {
          // некритично
        }
      }
    }
  }

  async function sendChat(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail || !chatBody.trim()) {
      return;
    }

    chatBusy = true;
    actionError = '';

    try {
      await sendMessage(detail.chat.thread_id, chatBody.trim(), [], id);
      chatBody = '';
      await loadChat();
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

  async function submitActivity(): Promise<void> {
    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await addActivity(id, activityType, activityBody.trim(), activityOccurredAt);
      activityType = '';
      activityBody = '';
      activityOccurredAt = localNow();
      activityOpen = false;
      actionNotice = 'Действие добавлено';
      cardTab = 'history';
    } catch (cause) {
      handleActionError(cause, 'Не удалось добавить действие');
    } finally {
      busy = false;
    }
  }

  async function submitComment(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await addComment(id, commentBody);
      commentBody = '';
    } catch (cause) {
      handleActionError(cause, 'Не удалось добавить комментарий');
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
            {#if detail.can.transition_to.length > 0}
              <button
                type="button"
                class="status-button"
                title="Изменить статус"
                onclick={openStatus}
              >
                <StatusBadge title={detail.request.status.title} color={detail.request.status.color} />
              </button>
            {:else}
              <StatusBadge title={detail.request.status.title} color={detail.request.status.color} />
            {/if}
            {#if detail.request.is_overdue}
              <span class="overdue-badge">Просрочена</span>
            {/if}
            {#if !detail.request.manager}
              <span class="search-badge">Поиск менеджера</span>
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

          <div class="sub hero-meta">
            <span class="meta-chip">
              <Icon name="user" size={14} />
              {#if isStaff}
                <a class="manager-link" href={`#/clients/${detail.request.client.id}`}>
                  {detail.request.client.name}
                </a>
              {:else}
                {detail.request.client.name}
              {/if}
            </span>

            <span class="meta-chip" class:priority-high={detail.request.priority === 3}>
              <Icon name="info" size={14} />
              {priorityLabel(detail.request.priority)}
            </span>

            {#if detail.request.due_at}
              <span class="meta-chip" class:overdue={detail.request.is_overdue}>
                <Icon name="calendar" size={14} />
                {formatDateTime(detail.request.due_at)}
              </span>
            {/if}

            {#if detail.request.manager}
              <span class="meta-chip">
                <Icon name="staff" size={14} />
                {#if isStaff}
                  <a class="manager-link" href={`#/users/${detail.request.manager.id}`}>
                    {detail.request.manager.name}
                  </a>
                {:else}
                  {detail.request.manager.name}
                {/if}
              </span>
            {/if}
          </div>
        </div>

        <div class="head-actions">
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

            {#if detail.can.comment}
              <button
                type="button"
                class="hero-tab"
                class:active={cardTab === 'comments'}
                title={`Комментарии${detail.comments.length > 0 ? `: ${detail.comments.length}` : ''}`}
                aria-label={`Комментарии${detail.comments.length > 0 ? `, ${detail.comments.length}` : ''}`}
                onclick={() => void openCardTab('comments')}
              >
                <Icon name="comments" size={18} />
                {#if detail.comments.length > 0}
                  <span class="tab-count">
                    {detail.comments.length > 99 ? '99+' : detail.comments.length}
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

            <button
              type="button"
              class="hero-tab"
              class:active={cardTab === 'history'}
              title="История"
              aria-label="История"
              onclick={() => void openCardTab('history')}
            >
              <Icon name="history" size={18} />
            </button>
          </div>

          <div class="hero-actions">
            {#if appSettings.salesEnabled && auth.can('requests.create')}
              <button
                type="button"
                class="page-icon-btn"
                title="Копировать заявку"
                aria-label="Копировать заявку"
                onclick={() => router.navigate(`/requests/new?copy=${id}`)}
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

            {#if detail.can.cancel}
              <button
                type="button"
                class="page-icon-btn danger"
                title="Отмена заявки"
                aria-label="Отмена заявки"
                onclick={() => (cancelOpen = true)}
              >
                <Icon name="close-circle" size={16} />
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
    <div class="layout">
      <div class="main">
    {#if cardTab === 'request'}
      <div class="card">
        <div class="card-head">
          <h2>Описание</h2>
          {#if detail.can.edit_text}
            <button type="button" class="link-btn" onclick={openBody}>
              {detail.request.body ? 'Изменить' : 'Добавить'}
            </button>
          {/if}
        </div>
        {#if detail.request.body}
          <div class="overview-text">{detail.request.body}</div>
        {:else}
          <p class="muted">Описание не заполнено</p>
        {/if}
      </div>

      <div class="card">
        <div class="items-head">
          <h2>Состав заявки</h2>

          <div class="items-head-right">
            {#if detail.items.length > 1}
              <select class="items-sort" bind:value={itemsSort} aria-label="Сортировка номенклатуры">
                {#each ITEMS_SORT_OPTIONS as option (option.value)}
                  <option value={option.value}>{option.label}</option>
                {/each}
              </select>
            {/if}

            {#if detail.can.edit_items}
              <Button variant="ghost" onclick={openPicker}>
                <Icon name="plus" size={16} />
                Добавить
              </Button>
            {/if}
          </div>
        </div>

        {#if detail.items.length === 0}
          <p class="muted">Номенклатуры пока нет</p>
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
                  <button
                    type="button"
                    class="item-name"
                    class:has-desc={item.description !== ''}
                    title={item.description !== ''
                      ? expandedItems.has(item.id)
                        ? 'Скрыть описание'
                        : 'Показать описание'
                      : ''}
                    onclick={() => toggleItemDescription(item.id)}
                  >
                    {item.name}
                  </button>

                  {#if expandedItems.has(item.id) && item.description !== ''}
                    <div class="it-desc">{item.description}</div>
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

                <span class="it-wh">{item.warehouse_name || '—'}</span>

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
              <span class="muted">
                Итого{detail.prices.type ? ` · цены: ${detail.prices.type.title.toLowerCase()}` : ''}:
              </span>
              <strong>{formatPrice(detail.prices.total)}</strong>
            </div>
          {/if}
        {/if}
      </div>
    {/if}

    {#if cardTab === 'comments' && detail.can.comment}
      <div class="card">
        <div class="card-head">
          <h2>Комментарии</h2>
          {#if detail.comments.length > 0}
            <span class="muted">{detail.comments.length}</span>
          {/if}
        </div>
        <p class="muted comment-hint">Служебные пометки для сотрудников</p>

        {#if detail.comments.length === 0}
          <p class="muted">Комментариев пока нет</p>
        {/if}

        <div class="comments">
          {#each detail.comments as comment (comment.id)}
            <div class="comment">
              <div class="c-head">
                <strong>{comment.user.name}</strong>
                <span class="t-date">{formatDateTime(comment.created_at)}</span>
              </div>
              <div class="c-body">{comment.body}</div>
            </div>
          {/each}
        </div>

        <form class="form" onsubmit={submitComment}>
          <textarea bind:value={commentBody} rows="3" placeholder="Написать комментарий"></textarea>

          <div class="row">
            <Button type="submit" loading={busy} disabled={!commentBody.trim()}>
              Отправить
            </Button>
          </div>
        </form>
      </div>
    {/if}

    {#if cardTab === 'chat'}
      <div class="card">
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

        {#if chatLoading}
          <div class="center"><Spinner size={22} /></div>
        {:else if chatMessages.length === 0}
          <p class="muted">Сообщений по этой заявке пока нет</p>
        {:else}
          <div class="chat-list">
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

        {#if detail.chat.can_post}
          <form class="chat-form" onsubmit={sendChat}>
            <textarea
              bind:value={chatBody}
              rows="2"
              maxlength="4000"
              placeholder="Сообщение по заявке"
            ></textarea>
            <div class="row">
              <Button type="submit" loading={chatBusy} disabled={!chatBody.trim()}>Отправить</Button>
            </div>
          </form>
        {:else}
          <p class="muted">Отправка сообщений в этом диалоге недоступна</p>
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
          <p class="muted">Файлов пока нет</p>
        {/if}

        <ul class="files">
          {#each attachments as file (file.id)}
            <li>
              <div class="file-info">
                <button type="button" class="file-link" onclick={() => void downloadFile(file)}>
                  {file.name}
                </button>
                <span class="file-meta">
                  {formatSize(file.size)} · {file.user.name} · {formatDateTime(file.created_at)}
                </span>
              </div>
              <button type="button" class="file-del" disabled={busy} onclick={() => void removeFile(file)}>
                Удалить
              </button>
            </li>
          {/each}
        </ul>

        <label class="upload">
          <input
            type="file"
            accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
            onchange={onFileSelected}
            disabled={uploading}
          />
          <span>{uploading ? 'Загрузка…' : 'Прикрепить файл (до 10 МБ)'}</span>
        </label>
      </div>
    {/if}

    {#if cardTab === 'history'}
      <div class="card">
        <div class="card-head">
          <h2>История</h2>
        </div>
        <div class="tabs tab-scroll">
          <button
            type="button"
            class="tab"
            class:active={historyTab === 'timeline'}
            onclick={() => (historyTab = 'timeline')}
          >
            Хронология
          </button>
          {#if isStaff}
            <button
              type="button"
              class="tab"
              class:active={historyTab === 'assignments'}
              onclick={() => (historyTab = 'assignments')}
            >
              Передачи ({detail.assignments.length})
            </button>
            <button
              type="button"
              class="tab"
              class:active={historyTab === 'views'}
              onclick={() => (historyTab = 'views')}
            >
              Просмотры ({detail.views.length})
            </button>
          {/if}
        </div>

        {#if historyTab === 'timeline'}
          {#if timeline.length === 0}
            <p class="muted">Событий пока нет</p>
          {:else}
            <ol class="timeline">
              {#each timeline as event (`${event.kind}-${event.id}`)}
                <li>
                  <div class="t-head">
                    <strong>{event.user.name}</strong>
                    {#if event.user.position}<span class="t-pos">{event.user.position}</span>{/if}
                    {#if event.kind === 'status'}
                      <span class="t-activity">Статус изменён</span>
                    {:else}
                      <span class="t-activity">{event.title}</span>
                    {/if}
                    <span class="t-date">{formatDateTime(event.at)}</span>
                  </div>
                  {#if event.kind === 'status'}
                    {#if event.comment}
                      <div class="t-comment">{event.comment}</div>
                    {/if}
                  {:else if event.body}
                    <div class="t-comment">{event.body}</div>
                  {/if}
                </li>
              {/each}
            </ol>
          {/if}
        {:else if historyTab === 'assignments'}
          {#if detail.assignments.length === 0}
            <p class="muted">Передач пока не было</p>
          {:else}
            <ul class="assignments">
              {#each detail.assignments as record (record.id)}
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
        {:else}
          {#if detail.views.length === 0}
            <p class="muted">Заявку ещё никто не открывал</p>
          {:else}
            <ul class="assignments">
              {#each detail.views as view (view.id)}
                <li>
                  <div class="t-head">
                    <strong>{view.user.name}</strong>
                    <span class="t-date">{formatDateTime(view.created_at)}</span>
                  </div>
                </li>
              {/each}
            </ul>
          {/if}
        {/if}
      </div>
    {/if}

      </div>

      <aside class="side">
        <div class="card side-client-card">
          {#if isStaff}
            <a
              class="side-client side-client-link"
              href={`#/clients/${detail.request.client.id}`}
              title="Открыть карточку клиента"
            >
              <span class="side-client-name">{detail.request.client.name}</span>
              <span class="side-client-open" aria-hidden="true">
                <Icon name="arrow-right" size={14} />
              </span>
            </a>
          {:else}
            <div class="side-client">
              <span class="side-client-name">{detail.request.client.name}</span>
            </div>
          {/if}

          <div class="side-contacts">
            {#if detail.request.client.inn}
              <span class="side-meta">ИНН {detail.request.client.inn}</span>
            {/if}
            {#if detail.request.client.phone}
              <a class="side-meta side-contact" href={`tel:${detail.request.client.phone}`}>
                {detail.request.client.phone}
              </a>
            {/if}
          </div>
        </div>

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

        <div class="card">
          <div class="card-head">
            <h2>Итого</h2>
          </div>
          <dl class="side-info">
            <div>
              <dt>Позиций</dt>
              <dd>{detail.items.length}</dd>
            </div>
            <div>
              <dt>Единиц</dt>
              <dd>{totalQuantityText}</dd>
            </div>
            {#if showPrices}
              <div>
                <dt>Сумма</dt>
                <dd class="side-total">{formatPrice(detail.prices.total)}</dd>
              </div>
              {#if detail.prices.type}
                <div>
                  <dt>Тип цен</dt>
                  <dd>{detail.prices.type.title}</dd>
                </div>
              {/if}
            {/if}
          </dl>
        </div>

        {#if detail.can.activity}
          <div class="card">
            <div class="card-head">
              <h2>Действия</h2>
            </div>
            <div class="side-actions">
              <Button variant="ghost" onclick={openActivity}>
                <Icon name="plus" size={16} />
                Добавить действие
              </Button>
            </div>
          </div>
        {/if}
      </aside>
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

          <div class="row">
            <Button type="submit" loading={busy} disabled={!targetStatus}>Сменить статус</Button>
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

    <Modal open={cancelOpen} title="Отмена заявки" onclose={() => (cancelOpen = false)}>
      <div class="form">
        <p class="muted">Заявка будет отменена, причина сохранится в истории заявки.</p>
        <textarea bind:value={cancelReason} rows="3" placeholder="Причина отмены (обязательно)"></textarea>

        <div class="modal-actions">
          <div class="modal-buttons">
            <Button variant="ghost" disabled={busy} onclick={() => (cancelOpen = false)}>Не отменять</Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={cancelReason.trim().length < 3}
              onclick={cancelRequest}
            >
              Отменить заявку
            </Button>
          </div>
        </div>
      </div>
    </Modal>

    <Modal open={activityOpen} title="Добавить действие" onclose={() => (activityOpen = false)}>
      <div class="form">
        <div class="action-row">
          <select bind:value={activityType}>
            <option value="" disabled>Выберите действие</option>
            {#each activityTypes as type}
              <option value={type.code}>{type.title}</option>
            {/each}
          </select>

          <label class="field action-date">
            <span>Дата и время</span>
            <input type="datetime-local" bind:value={activityOccurredAt} />
          </label>
        </div>

        <textarea
          bind:value={activityBody}
          rows="2"
          placeholder="Комментарий (для «Другое» обязателен)"
        ></textarea>

        <div class="modal-actions">
          <div class="modal-buttons">
            <Button variant="ghost" disabled={busy} onclick={() => (activityOpen = false)}>
              Отмена
            </Button>
            <Button
              loading={busy}
              disabled={!activityType || (activityType.startsWith('other') && activityBody.trim().length < 3)}
              onclick={submitActivity}
            >
              Добавить
            </Button>
          </div>
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
      onclose={closePicker}
      onchange={scheduleItemsApply}
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
    gap: 2px;
    padding: 2px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
  }

  .hero-tab {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: calc(var(--radius-sm) - 2px);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.12s ease, background 0.12s ease, border-color 0.12s ease;
  }

  .hero-tab:hover {
    background: var(--surface);
    color: var(--text);
  }

  .hero-tab.active {
    border-color: var(--border);
    background: var(--surface);
    color: var(--primary);
    box-shadow: var(--shadow-sm);
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

  .hero-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .hero-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px var(--space-4);
  }

  .meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .meta-chip.overdue {
    color: var(--danger);
  }

  .meta-chip.priority-high {
    color: var(--warning-text);
  }

  .notify-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
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

  .side-client-card {
    padding: var(--space-3);
    gap: var(--space-2);
  }

  .side-client {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
  }

  .side-client-link {
    margin: -2px;
    padding: 2px;
    border-radius: var(--radius-sm);
    color: inherit;
    text-decoration: none;
  }

  .side-client-link:hover {
    background: var(--bg);
  }

  .side-client-link:hover .side-client-name,
  .side-client-link:hover .side-client-open {
    color: var(--primary);
  }

  .side-client-name {
    overflow: hidden;
    flex: 1;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 14px;
    font-weight: 600;
  }

  .side-client-open {
    display: inline-flex;
    flex: 0 0 auto;
    color: var(--muted);
  }

  .side-contacts {
    display: flex;
    flex-wrap: wrap;
    gap: 2px var(--space-3);
  }

  .side-meta {
    color: var(--muted);
    font-size: 13px;
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

  .side-total {
    font-size: 16px;
    font-weight: 600;
  }

  .side-actions {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .side-actions :global(button) {
    justify-content: flex-start;
    width: 100%;
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

  .t-pos {
    color: var(--muted);
    font-size: 12px;
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

  .comment-hint {
    margin-top: -8px;
    font-size: 12px;
  }

  .items-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .items-sort {
    max-width: 250px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    font: inherit;
    font-size: 13px;
  }

  .items-table {
    display: flex;
    flex-direction: column;
    overflow-x: auto;
  }

  .items-head-right {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .it-row {
    display: grid;
    grid-template-columns: minmax(180px, 2fr) 150px minmax(120px, 1fr) 36px;
    min-width: 640px;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    transition: background 0.12s ease;
  }

  .it-row.prices {
    grid-template-columns: minmax(160px, 2fr) 140px minmax(100px, 1fr) 104px 104px 36px;
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
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    overflow-wrap: anywhere;
  }

  .item-name.has-desc {
    border-bottom: 1px dashed var(--border);
  }

  .item-name:hover {
    color: var(--primary);
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
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    margin-top: var(--space-3);
    padding-top: var(--space-2);
    border-top: 1px dashed var(--border);
  }

  .items-total strong {
    font-size: 16px;
  }

  @media (max-width: 860px) {
    .it-row.prices {
      grid-template-columns: minmax(120px, 2fr) 110px 80px 80px 36px;
      gap: var(--space-2);
    }

    .it-row.prices .it-wh {
      display: none;
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

  .status-button {
    padding: 0;
    border: none;
    background: none;
    cursor: pointer;
    transition: opacity 0.12s ease;
  }

  .status-button:hover {
    opacity: 0.75;
  }

  .search-badge {
    font-size: 12px;
    color: var(--warning-text);
    background: var(--warning-bg);
    border: 1px solid var(--warning);
    border-radius: 999px;
    padding: 2px 10px;
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
    border-bottom: 1px solid var(--border);
  }

  .tab {
    padding: 8px 4px;
    margin-bottom: -1px;
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font: inherit;
    font-size: 13px;
    color: var(--muted);
    cursor: pointer;
  }

  .tab:hover {
    color: var(--text);
  }

  .tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
  }

  .chat-hint {
    margin-top: -4px;
  }

  .chat-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
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
    flex-direction: column;
    gap: var(--space-2);
    margin-top: var(--space-3);
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
    justify-content: space-between;
    gap: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: var(--space-2) var(--space-3);
  }

  .file-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .file-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .file-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .file-del {
    border: none;
    background: none;
    color: var(--danger);
    cursor: pointer;
    font-size: 13px;
    white-space: nowrap;
  }

  .file-del:disabled {
    opacity: 0.5;
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

  .timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    border-left: 2px solid var(--border);
    padding-left: var(--space-4);
  }

  .timeline li {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .t-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--space-2);
    font-size: 14px;
  }

  .t-activity {
    color: var(--success-text);
    font-size: 13px;
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

  .comments {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .comment {
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: var(--space-3);
  }

  .c-head {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    margin-bottom: 4px;
    font-size: 14px;
  }

  .c-body {
    white-space: pre-wrap;
    margin-top: var(--space-3);
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

  .action-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-3);
  }

  .action-row select {
    flex: 1 1 240px;
    min-width: 0;
  }

  .action-date {
    margin-left: auto;
  }

  .action-date input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .field input[type='text'] {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
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
</style>
