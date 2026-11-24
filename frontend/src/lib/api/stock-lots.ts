import { apiRequest } from './client';
import { listLevels, listWarehouses } from './stocks';
import type { StockLevel } from './types';

export interface StockLotRow {
  id: number;
  stock_level_id: number;
  item_name: string;
  warehouse_id: number;
  warehouse_name: string;
  lot_code: string;
  received_at: string;
  expires_at: string | null;
  quantity_available: string;
  quantity_reserved: string;
  quantity_total: string;
  status: 'active' | 'reserved' | 'expired' | 'empty';
}

export interface StockLotFilters {
  item_id?: number;
  warehouse_id?: number;
  lot_code?: string;
  expires_before?: string;
  expires_after?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

export interface StockLotList {
  items: StockLotRow[];
  total: number;
  page: number;
  per_page: number;
}

export interface ReceiveLotPayload {
  stock_level_id: number;
  lot_code: string;
  quantity: string;
  expires_at?: string;
  idempotency_key?: string;
}

export interface ConvertLotLine {
  lot_code: string;
  quantity: string;
  expires_at?: string;
}

export interface ConvertLotsPayload {
  stock_level_id: number;
  lots: ConvertLotLine[];
  idempotency_key?: string;
}

interface StockLotLevel {
  id: number;
  name: string;
  warehouse_id: number;
  warehouse_name: string;
  physical_quantity: string;
  track_lots: boolean;
}

const LOTS_PATH = '/stocks/lots';

function lotFilterParams(filters: StockLotFilters): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.item_id !== undefined && filters.item_id > 0) {
    params.set('item_id', String(filters.item_id));
  }

  if (filters.warehouse_id !== undefined && filters.warehouse_id > 0) {
    params.set('warehouse_id', String(filters.warehouse_id));
  }

  if (filters.lot_code?.trim()) {
    params.set('lot_code', filters.lot_code.trim());
  }

  if (filters.expires_before) {
    params.set('expires_before', filters.expires_before);
  }

  if (filters.expires_after) {
    params.set('expires_after', filters.expires_after);
  }

  if (filters.status) {
    params.set('status', filters.status);
  }

  if (filters.page !== undefined && filters.page > 0) {
    params.set('page', String(filters.page));
  }

  if (filters.per_page !== undefined && filters.per_page > 0) {
    params.set('per_page', String(filters.per_page));
  }

  return params;
}

export function listStockLots(
  filters: StockLotFilters,
  signal?: AbortSignal
): Promise<StockLotList> {
  return apiRequest<StockLotList>(`${LOTS_PATH}?${lotFilterParams(filters).toString()}`, {
    auth: true,
    signal
  });
}

export function receiveStockLot(payload: ReceiveLotPayload): Promise<{
  lot_id: number | null;
  already_applied: boolean;
  level_id: number;
  quantity: string;
}> {
  return apiRequest(`${LOTS_PATH}/receive`, {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function convertStockLots(payload: ConvertLotsPayload): Promise<{
  stock_level_id: number;
  quantity: string;
  lots: Array<{ lot_code: string; quantity: string }>;
}> {
  return apiRequest(`${LOTS_PATH}/convert`, {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export interface RequestLotState {
  request_id: number;
  levels: Array<{
    stock_level_id: number;
    item_name: string;
    warehouse_name: string;
    reserved: string;
    available: string;
  }>;
  reservations: Array<{
    lot_id: number;
    lot_code: string;
    stock_level_id: number;
    quantity: string;
    expires_at: string | null;
    received_at: string;
  }>;
  issues: Array<{
    id: number;
    stock_level_id: number;
    lot_id: number;
    quantity: string;
    reversed_quantity: string;
    remaining_quantity: string;
    created_at: string;
    idempotency_key: string | null;
    lot_code?: string;
  }>;
}

export function getRequestLotState(
  requestId: number,
  signal?: AbortSignal
): Promise<RequestLotState> {
  return apiRequest(`/requests/${encodeURIComponent(String(requestId))}/lots`, {
    auth: true,
    signal
  });
}

export function issueStockLots(
  requestId: number,
  quantity: string,
  idempotencyKey?: string
): Promise<{ already_applied: boolean; operations: number[]; result_id: number }> {
  return apiRequest(`/requests/${encodeURIComponent(String(requestId))}/lots/issue`, {
    method: 'POST',
    auth: true,
    body: { quantity, idempotency_key: idempotencyKey }
  });
}

export function reverseStockIssue(
  issueId: number,
  quantity: string,
  idempotencyKey?: string
): Promise<{ already_applied: boolean; result_id: number }> {
  return apiRequest(`/stocks/lot-issues/${encodeURIComponent(String(issueId))}/reversal`, {
    method: 'POST',
    auth: true,
    body: { quantity, idempotency_key: idempotencyKey }
  });
}

function trackLotsFlag(value: unknown): { present: boolean; enabled: boolean } {
  if (value === undefined || value === null) {
    return { present: false, enabled: true };
  }

  return {
    present: true,
    enabled: value === true || value === 1 || value === '1' || value === 'true'
  };
}

function mapStockLotLevel(
  raw: StockLevel,
  warehouseNames: Map<number, string>
): StockLotLevel | null {
  const record = raw as unknown as Record<string, unknown>;
  const id = Number(raw.id);

  if (!Number.isFinite(id) || id <= 0) {
    return null;
  }

  const flag = trackLotsFlag(record.track_lots ?? record.TRACK_LOTS);

  // Поле пока не отдаётся бэкендом: если оно есть — фильтруем строго,
  // если нет — отдаём уровень, а валидацию делает API приёмки/конвертации.
  if (!flag.enabled) {
    return null;
  }

  const warehouseId = Number(raw.warehouse_id ?? 0) || 0;
  const physical = record.physical_quantity ?? raw.quantity ?? '0';

  return {
    id,
    name: String(raw.name ?? ''),
    warehouse_id: warehouseId,
    warehouse_name: String(raw.warehouse_name ?? warehouseNames.get(warehouseId) ?? ''),
    physical_quantity: String(physical),
    track_lots: flag.enabled
  };
}

/**
 * Уровни склада для выбора в модалках партий.
 *
 * Отдельный `/stocks/lots/levels` контрактом не гарантирован, поэтому список
 * собирается из `/stocks/warehouses` + `/stocks/levels` (уровни отдаются
 * постранично, бэкенд ограничивает per_page 100). Поля читаются защитно:
 * отсутствующий track_lots считается «включать», отсутствующее
 * physical_quantity — равным legacy-полю quantity.
 */
export async function listStockLevelsForLots(
  signal?: AbortSignal
): Promise<StockLotLevel[]> {
  const warehouses = await listWarehouses();
  const warehouseItems = warehouses.items ?? [];
  const warehouseNames = new Map<number, string>(
    warehouseItems.map((warehouse) => [warehouse.id, warehouse.name])
  );
  const warehouseIds = warehouseItems
    .map((warehouse) => Number(warehouse.id))
    .filter((id) => Number.isFinite(id) && id > 0);

  if (warehouseIds.length === 0) {
    return [];
  }

  const result: StockLotLevel[] = [];
  const perPage = 100;
  let page = 1;
  let total = Number.POSITIVE_INFINITY;

  // listWarehouses/listLevels не принимают AbortSignal — обрываем цикл сами.
  while (result.length < total && page <= 50) {
    if (signal?.aborted) {
      throw new DOMException('Aborted', 'AbortError');
    }

    const data = await listLevels(warehouseIds, {}, page, perPage);
    const batch = data.items ?? [];

    total = typeof data.total === 'number' ? data.total : batch.length;

    for (const raw of batch) {
      const level = mapStockLotLevel(raw, warehouseNames);
      if (level) {
        result.push(level);
      }
    }

    if (batch.length === 0 || batch.length < perPage) {
      break;
    }

    page += 1;
  }

  return result;
}
