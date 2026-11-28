<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listAdminItemTypes, type ItemTypeAdmin } from '../lib/api/item-types';
  import { router } from '../lib/router.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { directoryValue, type DirectoryField } from '../lib/views/directory-fields';

  const columns: DirectoryField<ItemTypeAdmin>[] = [
    {
      key: 'title',
      label: 'Название',
      kind: 'title',
      href: (row) => `#/stocks/types/${row.id}`,
      description: (row) => row.description,
      value: (row) => row.title
    },
    { key: 'code', label: 'Код', kind: 'code', value: (row) => row.code },
    {
      key: 'positions',
      label: 'Позиции',
      headClass: 'count-head',
      cellClass: 'count',
      kind: 'count',
      value: (row) => String(row.positions_count)
    },
    {
      key: 'enabled',
      label: 'Доступен',
      headClass: 'state-head',
      kind: 'state',
      on: (row) => row.enabled === true,
      value: (row) => (row.enabled ? 'Доступен' : 'Выключен')
    }
  ];

  let types = $state<ItemTypeAdmin[]>([]);
  let loading = $state(true);
  let error = $state('');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      types = (await listAdminItemTypes()).types;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить типы номенклатуры';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <PageToolbar
    title="Типы складов"
    description="Тип склада определяет его назначение и использование в остатках и заказах."
    actionsLabel="Действия с типами складов"
  >
    {#snippet actions()}
      <ToolbarMenu
        label="Действия с типами складов"
        items={[
          {
            id: 'create',
            label: 'Добавить тип склада',
            icon: 'plus',
            onclick: () => router.navigate('/stocks/types/new')
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
      {#if types.length === 0}
        <div class="empty">Типов пока нет — добавьте первый.</div>
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
              {#each types as type (type.id)}
                <tr>
                  {#each columns as column (column.key)}
                    <td class={column.cellClass}>
                      {#if column.kind === 'title'}
                        <div class="name">
                          <a class="title" href={column.href?.(type)} aria-label={`${column.value(type)}, код ${type.code}`}>
                            {column.value(type)}
                          </a>
                          {#if column.description?.(type)}
                            <span class="desc">{column.description(type)}</span>
                          {/if}
                        </div>
                      {:else if column.kind === 'code'}
                        <code>{directoryValue(column, type)}</code>
                      {:else if column.kind === 'state'}
                        <span class="state" class:on={column.on?.(type)}>
                          {directoryValue(column, type)}
                        </span>
                      {:else}
                        {directoryValue(column, type)}
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





  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 10px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--error-text);
    font-size: var(--font-md);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 1px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
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
    min-width: 720px;
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
    width: 100px;
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

  code {
    padding: 2px 6px;
    border-radius: var(--radius-xs);
    background: var(--bg);
    color: var(--text-description);
    font-size: var(--font-xs);
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
    padding: var(--space-6);
    color: var(--text-description);
    text-align: center;
  }

  @media (max-width: 720px) {
    .page {
      gap: var(--space-3);
    }
  }

  /* Узкие экраны: строки-карточки вместо таблицы, «Код» прячем. */
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
      display: none;
    }

    td:nth-child(3) {
      font-size: var(--font-sm);
    }

    td:nth-child(3)::before {
      content: 'Позиции: ';
    }

    td:nth-child(4) {
      margin-left: auto;
    }
  }
</style>
