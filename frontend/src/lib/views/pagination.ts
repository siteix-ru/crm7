/** Число страниц; `per_page = 0` означает «Все» и всегда одну страницу. */
export function maxPage(total: number, perPage: number): number {
  if (perPage <= 0) {
    return 1;
  }
  return Math.max(1, Math.ceil(total / perPage));
}
