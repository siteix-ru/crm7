import type { StockLevel } from '../api/types';

export type StockViewMode = 'list' | 'tiles' | 'columns';

export const STOCK_VIEW_MODES: StockViewMode[] = ['list', 'tiles', 'columns'];

export interface StockField {
  key: string;
  label: string;
  value: (row: StockLevel) => string;
}

/** Дополнительные поля, видимость которых настраивается отдельно для каждого вида. */
export const STOCK_FIELDS: StockField[] = [
  { key: 'article', label: 'Артикул', value: (row) => row.article || '' },
  { key: 'warehouse', label: 'Склад', value: (row) => row.warehouse_name || '' },
  { key: 'group', label: 'Группа', value: (row) => row.group_title || '' },
  { key: 'unit', label: 'Единица', value: (row) => row.unit || '' },
  { key: 'description', label: 'Описание', value: (row) => row.description || '' }
];

export const STOCK_FIELD_KEYS = STOCK_FIELDS.map((field) => field.key);

export function normalizeStockFields(value: unknown): Record<StockViewMode, string[]> {
  const normalize = (raw: unknown): string[] => {
    const keys = Array.isArray(raw) ? raw : STOCK_FIELD_KEYS;
    return STOCK_FIELD_KEYS.filter((key) => keys.includes(key));
  };

  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const stored = value as Record<string, unknown>;
    return {
      list: normalize(stored.list),
      tiles: normalize(stored.tiles),
      columns: normalize(stored.columns)
    };
  }

  const legacy = normalize(value);
  return { list: legacy, tiles: [...legacy], columns: [...legacy] };
}

export function stockFields(keys: string[]): StockField[] {
  return STOCK_FIELDS.filter((field) => keys.includes(field.key));
}
