<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listManagers } from '../lib/api/requests';
  import {
    endSubstitution,
    listSubstitutions,
    updateSubstitution
  } from '../lib/api/substitutions';
  import type { ManagerItem, Substitution } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }

  const today = iso(new Date());

  let item = $state<Substitution | null>(null);
  let managers = $state<ManagerItem[]>([]);
  let managerId = $state<number | null>(null);
  let substituteId = $state<number | null>(null);
  let dateFrom = $state(today);
  let dateTo = $state(today);
  let reason = $state('');
  let loading = $state(true);
  let saving = $state(false);
  let deleting = $state(false);
  let error = $state('');
  let message = $state('');

  const editable = $derived(item?.is_active === true);
  const deleteTitle = $derived.by(() => {
    if (item === null) {
      return 'Удалить замещение';
    }

    return item.date_from > today ? 'Удалить замещение' : 'Завершить замещение';
  });

  $effect(() => {
    void id;
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';
    message = '';
    item = null;

    try {
      const [items, managerData] = await Promise.all([listSubstitutions(), listManagers()]);
      const found = items.find((entry) => entry.id === id) ?? null;

      managers = managerData.items;

      if (found === null) {
        error = 'Замещение не найдено';
        return;
      }

      item = found;
      managerId = found.manager.id;
      substituteId = found.substitute.id;
      dateFrom = found.date_from;
      dateTo = found.date_to;
      reason = found.reason;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить замещение';
    } finally {
      loading = false;
    }
  }

  async function save(): Promise<void> {
    error = '';
    message = '';

    if (managerId === null || substituteId === null) {
      error = 'Выберите сотрудника и заместителя';
      return;
    }

    if (managerId === substituteId) {
      error = 'Сотрудник и заместитель должны быть разными';
      return;
    }

    saving = true;

    try {
      const result = await updateSubstitution(id, {
        manager_id: managerId,
        substitute_id: substituteId,
        date_from: dateFrom,
        date_to: dateTo,
        reason
      });

      item = result.item;
      managerId = result.item.manager.id;
      substituteId = result.item.substitute.id;
      dateFrom = result.item.date_from;
      dateTo = result.item.date_to;
      reason = result.item.reason;
      message = 'Замещение сохранено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить замещение';
    } finally {
      saving = false;
    }
  }

  async function remove(): Promise<void> {
    if (item === null || !editable) {
      return;
    }

    const question =
      item.date_from > today
        ? 'Удалить замещение? Оно ещё не началось.'
        : `Завершить замещение и вернуть заявки заместителя (${item.requests_count})?`;

    if (!confirm(question)) {
      return;
    }

    deleting = true;
    error = '';
    message = '';

    try {
      await endSubstitution(id);
      router.navigate('/substitutions');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить замещение';
    } finally {
      deleting = false;
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="head">
      <button
        type="button"
        class="back"
        title="Вернуться к замещениям"
        aria-label="Вернуться к замещениям"
        onclick={() => router.navigate('/substitutions')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
      <div class="head-text">
        <h1>Редактирование замещения</h1>
        <p class="sub">
          Изменения периода и участников пересчитают заявки на время замещения.
        </p>
      </div>
      {#if item && editable}
        <div class="head-actions">
          <button
            type="button"
            class="icon-btn danger"
            title={deleteTitle}
            aria-label={deleteTitle}
            disabled={saving || deleting}
            onclick={() => void remove()}
          >
            {#if deleting}
              <Spinner size={16} />
            {:else}
              <Icon name="trash" size={16} />
            {/if}
          </button>
        </div>
      {/if}
    </div>
  </div>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if item === null}
    <div class="empty">Замещение не найдено.</div>
  {:else if !editable}
    <div class="empty">Замещение завершено — редактирование недоступно.</div>
  {:else}
    <div class="card">
      <div class="grid">
        <label>
          <span>Кто отсутствует</span>
          <select bind:value={managerId} disabled={saving}>
            <option value={null}>- Выберите -</option>
            {#each managers as manager (manager.id)}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label>
          <span>Заместитель</span>
          <select bind:value={substituteId} disabled={saving}>
            <option value={null}>- Выберите -</option>
            {#each managers as manager (manager.id)}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label>
          <span>С</span>
          <input type="date" bind:value={dateFrom} disabled={saving} />
        </label>

        <label>
          <span>По</span>
          <input type="date" bind:value={dateTo} min={dateFrom} disabled={saving} />
        </label>
      </div>

      <label class="wide">
        <span>Причина (необязательно)</span>
        <input
          type="text"
          bind:value={reason}
          maxlength="500"
          placeholder="Отпуск, больничный, командировка"
          disabled={saving}
        />
      </label>

      <div class="actions">
        <Button
          variant="ghost"
          disabled={saving}
          onclick={() => router.navigate('/substitutions')}
        >
          Отмена
        </Button>
        <Button loading={saving} onclick={() => void save()}>Сохранить</Button>
      </div>
    </div>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
  }

  .head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    cursor: pointer;
    transition: color 0.12s ease, border-color 0.12s ease;
  }

  .icon-btn:hover:not(:disabled) {
    border-color: var(--error-border);
    color: var(--danger);
  }

  .icon-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .back {
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

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .head-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  .sub {
    margin: 0;
    font-size: 14px;
    color: var(--text-description);
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
    margin-left: auto;
  }

  .alert,
  .notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 14px;
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
    color: var(--error-text);
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success-text);
  }

  .alert-icon,
  .notice-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 1px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  .empty {
    padding: var(--space-6);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text-description);
    text-align: center;
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-3);
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    color: var(--text-description);
  }

  select,
  input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
  }

  select:focus,
  input:focus {
    outline: none;
    border-color: var(--primary);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
  }
</style>
