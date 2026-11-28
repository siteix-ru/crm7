<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listAdminItemGroups } from '../lib/api/groups';
  import type { ItemGroup } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { directoryValue, type DirectoryField } from '../lib/views/directory-fields';

  const columns: DirectoryField<ItemGroup>[] = [
    {
      key: 'title',
      label: 'Название',
      kind: 'title',
      href: (row) => `#/stocks/groups/${row.id}`,
      description: (row) => row.description,
      value: (row) => row.title
    },
    {
      key: 'positions',
      label: 'Номенклатуры',
      headClass: 'count-head',
      cellClass: 'count',
      kind: 'count',
      value: (row) => String(row.positions_count ?? 0)
    },
    {
      key: 'enabled',
      label: 'Доступен',
      headClass: 'state-head',
      kind: 'state',
      on: (row) => row.enabled !== false,
      value: (row) => (row.enabled === false ? 'Выключен' : 'Доступен')
    }
  ];

  let groups = $state<ItemGroup[]>([]);
  let loading = $state(true);
  let error = $state('');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      groups = (await listAdminItemGroups()).items;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить группы';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <PageToolbar
    title="Группы номенклатуры"
    description="Группа назначается номенклатуре при редактировании; по группам можно фильтровать остатки и выбор номенклатуры в заявке."
    actionsLabel="Действия с группами"
  >
    {#snippet leading()}
      <button
        type="button"
        class="back"
        title="Назад к номенклатуре"
        aria-label="Назад к номенклатуре"
        onclick={() => router.navigate('/stocks')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
    {/snippet}
    {#snippet actions()}
      <ToolbarMenu
        label="Действия с группами"
        items={[
          {
            id: 'create',
            label: 'Добавить группу',
            icon: 'plus',
            onclick: () => router.navigate('/stocks/groups/new')
          }
        ]}
      />
    {/snippet}
  </PageToolbar>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else}
    <div class="card">
      {#if groups.length === 0}
        <div class="empty">Групп пока нет — добавьте первую.</div>
      {:else}
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                {#each columns as column (column.key)}
                  <th class={column.headClass} scope="col">{column.label}</th>
                {/each}
              </tr>
            </thead>
            <tbody>
              {#each groups as group (group.id)}
                <tr>
                  {#each columns as column (column.key)}
                    <td class={column.cellClass}>
                      {#if column.kind === 'title'}
                        <div class="name">
                          <a class="title" href={column.href?.(group)}>{column.value(group)}</a>
                          {#if column.description?.(group)}
                            <span class="desc">{column.description(group)}</span>
                          {/if}
                        </div>
                      {:else if column.kind === 'state'}
                        <span class="state" class:on={column.on?.(group)}>
                          {directoryValue(column, group)}
                        </span>
                      {:else}
                        {directoryValue(column, group)}
                      {/if}
                    </td>
                  {/each}
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      {/if}
    </div>
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

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex: 0 0 auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
  }

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }





  .card {
    padding: var(--space-2) var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .table-wrap {
    overflow-x: auto;
  }

  table {
    width: 100%;
    min-width: 520px;
    border-collapse: collapse;
  }

  th {
    padding: 10px 12px;
    border-bottom: 2px solid var(--border);
    color: var(--text-description);
    font-size: var(--font-xs);
    font-weight: 500;
    text-align: left;
    white-space: nowrap;
  }

  th.count-head {
    width: 120px;
  }

  th.state-head {
    width: 120px;
  }

  td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: var(--font-md);
    vertical-align: middle;
  }

  tbody tr:last-child td {
    border-bottom: none;
  }

  tbody tr:hover {
    background: var(--fill-tertiary);
  }

  .name {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .title {
    font-weight: 500;
    color: var(--text);
    text-decoration: none;
    overflow-wrap: anywhere;
  }

  .title:hover {
    color: var(--primary);
    text-decoration: underline;
  }

  .desc {
    color: var(--text-description);
    font-size: var(--font-sm);
    overflow-wrap: anywhere;
  }

  .count {
    color: var(--text-description);
    white-space: nowrap;
  }

  .state {
    color: var(--text-description);
    font-size: var(--font-sm);
    white-space: nowrap;
  }

  .state.on {
    color: var(--success-text);
  }

  .empty {
    padding: var(--space-5);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
    color: var(--text-description);
    font-size: var(--font-md);
    text-align: center;
  }

  .alert {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: var(--font-md);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  /* Узкие экраны: строки-карточки вместо таблицы. */
  @media (max-width: 720px) {
    .table-wrap {
      overflow-x: visible;
    }

    table,
    tbody {
      display: block;
      min-width: 0;
    }

    thead {
      display: none;
    }

    tbody tr {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 4px 10px;
      padding: 12px 0;
      border-bottom: 1px solid var(--border);
    }

    tbody tr:last-child {
      border-bottom: none;
    }

    tbody tr:hover {
      background: none;
    }

    td {
      display: block;
      padding: 0;
      border: none;
    }

    td:nth-child(1) {
      flex: 1 1 100%;
    }

    td:nth-child(2) {
      font-size: var(--font-sm);
    }

    td:nth-child(2)::before {
      content: 'Номенклатуры: ';
    }

    td:nth-child(3) {
      margin-left: auto;
    }
  }
</style>
