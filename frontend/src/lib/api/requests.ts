import { apiRequest, downloadFromApi } from './client';
import type {
  ActivityType,
  ClientItem,
  ManagerItem,
  PricePreview,
  RequestDetail,
  RequestListResponse,
  RequestStatus,
  RequestSummary
} from './types';

export function listStatuses(): Promise<RequestStatus[]> {
  return apiRequest<RequestStatus[]>('/request-statuses', { auth: true });
}

export function listActivityTypes(): Promise<ActivityType[]> {
  return apiRequest<ActivityType[]>('/activity-types', { auth: true });
}

export interface AddActivityInput {
  type: string;
  subject: string;
  body?: string;
  occurredAt?: string;
  isInternal?: boolean;
  attachmentId?: number;
}

export function addActivity(id: number, input: AddActivityInput): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/activities`, {
    method: 'POST',
    auth: true,
    body: {
      type: input.type,
      subject: input.subject,
      body: input.body ?? '',
      occurred_at: input.occurredAt ?? '',
      is_internal: input.isInternal ?? true,
      attachment_id: input.attachmentId ?? 0
    }
  });
}

export interface RequestFilters {
  status?: string;
  statuses?: string[];
  manager_scope?: string;
  manager_scopes?: string[];
  q?: string;
  warehouse_id?: number;
  warehouse_ids?: number[];
  client_id?: number;
  state?: string;
  states?: string[];
  overdue?: string[];
  from?: string;
  to?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}

export interface RequestWarehouseOption {
  id: number;
  name: string;
  count: number;
}

export function requestFilters(): Promise<{
  statuses: RequestStatus[];
  warehouses: RequestWarehouseOption[];
  sorts: { code: string; title: string }[];
}> {
  return apiRequest<{
    statuses: RequestStatus[];
    warehouses: RequestWarehouseOption[];
    sorts: { code: string; title: string }[];
  }>('/requests/filters', { auth: true });
}

function requestParams(filters: RequestFilters): URLSearchParams {
  const query = new URLSearchParams();

  if (filters.status) query.set('status', filters.status);
  if (filters.statuses && filters.statuses.length > 0) {
    query.set('statuses', filters.statuses.join(','));
  }
  if (filters.manager_scope) query.set('manager_scope', filters.manager_scope);
  if (filters.manager_scopes && filters.manager_scopes.length > 0) {
    query.set('manager_scopes', filters.manager_scopes.join(','));
  }
  if (filters.q) query.set('q', filters.q);
  if (filters.warehouse_id) query.set('warehouse_id', String(filters.warehouse_id));
  if (filters.warehouse_ids && filters.warehouse_ids.length > 0) {
    query.set('warehouse_ids', filters.warehouse_ids.join(','));
  }
  if (filters.client_id) query.set('client_id', String(filters.client_id));
  if (filters.state) query.set('state', filters.state);
  if (filters.states && filters.states.length > 0) {
    query.set('states', filters.states.join(','));
  }
  if (filters.overdue && filters.overdue.length > 0) {
    query.set('overdue', filters.overdue.join(','));
  }
  if (filters.from) query.set('from', filters.from);
  if (filters.to) query.set('to', filters.to);
  if (filters.sort) query.set('sort', filters.sort);

  return query;
}

export function listRequests(filters: RequestFilters = {}): Promise<RequestListResponse> {
  const query = requestParams(filters);

  if (filters.page && filters.page > 1) query.set('page', String(filters.page));
  if (filters.per_page !== undefined) query.set('per_page', String(filters.per_page));

  const suffix = query.toString() ? `?${query.toString()}` : '';

  return apiRequest<RequestListResponse>(`/requests${suffix}`, { auth: true });
}

export function exportRequests(filters: RequestFilters, format: string): Promise<void> {
  const query = requestParams(filters);
  query.set('export', format);

  return downloadFromApi(
    `/requests?${query.toString()}`,
    `Заявки ${new Date().toISOString().slice(0, 10)}.${format}`
  );
}

export function emailRequests(
  filters: RequestFilters,
  format: string
): Promise<{ sent: boolean; email: string }> {
  const query = requestParams(filters);
  query.set('export', format);
  query.set('email', '1');

  return apiRequest<{ sent: boolean; email: string }>(`/requests?${query.toString()}`, {
    auth: true
  });
}

export function requestSummary(): Promise<RequestSummary> {
  return apiRequest<RequestSummary>('/requests/summary', { auth: true });
}

export function getRequest(id: number): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}`, { auth: true });
}

export interface CreateRequestItem {
  warehouse_id: number | null;
  warehouse_name: string;
  stock_level_id?: number | null;
  name: string;
  unit: string;
  description?: string;
  quantity: number;
}

export interface NewClientPayload {
  name: string;
  inn: string;
  phone: string;
  email?: string;
}

export interface CreateRequestPayload {
  subject: string;
  body?: string;
  priority?: number;
  client_id?: number;
  price_type_id?: number;
  new_client?: NewClientPayload;
  items?: CreateRequestItem[];
}

export interface CreatedClientAccess {
  id: number;
  name: string;
  login: string;
  password: string;
  email: string;
  phone: string;
}

export type CreateRequestResult = RequestDetail & { client_access?: CreatedClientAccess };

export function createRequest(payload: CreateRequestPayload): Promise<CreateRequestResult> {
  return apiRequest<CreateRequestResult>('/requests', { method: 'POST', auth: true, body: payload });
}

export function previewRequestPrices(
  items: CreateRequestItem[],
  clientId = 0,
  priceTypeId = 0
): Promise<PricePreview> {
  return apiRequest<PricePreview>('/requests/price-preview', {
    method: 'POST',
    auth: true,
    body: {
      items,
      client_id: clientId > 0 ? clientId : undefined,
      price_type_id: priceTypeId > 0 ? priceTypeId : undefined
    }
  });
}

export interface UpdateRequestPayload {
  subject: string;
  body: string;
  items: CreateRequestItem[];
  priority?: number;
}

export function updateRequest(
  id: number,
  payload: UpdateRequestPayload,
  version: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/edit`, {
    method: 'POST',
    auth: true,
    body: { ...payload, version }
  });
}

export function transitionRequest(
  id: number,
  toStatus: string,
  comment: string,
  version?: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/transition`, {
    method: 'POST',
    auth: true,
    body: { to_status: toStatus, comment, version }
  });
}

export function updateRequestMeta(
  id: number,
  payload: { priority?: number; due_at?: string | null; comment?: string; subject?: string; body?: string },
  version: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/meta`, {
    method: 'POST',
    auth: true,
    body: { ...payload, version }
  });
}

export function updateRequestItems(
  id: number,
  items: CreateRequestItem[],
  version: number,
  comment = ''
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/items`, {
    method: 'POST',
    auth: true,
    body: { items, version, comment }
  });
}

export function claimRequest(id: number): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/claim`, { method: 'POST', auth: true });
}

export function assignRequest(
  id: number,
  managerId: number | null,
  comment: string,
  version?: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/assign`, {
    method: 'POST',
    auth: true,
    body: { manager_id: managerId, comment, version }
  });
}

export function addComment(id: number, body: string, isInternal = true): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/comments`, {
    method: 'POST',
    auth: true,
    body: { body, is_internal: isInternal }
  });
}

export interface ClientListFilters {
  state?: string;
  managers?: string;
  cities?: string;
  from?: string;
  to?: string;
  sort?: string;
}

export function listClients(
  query = '',
  page = 1,
  perPage = 20,
  filters: ClientListFilters = {}
): Promise<{ items: ClientItem[]; total: number; page: number; per_page: number }> {
  const params = new URLSearchParams();

  if (query) params.set('q', query);
  if (page > 1) params.set('page', String(page));
  params.set('per_page', String(perPage));
  if (filters.state) params.set('state', filters.state);
  if (filters.managers) params.set('managers', filters.managers);
  if (filters.cities) params.set('cities', filters.cities);
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.sort) params.set('sort', filters.sort);

  return apiRequest<{ items: ClientItem[]; total: number; page: number; per_page: number }>(
    `/clients?${params.toString()}`,
    { auth: true }
  );
}

export interface ClientFacets {
  cities: string[];
}

export function getClientFacets(): Promise<ClientFacets> {
  return apiRequest<ClientFacets>('/clients/facets', { auth: true });
}

export interface AssignClientResult {
  client_id: number;
  manager: { id: number; name: string };
}

export function assignClient(clientId: number, managerId: number): Promise<AssignClientResult> {
  return apiRequest<AssignClientResult>(`/clients/${clientId}/assign`, {
    method: 'POST',
    auth: true,
    body: { manager_id: managerId }
  });
}

export function listManagers(): Promise<{ items: ManagerItem[] }> {
  return apiRequest<{ items: ManagerItem[] }>('/managers', { auth: true });
}




