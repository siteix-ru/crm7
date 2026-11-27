import { describe, expect, it } from 'vitest';
import { cycleSortTerm, summarizeSort } from './sort-terms';

const fields = [
  { field: 'name', label: 'Название' },
  { field: 'last_seen', label: 'Последний вход' },
  { field: 'warehouse_12', label: 'Основной склад' }
];

describe('sort terms', () => {
  it('cycles one field: off → asc → desc → off', () => {
    let terms: string[] = [];
    terms = cycleSortTerm(terms, 'name');
    expect(terms).toEqual(['name_asc']);
    terms = cycleSortTerm(terms, 'name');
    expect(terms).toEqual(['name_desc']);
    terms = cycleSortTerm(terms, 'name');
    expect(terms).toEqual([]);
  });

  it('keeps the inclusion order and replaces direction in place', () => {
    let terms = cycleSortTerm([], 'name');
    terms = cycleSortTerm(terms, 'last_seen');
    terms = cycleSortTerm(terms, 'last_seen');
    expect(terms).toEqual(['name_asc', 'last_seen_desc']);
  });

  it('summarizes fields whose names contain underscores', () => {
    expect(summarizeSort(['last_seen_asc', 'warehouse_12_desc'], fields)).toBe(
      'Последний вход ↑, Основной склад ↓'
    );
    expect(summarizeSort([], fields)).toBe('без сортировки');
  });
});
