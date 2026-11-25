import { describe, expect, it } from 'vitest';

const sources = import.meta.glob('./AppShell.svelte', {
  query: '?raw',
  import: 'default',
  eager: true
});
const source = sources['./AppShell.svelte'] as string;

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

describe('AppShell header at compact desktop widths', () => {
  it('switches navigation to icons by 1180px so actions fit at 1024px', () => {
    expect(mediaBlock(1180)).toContain('.desktop-nav .nav-label');
    expect(mediaBlock(1180)).toContain('display: none');
  });

  it('keeps the user/action block from being pushed out of the viewport', () => {
    expect(source).toMatch(/\.user\s*\{[^}]*flex:\s*0 0 auto/s);
    expect(source).toMatch(/\.left\s*\{[^}]*min-width:\s*0/s);
  });

  it('does not postpone label collapsing until the tablet breakpoint', () => {
    expect(source).not.toMatch(/@media\s*\(max-width:\s*980px\)\s*\{\s*\.desktop-nav\s+\.nav-label/);
  });
});
