import { apiRequest } from './client';
import type { AssignedPriceType } from './types';

export const STAFF_LEVELS = [10, 50, 90] as const;

export type StaffLevel = (typeof STAFF_LEVELS)[number];

export function staffLevelTitle(level: number): string {
  if (level >= 90) return 'Технический администратор';
  if (level >= 50) return 'Администратор';
  if (level >= 10) return 'Менеджер';

  return 'Сотрудник';
}

export function staffStateTitle(active: boolean): string {
  return active ? 'Активен' : 'Заблокирован';
}

export interface StaffUser {
  id: number;
  sid: string;
  login: string;
  name: string;
  email: string;
  phone: string;
  company: string;
  inn: string;
  position: string;
  department: string;
  city: string;
  office: string;
  level: number;
  active: boolean;
  reg_state: string;
  created_at: string;
  last_seen_at: string | null;
  has_avatar: boolean;
  is_self: boolean;
  is_last_admin: boolean;
  is_last_superadmin: boolean;
  can: {
    edit: boolean;
    change_level: boolean;
    block: boolean;
    reset_password: boolean;
    change_login: boolean;
    revoke_sessions: boolean;
    convert_to_client: boolean;
  };
}

export interface UserConversionBlocker {
  code: string;
  message: string;
}

export interface UserConversionPreview {
  id: number;
  level: number;
  target_levels: number[];
  suggested_level: number;
  direction: 'to_staff' | 'to_client';
  can: boolean;
  blockers: UserConversionBlocker[];
  counts: {
    clients_managed: number;
    open_requests: number;
    active_substitutions: number;
    pending_transfers: number;
    stock_acl: number;
    price_types: number;
    drafts: number;
  };
}

export interface UserConversionResult {
  id: number;
  from_level: number;
  to_level: number;
  direction: 'to_staff' | 'to_client';
  tokens_revoked: boolean;
  counts: Record<string, number>;
}

export function getUserConversion(id: number): Promise<UserConversionPreview> {
  return apiRequest<UserConversionPreview>(`/users/${id}/conversion`, { auth: true });
}

export function convertUser(id: number, targetLevel: number): Promise<UserConversionResult> {
  return apiRequest<UserConversionResult>(`/users/${id}/convert`, {
    method: 'POST',
    auth: true,
    body: { target_level: targetLevel }
  });
}

export interface StaffHistoryItem {
  id: number;
  event: string;
  comment: string;
  created_at: string;
  actor: { id: number; name: string; level: number } | null;
}

export interface StaffFacets {
  cities: string[];
  departments: string[];
  offices: string[];
  positions: string[];
}

export interface StaffClient {
  id: number;
  login: string;
  name: string;
  company: string;
  email: string;
  phone: string;
  active: boolean;
  reg_state: string;
  requests_total: number;
}

export interface StaffListParams {
  q?: string;
  levels?: number[];
  level?: number;
  states?: string[];
  state?: string;
  cities?: string[];
  city?: string;
  departments?: string[];
  department?: string;
  offices?: string[];
  office?: string;
  positions?: string[];
  position?: string;
  sort?: string;
  page?: number;
  perPage?: number;
}

export interface StaffListResult {
  items: StaffUser[];
  total: number;
  page: number;
  per_page: number;
}

export function listStaff(params: StaffListParams = {}): Promise<StaffListResult> {
  const search = new URLSearchParams({ scope: 'staff' });

  if (params.q) search.set('q', params.q);
  if (params.levels && params.levels.length > 0) search.set('levels', params.levels.join(','));
  if (params.level) search.set('level', String(params.level));
  if (params.states && params.states.length > 0) search.set('states', params.states.join(','));
  if (params.state) search.set('state', params.state);
  if (params.cities && params.cities.length > 0) search.set('cities', params.cities.join(','));
  if (params.city) search.set('city', params.city);
  if (params.departments && params.departments.length > 0) {
    search.set('departments', params.departments.join(','));
  }
  if (params.department) search.set('department', params.department);
  if (params.offices && params.offices.length > 0) search.set('offices', params.offices.join(','));
  if (params.office) search.set('office', params.office);
  if (params.positions && params.positions.length > 0) {
    search.set('positions', params.positions.join(','));
  }
  if (params.position) search.set('position', params.position);
  if (params.sort) search.set('sort', params.sort);
  if (params.page) search.set('page', String(params.page));
  if (params.perPage !== undefined) search.set('per_page', String(params.perPage));

  return apiRequest<StaffListResult>(`/users?${search.toString()}`, { auth: true });
}

export function getStaff(id: number): Promise<StaffUser> {
  return apiRequest<StaffUser>(`/users/${id}`, { auth: true });
}

export function getStaffFacets(): Promise<StaffFacets> {
  return apiRequest<StaffFacets>('/users/facets', { auth: true });
}

export function getStaffClients(id: number): Promise<{ items: StaffClient[]; total: number }> {
  return apiRequest<{ items: StaffClient[]; total: number }>(`/users/${id}/clients`, {
    auth: true
  });
}

export function getStaffHistory(id: number): Promise<{ items: StaffHistoryItem[] }> {
  return apiRequest<{ items: StaffHistoryItem[] }>(`/users/${id}/history`, { auth: true });
}

export interface StaffCreatePayload {
  name: string;
  email: string;
  phone?: string;
  company?: string;
  inn?: string;
  position?: string;
  department?: string;
  city?: string;
  office?: string;
  level: number;
  login?: string;
}

export interface StaffCreateResult {
  id: number;
  login: string;
  password: string | null;
  price_types: AssignedPriceType[];
}

export function createStaff(payload: StaffCreatePayload): Promise<StaffCreateResult> {
  return apiRequest<StaffCreateResult>('/users', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export interface StaffUpdatePayload {
  name?: string;
  email?: string;
  phone?: string;
  company?: string;
  inn?: string;
  position?: string;
  department?: string;
  city?: string;
  office?: string;
  level?: number;
}

export function updateStaff(id: number, payload: StaffUpdatePayload): Promise<{ id: number; level: number }> {
  return apiRequest<{ id: number; level: number }>(`/users/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function blockStaff(id: number): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/users/${id}/block`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function unblockStaff(id: number): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/users/${id}/unblock`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function resetStaffPassword(id: number): Promise<{ id: number; password: string }> {
  return apiRequest<{ id: number; password: string }>(`/users/${id}/reset-password`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function sendStaffCredentials(
  id: number,
  login: string,
  password: string
): Promise<{ sent: boolean; email: string }> {
  return apiRequest<{ sent: boolean; email: string }>(`/users/${id}/send-credentials`, {
    method: 'POST',
    auth: true,
    body: { login, password }
  });
}

export function revokeStaffSessions(id: number): Promise<{ id: number; revoked: boolean }> {
  return apiRequest<{ id: number; revoked: boolean }>(`/users/${id}/revoke-sessions`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function changeStaffLogin(id: number, login: string): Promise<{ id: number; login: string }> {
  return apiRequest<{ id: number; login: string }>(`/users/${id}/login`, {
    method: 'PATCH',
    auth: true,
    body: { login }
  });
}
