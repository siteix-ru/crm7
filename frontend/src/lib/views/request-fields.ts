import { formatDate, formatDateTime } from '../format';
import type { RequestItem } from '../api/types';

export type ViewMode = 'list' | 'tiles';

export interface RequestField {
  key: string;
  label: string;
  required?: boolean;
  staffOnly?: boolean;
  minWidth?: number;
  width?: string;
  headLabel?: string;
  headClass?: string;
  cellClass?: string;
  value: (row: RequestItem) => string;
}

export const REQUEST_FIELDS: RequestField[] = [
  {
    key: 'number',
    label: 'Номер',
    headLabel: 'Номер',
    required: true,
    value: (row) => row.number
  },
  {
    key: 'subject',
    label: 'Тема',
    headLabel: 'Тема',
    required: true,
    value: (row) => row.subject
  },
  {
    key: 'client',
    label: 'Клиент',
    minWidth: 1200,
    staffOnly: true,
    width: 'minmax(160px, 1.2fr)',
    headClass: 'col-client',
    cellClass: 'col-client',
    value: (row) => row.client.name
  },
  {
    key: 'manager',
    label: 'Менеджер',
    minWidth: 1024,
    width: 'minmax(140px, 1fr)',
    headClass: 'col-manager',
    cellClass: 'col-manager',
    value: (row) => row.manager?.name ?? 'поиск менеджера'
  },
  {
    key: 'positions',
    label: 'Номенклатуры',
    minWidth: 900,
    width: '110px',
    headClass: 'col-count',
    cellClass: 'col-count',
    value: (row) => String(row.items_count)
  },
  {
    key: 'status',
    label: 'Статус',
    headLabel: 'Статус',
    required: true,
    value: (row) => row.status.title
  },
  {
    key: 'overdue',
    label: 'Просрочка',
    minWidth: 1200,
    width: '110px',
    headClass: 'col-overdue',
    cellClass: 'col-overdue',
    value: (row) => (row.is_overdue ? 'Просрочена' : '—')
  },
  {
    key: 'created',
    label: 'Дата',
    minWidth: 720,
    width: '150px',
    headClass: 'col-date',
    cellClass: 'col-date',
    value: (row) => formatDateTime(row.created_at)
  }
];

export const REQUEST_OPTIONAL_KEYS = REQUEST_FIELDS.filter((field) => !field.required).map(
  (field) => field.key
);

const fieldLabel = (key: string): string =>
  REQUEST_FIELDS.find((field) => field.key === key)?.label ?? key;

export interface RequestTileField {
  key: string;
  staffOnly?: boolean;
  minWidth?: number;
  rows: (row: RequestItem) => Array<{ label: string; value: string }>;
}

export const REQUEST_TILE_FIELDS: RequestTileField[] = [
  {
    key: 'client',
    staffOnly: true,
    minWidth: 720,
    rows: (row) => [{ label: fieldLabel('client'), value: row.client.name }]
  },
  {
    key: 'manager',
    minWidth: 720,
    rows: (row) => [{ label: fieldLabel('manager'), value: row.manager?.name ?? 'поиск менеджера' }]
  },
  {
    key: 'positions',
    minWidth: 900,
    rows: (row) => [{ label: fieldLabel('positions'), value: `${row.items_count} поз.` }]
  },
  {
    key: 'overdue',
    rows: (row) => (row.is_overdue ? [{ label: fieldLabel('overdue'), value: 'Просрочена' }] : [])
  },
  {
    key: 'created',
    minWidth: 720,
    rows: (row) => [{ label: fieldLabel('created'), value: formatDate(row.created_at) }]
  }
];

export function normalizeRequestFields(value: unknown): Record<ViewMode, string[]> {
  const normalize = (raw: unknown): string[] => {
    const keys = Array.isArray(raw) ? raw : REQUEST_OPTIONAL_KEYS;
    return REQUEST_OPTIONAL_KEYS.filter((key) => keys.includes(key));
  };

  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const stored = value as Record<string, unknown>;
    return { list: normalize(stored.list), tiles: normalize(stored.tiles) };
  }

  const legacy = normalize(value);
  return { list: legacy, tiles: [...legacy] };
}

export function initialRequestViewMode(
  view: string | undefined,
  viewExplicit: boolean | undefined,
  width: number
): ViewMode {
  if (viewExplicit === undefined) {
    return view === 'tiles' ? 'tiles' : 'list';
  }
  return (viewExplicit && view === 'tiles') || (!viewExplicit && width <= 720) ? 'tiles' : 'list';
}

export function listRequestFields(
  fields: string[],
  width: number,
  isStaff: boolean
): RequestField[] {
  return REQUEST_FIELDS.filter(
    (field) =>
      !field.required &&
      fields.includes(field.key) &&
      (!field.staffOnly || isStaff) &&
      (field.minWidth === undefined || width > field.minWidth)
  );
}

export function hiddenRequestFields(
  fields: string[],
  width: number,
  isStaff: boolean
): string[] {
  return REQUEST_OPTIONAL_KEYS.filter(
    (key) =>
      fields.includes(key) &&
      (!listRequestFields(fields, width, isStaff).some((field) => field.key === key))
  );
}

export function requestTileFields(
  keys: string[],
  isStaff: boolean,
  width: number
): RequestTileField[] {
  return REQUEST_TILE_FIELDS.filter(
    (field) =>
      keys.includes(field.key) &&
      (!field.staffOnly || isStaff) &&
      (field.minWidth === undefined || width > field.minWidth)
  );
}

export function requestMetaFields(
  row: RequestItem,
  keys: string[],
  isStaff: boolean
): Array<{ label: string; value: string }> {
  return REQUEST_FIELDS.filter(
    (field) => !field.required && keys.includes(field.key) && (!field.staffOnly || isStaff)
  ).map((field) => ({ label: field.label, value: field.value(row) || '—' }));
}
