import { describe, expect, it } from 'vitest';

const sources = import.meta.glob('./CookieNotice.svelte', {
  query: '?raw',
  import: 'default',
  eager: true
});
const source = sources['./CookieNotice.svelte'] as string;

function mediaBlock(maxWidth: number): string {
  const start = source.indexOf(`@media (max-width: ${maxWidth}px) {`);
  expect(start, `media query ${maxWidth}px must exist`).toBeGreaterThan(-1);
  let depth = 0;
  for (let i = source.indexOf('{', start); i < source.length; i++) {
    if (source[i] === '{') depth++;
    if (source[i] === '}') {
      depth--;
      if (depth === 0) return source.slice(start, i + 1);
    }
  }
  return '';
}

describe('CookieNotice compact layout', () => {
  it('collapses the long notice into one compact row on phones', () => {
    const mobile = mediaBlock(720);
    expect(mobile).toContain('.full');
    expect(mobile).toMatch(/\.full\s*\{\s*display:\s*none/);
    expect(mobile).toContain('.compact');
    expect(mobile).not.toContain('flex-direction: column');
  });

  it('keeps legal links reachable through an expandable details block', () => {
    expect(source).toContain('aria-expanded={expanded}');
    expect(source).toContain('class="details-toggle"');
    expect(source).toMatch(/\{#if expanded\}[\s\S]*class="details"/);
    expect(source).toContain("/legal/privacy");
    expect(source).toContain("/legal/terms");
  });

  it('keeps the accept action and dynamic layout reservation', () => {
    expect(source).toContain('onclick={accept}');
    expect(source).toContain("setProperty('--cookie-space'");
    expect(source).toContain('new ResizeObserver(update)');
  });
});
