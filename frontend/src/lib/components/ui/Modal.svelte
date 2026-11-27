<script module lang="ts">
  let scrollLockCount = 0;
  const openDialogs: HTMLElement[] = [];

  function lockBodyScroll(): void {
    if (scrollLockCount === 0) {
      document.body.style.overflow = 'hidden';
    }

    scrollLockCount += 1;
  }

  function unlockBodyScroll(): void {
    scrollLockCount = Math.max(0, scrollLockCount - 1);

    if (scrollLockCount === 0) {
      document.body.style.overflow = '';
    }
  }
</script>

<script lang="ts">
  import type { Snippet } from 'svelte';
  import Icon from './Icon.svelte';

  interface Props {
    open: boolean;
    title?: string;
    label?: string;
    wide?: boolean;
    width?: number;
    size?: 'sm' | 'md' | 'lg';
    closeButton?: boolean;
    persistent?: boolean;
    bodyMinHeight?: number;
    /** Анонс внутри диалога: live-регион снаружи aria-modal может быть не услышан. */
    announcement?: string;
    onclose: () => void;
    children?: Snippet;
  }

  let {
    open,
    title = '',
    label,
    wide = false,
    width,
    size = 'sm',
    closeButton = true,
    persistent = false,
    bodyMinHeight,
    announcement,
    onclose,
    children
  }: Props = $props();

  const modalWidth = $derived(width ?? (wide ? 780 : { sm: 560, md: 640, lg: 780 }[size]));
  const hasHead = $derived(title !== '' || closeButton);
  const titleId = `modal-title-${Math.random().toString(36).slice(2, 9)}`;
  let dialogEl: HTMLDivElement | null = $state(null);
  let previouslyFocused: HTMLElement | null = null;

  function focusableElements(): HTMLElement[] {
    if (dialogEl === null) {
      return [];
    }

    return Array.from(
      dialogEl.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter(
      (element) => element.getClientRects().length > 0 && element.closest('[hidden]') === null
    );
  }

  function onKeydown(event: KeyboardEvent): void {
    if (!open || (dialogEl !== null && openDialogs.at(-1) !== dialogEl)) {
      return;
    }

    if (event.key === 'Escape') {
      if (!persistent) {
        onclose();
      }

      return;
    }

    if (event.key !== 'Tab' || dialogEl === null) {
      return;
    }

    const elements = focusableElements();

    if (elements.length === 0) {
      event.preventDefault();
      return;
    }

    const first = elements[0];
    const last = elements[elements.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (event.shiftKey) {
      if (active === first || active === null || !dialogEl.contains(active)) {
        event.preventDefault();
        last.focus();
      }
    } else if (active === last || active === null || !dialogEl.contains(active)) {
      event.preventDefault();
      first.focus();
    }
  }

  $effect(() => {
    if (!open) {
      return;
    }

    previouslyFocused = document.activeElement as HTMLElement | null;
    lockBodyScroll();
    if (dialogEl !== null) {
      openDialogs.push(dialogEl);
    }
    const timer = setTimeout(() => {
      const preferred =
        dialogEl?.querySelector<HTMLElement>('[data-autofocus], [autofocus]')
        ?? dialogEl?.querySelector<HTMLElement>(
          'input:not([disabled]), textarea:not([disabled]), select:not([disabled])'
        )
        ?? focusableElements()[0];

      preferred?.focus();
    }, 0);

    return () => {
      clearTimeout(timer);
      const index = dialogEl === null ? -1 : openDialogs.indexOf(dialogEl);
      if (index >= 0) {
        openDialogs.splice(index, 1);
      }
      unlockBodyScroll();
      previouslyFocused?.focus?.();
    };
  });
</script>

<svelte:window onkeydown={onKeydown} />

{#if open}
  <div class="overlay">
    {#if persistent}
      <div class="backdrop" aria-hidden="true"></div>
    {:else}
      <button
        type="button"
        class="backdrop"
        tabindex="-1"
        aria-label="Закрыть окно"
        onclick={onclose}
      ></button>
    {/if}
    <div
      class="dialog"
      class:wide={wide || size === 'lg'}
      style:width={`min(${modalWidth}px, 100%)`}
      bind:this={dialogEl}
      role="dialog"
      aria-modal="true"
      aria-labelledby={title !== '' ? titleId : undefined}
      aria-label={label ?? (title === '' ? 'Окно' : undefined)}
    >
      {#if hasHead}
        <div class="dialog-head" class:empty={title === ''}>
          {#if title !== ''}
            <h2 id={titleId}>{title}</h2>
          {/if}
          {#if closeButton}
            <button type="button" class="close" aria-label="Закрыть" onclick={onclose}>
              <Icon name="close" size={16} />
            </button>
          {/if}
        </div>
      {/if}
      {#if announcement !== undefined}
        <div class="sr-only" role="status" aria-live="polite">{announcement}</div>
      {/if}
      <div
        class="dialog-body"
        style:min-height={bodyMinHeight === undefined ? undefined : `${bodyMinHeight}px`}
      >
        {@render children?.()}
      </div>
    </div>
  </div>
{/if}

<style>
  .overlay {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    place-items: center;
    padding: var(--space-4);
    /* Рейл занимает место слева — центрируем окно в рабочей области, а не в окне браузера. */
    padding-left: calc(var(--rail-width, 0px) + var(--space-4));
  }

  .backdrop {
    position: absolute;
    inset: 0;
    border: none;
    padding: 0;
    background: rgba(0, 0, 0, 0.45);
    cursor: default;
  }

  .dialog.wide {
    width: min(780px, 100%);
  }

  .dialog {
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(560px, 100%);
    min-width: 0;
    max-height: min(82vh, 760px);
    padding: var(--space-4) var(--space-5);
    background: var(--surface);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
  }

  .dialog-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin-bottom: var(--space-2);
  }

  .dialog-head.empty {
    justify-content: flex-end;
  }

  h2 {
    flex: 1 1 auto;
    min-width: 0;
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.5;
    color: var(--text);
    overflow-wrap: anywhere;
  }

  .close {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: none;
    border-radius: 4px;
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.2s ease, background-color 0.2s ease;
  }

  .close:hover {
    color: var(--text);
    background: var(--fill-hover);
  }

  .dialog-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    scrollbar-width: thin;
  }

  .dialog-body::-webkit-scrollbar {
    width: 6px;
  }

  .dialog-body::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: var(--border);
  }

  /* Мобильный паттерн: шторка снизу с отступами от краёв экрана. */
  @media (max-width: 720px) {
    .overlay {
      place-items: end center;
      padding: 8px clamp(6px, 2.5vw, 16px) calc(clamp(6px, 2.5vw, 16px) + env(safe-area-inset-bottom));
      padding-left: clamp(6px, 2.5vw, 16px);
    }

    .dialog-body {
      overflow-x: hidden;
    }

    .dialog {
      width: 100%;
      max-width: 560px;
      max-height: 92dvh;
      padding: 12px 16px 16px;
      border-radius: 16px;
      animation: sheet-up 0.22s ease-out;
    }

    .dialog.wide {
      max-width: 780px;
    }

    .dialog::before {
      content: '';
      flex: 0 0 auto;
      width: 36px;
      height: 4px;
      margin: 0 auto 10px;
      border-radius: 2px;
      background: var(--border);
    }

    .dialog-head {
      margin-bottom: var(--space-1);
    }
  }

  @keyframes sheet-up {
    from {
      transform: translateY(24px);
      opacity: 0;
    }

    to {
      transform: translateY(0);
      opacity: 1;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .dialog {
      animation: none;
    }
  }
</style>
