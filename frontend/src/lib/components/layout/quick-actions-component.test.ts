import { describe, expect, it } from 'vitest';
import component from './QuickActions.svelte?raw';
import shell from './AppShell.svelte?raw';
import search from './NavSearchModal.svelte?raw';
import rail from './AppRail.svelte?raw';

describe('Phase 2C.2 Quick Actions component contract', () => {
  it('is a compact menu, not a second modal system', () => {
    expect(component).toContain('role="menu"');
    expect(component).not.toContain('aria-modal="true"');
    expect(component).not.toContain('role="dialog"');
    expect(component).toContain('aria-label="Быстрые действия"');
    expect(component).toContain('aria-expanded={open}');
    expect(component).toContain('aria-controls="quick-actions-menu"');
    expect(component).toContain('hidden={!open}');
  });

  it('re-checks rights before navigation and closes on outside interaction', () => {
    expect(component).toContain('canRunQuickAction(action.id, context)');
    expect(component).toContain('router.navigate(action.route)');
    expect(component).toContain('onpointerdown={onPointerDown}');
    expect(component).toContain('close(false)');
    expect(component).toContain('close();');
  });

  it('supports keyboard navigation, visible focus and focus return', () => {
    for (const key of ['Escape', 'ArrowDown', 'ArrowUp', 'Home']) {
      expect(component).toContain(`event.key === '${key}'`);
    }
    expect(component).toContain('event.key === \'End\'');
    expect(component).toContain('triggerEl?.focus()');
    expect(component).toContain('buttons[(current + 1) % buttons.length].focus()');
    expect(component).toContain('@media (prefers-reduced-motion: reduce)');
  });

  it('is available in the mobile drawer, not in the desktop topbar', () => {
    expect(shell).not.toContain('<QuickActions />');
    expect(shell).toContain('<QuickActions inline onNavigate={closeMobileMenu} />');
    expect(component).toContain('@media (min-width: 768px)');
    expect(shell).toContain('class="mobile-nav"');
    expect(shell).not.toContain('Ctrl+K');
    expect(shell).not.toContain('search-hint');
    expect(search).toContain('<CommandPalette');
  });

  it('keeps navigation and search regression markers', () => {
    expect(shell).toContain('nodes={appSettings.visibleNav}');
    expect(rail).toContain('sectionsFromNav(nodes)');
    expect(shell).toContain('allowedRoutesFromNav(appSettings.visibleNav)');
  });
});
