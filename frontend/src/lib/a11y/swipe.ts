/**
 * Мобильные свайпы: «назад» от левого края и переключение вкладок.
 * Жесты не перехватываются внутри полей ввода и горизонтальных скроллеров.
 */
const IGNORE_SELECTOR = [
  'input',
  'textarea',
  'select',
  '[contenteditable="true"]',
  '[data-no-swipe]',
  '.messages',
  '.threads',
  '.columns-scroll-shell',
  '.columns-scroll-bar',
  '.chart',
  '.tab-scroll',
  '.picker'
].join(', ');

const MIN_DISTANCE = 60;
const EDGE_WIDTH = 24;
const HORIZONTAL_BIAS = 1.5;

function ignored(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest(IGNORE_SELECTOR) !== null;
}

function touchPoint(event: TouchEvent): { x: number; y: number } | null {
  if (event.touches.length !== 1 || ignored(event.target)) {
    return null;
  }

  return { x: event.touches[0].clientX, y: event.touches[0].clientY };
}

/** Свайп вправо от левого края экрана — назад по истории. */
export function swipeBack(node: HTMLElement): { destroy: () => void } {
  let start: { x: number; y: number } | null = null;

  const onStart = (event: TouchEvent): void => {
    const point = touchPoint(event);

    if (point === null || point.x > EDGE_WIDTH) {
      return;
    }

    start = point;
  };

  const onEnd = (event: TouchEvent): void => {
    if (start === null) {
      return;
    }

    const point = start;
    start = null;
    const touch = event.changedTouches[0];
    const dx = touch.clientX - point.x;
    const dy = touch.clientY - point.y;

    if (dx > MIN_DISTANCE && Math.abs(dx) > Math.abs(dy) * HORIZONTAL_BIAS && history.length > 1) {
      history.back();
    }
  };

  node.addEventListener('touchstart', onStart, { passive: true });
  node.addEventListener('touchend', onEnd, { passive: true });

  return {
    destroy(): void {
      node.removeEventListener('touchstart', onStart);
      node.removeEventListener('touchend', onEnd);
    }
  };
}

export interface SwipeTabsHandlers {
  prev?: () => void;
  next?: () => void;
}

/** Свайп влево/вправо — следующая/предыдущая вкладка. */
export function swipeTabs(
  node: HTMLElement,
  handlers: SwipeTabsHandlers
): { update: (next: SwipeTabsHandlers) => void; destroy: () => void } {
  let current = handlers;
  let start: { x: number; y: number } | null = null;

  const onStart = (event: TouchEvent): void => {
    start = touchPoint(event);
  };

  const onEnd = (event: TouchEvent): void => {
    if (start === null) {
      return;
    }

    const point = start;
    start = null;
    const touch = event.changedTouches[0];
    const dx = touch.clientX - point.x;
    const dy = touch.clientY - point.y;

    if (Math.abs(dx) < MIN_DISTANCE || Math.abs(dx) < Math.abs(dy) * HORIZONTAL_BIAS) {
      return;
    }

    if (dx < 0) {
      current.next?.();
    } else {
      current.prev?.();
    }
  };

  node.addEventListener('touchstart', onStart, { passive: true });
  node.addEventListener('touchend', onEnd, { passive: true });

  return {
    update(next: SwipeTabsHandlers): void {
      current = next;
    },
    destroy(): void {
      node.removeEventListener('touchstart', onStart);
      node.removeEventListener('touchend', onEnd);
    }
  };
}
