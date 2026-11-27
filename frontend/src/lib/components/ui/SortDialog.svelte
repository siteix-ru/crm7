<script lang="ts">
  import Modal from './Modal.svelte';
  import Button from './Button.svelte';
  import Icon from './Icon.svelte';
  import { cycleSortTerm, summarizeSort } from '../../views/sort-terms';

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
  let announcement = $state('');

  $effect(() => {
    if (!open) {
      return;
    }

    const terms = sort
      .split(',')
      .map((term) => term.trim())
      .filter((term) => term !== '');
    draft = terms.length > 0 ? [...terms] : [defaultValue];
    announcement = '';
  });

  const rows = $derived.by(() => {
    const active = draft
      .map((term) => fields.find((item) => term.startsWith(`${item.field}_`)))
      .filter((item): item is SortField => item !== undefined);
    const inactive = fields.filter((item) => draftIndex(item.field) === 0);

    return [...active, ...inactive];
  });

  function draftIndex(field: string): number {
    return draft.findIndex((term) => term.startsWith(`${field}_`)) + 1;
  }

  function summary(): string {
    return summarizeSort(draft, fields);
  }

  /**
   * Один контрол на поле: выкл → по возрастанию → по убыванию → выкл.
   * Приоритет условий — по порядку включения, перестановка не нужна.
   */
  function cycle(field: string): void {
    const next = cycleSortTerm(draft, field);
    draft = next;
    const label = fields.find((item) => item.field === field)?.label ?? field;
    const index = draftIndex(field);
    const state = next.includes(`${field}_desc`)
      ? 'по убыванию'
      : next.includes(`${field}_asc`)
        ? 'по возрастанию'
        : 'выключена';
    announcement =
      index > 0
        ? `${label}: ${state}. ${summary()}. Порядок: ${index} из ${draft.length}`
        : `${label}: ${state}. ${summary()}`;
  }

  function reset(): void {
    draft = [defaultValue];
    announcement = `Сортировка сброшена: ${summary()}`;
  }

  function apply(): void {
    onapply(draft.length > 0 ? draft : [defaultValue]);
  }
</script>

<Modal open={open} title="Сортировка" size="md" {announcement} onclose={onclose}>
  <p class="sort-hint">
    Нажмите на переключатель условия: не сортировать → по возрастанию → по убыванию → выключить.
    Порядок условий — по порядку включения.
  </p>

  <div class="sort-list" role="list">
    {#each rows as column (column.field)}
      {@const asc = draft.includes(`${column.field}_asc`)}
      {@const desc = draft.includes(`${column.field}_desc`)}
      {@const active = asc || desc}
      <div class="sort-row" class:active role="listitem">
        {#if active}
          <span class="sort-order" aria-hidden="true">{draftIndex(column.field)}</span>
        {/if}
        <span class="sort-row-label">{column.label}</span>
        <button
          type="button"
          class="dir-toggle"
          class:on={active}
          aria-label={`${active ? `Условие ${draftIndex(column.field)} из ${draft.length}. ` : ''}${!active
            ? `Добавить: ${column.label}, сортировка по возрастанию`
            : asc
              ? `${column.label}: по возрастанию, нажмите для убывания`
              : `${column.label}: по убыванию, нажмите чтобы выключить`}`}
          onclick={() => cycle(column.field)}
        >
          {#if !active}
            <Icon name="plus" size={14} />
            <span>Добавить</span>
          {:else if asc}
            <Icon name="arrow-up" size={14} />
            <span>По возрастанию</span>
          {:else}
            <Icon name="arrow-down" size={14} />
            <span>По убыванию</span>
          {/if}
        </button>
      </div>
    {/each}
  </div>

  <div class="modal-actions">
    <Button variant="text" size="lg" onclick={reset}>Сбросить</Button>
    <div class="modal-buttons">
      <Button variant="ghost" size="lg" onclick={onclose}>Отмена</Button>
      <Button size="lg" onclick={apply}>Применить</Button>
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
    gap: var(--space-2);
  }

  .sort-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--control-height-lg);
    padding: var(--space-1) var(--space-2);
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
  }

  .sort-row.active {
    border-color: var(--primary-border);
    background: var(--primary-bg);
  }

  .sort-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary);
    color: var(--on-primary);
    font-size: var(--font-xs);
    font-weight: 600;
  }

  .sort-row-label {
    flex: 1;
    min-width: 0;
    font-size: var(--font-md);
  }

  .sort-row.active .sort-row-label {
    font-weight: 600;
  }

  .dir-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-1);
    min-height: var(--control-height-lg);
    padding: 0 var(--space-3);
    border: 1px solid var(--field-border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    font: inherit;
    font-size: var(--font-sm);
    cursor: pointer;
  }

  .dir-toggle:focus-visible {
    outline: 2px solid var(--focus-soft);
    outline-offset: -2px;
  }

  .dir-toggle:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .dir-toggle.on {
    border-color: var(--primary);
    background: var(--primary-bg);
    color: var(--primary);
    font-weight: 500;
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

  @media (pointer: coarse) {
    .dir-toggle {
      min-height: 44px;
    }
  }
</style>
