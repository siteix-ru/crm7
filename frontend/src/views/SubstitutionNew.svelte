<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listManagers } from '../lib/api/requests';
  import { createSubstitution } from '../lib/api/substitutions';
  import type { ManagerItem } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }

  const today = iso(new Date());

  let managers = $state<ManagerItem[]>([]);
  let managerId = $state<number | null>(null);
  let substituteId = $state<number | null>(null);
  let dateFrom = $state(today);
  let dateTo = $state(today);
  let reason = $state('');
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      managers = (await listManagers()).items;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сотрудников';
    } finally {
      loading = false;
    }
  }

  async function submit(): Promise<void> {
    error = '';

    if (managerId === null || substituteId === null) {
      error = 'Выберите сотрудника и заместителя';
      return;
    }

    if (managerId === substituteId) {
      error = 'Сотрудник и заместитель должны быть разными';
      return;
    }

    busy = true;

    try {
      await createSubstitution({
        manager_id: managerId,
        substitute_id: substituteId,
        date_from: dateFrom,
        date_to: dateTo,
        reason
      });

      router.navigate('/substitutions');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать замещение';
    } finally {
      busy = false;
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
        <h1>Новое замещение</h1>
        <p class="sub">
          На время замещения заявки сотрудника передаются заместителю и автоматически
          возвращаются, когда период закончится.
        </p>
      </div>
    </div>
  </div>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else}
    <div class="card">
      <div class="grid">
        <label>
          <span>Кто отсутствует</span>
          <select bind:value={managerId} disabled={busy}>
            <option value={null}>- Выберите -</option>
            {#each managers as manager (manager.id)}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label>
          <span>Заместитель</span>
          <select bind:value={substituteId} disabled={busy}>
            <option value={null}>- Выберите -</option>
            {#each managers as manager (manager.id)}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label>
          <span>С</span>
          <input type="date" bind:value={dateFrom} disabled={busy} />
        </label>

        <label>
          <span>По</span>
          <input type="date" bind:value={dateTo} min={dateFrom} disabled={busy} />
        </label>
      </div>

      <label class="wide">
        <span>Причина (необязательно)</span>
        <input
          type="text"
          bind:value={reason}
          maxlength="500"
          placeholder="Отпуск, больничный, командировка"
          disabled={busy}
        />
      </label>

      <div class="actions">
        <Button
          variant="ghost"
          disabled={busy}
          onclick={() => router.navigate('/substitutions')}
        >
          Отмена
        </Button>
        <Button loading={busy} onclick={() => void submit()}>Создать замещение</Button>
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
    max-width: 900px;
    margin-inline: auto;
  }

  .head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
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

  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 10px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--error-text);
    font-size: 14px;
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 1px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
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
