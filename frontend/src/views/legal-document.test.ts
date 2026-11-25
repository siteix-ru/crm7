import { describe, expect, it } from 'vitest';
import legal from './Legal.svelte?raw';
import cookieNotice from '../lib/components/CookieNotice.svelte?raw';

describe('legal documents', () => {
  it('reloads the document when the route code changes', () => {
    expect(legal).toMatch(/\$effect\(\(\) => \{\s*void load\(code\);/);
    expect(legal).not.toContain('onMount');
  });

  it('keeps the two cookie notice links distinct', () => {
    expect(cookieNotice).toContain("router.navigate('/legal/privacy')");
    expect(cookieNotice).toContain("router.navigate('/legal/terms')");
  });
});
