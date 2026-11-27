/** Phase 4 — персональные настройки навигации, привязанные к пользователю. */

export type NavDensity = 'comfortable' | 'compact';

interface NavPrefs {
  railExpanded: boolean;
  density: NavDensity;
}

const PREFIX = 'crm.nav2.prefs.';

function empty(): NavPrefs {
  return { railExpanded: false, density: 'comfortable' };
}

class NavPrefsStore {
  railExpanded = $state(false);

  density = $state<NavDensity>('comfortable');

  private userId: number | null = null;

  private key(): string {
    return this.userId === null ? `${PREFIX}guest` : `${PREFIX}${this.userId}`;
  }

  setUser(userId: number | null): void {
    this.userId = userId;
    this.railExpanded = false;
    this.density = 'comfortable';

    try {
      const raw = localStorage.getItem(this.key());
      if (raw === null) {
        return;
      }
      const parsed = JSON.parse(raw) as Partial<NavPrefs> | null;
      if (parsed?.railExpanded === true) {
        this.railExpanded = true;
      }
      if (parsed?.density === 'compact' || parsed?.density === 'comfortable') {
        this.density = parsed.density;
      }
    } catch {
      // повреждённые или недоступные настройки — безопасные значения по умолчанию
    }
  }

  private persist(): void {
    try {
      localStorage.setItem(
        this.key(),
        JSON.stringify({ railExpanded: this.railExpanded, density: this.density })
      );
    } catch {
      // приватный режим — настройки действуют до перезагрузки
    }
  }

  setRailExpanded(value: boolean): void {
    this.railExpanded = value;
    this.persist();
  }

  setDensity(value: NavDensity): void {
    this.density = value;
    this.persist();
  }

  toggleDensity(): void {
    this.setDensity(this.density === 'compact' ? 'comfortable' : 'compact');
  }
}

export const navPrefs = new NavPrefsStore();
