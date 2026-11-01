import { apiRequest } from './client';
import type { AssignedPriceType, PriceType } from './types';

export function listPriceTypes(): Promise<{ items: PriceType[] }> {
  return apiRequest<{ items: PriceType[] }>('/price-types', { auth: true });
}

export function setUserPriceTypes(
  userId: number,
  priceTypeIds: number[]
): Promise<{ id: number; price_types: AssignedPriceType[] }> {
  return apiRequest<{ id: number; price_types: AssignedPriceType[] }>(
    `/users/${userId}/price-types`,
    {
      method: 'PUT',
      auth: true,
      body: { price_type_ids: priceTypeIds }
    }
  );
}

export function listAdminPriceTypes(): Promise<{ items: PriceType[] }> {
  return apiRequest<{ items: PriceType[] }>('/admin/price-types', { auth: true });
}

export function createPriceType(title: string): Promise<{ type: PriceType }> {
  return apiRequest<{ type: PriceType }>('/admin/price-types', {
    method: 'POST',
    auth: true,
    body: { title }
  });
}

export function updatePriceType(id: number, title: string): Promise<{ type: PriceType }> {
  return apiRequest<{ type: PriceType }>(`/admin/price-types/${id}`, {
    method: 'PATCH',
    auth: true,
    body: { title }
  });
}

export function deletePriceType(id: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/admin/price-types/${id}`, {
    method: 'DELETE',
    auth: true
  });
}
