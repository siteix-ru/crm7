import { describe, expect, it } from 'vitest';
import type { StockLevel } from '../api/types';
import { groupStockLevels, sortStockColumns } from './columns';

function level(id: number, warehouseId: number, name: string, overrides: Partial<StockLevel> = {}): StockLevel {
  return {
    id,
    name,
    unit: 'шт',
    quantity: 1,
    description: '',
    actual_date: null,
    price: null,
    group_id: null,
    group_title: '',
    warehouse_id: warehouseId,
    warehouse_name: `Склад ${warehouseId}`,
    ...overrides
  };
}

describe('groupStockLevels', () => {
  it('joins one nomenclature row across warehouses', () => {
    const rows = groupStockLevels([
      level(1, 10, 'Товар'),
      level(2, 20, 'Товар'),
      level(3, 30, 'Товар')
    ]);

    expect(rows).toHaveLength(1);
    expect([...rows[0].levels.keys()]).toEqual([10, 20, 30]);
  });

  it('keeps different units and groups apart', () => {
    const rows = groupStockLevels([
      level(1, 10, 'Товар'),
      level(2, 10, 'Товар', { unit: 'кг' }),
      level(3, 10, 'Товар', { group_id: 7, group_title: 'Группа' })
    ]);

    expect(rows).toHaveLength(3);
  });

  it('keeps a missing warehouse absent from the row', () => {
    const [row] = groupStockLevels([level(1, 10, 'Товар')]);

    expect(row.levels.get(20)).toBeUndefined();
  });
});

describe('sortStockColumns', () => {
  it('applies terms sequentially', () => {
    const rows = groupStockLevels([
      level(1, 10, 'A', { quantity: 5 }),
      level(2, 10, 'B', { quantity: 1 }),
      level(3, 10, 'C', { quantity: 5 })
    ]);

    expect(sortStockColumns(rows, ['warehouse_10_asc', 'name_desc']).map((row) => row.name)).toEqual([
      'B',
      'C',
      'A'
    ]);
  });

  it('keeps rows without the warehouse at the end', () => {
    const rows = groupStockLevels([
      level(1, 10, 'A', { quantity: 1 }),
      level(2, 20, 'B', { quantity: 5 })
    ]);

    expect(sortStockColumns(rows, ['warehouse_10_asc']).map((row) => row.name)).toEqual(['A', 'B']);
    expect(sortStockColumns(rows, ['warehouse_10_desc']).map((row) => row.name)).toEqual(['A', 'B']);
  });
});
