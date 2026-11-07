<script lang="ts">
  let open = $state(false);
  let message = $state('Идут технические работы. Попробуйте через несколько минут.');

  $effect(() => {
    const handler = (event: Event) => {
      const detail = (event as CustomEvent<string>).detail;
      message = detail || 'Идут технические работы. Попробуйте через несколько минут.';
      open = true;
    };

    window.addEventListener('crm:maintenance', handler);

    return () => window.removeEventListener('crm:maintenance', handler);
  });
</script>

{#if open}
  <div class="maintenance" role="alert" aria-live="assertive">
    <div class="box">
      <div class="badge">CRM7</div>
      <h1>Идут технические работы</h1>
      <p>{message}</p>
      <button type="button" onclick={() => window.location.reload()}>Проверить снова</button>
    </div>
  </div>
{/if}

<style>
  .maintenance {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: grid;
    place-items: center;
    padding: 24px;
    background: var(--surface, #f7f9fc);
  }

  .box {
    width: min(440px, 100%);
    text-align: center;
  }

  .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    background: var(--primary, #2563eb);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.08em;
  }

  h1 {
    margin: 18px 0 8px;
    font-size: 26px;
    line-height: 1.2;
  }

  p {
    margin: 0 0 20px;
    color: var(--muted, #64748b);
    line-height: 1.55;
  }

  button {
    height: 38px;
    padding: 0 18px;
    border: 1px solid var(--border, #d7dee8);
    border-radius: var(--radius-sm, 8px);
    background: var(--surface, #fff);
    color: var(--text, #0f172a);
    font: inherit;
    font-weight: 600;
    cursor: pointer;
  }

  button:hover {
    border-color: var(--primary, #2563eb);
    color: var(--primary, #2563eb);
  }
</style>
