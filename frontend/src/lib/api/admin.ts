import { apiRequest } from './client';
import type {
  AdminUser,
  AuditEntry,
  DatabaseSettings,
  EmailQueueItem,
  EmailSettings,
  EmailTemplate,
  ReportSchedule,
  RolesResponse,
  SearchLogResponse,
  SystemSettings
} from './types';

export function getEmailSettings(): Promise<EmailSettings> {
  return apiRequest<EmailSettings>('/admin/settings/email', { auth: true });
}

export interface EmailSettingsPayload {
  host: string;
  port: number;
  encryption: string;
  username: string;
  password?: string;
  from: string;
  from_name: string;
}

export function saveEmailSettings(payload: EmailSettingsPayload): Promise<EmailSettings> {
  return apiRequest<EmailSettings>('/admin/settings/email', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function testEmailConnection(): Promise<{ ok: boolean }> {
  return apiRequest<{ ok: boolean }>('/admin/settings/email/test', {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function sendTestEmail(to: string): Promise<{ sent: boolean }> {
  return apiRequest<{ sent: boolean }>('/admin/settings/email/test', {
    method: 'POST',
    auth: true,
    body: { to }
  });
}

export function listTemplates(): Promise<EmailTemplate[]> {
  return apiRequest<EmailTemplate[]>('/admin/templates', { auth: true });
}

export function saveTemplate(code: string, subject: string, body: string): Promise<EmailTemplate[]> {
  return apiRequest<EmailTemplate[]>(`/admin/templates/${encodeURIComponent(code)}`, {
    method: 'POST',
    auth: true,
    body: { subject, body }
  });
}

export function listQueue(): Promise<EmailQueueItem[]> {
  return apiRequest<EmailQueueItem[]>('/admin/queue', { auth: true });
}

export function clearQueue(): Promise<{ cleared: number }> {
  return apiRequest<{ cleared: number }>('/admin/queue', {
    method: 'DELETE',
    auth: true
  });
}

export function getSystemSettings(): Promise<SystemSettings> {
  return apiRequest<SystemSettings>('/admin/settings/system', { auth: true });
}

export function saveSystemSettings(payload: {
  app_title: string;
  app_description: string;
  organization: string;
  legal_address: string;
  requisites: string;
  contact_email: string;
  contact_phone: string;
  responsible: string;
}): Promise<SystemSettings> {
  return apiRequest<SystemSettings>('/admin/settings/system', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export interface DatabaseSettingsPayload {
  host: string;
  port: number;
  database: string;
  user: string;
  password?: string;
}

export function getDatabaseSettings(): Promise<DatabaseSettings> {
  return apiRequest<DatabaseSettings>('/admin/settings/database', { auth: true });
}

export function testDatabaseSettings(
  payload: DatabaseSettingsPayload
): Promise<{ ok: boolean; server: string }> {
  return apiRequest<{ ok: boolean; server: string }>('/admin/settings/database/test', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function saveDatabaseSettings(payload: DatabaseSettingsPayload): Promise<DatabaseSettings> {
  return apiRequest<DatabaseSettings>('/admin/settings/database', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function listUsers(params: {
  q?: string;
  level?: number;
  state?: string;
  page?: number;
  per_page?: number;
}): Promise<{ items: AdminUser[]; total: number; page: number; per_page: number }> {
  const search = new URLSearchParams();

  if (params.q) search.set('q', params.q);
  if (params.level) search.set('level', String(params.level));
  if (params.state) search.set('state', params.state);
  if (params.page) search.set('page', String(params.page));
    if (params.per_page !== undefined) search.set('per_page', String(params.per_page));

  const suffix = search.toString() !== '' ? `?${search.toString()}` : '';

  return apiRequest(`/users${suffix}`, { auth: true });
}

export interface AdminUserPayload {
  name: string;
  email: string;
  phone?: string;
  company?: string;
  inn?: string;
  position?: string;
  level: number;
  password?: string;
  price_type_id?: number | null;
}

export function createUser(payload: AdminUserPayload): Promise<{ id: number; login: string; password: string | null }> {
  return apiRequest<{ id: number; login: string; password: string | null }>('/users', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function updateUser(
  id: number,
  payload: Partial<Omit<AdminUserPayload, 'email' | 'password'>>
): Promise<{ id: number; level: number }> {
  return apiRequest<{ id: number; level: number }>(`/users/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function blockUser(id: number): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/users/${id}/block`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function unblockUser(id: number): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/users/${id}/unblock`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function resetUserPassword(id: number): Promise<{ id: number; password: string }> {
  return apiRequest<{ id: number; password: string }>(`/users/${id}/reset-password`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function listRoles(): Promise<RolesResponse> {
  return apiRequest<RolesResponse>('/roles', { auth: true });
}

export function saveRole(level: number, capabilities: string[]): Promise<RolesResponse> {
  return apiRequest<RolesResponse>(`/roles/${level}`, {
    method: 'POST',
    auth: true,
    body: { capabilities }
  });
}

export interface ModuleAccessGroup {
  code: string;
  title: string;
  description: string;
  levels: number[];
  capabilities: string[];
}

export interface ModuleItem {
  code: string;
  title: string;
  description: string;
  enabled: boolean;
  levels: number[];
  capabilities: string[];
  groups: ModuleAccessGroup[];
  settings: Record<string, string | number | boolean>;
}

export interface ModuleRole {
  level: number;
  title: string;
}

export interface ModulesResponse {
  modules: ModuleItem[];
  roles: ModuleRole[];
}

export function listModules(): Promise<ModulesResponse> {
  return apiRequest<ModulesResponse>('/admin/modules', { auth: true });
}

export function saveModules(modules: ModuleItem[]): Promise<ModulesResponse> {
  return apiRequest<ModulesResponse>('/admin/modules', {
    method: 'POST',
    auth: true,
    body: {
      modules: Object.fromEntries(
        modules.map((module) => [
          module.code,
          {
            enabled: module.enabled,
            levels: module.levels,
            groups: Object.fromEntries(
              module.groups.map((group) => [group.code, group.levels])
            ),
            settings: module.settings
          }
        ])
      )
    }
  });
}

export function listAudit(params: {
  action?: string;
  entity?: string;
  user_id?: number;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}): Promise<{ items: AuditEntry[]; total: number; page: number; per_page: number }> {
  const search = new URLSearchParams();

  if (params.action) search.set('action', params.action);
  if (params.entity) search.set('entity', params.entity);
  if (params.user_id) search.set('user_id', String(params.user_id));
  if (params.from) search.set('from', params.from);
  if (params.to) search.set('to', params.to);
  if (params.page) search.set('page', String(params.page));
    if (params.per_page !== undefined) search.set('per_page', String(params.per_page));

  const suffix = search.toString() !== '' ? `?${search.toString()}` : '';

  return apiRequest(`/admin/audit${suffix}`, { auth: true });
}

export function searchLog(): Promise<SearchLogResponse> {
  return apiRequest<SearchLogResponse>('/admin/search-log', { auth: true });
}
