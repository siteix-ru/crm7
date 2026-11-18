/**
 * Стабильный ключ строки корзины и преобразование ответа price-preview.
 *
 * API возвращает цены в том же порядке, в котором были отправлены позиции,
 * но не возвращает идентификатор позиции. Поэтому ключ фиксируется до запроса
 * и используется для сопоставления уже после ответа — даже если состав
 * корзины изменился, пока запрос был в полёте.
 */

export interface PricedCartRow {
  warehouseId: number;
  name: string;
}

export interface CartPriceUpdate {
  key: string;
  price: number | null;
}

export function cartItemKey(item: PricedCartRow): string {
  return `${item.warehouseId}|${item.name}`;
}

/** Возвращает null, если ответ неполный — применять такие цены нельзя. */
export function buildPriceUpdates(
  keys: string[],
  prices: Array<number | null | undefined>
): CartPriceUpdate[] | null {
  if (keys.length !== prices.length) {
    return null;
  }

  return keys.map((key, index) => ({ key, price: prices[index] ?? null }));
}
