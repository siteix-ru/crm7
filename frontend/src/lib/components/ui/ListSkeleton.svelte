<script lang="ts">
  import Skeleton from './Skeleton.svelte';

  interface Props {
    rows?: number;
  }

  let { rows = 6 }: Props = $props();

  const widths = ['92px', '46%', '132px', '84px'];
</script>

<div class="list-skeleton" role="status" aria-live="polite">
  <span class="sr-only">Загрузка данных…</span>
  {#each Array(rows) as _, index (index)}
    <div class="row" aria-hidden="true">
      {#each widths as width, cell (cell)}
        <span class="cell" class:cell-extra={cell >= 2}>
          <Skeleton {width} height={cell === 1 ? '14px' : '12px'} />
        </span>
      {/each}
    </div>
  {/each}
</div>

<style>
  .list-skeleton {
    display: flex;
    flex-direction: column;
    padding: var(--space-2) 0;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2) var(--space-5);
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
  }

  .row:last-child {
    border-bottom: none;
  }

  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
  }

  /* Узкие экраны: меньше «колонок», чтобы скелетон не переносился лесенкой. */
  @media (max-width: 480px) {
    .row {
      gap: var(--space-2) var(--space-3);
    }

    .cell-extra {
      display: none;
    }
  }
</style>
