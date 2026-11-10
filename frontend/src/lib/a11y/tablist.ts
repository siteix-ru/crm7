const NAV_KEYS = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];

/**
 * Единая клавиатурная модель для role="tablist":
 * стрелки ←/→ и Home/End перемещают фокус и активируют вкладку.
 */
export function tablist(node: HTMLElement): { destroy: () => void } {
  const onKeydown = (event: KeyboardEvent): void => {
    if (!NAV_KEYS.includes(event.key)) {
      return;
    }

    const tabs = Array.from(node.querySelectorAll<HTMLElement>('[role="tab"]:not([disabled])'));

    if (tabs.length === 0) {
      return;
    }

    event.preventDefault();

    const current = tabs.indexOf(document.activeElement as HTMLElement);
    let next = current;

    if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = tabs.length - 1;
    } else if (event.key === 'ArrowLeft') {
      next = current <= 0 ? tabs.length - 1 : current - 1;
    } else if (event.key === 'ArrowRight') {
      next = current < 0 || current === tabs.length - 1 ? 0 : current + 1;
    }

    const target = tabs[next];

    target.focus();
    target.click();
  };

  node.addEventListener('keydown', onKeydown);

  return {
    destroy(): void {
      node.removeEventListener('keydown', onKeydown);
    }
  };
}
