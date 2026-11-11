<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import {
    createPriceType,
    deletePriceType,
    listAdminPriceTypes,
    updatePriceType
  } from '../lib/api/prices';
  import type { PriceType } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const isNew = $derived(id <= 0);

  let type = $state<PriceType | null>(null);
  let title = $state('');
  let description = $state('');
  let enabled = $state(true);
  let loading = $state(true);
  let saving = $state(false);
  let deleting = $state(false);
  let error = $state('');
  let message = $state('');

  const deleteLocked = $derived((type?.users_count ?? 0) > 0);
  const deleteTitle = $derived.by(() => {
    if (type === null) {
      return 'Удалить тип цен';
    }

    if (deleteLocked) {
      return `Тип закреплён за ${type.users_count} польз. — удаление недоступно`;
    }

    return 'Удалить тип цен';
  });

  $effect(() => {
    void id;
    void load();
  });

  async function load(): Promise<void> {
    error = '';
    message = '';
    type = null;

    if (isNew) {
      title = '';
      description = '';
      enabled = true;
      loading = false;

      return;
    }

    loading = true;

    try {
      const data = await listAdminPriceTypes();
      const found = data.items.find((item) => item.id === id) ?? null;

      if (found === null) {
        error = 'Тип цен не найден';
      } else {
        type = found;
        title = found.title;
        description = found.description ?? '';
        enabled = found.enabled !== false;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить тип цен';
    } finally {
      loading = false;
    }
  }

  async function save(): Promise<void> {
    const trimmed = title.trim();

    error = '';
    message = '';

    if (trimmed === '') {
      error = 'Укажите название типа цен';
      return;
    }

    saving = true;

    try {
      if (isNew) {
        await createPriceType(trimmed, description.trim(), enabled);
        router.navigate('/stocks/price-types');
        return;
      }

      await updatePriceType(id, trimmed, description.trim(), enabled);
      const data = await listAdminPriceTypes();
      type = data.items.find((item) => item.id === id) ?? null;

      if (type === null) {
        error = 'Тип цен не найден';
        return;
      }

      title = type.title;
      description = type.description ?? '';
      enabled = type.enabled !== false;
      message = 'Тип цен сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить тип цен';
    } finally {
      saving = false;
    }
  }

  async function removeType(): Promise<void> {
    if (isNew || type === null || deleteLocked) {
      return;
    }

    const suffix =
      (type.prices_count ?? 0) > 0 ? ' Цены этого типа также будут удалены.' : '';

    if (!confirm(`Удалить тип цен «${type.title}»?${suffix}`)) {
      return;
    }

    deleting = true;
    error = '';
    message = '';

    try {
      await deletePriceType(id);
      router.navigate('/stocks/price-types');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить тип цен';
    } finally {
      deleting = false;
    }
  }
</script>

<section class="page">
  <div class="hero page-hero">
    <div class="head">
      <button
        type="button"
        class="back"
        title="Вернуться к типам цен"
        aria-label="Вернуться к типам цен"
        onclick={() => router.navigate('/stocks/price-types')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
      <div class="head-text">
        <h1>{isNew ? 'Новый тип цен' : 'Редактирование типа цен'}</h1>
        <p class="sub">
          {isNew
            ? 'Укажите название — код сгенерируется автоматически.'
            : 'Название видно клиентам и сотрудникам при выборе типа профиля.'}
        </p>
      </div>
      {#if !isNew}
        <div class="head-actions">
          <button
            type="button"
            class="icon-btn danger"
            title={deleteTitle}
            aria-label="Удалить тип цен"
            disabled={saving || deleting || deleteLocked}
            onclick={() => void removeType()}
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
  {:else if !isNew && type === null}
    <div class="empty">Тип цен не найден.</div>
  {:else}
    <div class="layout" class:single={isNew || type === null}>
      <div class="card">
        <div class="form">
          <Input
            label="Название"
            bind:value={title}
            placeholder="Например, Оптовая"
            maxlength={255}
            disabled={saving}
          />

          <label class="field">
            <span class="label">Описание</span>
            <textarea
              bind:value={description}
              rows="4"
              maxlength="1000"
              placeholder="Кому и для чего подходит этот тип цен"
              disabled={saving}
            ></textarea>
          </label>

          {#if !isNew && type}
            <div class="code-row">
              <span class="label">Код</span>
              <code>{type.code}</code>
            </div>
          {/if}

          <label class="availability">
            <span class="availability-text">
              <span class="availability-title">Доступен для выбора</span>
              <span class="availability-hint">
                {enabled
                  ? 'Тип показывается при назначении клиентам и выборе цен номенклатуры.'
                  : 'Тип скрыт из выбора; цены и назначения сохраняются.'}
              </span>
            </span>
            <span class="switch" title="Доступен для выбора">
              <input type="checkbox" bind:checked={enabled} disabled={saving} />
              <span class="switch-track" aria-hidden="true"></span>
            </span>
          </label>

          <div class="actions">
            <Button
              variant="ghost"
              disabled={saving}
              onclick={() => router.navigate('/stocks/price-types')}
            >
              Отмена
            </Button>
            <Button loading={saving} disabled={!title.trim()} onclick={() => void save()}>
              {isNew ? 'Создать тип' : 'Сохранить'}
            </Button>
          </div>
        </div>
      </div>

      {#if !isNew && type}
        <div class="card">
          <h2>Использование</h2>

          <div class="usage">
            <div class="usage-row">
              <span>Пользователи</span>
              <strong>{type.users_count ?? 0}</strong>
            </div>
            <div class="usage-row">
              <span>Цены номенклатуры</span>
              <strong>{type.prices_count ?? 0}</strong>
            </div>
          </div>

          <p class="hint">
            {#if deleteLocked}
              Пока тип закреплён за пользователями, удалить его нельзя. Снимите тип в карточках
              пользователей.
            {:else}
              Цена для клиента берётся по его типу профиля. Если тип не назначен, используется
              первый в списке.
            {/if}
          </p>
        </div>
      {/if}
    </div>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .layout.single {
    grid-template-columns: minmax(0, 1fr);
    max-width: 720px;
    margin: 0 auto;
    width: 100%;
  }

  .hero {
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
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

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
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
    color: var(--text-description);
    text-align: center;
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
  }

  textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    resize: vertical;
  }

  textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .code-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  code {
    padding: 3px 8px;
    border-radius: var(--radius-xs);
    background: var(--bg);
    color: var(--text-description);
    font-size: 13px;
  }

  .availability {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
    cursor: pointer;
  }

  .availability-text {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
  }

  .availability-title {
    font-size: 14px;
    font-weight: 500;
  }

  .availability-hint {
    color: var(--text-description);
    font-size: 13px;
    line-height: 1.45;
  }

  .switch {
    position: relative;
    flex: 0 0 40px;
    width: 40px;
    height: 22px;
  }

  .switch input {
    position: absolute;
    inset: 0;
    z-index: 1;
    margin: 0;
    opacity: 0;
    cursor: pointer;
  }

  .switch-track {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: var(--border);
    transition: background 0.15s ease;
  }

  .switch-track::before {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    transition: transform 0.15s ease;
  }

  .switch input:checked + .switch-track {
    background: var(--primary);
  }

  .switch input:checked + .switch-track::before {
    transform: translateX(18px);
  }

  .switch input:disabled {
    cursor: not-allowed;
  }

  .switch input:disabled + .switch-track {
    opacity: 0.55;
  }

  .usage {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .usage-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
    font-size: 14px;
  }

  .usage-row strong {
    font-size: 16px;
  }

  .hint {
    margin: 0;
    color: var(--text-description);
    font-size: 13px;
    line-height: 1.45;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
  }

  @media (max-width: 720px) {
    .layout {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
