<script lang="ts">
  interface Option {
    value: string;
    label: string;
  }

  interface Props {
    label?: string;
    options: Option[];
    values?: string[];
    allLabel?: string;
    emptyLabel?: string;
    maxHeight?: string;
  }

  let {
    label = '',
    options,
    values = $bindable([]),
    allLabel = 'Все',
    emptyLabel = 'Нет данных',
    maxHeight = '260px'
  }: Props = $props();

  let open = $state(false);
  let triggerEl = $state<HTMLButtonElement | null>(null);
  let menuStyle = $state('');

  const summary = $derived.by(() => {
    if (values.length === 0 || values.length === options.length) {
      return allLabel;
    }

    const labels = options
      .filter((option) => values.includes(option.value))
      .map((option) => option.label);

    return labels.length > 0 ? labels.join(', ') : allLabel;
  });

  $effect(() => {
    if (!open) {
      return;
    }

    updatePosition();
    const handleScroll = () => updatePosition();
    window.addEventListener('scroll', handleScroll, true);
    window.addEventListener('resize', handleScroll);

    return () => {
      window.removeEventListener('scroll', handleScroll, true);
      window.removeEventListener('resize', handleScroll);
    };
  });

  function updatePosition(): void {
    if (triggerEl === null) {
      return;
    }

    const rect = triggerEl.getBoundingClientRect();
    const gap = 4;
    const viewportGap = 8;
    const requested = Number.parseInt(maxHeight, 10) || 260;
    const spaceBelow = window.innerHeight - rect.bottom - gap - viewportGap;
    const spaceAbove = rect.top - gap - viewportGap;
    const placeAbove = spaceBelow < 80 && spaceAbove > spaceBelow;
    const available = Math.max(0, placeAbove ? spaceAbove : spaceBelow);
    const height = Math.max(80, Math.min(requested, available));
    const vertical = placeAbove
      ? `bottom: ${window.innerHeight - rect.top + gap}px`
      : `top: ${rect.bottom + gap}px`;

    menuStyle = [
      vertical,
      `left: ${Math.max(viewportGap, rect.left)}px`,
      `width: ${rect.width}px`,
      `max-height: ${height}px`
    ].join('; ');
  }

  function toggleMenu(): void {
    open = !open;
  }

  function toggle(value: string): void {
    values = values.includes(value)
      ? values.filter((item) => item !== value)
      : [...values, value];
  }

  function handleFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;
    const current = event.currentTarget as HTMLElement | null;

    if (next === null || current === null || !current.contains(next)) {
      open = false;
    }
  }
</script>

<div class="multi" onfocusout={handleFocusOut}>
  <button
    type="button"
    class="trigger"
    bind:this={triggerEl}
    aria-label={label || summary}
    aria-haspopup="listbox"
    aria-expanded={open}
    disabled={options.length === 0}
    onclick={toggleMenu}
  >
    <span class="value">{summary}</span>
    <span class="caret" aria-hidden="true">▾</span>
  </button>

  {#if open}
    <div class="menu" role="listbox" aria-label={label} style={menuStyle}>
      {#each options as option (option.value)}
        <label class="option">
          <input
            type="checkbox"
            checked={values.includes(option.value)}
            onchange={() => toggle(option.value)}
          />
          <span>{option.label}</span>
        </label>
      {/each}
      {#if options.length === 0}
        <span class="empty">{emptyLabel}</span>
      {/if}
    </div>
  {/if}
</div>

<style>
  .multi {
    position: relative;
  }

  .trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    width: 100%;
    min-height: 34px;
    padding: 7px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    font: inherit;
    font-size: 14px;
    text-align: left;
  }

  .trigger:hover:not(:disabled),
  .trigger[aria-expanded='true'] {
    border-color: var(--primary);
  }

  .trigger:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  .value {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .caret {
    flex: 0 0 auto;
    color: var(--muted);
    font-size: 11px;
    transition: transform 0.15s ease;
  }

  .trigger[aria-expanded='true'] .caret {
    transform: rotate(180deg);
  }

  .menu {
    position: fixed;
    z-index: 60;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    box-shadow: var(--shadow-md);
  }

  .option {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
    padding: 7px 8px;
    border-radius: var(--radius-xs);
    color: var(--text);
    cursor: pointer;
    font-size: 14px;
  }

  .option:hover {
    background: var(--fill-hover);
  }

  .option input {
    accent-color: var(--primary);
  }

  .empty {
    padding: 8px;
    color: var(--muted);
    font-size: 13px;
  }
</style>
