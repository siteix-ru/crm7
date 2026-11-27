export interface SortTermField {
  field: string;
  label: string;
}

/** Термины вида `last_seen_asc`: направление — последний сегмент после подчёркивания. */
function splitTerm(term: string): { field: string; dir: string } {
  const at = term.lastIndexOf('_');
  return { field: term.slice(0, at), dir: term.slice(at + 1) };
}

export function summarizeSort(terms: string[], fields: SortTermField[]): string {
  if (terms.length === 0) {
    return 'без сортировки';
  }

  return terms
    .map((term) => {
      const { field, dir } = splitTerm(term);
      const label = fields.find((item) => item.field === field)?.label ?? field;
      return `${label} ${dir === 'asc' ? '↑' : '↓'}`;
    })
    .join(', ');
}

/** Один контрол на поле: выкл → по возрастанию → по убыванию → выкл. */
export function cycleSortTerm(terms: string[], field: string): string[] {
  const asc = `${field}_asc`;
  const desc = `${field}_desc`;
  let next = [...terms];

  if (next.includes(asc)) {
    next = next.map((item) => (item === asc ? desc : item));
  } else if (next.includes(desc)) {
    next = next.filter((item) => item !== desc);
  } else {
    next = [...next, asc];
  }

  return next;
}
