import { apiRequest } from './client';
import type { CreateRequestItem } from './requests';

export interface RequestDraftSummary {
  id: number;
  client_id: number;
  subject: string;
  priority: number;
  items_count: number;
  created_at: string;
  updated_at: string;
}

export interface RequestDraft {
  id: number;
  subject: string;
  body: string;
  priority: number;
  price_type_id: number;
  client_id: number;
  items: CreateRequestItem[];
  created_at: string;
  updated_at: string;
}

export interface RequestDraftPayload {
  subject: string;
  body: string;
  priority: number;
  price_type_id?: number;
  client_id?: number;
  items: CreateRequestItem[];
}

export function listDrafts(clientId = 0): Promise<{ items: RequestDraftSummary[] }> {
  const query = clientId > 0 ? `?client_id=${clientId}` : '';

  return apiRequest<{ items: RequestDraftSummary[] }>(`/request-drafts${query}`, { auth: true });
}

export function getDraft(id: number): Promise<RequestDraft> {
  return apiRequest<RequestDraft>(`/request-drafts/${id}`, { auth: true });
}

export function createDraft(payload: RequestDraftPayload, keepalive = false): Promise<RequestDraft> {
  return apiRequest<RequestDraft>('/request-drafts', {
    method: 'POST',
    auth: true,
    body: payload,
    keepalive
  });
}

export function updateDraft(
  id: number,
  payload: RequestDraftPayload,
  keepalive = false
): Promise<RequestDraft> {
  return apiRequest<RequestDraft>(`/request-drafts/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload,
    keepalive
  });
}

export function deleteDraft(id: number): Promise<{ deleted: boolean }> {
  return apiRequest<{ deleted: boolean }>(`/request-drafts/${id}`, { method: 'DELETE', auth: true });
}
