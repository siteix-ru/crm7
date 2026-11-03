import { apiRequest } from './client';
import type { ItemGroupItem, ItemType } from './types';

export interface ItemTypeAdmin extends ItemType {
  id: number;
  sort: number;
  positions_count: number;
  is_last: boolean;
}

export interface ItemTypePayload {
  title: string;
  description: string;
  enabled: boolean;
}

export function listAdminItemTypes(): Promise<{ types: ItemTypeAdmin[] }> {
  return apiRequest<{ types: ItemTypeAdmin[] }>('/admin/item-types', { auth: true });
}

export function getAdminItemType(id: number): Promise<{ type: ItemTypeAdmin }> {
  return apiRequest<{ type: ItemTypeAdmin }>(`/admin/item-types/${id}`, { auth: true });
}

export function listAdminItemTypeItems(id: number): Promise<{ items: ItemGroupItem[] }> {
  return apiRequest<{ items: ItemGroupItem[] }>(`/admin/item-types/${id}/items`, { auth: true });
}

export function createAdminItemType(payload: ItemTypePayload): Promise<{ type: ItemTypeAdmin }> {
  return apiRequest<{ type: ItemTypeAdmin }>('/admin/item-types', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function updateAdminItemType(
  id: number,
  payload: ItemTypePayload
): Promise<{ type: ItemTypeAdmin }> {
  return apiRequest<{ type: ItemTypeAdmin }>(`/admin/item-types/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function deleteAdminItemType(id: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/admin/item-types/${id}`, {
    method: 'DELETE',
    auth: true,
    body: {}
  });
}
