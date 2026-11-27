<script module lang="ts">
  let nextTitleId = 0;
</script>

<script lang="ts">
  import type { Snippet } from 'svelte';

  interface Props {
    title: string | Snippet;
    description?: string;
    leading?: Snippet;
    tabs?: Snippet;
    actions?: Snippet;
    primary?: Snippet;
    meta?: Snippet;
    actionsLabel?: string;
    tabsLabel?: string;
  }

  let {
    title,
    description = '',
    leading,
    tabs,
    actions,
    primary,
    meta,
    actionsLabel = 'Действия страницы',
    tabsLabel = 'Разделы страницы'
  }: Props = $props();

  // Шапка остаётся плоской: фон, рамка и разделитель не нужны —
  // структуру задают сами контролы и карточки ниже.
  const titleId = `page-toolbar-title-${++nextTitleId}`;
</script>

<header class="page-toolbar" aria-labelledby={titleId}>
  <div class="toolbar-title">
    <div class="toolbar-heading">
      {#if leading}
        <span class="toolbar-leading">{@render leading()}</span>
      {/if}
      <h1 id={titleId}>
        {#if typeof title === 'string'}{title}{:else}{@render title()}{/if}
      </h1>
    </div>
    {#if description}<p class="toolbar-description">{description}</p>{/if}
    {#if meta}<div class="toolbar-meta">{@render meta()}</div>{/if}
  </div>

  {#if tabs}
    <div class="toolbar-tabs" role="group" aria-label={tabsLabel}>{@render tabs()}</div>
  {/if}

  {#if actions || primary}
    <div class="toolbar-actions" role="group" aria-label={actionsLabel}>
      {#if actions}{@render actions()}{/if}
      {#if primary}<span class="toolbar-primary">{@render primary()}</span>{/if}
    </div>
  {/if}
</header>

<style>
  .page-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    flex-wrap: wrap;
    row-gap: var(--space-3);
    min-width: 0;
  }

  .toolbar-title {
    min-width: 0;
  }

  .toolbar-heading {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  h1 {
    margin: 0;
    color: var(--text);
    font-size: var(--font-xl);
    font-weight: 600;
    line-height: 1.4;
  }

  .toolbar-description {
    margin: var(--space-1) 0 0;
    color: var(--text-description);
    font-size: var(--font-sm);
  }

  .toolbar-meta {
    margin-top: var(--space-2);
  }

  .toolbar-tabs {
    display: flex;
    align-items: center;
    min-width: 0;
  }

  .toolbar-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    flex: 1 1 auto;
    min-width: 0;
  }

  .toolbar-primary {
    display: inline-flex;
    align-items: center;
    flex: 0 0 auto;
    margin-left: auto;
  }

  @media (max-width: 720px) {
    .page-toolbar {
      align-items: flex-start;
      gap: var(--space-3);
      row-gap: var(--space-1);
    }

    .toolbar-title {
      flex: 1 1 0;
      min-width: 0;
    }

    .toolbar-tabs {
      width: 100%;
      justify-content: flex-start;
    }

    .toolbar-actions {
      width: auto;
      flex: 0 0 auto;
      margin-left: auto;
      justify-content: flex-end;
    }
  }
</style>
