import { describe, expect, it } from 'vitest';
import rail from './AppRail.svelte?raw';
import shell from './AppShell.svelte?raw';
import store from '../../stores/app-settings.svelte.ts?raw';
import icons from '../ui/Icon.svelte?raw';

describe('Navigation 2.0 Phase 2A tree rail', () => {
  it('renders the rail from visibleNav, not from a second frontend list', () => {
    expect(shell).toContain('nodes={appSettings.visibleNav}');
    expect(shell).toContain('<AppRail');
    expect(shell).not.toContain('<nav class="desktop-nav"');
    expect(shell).toContain('class="mobile-nav"');
    expect(store).toContain('normalizeNav(data.visibleNav)');
    expect(store).toContain('navError');
  });

  it('does not duplicate visibleNav with legacy frontend visibility flags', () => {
    for (const legacy of ['showClients', 'showStaff', 'showSettings', 'showAudit', 'showStockLots', 'showReports', 'showMyReports', 'mobileServices']) {
      expect(shell).not.toContain(legacy);
    }
    expect(shell).toContain('node.presentation.bottom');
    expect(shell).toContain('node.presentation.drawer');
  });

  it('keeps Compact by default with manual Expanded from 768', () => {
    expect(shell).toContain("matchMedia('(min-width: 768px)')");
    expect(shell).toContain('railExpanded ? 240 : 56');
    expect(shell).toContain('if (!canExpandRail && railExpanded)');
    expect(rail).toContain('style:width={`${railWidth}px`}');
  });

  it('uses one breakpoint rule: Compact from 768, mobile below 768', () => {
    expect(shell).toContain("window.matchMedia('(max-width: 767px)')");
    expect(shell).toContain('@media (max-width: 767px)');
    expect(rail).toContain('@media (max-width: 767px)');
    expect(shell).not.toContain('720px');
    expect(rail).not.toContain('719px');
  });

  it('builds blocks, subsections and pages from the shared registry tree', () => {
    expect(rail).toContain('sectionsFromNav(nodes)');
    expect(rail).toContain('{@render treeNodes(');
    expect(rail).toContain('type TreeNode = SearchTreeNode');
  });

  it('keeps accordion states independent, per user and pruned with visibleNav', () => {
    expect(rail).toContain('accordion.groups[code] === true');
    expect(rail).toContain('accordion.sections[id] === true');
    expect(rail).toContain('function toggleGroup');
    expect(rail).toContain('aria-expanded={isGroupOpen(group.code)}');
    expect(rail).toContain('aria-controls={`nav-tree-${group.code}`}');
    expect(rail).not.toContain('tree-chevron');
    expect(rail).not.toContain('arrow-down');
    expect(rail).toContain('const currentUser = $derived(String(auth.user?.id');
    expect(rail).toContain('crm.nav2.accordion.');
    expect(rail).toContain('if (!groupCodes.has(key))');
    expect(rail).toContain('findActive(root, currentPath)');
    expect(rail).toContain('untrack(() =>');
  });

  it('distinguishes the group Flyout from terminal links and supports keyboard/focus', () => {
    expect(rail).toContain('aria-label={`Группа «${group.title}»`}');
    expect(rail).toContain('aria-expanded={flyoutGroup === group.code}');
    expect(rail).toContain('aria-controls="nav-flyout"');
    expect(rail).toContain('role="dialog"');
    expect(rail).toContain('onkeydown={onWindowKeydown}');
    expect(rail).toContain('onpointerdown={onWindowPointerDown}');
    expect(rail).toContain('title={group.title}');
    expect(rail).not.toContain('showTooltip');
    expect(rail).not.toContain('class="tooltip"');
    expect(rail).toContain('closeFlyout()');
  });

  it('keeps status channels in the rail with no topbar', () => {
    expect(rail).toContain('class="rail-channels"');
    expect(rail).toContain('channelNodes');
    expect(rail).toContain('channelCount(node)');
    expect(shell).not.toContain('class="topbar"');
    expect(shell).not.toContain('topbar-search');
    expect(shell).not.toContain('search-hint');
    expect(shell).not.toContain('class="name"');
    expect(shell).not.toContain('class="back-button"');
    expect(shell).not.toContain('<QuickActions />');
    expect(shell).not.toContain('Ctrl+K');
    expect(rail).toContain("router.navigate('/profile')");
  });

  it('opens the single Command Palette from the Expanded rail icon', () => {
    expect(rail).toContain('class="rail-search"');
    expect(rail).toContain('aria-label="Поиск по разделам"');
    expect(rail).toContain('aria-expanded={commandPalette.open}');
    expect(rail).toContain('onclick={openSearch}');
    expect(rail).toContain('searchBtnEl?.focus()');
    expect(rail).toContain('commandPalette.openPalette()');
    expect(rail).not.toContain('<NavSearchModal');
    expect(rail).toContain('if (!expanded) {\n      commandPalette.close();');
    expect(rail).toContain("event.key !== 'Escape' || commandPalette.open");
  });

  it('polishes the expanded header and tree rows', () => {
    expect(rail).toContain('class="rail-head-actions"');
    expect(rail).toContain('.rail-search,\n  .rail-toggle {');
    expect(rail).toContain('width: 32px;');
    expect(rail).toContain('.tree-group + .tree-group');
    expect(rail).toContain('calc(12px + var(--tree-depth, 0) * 16px)');
    expect(rail).not.toContain('.tree-link.active::before');
    expect(rail).not.toContain('border-left: 1px solid var(--border-secondary)');
    expect(rail).not.toContain('arrow-down');
  });

  it('polishes the group affordance and per-user density', () => {
    expect(rail).toContain('class:compact-dense={navPrefs.density === \'compact\'}');
    expect(rail).toContain('navPrefs.toggleDensity()');
    expect(rail).toContain('class="rail-foot-actions"');
    expect(rail).toContain('theme.toggle()');
    expect(rail).toContain('class="theme-mark"');
    expect(rail).toContain('class:expand-affordance={!expanded && canExpand && logoHover}');
    expect(rail).toContain('class:expand-disabled={!expanded && !canExpand && logoHover}');
    expect(rail).toContain('<span class="brand-expand"><Icon name="menu-unfold" size={18} /></span>');
    expect(rail).toContain('<Icon name="menu-fold" size={18} />');
    expect(icons).toContain("'menu-fold': MenuFoldOutlined");
    expect(icons).toContain("'menu-unfold': MenuUnfoldOutlined");
    expect(rail).toContain('.rail:not(.expanded) .rail-brand:hover .brand-expand');
    expect(rail).toContain('transition: opacity 0.18s ease, transform 0.18s ease');
    expect(rail).toContain('{#if canExpand}');
    expect(rail).toContain('<span class="brand-logo"><Logo size={22} /></span>');
    expect(rail).toContain('<span>CRM7</span>');
    expect(rail).not.toContain('<Logo size={expanded ? 26 : 22} />');
    expect(rail).toContain('.rail:not(.expanded) .rail-head');
    expect(rail).toContain('.rail:not(.expanded) .rail-group');
    expect(rail).toContain('.rail.compact-dense .tree-link');
    expect(rail).not.toContain('group-dots');
    expect(rail).not.toContain('.group-button.current::before');
    expect(rail).toContain('.group-button.current {\n    color: var(--primary);');
  });

  it('supports reduced motion and independent tree scrolling', () => {
    expect(rail).toContain('@media (prefers-reduced-motion: reduce)');
    expect(rail).toContain('overscroll-behavior: contain');
    expect(shell).toContain('transition: margin-left 0.18s ease');
  });
});
