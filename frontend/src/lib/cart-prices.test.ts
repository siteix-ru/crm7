import { describe, expect, it } from 'vitest';
import { buildPriceUpdates, cartItemKey } from './cart-prices';

describe('cart prices', () => {
  it('builds a stable key from warehouse and product name', () => {
    expect(cartItemKey({ warehouseId: 7, name: 'Товар' })).toBe('7|Товар');
  });

  it('maps prices to the keys captured before the request', () => {
    const keys = ['1|Товар A', '2|Товар B'];

    expect(buildPriceUpdates(keys, [100, null])).toEqual([
      { key: '1|Товар A', price: 100 },
      { key: '2|Товар B', price: null }
    ]);
  });

  it('rejects an incomplete response instead of substituting zeroes', () => {
    expect(buildPriceUpdates(['1|Товар A', '2|Товар B'], [100])).toBeNull();
    expect(buildPriceUpdates(['1|Товар A'], [])).toBeNull();
  });

  it('treats a missing price as unknown, not as zero', () => {
    expect(buildPriceUpdates(['1|Товар A'], [undefined])).toEqual([
      { key: '1|Товар A', price: null }
    ]);
  });
});
