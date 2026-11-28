import { describe, expect, it } from 'vitest';
import {
  STAFF_FIELDS,
  STAFF_OPTIONAL_KEYS,
  initialStaffViewMode,
  listStaffFields,
  hiddenStaffFields,
  normalizeStaffFields,
  isLegacyStaffView,
  staffTileFields,
  staffMetaFields
} from './staff-fields';
import type { StaffUser } from '../api/staff';

const row = {
  name: 'Иван Иванов',
  level: 50,
  phone: '+7999',
  email: 'ivan@example.test',
  department: 'Отдел',
  city: 'Москва',
  office: 'Офис',
  position: 'Менеджер',
  created_at: '2026-01-01T00:00:00Z',
  last_seen_at: '2026-11-27T00:00:00Z',
  active: true
} as StaffUser;

describe('staff fields', () => {
  it('restores auto view only when saved choice was explicit', () => {
    expect(initialStaffViewMode('tiles', false, 1440)).toBe('list');
    expect(initialStaffViewMode('tiles', false, 360)).toBe('tiles');
    expect(initialStaffViewMode('tiles', true, 1440)).toBe('tiles');
    expect(initialStaffViewMode('list', true, 360)).toBe('list');
  });

  it('keeps legacy saved view without viewExplicit', () => {
    expect(isLegacyStaffView({ view: 'tiles' })).toBe(true);
    expect(isLegacyStaffView({ view: 'list', viewExplicit: false })).toBe(false);
    expect(isLegacyStaffView(null)).toBe(false);
    expect(initialStaffViewMode('tiles', undefined, 1440)).toBe('tiles');
    expect(initialStaffViewMode('list', undefined, 360)).toBe('list');
    expect(initialStaffViewMode('list', false, 360)).toBe('tiles');
  });

  it('renders tile rows only for enabled tile fields', () => {
    const all = staffTileFields(['role', 'contacts', 'position']);
    expect(all.map((field) => field.key)).toEqual(['role', 'contacts', 'position']);
    expect(all[1].rows(row).map((item) => item.label)).toEqual(['Телефон', 'E-mail']);
    expect(staffTileFields(['role']).map((field) => field.key)).toEqual(['role']);
  });

  it('normalizes legacy field lists', () => {
    expect(normalizeStaffFields(['role', 'unknown'])).toEqual({
      list: ['role'],
      tiles: ['role']
    });
  });

  it('keeps header/cell parity by deriving both from the registry', () => {
    for (const width of [360, 720, 721, 900, 1024, 1200, 1441]) {
      const columns = listStaffFields([...STAFF_OPTIONAL_KEYS], width);
      const hidden = hiddenStaffFields([...STAFF_OPTIONAL_KEYS], width);
      expect(columns.length + hidden.length).toBe(STAFF_OPTIONAL_KEYS.length);
    }
  });

  it('describes hidden fields with full values', () => {
    const meta = staffMetaFields(row, ['contacts', 'organization', 'last_seen']);
    expect(meta.map((field) => field.label)).toEqual(['Контакты', 'Организация', 'Последний вход']);
    expect(meta[0].value).toBe('+7999 · ivan@example.test');
    expect(meta[1].value).toBe('Отдел · Москва · Офис');
    expect(STAFF_FIELDS.filter((field) => field.required).map((field) => field.key)).toEqual([
      'name',
      'status'
    ]);
  });
});
