-- Stage 3.4.283: schedule messenger retries instead of re-claiming immediately.
-- Additive only; email rows keep NULL and retain their existing behaviour.
ALTER TABLE `email_queue`
  ADD COLUMN IF NOT EXISTS `next_attempt_at` datetime NULL AFTER `event_key`,
  ADD KEY `idx_ready` (`channel`, `status`, `next_attempt_at`, `created_at`);
