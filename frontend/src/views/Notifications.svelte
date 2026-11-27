<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listNotifications, markRead } from '../lib/api/notifications';
  import type { NotificationItem } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import EmptyState from '../lib/components/ui/EmptyState.svelte';
  import ListSkeleton from '../lib/components/ui/ListSkeleton.svelte';
  import { formatDateTime, formatRelative } from '../lib/format';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { withReturnTo } from '../lib/navigation-context';

  const typeIcons: Record<string, string> = {
    'request.new': '＋',
    'request.claim': '→',
    'request.status': '⇄',
    'request.comment': '✎',
    'request.assigned': '→',
    'request.transferred': '⇄',
    'request.assign': '→',
    'substitution.created': '⇄',
    'substitution.ended': '⇄',
    'user.pending': '☺',
    'user.approved': '☺',
    'user.rejected': '☺',
    'client.assigned': '☺',
    'client.unassigned': '☺',
    'client.transfer': '⇄',
    'client.manager': '☺',
    'chat.message': '✉'
  };

  const initialNotifications = loadFilters('notifications', { filter: 'all', page: 1, per_page: 20 });

  let items = $state<NotificationItem[]>([]);
  let unread = $state(0);
  let total = $state(0);
  let page = $state(Number(initialNotifications.page) > 0 ? Number(initialNotifications.page) : 1);
  let perPage = $state(
    [10, 20, 50].includes(Number(initialNotifications.per_page))
      ? Number(initialNotifications.per_page)
      : 20
  );
  let loading = $state(true);
  let error = $state('');
  let busy = $state(false);
  let filter = $state<'all' | 'unread'>(initialNotifications.filter === 'unread' ? 'unread' : 'all');

  function persistState(): void {
    saveFilters('notifications', { filter, page, per_page: perPage });
  }

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listNotifications(filter === 'unread', page, perPage);
      items = data.items;
      unread = data.unread;
      total = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить уведомления';
    } finally {
      loading = false;
    }
  }

  function setFilter(value: 'all' | 'unread'): void {
    if (filter === value) {
      return;
    }

    filter = value;
    page = 1;
    persistState();
    void load();
  }

  function goToPage(next: number): void {
    page = next;
    persistState();
    void load();
  }

  function changePerPage(value: number): void {
    perPage = value;
    page = 1;
    void load();
  }

  async function markAll(): Promise<void> {
    busy = true;
    error = '';

    try {
      const data = await markRead({ all: true });
      unread = data.unread;

      if (filter === 'unread') {
        page = 1;
        await load();
      } else {
        items = items.map((item) => ({ ...item, is_read: true }));
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось обновить уведомления';
    } finally {
      busy = false;
    }
  }

  async function open(item: NotificationItem): Promise<void> {
    if (!item.is_read) {
      try {
        const data = await markRead({ ids: [item.id] });
        unread = data.unread;
        item.is_read = true;

        if (filter === 'unread') {
          items = items.filter((row) => row.id !== item.id);
          total = Math.max(0, total - 1);
        }
      } catch {
        // некритично
      }
    }

    const from = `${router.current.path}${router.current.query.toString() ? `?${router.current.query.toString()}` : ''}`;

    if (item.request_id) {
      router.navigate(withReturnTo(`/requests/${item.request_id}`, from));
    } else if (item.type === 'chat.message') {
      router.navigate('/chat');
    } else if (item.type === 'user.pending') {
      router.navigate('/clients');
    }
  }
</script>

<section class="page">
  <PageToolbar actionsLabel="Действия с уведомлениями">
    {#snippet title()}
      Уведомления {#if unread > 0}<span class="unread-count">{unread}</span>{/if}
    {/snippet}
    {#snippet actions()}
      <ToolbarMenu
        label="Действия с уведомлениями"
        items={[
          {
            id: 'mark-all',
            label: 'Отметить все прочитанными',
            icon: 'check',
            disabled: busy || unread === 0,
            onclick: markAll
          },
          { id: 'sep', label: '', separator: true, onclick: () => {} },
          {
            id: 'all',
            label: 'Все уведомления',
            icon: 'bell',
            checked: filter === 'all',
            onclick: () => setFilter('all')
          },
          {
            id: 'unread',
            label: `Непрочитанные${unread > 0 ? ` (${unread})` : ''}`,
            icon: 'bell',
            checked: filter === 'unread',
            onclick: () => setFilter('unread')
          }
        ]}
      />
    {/snippet}
  </PageToolbar>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if loading}
    <ListSkeleton rows={5} />
  {:else if items.length === 0}
    <EmptyState
      icon="bell"
      title={filter === 'unread' ? 'Непрочитанных нет' : 'Уведомлений пока нет'}
      description="Здесь появятся события по вашим заявкам: смена статуса, комментарии менеджера, назначение исполнителя и сообщения в чате."
    />
  {:else}
    <div class="list">
      {#each items as item (item.id)}
        <button type="button" class="item" class:unread={!item.is_read} onclick={() => void open(item)}>
          <span class="type" aria-hidden="true">{typeIcons[item.type] ?? '•'}</span>
          <span class="content">
            <span class="row">
              <span class="title">{item.title}</span>
              {#if !item.is_read}<span class="dot"></span>{/if}
            </span>
            {#if item.body}
              <span class="body">{item.body}</span>
            {/if}
            <span class="date" title={formatDateTime(item.created_at)}>{formatRelative(item.created_at)}</span>
          </span>
        </button>
      {/each}
    </div>

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

  .unread-count {
    display: inline-block;
    min-width: 20px;
    padding: 1px 8px;
    margin-left: 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 13px;
    vertical-align: middle;
    text-align: center;
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .item {
    display: flex;
    gap: var(--space-3);
    text-align: left;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
    font: inherit;
    color: inherit;
  }

  .type {
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-size: 15px;
  }

  .content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
  }

  .item.unread {
    border-left: 3px solid var(--primary);
  }

  .item:hover {
    border-color: var(--primary);
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .title {
    min-width: 0;
    overflow-wrap: anywhere;
    font-weight: 500;
  }

  .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--primary);
  }

  .body {
    display: -webkit-box;
    overflow: hidden;
    min-width: 0;
    color: var(--muted);
    font-size: 14px;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
  }

  .date {
    color: var(--muted);
    font-size: 12px;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }


</style>
