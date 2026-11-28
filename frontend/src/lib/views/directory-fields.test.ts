import { describe, expect, it } from 'vitest';
import { directoryValue, type DirectoryField } from './directory-fields';

interface Row {
  title: string;
  code: string;
}

const fields: DirectoryField<Row>[] = [
  { key: 'title', label: 'Название', value: (row) => row.title },
  { key: 'code', label: 'Код', kind: 'code', value: (row) => row.code },
  {
    key: 'enabled',
    label: 'Доступен',
    kind: 'state',
    on: (row) => row.code !== '',
    value: () => 'Доступен'
  }
];

describe('directory fields', () => {
  it('renders a dash for empty values and keeps non-empty ones', () => {
    expect(directoryValue(fields[0], { title: 'Склад', code: '' })).toBe('Склад');
    expect(directoryValue(fields[1], { title: 'Склад', code: '' })).toBe('—');
  });

  it('keeps labels, kinds and state predicates in descriptors', () => {
    expect(fields.map((field) => field.label)).toEqual(['Название', 'Код', 'Доступен']);
    expect(fields[1].kind).toBe('code');
    expect(fields[2].on?.({ title: 'Склад', code: 'main' })).toBe(true);
  });
});
