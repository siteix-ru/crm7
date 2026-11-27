<script lang="ts">
  import type { Snippet } from 'svelte';
  import Spinner from './Spinner.svelte';

  interface Props {
    type?: 'button' | 'submit';
    variant?: 'primary' | 'success' | 'ghost' | 'text' | 'danger';
    size?: 'sm' | 'md' | 'lg';
    disabled?: boolean;
    loading?: boolean;
    title?: string;
    ariaLabel?: string;
    onclick?: (event: MouseEvent) => void;
    children?: Snippet;
  }

  let {
    type = 'button',
    variant = 'primary',
    size = 'md',
    disabled = false,
    loading = false,
    title,
    ariaLabel,
    onclick,
    children
  }: Props = $props();
</script>

<button
  {type}
  class={variant}
  class:sm={size === 'sm'}
  class:lg={size === 'lg'}
  disabled={disabled || loading}
  {title}
  aria-label={ariaLabel}
  {onclick}
>
  {#if loading}
    <Spinner size={14} />
  {/if}
  {@render children?.()}
</button>

<style>
  button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    height: var(--control-height);
    padding: 0 var(--space-4);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    font-size: var(--font-md);
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
  }

  button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  button.sm {
    padding: 0 var(--space-2);
    font-size: var(--font-sm);
  }

  button.lg {
    height: var(--control-height-lg);
    padding: 0 var(--space-4);
  }

  .primary {
    background: linear-gradient(
      180deg,
      color-mix(in srgb, var(--primary) 92%, var(--surface)),
      var(--primary)
    );
    box-shadow: var(--shadow-sm);
    color: var(--on-primary);
  }

  .primary:hover:not(:disabled) {
    background: var(--primary-hover);
    box-shadow: var(--shadow-md);
  }

  .success {
    background: var(--success-bg);
    border-color: var(--success-border);
    color: var(--success-text);
  }

  .success:hover:not(:disabled) {
    border-color: var(--success);
    color: var(--success-text);
  }

  .ghost {
    background: transparent;
    border-color: var(--border);
    color: var(--text);
  }

  .ghost:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary);
  }

  .text {
    background: transparent;
    border-color: transparent;
    color: var(--primary);
  }

  .text:hover:not(:disabled) {
    background: var(--fill-hover);
  }

  .danger {
    background: var(--danger);
    color: #fff;
  }
</style>
