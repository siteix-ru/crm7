<script lang="ts">
  import { buildPageItems } from '../../pagination';
  import Icon from './Icon.svelte';

  interface Props {
    page: number;
    perPage: number;
    total: number;
    loading?: boolean;
    perPageOptions?: number[];
    onchange: (page: number) => void;
    onperpage?: (perPage: number) => void;
  }

  let {
    page,
    perPage,
    total,
    loading = false,
    perPageOptions = [10, 20, 50, 100],
    onchange,
    onperpage
  }: Props = $props();

  const pages = $derived(perPage > 0 ? Math.max(1, Math.ceil(total / perPage)) : 1);
  const rangeFrom = $derived(perPage <= 0 ? (total === 0 ? 0 : 1) : Math.min((page - 1) * perPage + 1, total));
  const rangeTo = $derived(perPage <= 0 ? total : Math.min(page * perPage, total));
  const pageItems = $derived(buildPageItems(Math.min(page, pages), pages));
  const showSelector = $derived(onperpage !== undefined);

  let jumpPage = $state('');

  function go(target: number): void {
    if (loading || target === page) {
      return;
    }

    onchange(target);
  }

  function submitJump(event: Event): void {
    event.preventDefault();
    const value = Number(jumpPage);

    if (Number.isFinite(value) && value >= 1 && value <= pages) {
      go(Math.trunc(value));
    }

    jumpPage = '';
  }

  function changePerPage(event: Event): void {
    onperpage?.(Number((event.currentTarget as HTMLSelectElement).value));
  }
</script>

{#snippet pagerPrev()}
  <button
    type="button"
    class="page-btn"
    disabled={loading || page <= 1}
    aria-label="Предыдущая страница"
    onclick={() => go(page - 1)}
  >
    <Icon name="arrow-left" size={14} />
  </button>
{/snippet}

{#snippet pagerNext()}
  <button
    type="button"
    class="page-btn"
    disabled={loading || page >= pages}
    aria-label="Следующая страница"
    onclick={() => go(page + 1)}
  >
    <Icon name="arrow-right" size={14} />
  </button>
{/snippet}

<div class="pager">
  {#if showSelector}
    <label class="per-page">
      <span>Показывать</span>
      <select value={perPage} disabled={loading} onchange={changePerPage}>
        {#each perPageOptions as option (option)}
          <option value={option}>{option}</option>
        {/each}
        <option value={0}>Все</option>
      </select>
    </label>
  {:else}
    <span></span>
  {/if}

  {#if pages > 1}
    <div class="pager-main">
      <span class="pager-total">{rangeFrom}–{rangeTo} из {total}</span>

      <div class="pager-pages" aria-label="Постраничная навигация">
        {@render pagerPrev()}

        {#each pageItems as item, index (`${item}-${index}`)}
          {#if item === '...'}
            <span class="page-ellipsis" aria-hidden="true">…</span>
          {:else}
            <button
              type="button"
              class="page-btn"
              class:active={item === page}
              aria-current={item === page ? 'page' : undefined}
              aria-label={`Страница ${item}`}
              disabled={loading}
              onclick={() => go(Number(item))}
            >
              {item}
            </button>
          {/if}
        {/each}

        {@render pagerNext()}
      </div>

      <div class="pager-simple" aria-label="Постраничная навигация">
        {@render pagerPrev()}
        <span class="pager-simple-count">{page} / {pages}</span>
        {@render pagerNext()}
      </div>

      <form class="pager-jump" onsubmit={submitJump}>
        <span>Перейти</span>
        <input
          type="number"
          min="1"
          max={pages}
          bind:value={jumpPage}
          aria-label="Номер страницы"
          disabled={loading}
        />
      </form>
    </div>
  {/if}
</div>

<style>
  .pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 14px;
    color: var(--text);
  }

  .per-page {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .per-page select {
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 14px;
  }

  .pager-main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-left: auto;
  }

  .pager-total {
    color: var(--text);
    white-space: nowrap;
  }

  .pager-pages {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
  }

  .page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 6px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
  }

  .page-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .page-btn.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .page-btn:disabled {
    color: rgba(0, 0, 0, 0.25);
    cursor: not-allowed;
  }

  .page-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    color: var(--text-description);
  }

  .pager-jump {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    white-space: nowrap;
  }

  .pager-jump input {
    width: 56px;
    height: 32px;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
  }

  .pager-simple {
    display: none;
    align-items: center;
    gap: var(--space-2);
  }

  .pager-simple-count {
    color: var(--text);
  }

  @media (max-width: 575.98px) {
    .pager-pages,
    .pager-jump,
    .pager-total {
      display: none;
    }

    .pager-simple {
      display: inline-flex;
    }
  }
</style>
