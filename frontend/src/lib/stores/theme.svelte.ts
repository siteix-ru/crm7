const STORAGE_KEY = 'crm.theme';

class ThemeStore {
  dark = $state(false);

  constructor() {
    try {
      this.dark = localStorage.getItem(STORAGE_KEY) === 'dark';
    } catch {
      this.dark = false;
    }

    this.apply();
  }

  toggle(): void {
    this.dark = !this.dark;
    this.apply();

    try {
      localStorage.setItem(STORAGE_KEY, this.dark ? 'dark' : 'light');
    } catch {
      // приватный режим — тема действует до перезагрузки
    }
  }

  private apply(): void {
    if (typeof document === 'undefined') {
      return;
    }

    document.documentElement.dataset.theme = this.dark ? 'dark' : 'light';
  }
}

export const theme = new ThemeStore();
