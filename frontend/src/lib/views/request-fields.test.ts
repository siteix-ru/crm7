import { describe, expect, it } from 'vitest';
import {
  REQUEST_FIELDS,
  REQUEST_OPTIONAL_KEYS,
  normalizeRequestFields,
  initialRequestViewMode,
  listRequestFields,
  hiddenRequestFields,
  requestTileFields,
  requestMetaFields
} from './request-fields';
import type { RequestItem } from '../api/types';

const row = {
  number: 'R-1',
  subject: 'Тестовая заявка',
  client: { name: 'ООО Ромашка' },
  manager: { name: 'Иван' },
  items_count: 3,
  due_at: null,
  is_overdue: false,
  status: { title: 'Новая' },
  created_at: '2026-11-01T00:00:00Z'
} as RequestItem;

describe('request fields', () => {
  it('normalizes legacy field lists', () => {
    expect(normalizeRequestFields(['client', 'unknown'])).toEqual({
      list: ['client'],
      tiles: ['client']
    });
  });

  it('honors legacy saved view and new explicit view', () => {
    expect(initialRequestViewMode('tiles', undefined, 1440)).toBe('tiles');
    expect(initialRequestViewMode('list', false, 360)).toBe('tiles');
    expect(initialRequestViewMode('list', true, 360)).toBe('list');
  });

  it('derives columns and hidden fields from width and rights', () => {
    const selected = [...REQUEST_OPTIONAL_KEYS];
    const cols = listRequestFields(selected, 1440, true);
    expect(cols.map((field) => field.key)).toEqual(REQUEST_OPTIONAL_KEYS);
    expect(listRequestFields(selected, 1440, false).some((field) => field.key === 'client')).toBe(false);
    expect(hiddenRequestFields(selected, 720, true).length).toBeGreaterThan(0);
  });

  it('keeps head/cell parity via the same registry', () => {
    const required = REQUEST_FIELDS.filter((field) => field.required);
    expect(required.map((field) => field.key)).toEqual(['number', 'subject', 'status']);
  });

  it('drives tile rows and meta segments', () => {
    expect(requestTileFields(['client', 'positions'], true, 1440).map((field) => field.key)).toEqual([
      'client',
      'positions'
    ]);
    expect(requestTileFields(['client'], false, 1440)).toEqual([]);
    expect(requestTileFields(['client'], true, 720)).toEqual([]);
    expect(requestTileFields(['client'], true, 1440)[0].rows(row)[0].value).toBe('ООО Ромашка');
    expect(requestMetaFields(row, ['client', 'manager'], true).map((field) => field.value)).toEqual([
      'ООО Ромашка',
      'Иван'
    ]);
  });
});
