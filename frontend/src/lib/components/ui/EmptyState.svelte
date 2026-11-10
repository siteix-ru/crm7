<script lang="ts">
  import type { Snippet } from 'svelte';
  import Icon from './Icon.svelte';

  interface Props {
    icon?: string;
    title: string;
    description?: string;
    action?: Snippet;
  }

  let { icon = 'search', title, description = '', action }: Props = $props();
</script>

<div class="empty-state" role="status">
  <span class="icon" aria-hidden="true"><Icon name={icon} size={30} /></span>
  <p class="title">{title}</p>
  {#if description}
    <p class="description">{description}</p>
  {/if}
  {#if action}
    <div class="action">{@render action()}</div>
  {/if}
</div>

<style>
  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-6) var(--space-4);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
    text-align: center;
  }

  .icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    margin-bottom: var(--space-1);
    border-radius: 50%;
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .title {
    margin: 0;
    color: var(--text);
    font-size: 15px;
    font-weight: 600;
  }

  .description {
    margin: 0;
    max-width: 52ch;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
  }

  .action {
    margin-top: var(--space-2);
  }
</style>
