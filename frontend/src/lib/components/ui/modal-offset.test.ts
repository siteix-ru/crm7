// @ts-ignore — тестовый Node API, не часть browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const modal = readFileSync(new URL('./Modal.svelte', import.meta.url), 'utf8');
const app = readFileSync(new URL('../../../app.css', import.meta.url), 'utf8');
const shell = readFileSync(new URL('../layout/AppShell.svelte', import.meta.url), 'utf8');
const palette = readFileSync(new URL('../layout/CommandPalette.svelte', import.meta.url), 'utf8');
const multiSelect = readFileSync(new URL('./MultiSelect.svelte', import.meta.url), 'utf8');
const filtersModal = readFileSync(new URL('./FiltersModal.svelte', import.meta.url), 'utf8');
const button = readFileSync(new URL('./Button.svelte', import.meta.url), 'utf8');

describe('модалки с боковым рейлом', () => {
  it('центрируются в рабочей области, а не в окне браузера', () => {
    expect(modal).toContain('padding-left: calc(var(--rail-width, 0px) + var(--space-4))');
  });

  it('вне оболочки рейла нет', () => {
    expect(app).toContain('--rail-width: 0px;');
  });

  it('на мобильном остаётся шторка снизу без сдвига', () => {
    expect(modal).toContain('place-items: end center');
  });

  it('горизонтальные отступы модалки симметричны', () => {
    expect(app).not.toMatch(/\.overlay \.dialog-body \{[\s\S]*?scrollbar-gutter/);
    expect(app).not.toMatch(/\.overlay \.dialog-body \.modal-actions \{[^}]*padding-right:/);
    expect(modal).toContain('scrollbar-width: thin');
  });

  it('фокус полей мягкий и не выходит за границы', () => {
    expect(app).toContain('--focus-soft: color-mix(in srgb, var(--primary) 95%, var(--surface))');
    expect(app).toContain(':where(.filter-form, .filters-form) .filter-field input:not([type=\'checkbox\']):not([type=\'radio\']):focus-visible');
    expect(app).toContain('box-shadow: inset 0 0 0 2px var(--focus-soft)');
    expect(app).toContain('border-color: var(--primary-border)');
    expect(app).not.toContain('outline-offset: 2px;\n}\n\n.filter-form');
  });

  it('поля в модалках имеют единую высоту и отступы', () => {
    expect(app).toContain('height: var(--control-height-lg)');
    expect(app).toContain('padding: 0 var(--space-4)');
    expect(app).toContain('gap: var(--field-label-gap)');
    expect(multiSelect).toContain('.trigger.lg');
    expect(multiSelect).toContain('height: var(--control-height-lg)');
  });

  it('эталонная модалка использует общие токены и API', () => {
    expect(app).toContain('--control-height-lg: 40px');
    expect(app).toContain('--field-label-gap: var(--space-3)');
    expect(app).toContain('--filters-group-gap: var(--space-5)');
    expect(app).toMatch(/\.overlay \.dialog-body \.modal-actions \{\r?\n  position: sticky;/);
    expect(app).toContain('@media (pointer: coarse)');
    expect(filtersModal).toContain('export async function announce(text: string): Promise<void>');
    expect(filtersModal).toContain('const result = await onapply()');
    expect(filtersModal).toContain('dirty?: boolean;');
    expect(filtersModal).toContain("size = 'md'");
    expect(filtersModal).toContain('variant="text" size="lg"');
    expect(button).toContain("size?: 'sm' | 'md' | 'lg'");
    expect(multiSelect).toContain('aria-haspopup="dialog"');
    expect(multiSelect).toContain('aria-controls={menuId}');
    expect(multiSelect).toContain('@media (pointer: coarse)');
    expect(modal).toContain('element.getClientRects().length > 0');
    expect(app).toContain('min-width: 24px');
    expect(app).toMatch(/\.menu-trigger \{[\s\S]*?width: 36px;/);
    expect(app).toContain('inset: -4px;');
    expect(app).toMatch(/\.skip-link \{[\s\S]*?color: var\(--on-primary\)/);
    expect(app).toContain('.view-field-check:focus-within');
  });

  it('command palette вне оболочки тоже знает ширину рейла', () => {
    expect(palette).toContain('padding-left: calc(var(--rail-width, 0px) + 12px)');
    expect(shell).toContain('style:--rail-width={`${railWidth}px`}');
    expect(shell.indexOf('<CommandPalette />')).toBeGreaterThan(
      shell.indexOf('<div style:--rail-width')
    );
  });
});
