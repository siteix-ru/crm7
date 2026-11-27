import { formatDate } from '../format';

export type ViewMode = 'list' | 'tiles';

export interface ClientRow {
  name?: string;
  company?: string | null;
  phone?: string | null;
  email?: string | null;
  city?: string | null;
  address?: string | null;
  manager?: { name: string } | null;
  registered_at?: string | null;
  inn?: string | null;
  requests_total?: number;
}

export type FieldKind = 'text' | 'email';
export type TileRegion = 'title' | 'row' | 'foot';

/**
 * Единственная точка описания поля: чекбокс в «Виде», колонка таблицы,
 * строка/подвал плитки и мета-строка мобильного списка.
 */
export interface ViewField {
  key: string;
  label: string;
  required?: boolean;
  minWidth?: number;
  width?: string;
  headClass?: string;
  headLabel?: string;
  cellClass?: string;
  kind?: FieldKind;
  muted?: boolean;
  ellipsis?: boolean;
  empty?: string;
  tileRegion?: TileRegion;
  tileClass?: string;
  primary?: boolean;
  value?: (client: ClientRow) => string;
  title?: (client: ClientRow) => string | undefined;
}

const emailValue = (client: ClientRow): string =>
  [client.email, [client.city, client.address].filter(Boolean).join(', ')]
    .filter(Boolean)
    .join(', ');

/** Порядок совпадает с порядком чекбоксов в модалке «Вид». */
export const VIEW_FIELDS: ViewField[] = [
  {
    key: 'name',
    label: 'Название',
    headLabel: 'Клиент',
    required: true,
    value: (client) => client.name ?? '—'
  },
  {
    key: 'company',
    label: 'Компания',
    minWidth: 1200,
    width: 'minmax(120px, 1.1fr)',
    headClass: 'ct-company',
    cellClass: 'ct-company',
    ellipsis: true,
    tileRegion: 'title',
    tileClass: 'tile-company',
    empty: 'Без компании',
    value: (client) => client.company ?? '',
    title: (client) => client.company || undefined
  },
  {
    key: 'phone',
    label: 'Телефон',
    minWidth: 720,
    width: 'minmax(130px, 1fr)',
    headClass: 'ct-phone',
    cellClass: 'ct-phone',
    ellipsis: true,
    tileRegion: 'row',
    primary: true,
    value: (client) => client.phone ?? '—',
    title: (client) => client.phone || undefined
  },
  {
    key: 'email',
    label: 'Email и адрес',
    minWidth: 1024,
    width: 'minmax(170px, 1.3fr)',
    headClass: 'ct-email',
    cellClass: 'ct-email',
    kind: 'email',
    muted: true,
    tileRegion: 'row',
    value: emailValue,
    title: (client) => emailValue(client) || undefined
  },
  {
    key: 'manager',
    label: 'Менеджер',
    minWidth: 720,
    width: 'minmax(110px, 1fr)',
    headClass: 'ct-manager',
    cellClass: 'ct-manager',
    ellipsis: true,
    tileRegion: 'foot',
    tileClass: 'tile-manager',
    empty: 'без менеджера',
    value: (client) => client.manager?.name ?? '',
    title: (client) => client.manager?.name ?? undefined
  },
  {
    key: 'requests',
    label: 'Заявки',
    headLabel: 'Заявки',
    required: true,
    headClass: 'num',
    value: (client) => String(client.requests_total ?? 0)
  },
  {
    key: 'registered',
    label: 'Регистрация',
    minWidth: 900,
    width: '104px',
    headClass: 'ct-date',
    cellClass: 'ct-date',
    muted: true,
    tileRegion: 'foot',
    tileClass: 'tile-date',
    value: (client) => (client.registered_at ? formatDate(client.registered_at) : '—')
  },
  { key: 'status', label: 'Статус', headLabel: 'Статус', required: true }
];

export const OPTIONAL_KEYS = VIEW_FIELDS.filter((field) => !field.required).map(
  (field) => field.key
);

/** Legacy:contacts разворачивается в phone+email, остальные значения проходят whitelist. */
export function normalizeViewFields(value: unknown): Record<ViewMode, string[]> {
  const normalizeList = (raw: unknown): string[] => {
    const keys = Array.isArray(raw) ? raw : OPTIONAL_KEYS;
    const expanded = keys.includes('contacts') ? [...keys, 'phone', 'email'] : keys;
    return OPTIONAL_KEYS.filter((key) => expanded.includes(key));
  };

  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const stored = value as Record<string, unknown>;
    return { list: normalizeList(stored.list), tiles: normalizeList(stored.tiles) };
  }

  const legacy = normalizeList(value);
  return { list: legacy, tiles: [...legacy] };
}

/**
 * Начальный вид: явно сохранённый выбор имеет приоритет; иначе узкий экран
 * получает плитки, а широкий — список. Неявно сохранённые плитки игнорируются.
 */
export function initialViewMode(
  view: string | undefined,
  viewExplicit: boolean | undefined,
  width: number
): ViewMode {
  return (viewExplicit === true && view === 'tiles') ||
    (viewExplicit !== true && width <= 720)
    ? 'tiles'
    : 'list';
}

/** Поля, у которых на данной ширине рендерится отдельная колонка. */
export function visibleListFields(fields: string[], viewportWidth: number): string[] {
  return VIEW_FIELDS.filter(
    (field) =>
      !field.required &&
      fields.includes(field.key) &&
      (field.minWidth === undefined || viewportWidth > field.minWidth)
  ).map((field) => field.key);
}

/** Дескрипторы колонок таблицы в порядке реестра. */
export function listColumns(fields: string[], viewportWidth: number): ViewField[] {
  const visible = visibleListFields(fields, viewportWidth);
  return VIEW_FIELDS.filter((field) => !field.required && visible.includes(field.key));
}

/** Дескрипторы плиток по зоне: заголовок, строки или подвал. */
export function tileFields(fields: string[], region: TileRegion): ViewField[] {
  return VIEW_FIELDS.filter(
    (field) => field.tileRegion === region && fields.includes(field.key)
  );
}

/** Мета-сегменты строк: ровно запрошенные ключи с подписью и полным значением. */
export function clientMetaFields(
  client: ClientRow,
  keys: string[],
  format: (value: string) => string = formatDate
): Array<{ key: string; label: string; value: string }> {
  const values: Record<string, string> = {
    company: client.company || '—',
    phone: client.phone || '—',
    email: emailValue(client) || '—',
    manager: client.manager?.name || '—',
    registered: client.registered_at ? format(client.registered_at) : '—'
  };

  return VIEW_FIELDS.filter((field) => !field.required && keys.includes(field.key)).map(
    (field) => ({ key: field.key, label: field.label, value: values[field.key] ?? '—' })
  );
}
