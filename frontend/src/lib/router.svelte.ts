export interface RouteState {
  path: string;
  query: URLSearchParams;
}

interface HistoryScrollState {
  crmNavId?: string;
  crmScroll?: number;
}

function normalize(path: string): string {
  if (!path) return '/';
  return path.startsWith('/') ? path : `/${path}`;
}

function parse(hash: string): RouteState {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash;
  const [path, queryString = ''] = raw.split('?');
  return { path: normalize(path), query: new URLSearchParams(queryString) };
}

class Router {
  current = $state<RouteState>(parse(window.location.hash));

  private navSeq = 0;

  private scrollByNav = new Map<string, number>();

  private scrollTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    // Прокрутку восстанавливаем сами, чтобы не дублировать записи истории.
    if ('scrollRestoration' in window.history) {
      window.history.scrollRestoration = 'manual';
    }
    window.addEventListener('hashchange', () => this.onHashChange());
    // Ссылки `<a href="#/…">` меняют hash без router.navigate — позицию
    // всё равно нужно сохранить в текущей записи истории.
    window.addEventListener(
      'scroll',
      () => {
        if (this.scrollTimer !== null) {
          return;
        }
        this.scrollTimer = setTimeout(() => {
          this.scrollTimer = null;
          this.recordScroll();
        }, 120);
      },
      { passive: true }
    );
  }

  private historyState(): HistoryScrollState {
    const state = window.history.state as HistoryScrollState | null;
    return state !== null && typeof state === 'object' ? state : {};
  }

  private navId(): string {
    const state = this.historyState();
    if (typeof state.crmNavId === 'string' && state.crmNavId !== '') {
      return state.crmNavId;
    }
    const id = `nav-${(this.navSeq += 1)}`;
    // replaceState не создаёт новую запись в истории.
    window.history.replaceState({ ...state, crmNavId: id }, '');
    return id;
  }

  private recordScroll(): void {
    const id = this.navId();
    const position = Math.max(0, Math.round(window.scrollY));
    const state = this.historyState();
    if (state.crmScroll !== position) {
      window.history.replaceState({ ...state, crmScroll: position }, '');
    }
    this.scrollByNav.set(id, position);
  }

  private saveScroll(): void {
    this.recordScroll();
  }

  private onHashChange(): void {
    this.current = parse(window.location.hash);
    const id = this.navId();
    const state = this.historyState();
    const stored = typeof state.crmScroll === 'number' ? state.crmScroll : this.scrollByNav.get(id) ?? 0;
    const position = Number.isFinite(stored) && stored >= 0 ? stored : 0;
    this.scrollByNav.set(id, position);

    if (position === 0) {
      return;
    }

    // View загружается лениво: повторяем после отрисовки и завершения чанка.
    const restore = (): void => window.scrollTo(0, position);
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(() => requestAnimationFrame(restore));
    }
    setTimeout(restore, 120);
  }

  navigate(to: string, replace = false): void {
    this.saveScroll();
    const target = `#${normalize(to)}`;
    if (replace) {
      window.location.replace(target);
      this.current = parse(target);
      return;
    }
    window.location.hash = normalize(to);
  }
}

export const router = new Router();

export { matchRoute } from './match-route';
