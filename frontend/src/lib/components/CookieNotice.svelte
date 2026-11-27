<script lang="ts">
  import { router } from '../router.svelte';

  const STORAGE_KEY = 'crm.cookie.accepted';

  let visible = $state(false);
  let expanded = $state(false);
  let bannerEl = $state<HTMLDivElement | null>(null);

  $effect(() => {
    try {
      visible = localStorage.getItem(STORAGE_KEY) !== '1';
    } catch {
      visible = true;
    }
  });

  // Резервируем фактическую высоту баннера в layout, чтобы прокрутка
  // могла поднять любой элемент выше него на всех размерах экрана.
  $effect(() => {
    const element = bannerEl;

    if (!visible || element === null) {
      document.documentElement.style.setProperty('--cookie-space', '0px');

      return;
    }

    const update = (): void => {
      document.documentElement.style.setProperty(
        '--cookie-space',
        `${Math.ceil(element.getBoundingClientRect().height)}px`
      );
    };

    update();

    const observer = new ResizeObserver(update);

    observer.observe(element);
    window.addEventListener('resize', update);

    return () => {
      observer.disconnect();
      window.removeEventListener('resize', update);
      document.documentElement.style.setProperty('--cookie-space', '0px');
    };
  });

  $effect(() => {
    document.documentElement.classList.toggle('cookie-visible', visible);

    return () => document.documentElement.classList.remove('cookie-visible');
  });

  function accept(): void {
    try {
      localStorage.setItem(STORAGE_KEY, '1');
    } catch {
      // приватный режим — просто скрываем
    }

    visible = false;
  }
</script>

{#if visible}
  <div class="cookie" role="dialog" aria-label="Использование cookie" bind:this={bannerEl}>
    <div class="notice">
      <p class="full">
        Мы используем cookie, чтобы сохранять сессию и настройки. Продолжая работу, вы соглашаетесь
        с <button type="button" class="link" onclick={() => router.navigate('/legal/privacy')}>политикой обработки персональных данных</button>
        и <button type="button" class="link" onclick={() => router.navigate('/legal/terms')}>пользовательским соглашением</button>.
      </p>
      <div class="compact">
        <span>Используем cookie для сессии и настроек</span>
        <button
          type="button"
          class="details-toggle"
          aria-expanded={expanded}
          onclick={() => (expanded = !expanded)}
        >
          {expanded ? 'Свернуть' : 'Подробнее'}
        </button>
      </div>
      {#if expanded}
        <p class="details">
          Продолжая работу, вы соглашаетесь
          с <button type="button" class="link" onclick={() => router.navigate('/legal/privacy')}>политикой обработки персональных данных</button>
          и <button type="button" class="link" onclick={() => router.navigate('/legal/terms')}>пользовательским соглашением</button>.
        </p>
      {/if}
    </div>
    <button type="button" class="accept" onclick={accept}>Принять</button>
  </div>
{/if}

<style>
  .cookie {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: calc(var(--space-4) + env(safe-area-inset-bottom));
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    max-width: 760px;
    margin: 0 auto;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    font-size: 13px;
  }

  .notice {
    min-width: 0;
  }

  .cookie p {
    margin: 0;
  }

  .compact,
  .details {
    display: none;
  }

  .link {
    border: none;
    background: none;
    padding: 0;
    color: var(--primary);
    cursor: pointer;
    font: inherit;
    text-decoration: underline;
  }

  .accept {
    position: relative;
    flex: 0 0 auto;
    min-height: var(--control-height);
    padding: 0 var(--space-3);
    border: none;
    border-radius: var(--radius-sm);
    background: var(--primary);
    color: var(--on-primary);
    cursor: pointer;
    font: inherit;
    font-size: var(--font-sm);
  }

  @media (max-width: 720px) {
    /* Компактная строка занимает меньше места, чем полный текст,
       поэтому форма остаётся доступной даже до принятия cookie. */
    .cookie {
      align-items: center;
      gap: var(--space-3);
      padding: var(--space-2) var(--space-3);
      bottom: calc(84px + env(safe-area-inset-bottom));
    }

    .full {
      display: none;
    }

    .compact {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;
      font-size: 12px;
    }

    .details {
      display: block;
      margin-top: 4px;
      font-size: 12px;
    }

    .details-toggle {
      border: none;
      background: none;
      padding: 0;
      color: var(--primary);
      cursor: pointer;
      font: inherit;
      text-decoration: underline;
    }

    .accept {
      min-height: 36px;
      padding: 0 var(--space-2);
    }

    .accept::after {
      content: '';
      position: absolute;
      inset: -4px;
    }
  }
</style>
