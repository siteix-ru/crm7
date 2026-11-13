<script lang="ts">
  import Modal from './Modal.svelte';
  import Button from './Button.svelte';
  import Icon from './Icon.svelte';

  export interface SortField {
    field: string;
    label: string;
  }

  interface Props {
    open: boolean;
    fields: SortField[];
    sort: string;
    defaultValue: string;
    onclose: () => void;
    onapply: (terms: string[]) => void;
  }

  let { open, fields, sort, defaultValue, onclose, onapply }: Props = $props();
  let draft = $state<string[]>([]);
  let dragField = $state<string | null>(null);
  let dragOver = $state<string | null>(null);

  $effect(() => {
    if (!open) {
      return;
    }

    const terms = sort.split(',').map((term) => term.trim()).filter((term) => term !== '');
    draft = terms.length > 0 ? [...terms] : [defaultValue];
  });

  const rows = $derived.by(() => {
    const active = draft
      .map((term) => fields.find((item) => term.startsWith(`${item.field}_`)))
      .filter((item): item is SortField => item !== undefined);
    const inactive = fields.filter((item) => draftIndex(item.field) === 0);

    return [...active, ...inactive];
  });

  function draftIndex(field: string): number {
    return draft.findIndex((term) => term === field || term.startsWith(`${field}_`)) + 1;
  }

  function dirActive(field: string, dir: 'asc' | 'desc'): boolean {
    return draft.includes(`${field}_${dir}`);
  }

  function toggleDir(field: string, dir: 'asc' | 'desc'): void {
    const term = `${field}_${dir}`;
    const index = draft.findIndex((item) => item.startsWith(`${field}_`));

    if (index >= 0 && draft[index] === term) {
      draft = draft.filter((_, itemIndex) => itemIndex !== index);
      return;
    }

    if (index >= 0) {
      const next = [...draft];
      next[index] = term;
      draft = next;
      return;
    }

    draft = [...draft, term];
  }

  function move(fromField: string, toField: string): void {
    const fromIndex = draft.findIndex((term) => term.startsWith(`${fromField}_`));
    const toIndex = draft.findIndex((term) => term.startsWith(`${toField}_`));

    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
      return;
    }

    const next = [...draft];
    const [moved] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, moved);
    draft = next;
  }

  /*
   * Перестановка условий: pointer-события (мышь и палец), а не HTML5 drag&drop,
   * который не работает на тач-устройствах.
   */
  function startDrag(event: PointerEvent, field: string): void {
    if (draftIndex(field) === 0) {
      return;
    }

    dragField = field;
    dragOver = null;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    event.preventDefault();
  }

  function dragMove(event: PointerEvent): void {
    if (dragField === null) {
      return;
    }

    const row = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-sort-field]');
    const target = row?.getAttribute('data-sort-field') ?? null;

    if (target !== null && target !== dragField && draftIndex(target) > 0) {
      dragOver = target;
      move(dragField, target);
    }
  }

  function endDrag(): void {
    dragField = null;
    dragOver = null;
  }

  function reset(): void {
    draft = [defaultValue];
  }

  function apply(): void {
    onapply(draft.length > 0 ? draft : [defaultValue]);
  }
</script>

<Modal open={open} title="Сортировка" onclose={onclose}>
  <p class="sort-hint">
    Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку, чтобы добавить
    условие, и повторно — чтобы убрать.
  </p>

  <div class="sort-list" role="list">
    {#each rows as column (column.field)}
      {@const active = draftIndex(column.field) > 0}
      <div
        class="sort-row"
        class:active
        class:drag-over={dragOver === column.field && dragField !== column.field}
        role="listitem"
        data-sort-field={column.field}
      >
        <span
          class="sort-drag"
          class:off={!active}
          title={active ? 'Перетащите, чтобы изменить порядок' : undefined}
          aria-hidden="true"
          onpointerdown={(event) => startDrag(event, column.field)}
          onpointermove={dragMove}
          onpointerup={endDrag}
          onpointercancel={endDrag}
        >
          <Icon name="holder" size={14} />
        </span>
        {#if active}
          <span class="sort-order">{draftIndex(column.field)}</span>
        {/if}
        <span class="sort-row-label">{column.label}</span>
        <button
          type="button"
          class="dir-btn"
          class:on={dirActive(column.field, 'asc')}
          title="По возрастанию"
          aria-label={`${column.label}: по возрастанию`}
          aria-pressed={dirActive(column.field, 'asc')}
          onclick={() => toggleDir(column.field, 'asc')}
        >
          <Icon name="arrow-up" size={16} />
        </button>
        <button
          type="button"
          class="dir-btn"
          class:on={dirActive(column.field, 'desc')}
          title="По убыванию"
          aria-label={`${column.label}: по убыванию`}
          aria-pressed={dirActive(column.field, 'desc')}
          onclick={() => toggleDir(column.field, 'desc')}
        >
          <Icon name="arrow-down" size={16} />
        </button>
      </div>
    {/each}
  </div>

  <div class="modal-actions">
    <Button variant="ghost" onclick={reset}>Сбросить</Button>
    <div class="modal-buttons">
      <Button variant="ghost" onclick={onclose}>Отмена</Button>
      <Button onclick={apply}>Применить</Button>
    </div>
  </div>
</Modal>

<style>
  .sort-hint {
    margin: 0 0 var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .sort-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 340px;
    overflow-y: auto;
  }

  .sort-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
  }

  .sort-row.active {
    border-color: var(--primary-border);
    background: var(--primary-bg);
  }

  .sort-row.active .sort-row-label {
    font-weight: 600;
  }

  .sort-row.drag-over {
    border-color: var(--primary);
    box-shadow: inset 0 2px 0 var(--primary);
  }

  .sort-drag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 18px;
    height: 24px;
    color: var(--muted);
    cursor: grab;
    touch-action: none;
  }

  .sort-drag:active {
    cursor: grabbing;
  }

  .sort-drag.off {
    visibility: hidden;
    cursor: default;
  }

  .sort-row-label {
    flex: 1;
    min-width: 0;
    font-size: 14px;
  }

  .sort-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--primary);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
  }

  .dir-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
  }

  .dir-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .dir-btn.on {
    border-color: var(--primary);
    background: var(--primary-bg);
    color: var(--primary);
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }
</style>
