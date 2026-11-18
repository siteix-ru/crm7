import { describe, expect, it } from 'vitest';
import { Sequence } from './async-sequence';

describe('Sequence', () => {
  it('accepts only the newest token', () => {
    const sequence = new Sequence();
    const first = sequence.next();
    const second = sequence.next();

    expect(sequence.isCurrent(first)).toBe(false);
    expect(sequence.isCurrent(second)).toBe(true);
  });

  it('invalidates a token when a newer load starts', () => {
    const sequence = new Sequence();
    const token = sequence.next();

    expect(sequence.isCurrent(token)).toBe(true);

    sequence.next();

    expect(sequence.isCurrent(token)).toBe(false);
  });
});
