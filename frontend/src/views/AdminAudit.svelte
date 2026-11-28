<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listAudit } from '../lib/api/admin';
  import type { AuditEntry } from '../lib/api/types';
  import { addHistory } from '../lib/search-history';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import { clampDates, todayIso } from '../lib/period';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import PageToolbar from '../lib/components/layout/PageToolbar.svelte';
  import ToolbarMenu from '../lib/components/layout/ToolbarMenu.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';
  import { auditLabel, entityLabel } from '../lib/labels';
  import { maxPage } from '../lib/views/pagination';
  import Button from '../lib/components/ui/Button.svelte';

  let filtersRef: FiltersModal | null = $state(null);

  const auditFilterDefaults = { from: '', to: '' };
  const initialAuditState = loadFilters('admin-audit', {
    from: '',
    to: '',
    action: '',
    page: 1,
    per_page: 20
  });

  let audit = $state<AuditEntry[]>([]);
  let auditTotal = $state(0);
  let auditPage = $state(Math.max(1, Math.trunc(Number(initialAuditState.page) || 1)));
  let auditPerPage = $state(
    [0, 10, 20, 50, 100].includes(Number(initialAuditState.per_page))
      ? Number(initialAuditState.per_page)
      : 20
  );
  let auditActionInput = $state(String(initialAuditState.action ?? ''));
  let auditAction = $state(String(initialAuditState.action ?? ''));
  let auditFilters = $state({
    from: String(initialAuditState.from ?? ''),
    to: String(initialAuditState.to ?? '')
  });
  let auditDraft = $state({
    from: String(initialAuditState.from ?? ''),
    to: String(initialAuditState.to ?? '')
  });
  let auditLoading = $state(true);
  let error = $state('');
  let loadSeq = 0;
  let searchAbort: AbortController | null = null;

  onMount(() => {
    void loadAudit();
  });

  function persistAudit(): void {
    saveFilters('admin-audit', {
      ...auditFilters,
      action: auditAction,
      page: auditPage,
      per_page: auditPerPage
    });
  }

  function abortSearch(): void {
    searchAbort?.abort();
    searchAbort = null;
  }

  const hasQuery = $derived(
    auditAction !== '' || countActive(auditFilters, auditFilterDefaults) > 0
  );

  function clearAll(): void {
    abortSearch();
    auditAction = '';
    auditActionInput = '';
    auditFilters = { ...auditFilterDefaults };
    auditPage = 1;
    persistAudit();
    void loadAudit();
  }

  function clampAuditDates(): void {
    const dates = clampDates(auditDraft.from, auditDraft.to);
    auditDraft.from = dates.from;
    auditDraft.to = dates.to;
  }

  async function applyAuditDraft(): Promise<string> {
    clampAuditDates();
    auditFilters = { ...auditDraft };
    auditPage = 1;
    persistAudit();
    const result = await loadAudit();
    return result.ok
      ? `Фильтры применены. Найдено: ${result.total}`
      : 'Не удалось применить фильтры. Попробуйте ещё раз.';
  }

  async function resetAuditFilters(): Promise<string> {
    auditDraft = { ...auditFilterDefaults };
    auditFilters = { ...auditFilterDefaults };
    clearFilters('admin-audit');
    auditPage = 1;
    persistAudit();
    const result = await loadAudit();
    return result.ok
      ? `Фильтры сброшены. Найдено: ${result.total}`
      : 'Не удалось сбросить фильтры. Попробуйте ещё раз.';
  }

  async function submitSearch(): Promise<void> {
    abortSearch();
    searchAbort = new AbortController();
    auditAction = auditActionInput.trim();
    auditPage = 1;
    addHistory('admin-audit', auditAction);
    persistAudit();
    await loadAudit(searchAbort.signal);
  }

  function clearSearch(): void {
    abortSearch();
    auditAction = '';
    auditActionInput = '';
    auditPage = 1;
    persistAudit();
    void loadAudit();
  }

  function goToPage(next: number): void {
    auditPage = next;
    persistAudit();
    void loadAudit();
  }

  function changePerPage(next: number): void {
    auditPerPage = next;
    auditPage = 1;
    persistAudit();
    void loadAudit();
  }

  async function loadAudit(signal?: AbortSignal): Promise<{ ok: boolean; total: number }> {
    const seq = ++loadSeq;
    auditLoading = true;
    error = '';

    try {
      const data = await listAudit({
        action: auditAction,
        from: auditFilters.from,
        to: auditFilters.to,
        page: auditPage,
        per_page: auditPerPage,
        signal
      });
      if (seq !== loadSeq) {
        return { ok: false, total: 0 };
      }
      const pagesCount = maxPage(data.total, auditPerPage);
      if (auditPage > pagesCount) {
        auditPage = pagesCount;
        persistAudit();
        return loadAudit(signal);
      }
      audit = data.items;
      auditTotal = data.total;
      return { ok: true, total: data.total };
    } catch (cause) {
      if (seq !== loadSeq) {
        return { ok: false, total: 0 };
      }
      if (cause instanceof DOMException && cause.name === 'AbortError') {
        return { ok: false, total: 0 };
      }
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить журнал';
      return { ok: false, total: 0 };
    } finally {
      if (seq === loadSeq) {
        auditLoading = false;
      }
    }
  }
</script>

<section class="page">
  <PageToolbar title="Журнал" actionsLabel="Действия журнала">
    {#snippet actions()}
      <ToolbarMenu
        label="Действия журнала"
        search={{
          value: auditActionInput,
          historyKey: 'admin-audit',
          title: 'Поиск по журналу',
          placeholder: 'Действие (например, request.transition)',
          suggestions: audit.map((entry) => entry.action),
          onsearch: async (query) => {
            auditActionInput = query;
            await submitSearch();
          },
          onclear: clearSearch,
          onstop: abortSearch
        }}
        items={[
          {
            id: 'filters',
            label: 'Фильтры',
            icon: 'filter',
            dialog: true,
            onclick: () => filtersRef?.show()
          }
        ]}
      />
    {/snippet}
  </PageToolbar>

  <FiltersModal
    bind:this={filtersRef}
    trigger={false}
    count={countActive(auditFilters, auditFilterDefaults)}
    onopen={() => (auditDraft = { ...auditFilters })}
    onapply={applyAuditDraft}
    onreset={resetAuditFilters}
  >
    <label class="filter-field">
      <span>Период с</span>
      <input type="date" bind:value={auditDraft.from} max={auditDraft.to || todayIso()} />
    </label>
    <label class="filter-field">
      <span>Период по</span>
      <input type="date" bind:value={auditDraft.to} min={auditDraft.from} max={todayIso()} />
    </label>
  </FiltersModal>

  {#if error}
    <div class="alert" role="alert">{error}</div>
  {/if}

  {#if auditLoading}
    <div class="center"><Spinner size={26} /></div>
  {:else if audit.length === 0}
    <div class="empty">
      <p>{hasQuery ? 'Ничего не найдено' : 'Записей нет'}</p>
      {#if hasQuery}
        <Button variant="ghost" onclick={clearAll}>Сбросить поиск и фильтры</Button>
      {/if}
    </div>
  {:else}
    <div class="audit" role="list" aria-label="Записи журнала">
      {#each audit as entry (entry.id)}
        <div class="entry" role="listitem">
          <div class="row">
            <span class="action">{auditLabel(entry.action)}</span>
            <span class="when">{formatDateTime(entry.created_at)}</span>
          </div>
          <div class="meta">
            {#if entry.user}
              <span><span class="sr-only">Пользователь: </span>{entry.user.name}</span>
            {/if}
            {#if entry.entity}
              <span><span class="sr-only">Объект: </span>{entityLabel(entry.entity)} #{entry.entity_id}</span>
            {/if}
            {#if entry.ip}
              <span><span class="sr-only">IP: </span>{entry.ip}</span>
            {/if}
            <span class="action-code"><span class="sr-only">Код действия: </span>{entry.action}</span>
          </div>
          {#if Object.keys(entry.data).length > 0}
            <div class="data">
              {#each Object.entries(entry.data) as [key, value]}
                <span>{key}: {String(value)}</span>
              {/each}
            </div>
          {/if}
        </div>
      {/each}
    </div>

    <Pager
      page={auditPage}
      perPage={auditPerPage}
      total={auditTotal}
      loading={auditLoading}
      onchange={goToPage}
      onperpage={changePerPage}
    />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }







  .audit {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .entry {
    padding: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    transition: background 0.12s ease;
  }

  .entry:hover {
    background: var(--bg);
  }

  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .entry .action {
    font-weight: 500;
    font-size: var(--font-md);
  }

  .entry .action-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: var(--font-xs);
  }

  .entry .when {
    font-size: var(--font-xs);
    color: var(--muted);
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: 4px;
    font-size: var(--font-xs);
    color: var(--muted);
  }

  .data {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: 4px;
    font-size: var(--font-xs);
    color: var(--muted);
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--muted);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }


</style>
