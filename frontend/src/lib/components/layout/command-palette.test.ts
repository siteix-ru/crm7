import { describe, expect, it } from 'vitest';
import palette from './CommandPalette.svelte?raw';
import store from '../../stores/command-palette.svelte.ts?raw';
import shell from './AppShell.svelte?raw';
import rail from './AppRail.svelte?raw';
import quick from './QuickActions.svelte?raw';

describe('Phase 3 Command Palette contract', () => {
  it('opens with Ctrl+K and Cmd+K through one store instance', () => {
    expect(palette).toContain('event.ctrlKey || event.metaKey');
    expect(palette).toContain("event.key.toLowerCase() === 'k'");
    expect(palette).toContain('commandPalette.toggle()');
    expect(store).toContain('class CommandPaletteStore');
    expect(store).toContain('open = $state(false)');
    expect(shell).toContain('<CommandPalette />');
    expect(rail).not.toContain('<NavSearchModal');
    expect(rail).toContain('commandPalette.openPalette()');
  });

  it('does not steal the shortcut from input fields', () => {
    expect(palette).toContain('function isTypingTarget');
    expect(palette).toContain('target.isContentEditable');
    expect(palette).toContain("target.tagName === 'INPUT'");
    expect(palette).toContain("target.tagName === 'TEXTAREA'");
    expect(palette).toContain("target.tagName === 'SELECT'");
    expect(palette).toContain('if (isTypingTarget(event.target)) {\n        return;');
  });

  it('has a single modal dialog with accessible combobox and listbox', () => {
    expect(palette).toContain('role="dialog"');
    expect(palette).toContain('aria-modal="true"');
    expect(palette).toContain('aria-labelledby="palette-title"');
    expect(palette).toContain('role="combobox"');
    expect(palette).toContain('aria-activedescendant={activeId}');
    expect(palette).toContain('role="listbox"');
    expect(palette).toContain('role="option"');
    expect(palette).toContain('aria-selected={index === activeIndex}');
    expect(palette).toContain('aria-live="polite"');
    expect(palette).toContain('previouslyFocused?.focus?.()');
  });

  it('merges three sources with category labels and no duplicates', () => {
    for (const label of ['Недавние', 'Страницы', 'Действия', 'Система']) {
      expect(palette).toContain(label);
    }
    expect(palette).toContain('buildPaletteResults(');
    expect(palette).toContain('recentPages.entries');
    expect(palette).toContain('sectionsFromNav(appSettings.visibleNav)');
    expect(palette).toContain('buildPaletteResults(');
    expect(palette).toContain("import { quickActions, type QuickActionContext }");
    expect(palette).toContain('Ничего не найдено');
    expect(palette).toContain('class:hit={part.hit}');
  });

  it('re-checks rights on execution and respects Escape priority', () => {
    expect(palette).toContain('canRunResult(result, context, allowed)');
    expect(palette).toContain('recentPages.clear()');
    expect(palette).toContain('event.stopPropagation()');
    expect(rail).toContain('commandPalette.open');
    expect(shell).toContain('if (commandPalette.open) {');
    expect(quick).toContain('commandPalette.open');
  });

  it('supports keyboard navigation, mobile width and reduced motion', () => {
    for (const key of ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', 'Escape']) {
      expect(palette).toContain(`event.key === '${key}'`);
    }
    expect(palette).toContain("event.key !== 'Tab'");
    expect(palette).toContain('onDialogKeydown');
    expect(palette).toContain('event.target === inputEl');
    expect(palette).toContain('event.shiftKey');
    expect(palette).toContain('@media (max-width: 767px)');
    expect(palette).toContain('max-height: calc(100dvh - 24px)');
    expect(palette).toContain('@media (prefers-reduced-motion: reduce)');
  });

  it('keeps existing navigation features working', () => {
    expect(shell).toContain('nodes={appSettings.visibleNav}');
    expect(rail).toContain('role="dialog"');
    expect(rail).toContain('sectionsFromNav(nodes)');
    expect(shell).toContain('<QuickActions inline onNavigate={closeMobileMenu} />');
    expect(shell).toContain("window.matchMedia('(max-width: 767px)')");
  });
});
