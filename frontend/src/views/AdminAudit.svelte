<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listAudit } from '../lib/api/admin';
  import type { AuditEntry } from '../lib/api/types';
  import { addHistory } from '../lib/search-history';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import { clampDates, todayIso } from '../lib/period';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import Pager from '../lib/components/ui/Pager.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';
  import { auditLabel, entityLabel } from '../lib/labels';

  const auditFilterDefaults = { from: '', to: '' };

  let audit = $state<AuditEntry[]>([]);
  let auditTotal = $state(0);
  let auditPage = $state(1);
  let auditPerPage = $state(20);
  let auditAction = $state('');
  const initialAuditFilters = loadFilters('admin-audit', auditFilterDefaults);
  let auditFilters = $state({ ...initialAuditFilters });
  let auditDraft = $state({ ...initialAuditFilters });
  let auditLoading = $state(true);
  let error = $state('');

  onMount(() => {
    void loadAudit();
  });

  function clampAuditDates(): void {
    const dates = clampDates(auditDraft.from, auditDraft.to);

    auditDraft.from = dates.from;
    auditDraft.to = dates.to;
  }

  function applyAuditDraft(): void {
    clampAuditDates();
    auditFilters = { ...auditDraft };
    saveFilters('admin-audit', auditFilters);
    auditPage = 1;
    void loadAudit();
  }

  function submitSearch(): void {
    auditPage = 1;
    addHistory('admin-audit', auditAction);
    void loadAudit();
  }

  function clearSearch(): void {
    auditAction = '';
    auditPage = 1;
    void loadAudit();
  }

  function resetAuditFilters(): void {
    auditDraft = { ...auditFilterDefaults };
    auditFilters = { ...auditFilterDefaults };
    clearFilters('admin-audit');
    auditPage = 1;
    void loadAudit();
  }

  async function loadAudit(): Promise<void> {
    auditLoading = true;
    error = '';

    try {
      const data = await listAudit({
        action: auditAction,
        from: auditFilters.from,
        to: auditFilters.to,
        page: auditPage,
        per_page: auditPerPage
      });

      audit = data.items;
      auditTotal = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить журнал';
    } finally {
      auditLoading = false;
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="filters">
      <div class="head">
        <h1>Журнал</h1>
      </div>

      <form
        class="search"
        onsubmit={(event) => {
          event.preventDefault();
          submitSearch();
        }}
      >
        <SearchInput
          bind:value={auditAction}
          historyKey="admin-audit"
          placeholder="Действие (например, request.transition)"
          onclear={clearSearch}
          onpick={submitSearch}
        />
      </form>

      <div class="filter-icons">
        <FiltersModal
          icon
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
      </div>
    </div>
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if auditLoading}
    <div class="center"><Spinner size={26} /></div>
  {:else if audit.length === 0}
    <div class="empty">Записей нет</div>
  {:else}
    <div class="audit">
      {#each audit as entry (entry.id)}
        <div class="entry">
          <div class="row">
            <span class="action">{auditLabel(entry.action)}</span>
            <span class="when">{formatDateTime(entry.created_at)}</span>
          </div>
          <div class="meta">
            {#if entry.user}<span>{entry.user.name}</span>{/if}
            {#if entry.entity}<span>{entityLabel(entry.entity)} #{entry.entity_id}</span>{/if}
            {#if entry.ip}<span>{entry.ip}</span>{/if}
            <span class="action-code">{entry.action}</span>
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
      onchange={(next) => {
        auditPage = next;
        void loadAudit();
      }}
      onperpage={(next) => {
        auditPerPage = next;
        auditPage = 1;
        void loadAudit();
      }}
    />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
    align-items: center;
  }

  .search {
    display: flex;
    flex: 1 1 260px;
    min-width: 0;
    max-width: 420px;
  }

  .filter-icons {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
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
    font-size: 14px;
  }

  .entry .action-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    opacity: 0.75;
  }

  .entry .when {
    font-size: 12px;
    color: var(--muted);
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: 4px;
    font-size: 12px;
    color: var(--muted);
  }

  .data {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: 4px;
    font-size: 12px;
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

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
      max-width: none;
    }

    .filter-icons {
      margin-left: 0;
    }
  }
</style>
