/**
 * Состояние сети: баннер «нет подключения» и короткое подтверждение
 * восстановления связи. Слушатели живут всё время работы приложения.
 */
class NetworkState {
  online = $state(true);
  restored = $state(false);
  private timer: ReturnType<typeof setTimeout> | undefined;

  constructor() {
    if (typeof window === 'undefined') {
      return;
    }

    this.online = navigator.onLine;

    window.addEventListener('offline', () => {
      this.online = false;
      this.restored = false;
      clearTimeout(this.timer);
    });

    window.addEventListener('online', () => {
      this.online = true;
      this.restored = true;
      clearTimeout(this.timer);
      this.timer = setTimeout(() => {
        this.restored = false;
      }, 4000);
    });
  }
}

export const network = new NetworkState();
