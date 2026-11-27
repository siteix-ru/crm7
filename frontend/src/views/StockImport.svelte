<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    importHistory,
    importLevels,
    type ImportPriceType,
    type StockImportResponse
  } from '../lib/api/stocks';
  import type { StockImportJob, StockUpdate } from '../lib/api/types';
  import { formatDateTime } from '../lib/format';
  import { router } from '../lib/router.svelte';
  import { tablist } from '../lib/a11y/tablist';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';

  function todayLocal(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60_000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
  }

  const tabFromUrl = router.current.query.get('tab');
  let tab = $state<'import' | 'history'>(tabFromUrl === 'history' ? 'history' : 'import');

  function selectTab(next: 'import' | 'history', keepResult = false): void {
    tab = next;
    // Старый отчёт не должен «зависать» на форме после возврата к ней.
    if (!keepResult || next === 'import') {
      result = '';
    }
    // Закладка фиксируется в URL без дублирования записей истории.
    router.navigate(`/stocks/import?tab=${next}`, true);
    if (next === 'history') {
      void loadHistory();
    }
  }

  // После импорта кнопка, находившаяся в фокусе, удаляется из DOM — переводим фокус на таб,
  // иначе он падает в <body> и скринридер теряет контекст.
  async function focusTab(next: 'import' | 'history'): Promise<void> {
    await tick();
    document.getElementById(next === 'history' ? 'import-tab-history' : 'import-tab-upload')?.focus();
  }

  let file = $state<File | null>(null);
  let actualDate = $state(todayLocal());
  let busy = $state(false);
  let confirm = $state(false);
  let error = $state('');
  let result = $state('');
  let pendingMapping = $state<Record<string, number> | null>(null);
  let mappingColumns = $state<string[]>([]);
  let mappingTypes = $state<ImportPriceType[]>([]);
  let mapping = $state<Record<string, number>>({});
  let jobs = $state<StockImportJob[]>([]);
  let updates = $state<StockUpdate[]>([]);
  let historyLoading = $state(true);

  async function loadHistory(): Promise<void> {
    historyLoading = true;
    try {
      const data = await importHistory();
      jobs = data.jobs;
      updates = data.updates;
    } catch {
      // история не critical — форма продолжает работать
    } finally {
      historyLoading = false;
    }
  }

  function applyResponse(data: StockImportResponse): void {
    if (data.status === 'needs_mapping') {
      mappingColumns = data.columns;
      mappingTypes = data.types;
      mapping = Object.fromEntries(data.columns.map((column) => [column, 0]));
      return;
    }

    result =
      `Импортировано ${data.rows_imported} из ${data.rows_total} строк` +
      (data.rows_created > 0 ? `, новых строк остатков: ${data.rows_created}` : '') +
      (data.warehouses_created > 0 ? `, новых складов: ${data.warehouses_created}` : '') +
      (data.rows_zeroed > 0 ? `, обнулено остатков: ${data.rows_zeroed}` : '') +
      (data.rows_skipped > 0 ? `, пропущено строк: ${data.rows_skipped}` : '') +
      (data.prices_imported > 0 ? `, загружено цен: ${data.prices_imported}` : '');

    if (data.errors.length > 0) {
      result += `. ${data.errors.slice(0, 3).join('; ')}`;
    }

    file = null;
    confirm = false;
    pendingMapping = null;
    mappingColumns = [];
    mapping = {};
    // Отчёт показываем на истории — иначе пользователь его не увидит.
    selectTab('history', true);
    void focusTab('history');
  }

  async function runImport(currentMapping?: Record<string, number>): Promise<void> {
    if (file === null) {
      error = 'Выберите файл (xls, xlsx или csv)';
      return;
    }

    busy = true;
    error = '';
    result = '';
    confirm = false;
    pendingMapping = null;

    try {
      applyResponse(await importLevels(file, actualDate, currentMapping));
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось импортировать файл';
    } finally {
      busy = false;
    }
  }

  let dragging = $state(false);

  function setFile(next: File | null): void {
    file = next;
    confirm = false;
    pendingMapping = null;
    error = '';
    result = '';
  }

  function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    setFile(input.files?.[0] ?? null);
  }

  function onDrop(event: DragEvent): void {
    event.preventDefault();
    dragging = false;
    setFile(event.dataTransfer?.files?.[0] ?? null);
  }

  onMount(() => {
    if (tab === 'history') {
      void loadHistory();
    }
  });
</script>

{#snippet confirmBlock()}
  <div class="confirm" role="alert">
    <div class="confirm-icon"><Icon name="info" size={20} /></div>
    <div>
      <strong>Заменить остатки целиком?</strong>
      <p>
        Остатки на дату {actualDate} из файла «{file?.name}» заменят текущие <b>на всех складах</b>.
        Позиции, которых нет в файле, будут обнулены. Откат из интерфейса недоступен.
      </p>
      <div class="actions">
        <Button variant="ghost" onclick={() => (confirm = false)}>Отмена</Button>
        <Button loading={busy} onclick={() => void runImport(pendingMapping ?? undefined)}>
          Да, заменить
        </Button>
      </div>
    </div>
  </div>
{/snippet}

<section class="page page-wide">
  <PageToolbar title="Импорт остатков из 1С">
    {#snippet tabs()}
      <div class="import-tabs" role="tablist" use:tablist aria-label="Разделы импорта">
        <button
          type="button"
          id="import-tab-upload"
          role="tab"
          class="import-tab"
          class:active={tab === 'import'}
          aria-selected={tab === 'import'}
          aria-controls="import-panel-upload"
          tabindex={tab === 'import' ? 0 : -1}
          onclick={() => selectTab('import')}
        >
          Загрузка
        </button>
        <button
          type="button"
          id="import-tab-history"
          role="tab"
          class="import-tab"
          class:active={tab === 'history'}
          aria-selected={tab === 'history'}
          aria-controls="import-panel-history"
          tabindex={tab === 'history' ? 0 : -1}
          onclick={() => selectTab('history')}
        >
          История
        </button>
      </div>
    {/snippet}
  </PageToolbar>

  <div class="import-layout">
    {#if tab === 'import'}
    <div
      class="card form-card"
      id="import-panel-upload"
      role="tabpanel"
      aria-labelledby="import-tab-upload"
      tabindex="0"
    >
      {#if mappingColumns.length > 0}
        <div class="step-line" aria-hidden="true">
          <span class="step done">1 · Файл</span>
          <span class="step-line-fill"></span>
          <span class="step active">2 · Типы цен</span>
        </div>

        <div class="notice">
          <Icon name="info" size={18} />
          <div>
            <strong>Нужно сопоставить типы цен</strong>
            <p>
              В файле есть типы цен, которых нет в CRM7. Укажите соответствие — выбор запомнится и
              применится в следующий раз автоматически.
            </p>
          </div>
        </div>

        <div class="fields">
          {#each mappingColumns as column (column)}
            <div class="field mapping-field">
              <label for={`map-${column}`}>{column}</label>
              <select id={`map-${column}`} bind:value={mapping[column]}>
                <option value={0}>Не импортировать</option>
                {#each mappingTypes as type (type.id)}
                  <option value={type.id}>{type.title}</option>
                {/each}
              </select>
            </div>
          {/each}
        </div>
        {#if error}<p class="error" role="alert">{error}</p>{/if}
        <div class="actions">
          <Button variant="ghost" onclick={() => (mappingColumns = [])}>Назад</Button>
          {#if confirm}
            {@render confirmBlock()}
          {:else}
            <Button
              loading={busy}
              onclick={() => {
                pendingMapping = mapping;
                confirm = true;
                result = '';
              }}
            >
              Загрузить файл
            </Button>
          {/if}
        </div>
      {:else}
        <div class="step-line" aria-hidden="true">
          <span class="step active">1 · Файл</span>
          <span class="step-line-fill"></span>
          <span class="step">2 · Типы цен</span>
        </div>

        <div class="notice">
          <Icon name="info" size={18} />
          <div>
            <strong>Что делает импорт</strong>
            <p>
              Загружает остатки из Excel (xls/xlsx) или CSV: «Склад …» и строки «товар; ед.; остаток».
              Импорт заменяет остатки целиком — позиции, которых нет в файле, обнуляются. Новые склады
              добавляются автоматически.
            </p>
          </div>
        </div>

        <div
          class="dropzone"
          class:dragging
          class:filled={file !== null}
          role="group"
          aria-label="Файл импорта"
          ondragover={(event) => {
            event.preventDefault();
            dragging = true;
          }}
          ondragleave={() => (dragging = false)}
          ondrop={onDrop}
        >
          <input
            id="import-file"
            class="file-input"
            type="file"
            accept=".xls,.xlsx,.csv,.txt"
            onchange={onFileChange}
          />
          <label for="import-file">
            <span class="drop-icon"><Icon name="import" size={22} /></span>
            <span class="drop-text">
              <strong>{file ? file.name : 'Выберите файл или перетащите сюда'}</strong>
              <small>
                {file
                  ? `${(file.size / 1024).toFixed(1)} КБ · нажмите, чтобы заменить`
                  : 'XLS, XLSX, CSV или TXT'}
              </small>
            </span>
          </label>
        </div>

        <div class="field date-field">
          <label for="import-date">Дата остатков</label>
          <input id="import-date" type="date" bind:value={actualDate} />
          <span class="field-hint">Остатки будут относиться к этой дате</span>
        </div>

        {#if error}<p class="error" role="alert">{error}</p>{/if}

        {#if confirm}
          {@render confirmBlock()}
        {:else}
          <div class="actions">
            <Button
              loading={busy}
              disabled={file === null}
              onclick={() => {
                pendingMapping = null;
                confirm = true;
                result = '';
              }}
            >
              Загрузить файл
            </Button>
          </div>
        {/if}
      {/if}
    </div>
    {:else}
    <div
      class="card history-card"
      id="import-panel-history"
      role="tabpanel"
      aria-labelledby="import-tab-history"
      tabindex="0"
    >
      <div class="card-head">
        <Button variant="ghost" onclick={() => void loadHistory()}>Обновить</Button>
      </div>

      {#if result}<p class="success" role="status">{result}</p>{/if}

      {#if historyLoading}
        <p class="empty">Загрузка истории…</p>
      {:else if jobs.length === 0 && updates.length === 0}
        <p class="empty">Импортов ещё не было</p>
      {:else}
        <div class="history">
          {#each jobs as job (job.id)}
            <article class="job">
              <div class="job-row">
                <span class="file">{job.file_name}</span>
                <span class="status" class:failed={job.status === 'failed'}>
                  {job.status === 'done' ? 'загружен' : job.status === 'failed' ? 'ошибка' : 'в работе'}
                </span>
              </div>
              <div class="meta">
                <span>{formatDateTime(job.created_at)}</span>
                <span>{job.user}</span>
                <span>строк: {job.rows_imported} из {job.rows_total}</span>
                {#if job.rows_skipped > 0}<span class="warn">пропущено: {job.rows_skipped}</span>{/if}
                <span>на дату {job.actual_date}</span>
              </div>
              {#if job.errors}<p class="errors">{job.errors}</p>{/if}
            </article>
          {/each}
          {#each updates as update (`legacy-${update.id}`)}
            <article class="job">
              <div class="job-row">
                <span class="file">{update.file || 'обновление остатков'}</span>
                <span class="status">{update.status || 'загружено'}</span>
              </div>
              <div class="meta">
                <span>{formatDateTime(update.created_at)}</span>
                <span>строк: {update.rows_count}</span>
                {#if update.actual_date}<span>на дату {update.actual_date}</span>{/if}
              </div>
            </article>
          {/each}
        </div>
      {/if}
    </div>
    {/if}
  </div>
</section>

<style>
  /* Ритм страницы как в REQ-LIST: main уже даёт внешний padding. */
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .import-tabs {
    display: inline-flex;
    gap: var(--space-2);
    padding: 3px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
  }

  .import-tab {
    display: inline-flex;
    align-items: center;
    min-height: var(--control-height);
    padding: 0 var(--space-3);
    border: 1px solid transparent;
    border-radius: var(--radius-xs);
    background: transparent;
    color: var(--muted);
    font: inherit;
    font-size: var(--font-sm);
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
  }

  .import-tab:hover {
    background: var(--fill-tertiary);
    color: var(--text);
  }

  /* Активный сегмент — «поднятая» поверхность, а не ещё одна синяя заливка. */
  .import-tab.active {
    border-color: var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    color: var(--primary);
  }

  @media (prefers-reduced-motion: reduce) {
    .import-tab {
      transition: none;
    }
  }

  .import-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
  }

  .card {
    min-width: 0;
    padding: var(--space-3) var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
  }

  .notice {
    display: flex;
    gap: var(--space-2);
    margin-bottom: var(--space-4);
    padding: var(--space-3);
    border-radius: var(--radius-sm);
    background: var(--primary-bg);
    color: var(--text-description);
    font-size: var(--font-sm);
    line-height: 1.5;
  }

  .notice :global(svg) {
    flex: 0 0 auto;
    color: var(--primary);
  }

  .notice strong {
    color: var(--text);
  }

  .notice p {
    margin: var(--space-1) 0 0;
  }

  .step-line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-bottom: var(--space-4);
    color: var(--muted);
    font-size: var(--font-xs);
    font-weight: 600;
    letter-spacing: 0.03em;
  }

  .step.active {
    color: var(--primary);
  }

  .step.done {
    color: var(--success-text);
  }

  .step-line-fill {
    width: 32px;
    height: 1px;
    background: var(--border);
  }

  .fields {
    display: grid;
    gap: var(--space-3);
  }

  .field {
    display: grid;
    gap: var(--space-1);
  }

  label {
    color: var(--text-description);
    font-size: var(--font-sm);
    font-weight: 500;
  }

  input,
  select {
    width: 100%;
    min-height: var(--control-height);
    padding: 0 var(--space-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: var(--font-md);
  }

  .dropzone {
    position: relative;
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .dropzone:hover,
  .dropzone:focus-within,
  .dropzone.dragging {
    border-color: var(--primary);
    background: var(--primary-bg);
  }

  .dropzone.filled {
    border-style: solid;
  }

  .file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
  }

  .dropzone label {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-height: 92px;
    padding: var(--space-3) var(--space-4);
    cursor: pointer;
  }

  .drop-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    flex: 0 0 auto;
    border-radius: var(--radius-sm);
    background: var(--primary-bg);
    color: var(--primary);
  }

  .drop-text {
    display: grid;
    gap: 2px;
    min-width: 0;
  }

  .drop-text strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text);
    font-size: var(--font-md);
  }

  .drop-text small {
    color: var(--muted);
    font-size: var(--font-xs);
  }

  .date-field {
    margin-top: var(--space-4);
    max-width: 280px;
  }

  .field-hint {
    color: var(--muted);
    font-size: var(--font-xs);
  }

  .mapping-field {
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
  }

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .confirm {
    display: flex;
    gap: var(--space-3);
    margin-top: var(--space-4);
    padding: var(--space-3);
    border: 1px solid var(--warning);
    border-radius: var(--radius-sm);
    background: var(--warning-bg);
  }

  .confirm-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: var(--warning-bg);
    color: var(--warning-text);
  }

  .confirm strong {
    color: var(--text);
  }

  .confirm p {
    margin: var(--space-2) 0 0;
    font-size: var(--font-sm);
  }

  .confirm .actions {
    margin-top: var(--space-3);
  }

  .error,
  .success {
    margin: var(--space-3) 0 0;
    font-size: var(--font-sm);
  }

  .error {
    color: var(--error-text);
  }

  .success {
    color: var(--success-text);
  }

  .empty {
    color: var(--muted);
    font-size: var(--font-sm);
    text-align: center;
  }

  .history {
    display: grid;
    gap: var(--space-2);
  }

  .job {
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
  }

  .job-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .file {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 600;
    font-size: var(--font-sm);
  }

  .status {
    flex: 0 0 auto;
    padding: 2px var(--space-2);
    border-radius: 10px;
    background: var(--success-bg);
    color: var(--success-text);
    font-size: var(--font-xs);
  }

  .status.failed {
    background: var(--danger-bg);
    color: var(--error-text);
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-top: var(--space-2);
    color: var(--muted);
    font-size: var(--font-xs);
  }

  .warn {
    color: var(--warning-text);
  }

  .errors {
    margin: var(--space-2) 0 0;
    color: var(--error-text);
    font-size: var(--font-xs);
    overflow-wrap: anywhere;
  }

  @media (max-width: 900px) {
    .import-layout {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
