<script lang="ts">
  import type { FullAutoFill } from 'svelte/elements';

  interface Props {
    label?: string;
    type?: string;
    value?: string;
    placeholder?: string;
    error?: string;
    autocomplete?: FullAutoFill;
    name?: string;
    disabled?: boolean;
    inputmode?: 'none' | 'text' | 'decimal' | 'numeric' | 'tel' | 'search' | 'email' | 'url';
    min?: string | number;
    max?: string | number;
    step?: string | number;
    maxlength?: number;
    ariaLabel?: string;
    ariaInvalid?: boolean;
    ariaDescribedby?: string;
    id?: string;
    onblur?: (event: FocusEvent) => void;
    inline?: boolean;
  }

  let {
    label = '',
    type = 'text',
    value = $bindable(''),
    placeholder = '',
    error = '',
    autocomplete,
    name = '',
    disabled = false,
    inputmode,
    min,
    max,
    step,
    maxlength,
    ariaLabel,
    ariaInvalid,
    ariaDescribedby,
    id,
    onblur,
    inline = false
  }: Props = $props();
</script>

<label class="field" class:inline>
  {#if label}
    <span class="label">{label}</span>
  {/if}
  <input
    {type}
    bind:value
    {placeholder}
    {autocomplete}
    {name}
    {disabled}
    {inputmode}
    {min}
    {max}
    {step}
    {maxlength}
    {id}
    aria-label={ariaLabel}
    aria-invalid={ariaInvalid ? 'true' : undefined}
    aria-describedby={ariaDescribedby}
    class:invalid={Boolean(error)}
    {onblur}
  />
  {#if error}
    <span class="error">{error}</span>
  {/if}
</label>

<style>
  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .field.inline {
    display: grid;
    grid-template-columns: minmax(140px, 0.8fr) minmax(0, 1.4fr);
    gap: var(--space-3);
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .field.inline .label {
    color: var(--muted);
    font-size: 13px;
  }

  .field.inline .error {
    grid-column: 2;
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  input {
    height: 32px;
    padding: 0 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  input.invalid {
    border-color: var(--danger);
  }

  input:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  .error {
    font-size: 12px;
    color: var(--danger);
  }
</style>
