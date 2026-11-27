/**
 * Phase 2C.1 — безопасные внутренние переходы.
 *
 * Чистые функции без состояния: accepted-route allowlist строится вызывающей
 * стороной из актуального visibleNav и прав пользователя. localStorage здесь
 * не является источником прав.
 */

export const MAX_RETURN_TO_LENGTH = 128;

const SAFE_PATH = /^\/[A-Za-z0-9\-_/]*$/;
const SAFE_QUERY = /^[A-Za-z0-9_=&%\-./:@+,~]*$/;
const CONTROL_CHARS = /[\u0000-\u001f\u007f]/;

/** Принимает только внутренний маршрут CRM7 (`/…` или `#/…`). */
export function internalRoute(value: unknown): string | null {
  if (typeof value !== 'string' || value.length === 0 || value.length > MAX_RETURN_TO_LENGTH) {
    return null;
  }

  if (CONTROL_CHARS.test(value)) {
    return null;
  }

  let raw = value.trim();
  if (raw.startsWith('#')) {
    raw = raw.slice(1);
  }

  if (!raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\')) {
    return null;
  }
  if (/^(https?:|javascript:|data:)/i.test(raw)) {
    return null;
  }

  const [path, query = ''] = raw.split('?');
  if (!SAFE_PATH.test(path) || (query !== '' && !SAFE_QUERY.test(query))) {
    return null;
  }

  // В URL не должны попадать секреты и параметры авторизации.
  if (query !== '') {
    for (const [key] of new URLSearchParams(query)) {
      if (/password|token|secret|api_?key|authorization|card|cvv/i.test(key)) {
        return null;
      }
    }
  }

  return raw;
}

export function routePath(route: string): string {
  return route.split('?')[0];
}

/** Allowlist доступных маршрутов из серверного visibleNav. */
export function allowedRoutesFromNav(nodes: { route: string }[]): Set<string> {
  const allowed = new Set<string>();
  for (const node of nodes) {
    const route = internalRoute(node.route);
    if (route !== null) {
      allowed.add(routePath(route));
    }
  }
  // Профиль — существующий внутренний маршрут, защищённый теми же правами в App.svelte.
  allowed.add('/profile');
  return allowed;
}

/**
 * Возвращает безопасный returnTo или null. Проверяются схема, длина, allowlist
 * и циклический возврат на текущий маршрут.
 */
export function normalizeReturnTo(
  value: unknown,
  allowed: Set<string> | null,
  currentPath: string | null
): string | null {
  const route = internalRoute(value);
  if (route === null) {
    return null;
  }

  const path = routePath(route);
  if (currentPath !== null && path === currentPath && !route.includes('?')) {
    return null;
  }
  if (allowed !== null && !allowed.has(path)) {
    return null;
  }

  return route;
}

/** Первый доступный пользователю маршрут вместо жёстко заданного fallback. */
export function fallbackRoute(allowed: Set<string>, preferred = '/requests'): string | null {
  if (allowed.has(preferred)) {
    return preferred;
  }
  for (const route of allowed) {
    if (route !== '/profile') {
      return route;
    }
  }
  return null;
}

/** Прикрепляет returnTo к переходу из списка в карточку. */
export function withReturnTo(route: string, from: string): string {
  const target = internalRoute(route);
  const source = internalRoute(from);
  if (target === null || source === null) {
    return route;
  }

  const value = encodeURIComponent(source).slice(0, MAX_RETURN_TO_LENGTH);
  const separator = target.includes('?') ? '&' : '?';
  if (`${target}${separator}returnTo=${value}`.length > MAX_RETURN_TO_LENGTH * 2) {
    return target;
  }
  return `${target}${separator}returnTo=${value}`;
}

/** Удаляет все локальные состояния навигации текущего пользователя. */
export function clearLocalNavigationState(userId: number | null): void {
  try {
    const keys: string[] = [];
    for (let index = 0; index < localStorage.length; index += 1) {
      const key = localStorage.key(index);
      if (key === null) {
        continue;
      }
      if (key.startsWith('crm.nav2.') || (userId !== null && key.startsWith(`crm.filters.${userId}.`))) {
        keys.push(key);
      }
    }
    keys.forEach((key) => localStorage.removeItem(key));
  } catch {
    // приватный режим — нечего очищать
  }
}
