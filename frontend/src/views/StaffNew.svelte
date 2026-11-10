<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import {
    createStaff,
    sendStaffCredentials,
    staffLevelTitle,
    STAFF_LEVELS,
    type StaffCreateResult
  } from '../lib/api/staff';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';

  const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  const roleDescriptions: Record<number, string> = {
    10: 'Клиенты, заявки, чат и отчёты по своим клиентам',
    50: 'Сотрудники, клиенты, заявки и настройки системы',
    90: 'Полный доступ, включая роли и системные настройки'
  };

  let form = $state({
    name: '',
    email: '',
    phone: '',
    position: '',
    department: '',
    city: '',
    office: '',
    level: '10'
  });
  let errors = $state<Record<string, string>>({});
  let saving = $state(false);
  let actionError = $state('');
  let created = $state<StaffCreateResult | null>(null);
  let createdOpen = $state(false);
  let copied = $state('');
  let sendingCredentials = $state(false);
  let credentialsSent = $state('');
  let credentialsError = $state('');

  const canChangeLevel = $derived(auth.can('roles.manage'));

  function collectErrors(): Record<string, string> {
    const next: Record<string, string> = {};
    const name = form.name.trim();
    const email = form.email.trim();
    const phone = form.phone.trim();
    const digits = phone.replace(/\D/g, '');

    if (name.length < 3) {
      next.name = 'Укажите ФИО (минимум 3 символа)';
    }

    if (!emailPattern.test(email)) {
      next.email = 'Укажите корректный e-mail';
    }

    if (phone !== '' && (digits.length < 10 || digits.length > 15)) {
      next.phone = 'Телефон должен содержать от 10 до 15 цифр';
    }

    return next;
  }

  function validateField(field: 'name' | 'email' | 'phone'): void {
    const fieldErrors = collectErrors();
    const next = { ...errors };

    if (fieldErrors[field]) {
      next[field] = fieldErrors[field];
    } else {
      delete next[field];
    }

    errors = next;
  }

  function scheduleValidation(field: 'name' | 'email' | 'phone'): void {
    setTimeout(() => validateField(field), 0);
  }

  function validate(): boolean {
    const next = collectErrors();
    errors = next;

    return Object.keys(next).length === 0;
  }

  function applyServerError(cause: unknown): void {
    if (!(cause instanceof ApiError)) {
      actionError = 'Не удалось создать сотрудника';
      return;
    }

    const message = cause.message;
    const lower = message.toLowerCase();

    if (cause.code === 'email_taken' || cause.code === 'email_missing' || lower.includes('e-mail')) {
      errors = { ...errors, email: message };
      return;
    }

    if (cause.code === 'phone_taken' || lower.includes('телефон')) {
      errors = { ...errors, phone: message };
      return;
    }

    if (cause.code === 'validation_error' && (lower.includes('фио') || lower.includes('имя'))) {
      errors = { ...errors, name: message };
      return;
    }

    actionError = message;
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    actionError = '';

    if (!validate()) {
      return;
    }

    saving = true;

    try {
      created = await createStaff({
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
        position: form.position.trim(),
        department: form.department.trim(),
        city: form.city.trim(),
        office: form.office.trim(),
        level: Number(form.level)
      });
      createdOpen = true;
    } catch (cause) {
      applyServerError(cause);
    } finally {
      saving = false;
    }
  }

  async function sendCredentials(): Promise<void> {
    if (created === null || created.password === null) {
      return;
    }

    sendingCredentials = true;
    credentialsError = '';

    try {
      const result = await sendStaffCredentials(created.id, created.login, created.password);
      credentialsSent = result.email;
    } catch (cause) {
      credentialsError = cause instanceof ApiError ? cause.message : 'Не удалось отправить письмо';
    } finally {
      sendingCredentials = false;
    }
  }

  async function copyValue(value: string, key: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(value);
      copied = key;
      setTimeout(() => {
        if (copied === key) {
          copied = '';
        }
      }, 2000);
    } catch {
      actionError = 'Не удалось скопировать значение';
    }
  }

  function openCreatedCard(): void {
    if (created !== null) {
      router.navigate(`/staff/${created.id}`);
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="head">
      <a class="back" href="#/staff" title="К списку сотрудников" aria-label="К списку сотрудников">
        <Icon name="arrow-left" size={16} />
      </a>
      <div>
        <h1>Новый сотрудник</h1>
        <p class="sub">Создание учётной записи и настройка доступа</p>
      </div>
    </div>
  </div>

  {#if actionError}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span class="notify-text">{actionError}</span>
      <button type="button" class="notify-close" aria-label="Закрыть" onclick={() => (actionError = '')}>
        <Icon name="close" size={14} />
      </button>
    </div>
  {/if}

  <form class="form" onsubmit={submit}>
    <div class="layout">
      <div class="main">
        <section class="card">
          <div class="card-head">
            <h2>Личные данные</h2>
          </div>
          <div class="stack">
            <Input
              label="ФИО *"
              bind:value={form.name}
              maxlength={255}
              error={errors.name}
              placeholder="Иванов Иван Иванович"
              onblur={() => scheduleValidation('name')}
            />
            <Input
              label="Должность"
              bind:value={form.position}
              maxlength={255}
              error={errors.position}
              placeholder="Например, менеджер по продажам"
            />
          </div>
        </section>

        <section class="card">
          <div class="card-head">
            <h2>Организация</h2>
            <HelpTip text="Отдел, город и офис помогают группировать сотрудников и находить их в списке." />
          </div>
          <div class="stack">
            <Input
              label="Отдел"
              bind:value={form.department}
              maxlength={255}
              placeholder="Например, отдел продаж"
            />
            <Input label="Город" bind:value={form.city} maxlength={128} placeholder="Москва" />
            <Input label="Офис" bind:value={form.office} maxlength={128} placeholder="Центральный" />
          </div>
        </section>

        <section class="card">
          <div class="card-head">
            <h2>Контакты</h2>
            <HelpTip text="E-mail используется для служебных писем и как основа для автоматического логина." />
          </div>
          <div class="stack">
            <Input
              label="E-mail *"
              type="email"
              inputmode="email"
              bind:value={form.email}
              maxlength={255}
              error={errors.email}
              placeholder="employee@example.ru"
              onblur={() => scheduleValidation('email')}
            />
            <Input
              label="Телефон"
              type="tel"
              inputmode="tel"
              bind:value={form.phone}
              maxlength={32}
              error={errors.phone}
              placeholder="+7 999 000-00-00"
              onblur={() => scheduleValidation('phone')}
            />
          </div>
        </section>
      </div>

      <aside class="side">
        <section class="card">
          <div class="card-head">
            <h2>Доступ</h2>
          </div>
          {#if canChangeLevel}
            <div class="role-options">
              {#each STAFF_LEVELS as level (level)}
                <label class="role-option" class:on={form.level === String(level)}>
                  <input type="radio" name="staff-level" value={String(level)} bind:group={form.level} />
                  <span class="role-text">
                    <span class="role-title">{staffLevelTitle(level)}</span>
                    <span class="role-desc">{roleDescriptions[level]}</span>
                  </span>
                </label>
              {/each}
            </div>
          {:else}
            <p class="hint">{staffLevelTitle(Number(form.level))}</p>
          {/if}
        </section>

      </aside>
    </div>

    <div class="form-actions">
      <Button variant="ghost" onclick={() => router.navigate('/staff')}>Отмена</Button>
      <Button type="submit" loading={saving}>Создать сотрудника</Button>
    </div>
  </form>
</section>

<Modal
  open={createdOpen}
  title="Сотрудник создан"
  persistent
  onclose={() => router.navigate('/staff')}
>
  {#if created}
    <div class="created-head">
      <p class="created-text">
        Сотрудник создан. Логин сгенерирован из e-mail, пароль — автоматически. Передайте данные
        для входа.
      </p>
      {#if appSettings.mailConfigured}
        <HelpTip text="Письмо с логином и паролем можно отправить на e-mail сотрудника. После закрытия окна пароль не сохранится, поэтому отправить его можно только сейчас." />
      {/if}
    </div>

    <div class="credentials">
      <div class="credential">
        <span>Логин</span>
        <code>{created.login}</code>
        <button type="button" class="copy-btn" onclick={() => copyValue(created?.login ?? '', 'login')}>
          {copied === 'login' ? 'Скопировано' : 'Копировать'}
        </button>
      </div>

      {#if created.password}
        <div class="credential">
          <span>Пароль</span>
          <code>{created.password}</code>
          <button
            type="button"
            class="copy-btn"
            onclick={() => copyValue(created?.password ?? '', 'password')}
          >
            {copied === 'password' ? 'Скопировано' : 'Копировать'}
          </button>
        </div>
      {/if}
    </div>

    <p class="hint">После первого входа сотрудник сможет сменить пароль в профиле.</p>

    {#if credentialsSent}
      <p class="hint">Письмо с данными для входа отправлено на {credentialsSent}.</p>
    {/if}
    {#if credentialsError}
      <p class="form-error">{credentialsError}</p>
    {/if}

    <div class="modal-actions">
      {#if form.email.trim() !== '' && created.password && appSettings.mailConfigured && auth.can('mail.recovery')}
        <Button
          variant="ghost"
          loading={sendingCredentials}
          disabled={credentialsSent !== ''}
          onclick={sendCredentials}
        >
          {credentialsSent ? 'Отправлено' : 'Отправить на почту'}
        </Button>
      {/if}
      <Button onclick={openCreatedCard}>Открыть карточку</Button>
    </div>
  {/if}
</Modal>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .head {
    display: flex;
    align-items: flex-start;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 32px;
    height: 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    text-decoration: none;
    transition: color 0.12s ease, border-color 0.12s ease;
  }

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
  }

  .sub {
    margin: 2px 0 0;
    color: var(--muted);
    font-size: 14px;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(300px, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .main,
  .side {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    max-width: 480px;
  }

  .hint {
    margin: var(--space-3) 0 0;
    color: var(--muted);
    font-size: 13px;
  }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    color: var(--text);
    font-size: 14px;
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .notify-text {
    flex: 1;
  }

  .notify-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    margin: -2px -4px -2px 0;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
  }

  .notify-close:hover {
    color: var(--text);
  }

  .role-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .role-option {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: border-color 0.12s ease, background 0.12s ease;
  }

  .role-option:hover {
    border-color: var(--primary);
  }

  .role-option.on {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, var(--surface));
  }

  .role-option input {
    margin-top: 3px;
  }

  .role-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .role-title {
    font-weight: 500;
  }

  .role-desc {
    color: var(--muted);
    font-size: 12px;
  }

  .form-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
  }

  .created-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-4);
  }

  .created-text {
    margin: 0;
  }

  .form-error {
    margin: var(--space-2) 0 0;
    color: var(--danger);
    font-size: 13px;
  }

  .credentials {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .credential {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--space-2);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .credential span {
    color: var(--muted);
    font-size: 13px;
  }

  .credential code {
    overflow-wrap: anywhere;
    font-family: ui-monospace, SFMono-Regular, Consolas, 'Liberation Mono', monospace;
    font-size: 14px;
  }

  .copy-btn {
    padding: 0;
    border: none;
    background: transparent;
    color: var(--primary);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
  }

  .copy-btn:hover {
    color: var(--primary-hover);
    text-decoration: underline;
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  @media (max-width: 900px) {
    .layout {
      grid-template-columns: 1fr;
    }
  }
</style>
