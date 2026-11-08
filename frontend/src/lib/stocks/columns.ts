import type { StockLevel } from '../api/types';

export type StockColumnRow = {
  key: string;
  name: string;
  unit: string;
  group_id: number | null;
  group_title: string;
  levels: Map<number, StockLevel>;
};

export function groupStockLevels(items: StockLevel[]): StockColumnRow[] {
  const rows = new Map<string, StockColumnRow>();

  for (const level of items) {
    const key = `${level.name}\u0000${level.unit}\u0000${level.group_id ?? ''}`;
    let row = rows.get(key);

    if (!row) {
      row = {
        key,
        name: level.name,
        unit: level.unit,
        group_id: level.group_id,
        group_title: level.group_title,
        levels: new Map()
      };
      rows.set(key, row);
    }

    row.levels.set(level.warehouse_id, level);
  }

  return [...rows.values()];
}

export function sortStockColumns(rows: StockColumnRow[], terms: string[]): StockColumnRow[] {
  return [...rows].sort((a, b) => {
    for (const term of terms) {
      const desc = term.endsWith('_desc');
      const base = term.replace(/_(asc|desc)$/, '');

      if (base === 'name') {
        const cmp = a.name.localeCompare(b.name, 'ru');

        if (cmp !== 0) {
          return desc ? -cmp : cmp;
        }

        continue;
      }

      if (!base.startsWith('warehouse_')) {
        continue;
      }

      const warehouseId = Number(base.slice(10));
      const aq = a.levels.get(warehouseId)?.quantity;
      const bq = b.levels.get(warehouseId)?.quantity;

      if (aq === undefined && bq === undefined) {
        continue;
      }

      if (aq === undefined) {
        return 1;
      }

      if (bq === undefined) {
        return -1;
      }

      const cmp = aq - bq;

      if (cmp !== 0) {
        return desc ? -cmp : cmp;
      }
    }

    return 0;
  });
}
