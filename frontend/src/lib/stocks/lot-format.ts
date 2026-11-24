/** Количества приходят из API канонической строкой DECIMAL(20,5). */

export function formatLotQuantity(raw: string): string {
  const text = String(raw ?? '').trim();

  if (!/^-?\d+(?:\.\d+)?$/.test(text)) {
    return text;
  }

  const [whole, fraction = ''] = text.split('.');
  const trimmed = fraction.replace(/0+$/, '');

  return trimmed === '' ? whole : `${whole}.${trimmed}`;
}

export function lotStatusLabel(status: string): string {
  switch (status) {
    case 'active':
      return 'В наличии';
    case 'reserved':
      return 'Зарезервировано';
    case 'expired':
      return 'Истёк срок';
    case 'empty':
      return 'Пустая';
    default:
      return status;
  }
}

export function lotStatusTone(status: string): 'default' | 'processing' | 'warning' | 'success' {
  switch (status) {
    case 'active':
      return 'success';
    case 'reserved':
      return 'processing';
    case 'expired':
      return 'warning';
    default:
      return 'default';
  }
}

interface LotDistributionResult {
  ok: boolean;
  error?: string;
}

interface LotDistributionLine {
  lot_code: string;
  quantity: string;
}

const SCALE = 100_000n;
const DECIMAL_PATTERN = /^\d{1,13}(?:\.\d{1,5})?$/;

/** Переводит десятичную строку в целое число сотых тысячных без float. */
function toScaled(value: string): bigint | null {
  const text = String(value ?? '').trim();

  if (!DECIMAL_PATTERN.test(text)) {
    return null;
  }

  const [whole, fraction = ''] = text.split('.');
  const padded = (fraction + '00000').slice(0, 5);

  return BigInt(whole) * SCALE + BigInt(padded);
}

function formatScaled(value: bigint): string {
  const whole = value / SCALE;
  const fraction = (value % SCALE).toString().padStart(5, '0').replace(/0+$/, '');

  return fraction === '' ? whole.toString() : `${whole}.${fraction}`;
}

/**
 * Проверяет распределение остатка по партиям: положительные DECIMAL(20,5),
 * уникальные (без учёта регистра) коды и точное совпадение суммы с остатком.
 */
export function parseDistribution(
  lines: LotDistributionLine[],
  expected: string
): LotDistributionResult {
  if (!Array.isArray(lines) || lines.length === 0) {
    return { ok: false, error: 'Добавьте хотя бы одну партию' };
  }

  const expectedScaled = toScaled(expected);

  if (expectedScaled === null) {
    return { ok: false, error: 'Некорректное ожидаемое количество' };
  }

  let sum = 0n;
  const seen = new Set<string>();

  for (const line of lines) {
    const code = String(line?.lot_code ?? '').trim();

    if (code === '') {
      return { ok: false, error: 'Укажите код партии' };
    }

    const key = code.toLowerCase();

    if (seen.has(key)) {
      return { ok: false, error: `Код партии «${code}» указан дважды` };
    }

    seen.add(key);

    const quantity = String(line?.quantity ?? '').trim();
    const scaled = toScaled(quantity);

    if (scaled === null) {
      return { ok: false, error: `Некорректное количество для партии «${code}»` };
    }

    if (scaled === 0n) {
      return { ok: false, error: `Количество для партии «${code}» должно быть больше нуля` };
    }

    sum += scaled;
  }

  if (sum !== expectedScaled) {
    return {
      ok: false,
      error: `Распределено ${formatScaled(sum)}, требуется ${formatLotQuantity(expected)}`
    };
  }

  return { ok: true };
}
