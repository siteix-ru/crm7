<script lang="ts">
  import Icon from '../ui/Icon.svelte';
  import { auth } from '../../stores/auth.svelte';
  import { appSettings } from '../../stores/app-settings.svelte';
  import { commandPalette } from '../../stores/command-palette.svelte';
  import { recentPages } from '../../stores/recent-pages.svelte';
  import { router } from '../../router.svelte';
  import { allowedRoutesFromNav } from '../../navigation-context';
  import { highlightParts, sectionsFromNav } from './nav-search';
  import {
    buildPaletteResults,
    canRunResult,
    type PaletteResult
  } from '../../palette-results';
  import { quickActions, type QuickActionContext } from '../../quick-actions';

  interface Props {
    onNavigate?: (route: string) => void;
    onClose?: () => void;
  }

  let { onNavigate, onClose }: Props = $props();

  let activeIndex = $state(0);
  let dialogEl = $state<HTMLDivElement | null>(null);
  let inputEl = $state<HTMLInputElement | null>(null);
  let listEl = $state<HTMLDivElement | null>(null);
  let previouslyFocused: HTMLElement | null = null;

  const open = $derived(commandPalette.open);
  const query = $derived(commandPalette.query);
  const sections = $derived(sectionsFromNav(appSettings.visibleNav));
  const allowed = $derived(allowedRoutesFromNav(appSettings.visibleNav));
  const context = $derived<QuickActionContext>({
    navIds: new Set(appSettings.visibleNav.map((node) => node.id)),
    capabilities: auth.capabilities,
    requestsEnabled: appSettings.requestsEnabled,
    salesEnabled: appSettings.salesEnabled,
    chatEnabled: appSettings.chatEnabled
  });
  const results = $derived(
    buildPaletteResults({ sections, context, recent: recentPages.entries, query })
  );
  const activeId = $derived(results[activeIndex] ? `palette-option-${activeIndex}` : undefined);

  const GROUP_LABELS: Record<PaletteResult['kind'], string> = {
    recent: 'Недавние',
    page: 'Страницы',
    action: 'Действия',
    system: 'Система'
  };

  function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
      return false;
    }
    return (
      target.isContentEditable ||
      target.tagName === 'INPUT' ||
      target.tagName === 'TEXTAREA' ||
      target.tagName === 'SELECT'
    );
  }

  function close(): void {
    commandPalette.close();
    onClose?.();
  }

  function choose(result: PaletteResult): void {
    if (!canRunResult(result, context, allowed)) {
      return;
    }

    close();

    if (result.system === 'clear-recent') {
      recentPages.clear();
      return;
    }

    if (result.route === null) {
      return;
    }
    if (onNavigate) {
      onNavigate(result.route);
    } else {
      router.navigate(result.route);
    }
  }

  function focusableElements(): HTMLElement[] {
    if (dialogEl === null) {
      return [];
    }
    return Array.from(
      dialogEl.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter((element) => element.offsetParent !== null);
  }

  function onKeydown(event: KeyboardEvent): void {
    const shortcut = (event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k';

    if (shortcut) {
      // В полях ввода сочетание принадлежит приложению/браузеру, не Palette.
      if (isTypingTarget(event.target)) {
        return;
      }
      event.preventDefault();
      commandPalette.toggle();
      return;
    }

    if (!open) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close();
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

  /** Стрелки работают и после Tab, когда фокус уже на пункте результата. */
  function onDialogKeydown(event: KeyboardEvent): void {
    if (event.target === inputEl || dialogEl === null || !dialogEl.contains(event.target as Node)) {
      return;
    }
    if (results.length === 0) {
      return;
    }

    const focusResult = (index: number): void => {
      activeIndex = index;
      queueMicrotask(() => document.getElementById(`palette-option-${index}`)?.focus());
    };

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      focusResult((activeIndex + 1) % results.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      focusResult((activeIndex - 1 + results.length) % results.length);
    } else if (event.key === 'Home') {
      event.preventDefault();
      focusResult(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      focusResult(results.length - 1);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const index = Number((document.activeElement as HTMLElement | null)?.id?.replace('palette-option-', ''));
      if (Number.isInteger(index) && results[index] !== undefined) {
        choose(results[index]);
      }
    }
  }

  function onInputKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close();
      return;
    }

    if (results.length === 0) {
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activeIndex = (activeIndex + 1) % results.length;
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = (activeIndex - 1 + results.length) % results.length;
    } else if (event.key === 'Home') {
      event.preventDefault();
      activeIndex = 0;
    } else if (event.key === 'End') {
      event.preventDefault();
      activeIndex = results.length - 1;
    } else if (event.key === 'Enter') {
      event.preventDefault();
      choose(results[activeIndex]);
    }
  }

  $effect(() => {
    if (!open) {
      return;
    }

    previouslyFocused = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const timer = setTimeout(() => inputEl?.focus(), 0);

    return () => {
      clearTimeout(timer);
      document.body.style.overflow = previousOverflow;
      previouslyFocused?.focus?.();
    };
  });

  $effect(() => {
    void commandPalette.query;
    activeIndex = 0;
  });

  $effect(() => {
    if (activeIndex >= results.length) {
      activeIndex = 0;
    }
  });

  $effect(() => {
    const id = activeId;
    if (id === undefined) {
      return;
    }
    queueMicrotask(() => document.getElementById(id)?.scrollIntoView({ block: 'nearest' }));
  });
</script>

<svelte:window onkeydown={onKeydown} />

{#if open}
  <div class="palette-overlay">
    <button
      type="button"
      class="palette-backdrop"
      tabindex="-1"
      aria-label="Закрыть Command Palette"
      onclick={close}
    ></button>
    <div
      class="palette-dialog"
      bind:this={dialogEl}
      role="dialog"
      aria-modal="true"
      aria-labelledby="palette-title"
      tabindex="-1"
      onkeydown={onDialogKeydown}
    >
      <div class="palette-head">
        <h2 id="palette-title">Command Palette</h2>
        <button type="button" class="palette-close" aria-label="Закрыть" onclick={close}>
          <Icon name="close" size={16} />
        </button>
      </div>

      <div class="palette-field">
        <Icon name="search" size={18} />
        <input
          bind:this={inputEl}
          value={commandPalette.query}
          oninput={(event) => (commandPalette.query = event.currentTarget.value)}
          type="search"
          role="combobox"
          aria-label="Поиск по разделам и действиям"
          aria-autocomplete="list"
          aria-expanded={results.length > 0}
          aria-controls="palette-results"
          aria-activedescendant={activeId}
          autocomplete="off"
          placeholder="Раздел, действие, недавняя страница"
          onkeydown={onInputKeydown}
        />
      </div>

      <p class="palette-status" aria-live="polite">
        {results.length > 0 ? `Доступно: ${results.length}` : 'Нет доступных результатов'}
      </p>

      {#if results.length === 0}
        <div class="palette-empty">
          <Icon name="info" size={22} />
          <strong>Ничего не найдено</strong>
          <span>Попробуйте другое название или проверьте доступ к разделу.</span>
        </div>
      {:else}
        <div class="palette-results" id="palette-results" role="listbox" aria-label="Результаты" bind:this={listEl}>
          {#each results as result, index (result.id)}
            {#if index === 0 || results[index - 1].kind !== result.kind}
              <p class="palette-group" id={`palette-group-${result.kind}`}>{GROUP_LABELS[result.kind]}</p>
            {/if}
            <button
              type="button"
              id={`palette-option-${index}`}
              class="palette-option"
              class:active={index === activeIndex}
              role="option"
              aria-selected={index === activeIndex}
              aria-describedby={`palette-group-${result.kind}`}
              onmouseenter={() => (activeIndex = index)}
              onclick={() => choose(result)}
            >
              <span class="option-icon"><Icon name={result.iconId} size={18} /></span>
              <span class="option-body">
                <span class="option-label">
                  {#each highlightParts(result.label, query) as part}
                    <span class:hit={part.hit}>{part.text}</span>
                  {/each}
                </span>
                <span class="option-sub">{result.sublabel}</span>
              </span>
              <span class="option-kind">{GROUP_LABELS[result.kind]}</span>
            </button>
          {/each}
        </div>
      {/if}
    </div>
  </div>
{/if}

<style>
  .palette-overlay {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: start center;
    padding: max(24px, 7vh) 12px 12px;
    /* Как и обычные модалки, центрируем в рабочей области справа от рейла. */
    padding-left: calc(var(--rail-width, 0px) + 12px);
  }

  .palette-backdrop {
    position: absolute;
    inset: 0;
    border: 0;
    padding: 0;
    background: rgba(0, 0, 0, 0.45);
    cursor: default;
  }

  .palette-dialog {
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(640px, 100%);
    max-height: calc(100dvh - 48px);
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-md);
  }

  .palette-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-secondary);
  }

  .palette-head h2 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
  }

  .palette-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
  }

  .palette-field {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 12px 14px 8px;
    padding: 0 12px;
    min-height: 46px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--muted);
  }

  .palette-field:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary) 18%, transparent);
  }

  .palette-field input {
    flex: 1 1 auto;
    min-width: 0;
    border: 0;
    outline: none;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 14px;
  }

  .palette-status {
    margin: 0 14px 6px;
    color: var(--muted);
    font-size: 12px;
  }

  .palette-results {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 0 8px 8px;
  }

  .palette-group {
    margin: 8px 8px 2px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .palette-option {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 48px;
    padding: 6px 10px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    text-align: left;
    cursor: pointer;
  }

  .palette-option:hover,
  .palette-option.active {
    background: var(--fill-tertiary);
  }

  .palette-option.active {
    box-shadow: inset 3px 0 0 var(--primary);
  }

  .option-icon {
    display: inline-flex;
    flex: 0 0 auto;
    color: var(--muted);
  }

  .option-body {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
    gap: 2px;
  }

  .option-label {
    font-size: 13px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .option-label .hit {
    border-radius: 2px;
    background: color-mix(in srgb, var(--primary) 18%, transparent);
    color: var(--primary);
  }

  .option-sub {
    color: var(--muted);
    font-size: 11px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .option-kind {
    flex: 0 0 auto;
    color: var(--muted);
    font-size: 11px;
  }

  .palette-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 24px 14px 28px;
    color: var(--muted);
    text-align: center;
  }

  .palette-empty strong {
    color: var(--text);
    font-size: 13px;
  }

  .palette-empty span {
    font-size: 12px;
  }

  @media (max-width: 767px) {
    .palette-overlay {
      padding: 12px;
    }

    .palette-dialog {
      max-height: calc(100dvh - 24px);
    }

    .option-kind {
      display: none;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .palette-option,
    .palette-close {
      transition: none;
    }
  }
</style>
