/** Дескриптор колонки простого справочника (без поиска и сортировки на сервере). */
export interface DirectoryField<T> {
  key: string;
  label: string;
  headClass?: string;
  cellClass?: string;
  kind?: 'title' | 'code' | 'text' | 'count' | 'state';
  href?: (row: T) => string;
  description?: (row: T) => string | undefined;
  on?: (row: T) => boolean;
  value: (row: T) => string;
}

export function directoryValue<T>(field: DirectoryField<T>, row: T): string {
  return field.value(row) || '—';
}
