import { describe, expect, it } from 'vitest';
import {
  formatLotQuantity,
  lotStatusLabel,
  lotStatusTone,
  parseDistribution
} from './lot-format';

describe('formatLotQuantity', () => {
  it('trims trailing zeros of DECIMAL(20,5)', () => {
    expect(formatLotQuantity('10.00000')).toBe('10');
    expect(formatLotQuantity('1.50000')).toBe('1.5');
    expect(formatLotQuantity('0.00001')).toBe('0.00001');
  });

  it('keeps meaningful fractional digits and leaves plain values intact', () => {
    expect(formatLotQuantity('12.34500')).toBe('12.345');
    expect(formatLotQuantity('7')).toBe('7');
    expect(formatLotQuantity('0.00000')).toBe('0');
  });

  it('does not invent digits for unexpected input', () => {
    expect(formatLotQuantity('')).toBe('');
    expect(formatLotQuantity('abc')).toBe('abc');
  });
});

describe('lotStatusLabel', () => {
  it('uses Russian labels for known statuses', () => {
    expect(lotStatusLabel('active')).toBe('В наличии');
    expect(lotStatusLabel('reserved')).toBe('Зарезервировано');
    expect(lotStatusLabel('expired')).toBe('Истёк срок');
    expect(lotStatusLabel('empty')).toBe('Пустая');
  });

  it('falls back to the raw status', () => {
    expect(lotStatusLabel('unknown')).toBe('unknown');
  });
});

describe('lotStatusTone', () => {
  it('maps statuses to Ant-like tones', () => {
    expect(lotStatusTone('active')).toBe('success');
    expect(lotStatusTone('reserved')).toBe('processing');
    expect(lotStatusTone('expired')).toBe('warning');
    expect(lotStatusTone('empty')).toBe('default');
    expect(lotStatusTone('anything')).toBe('default');
  });
});

describe('parseDistribution', () => {
  it('accepts a single lot with exact quantity', () => {
    expect(parseDistribution([{ lot_code: 'LOT-1', quantity: '10.00000' }], '10')).toEqual({ ok: true });
  });

  it('accepts several lots and ignores fractional trailing zeros', () => {
    const result = parseDistribution(
      [
        { lot_code: 'A', quantity: '1.50000' },
        { lot_code: 'B', quantity: '2.25' },
        { lot_code: 'C', quantity: '0.00001' }
      ],
      '3.75001'
    );

    expect(result).toEqual({ ok: true });
  });

  it('trims whitespace around codes and quantities', () => {
    expect(parseDistribution([{ lot_code: '  LOT-2  ', quantity: ' 5.000 ' }], '5')).toEqual({ ok: true });
  });

  it('rejects an empty line list', () => {
    const result = parseDistribution([], '10');

    expect(result.ok).toBe(false);
    expect(result.error).toContain('хотя бы одну партию');
  });

  it('rejects blank lot codes', () => {
    const result = parseDistribution([{ lot_code: '   ', quantity: '1' }], '1');

    expect(result.ok).toBe(false);
    expect(result.error).toContain('код партии');
  });

  it('rejects duplicate lot codes ignoring case', () => {
    const result = parseDistribution(
      [
        { lot_code: 'LOT-1', quantity: '1' },
        { lot_code: 'lot-1', quantity: '2' }
      ],
      '3'
    );

    expect(result.ok).toBe(false);
    expect(result.error).toContain('указан дважды');
  });

  it('rejects zero and negative or over-precision quantities', () => {
    expect(parseDistribution([{ lot_code: 'A', quantity: '0' }], '0').ok).toBe(false);
    expect(parseDistribution([{ lot_code: 'A', quantity: '-1' }], '-1').ok).toBe(false);
    expect(parseDistribution([{ lot_code: 'A', quantity: '1.000001' }], '1.000001').ok).toBe(false);
    expect(parseDistribution([{ lot_code: 'A', quantity: '1e3' }], '1000').ok).toBe(false);
  });

  it('rejects a sum that differs from the physical balance', () => {
    const result = parseDistribution(
      [
        { lot_code: 'A', quantity: '1.5' },
        { lot_code: 'B', quantity: '2' }
      ],
      '4'
    );

    expect(result.ok).toBe(false);
    expect(result.error).toContain('Распределено 3.5');
    expect(result.error).toContain('требуется 4');
  });

  it('rejects an invalid expected quantity', () => {
    const result = parseDistribution([{ lot_code: 'A', quantity: '1' }], 'nope');

    expect(result.ok).toBe(false);
    expect(result.error).toContain('ожидаемое количество');
  });

  it('uses integer arithmetic for large 13-digit values without float drift', () => {
    const result = parseDistribution(
      [
        { lot_code: 'A', quantity: '999999999999.99999' },
        { lot_code: 'B', quantity: '0.00001' }
      ],
      '1000000000000'
    );

    expect(result).toEqual({ ok: true });
  });
});
