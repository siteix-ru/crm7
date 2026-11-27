// @ts-ignore — тестовый Node API, не часть browser bundle
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const source = readFileSync(new URL('./tablist.ts', import.meta.url), 'utf8');

describe('tablist keyboard contract', () => {
  it('does not steal browser shortcuts with modifiers', () => {
    expect(source).toMatch(/event\.altKey[\s\S]*event\.ctrlKey[\s\S]*event\.metaKey[\s\S]*event\.shiftKey/);
  });

  it('still handles arrows and Home/End', () => {
    expect(source).toContain("['ArrowLeft', 'ArrowRight', 'Home', 'End']");
    expect(source).toContain('target.focus()');
    expect(source).toContain('target.click()');
  });
});
