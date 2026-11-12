<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listAdminItemTypes, type ItemTypeAdmin } from '../lib/api/item-types';
  import { router } from '../lib/router.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

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
  <div class="hero page-hero">
    <div class="head">
      <button
        type="button"
        class="back"
        title="Вернуться к складу"
        aria-label="Вернуться к складу"
        onclick={() => router.navigate('/stocks')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
      <div class="head-text">
        <h1>
          <HelpTip
            text="Типы, которые можно выбрать в карточке номенклатуры. Хотя бы один тип должен оставаться включённым."
          >
            {#snippet trigger()}
              Типы номенклатуры
            {/snippet}
          </HelpTip>
        </h1>
      </div>
      <div class="head-actions">
        <button
          type="button"
          class="icon-btn"
          title="Добавить тип"
          aria-label="Добавить тип"
          onclick={() => router.navigate('/stocks/types/new')}
        >
          <Icon name="plus" size={16} />
        </button>
      </div>
    </div>
  </div>

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
                <th>Название</th>
                <th>Код</th>
                <th class="count-head">Позиции</th>
                <th class="state-head">Состояние</th>
                <th class="actions-head">Действия</th>
              </tr>
            </thead>
            <tbody>
              {#each types as type (type.id)}
                <tr>
                  <td>
                    <div class="name">
                      <span class="title">{type.title}</span>
                      {#if type.description}
                        <span class="desc">{type.description}</span>
                      {/if}
                    </div>
                  </td>
                  <td><code>{type.code}</code></td>
                  <td class="count">{type.positions_count}</td>
                  <td>
                    <span class="state" class:on={type.enabled}>
                      {type.enabled ? 'Включён' : 'Выключен'}
                    </span>
                  </td>
                  <td>
                    <div class="actions">
                      <button
                        type="button"
                        class="icon-btn"
                        title="Редактировать тип"
                        aria-label="Редактировать тип"
                        onclick={() => router.navigate(`/stocks/types/${type.id}`)}
                      >
                        <Icon name="edit" size={16} />
                      </button>
                    </div>
                  </td>
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

  .hero {
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
  }

  .head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease;
  }

  .icon-btn:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
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

  .head-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }


  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
    margin-left: auto;
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
    font-size: 14px;
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
    font-size: 12px;
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

  th.actions-head {
    text-align: right;
  }

  td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
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
    overflow-wrap: anywhere;
  }

  .desc {
    color: var(--text-description);
    font-size: 13px;
    overflow-wrap: anywhere;
  }

  code {
    padding: 2px 6px;
    border-radius: var(--radius-xs);
    background: var(--bg);
    color: var(--text-description);
    font-size: 12px;
  }

  .count {
    color: var(--text-description);
    white-space: nowrap;
  }

  .state {
    color: var(--text-description);
    font-size: 13px;
    white-space: nowrap;
  }

  .state.on {
    color: var(--success-text);
  }

  .actions {
    display: flex;
    justify-content: flex-end;
  }

  .empty {
    padding: var(--space-6);
    color: var(--text-description);
    text-align: center;
  }

  @media (max-width: 640px) {
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
      font-size: 13px;
    }

    td:nth-child(3)::before {
      content: 'Позиции: ';
    }

    td:nth-child(5) {
      margin-left: auto;
    }
  }
</style>
