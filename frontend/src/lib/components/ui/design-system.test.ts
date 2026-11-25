// @ts-ignore — test-only Node API, not part of the browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const appCss = readFileSync(new URL('../../../app.css', import.meta.url), 'utf8');
import button from './Button.svelte?raw';
import pager from './Pager.svelte?raw';
import settings from '../../../views/Settings.svelte?raw';
import notifications from '../../../views/Notifications.svelte?raw';
import chat from '../../../views/Chat.svelte?raw';

describe('design system consistency', () => {
  it('defines the radius token used by shared controls', () => {
    expect(appCss).toContain('--radius-xs: 4px');
  });

  it('uses the accessible primary color for hover text', () => {
    const block = button.slice(button.indexOf('.ghost:hover'), button.indexOf('.text {'));
    expect(block).toMatch(/\n\s*color:\s*var\(--primary\);/);
    expect(block).not.toMatch(/\n\s*color:\s*var\(--primary-hover\);/);
  });

  it('marks the current page in pagination', () => {
    expect(pager).toMatch(/\.page-btn\.active\s*\{[^}]*background:\s*var\(--primary-bg\)/s);
  });

  it('keeps the settings sidebar short on phones', () => {
    expect(settings).toMatch(/@media \(max-width: 720px\)\s*\{\s*\.settings-sidebar\s*\{[^}]*max-height:\s*168px/s);
  });

  it('prevents notification text from stretching cards', () => {
    expect(notifications).toContain('-webkit-line-clamp: 2');
    expect(notifications).toMatch(/\.title\s*\{[^}]*min-width:\s*0/s);
  });

  it('keeps the chat composer inside the viewport', () => {
    expect(chat).toMatch(/\.composer textarea\s*\{[^}]*min-width:\s*0/s);
  });
});
