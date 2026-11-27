import { describe, expect, it } from 'vitest';
import adapter from './NavSearchModal.svelte?raw';
import palette from './CommandPalette.svelte?raw';
import rail from './AppRail.svelte?raw';

describe('NavSearchModal compatibility adapter (Phase 3)', () => {
  it('delegates to the single Command Palette', () => {
    expect(adapter).toContain("import CommandPalette from './CommandPalette.svelte'");
    expect(adapter).toContain('<CommandPalette');
    expect(adapter).toContain('commandPalette.openPalette()');
    expect(adapter).not.toContain('role="dialog"');
    expect(adapter).not.toContain('class="search-overlay"');
  });

  it('is not mounted by the rail any more', () => {
    expect(rail).not.toContain('NavSearchModal');
    expect(rail).toContain('commandPalette.openPalette()');
  });

  it('keeps the shared search logic in one place', () => {
    expect(palette).toContain('highlightParts(result.label, query)');
    expect(palette).toContain('sectionsFromNav(');
  });
});
