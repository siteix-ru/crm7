<script lang="ts">
  import Icon from '../ui/Icon.svelte';
  import { router } from '../../router.svelte';
  import { auth } from '../../stores/auth.svelte';
  import { appSettings } from '../../stores/app-settings.svelte';
  import { commandPalette } from '../../stores/command-palette.svelte';
  import {
    canRunQuickAction,
    quickActions,
    type QuickAction,
    type QuickActionContext
  } from '../../quick-actions';

  interface Props {
    inline?: boolean;
    onNavigate?: () => void;
  }

  let { inline = false, onNavigate }: Props = $props();

  let open = $state(false);
  let triggerEl = $state<HTMLButtonElement | null>(null);
  let menuEl = $state<HTMLDivElement | null>(null);

  const context = $derived<QuickActionContext>({
    navIds: new Set(appSettings.visibleNav.map((node) => node.id)),
    capabilities: auth.capabilities,
    requestsEnabled: appSettings.requestsEnabled,
    salesEnabled: appSettings.salesEnabled,
    chatEnabled: appSettings.chatEnabled
  });
  const actions = $derived(quickActions(context));

  function menuButtons(): HTMLButtonElement[] {
    return menuEl === null
      ? []
      : Array.from(menuEl.querySelectorAll<HTMLButtonElement>('button:not([disabled])'));
  }

  function close(restoreFocus = true): void {
    open = false;
    if (restoreFocus) {
      queueMicrotask(() => triggerEl?.focus());
    }
  }

  function toggle(): void {
    if (open) {
      close();
      return;
    }
    open = true;
  }

  function run(action: QuickAction): void {
    // Права проверяются и на отображении, и непосредственно перед переходом.
    if (!canRunQuickAction(action.id, context)) {
      return;
    }
    close(false);
    onNavigate?.();
    router.navigate(action.route);
  }

  function onKeydown(event: KeyboardEvent): void {
    if (!open || commandPalette.open) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }

    const buttons = menuButtons();
    if (buttons.length === 0) {
      return;
    }

    const current = buttons.indexOf(document.activeElement as HTMLButtonElement);

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      buttons[(current + 1) % buttons.length].focus();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      buttons[(current - 1 + buttons.length) % buttons.length].focus();
    } else if (event.key === 'Home') {
      event.preventDefault();
      buttons[0].focus();
    } else if (event.key === 'End') {
      event.preventDefault();
      buttons[buttons.length - 1].focus();
    }
  }

  function onPointerDown(event: PointerEvent): void {
    if (!open) {
      return;
    }
    const target = event.target as Node | null;
    if (target !== null && (menuEl?.contains(target) || triggerEl?.contains(target))) {
      return;
    }
    close(false);
  }

  $effect(() => {
    if (!open) {
      return;
    }
    queueMicrotask(() => menuEl?.querySelector<HTMLButtonElement>('button')?.focus());
  });

  $effect(() => {
    if (open && actions.length === 0) {
      open = false;
    }
  });
</script>

<svelte:window onkeydown={onKeydown} onpointerdown={onPointerDown} />

{#if inline}
  {#if actions.length > 0}
    <div class="inline-list" role="group" aria-label="Быстрые действия">
      {#each actions as action (action.id)}
        <button type="button" onclick={() => run(action)}>
          <Icon name={action.iconId} size={18} />
          <span>{action.label}</span>
        </button>
      {/each}
    </div>
  {/if}
{:else}
  <div class="qa-wrap">
    <button
      type="button"
      class="qa-trigger"
      bind:this={triggerEl}
      aria-label="Быстрые действия"
      aria-expanded={open}
      aria-controls="quick-actions-menu"
      onclick={toggle}
    >
      <Icon name="more" size={20} />
    </button>
    <div
      class="qa-menu"
      id="quick-actions-menu"
      role="menu"
      aria-label="Быстрые действия"
      hidden={!open}
      bind:this={menuEl}
    >
      {#each actions as action (action.id)}
        <button type="button" role="menuitem" onclick={() => run(action)}>
          <Icon name={action.iconId} size={18} />
          <span>{action.label}</span>
        </button>
      {/each}
    </div>
  </div>
{/if}

<style>
  .qa-wrap {
    position: relative;
    flex: 0 0 auto;
  }

  .qa-trigger {
    display: none;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
  }

  .qa-trigger:hover,
  .qa-trigger[aria-expanded='true'] {
    color: var(--text);
    background: var(--fill-tertiary);
  }

  .qa-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 95;
    width: max-content;
    min-width: 220px;
    max-width: calc(100vw - 24px);
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
    padding: 6px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-md);
  }

  [hidden] {
    display: none !important;
  }

  .qa-menu button,
  .inline-list button {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 44px;
    padding: 0 10px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }

  .qa-menu button:hover,
  .qa-menu button:focus-visible,
  .inline-list button:hover,
  .inline-list button:focus-visible {
    background: var(--fill-tertiary);
  }

  .inline-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  @media (min-width: 768px) {
    .qa-trigger {
      display: inline-flex;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .qa-trigger,
    .qa-menu button,
    .inline-list button {
      transition: none;
    }
  }
</style>
