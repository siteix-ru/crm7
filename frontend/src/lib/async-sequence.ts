/**
 *Sequence guard для асинхронных загрузок.
 *
 * Каждая загрузка получает токен; записать результат можно только если
 * токен всё ещё актуален. Позволяет не допускать перезаписи свежих данных
 * запоздавшими ответами без отмены самих запросов.
 */
export class Sequence {
  private current = 0;

  next(): number {
    this.current += 1;

    return this.current;
  }

  isCurrent(token: number): boolean {
    return token === this.current;
  }
}
