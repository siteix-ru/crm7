<script lang="ts">
  import { tick, type Snippet } from 'svelte';
  import Button from './Button.svelte';
  import Icon from './Icon.svelte';
  import Modal from './Modal.svelte';

  interface Props {
    count?: number;
    title?: string;
    icon?: boolean;
    wide?: boolean;
    width?: number;
    size?: 'sm' | 'md' | 'lg';
    dirty?: boolean;
    resetLabel?: string;
    trigger?: boolean;
    onapply: () => void | string | Promise<string | void>;
    onreset?: () => void | string | Promise<string | void>;
    onopen?: () => void;
    children?: Snippet;
  }

  let {
    count = 0,
    title = 'Фильтры',
    icon = false,
    wide = false,
    width,
    size = 'md',
    dirty = false,
    resetLabel = 'Сбросить',
    trigger = true,
    onapply,
    onreset,
    onopen,
    children
  }: Props = $props();

  let open = $state(false);
  let announcement = $state('');

  export function show(): void {
    openModal();
  }

  export async function announce(text: string): Promise<void> {
    // Очищаем перед установкой — иначе повтор той же строки не обновит live-регион.
    announcement = '';
    await tick();
    announcement = text;
  }

  function openModal(): void {
    onopen?.();
    open = true;
  }

  async function apply(): Promise<void> {
    open = false;
    const result = await onapply();
    if (typeof result === 'string') {
      await announce(result);
    }
  }

  async function reset(): Promise<void> {
    const result = await onreset?.();
    if (typeof result === 'string') {
      await announce(result);
    }
  }
</script>

{#if trigger}
  {#if icon}
    <button
      type="button"
      class="icon-trigger"
      class:active={count > 0}
      title="Фильтры"
      aria-label={count > 0 ? `Фильтры, активно: ${count}` : 'Фильтры'}
      aria-haspopup="dialog"
      onclick={openModal}
    >
      <Icon name="filter" size={16} />
      {#if count > 0}<span class="badge" aria-hidden="true">{count}</span>{/if}
    </button>
  {:else}
    <Button variant="ghost" onclick={openModal}>
      Фильтры{#if count > 0}<span class="count">{count}</span>{/if}
    </Button>
  {/if}
{/if}

<div class="sr-only" role="status" aria-live="polite" aria-hidden={open}>{announcement}</div>

<Modal {open} {title} {wide} {width} {size} {announcement} onclose={() => (open = false)}>
  <div class="filter-form">
    {#if dirty}
      <p class="dirty-hint" role="status">Есть несохранённые изменения — нажмите «Применить»</p>
    {/if}
    {@render children?.()}
  </div>

  <div class="modal-actions">
    {#if onreset}
      <Button variant="text" size="lg" onclick={() => void reset()}>{resetLabel}</Button>
    {/if}
  <div class="modal-buttons">
    <Button variant="ghost" size="lg" onclick={() => (open = false)}>Отмена</Button>
    <Button size="lg" onclick={apply}>Применить</Button>
  </div>
  </div>
</Modal>

<style>
  .icon-trigger {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--field-border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  .icon-trigger:hover {
    border-color: var(--primary-hover);
    color: var(--primary);
  }

  .icon-trigger.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary);
    color: var(--on-primary);
    font-size: 10px;
    line-height: 16px;
    text-align: center;
  }

  .count {
    margin-left: 6px;
    min-width: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary);
    color: var(--on-primary);
    font-size: 11px;
    line-height: 17px;
    text-align: center;
  }

  .dirty-hint {
    margin: 0;
    color: var(--text-description);
    font-size: var(--font-xs);
  }

  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

</style>
