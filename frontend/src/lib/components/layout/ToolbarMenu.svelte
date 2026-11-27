<script lang="ts">
  import { onDestroy, tick } from 'svelte';
  import { getHistory } from '../../search-history';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import Modal from '../ui/Modal.svelte';
  import Spinner from '../ui/Spinner.svelte';

  export interface ToolbarMenuItem {
    id: string;
    label: string;
    icon?: string;
    checked?: boolean;
    disabled?: boolean;
    separator?: boolean;
    dialog?: boolean;
    count?: number;
    onclick: () => void;
  }

  interface SearchConfig {
    value: string;
    historyKey: string;
    placeholder?: string;
    title?: string;
    suggestions?: string[];
    onsearch: (query: string) => Promise<void> | void;
    onclear: () => void;
    onstop?: () => void;
  }

  interface Props {
    label?: string;
    items: ToolbarMenuItem[];
    search?: SearchConfig;
  }

  let { label = 'Действия страницы', items, search }: Props = $props();

  let open = $state(false);
  let root = $state<HTMLDivElement | null>(null);
  let searchOpen = $state(false);
  let draft = $state('');
  let history = $state<string[]>([]);
  let busy = $state(false);
  let notice = $state('');
  let tone = $state<'info' | 'warn' | 'error'>('info');
  let seq = 0;
  let watchdog: ReturnType<typeof setTimeout> | undefined;

  const suggestions = $derived.by(() => {
    const term = draft.trim().toLocaleLowerCase('ru');
    const matches = (value: string): boolean =>
      term === '' || value.toLocaleLowerCase('ru').includes(term);
    const extra = search?.suggestions ?? [];

    return [...new Set([...history, ...extra].filter(matches))].slice(0, 8);
  });

  function trigger(): HTMLElement | null {
    return root?.querySelector<HTMLElement>('button[aria-haspopup="menu"]') ?? null;
  }

  function menuItems(): HTMLElement[] {
    return Array.from(
      root?.querySelectorAll<HTMLElement>(
        '[role="menuitem"]:not([disabled]), [role="menuitemcheckbox"]:not([disabled])'
      ) ?? []
    );
  }

  function close(returnFocus: boolean): void {
    open = false;
    if (returnFocus) {
      requestAnimationFrame(() => trigger()?.focus());
    }
  }

  async function toggle(): Promise<void> {
    open = !open;
    if (!open) {
      return;
    }
    await tick();
    menuItems()[0]?.focus();
  }

  function run(item: ToolbarMenuItem): void {
    // Возвращаем фокус на триггер до открытия диалога — иначе Modal запомнит
    // уже отсоединённый пункт меню и фокус потеряется в <body>.
    trigger()?.focus();
    close(false);
    item.onclick();
  }

  function onKeydown(event: KeyboardEvent): void {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      return;
    }
    const elements = menuItems();
    if (elements.length === 0) {
      return;
    }
    event.preventDefault();
    const current = elements.indexOf(document.activeElement as HTMLElement);
    const next =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? elements.length - 1
          : event.key === 'ArrowDown'
            ? (current + 1) % elements.length
            : current <= 0
              ? elements.length - 1
              : current - 1;
    elements[next]?.focus();
  }

  function onWindowPointerDown(event: PointerEvent): void {
    if (!open || root === null) {
      return;
    }
    const target = event.target as Node | null;
    if (target !== null && root.contains(target)) {
      return;
    }
    // Клик вне меню закрывает его без возврата фокуса на триггер.
    close(false);
  }

  function openSearch(): void {
    close(false);
    if (!search) {
      return;
    }
    draft = search.value;
    history = getHistory(search.historyKey);
    notice = '';
    searchOpen = true;
  }

  function stopWatchdog(): void {
    if (watchdog !== undefined) {
      clearTimeout(watchdog);
      watchdog = undefined;
    }
  }

  async function submitSearch(): Promise<void> {
    if (!search || busy) {
      return;
    }
    const value = draft.trim().slice(0, 200);
    if (value === '') {
      notice = 'Введите запрос для поиска';
      tone = 'warn';
      return;
    }

    const current = ++seq;
    searchOpen = false;
    busy = true;
    notice = `Ищем «${value}»…`;
    tone = 'info';
    stopWatchdog();
    watchdog = setTimeout(() => {
      if (current !== seq) {
        return;
      }
      busy = false;
      tone = 'warn';
      notice = 'Поиск занимает дольше обычного. Попробуйте ещё раз.';
    }, 15_000);

    try {
      await search.onsearch(value);
      if (current !== seq) {
        return;
      }
      stopWatchdog();
      busy = false;
      tone = 'warn';
      notice = search.value.trim() === '' ? `По запросу «${value}» ничего не найдено` : '';
    } catch {
      if (current !== seq) {
        return;
      }
      stopWatchdog();
      busy = false;
      tone = 'error';
      notice = 'Не удалось выполнить поиск. Попробуйте ещё раз.';
    }
  }

  function stopSearch(): void {
    ++seq;
    search?.onstop?.();
    stopWatchdog();
    busy = false;
    tone = 'info';
    notice = 'Поиск остановлен.';
  }

  function clearSearch(): void {
    ++seq;
    search?.onstop?.();
    stopWatchdog();
    busy = false;
    notice = '';
    search?.onclear();
  }

  onDestroy(() => {
    stopWatchdog();
  });
</script>

<svelte:window
  onkeydown={(event) => {
    if (event.key === 'Escape' && open) {
      close(true);
    }
  }}
  onpointerdown={onWindowPointerDown}
/>

<div class="toolbar-menu-root" bind:this={root}>
  {#if search && search.value.trim() !== ''}
    <div
      class="search-chip"
      role="status"
      aria-label={`Активен поиск: ${search.value}`}
    >
      <Icon name="search" size={14} />
      <span class="search-chip-text" title={`Активен поиск: ${search.value}`}>
        Поиск: {search.value}
      </span>
      <button
        type="button"
        class="search-chip-clear"
        title="Очистить поиск"
        aria-label="Очистить поиск"
        onclick={clearSearch}
      >
        <Icon name="close" size={12} />
      </button>
    </div>
  {/if}

  <button
    type="button"
    class="menu-trigger"
    title={label}
    aria-label={label}
    aria-haspopup="menu"
    aria-expanded={open}
    onclick={() => void toggle()}
  >
    <Icon name="more" size={16} />
  </button>

  {#if open}
    <div class="menu" role="menu" aria-label={label} tabindex="-1" onkeydown={onKeydown}>
      {#if search}
        <button type="button" class="menu-item" role="menuitem" onclick={openSearch}>
          <Icon name="search" size={16} />
          <span>Поиск</span>
        </button>
        <div class="menu-separator" role="separator"></div>
      {/if}

      {#each items as item (item.id)}
        {#if item.separator}
          <div class="menu-separator" role="separator"></div>
        {/if}
        {#if !item.separator}
          {#if item.checked === undefined}
            <button
              type="button"
              class="menu-item"
              role="menuitem"
              aria-haspopup={item.dialog ? 'dialog' : undefined}
              aria-label={item.count
                ? `${item.label}, активно: ${item.count}`
                : item.dialog
                  ? item.label
                  : undefined}
              disabled={item.disabled}
              onclick={() => run(item)}
            >
              {#if item.icon}<Icon name={item.icon} size={16} />{/if}
              <span>{item.label}</span>
              {#if item.count}<span class="menu-item-count" aria-hidden="true">{item.count}</span>{/if}
            </button>
          {:else}
            <button
              type="button"
              class="menu-item"
              role="menuitemcheckbox"
              aria-checked={item.checked}
              aria-haspopup={item.dialog ? 'dialog' : undefined}
              disabled={item.disabled}
              onclick={() => run(item)}
            >
              {#if item.icon}<Icon name={item.icon} size={16} />{/if}
              <span>{item.label}</span>
              {#if item.count}<span class="menu-item-count" aria-hidden="true">{item.count}</span>{/if}
            </button>
          {/if}
        {/if}
      {/each}
    </div>
  {/if}
</div>

{#if search}
  <Modal
    open={searchOpen}
    title={search.title ?? 'Поиск'}
    label={search.title ?? 'Поиск'}
    onclose={() => (searchOpen = false)}
  >
    <form class="search-form" onsubmit={(event) => { event.preventDefault(); void submitSearch(); }}>
      <label class="search-field">
        <span>Запрос</span>
        <input
          data-autofocus
          bind:value={draft}
          maxlength="200"
          autocomplete="off"
          placeholder={search.placeholder ?? 'Поиск'}
          aria-label="Поисковый запрос"
        />
      </label>

      {#if suggestions.length > 0}
        <div class="suggestions" role="group" aria-label="Подсказки поиска">
          <p class="suggestions-label">Подсказки</p>
          {#each suggestions as item (item)}
            <button type="button" class="suggestion" onclick={() => (draft = item)}>
              <Icon name="search" size={14} />
              <span>{item}</span>
            </button>
          {/each}
        </div>
      {/if}

      {#if notice && !busy && searchOpen}
        <p class="search-error" role="alert">{notice}</p>
      {/if}

      <div class="search-actions">
        <Button variant="ghost" onclick={() => (searchOpen = false)}>Отмена</Button>
        <Button type="submit" loading={busy}>Найти</Button>
      </div>
    </form>
  </Modal>

  {#if busy || notice}
    <div
      class="search-progress"
      class:warn={tone === 'warn'}
      class:error={tone === 'error'}
      role="status"
      aria-live="polite"
    >
      {#if busy}<Spinner size={16} />{:else}<Icon name="info" size={16} />{/if}
      <span>{notice}</span>
      {#if busy}
        <button type="button" class="progress-stop" onclick={stopSearch}>Остановить</button>
      {/if}
    </div>
  {/if}
{/if}

<style>
  .toolbar-menu-root {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-width: 0;
  }

  .menu-trigger {
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

  .menu-trigger:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 20;
    display: flex;
    flex-direction: column;
    min-width: min(260px, calc(100vw - 32px));
    max-height: min(70vh, 520px);
    overflow-y: auto;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    box-shadow: var(--shadow-md);
  }

  .menu-item {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: var(--radius-xs);
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }

  .menu-item:hover:not(:disabled) {
    background: var(--fill-tertiary);
  }

  .menu-item:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .menu-item:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
  }

  .menu-item span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .menu-item-count {
    flex: 0 0 auto;
    min-width: 18px;
    height: 18px;
    margin-left: auto;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary-bg);
    color: var(--primary);
    font-size: 11px;
    line-height: 18px;
    text-align: center;
  }

  .menu-separator {
    height: 1px;
    margin: 4px 6px;
    background: var(--border-secondary);
  }

  .search-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    min-width: 0;
    max-width: 260px;
    height: var(--control-height);
    padding: 0 var(--space-2);
    border: 1px solid var(--primary-border);
    border-radius: var(--radius-sm);
    background: var(--primary-bg);
    color: var(--primary);
    font-size: 13px;
  }

  .search-chip-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .search-chip-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    flex: 0 0 auto;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: inherit;
    cursor: pointer;
  }

  .search-chip-clear:hover {
    background: var(--fill-hover);
  }

  .search-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .search-field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    color: var(--text-description);
    font-size: 13px;
  }

  .search-field input {
    height: var(--control-height);
    padding: 0 var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
  }

  .search-field input:focus {
    border-color: var(--primary-border);
  }

  .search-field input:focus-visible {
    outline: 2px solid var(--focus-soft);
    outline-offset: -2px;
  }

  .suggestions {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 240px;
    overflow-y: auto;
    padding: var(--space-2);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .suggestions-label {
    margin: 0 0 var(--space-1);
    color: var(--muted);
    font-size: 12px;
  }

  .suggestion {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 8px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }

  .suggestion:hover {
    background: var(--fill-hover);
    color: var(--primary);
  }

  .search-error {
    margin: 0;
    color: var(--error-text);
    font-size: 13px;
  }

  .search-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    padding-top: var(--space-2);
    border-top: 1px solid var(--border-secondary);
  }

  .search-progress {
    position: fixed;
    top: 12px;
    left: 50%;
    z-index: 60;
    display: flex;
    transform: translateX(-50%);
    align-items: center;
    gap: var(--space-2);
    max-width: calc(100vw - 32px);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--primary-border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    box-shadow: var(--shadow-md);
    color: var(--primary);
    font-size: 13px;
  }

  .search-progress.warn {
    border-color: var(--warning);
    color: var(--warning-text);
  }

  .search-progress.error {
    border-color: var(--error-border);
    color: var(--error-text);
  }

  .progress-stop {
    margin-left: auto;
    padding: 4px 8px;
    border: 1px solid currentColor;
    border-radius: var(--radius-sm);
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    cursor: pointer;
  }

  @media (max-width: 720px) {
    .search-chip {
      max-width: min(200px, 55vw);
    }
  }
</style>
