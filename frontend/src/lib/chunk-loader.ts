export type ChunkStatus = 'loading' | 'ready' | 'error';

/**
 * Загрузка ленивого компонента с конечным числом повторов.
 *
 * Состояния разделены: после исчерпания попыток наступает `error`,
 * а не бесконечный `loading`. Повторный `start()` запускает загрузку заново.
 */
export class ChunkLoader {
  private cancelled = false;
  private timer: ReturnType<typeof setTimeout> | undefined;

  constructor(
    private readonly load: () => Promise<unknown>,
    private readonly onChange: (status: ChunkStatus, value: unknown | null) => void,
    private readonly retries = 2,
    private readonly delayMs = 1200
  ) {}

  start(): void {
    this.cancel();
    this.cancelled = false;
    this.onChange('loading', null);
    this.run(0);
  }

  private run(attempt: number): void {
    this.load()
      .then((module) => {
        if (!this.cancelled) {
          this.onChange('ready', module);
        }
      })
      .catch(() => {
        if (this.cancelled) {
          return;
        }

        if (attempt < this.retries) {
          this.timer = setTimeout(() => this.run(attempt + 1), this.delayMs);

          return;
        }

        this.onChange('error', null);
      });
  }

  cancel(): void {
    this.cancelled = true;
    clearTimeout(this.timer);
  }
}
