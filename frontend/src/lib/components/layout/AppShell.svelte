<script lang="ts">
  import { onMount } from 'svelte';
  import type { Snippet } from 'svelte';
  import { chatUnread } from '../../api/chat';
  import { unreadCount } from '../../api/notifications';
  import { config } from '../../config';
  import { avatarColor, avatarLetter, avatarName } from '../../avatar';
  import Icon from '../ui/Icon.svelte';
  import { auth } from '../../stores/auth.svelte';
  import { avatars } from '../../stores/avatar.svelte';
  import { appSettings } from '../../stores/app-settings.svelte';
  import { network } from '../../stores/network.svelte';
  import { swipeBack } from '../../a11y/swipe';
  import { cart } from '../../stores/cart.svelte';
  import { router } from '../../router.svelte';
  import Logo from '../ui/Logo.svelte';

  interface Props {
    children?: Snippet;
  }

  let { children }: Props = $props();

  let unread = $state(0);
  let chatUnreadCount = $state(0);

  const avatarUrl = $derived(avatars.url(auth.user?.id));
  let avatarFailed = $state(false);

  $effect(() => {
    void avatarUrl;
    avatarFailed = false;
  });

  const avatarSeed = $derived(avatarName(auth.user?.name, auth.user?.login ?? 'guest'));
  const letter = $derived(avatarLetter(avatarSeed));
  const avatarBg = $derived(avatarColor(avatarSeed));

  const path = $derived(router.current.path);
  const brandHint = $derived(appSettings.appDescription || appSettings.appTitle);

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

  const showClients = $derived(
    appSettings.clientsEnabled && (auth.can('clients.view.all') || auth.can('clients.view.own'))
  );

  const showSettings = $derived(
    auth.can('settings.manage') || auth.can('mail.manage') || auth.can('mail.send')
  );

  const showStaff = $derived(appSettings.staffEnabled && auth.can('users.manage'));

  const showAudit = $derived(auth.level >= 90);

  const canViewReports = $derived(auth.can('reports.view.all') || auth.can('reports.view.own'));

  const showReports = $derived(appSettings.reportsEnabled && canViewReports);

  const showMyReports = $derived(appSettings.reportsEnabled && !canViewReports);

  const navCount = $derived(
    3 +
      (showReports ? 1 : 0) +
      (showClients ? 1 : 0) +
      (showStaff ? 1 : 0) +
      (showSettings ? 1 : 0)
  );

  interface MobileNavItem {
    path: string;
    label: string;
    icon: string;
    active: boolean;
  }

  /*
   * Мобильная навигация — одна, внизу: до 4 основных разделов + «Ещё».
   * Шапка несёт только статусные каналы (уведомления) и профиль;
   * чат, корзина и служебные пункты живут в «Ещё», чтобы не дублировать меню.
   */
  const mobileNavItems = $derived.by<MobileNavItem[]>(() => {
    const items: MobileNavItem[] = [
      {
        path: '/stocks',
        label: 'Номенклатура',
        icon: 'stocks',
        active: path === '/stocks' || path.startsWith('/stocks/')
      }
    ];

    if (appSettings.requestsEnabled) {
      items.push({
        path: '/requests',
        label: 'Заявки',
        icon: 'requests',
        active: path.startsWith('/requests')
      });
    }
    if (showReports) {
      items.push({ path: '/reports', label: 'Отчёты', icon: 'reports', active: path === '/reports' });
    }
    if (showMyReports) {
      items.push({
        path: '/my-reports',
        label: 'Мои отчёты',
        icon: 'my-reports',
        active: path === '/my-reports'
      });
    }
    if (showClients) {
      items.push({
        path: '/clients',
        label: 'Клиенты',
        icon: 'clients',
        active: path.startsWith('/clients')
      });
    }
    if (showStaff) {
      items.push({
        path: '/staff',
        label: 'Сотрудники',
        icon: 'staff',
        active: path.startsWith('/staff') || path === '/substitutions'
      });
    }
    if (showSettings) {
      items.push({
        path: '/settings',
        label: 'Настройки',
        icon: 'settings',
        active: path === '/settings'
      });
    }

    return items;
  });

  const primaryNavItems = $derived(mobileNavItems.slice(0, 4));
  const overflowNavItems = $derived(mobileNavItems.slice(4));
  const overflowActive = $derived(overflowNavItems.some((item) => item.active));

  const mobileServices = $derived.by<{
    path: string;
    label: string;
    icon: string;
    active: boolean;
    badge: number;
  }[]>(() => {
    const items: { path: string; label: string; icon: string; active: boolean; badge: number }[] = [];

    if (appSettings.chatEnabled && auth.can('chat.use')) {
      items.push({
        path: '/chat',
        label: 'Чат',
        icon: 'chat',
        active: path === '/chat',
        badge: chatUnreadCount
      });
    }
    if (appSettings.salesEnabled && appSettings.cartEnabled) {
      items.push({
        path: '/cart',
        label: 'Корзина',
        icon: 'cart',
        active: path === '/cart',
        badge: cart.count
      });
    }
    items.push({ path: '/about', label: 'О программе', icon: 'info', active: path === '/about', badge: 0 });
    if (showAudit) {
      items.push({
        path: '/admin/audit',
        label: 'Журнал',
        icon: 'audit',
        active: path === '/admin/audit',
        badge: 0
      });
    }

    return items;
  });

  const moreBadge = $derived(
    mobileServices.reduce((sum, item) => sum + item.badge, 0)
  );

  let mobileMenuOpen = $state(false);
  let mobileMenuEl = $state<HTMLElement | null>(null);
  let moreBtnEl = $state<HTMLElement | null>(null);

  function closeMobileMenu(): void {
    if (mobileMenuOpen) {
      moreBtnEl?.focus();
    }
    mobileMenuOpen = false;
  }

  $effect(() => {
    const mq = window.matchMedia('(max-width: 720px)');
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
    if (mobileMenuOpen) {
      mobileMenuEl?.focus();
    }
  });

  function onGlobalKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && mobileMenuOpen) {
      closeMobileMenu();
    }
  }

  onMount(() => {
    if (auth.user) {
      void avatars.load(auth.user.id);
    }

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

</script>

<svelte:window onkeydown={onGlobalKeydown} />

<div class="shell" class:demo={appSettings.demoMode} use:swipeBack>
  <a class="skip-link" href="#main-content">К содержимому</a>
  <header>
    <div class="left">
      <a class="brand" href="#/requests" title={brandHint}>
        <span title={brandHint}><Logo size={26} /></span>
        <span title={brandHint}>{appSettings.appTitle}</span>
      </a>
      <nav class="desktop-nav" class:many={navCount > 6} aria-label="Основная навигация">
        <a
          href="#/stocks"
          class:active={path === '/stocks' || path.startsWith('/stocks/')}
          aria-current={path === '/stocks' || path.startsWith('/stocks/') ? 'page' : undefined}
          title="Номенклатура"
        >
          <Icon name="stocks" size={20} />
          <span class="nav-label">Номенклатура</span>
        </a>
        {#if appSettings.requestsEnabled}
          <a
            href="#/requests"
            class:active={path.startsWith('/requests')}
            aria-current={path.startsWith('/requests') ? 'page' : undefined}
            title="Заявки"
          >
            <Icon name="requests" size={20} />
            <span class="nav-label">Заявки</span>
          </a>
        {/if}
        {#if showReports}
          <a
            href="#/reports"
            class:active={path === '/reports'}
            aria-current={path === '/reports' ? 'page' : undefined}
            title="Отчёты"
          >
            <Icon name="reports" size={20} />
            <span class="nav-label">Отчёты</span>
          </a>
        {/if}
        {#if showMyReports}
          <a
            href="#/my-reports"
            class:active={path === '/my-reports'}
            aria-current={path === '/my-reports' ? 'page' : undefined}
            title="Мои отчёты"
          >
            <Icon name="my-reports" size={20} />
            <span class="nav-label">Мои отчёты</span>
          </a>
        {/if}
        {#if showClients}
          <a
            href="#/clients"
            class:active={path.startsWith('/clients')}
            aria-current={path.startsWith('/clients') ? 'page' : undefined}
            title="Клиенты"
          >
            <Icon name="clients" size={20} />
            <span class="nav-label">Клиенты</span>
          </a>
        {/if}
        {#if showStaff}
          <a
            href="#/staff"
            class:active={path.startsWith('/staff') || path === '/substitutions'}
            aria-current={path.startsWith('/staff') || path === '/substitutions' ? 'page' : undefined}
            title="Сотрудники"
          >
            <Icon name="staff" size={20} />
            <span class="nav-label">Сотрудники</span>
          </a>
        {/if}
        {#if showSettings}
          <a
            href="#/settings"
            class:active={path === '/settings'}
            aria-current={path === '/settings' ? 'page' : undefined}
            title="Настройки"
          >
            <Icon name="settings" size={20} />
            <span class="nav-label">Настройки</span>
          </a>
        {/if}
      </nav>
    </div>
    <div class="user">
      {#if showAudit}
        <button
          type="button"
          class="bell bell-audit"
          class:current={path === '/admin/audit'}
          aria-label="Журнал"
          title="Журнал"
          onclick={() => router.navigate('/admin/audit')}
        >
          <Icon name="audit" size={20} />
        </button>
      {/if}
      <button
        type="button"
        class="bell bell-info"
        class:current={path === '/about'}
        aria-label="О программе"
        title="О программе"
        onclick={() => router.navigate('/about')}
      >
        <Icon name="info" size={20} />
      </button>
      {#if appSettings.chatEnabled && auth.can('chat.use')}
        <button
          type="button"
          class="bell bell-chat"
          class:has-new={chatUnreadCount > 0}
          class:current={path === '/chat'}
          aria-label={chatUnreadCount > 0 ? `Чат: ${chatUnreadCount} новых` : 'Чат'}
          title="Чат"
          onclick={() => router.navigate('/chat')}
        >
          <Icon name="chat" size={20} />
          {#if chatUnreadCount > 0}
            <span class="bell-badge">{chatUnreadCount > 99 ? '99+' : chatUnreadCount}</span>
          {/if}
        </button>
      {/if}
      {#if appSettings.notificationsEnabled && auth.can('notifications.use')}
        <button
          type="button"
          class="bell"
          class:has-new={unread > 0}
          class:current={path === '/notifications'}
          aria-label={unread > 0 ? `Уведомления: ${unread} новых` : 'Уведомления'}
          title="Уведомления"
          onclick={() => router.navigate('/notifications')}
        >
          <Icon name="bell" size={20} />
          {#if unread > 0}
            <span class="bell-badge">{unread > 99 ? '99+' : unread}</span>
          {/if}
        </button>
      {/if}
      {#if appSettings.salesEnabled && appSettings.cartEnabled}
        <button
          type="button"
          class="bell bell-cart"
          class:has-new={cart.count > 0}
          class:current={path === '/cart'}
          aria-label={cart.count > 0 ? `Корзина: ${cart.count}` : 'Корзина'}
          title="Корзина"
          onclick={() => router.navigate('/cart')}
        >
          <Icon name="cart" size={20} />
          {#if cart.count > 0}
            <span class="bell-badge">{cart.count > 99 ? '99+' : cart.count}</span>
          {/if}
        </button>
      {/if}
      <button
        type="button"
        class="avatar"
        title="Профиль"
        aria-label="Профиль"
        onclick={() => router.navigate('/profile')}
      >
          {#if avatarUrl && !avatarFailed}
            <img src={avatarUrl} alt="" onerror={() => (avatarFailed = true)} />
          {:else}
          <span style:background={avatarBg}>{letter}</span>
        {/if}
      </button>
      <button type="button" class="name" title="Профиль" onclick={() => router.navigate('/profile')}>
        {auth.name || 'Пользователь'}
      </button>
    </div>
  </header>

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

  {#if appSettings.demoMode}
    <div class="demo-banner">
      Демо-режим: данные вымышленные, изменения не сохраняются. Смена паролей и управление
      пользователями недоступны.
    </div>
  {/if}

<main id="main-content">
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
        {#if overflowNavItems.length > 0}
          <nav class="mobile-menu-group" aria-label="Дополнительные разделы">
            <p class="mobile-menu-title">Разделы</p>
            {#each overflowNavItems as item (item.path)}
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
          {#each mobileServices as item (item.path)}
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

<style>
  .shell {
    --demo-banner-height: 0px;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding-top: env(safe-area-inset-top);
  }

  .shell.demo {
    --demo-banner-height: 37px;
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

  .demo-banner {
    padding: var(--space-2) var(--space-5);
    background: var(--warning-bg, #fff4d6);
    color: var(--warning, #8a6d00);
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    text-align: center;
  }

  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    height: 64px;
    padding: 0 var(--space-5);
    background: var(--surface);
    border-bottom: 1px solid var(--border-secondary);
  }

  .left {
    display: flex;
    align-items: center;
    gap: var(--space-5);
    height: 100%;
  }

  .brand {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--text);
    font-size: 18px;
    font-weight: 600;
    text-decoration: none;
  }

  nav.desktop-nav {
    display: flex;
    align-items: stretch;
    gap: 0;
    height: 100%;
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

  .desktop-nav a {
    height: 100%;
    padding: 0 var(--space-4);
  }

  /* Компактная навигация: подписи разделов сохраняются как можно дольше. */
  @media (max-width: 1520px) {
    .desktop-nav.many a {
      gap: 6px;
      padding: 0 var(--space-2);
      font-size: 13px;
    }
  }

  @media (max-width: 1400px) {
    .desktop-nav a {
      padding: 0 var(--space-3);
    }
  }

  @media (max-width: 1240px) {
    .name {
      display: none;
    }
  }

  @media (max-width: 1180px) {
    .brand > span:last-child {
      display: none;
    }

    .desktop-nav a {
      gap: 6px;
      padding: 0 var(--space-2);
      font-size: 13px;
    }
  }

  @media (max-width: 980px) {
    .desktop-nav .nav-label {
      display: none;
    }
  }

  .user {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .name {
    padding: 0;
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 500;
    cursor: pointer;
  }

  .name:hover {
    color: var(--primary);
  }

  .avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    overflow: hidden;
  }

  .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: #fff;
  }

  .avatar:hover {
    border-color: var(--primary);
  }

  .bell {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.15s ease, background-color 0.15s ease;
  }

  .bell:hover {
    color: var(--primary);
    background: var(--fill-tertiary);
  }

  .bell.has-new {
    color: var(--primary);
  }

  .bell.current {
    color: var(--primary);
    background: var(--primary-bg);
  }

  .bell-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: var(--danger);
    color: #fff;
    font-size: 12px;
    line-height: 20px;
    text-align: center;
    box-shadow: 0 0 0 1px var(--surface);
  }

  main {
    flex: 1;
    width: 100%;
    max-width: 1280px;
    margin: 0 auto;
    padding: var(--space-5);
  }

  .mobile-nav {
    display: none;
  }

  @media (max-width: 720px) {
    header {
      padding: 0 var(--space-4);
    }

    .bell-badge {
      top: 0;
      right: 0;
      min-width: 18px;
      height: 18px;
      padding: 0 5px;
      border-radius: 9px;
      font-size: 11px;
      line-height: 18px;
    }

    nav.desktop-nav {
      display: none;
    }

    .name {
      display: none;
    }

    /*
     * Шапка — не меню: только логотип, уведомления и профиль.
     * Чат, корзина, «О программе» и «Журнал» — в нижнем «Ещё».
     */
    .bell-audit,
    .bell-info,
    .bell-chat,
    .bell-cart {
      display: none;
    }

    main {
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
    header {
      gap: var(--space-2);
      padding: 0 var(--space-2);
    }

    .user {
      gap: 2px;
    }

    .bell {
      width: 30px;
      height: 30px;
    }

    .avatar {
      width: 28px;
      height: 28px;
      font-size: 12px;
    }

    main {
      padding: var(--space-3);
      padding-bottom: calc(84px + env(safe-area-inset-bottom));
    }
  }
</style>
