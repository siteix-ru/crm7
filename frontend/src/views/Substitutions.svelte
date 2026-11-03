<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listSubstitutions } from '../lib/api/substitutions';
  import type { Substitution } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let {
    embedded = false,
    search = '',
    sort = 'date_desc',
    view = 'list',
    statuses = [],
    managerIds = [],
    substituteIds = [],
    periodFrom = '',
    periodTo = ''
  }: {
    embedded?: boolean;
    search?: string;
    sort?: string;
    view?: 'list' | 'tiles';
    statuses?: string[];
    managerIds?: string[];
    substituteIds?: string[];
    periodFrom?: string;
    periodTo?: string;
  } = $props();

  function initials(name: string): string {
    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }

  let items = $state<Substitution[]>([]);
  let loading = $state(true);
  let error = $state('');

  const visibleItems = $derived.by(() => {
    const term = search.trim().toLocaleLowerCase('ru');
    const matched =
      term === ''
        ? items
        : items.filter((item) =>
            [
              item.manager.name,
              item.substitute.name,
              item.reason,
              item.date_from,
              item.date_to
            ]
              .join(' ')
              .toLocaleLowerCase('ru')
              .includes(term)
          );
    const filtered = matched
      .filter(
        (item) =>
          statuses.length === 0 ||
          statuses.includes(item.is_active ? 'active' : 'finished')
      )
      .filter(
        (item) => managerIds.length === 0 || managerIds.includes(String(item.manager.id))
      )
      .filter(
        (item) =>
          substituteIds.length === 0 || substituteIds.includes(String(item.substitute.id))
      )
      .filter((item) => periodFrom === '' || item.date_to >= periodFrom)
      .filter((item) => periodTo === '' || item.date_from <= periodTo);
    const [field, direction = 'desc'] = (sort.split(',')[0] ?? 'date_desc').split('_');
    const sign = direction === 'asc' ? 1 : -1;

    return [...filtered].sort((left, right) => {
      if (field === 'manager' || field === 'substitute' || field === 'status') {
        const key =
          field === 'manager'
            ? 'manager'
            : field === 'substitute'
              ? 'substitute'
              : null;
        const leftValue =
          key === null
            ? Number(left.is_active)
            : left[key].name.toLocaleLowerCase('ru');
        const rightValue =
          key === null
            ? Number(right.is_active)
            : right[key].name.toLocaleLowerCase('ru');

        if (typeof leftValue === 'string' && typeof rightValue === 'string') {
          return leftValue.localeCompare(rightValue, 'ru') * sign;
        }

        return (Number(leftValue) - Number(rightValue)) * sign;
      }

      if (field === 'requests') {
        return (left.requests_count - right.requests_count) * sign;
      }

      return left.date_from.localeCompare(right.date_from) * sign;
    });
  });

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      items = await listSubstitutions();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить замещения';
    } finally {
      loading = false;
    }
  }

</script>

<svelte:element this={embedded ? 'div' : 'section'} class="page">
  {#if !embedded}
    <div class="page-hero">
      <div class="head">
        <h1>Замещения</h1>
        <div class="head-actions">
          <button
            type="button"
            class="page-icon-btn"
            title="Обновить"
            aria-label="Обновить"
            disabled={loading}
            onclick={() => void load()}
          >
            {#if loading}
              <Spinner size={16} />
            {:else}
              <Icon name="refresh" size={16} />
            {/if}
          </button>
        </div>
      </div>
    </div>
  {/if}

  {#if error}<p class="alert error">{error}</p>{/if}

  {#if loading}
    <div class="center"><Spinner /></div>
  {:else if visibleItems.length === 0}
    <p class="empty">{items.length === 0 ? 'Замещений нет.' : 'Замещения не найдены.'}</p>
  {:else if view === 'tiles'}
    <div class="tiles-grid">
      {#each visibleItems as item (item.id)}
        <div class="tile">
          <div class="tile-head">
            <div class="avatars">
              <span class="avatar">{initials(item.manager.name)}</span>
              <span class="avatar-arrow">→</span>
              <span class="avatar alt">{initials(item.substitute.name)}</span>
            </div>
            <div class="tile-title">
              <span class="name">{item.manager.name}</span>
              <span class="login">замещает: {item.substitute.name}</span>
            </div>
          </div>

          <div class="tile-row"><span>Период</span><strong>{item.date_from} - {item.date_to}</strong></div>
          <div class="tile-row"><span>Заявки</span><strong>{item.requests_count}</strong></div>
          {#if item.reason}
            <div class="tile-row"><span>Причина</span><strong>{item.reason}</strong></div>
          {/if}

          <div class="tile-footer">
            <span class="state" class:on={item.is_active}>
              {item.is_active ? 'Активно' : 'Завершено'}
            </span>
            {#if item.is_active}
              <button
                type="button"
                class="edit-btn"
                title="Редактировать замещение"
                aria-label="Редактировать замещение"
                onclick={() => router.navigate(`/staff/substitutions/${item.id}`)}
              >
                <Icon name="edit" size={14} />
              </button>
            {/if}
          </div>
        </div>
      {/each}
    </div>
  {:else}
    <div class="list">
      <div class="row row-head" aria-hidden="true">
        <span>Замещение</span>
        <span>Период</span>
        <span>Заявки</span>
        <span>Причина</span>
        <span>Статус</span>
        <span></span>
      </div>

      {#each visibleItems as item (item.id)}
        <div class="row">
          <span class="who">
            <span class="avatars">
              <span class="avatar">{initials(item.manager.name)}</span>
              <span class="avatar-arrow">→</span>
              <span class="avatar alt">{initials(item.substitute.name)}</span>
            </span>
            <span class="who-text">
              <span class="name">{item.manager.name}</span>
              <span class="login">замещает: {item.substitute.name}</span>
            </span>
          </span>
          <span class="period">{item.date_from} - {item.date_to}</span>
          <span class="requests">{item.requests_count}</span>
          <span class="reason">{item.reason || '—'}</span>
          <span>
            <span class="state" class:on={item.is_active}>
              {item.is_active ? 'Активно' : 'Завершено'}
            </span>
          </span>
          <span class="actions-cell">
            {#if item.is_active}
              <button
                type="button"
                class="edit-btn"
                title="Редактировать замещение"
                aria-label="Редактировать замещение"
                onclick={() => router.navigate(`/staff/substitutions/${item.id}`)}
              >
                <Icon name="edit" size={14} />
              </button>
            {/if}
          </span>
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

  .head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  h1 {
    font-size: 22px;
    margin: 0;
  }

  .alert {
    margin: 0;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 14px;
  }

  .alert.error {
    background: #fdecea;
    color: #8c1d18;
  }

  .list {
    overflow: hidden;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .row {
    display: grid;
    grid-template-columns: minmax(200px, 1.4fr) 150px 70px minmax(120px, 1fr) 100px 110px;
    gap: var(--space-3);
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-secondary);
    color: var(--text);
    transition: background 0.12s ease;
  }

  .row:last-child {
    border-bottom: none;
  }

  .row:not(.row-head):hover {
    background: var(--fill-hover);
  }

  .row-head {
    padding-top: 10px;
    padding-bottom: 10px;
    background: var(--fill-tertiary);
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
  }

  .who {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
  }

  .avatars {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 4px;
  }

  .avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
    color: var(--primary);
    font-size: 12px;
    font-weight: 600;
  }

  .avatar.alt {
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .avatar-arrow {
    color: var(--muted);
    font-size: 12px;
  }

  .who-text,
  .tile-title {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .name {
    overflow: hidden;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .login {
    overflow: hidden;
    color: var(--muted);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .period,
  .requests {
    color: var(--text-description);
    font-size: 13px;
    white-space: nowrap;
  }

  .reason {
    overflow: hidden;
    color: var(--text-description);
    font-size: 13px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .actions-cell {
    display: flex;
    justify-content: flex-end;
  }

  .edit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease;
  }

  .edit-btn:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .state {
    display: inline-flex;
    align-items: center;
    padding: 2px 9px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .state.on {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success-text);
  }

  .tiles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--space-3);
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
  }

  .tile-head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
    margin-bottom: var(--space-1);
  }

  .tile-row {
    display: flex;
    justify-content: space-between;
    gap: var(--space-2);
    font-size: 13px;
  }

  .tile-row span {
    color: var(--muted);
  }

  .tile-row strong {
    overflow: hidden;
    font-weight: 500;
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tile-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-top: var(--space-1);
  }

  .center {
    display: flex;
    justify-content: center;
    padding: var(--space-5);
  }

  .empty {
    color: var(--muted);
    text-align: center;
    padding: var(--space-5);
  }

  @media (max-width: 900px) {
    .row {
      grid-template-columns: minmax(200px, 1.4fr) 140px 70px 100px 110px;
    }

    .row > :nth-child(4),
    .row-head > :nth-child(4) {
      display: none;
    }
  }

  @media (max-width: 720px) {
    .list {
      border: none;
      background: transparent;
      box-shadow: none;
    }

    .row-head {
      display: none;
    }

    .row {
      grid-template-columns: minmax(0, 1fr) auto;
      gap: var(--space-2);
      border: 1px solid var(--border);
      border-radius: var(--radius-md);
      margin-bottom: var(--space-2);
      background: var(--surface);
    }

    .period,
    .requests,
    .reason,
    .row > :nth-child(5) {
      display: none;
    }
  }
</style>
