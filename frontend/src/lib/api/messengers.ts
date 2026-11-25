import { apiRequest } from './client';

export interface MessengerChannelSettings {
  channel: 'max' | 'telegram';
  enabled: boolean;
  has_token: boolean;
  token_masked: string;
  bot_id: string | null;
  bot_name: string | null;
  last_check_status: string | null;
  last_check_error: string | null;
  last_check_at: string | null;
  has_webhook_secret: boolean;
}

export interface MessengerCheckResult {
  connected: boolean;
  bot_id: string | null;
  bot_name: string | null;
  error_code: string | null;
  message: string | null;
  checked_at: string | null;
}

export interface MessengerLink {
  channel: 'max' | 'telegram';
  connected: boolean;
  masked_id: string;
  linked_at: string | null;
}

export function listMessengerChannels(): Promise<{ items: MessengerChannelSettings[] }> {
  return apiRequest<{ items: MessengerChannelSettings[] }>('/admin/messengers', { auth: true });
}

export function saveMessengerChannel(
  channel: string,
  enabled: boolean,
  token: string
): Promise<{ items: MessengerChannelSettings[] }> {
  return apiRequest<{ items: MessengerChannelSettings[] }>(`/admin/messengers/${channel}`, {
    method: 'PUT',
    auth: true,
    body: { enabled, token }
  });
}

export function checkMessengerChannel(channel: string): Promise<MessengerCheckResult> {
  return apiRequest<MessengerCheckResult>(`/admin/messengers/${channel}/check`, {
    method: 'POST',
    auth: true
  });
}

export function deleteMessengerToken(channel: string): Promise<{ items: MessengerChannelSettings[] }> {
  return apiRequest<{ items: MessengerChannelSettings[] }>(`/admin/messengers/${channel}/token`, {
    method: 'DELETE',
    auth: true,
    body: { confirm: true }
  });
}

export function registerMessengerWebhook(channel: string, url: string): Promise<{ registered: boolean }> {
  return apiRequest<{ registered: boolean }>(`/admin/messengers/${channel}/webhook`, {
    method: 'POST',
    auth: true,
    body: { url }
  });
}

export function listMessengerLinks(): Promise<{ items: MessengerLink[] }> {
  return apiRequest<{ items: MessengerLink[] }>('/messengers/links', { auth: true });
}

export function startMessengerBind(channel: string): Promise<{ code: string; expires_in: number }> {
  return apiRequest<{ code: string; expires_in: number }>(`/messengers/${channel}/bind`, {
    method: 'POST',
    auth: true
  });
}

export function unlinkMessenger(channel: string): Promise<{ unlinked: boolean }> {
  return apiRequest<{ unlinked: boolean }>(`/messengers/${channel}/link`, {
    method: 'DELETE',
    auth: true
  });
}
