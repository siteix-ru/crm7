import { apiRequest } from './client';
import type {
  ClientCard,
  ClientCardRequest,
  ClientHistoryItem,
  ClientInterest,
  PendingClient,
  ViewEntry
} from './types';

export interface RegisterPayload {
  name: string;
  company: string;
  inn: string;
  phone: string;
  email: string;
  password: string;
  password_confirm: string;
  consent: boolean;
}

export function registerClient(payload: RegisterPayload): Promise<{ pending: boolean; message: string }> {
  return apiRequest<{ pending: boolean; message: string }>('/auth/register', {
    method: 'POST',
    body: payload
  });
}

export function pendingClients(): Promise<{ items: PendingClient[] }> {
  return apiRequest<{ items: PendingClient[] }>('/clients/pending', { auth: true });
}

export function activateClient(id: number, comment: string): Promise<{ id: number; state: string }> {
  return apiRequest<{ id: number; state: string }>(`/clients/${id}/activate`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export function rejectClient(id: number, comment: string): Promise<{ id: number; state: string }> {
  return apiRequest<{ id: number; state: string }>(`/clients/${id}/reject`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export function getClientCard(id: number): Promise<ClientCard> {
  return apiRequest<ClientCard>(`/clients/${id}`, { auth: true });
}

export interface ClientHistoryPayload {
  history: ClientHistoryItem[];
  views: ViewEntry[];
  requests: ClientCardRequest[];
}

export function getClientHistory(id: number): Promise<ClientHistoryPayload> {
  return apiRequest<ClientHistoryPayload>(`/clients/${id}/history`, { auth: true });
}

export interface ClientInterestFilters {
  q?: string;
  sort?: string;
  page?: number;
  perPage?: number;
  warehouses?: string[];
  from?: string;
  to?: string;
}

export interface ClientInterestsResult {
  items: ClientInterest[];
  total: number;
  warehouses: string[];
  page: number;
  per_page: number;
}

export function getClientInterests(
  id: number,
  filters: ClientInterestFilters = {}
): Promise<ClientInterestsResult> {
  const params = new URLSearchParams();

  if (filters.q) params.set('q', filters.q);
  if (filters.sort) params.set('sort', filters.sort);
  if (filters.warehouses && filters.warehouses.length > 0) {
    for (const warehouse of filters.warehouses) {
      params.append('warehouses[]', warehouse);
    }
  }
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  params.set('page', String(filters.page ?? 1));
  params.set('per_page', String(filters.perPage ?? 20));

  return apiRequest<ClientInterestsResult>(
    `/clients/${id}/interests?${params.toString()}`,
    { auth: true }
  );
}

export interface ClientAccessResult {
  id: number;
  active: boolean;
  email_sent: boolean;
  email: string | null;
}

export function blockClient(id: number, comment: string): Promise<ClientAccessResult> {
  return apiRequest<ClientAccessResult>(`/clients/${id}/block`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export function unblockClient(id: number, comment: string): Promise<ClientAccessResult> {
  return apiRequest<ClientAccessResult>(`/clients/${id}/unblock`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export interface ClientContactsPayload {
  name: string;
  company: string;
  inn: string;
  position: string;
  phone: string;
  email: string;
}

export function updateClientContacts(id: number, payload: ClientContactsPayload): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function changeClientLogin(id: number, login: string): Promise<{ id: number; login: string }> {
  return apiRequest<{ id: number; login: string }>(`/clients/${id}/login`, {
    method: 'PATCH',
    auth: true,
    body: { login }
  });
}

export interface ClientPasswordResult {
  id: number;
  login: string;
  password: string;
  channel: 'show';
  email_sent: boolean;
  email: string | null;
}

export interface ClientPasswordLinkResult {
  id: number;
  email: string;
  channel: 'email';
}

export function resetClientPassword(id: number): Promise<ClientPasswordResult> {
  return apiRequest<ClientPasswordResult>(`/clients/${id}/reset-password`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function sendClientPasswordLink(id: number): Promise<ClientPasswordLinkResult> {
  return apiRequest<ClientPasswordLinkResult>(`/clients/${id}/reset-password-email`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function claimClient(id: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}/claim`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function assignClient(
  id: number,
  managerId: number | null,
  comment: string
): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}/assign`, {
    method: 'POST',
    auth: true,
    body: { manager_id: managerId, comment }
  });
}

export function transferClient(
  id: number,
  toManagerId: number | null,
  dateFrom: string,
  dateTo: string | null,
  comment: string
): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}/transfer`, {
    method: 'POST',
    auth: true,
    body: {
      to_manager_id: toManagerId,
      date_from: dateFrom,
      date_to: dateTo,
      comment
    }
  });
}

export function acceptTransfer(transferId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/client-transfers/${transferId}/accept`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function declineTransfer(transferId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/client-transfers/${transferId}/decline`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function cancelTransfer(transferId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/client-transfers/${transferId}/cancel`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}
