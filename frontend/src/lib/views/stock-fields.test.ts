import { describe, expect, it } from 'vitest';
import {
  STOCK_FIELDS,
  STOCK_VIEW_MODES,
  normalizeStockFields,
  stockFields
} from './stock-fields';

describe('stock fields', () => {
  it('normalizes legacy lists into independent per-view lists', () => {
    expect(normalizeStockFields(['article'])).toEqual({
      list: ['article'],
      tiles: ['article'],
      columns: ['article']
    });
  });

  it('keeps per-view lists separate', () => {
    const saved = normalizeStockFields({
      list: ['article', 'unknown'],
      tiles: ['group'],
      columns: ['unit']
    });
    expect(saved).toEqual({ list: ['article'], tiles: ['group'], columns: ['unit'] });
  });

  it('exposes all modes and filters descriptors', () => {
    expect(STOCK_VIEW_MODES).toEqual(['list', 'tiles', 'columns']);
    expect(stockFields(['article', 'group']).map((field) => field.key)).toEqual([
      'article',
      'group'
    ]);
    expect(STOCK_FIELDS.length).toBe(stockFields(STOCK_FIELDS.map((f) => f.key)).length);
  });
});
