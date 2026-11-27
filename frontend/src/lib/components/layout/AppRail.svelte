<script lang="ts">
  import { untrack } from 'svelte';
  import type { NavNode } from '../../stores/app-settings.svelte';
  import { auth } from '../../stores/auth.svelte';
  import { router } from '../../router.svelte';
  import Icon from '../ui/Icon.svelte';
  import Logo from '../ui/Logo.svelte';
  import { commandPalette } from '../../stores/command-palette.svelte';
  import { navPrefs } from '../../stores/nav-prefs.svelte';
  import { theme } from '../../stores/theme.svelte';
  import { cart } from '../../stores/cart.svelte';
  import { sectionsFromNav, type SearchSection, type SearchTreeNode } from './nav-search';

  interface Props {
    nodes: NavNode[];
    path: string;
    expanded: boolean;
    railWidth: number;
    canExpand: boolean;
    unread: number;
    chatUnreadCount: number;
    navError?: string | null;
    onRetry?: () => void;
    onToggle: () => void;
  }

  let {
    nodes,
    path,
    expanded,
    railWidth,
    canExpand,
    unread,
    chatUnreadCount,
    navError = null,
    onRetry,
    onToggle
  }: Props = $props();

  type TreeNode = SearchTreeNode;
  type TreeGroup = SearchSection;

  interface AccordionState {
    user: string;
    groups: Record<string, boolean>;
    sections: Record<string, boolean>;
  }

  let flyoutGroup = $state<string | null>(null);
  let flyoutEl = $state<HTMLDivElement | null>(null);
  let flyoutTrigger = $state<HTMLButtonElement | null>(null);
  let logoHover = $state(false);
  let searchBtnEl = $state<HTMLButtonElement | null>(null);

  const currentUser = $derived(String(auth.user?.id ?? 'guest'));

  /* Состояния accordion — пользовательские настройки отображения, не права. */
  let accordion = $state<AccordionState>({ user: 'guest', groups: {}, sections: {} });

  function storageKey(userId: string): string {
    return `crm.nav2.accordion.${userId}`;
  }

  function readAccordion(userId: string): AccordionState {
    const fresh: AccordionState = { user: userId, groups: {}, sections: {} };
    try {
      const raw = localStorage.getItem(storageKey(userId));
      if (raw === null) {
        return fresh;
      }
      const parsed = JSON.parse(raw) as Partial<AccordionState> | null;
      const groups = parsed?.groups;
      const sections = parsed?.sections;
      return {
        user: userId,
        groups: groups !== null && typeof groups === 'object' ? groups : {},
        sections: sections !== null && typeof sections === 'object' ? sections : {}
      };
    } catch {
      return fresh;
    }
  }

  /* Единое построение дерева для Rail и Command Palette. */
  const groups = $derived<TreeGroup[]>(sectionsFromNav(nodes));
  const channelNodes = $derived(nodes.filter((node) => node.presentation.topbar));

  function channelCount(node: NavNode): number {
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

  function channelLabel(node: NavNode): string {
    const count = channelCount(node);
    if (node.badge?.source === 'chat') {
      return count > 0 ? `Чат: ${count} новых` : 'Чат';
    }
    if (node.badge?.source === 'notifications') {
      return count > 0 ? `Уведомления: ${count} новых` : 'Уведомления';
    }
    if (node.badge?.source === 'cart') {
      return count > 0 ? `Корзина: ${count}` : 'Корзина';
    }
    return node.label;
  }

  function flatten(items: TreeNode[], result: NavNode[] = []): NavNode[] {
    for (const item of items) {
      result.push(item.node);
      flatten(item.children, result);
    }
    return result;
  }

  function isActive(node: NavNode, current: string): boolean {
    const base = node.route.replace(/^#/, '');
    return current === base || current.startsWith(`${base}/`);
  }

  function findActive(item: TreeNode, current: string, ancestors: string[] = []): string[] | null {
    const selfActive = isActive(item.node, current);
    for (const child of item.children) {
      const nested = findActive(child, current, [...ancestors, item.node.id]);
      if (nested !== null) {
        return nested;
      }
    }
    return selfActive ? ancestors : null;
  }

  function isGroupOpen(code: string): boolean {
    return accordion.groups[code] === true;
  }

  function isSectionOpen(id: string): boolean {
    return accordion.sections[id] === true;
  }

  function toggleGroup(code: string): void {
    accordion = {
      ...accordion,
      groups: { ...accordion.groups, [code]: !isGroupOpen(code) }
    };
  }

  function toggleSection(id: string): void {
    accordion = {
      ...accordion,
      sections: { ...accordion.sections, [id]: !isSectionOpen(id) }
    };
  }

  /* Загрузка/сохранение states привязаны к пользователю: настройки не текут между учётками. */
  $effect(() => {
    accordion = readAccordion(currentUser);
  });

  $effect(() => {
    const snapshot = accordion;
    try {
      localStorage.setItem(
        storageKey(snapshot.user),
        JSON.stringify({ groups: snapshot.groups, sections: snapshot.sections })
      );
    } catch {
      // приватный режим — состояние действует до перезагрузки
    }
  });

  $effect(() => {
    const currentPath = path;
    const tree = groups;
    untrack(() => {
      const nextGroups = { ...accordion.groups };
      const nextSections = { ...accordion.sections };
      let changed = false;

      // Удаляем состояния узлов, которых больше нет в доступном visibleNav.
      const groupCodes = new Set(tree.map((group) => group.code));
      const nodeIds = new Set<string>();
      for (const group of tree) {
        for (const item of flatten(group.items)) {
          nodeIds.add(item.id);
        }
      }
      for (const key of Object.keys(nextGroups)) {
        if (!groupCodes.has(key)) {
          delete nextGroups[key];
          changed = true;
        }
      }
      for (const key of Object.keys(nextSections)) {
        if (!nodeIds.has(key)) {
          delete nextSections[key];
          changed = true;
        }
      }

      // Активный маршрут раскрывает только своих родителей, не сбрасывая остальные.
      for (const group of tree) {
        for (const root of group.items) {
          const ancestors = findActive(root, currentPath);
          if (ancestors === null) {
            continue;
          }
          if (nextGroups[group.code] !== true) {
            nextGroups[group.code] = true;
            changed = true;
          }
          for (const id of ancestors) {
            if (nextSections[id] !== true) {
              nextSections[id] = true;
              changed = true;
            }
          }
        }
      }

      if (changed) {
        accordion = { ...accordion, groups: nextGroups, sections: nextSections };
      }
    });
  });

  function toggleFlyout(code: string, event: MouseEvent): void {
    if (flyoutGroup === code) {
      closeFlyout();
      return;
    }
    flyoutTrigger = event.currentTarget as HTMLButtonElement;
    flyoutGroup = code;
    queueMicrotask(() => flyoutEl?.querySelector<HTMLElement>('a, button')?.focus());
  }

  function closeFlyout(restoreFocus = true): void {
    flyoutGroup = null;
    if (restoreFocus) {
      queueMicrotask(() => flyoutTrigger?.focus());
    }
  }

  function navigate(route: string): void {
    router.navigate(route.replace(/^#/, ''));
    closeFlyout(false);
  }

  function openSearch(): void {
    // Единая Command Palette: повторный вызов не создаёт второй слой.
    closeFlyout(false);
    // Явный фокус на иконке: Palette вернёт фокус именно сюда.
    searchBtnEl?.focus();
    commandPalette.openPalette();
  }

  function onWindowKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || commandPalette.open) {
      return;
    }

    if (flyoutGroup !== null) {
      event.stopPropagation();
      closeFlyout();
      return;
    }
  }

  function onWindowPointerDown(event: PointerEvent): void {
    if (flyoutGroup === null) {
      return;
    }

    const target = event.target as Node | null;
    if (target && (flyoutEl?.contains(target) || flyoutTrigger?.contains(target))) {
      return;
    }

    closeFlyout(false);
  }

  $effect(() => {
    // Маршрут меняется — временные слои навигации закрываются.
    void path;
    flyoutGroup = null;
    commandPalette.close();
  });

  $effect(() => {
    void expanded;
    flyoutGroup = null;
    logoHover = false;
    // В Compact второй кнопки поиска нет; Palette закрывается.
    if (!expanded) {
      commandPalette.close();
    }
  });

  $effect(() => {
    if (flyoutGroup !== null && !groups.some((group) => group.code === flyoutGroup)) {
      flyoutGroup = null;
    }
  });
</script>

<svelte:window onkeydown={onWindowKeydown} onpointerdown={onWindowPointerDown} />

<aside
  class="rail"
  class:expanded
  class:compact-dense={navPrefs.density === 'compact'}
  style:width={`${railWidth}px`}
  aria-label="Основная навигация"
>
  <div class="rail-head">
    <a
      class="rail-brand"
      class:expand-affordance={!expanded && canExpand && logoHover}
      class:expand-disabled={!expanded && !canExpand && logoHover}
      href="#/requests"
      title={!expanded && logoHover
        ? canExpand
          ? 'Развернуть навигацию'
          : 'Разворачивание доступно от 1280 px'
        : 'CRM7'}
      aria-label={!expanded && logoHover
        ? canExpand
          ? 'Развернуть навигацию'
          : 'Разворачивание доступно от 1280 px'
        : 'CRM7 — на главную'}
      onmouseenter={() => (logoHover = true)}
      onmouseleave={() => (logoHover = false)}
      onfocus={() => (logoHover = true)}
      onblur={() => (logoHover = false)}
      onclick={(event) => {
        if (!expanded && canExpand) {
          event.preventDefault();
          onToggle();
        }
      }}
    >
      {#if !expanded}
        <span class="brand-logo"><Logo size={22} /></span>
        <span class="brand-expand"><Icon name="menu-unfold" size={18} /></span>
      {:else}
        <span>CRM7</span>
      {/if}
    </a>
    {#if expanded}
      <div class="rail-head-actions">
        <button
          type="button"
          class="rail-search"
          bind:this={searchBtnEl}
          aria-label="Поиск по разделам"
          aria-expanded={commandPalette.open}
          title="Поиск по разделам"
          onclick={openSearch}
        >
          <Icon name="search" size={18} />
        </button>
        {#if canExpand}
          <button
            type="button"
            class="rail-toggle"
            onclick={onToggle}
            aria-label="Свернуть навигацию"
            aria-expanded={true}
          >
            <Icon name="menu-fold" size={18} />
          </button>
        {/if}
      </div>
    {/if}
  </div>

  {#if navError && nodes.length === 0}
    <div class="rail-error" role="status">
      <Icon name="info" size={20} />
      {#if expanded}
        <span>{navError}</span>
        {#if onRetry}
          <button type="button" onclick={onRetry}>Повторить</button>
        {/if}
      {/if}
    </div>
  {/if}

  <div class="rail-channels" aria-label="Статусные каналы">
    {#each channelNodes as node (node.id)}
      {@const count = channelCount(node)}
      <button
        type="button"
        class="rail-channel"
        class:has-new={count > 0}
        aria-label={channelLabel(node)}
        title={node.label}
        onclick={() => router.navigate(node.route.replace(/^#/, ''))}
      >
        <Icon name={node.iconId} size={18} />
        {#if count > 0}
          <span class="channel-badge">{count > 99 ? '99+' : count}</span>
        {/if}
      </button>
    {/each}
    <button
      type="button"
      class="rail-channel"
      aria-label="Профиль"
      title="Профиль"
      onclick={() => router.navigate('/profile')}
    >
      <Icon name="user" size={18} />
    </button>
  </div>

  {#if expanded}
    <nav class="tree" aria-label="Дерево разделов">
      {#each groups as group (group.code)}
        <section class="tree-group">
          <button
            type="button"
            class="tree-group-toggle"
            id={`nav-group-btn-${group.code}`}
            aria-expanded={isGroupOpen(group.code)}
            aria-controls={`nav-tree-${group.code}`}
            onclick={() => toggleGroup(group.code)}
          >
            <span>{group.title}</span>
          </button>
          <div class="tree-group-body" id={`nav-tree-${group.code}`} hidden={!isGroupOpen(group.code)}>
            {@render treeNodes(group.items, 0)}
          </div>
        </section>
      {/each}
    </nav>
    <div class="rail-foot">
      <div class="rail-foot-actions">
        <button
          type="button"
          aria-pressed={navPrefs.density === 'compact'}
          aria-label={navPrefs.density === 'compact'
            ? 'Плотность интерфейса: компактная. Переключить на комфортную'
            : 'Плотность интерфейса: комфортная. Переключить на компактную'}
          title={navPrefs.density === 'compact' ? 'Комфортные строки' : 'Компактные строки'}
          onclick={() => navPrefs.toggleDensity()}
        >
          <Icon name={navPrefs.density === 'compact' ? 'view' : 'list'} size={16} />
          <span>{navPrefs.density === 'compact' ? 'Компактно' : 'Комфортно'}</span>
        </button>
        <button
          type="button"
          aria-pressed={theme.dark}
          aria-label={theme.dark ? 'Светлая тема' : 'Тёмная тема'}
          title={theme.dark ? 'Светлая тема' : 'Тёмная тема'}
          onclick={() => theme.toggle()}
        >
          <span class="theme-mark" class:dark={theme.dark} aria-hidden="true"></span>
          <span>{theme.dark ? 'Светлая' : 'Тёмная'}</span>
        </button>
      </div>
    </div>
  {:else}
    <nav class="rail-nav" aria-label="Основные блоки">
      {#each groups as group (group.code)}
        <div class="rail-group">
          <button
            type="button"
            class="group-button"
            class:current={group.items.some((item) => findActive(item, path) !== null)}
            aria-label={`Группа «${group.title}»`}
            aria-expanded={flyoutGroup === group.code}
            aria-controls="nav-flyout"
            title={group.title}
            onclick={(event) => toggleFlyout(group.code, event)}
          >
            <Icon name={group.iconId} size={18} />
          </button>
        </div>
      {/each}
    </nav>
  {/if}
</aside>

{#snippet treeNodes(items: TreeNode[], depth: number)}
  {#each items as item (item.node.id)}
    {@const active = isActive(item.node, path)}
    <div class="tree-node">
      <div class="tree-node-row" style:--tree-depth={depth}>
        <a
          href={item.node.route}
          class="tree-link"
          class:active={active}
          aria-current={active ? 'page' : undefined}
          onclick={() => navigate(item.node.route)}
        >
          <Icon name={item.node.iconId} size={16} />
          <span>{item.node.label}</span>
          {#if channelCount(item.node) > 0}
            <span class="tree-badge">{channelCount(item.node) > 99 ? '99+' : channelCount(item.node)}</span>
          {:else if item.node.badge}
            <span class="rail-dot" aria-hidden="true"></span>
          {/if}
        </a>
      </div>
      {#if item.children.length > 0}
        <div class="tree-children">
          {@render treeNodes(item.children, depth + 1)}
        </div>
      {/if}
    </div>
  {/each}
{/snippet}

{#if flyoutGroup !== null}
  {@const group = groups.find((item) => item.code === flyoutGroup)}
  {#if group}
    <div
      class="flyout"
      id="nav-flyout"
      role="dialog"
      aria-label={`Группа «${group.title}»`}
      style:left={`${railWidth}px`}
      bind:this={flyoutEl}
    >
      <div class="flyout-head">
        <strong>{group.title}</strong>
        <button type="button" aria-label="Закрыть меню" onclick={() => closeFlyout()}>
          <Icon name="close" size={16} />
        </button>
      </div>
      <nav aria-label={group.title}>
        {@render treeNodes(group.items, 0)}
      </nav>
    </div>
  {/if}
{/if}

<!-- Phase 3: единая Command Palette монтируется в AppShell; Rail открывает её через store. -->

<style>
  .rail {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 60;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border-right: 1px solid var(--border);
    transition: width 0.18s ease;
  }

  .rail-head {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    height: 56px;
    padding: 0 12px;
    border-bottom: 1px solid var(--border-secondary);
  }

  /* В Compact логотип стоит по центру той же колонки, что и иконки блоков. */
  .rail:not(.expanded) .rail-head {
    justify-content: center;
    padding: 0 6px;
  }

  .rail-brand.expand-affordance {
    border-color: var(--border);
    background: var(--fill-tertiary);
    color: var(--primary);
  }

  .rail-brand.expand-disabled {
    color: var(--muted);
    opacity: 0.7;
  }

  .brand-logo,
  .brand-expand {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: opacity 0.18s ease, transform 0.18s ease;
  }

  /* Обе иконки занимают одно место — смена идёт через opacity, без скачка. */
  .brand-expand {
    position: absolute;
    inset: 0;
    opacity: 0;
    transform: scale(0.75);
    pointer-events: none;
  }

  .rail:not(.expanded) .rail-brand:hover .brand-logo,
  .rail:not(.expanded) .rail-brand:focus-visible .brand-logo {
    opacity: 0;
    transform: scale(0.9);
  }

  .rail:not(.expanded) .rail-brand:hover .brand-expand,
  .rail:not(.expanded) .rail-brand:focus-visible .brand-expand {
    opacity: 1;
    transform: scale(1);
  }

  .rail-brand {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    text-decoration: none;
    font-weight: 600;
  }

  .rail:not(.expanded) .rail-brand {
    position: relative;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    transition: border-color 0.18s ease, background-color 0.18s ease;
  }

  .rail-brand span {
    font-size: 16px;
  }

  .rail-head-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 0 0 auto;
  }

  /* Иконки шапки Expanded — без обводки, размер как у иконки раскрытия. */
  .rail-search,
  .rail-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.15s ease, background-color 0.15s ease;
  }

  .rail-search:hover,
  .rail-search[aria-expanded='true'],
  .rail-toggle:hover {
    color: var(--primary);
    background: var(--fill-tertiary);
  }

  .rail-nav,
  .tree {
    flex: 1 1 auto;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 8px;
  }

  .rail-channels {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 0 0 auto;
    padding: 6px;
    border-bottom: 1px solid var(--border-secondary);
  }

  .rail:not(.expanded) .rail-channels {
    flex-direction: column;
  }

  .rail-channel {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    padding: 0;
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.15s ease, background-color 0.15s ease;
  }

  .rail-channel:hover,
  .rail-channel.has-new {
    color: var(--primary);
    background: var(--fill-tertiary);
  }

  .channel-badge {
    position: absolute;
    top: 1px;
    right: 1px;
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

  .rail-group {
    display: flex;
    flex-direction: column;
    margin-bottom: 6px;
  }

  .rail:not(.expanded) .rail-nav {
    padding: 6px;
  }

  .rail:not(.expanded) .rail-group {
    align-items: center;
    margin-bottom: 3px;
  }

  .group-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    margin-bottom: 2px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .group-button:hover,
  .group-button[aria-expanded='true'] {
    background: var(--fill-tertiary);
    color: var(--text);
  }

  /* Активный блок в Compact выделяется только цветом иконки. */
  .group-button.current {
    color: var(--primary);
  }

  .tree-group {
    margin-bottom: 10px;
  }

  .tree-group + .tree-group {
    padding-top: 8px;
    border-top: 1px solid color-mix(in srgb, var(--border) 45%, transparent);
  }

  .tree-group-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 40px;
    padding: 0 10px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-align: left;
    text-transform: uppercase;
    cursor: pointer;
  }

  .tree-group-toggle:hover {
    background: var(--fill-tertiary);
    color: var(--text);
  }

  .tree-group-toggle span {
    flex: 1 1 auto;
  }

  .tree-group-body {
    margin-top: 2px;
  }

  [hidden] {
    display: none !important;
  }

  .tree-node-row {
    display: flex;
    align-items: center;
    gap: 2px;
    padding-left: calc(12px + var(--tree-depth, 0) * 16px);
  }

  .tree-link {
    position: relative;
    display: flex;
    flex: 1 1 auto;
    align-items: center;
    gap: 10px;
    min-width: 0;
    min-height: 40px;
    padding: 0 10px;
    border-radius: var(--radius-sm);
    color: var(--muted);
    font-size: 13px;
    text-decoration: none;
  }

  .tree-link:hover {
    background: var(--fill-tertiary);
    color: var(--text);
  }

  .tree-link.active {
    background: color-mix(in srgb, var(--primary) 8%, var(--surface));
    color: var(--primary);
    font-weight: 600;
  }

  .tree-link span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tree-children {
    display: block;
  }

  .rail-dot {
    width: 8px;
    height: 8px;
    margin-left: auto;
    border-radius: 50%;
    background: var(--primary);
  }

  .tree-badge {
    min-width: 18px;
    height: 18px;
    margin-left: auto;
    padding: 0 5px;
    border-radius: 9px;
    background: var(--danger);
    color: #fff;
    font-size: 10px;
    line-height: 18px;
    text-align: center;
  }

  .rail-foot {
    flex: 0 0 auto;
    padding: 8px;
    border-top: 1px solid var(--border-secondary);
  }

  .rail-foot-actions {
    display: flex;
    gap: 4px;
  }

  .rail-foot button {
    display: flex;
    flex: 1 1 0;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 0;
    min-height: 36px;
    padding: 0 6px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
  }

  .rail-foot button span:last-child {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .theme-mark {
    width: 14px;
    height: 14px;
    flex: 0 0 auto;
    border: 2px solid currentColor;
    border-radius: 50%;
    background: transparent;
  }

  .theme-mark.dark {
    background: currentColor;
    box-shadow: inset -4px -2px 0 0 var(--surface);
  }

  .rail-foot button:hover,
  .rail-foot button[aria-pressed='true'] {
    color: var(--primary);
    background: var(--fill-tertiary);
  }

  .rail.compact-dense .tree-group-toggle,
  .rail.compact-dense .tree-link {
    min-height: 36px;
  }

  .rail.compact-dense .tree-node-row {
    padding-top: 0;
    padding-bottom: 0;
  }

  .rail-error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 12px 8px;
    color: var(--muted);
    font-size: 12px;
    text-align: center;
  }

  .flyout {
    position: fixed;
    top: 64px;
    z-index: 70;
    width: 240px;
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-md);
  }

  .flyout-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 4px 4px 8px;
  }

  .flyout-head strong {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--muted);
  }

  .flyout-head button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
  }

  .flyout .tree-node-row {
    padding-left: 0;
  }

  .flyout .tree-link {
    min-height: 44px;
    font-size: 12px;
  }

  @media (max-width: 767px) {
    .rail,
    .flyout {
      display: none;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .rail,
    .rail-toggle,
    .rail-search,
    .rail-brand,
    .brand-logo,
    .brand-expand {
      transition: none;
    }
  }
</style>
