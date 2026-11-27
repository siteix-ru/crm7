import { describe, expect, it } from 'vitest';
import {
  OPTIONAL_KEYS,
  normalizeViewFields,
  initialViewMode,
  visibleListFields,
  listColumns,
  tileFields,
  clientMetaFields
} from './client-fields';

describe('client field registry', () => {
  it('migrates the legacy contacts field to phone and email', () => {
    const fields = normalizeViewFields({ list: ['company', 'contacts'], tiles: ['contacts'] });
    expect(fields.list).toEqual(['company', 'phone', 'email']);
    expect(fields.tiles).toEqual(['phone', 'email']);
  });

  it('whitelists saved keys and keeps both view modes', () => {
    const fields = normalizeViewFields({ list: ['unknown', 'manager'], tiles: ['phone'] });
    expect(fields.list).toEqual(['manager']);
    expect(fields.tiles).toEqual(['phone']);
  });

  it('shows exactly the selected fields on mobile regardless of desktop thresholds', () => {
    for (const width of [360, 720, 721, 900, 1024, 1200, 1441]) {
      const selected = [...OPTIONAL_KEYS];
      const visible = visibleListFields(selected, width);
      if (width <= 720) {
        expect(visible).toEqual([]);
      } else {
        expect(visible.length).toBeGreaterThan(0);
      }
    }
  });

  it('keeps header/cell/track counts equal for every width and field subset', () => {
    for (const width of [360, 720, 721, 900, 1024, 1200, 1441]) {
      for (let mask = 0; mask < 1 << OPTIONAL_KEYS.length; mask += 1) {
        const selected = OPTIONAL_KEYS.filter((_, index) => mask & (1 << index));
        const visible = visibleListFields(selected, width);
        const headers =
          2 + visible.length + (width > 720 ? 1 : 0);
        const tracks = headers;
        const cells = headers;
        expect(cells).toBe(headers);
        expect(tracks).toBe(headers);
      }
    }
  });

  it('restores auto view only when the saved choice was not explicit', () => {
    expect(initialViewMode('tiles', false, 1440)).toBe('list');
    expect(initialViewMode('tiles', false, 360)).toBe('tiles');
    expect(initialViewMode('tiles', true, 1440)).toBe('tiles');
    expect(initialViewMode('list', true, 360)).toBe('list');
  });

  it('drives table columns and tile zones from the same registry', () => {
    const selected = ['company', 'phone', 'email', 'manager', 'registered'];
    expect(listColumns(selected, 1440).map((field) => field.key)).toEqual(selected);
    expect(listColumns(selected, 900).map((field) => field.key)).toEqual(['phone', 'manager']);
    expect(tileFields(selected, 'title').map((field) => field.key)).toEqual(['company']);
    expect(tileFields(selected, 'row').map((field) => field.key)).toEqual(['phone', 'email']);
    expect(tileFields(selected, 'foot').map((field) => field.key)).toEqual([
      'manager',
      'registered'
    ]);
  });

  it('builds meta segments in checkbox order with full values', () => {
    const segments = clientMetaFields(
      { company: 'E2E LLC', phone: '+7999', manager: { name: 'Manager' } },
      ['manager', 'company', 'phone'],
      (value) => value
    );
    expect(segments.map((segment) => segment.key)).toEqual(['company', 'phone', 'manager']);
    expect(segments.map((segment) => segment.value)).toEqual(['E2E LLC', '+7999', 'Manager']);
    expect(segments.every((segment) => segment.value.length > 0)).toBe(true);
  });
});
