import { describe, expect, it } from 'vitest';
import { maxPage } from './pagination';

describe('maxPage', () => {
  it('keeps one page when per_page is 0 (Все)', () => {
    expect(maxPage(70, 0)).toBe(1);
    expect(maxPage(0, 0)).toBe(1);
  });

  it('rounds up and never returns zero', () => {
    expect(maxPage(70, 20)).toBe(4);
    expect(maxPage(0, 20)).toBe(1);
    expect(maxPage(1, 100)).toBe(1);
  });
});
