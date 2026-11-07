import { apiRequest } from './client';

export interface UpdateRelease {
  version: string;
  released_at: string;
  commit: string;
  min_from: string;
  notes: string;
  images: Record<string, string>;
}

export interface UpdateCheck {
  configured: boolean;
  current_version: string | null;
  latest_version: string | null;
  update_available: boolean;
  release: UpdateRelease | null;
  chain: UpdateRelease[];
  feed_generated_at: string | null;
}

export type UpdateState =
  | 'idle'
  | 'queued'
  | 'running'
  | 'success'
  | 'failed'
  | 'busy'
  | 'stalled'
  | 'not_configured';

export interface UpdateStatus {
  state: UpdateState;
  exit_code?: number;
  updated_at?: string | null;
  request_id?: string;
  current_version?: string | null;
  target_version?: string;
  message?: string;
  log_tail?: string;
  requested_by?: { id: number; login: string };
}

export interface UpdateApplyResult {
  accepted: boolean;
  request_id: string;
  target_version: string;
  status: UpdateStatus;
}

export interface UpdateStatusResponse {
  configured: boolean;
  current_version: string | null;
  status: UpdateStatus;
}

function withCurrent(path: string, current?: string): string {
  if (!current) {
    return path;
  }

  const separator = path.includes('?') ? '&' : '?';

  return `${path}${separator}current=${encodeURIComponent(current)}`;
}

export function checkUpdates(current?: string): Promise<UpdateCheck> {
  return apiRequest<UpdateCheck>(withCurrent('/admin/update/check', current), { auth: true });
}

export function applyUpdate(current?: string): Promise<UpdateApplyResult> {
  return apiRequest<UpdateApplyResult>(withCurrent('/admin/update/apply', current), {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function getUpdateStatus(current?: string): Promise<UpdateStatusResponse> {
  return apiRequest<UpdateStatusResponse>(withCurrent('/admin/update/status', current), { auth: true });
}
