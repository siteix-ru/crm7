<script lang="ts">
  import { onMount } from 'svelte';
  import type { Snippet } from 'svelte';
  import { chatUnread } from '../../api/chat';
  import { unreadCount } from '../../api/notifications';
  import { config } from '../../config';
  import Icon from '../ui/Icon.svelte';
  import AppRail from './AppRail.svelte';
  import QuickActions from './QuickActions.svelte';
  import CommandPalette from './CommandPalette.svelte';
  import { commandPalette } from '../../stores/command-palette.svelte';
  import { recentPages } from '../../stores/recent-pages.svelte';
  import { navPrefs } from '../../stores/nav-prefs.svelte';
  import { theme } from '../../stores/theme.svelte';
  import { auth } from '../../stores/auth.svelte';
  import { appSettings, type NavNode } from '../../stores/app-settings.svelte';
  import { network } from '../../stores/network.svelte';
  import { swipeBack } from '../../a11y/swipe';
  import { cart } from '../../stores/cart.svelte';
  import { router } from '../../router.svelte';
  import { allowedRoutesFromNav } from '../../navigation-context';
  import { quickActions } from '../../quick-actions';

  interface Props {
    children?: Snippet;
  }

  let { children }: Props = $props();

  let unread = $state(0);
  let chatUnreadCount = $state(0);

  const path = $derived(router.current.path);
  const brandHint = $derived(appSettings.appDescription || appSettings.appTitle);

  /* Rail есть на desktop — там он раскрывается вручную; ниже 768 px его нет. */
  let railVisible = $state(true);
  const railExpanded = $derived(navPrefs.railExpanded);
  const canExpandRail = $derived(railVisible);
  const railWidth = $derived(railExpanded ? 240 : 56);
  const allowedRoutes = $derived(allowedRoutesFromNav(appSettings.visibleNav));
  const quickActionCount = $derived(
    quickActions({
      navIds: new Set(appSettings.visibleNav.map((node) => node.id)),
      capabilities: auth.capabilities,
      requestsEnabled: appSettings.requestsEnabled,
      salesEnabled: appSettings.salesEnabled,
      chatEnabled: appSettings.chatEnabled
    }).length
  );
  const navTitles = $derived(
    new Map(appSettings.visibleNav.map((node) => [node.route.replace(/^#/, ''), node.label]))
  );

  $effect(() => {
    const userId = auth.user?.id ?? null;
    if (!auth.isAuthenticated || userId === null) {
      return;
    }
    recentPages.setUser(userId, allowedRoutes, navTitles);
  });

  $effect(() => {
    const currentPath = path;
    if (!auth.isAuthenticated || !appSettings.loaded) {
      return;
    }
    recentPages.visit(currentPath, allowedRoutes, navTitles);
  });

  function channelCount(node: { badge?: { source?: string } }): number {
    if (node.badge?.source === 'chat') {
      return chatUnreadCount;
    }
    if (node.badge?.source === 'notifications') {
      return unread;
    }
    if (node.badge?.source === 'cart') {
      return cart.count;
    }

    return 0;
  }

  function toggleRail(): void {
    if (canExpandRail) {
      navPrefs.setRailExpanded(!railExpanded);
    }
  }

  $effect(() => {
    // Настройки меню персональны и не переходят к другому пользователю.
    navPrefs.setUser(auth.user?.id ?? null);
  });

  $effect(() => {
    // Плотность применяется ко всему сайту через токены в app.css.
    document.documentElement.dataset.density = navPrefs.density;
  });

  onMount(() => {
    const railQuery = window.matchMedia('(min-width: 768px)');
    railVisible = railQuery.matches;

    const onRailChange = (event: MediaQueryListEvent): void => {
      railVisible = event.matches;
    };

    railQuery.addEventListener('change', onRailChange);
    return () => railQuery.removeEventListener('change', onRailChange);
  });

  $effect(() => {
    if (!canExpandRail && railExpanded) {
      navPrefs.setRailExpanded(false);
    }
  });

  $effect(() => {
    if (!appSettings.loaded) {
      return;
    }

    if (!appSettings.requestsEnabled && path.startsWith('/requests')) {
      router.navigate('/stocks');
    } else if (
      !appSettings.substitutionsEnabled &&
      (path === '/substitutions' || path.startsWith('/staff/substitutions'))
    ) {
      router.navigate('/stocks');
    } else if (!appSettings.cartEnabled && path === '/cart') {
      router.navigate('/stocks');
    } else if (!appSettings.warehousesEnabled && path.startsWith('/stocks/warehouses')) {
      router.navigate('/stocks');
    } else if (path.startsWith('/warehouses')) {
      router.navigate(`/stocks${path}`);
    } else if (!appSettings.reportsEnabled && (path === '/reports' || path === '/my-reports')) {
      router.navigate('/stocks');
    } else if (!appSettings.chatEnabled && path === '/chat') {
      router.navigate('/requests');
    } else if (!appSettings.notificationsEnabled && path === '/notifications') {
      router.navigate('/requests');
    } else if (!appSettings.clientsEnabled && path.startsWith('/clients')) {
      router.navigate('/requests');
    } else if (!appSettings.staffEnabled && path.startsWith('/staff')) {
      router.navigate('/requests');
    }
  });

  interface MobileNavItem {
    path: string;
    label: string;
    icon: string;
    active: boolean;
    badge: number;
    topbar: boolean;
  }

  function toMobileItem(node: NavNode): MobileNavItem {
    const base = node.route.replace(/^#/, '');

    return {
      path: base,
      label: node.label,
      icon: node.iconId,
      active: path === base || path.startsWith(`${base}/`),
      badge: channelCount(node),
      topbar: node.presentation.topbar
    };
  }

  /*
   * Мобильная навигация — второе представление того же visibleNav:
   * до 4 нижних разделов и «Ещё» для остальных доступных узлов.
   */
  const mobileNodes = $derived(
    appSettings.visibleNav
      .filter((node) => node.presentation.bottom || node.presentation.drawer)
      .slice()
      .sort((a, b) => a.order - b.order)
  );

  const mobileNavItems = $derived(mobileNodes.filter((node) => node.presentation.bottom).slice(0, 4).map(toMobileItem));
  const primaryNavItems = $derived(mobileNavItems.slice(0, 4));

  const overflowNavItems = $derived(
    mobileNodes.filter((node) => !node.presentation.bottom).map(toMobileItem)
  );
  const sectionItems = $derived(overflowNavItems.filter((item) => !item.topbar));
  const mobileServiceItems = $derived(overflowNavItems.filter((item) => item.topbar));
  const overflowActive = $derived(overflowNavItems.some((item) => item.active));

  const moreBadge = $derived(overflowNavItems.reduce((sum, item) => sum + item.badge, 0));

  let mobileMenuOpen = $state(false);
  let mobileMenuEl = $state<HTMLElement | null>(null);
  let moreBtnEl = $state<HTMLElement | null>(null);

  function closeMobileMenu(): void {
    const active = document.activeElement;
    if (mobileMenuOpen && mobileMenuEl?.contains(active)) {
      moreBtnEl?.focus();
    }
    mobileMenuOpen = false;
  }

  function onMenuKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Tab' || mobileMenuEl === null) {
      return;
    }

    const focusables = Array.from(
      mobileMenuEl.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter((element) => element.offsetParent !== null);

    if (focusables.length === 0) {
      return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement as HTMLElement | null;

    if (event.shiftKey && (active === first || active === mobileMenuEl)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  $effect(() => {
    const mq = window.matchMedia('(max-width: 767px)');
    const onChange = (event: MediaQueryListEvent): void => {
      // шторка «Ещё» — мобильный артефакт: при поворте/расширении закрываем
      if (!event.matches && mobileMenuOpen) {
        mobileMenuOpen = false;
      }
    };
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  });

  $effect(() => {
    void path;
    mobileMenuOpen = false;
  });

  $effect(() => {
    if (commandPalette.open && mobileMenuOpen) {
      closeMobileMenu();
    }
  });

  $effect(() => {
    if (mobileMenuOpen) {
      mobileMenuEl?.focus();
    }
  });

  $effect(() => {
    if (!mobileMenuOpen) {
      return;
    }

    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previous;
    };
  });

  function onGlobalKeydown(event: KeyboardEvent): void {
    if (commandPalette.open) {
      return;
    }
    if (event.key === 'Escape' && mobileMenuOpen) {
      closeMobileMenu();
    }
  }

  onMount(() => {
    const tick = async (): Promise<void> => {
      try {
        const [notes, chat] = await Promise.all([
          appSettings.notificationsEnabled && auth.can('notifications.use')
            ? unreadCount().catch(() => ({ unread: 0 }))
            : Promise.resolve({ unread: 0 }),
          appSettings.chatEnabled && auth.can('chat.use')
            ? chatUnread().catch(() => ({ unread: 0 }))
            : Promise.resolve({ unread: 0 })
        ]);

        unread = notes.unread;
        chatUnreadCount = chat.unread;
      } catch {
        // некритично: обновим на следующем тике
      }
    };

    void tick();
    const timer = setInterval(() => void tick(), config.notificationsPollMs);

    return () => clearInterval(timer);
  });

  /**
   * Обычный якорь в hash-роутере трактуется как маршрут. Оставляем ссылку
   * семантической, но не меняем hash: фокус и прокрутку выполняем явно.
   */
  function skipToContent(event: MouseEvent): void {
    event.preventDefault();
    const main = document.getElementById('main-content');

    if (main === null) {
      return;
    }

    main.focus();
    main.scrollIntoView();
  }

</script>

<svelte:window onkeydown={onGlobalKeydown} />

<div class="shell" style:--rail-width={`${railWidth}px`} use:swipeBack>
  <a class="skip-link" href="#main-content" onclick={skipToContent}>К содержимому</a>
    <AppRail
    nodes={appSettings.visibleNav}
    {path}
    expanded={railExpanded}
    {railWidth}
    canExpand={canExpandRail}
    {unread}
    {chatUnreadCount}
    navError={appSettings.navError}
    onRetry={() => void appSettings.retry()}
    onToggle={toggleRail}
  />
  {#if !network.online}
    <div class="network-strip offline" role="status">
      <span class="strip-icon"><Icon name="close-circle" size={16} /></span>
      <span>Нет подключения. Проверьте интернет — данные обновятся, когда связь вернётся.</span>
    </div>
  {:else if network.restored}
    <div class="network-strip restored" role="status">
      <span class="strip-icon"><Icon name="check-circle" size={16} /></span>
      <span>Подключение восстановлено.</span>
    </div>
  {/if}

<main id="main-content" tabindex="-1">
  {@render children?.()}
</main>

  <nav class="mobile-nav" aria-label="Основная навигация">
    {#each primaryNavItems as item (item.path)}
      <a
        href={`#${item.path}`}
        class:active={item.active}
        aria-current={item.active ? 'page' : undefined}
        title={item.label}
      >
        <Icon name={item.icon} size={20} />
        <span class="nav-label">{item.label}</span>
      </a>
    {/each}
    <button
      type="button"
      class="nav-more"
      class:active={mobileMenuOpen || overflowActive}
      aria-expanded={mobileMenuOpen}
      aria-controls="mobile-more-menu"
      aria-label={moreBadge > 0 ? `Ещё: ${moreBadge} новых` : 'Ещё'}
      title={moreBadge > 0 ? `Ещё: ${moreBadge} новых` : 'Ещё'}
      bind:this={moreBtnEl}
      onclick={() => (mobileMenuOpen = !mobileMenuOpen)}
    >
      <span class="more-icon">
        <Icon name="menu" size={20} />
        {#if moreBadge > 0}
          <span class="more-badge">{moreBadge > 99 ? '99+' : moreBadge}</span>
        {/if}
      </span>
      <span class="nav-label">Ещё</span>
    </button>
  </nav>

  {#if mobileMenuOpen}
    <button
      type="button"
      class="mobile-menu-backdrop"
      aria-label="Закрыть меню"
      tabindex="-1"
      onclick={closeMobileMenu}
    ></button>
    <div
      id="mobile-more-menu"
      class="mobile-menu"
      role="dialog"
      aria-modal="true"
      aria-label="Ещё: разделы и сервис"
      tabindex="-1"
      onkeydown={onMenuKeydown}
      bind:this={mobileMenuEl}
    >
      <div class="mobile-menu-head">
        <span>Ещё</span>
        <button
          type="button"
          class="mobile-menu-close"
          aria-label="Закрыть меню"
          title="Закрыть"
          onclick={closeMobileMenu}
        >
          <Icon name="close" size={18} />
        </button>
      </div>
      <div class="mobile-menu-list">
        {#if quickActionCount > 0}
          <section class="mobile-menu-group" aria-label="Быстрые действия">
            <p class="mobile-menu-title">Быстрые действия</p>
            <QuickActions inline onNavigate={closeMobileMenu} />
          </section>
        {/if}
        <section class="mobile-menu-group" aria-label="Отображение">
          <p class="mobile-menu-title">Отображение</p>
          <button
            type="button"
            class="mobile-density"
            aria-pressed={navPrefs.density === 'compact'}
            onclick={() => navPrefs.toggleDensity()}
          >
            <Icon name={navPrefs.density === 'compact' ? 'view' : 'list'} size={18} />
            <span>{navPrefs.density === 'compact' ? 'Компактно' : 'Комфортно'}</span>
          </button>
          <button
            type="button"
            class="mobile-density"
            aria-pressed={theme.dark}
            onclick={() => theme.toggle()}
          >
            <Icon name="picture" size={18} />
            <span>{theme.dark ? 'Светлая тема' : 'Тёмная тема'}</span>
          </button>
        </section>
        {#if sectionItems.length > 0}
          <nav class="mobile-menu-group" aria-label="Дополнительные разделы">
            <p class="mobile-menu-title">Разделы</p>
            {#each sectionItems as item (item.path)}
              <a
                href={`#${item.path}`}
                class:active={item.active}
                aria-current={item.active ? 'page' : undefined}
                onclick={closeMobileMenu}
              >
                <Icon name={item.icon} size={18} />
                <span>{item.label}</span>
              </a>
            {/each}
          </nav>
        {/if}
        <nav class="mobile-menu-group" aria-label="Сервис">
          <p class="mobile-menu-title">Сервис</p>
          {#each mobileServiceItems as item (item.path)}
            <a
              href={`#${item.path}`}
              class:active={item.active}
              aria-current={item.active ? 'page' : undefined}
              onclick={closeMobileMenu}
            >
              <Icon name={item.icon} size={18} />
              <span>{item.label}</span>
              {#if item.badge > 0}
                <span class="menu-badge">{item.badge > 99 ? '99+' : item.badge}</span>
              {/if}
            </a>
          {/each}
        </nav>
      </div>
    </div>
  {/if}
</div>

<!-- Палитра живёт вне .shell, но должна знать ширину рейла для центрирования. -->
<div style:--rail-width={`${railWidth}px`}>
  <CommandPalette />
</div>

<style>
  .shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding-top: env(safe-area-inset-top);
  }

  .network-strip {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-5);
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    line-height: 1.4;
    text-align: center;
  }

  .network-strip.offline {
    background: var(--danger-bg);
    color: var(--error-text);
  }

  .network-strip.restored {
    background: var(--success-bg);
    color: var(--success-text);
  }

  .strip-icon {
    display: inline-flex;
    flex: 0 0 auto;
  }

  nav a {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
    border-bottom: 2px solid transparent;
    white-space: nowrap;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  nav a.active,
  nav a:hover {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  main {
    flex: 1;
    /* Фиксируем ширину: смена вкладок не должна тянуть main за содержимым. */
    width: min(calc(100% - var(--rail-width, 56px)), 1328px);
    margin: 0 0 0 var(--rail-width, 56px);
    padding: var(--space-5);
    min-width: 0;
    transition: margin-left 0.18s ease;
  }

  @media (prefers-reduced-motion: reduce) {
    main {
      transition: none;
    }
  }

  .mobile-nav {
    display: none;
  }

  @media (max-width: 767px) {
    /*
     * Фиксированная высота оболочки на телефоне: main получает остаток
     * экрана, и страницы вроде чата заполняют его без расчётов от 100dvh.
     */
    .shell {
      height: 100dvh;
      min-height: 100dvh;
    }

    main {
      display: flex;
      flex-direction: column;
      width: 100%;
      max-width: none;
      margin-left: 0;
      padding: var(--space-4);
      padding-bottom: calc(84px + env(safe-area-inset-bottom));
    }

    .mobile-nav {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      justify-content: space-between;
      box-sizing: border-box;
      max-width: 100%;
      overflow: hidden;
      background: var(--surface);
      border-top: 1px solid var(--border);
      padding: 6px 4px calc(6px + env(safe-area-inset-bottom));
      z-index: 10;
    }

    .mobile-nav a,
    .mobile-nav .nav-more {
      display: flex;
      flex: 1 1 0;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      min-width: 0;
      font-size: 10px;
      border-bottom: none;
      padding: 4px 2px;
      white-space: nowrap;
    }

    .mobile-nav .nav-more {
      border: 0;
      background: none;
      color: inherit;
      font-family: inherit;
      cursor: pointer;
    }

    .more-icon {
      position: relative;
      display: inline-flex;
    }

    .more-badge {
      position: absolute;
      top: -5px;
      right: -7px;
      min-width: 16px;
      height: 16px;
      padding: 0 4px;
      border-radius: 8px;
      background: var(--danger);
      color: #fff;
      font-size: 10px;
      line-height: 16px;
      text-align: center;
      box-shadow: 0 0 0 1px var(--surface);
    }

    .mobile-nav a.active,
    .mobile-nav .nav-more.active {
      color: var(--primary);
    }

    .mobile-nav .nav-label {
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* Очень узкие телефоны: «Номенклатура» должна помещаться целиком. */
    @media (max-width: 360px) {
      .mobile-nav a,
      .mobile-nav .nav-more {
        padding: 4px 1px;
      }

      .mobile-nav .nav-label {
        font-size: 9px;
        letter-spacing: -0.2px;
      }
    }
  }

  .mobile-menu-backdrop {
    position: fixed;
    inset: 0;
    margin: 0;
    padding: 0;
    border: 0;
    background: rgba(15, 23, 42, 0.4);
    z-index: 45;
    animation: mobile-menu-fade 120ms ease-out;
  }

  .mobile-menu {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    width: min(320px, 86vw);
    padding: calc(12px + env(safe-area-inset-top)) 0 calc(12px + env(safe-area-inset-bottom));
    background: var(--surface);
    box-shadow: var(--shadow-md);
    outline: none;
    z-index: 46;
    animation: mobile-menu-slide 140ms ease-out;
  }

  .mobile-menu-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 var(--space-4) var(--space-2);
    font-weight: 600;
  }

  .mobile-menu-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .mobile-menu-list {
    display: flex;
    flex-direction: column;
    overflow-y: auto;
  }

  .mobile-menu-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 var(--space-2);
  }

  .mobile-menu-title {
    margin: var(--space-3) var(--space-3) 4px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .mobile-menu-group:first-child .mobile-menu-title {
    margin-top: 0;
  }

  .mobile-density {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 44px;
    padding: 0 var(--space-3);
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
  }

  .mobile-density:hover,
  .mobile-density[aria-pressed='true'] {
    background: var(--fill-tertiary);
    color: var(--primary);
  }

  .mobile-menu-group .menu-badge {
    margin-left: auto;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: var(--danger);
    color: #fff;
    font-size: 12px;
    line-height: 20px;
    text-align: center;
  }

  .mobile-menu-list a {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 12px var(--space-3);
    border-bottom: none;
    border-radius: var(--radius-sm);
    color: var(--text);
  }

  .mobile-menu-list a.active {
    background: var(--primary-bg);
    color: var(--primary);
  }

  @keyframes mobile-menu-slide {
    from {
      transform: translateX(100%);
    }
    to {
      transform: translateX(0);
    }
  }

  @keyframes mobile-menu-fade {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }
  @media (max-width: 360px) {
    main {
      padding: var(--space-3);
      padding-bottom: calc(84px + env(safe-area-inset-bottom));
    }
  }
</style>
