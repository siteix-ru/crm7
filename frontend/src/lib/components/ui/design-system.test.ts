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

  it('keeps primary hover background at AA contrast with its text', () => {
    const light = appCss.slice(0, appCss.indexOf(":root[data-theme='dark']"));
    const background = light.match(/--primary-hover:\s*(#[0-9a-f]{6})/i)?.[1];
    const foreground = light.match(/--on-primary:\s*(#[0-9a-f]{6})/i)?.[1];
    expect(background).toBeTruthy();
    expect(foreground).toBeTruthy();

    const luminance = (hex: string): number => {
      const channels = hex
        .slice(1)
        .match(/../g)!
        .map((value) => Number.parseInt(value, 16) / 255)
        .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
      return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    };
    const a = luminance(background!);
    const b = luminance(foreground!);
    const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

    expect(ratio).toBeGreaterThanOrEqual(4.5);
  });
});
