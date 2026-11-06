<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import {
    createAdminItemType,
    deleteAdminItemType,
    getAdminItemType,
    listAdminItemTypeItems,
    updateAdminItemType,
    type ItemTypeAdmin
  } from '../lib/api/item-types';
  import type { ItemGroupItem } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const isNew = $derived(id <= 0);

  let type = $state<ItemTypeAdmin | null>(null);
  let title = $state('');
  let description = $state('');
  let enabled = $state(true);
  let loading = $state(true);
  let saving = $state(false);
  let deleting = $state(false);
  let error = $state('');
  let message = $state('');
  let items = $state<ItemGroupItem[]>([]);
  let itemsError = $state('');
  let itemsOpen = $state(false);
  let itemsPage = $state(1);

  const previewItems = $derived(items.slice(0, 20));
  const pagedItems = $derived(items.slice((itemsPage - 1) * 20, itemsPage * 20));
  const enabledLocked = $derived(
    enabled && type !== null && (type.positions_count ?? 0) > 0
  );
  const deleteTitle = $derived.by(() => {
    if (type === null) {
      return 'Удалить тип';
    }

    if (type.is_last) {
      return 'Нельзя удалить последний тип номенклатуры';
    }

    if (type.positions_count > 0) {
      return `Тип используется в ${type.positions_count} поз. номенклатуры — удаление недоступно`;
    }

    return 'Удалить тип';
  });

  $effect(() => {
    void id;
    void load();
  });

  async function load(): Promise<void> {
    error = '';
    message = '';
    type = null;
    items = [];
    itemsError = '';
    itemsPage = 1;
    itemsOpen = false;

    if (isNew) {
      title = '';
      description = '';
      enabled = true;
      loading = false;
      return;
    }

    loading = true;

    try {
      type = (await getAdminItemType(id)).type;
      title = type.title;
      description = type.description ?? '';
      enabled = type.enabled ?? true;

      try {
        items = (await listAdminItemTypeItems(id)).items;
      } catch (itemsCause) {
        itemsError =
          itemsCause instanceof ApiError ? itemsCause.message : 'Не удалось загрузить состав';
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить тип номенклатуры';
    } finally {
      loading = false;
    }
  }

  function openItems(): void {
    itemsPage = 1;
    itemsOpen = true;
  }

  async function save(): Promise<void> {
    const trimmedTitle = title.trim();

    error = '';
    message = '';

    if (trimmedTitle === '') {
      error = 'Укажите название типа';
      return;
    }

    saving = true;

    try {
      if (isNew) {
        await createAdminItemType({
          title: trimmedTitle,
          description: description.trim(),
          enabled
        });
        router.navigate('/stocks/types');
        return;
      }

      const result = await updateAdminItemType(id, {
        title: trimmedTitle,
        description: description.trim(),
        enabled
      });
      type = result.type;
      title = type.title;
      description = type.description ?? '';
      enabled = type.enabled ?? true;
      message = 'Изменения сохранены';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить тип';
    } finally {
      saving = false;
    }
  }

  async function removeType(): Promise<void> {
    if (isNew || type === null || type.positions_count > 0) {
      return;
    }

    if (!confirm(`Удалить тип «${type.title}»?`)) {
      return;
    }

    deleting = true;
    error = '';
    message = '';

    try {
      await deleteAdminItemType(id);
      router.navigate('/stocks/types');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить тип';
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
        title="Вернуться к типам"
        aria-label="Вернуться к типам"
        onclick={() => router.navigate('/stocks/types')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
      <div class="head-text">
        <h1>{isNew ? 'Новый тип номенклатуры' : 'Редактировать тип номенклатуры'}</h1>
        <p class="sub">
          {isNew
            ? 'Задайте название и описание — код создастся автоматически.'
            : 'Измените название, описание или доступность типа в карточке номенклатуры.'}
        </p>
      </div>
      {#if !isNew}
        <div class="head-actions">
          <button
            type="button"
            class="icon-btn danger"
            title={deleteTitle}
            aria-label="Удалить тип"
            disabled={saving || deleting || (type?.positions_count ?? 0) > 0 || type?.is_last === true}
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
    <div class="empty">Тип не найден.</div>
  {:else}
    <div class="layout" class:single={isNew || type === null}>
      <div class="card">
        <div class="form">
          <Input
            label="Название"
            bind:value={title}
            placeholder="Например, расходный материал"
            maxlength={255}
            disabled={saving}
          />

          <label class="field">
            <span class="label">Описание</span>
            <textarea
              bind:value={description}
              rows="4"
              maxlength="1000"
              placeholder="Коротко опишите, что относится к этому типу"
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
                {#if enabledLocked}
                  В типе есть {type?.positions_count} поз. номенклатуры — выключить нельзя.
                {:else}
                  Тип появится в карточке номенклатуры. Последний доступный тип нельзя выключить.
                {/if}
              </span>
            </span>
            <span
              class="switch"
              title={enabledLocked
                ? `В типе есть ${type?.positions_count} поз. номенклатуры — выключить нельзя`
                : 'Доступен для выбора'}
            >
              <input
                type="checkbox"
                bind:checked={enabled}
                disabled={saving || enabledLocked}
              />
              <span class="switch-track" aria-hidden="true"></span>
            </span>
          </label>

          <div class="actions">
            <Button variant="ghost" disabled={saving} onclick={() => router.navigate('/stocks/types')}>
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
          <div class="card-head">
            <h2>Состав типа</h2>
            {#if items.length > 0}
              <button type="button" class="link-btn" onclick={openItems}>
                Все позиции типа →
              </button>
            {/if}
          </div>

          {#if itemsError}
            <p class="form-error">{itemsError}</p>
          {:else if items.length === 0}
            <p class="hint">В этом типе пока нет номенклатуры.</p>
          {:else}
            <ul class="composition-list">
              {#each previewItems as item (item.sid)}
                <li class:inactive={!item.active}>
                  <span class="composition-name">
                    {item.name}
                    {#if !item.active}<span class="tag">деактивирована</span>{/if}
                  </span>
                  <span class="composition-meta">
                    {item.article || 'без артикула'}
                    {#if item.unit}· {item.unit}{/if}
                  </span>
                </li>
              {/each}
            </ul>
          {/if}
        </div>
      {/if}
    </div>
  {/if}
</section>

<Modal open={itemsOpen} title="Все позиции типа" wide onclose={() => (itemsOpen = false)}>
  {#if items.length === 0}
    <p class="hint">В этом типе пока нет номенклатуры.</p>
  {:else}
    <p class="modal-count">{items.length} поз.</p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Название</th>
            <th>Артикул</th>
            <th>Ед.</th>
          </tr>
        </thead>
        <tbody>
          {#each pagedItems as item (item.sid)}
            <tr>
              <td class="item-name">
                {item.name}
                {#if !item.active}<span class="tag">деактивирована</span>{/if}
              </td>
              <td>{item.article || '—'}</td>
              <td>{item.unit || '—'}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>

    <div class="modal-pager">
      <Pager
        page={itemsPage}
        perPage={20}
        total={items.length}
        onchange={(next) => (itemsPage = next)}
      />
    </div>
  {/if}

  <div class="modal-actions">
    <Button variant="ghost" onclick={() => (itemsOpen = false)}>Закрыть</Button>
  </div>
</Modal>

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
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }

  .composition-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0;
    padding: 0 var(--space-2) 0 0;
    max-height: min(360px, 50vh);
    overflow-y: auto;
  }

  .composition-list li {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-left: var(--space-3);
    border-left: 1px solid var(--border-secondary);
  }

  .composition-list li::before {
    content: '';
    position: absolute;
    top: 5px;
    left: -4px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--border);
  }

  .composition-list li.inactive::before {
    background: var(--danger);
  }

  .composition-name {
    font-size: 14px;
    font-weight: 500;
    overflow-wrap: anywhere;
  }

  .composition-meta {
    color: var(--muted);
    font-size: 12px;
    overflow-wrap: anywhere;
  }

  .link-btn {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
  }

  .link-btn:hover {
    text-decoration: underline;
  }

  .form-error {
    margin: 0;
    color: var(--danger);
    font-size: 13px;
  }

  .tag {
    margin-left: var(--space-2);
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--fill-tertiary);
    color: var(--text-description);
    font-size: 12px;
    font-weight: 400;
    line-height: 20px;
  }

  .modal-count {
    margin: 0 0 var(--space-3);
    color: var(--text-description);
    font-size: 13px;
  }

  .modal-pager {
    margin-top: auto;
    padding-top: var(--space-3);
  }

  .table-wrap {
    overflow-x: auto;
  }

  table {
    width: 100%;
    min-width: 520px;
    border-collapse: collapse;
  }

  th {
    padding: 8px 12px;
    border-bottom: 2px solid var(--border);
    color: var(--text-description);
    font-size: 12px;
    font-weight: 500;
    text-align: left;
    white-space: nowrap;
  }

  td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    vertical-align: middle;
  }

  tbody tr:last-child td {
    border-bottom: none;
  }

  .item-name {
    font-weight: 500;
    overflow-wrap: anywhere;
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

  .availability-hint,
  .hint {
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

  .switch-track::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    transition: transform 0.15s ease;
  }

  .switch input:checked + .switch-track {
    background: var(--primary);
  }

  .switch input:checked + .switch-track::after {
    transform: translateX(18px);
  }

  .switch input:focus-visible + .switch-track {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  .switch input:disabled {
    cursor: not-allowed;
  }

  .switch input:disabled + .switch-track {
    opacity: 0.55;
  }

  .hint {
    margin: 0;
  }

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    padding-top: var(--space-2);
  }

  @media (max-width: 1000px) {
    .page {
      max-width: 720px;
    }

    .layout {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  @media (max-width: 640px) {
    .page {
      gap: var(--space-3);
    }

    .head-actions {
      width: 100%;
      margin-left: 0;
    }

    .actions {
      flex-direction: column;
    }
  }
</style>
