<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import {
    createItemGroup,
    deleteItemGroup,
    listAdminItemGroups,
    listGroupItems,
    updateItemGroup
  } from '../lib/api/groups';
  import type { ItemGroup, ItemGroupItem } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const isNew = $derived(id <= 0);
  const groupsEnabled = $derived(appSettings.groupsEnabled);
  const TYPE_TITLES: Record<string, string> = { product: 'Товар', service: 'Услуга', set: 'Набор' };

  let group = $state<ItemGroup | null>(null);
  let items = $state<ItemGroupItem[]>([]);
  let title = $state('');
  let description = $state('');
  let enabled = $state(true);
  let loading = $state(true);
  let saving = $state(false);
  let deleting = $state(false);
  let error = $state('');
  let itemsError = $state('');
  let message = $state('');
  let itemsOpen = $state(false);
  let itemsPage = $state(1);

  const previewItems = $derived(items.slice(0, 20));
  const pagedItems = $derived(items.slice((itemsPage - 1) * 20, itemsPage * 20));
  const deleteTitle = $derived(
    items.length > 0
      ? `В группе ${items.length} поз. номенклатуры — удаление недоступно`
      : 'Удалить группу'
  );

  function typeTitle(code: string): string {
    return TYPE_TITLES[code] ?? code;
  }

  $effect(() => {
    void id;
    void load();
  });

  async function load(): Promise<void> {
    error = '';
    itemsError = '';
    items = [];
    itemsPage = 1;
    itemsOpen = false;

    if (isNew) {
      enabled = true;
      loading = false;
      return;
    }

    loading = true;

    try {
      const found = (await listAdminItemGroups()).items.find((item) => item.id === id) ?? null;

      if (found === null) {
        error = 'Группа не найдена';
        return;
      }

      group = found;
      title = found.title;
      description = found.description ?? '';
      enabled = found.enabled ?? true;

      try {
        items = (await listGroupItems(id)).items;
      } catch (cause) {
        itemsError =
          cause instanceof ApiError ? cause.message : 'Не удалось загрузить состав группы';
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить группу';
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
      error = 'Укажите название группы';
      return;
    }

    saving = true;

    try {
      if (isNew) {
        await createItemGroup(trimmedTitle, description.trim(), enabled);
        router.navigate('/stocks/groups');
        return;
      }

      await updateItemGroup(id, trimmedTitle, description.trim(), enabled);
      await load();
      message = 'Изменения сохранены';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить группу';
    } finally {
      saving = false;
    }
  }

  async function removeGroup(): Promise<void> {
    if (isNew || group === null || items.length > 0) {
      return;
    }

    if (!confirm(`Удалить группу «${group.title}»?`)) {
      return;
    }

    deleting = true;
    error = '';
    message = '';

    try {
      await deleteItemGroup(id);
      router.navigate('/stocks/groups');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить группу';
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
        title="Назад к группам"
        aria-label="Назад к группам"
        onclick={() => router.navigate('/stocks/groups')}
      >
        <Icon name="arrow-left" size={16} />
      </button>
      <div class="head-text">
        <h1>{isNew ? 'Новая группа номенклатуры' : 'Редактирование группы номенклатуры'}</h1>
        <p class="sub">
          {isNew
            ? 'Заполните название и описание группы.'
            : 'Изменения названия и описания сохраняются отдельно.'}
        </p>
      </div>
      {#if !isNew}
        <div class="head-actions">
          <button
            type="button"
            class="icon-btn danger"
            title={deleteTitle}
            aria-label="Удалить группу"
            disabled={saving || deleting || items.length > 0}
            onclick={() => void removeGroup()}
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

  {#if !groupsEnabled}
    <div class="notice">
      <span class="notice-icon"><Icon name="info" size={16} /></span>
      <span>Группы номенклатуры отключены — включите модуль в настройках.</span>
      <Button variant="ghost" onclick={() => router.navigate('/settings')}>
        Открыть настройки
      </Button>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if !isNew && group === null}
    <div class="empty">Группа не найдена.</div>
  {:else}
    <div class="layout" class:single={isNew || group === null}>
      <div class="card">
        <div class="form">
          <Input
            label="Название"
            bind:value={title}
            placeholder="Например: Смартфоны"
            disabled={saving}
          />

          <label class="field">
            <span class="label">Описание</span>
            <textarea
              bind:value={description}
              rows="4"
              maxlength="1000"
              placeholder="Коротко: что входит в группу"
              disabled={saving}
            ></textarea>
          </label>

          <label class="availability">
            <span class="availability-text">
              <span class="availability-title">Доступна для выбора</span>
              <span class="availability-hint">
                Группа появится в фильтрах и карточке номенклатуры.
              </span>
            </span>
            <span class="switch">
              <input type="checkbox" bind:checked={enabled} disabled={saving} />
              <span class="switch-track" aria-hidden="true"></span>
            </span>
          </label>

          <div class="actions">
            <Button
              variant="ghost"
              disabled={saving}
              onclick={() => router.navigate('/stocks/groups')}
            >
              Отмена
            </Button>
            <Button loading={saving} disabled={!title.trim()} onclick={() => void save()}>
              {isNew ? 'Создать группу' : 'Сохранить'}
            </Button>
          </div>
        </div>
      </div>

      {#if !isNew && group !== null}
        <div class="card">
          <div class="card-head">
            <h2>Состав группы</h2>
            {#if items.length > 0}
              <button type="button" class="link-btn" onclick={openItems}>
                Все позиции группы →
              </button>
            {/if}
          </div>

          {#if itemsError}
            <div class="alert" role="alert">
              <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
              <span>{itemsError}</span>
            </div>
          {:else if items.length === 0}
            <div class="empty">
              В группе пока нет номенклатуры. Назначьте группу в карточке номенклатуры.
            </div>
          {:else}
            <ul class="composition-list">
              {#each previewItems as item (item.sid)}
                <li class:inactive={!item.active}>
                  <span class="composition-name">
                    {item.name}
                    {#if !item.active}<span class="tag">деактивирована</span>{/if}
                  </span>
                  <span class="composition-meta">
                    {item.article || 'без артикула'} · {typeTitle(item.type)}
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

<Modal open={itemsOpen} title="Все позиции группы" wide onclose={() => (itemsOpen = false)}>
  {#if items.length === 0}
    <div class="empty">В группе пока нет номенклатуры.</div>
  {:else}
    <p class="modal-count">{items.length} поз.</p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Название</th>
            <th>Артикул</th>
            <th>Тип</th>
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
              <td>{typeTitle(item.type)}</td>
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
    margin: 0 auto;
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
    overflow-wrap: anywhere;
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
    color: var(--text);
  }

  .modal-count {
    margin: 0 0 var(--space-3);
    color: var(--text-description);
    font-size: 13px;
  }

  .composition-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin: 0 0 var(--space-3);
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

  .modal-pager {
    margin-top: var(--space-3);
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

  tbody tr:hover {
    background: var(--fill-tertiary);
  }

  .item-name {
    font-weight: 500;
    overflow-wrap: anywhere;
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

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    outline: none;
    resize: vertical;
  }

  textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  textarea:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
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

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
  }

  .empty {
    padding: var(--space-5);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
    color: var(--text-description);
    font-size: 14px;
    text-align: center;
  }

  .notice,
  .alert {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice-icon,
  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
  }

  .notice-icon {
    color: var(--success);
  }

  .alert-icon {
    color: var(--danger);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  @media (max-width: 1000px) {
    .page {
      max-width: 720px;
    }

    .layout {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
