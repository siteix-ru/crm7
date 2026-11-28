import { formatDateTime } from '../format';
import type { StaffUser } from '../api/staff';

export type ViewMode = 'list' | 'tiles';

export interface StaffField {
  key: string;
  label: string;
  required?: boolean;
  minWidth?: number;
  width?: string;
  headLabel?: string;
  headClass?: string;
  cellClass?: string;
  value: (row: StaffUser) => string;
}

export const STAFF_FIELDS: StaffField[] = [
  {
    key: 'name',
    label: 'Сотрудник',
    headLabel: 'Сотрудник',
    required: true,
    value: (row) => row.name
  },
  {
    key: 'role',
    label: 'Роль',
    minWidth: 720,
    width: 'minmax(110px, 0.8fr)',
    headClass: 'role-head',
    cellClass: 'role',
    value: (row) => staffLevelTitle(row.level)
  },
  {
    key: 'contacts',
    label: 'Контакты',
    minWidth: 720,
    width: 'minmax(180px, 1.4fr)',
    headClass: 'contacts-head',
    cellClass: 'contacts',
    value: (row) => [row.phone, row.email].filter(Boolean).join(' · ')
  },
  {
    key: 'organization',
    label: 'Организация',
    minWidth: 1024,
    width: 'minmax(160px, 1.2fr)',
    headClass: 'org-head',
    cellClass: 'org',
    value: (row) => [row.department, row.city, row.office].filter(Boolean).join(' · ')
  },
  {
    key: 'position',
    label: 'Должность',
    minWidth: 1024,
    width: 'minmax(130px, 1fr)',
    headClass: 'position-head',
    cellClass: 'position',
    value: (row) => row.position || '—'
  },
  {
    key: 'registered',
    label: 'Регистрация',
    minWidth: 1200,
    width: '140px',
    headClass: 'seen-head',
    cellClass: 'seen',
    value: (row) => formatDateTime(row.created_at)
  },
  {
    key: 'last_seen',
    label: 'Последний вход',
    minWidth: 900,
    width: '140px',
    headClass: 'seen-head',
    cellClass: 'seen',
    value: (row) => (row.last_seen_at ? formatDateTime(row.last_seen_at) : '—')
  },
  {
    key: 'status',
    label: 'Статус',
    headLabel: 'Статус',
    required: true,
    value: (row) => (row.active ? 'Активен' : 'Заблокирован')
  }
];

export interface StaffTileField {
  key: string;
  rows: (row: StaffUser) => Array<{ label: string; value: string }>;
}

export const STAFF_TILE_FIELDS: StaffTileField[] = [
  {
    key: 'role',
    rows: (row) => [{ label: 'Роль', value: staffLevelTitle(row.level) }]
  },
  {
    key: 'contacts',
    rows: (row) => [
      { label: 'Телефон', value: row.phone || '—' },
      { label: 'E-mail', value: row.email || '—' }
    ]
  },
  {
    key: 'organization',
    rows: (row) => [
      {
        label: 'Организация',
        value: [row.department, row.city, row.office].filter(Boolean).join(' · ') || '—'
      }
    ]
  },
  {
    key: 'position',
    rows: (row) => [{ label: 'Должность', value: row.position || '—' }]
  },
  {
    key: 'registered',
    rows: (row) => [{ label: 'Регистрация', value: formatDateTime(row.created_at) }]
  },
  {
    key: 'last_seen',
    rows: (row) => [{ label: 'Последний вход', value: row.last_seen_at ? formatDateTime(row.last_seen_at) : '—' }]
  }
];

export function staffTileFields(keys: string[]): StaffTileField[] {
  return STAFF_TILE_FIELDS.filter((field) => keys.includes(field.key));
}

function staffLevelTitle(level: number): string {
  if (level >= 90) return 'Технический администратор';
  if (level >= 50) return 'Администратор';
  return 'Менеджер';
}

export const STAFF_OPTIONAL_KEYS = STAFF_FIELDS.filter((field) => !field.required).map(
  (field) => field.key
);

export function normalizeStaffFields(value: unknown): Record<ViewMode, string[]> {
  const normalize = (raw: unknown): string[] => {
    const keys = Array.isArray(raw) ? raw : STAFF_OPTIONAL_KEYS;
    return STAFF_OPTIONAL_KEYS.filter((key) => keys.includes(key));
  };

  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const stored = value as Record<string, unknown>;
    return { list: normalize(stored.list), tiles: normalize(stored.tiles) };
  }

  const legacy = normalize(value);
  return { list: legacy, tiles: [...legacy] };
}

export function initialStaffViewMode(
  view: string | undefined,
  viewExplicit: boolean | undefined,
  width: number
): ViewMode {
  // Legacy-записи без viewExplicit сохраняли выбранный вид напрямую — чтим его.
  if (viewExplicit === undefined) {
    return view === 'tiles' ? 'tiles' : 'list';
  }
  return (viewExplicit && view === 'tiles') || (!viewExplicit && width <= 720) ? 'tiles' : 'list';
}

/** Legacy-запись: view сохранён, а viewExplicit ещё отсутствует. */
export function isLegacyStaffView(raw: unknown): boolean {
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    return false;
  }
  const saved = raw as Record<string, unknown>;
  return (saved.view === 'list' || saved.view === 'tiles') && saved.viewExplicit === undefined;
}

export function visibleStaffFields(fields: string[], width: number): string[] {
  return STAFF_FIELDS.filter(
    (field) =>
      !field.required &&
      fields.includes(field.key) &&
      (field.minWidth === undefined || width > field.minWidth)
  ).map((field) => field.key);
}

export function listStaffFields(fields: string[], width: number): StaffField[] {
  const visible = visibleStaffFields(fields, width);
  return STAFF_FIELDS.filter((field) => !field.required && visible.includes(field.key));
}

export function hiddenStaffFields(fields: string[], width: number): string[] {
  return STAFF_OPTIONAL_KEYS.filter(
    (key) => fields.includes(key) && !visibleStaffFields(fields, width).includes(key)
  );
}

export function staffMetaFields(row: StaffUser, keys: string[]): Array<{ label: string; value: string }> {
  return STAFF_FIELDS.filter((field) => !field.required && keys.includes(field.key)).map((field) => ({
    label: field.label,
    value: field.value(row) || '—'
  }));
}
