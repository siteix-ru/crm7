<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError, apiRequest } from '../lib/api/client';
  import { deleteAvatar, updateProfile, uploadAvatar } from '../lib/api/profile';
  import { avatarColor, avatarLetter, avatarName } from '../lib/avatar';
  import {
    getNotificationSettings,
    saveNotificationSettings,
    type NotificationSettingItem
  } from '../lib/api/notifications';
  import { auth } from '../lib/stores/auth.svelte';
  import { avatars } from '../lib/stores/avatar.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { staffLevelTitle } from '../lib/api/staff';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import { tablist } from '../lib/a11y/tablist';
  import Input from '../lib/components/ui/Input.svelte';

  const tabs = [
    { id: 'profile', icon: 'staff', label: 'Профиль' },
    { id: 'notifications', icon: 'bell', label: 'Уведомления' },
    { id: 'security', icon: 'key', label: 'Безопасность' },
    { id: 'logout', icon: 'logout', label: 'Сессии' }
  ] as const;

  type ProfileTab = (typeof tabs)[number]['id'];
  let tab = $state<ProfileTab>('profile');
  let logoutBusy = $state('');
  let logoutNotice = $state('');
  let logoutError = $state('');

  let name = $state(auth.name);
  let position = $state(auth.user?.position ?? '');
  let department = $state(auth.user?.department ?? '');
  let city = $state(auth.user?.city ?? '');
  let office = $state(auth.user?.office ?? '');
  let company = $state(auth.user?.company ?? '');
  let inn = $state(auth.user?.inn ?? '');
  let address = $state(auth.user?.address ?? '');
  let phone = $state(auth.user?.phone ?? '');
  let email = $state(auth.user?.email ?? '');
  const isStaff = $derived(auth.level >= 10);
  let profileBusy = $state(false);
  let profileNotice = $state('');
  let profileError = $state('');

  const avatarUrl = $derived(avatars.url(auth.user?.id));
  let avatarBusy = $state(false);
  let avatarInput: HTMLInputElement | null = $state(null);

  let settings = $state<NotificationSettingItem[]>([]);
  let settingsLoaded = $state(false);
  let settingsBusy = $state(false);
  let settingsNotice = $state('');

  let current = $state('');
  let next = $state('');
  let confirmation = $state('');
  let error = $state('');
  let success = $state('');
  let loading = $state(false);

  const avatarSeed = $derived(avatarName(auth.user?.name, auth.user?.login ?? 'guest'));
  const letter = $derived(avatarLetter(avatarSeed));
  const avatarBg = $derived(avatarColor(avatarSeed));
  const roleTitle = $derived(staffLevelTitle(auth.level));
  function notificationAvailable(event: string): boolean {
    if (!appSettings.notificationsEnabled) {
      return false;
    }

    if (event.startsWith('request.')) {
      return appSettings.requestsEnabled;
    }

    if (event === 'chat.message') {
      return appSettings.chatEnabled;
    }

    if (event.startsWith('substitution.')) {
      return appSettings.substitutionsEnabled;
    }

    if (event.startsWith('user.')) {
      return appSettings.registrationEnabled && appSettings.clientsEnabled;
    }

    return true;
  }

  const visibleSettings = $derived(settings.filter((item) => notificationAvailable(item.event)));
  const showNotifications = $derived(settingsLoaded && visibleSettings.length > 0);
  const visibleTabs = $derived(
    tabs.filter((item) => item.id !== 'notifications' || showNotifications)
  );

  $effect(() => {
    if (tab === 'notifications' && !showNotifications) {
      tab = 'profile';
    }
  });

  onMount(() => {
    if (auth.user) {
      void avatars.load(auth.user.id);
    }

    void getNotificationSettings()
      .then((data) => {
        settings = data.items;
      })
      .catch(() => {
        settings = [];
      })
      .finally(() => {
        settingsLoaded = true;
      });
  });

  async function saveName(): Promise<void> {
    profileBusy = true;
    profileNotice = '';
    profileError = '';

    try {
      const result = await updateProfile(
        isStaff
          ? {
              name: name.trim(),
              position: position.trim(),
              department: department.trim(),
              city: city.trim(),
              office: office.trim(),
              phone: phone.trim(),
              email: email.trim()
            }
          : {
              name: name.trim(),
              position: position.trim(),
              company: company.trim(),
              inn: inn.trim(),
              city: city.trim(),
              address: address.trim(),
              phone: phone.trim(),
              email: email.trim()
            }
      );

      if (auth.user) {
        auth.user.name = result.name;
        auth.user.position = result.position;
        auth.user.department = result.department;
        auth.user.city = result.city;
        auth.user.office = result.office;
        auth.user.company = result.company;
        auth.user.inn = result.inn;
        auth.user.address = result.address;
        auth.user.phone = result.phone;
        auth.user.email = result.email;
      }

      profileNotice = 'Данные сохранены';
    } catch (cause) {
      profileError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить данные';
    } finally {
      profileBusy = false;
    }
  }

  async function onAvatarSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file || !auth.user) {
      return;
    }

    avatarBusy = true;
    profileError = '';
    profileNotice = '';

    try {
      await uploadAvatar(file);
      await avatars.refresh(auth.user.id);
      profileNotice = 'Фото обновлено';
    } catch (cause) {
      profileError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить фото';
    } finally {
      avatarBusy = false;

      if (avatarInput) {
        avatarInput.value = '';
      }
    }
  }

  async function removeAvatar(): Promise<void> {
    if (!auth.user || !confirm('Удалить фото профиля?')) {
      return;
    }

    avatarBusy = true;
    profileError = '';
    profileNotice = '';

    try {
      await deleteAvatar();
      await avatars.refresh(auth.user.id);
      profileNotice = 'Фото удалено';
    } catch (cause) {
      profileError = cause instanceof ApiError ? cause.message : 'Не удалось удалить фото';
    } finally {
      avatarBusy = false;
    }
  }

  async function saveSettings(): Promise<void> {
    settingsBusy = true;
    settingsNotice = '';

    try {
      settings = (await saveNotificationSettings(settings)).items;
      settingsNotice = 'Настройки уведомлений сохранены';
    } catch (cause) {
      settingsNotice = cause instanceof ApiError ? cause.message : 'Не удалось сохранить';
    } finally {
      settingsBusy = false;
    }
  }

  async function logoutCurrent(): Promise<void> {
    if (!confirm('Выйти из текущей сессии?')) {
      return;
    }

    logoutBusy = 'current';
    await auth.logout();
    logoutBusy = '';
  }

  async function logoutOthers(): Promise<void> {
    logoutBusy = 'others';
    logoutNotice = '';
    logoutError = '';

    try {
      const revoked = await auth.logoutOthers();

      logoutNotice = revoked > 0
        ? `Завершено других сессий: ${revoked}`
        : 'Других активных сессий не было';
    } catch (cause) {
      logoutError = cause instanceof ApiError ? cause.message : 'Не удалось завершить другие сессии';
    } finally {
      logoutBusy = '';
    }
  }

  async function logoutAll(): Promise<void> {
    if (!confirm('Выйти из всех сессий на всех устройствах?')) {
      return;
    }

    logoutBusy = 'all';
    await auth.logoutAll();
    logoutBusy = '';
  }

  async function submitPassword(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';
    success = '';

    if (next !== confirmation) {
      error = 'Пароли не совпадают';
      return;
    }

    loading = true;

    try {
      await apiRequest('/auth/password/change', {
        method: 'POST',
        auth: true,
        body: { current_password: current, new_password: next }
      });

      success = 'Пароль изменён. Остальные сессии завершены.';
      current = '';
      next = '';
      confirmation = '';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось изменить пароль';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="hero-row">
      <div class="identity">
        <div class="avatar-hero">
          {#if avatarUrl}
            <img src={avatarUrl} alt="" />
          {:else}
            <span style:background={avatarBg}>{letter}</span>
          {/if}
        </div>
        <div class="head-text">
          <h1>{auth.name}</h1>
          <p class="sub">{roleTitle}</p>
        </div>
      </div>

      <div class="tabs tab-scroll" role="tablist" use:tablist aria-label="Разделы профиля">
        {#each visibleTabs as item (item.id)}
          <button
            type="button"
            role="tab"
            class:active={tab === item.id}
            aria-selected={tab === item.id}
            aria-label={item.label}
            title={item.label}
            onclick={() => (tab = item.id)}
          >
            <Icon name={item.icon} size={16} />
          </button>
        {/each}
      </div>
    </div>
  </div>

  {#if tab === 'profile'}
    {#if profileError}<div class="alert error">{profileError}</div>{/if}
    {#if profileNotice}<div class="alert success">{profileNotice}</div>{/if}

    <div class="profile-grid">
      <div class="card avatar-card">
        <h2>Фото профиля</h2>

        <div class="avatar-big">
          {#if avatarUrl}
            <img src={avatarUrl} alt="" />
          {:else}
            <span style:background={avatarBg}>{letter}</span>
          {/if}
        </div>

        <div class="avatar-actions">
          <label class="upload">
            <input
              bind:this={avatarInput}
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              onchange={onAvatarSelected}
              disabled={avatarBusy}
            />
            <Icon name="camera" size={16} />
            <span>{avatarBusy ? 'Загрузка…' : avatarUrl ? 'Заменить фото' : 'Добавить фото'}</span>
          </label>
          {#if avatarUrl}
            <Button variant="ghost" disabled={avatarBusy} onclick={() => void removeAvatar()}>
              Удалить фото
            </Button>
          {/if}
          <p class="hint">jpg, png или webp, до 5 МБ</p>
        </div>
      </div>

      <form
        class="card form"
        onsubmit={(event) => {
          event.preventDefault();
          void saveName();
        }}
      >
        <div class="card-head">
          <div>
            <h2>Личные данные</h2>
            <p class="hint">Имя видно в чате, заявках и карточках сотрудников.</p>
          </div>
        </div>

        <div class="form-grid">
          <div class="span-2">
            <Input label="Имя" bind:value={name} autocomplete="name" />
          </div>

          {#if isStaff}
            <Input label="Должность" bind:value={position} autocomplete="organization-title" />
            <Input label="Отдел" bind:value={department} />
            <Input label="Телефон" type="tel" bind:value={phone} autocomplete="tel" />
            <Input label="E-mail" type="email" bind:value={email} autocomplete="email" />
            <Input label="Город" bind:value={city} autocomplete="address-level2" />
            <Input label="Офис" bind:value={office} />

            <div class="span-2 readonly">
              <div><span>Логин</span><strong>{auth.user?.login ?? '—'}</strong></div>
              <div><span>Компания</span><strong>{auth.user?.company || '—'}</strong></div>
            </div>
          {:else}
            <Input label="Должность" bind:value={position} />
            <Input label="Компания" bind:value={company} autocomplete="organization" />
            <Input label="ИНН" bind:value={inn} maxlength={12} inputmode="numeric" />
            <Input label="Город" bind:value={city} autocomplete="address-level2" />
            <div class="span-2">
              <Input label="Адрес" bind:value={address} autocomplete="street-address" />
            </div>
            <Input label="Телефон" type="tel" bind:value={phone} autocomplete="tel" />
            <Input label="E-mail" type="email" bind:value={email} autocomplete="email" />

            <div class="span-2 readonly">
              <div><span>Логин</span><strong>{auth.user?.login ?? '—'}</strong></div>
            </div>
            <p class="span-2 hint">
              Логин и тип учётной записи меняет администратор.
            </p>
          {/if}
        </div>

        <div class="form-actions">
          <span class="hint">Изменения сохраняются сразу после нажатия кнопки.</span>
          <Button type="submit" loading={profileBusy} disabled={name.trim().length < 3}>
            Сохранить
          </Button>
        </div>
      </form>
    </div>
  {/if}

  {#if tab === 'notifications'}
    {#if visibleSettings.length > 0}
      <div class="card">
        <div class="card-head">
          <div>
            <h2>Уведомления</h2>
            <p class="hint">Выберите, какие события показывать в системе и отправлять на e-mail.</p>
          </div>
        </div>

        <div class="notify">
          <div class="notify-head">
            <span>Событие</span>
            <span>В системе</span>
            <span>На e-mail</span>
          </div>
          {#each visibleSettings as item (item.event)}
            <div class="notify-row">
              <span class="notify-title">{item.title}</span>
              <label>
                <input
                  type="checkbox"
                  bind:checked={item.in_app}
                  aria-label={`${item.title} — в системе`}
                />
              </label>
              <label>
                <input
                  type="checkbox"
                  bind:checked={item.email}
                  aria-label={`${item.title} — на e-mail`}
                />
              </label>
            </div>
          {/each}
        </div>

        {#if settingsNotice}
          <div class="alert success">{settingsNotice}</div>
        {/if}

        <div class="form-actions">
          <span class="hint">Настройки действуют для вашего профиля.</span>
          <Button loading={settingsBusy} onclick={() => void saveSettings()}>
            Сохранить уведомления
          </Button>
        </div>
      </div>
    {:else}
      <div class="card"><p class="hint">Настройки уведомлений недоступны.</p></div>
    {/if}
  {/if}

  {#if tab === 'security'}
    {#if appSettings.demoMode}
      <div class="card">
        <div class="card-head">
          <div>
            <h2>Смена пароля</h2>
            <p class="hint">В демо-режиме смена пароля недоступна.</p>
          </div>
        </div>
      </div>
    {:else}
      <form class="card form security-form" onsubmit={submitPassword}>
        <div class="card-head">
          <div>
            <h2>Смена пароля</h2>
            <p class="hint">После смены пароля остальные сессии будут завершены.</p>
          </div>
        </div>

        <div class="password-grid">
          <div class="span-2">
            <Input
              label="Текущий пароль"
              type="password"
              bind:value={current}
              autocomplete="current-password"
            />
          </div>
          <Input
            label="Новый пароль"
            type="password"
            bind:value={next}
            autocomplete="new-password"
          />
          <Input
            label="Повторите новый пароль"
            type="password"
            bind:value={confirmation}
            autocomplete="new-password"
          />
        </div>

        <p class="hint">Пароль: не менее 10 символов, буквы и цифры.</p>

        {#if error}
          <div class="alert error">{error}</div>
        {/if}

        {#if success}
          <div class="alert success">{success}</div>
        {/if}

        <div class="form-actions">
          <span class="hint">Пароль должен быть уникальным.</span>
          <Button type="submit" loading={loading} disabled={!current || !next || !confirmation}>
            Изменить пароль
          </Button>
        </div>
      </form>
    {/if}
  {/if}

  {#if tab === 'logout'}
    <div class="card">
      <div class="card-head">
        <div>
          <h2>Сессии и выход</h2>
          <p class="hint">
            Если вы работали с нескольких устройств, можно завершить только текущую сессию, все
            сессии кроме текущей или все сразу.
          </p>
        </div>
      </div>

      {#if logoutNotice}<div class="alert success">{logoutNotice}</div>{/if}
      {#if logoutError}<div class="alert error">{logoutError}</div>{/if}

      <div class="session-list">
        <div class="session-row">
          <span class="session-icon"><Icon name="logout" size={18} /></span>
          <div class="session-copy">
            <strong>Текущая сессия</strong>
            <span>Выйти только на этом устройстве</span>
          </div>
          <Button variant="ghost" loading={logoutBusy === 'current'} onclick={() => void logoutCurrent()}>
            Выйти из текущей сессии
          </Button>
        </div>

        <div class="session-row">
          <span class="session-icon"><Icon name="key" size={18} /></span>
          <div class="session-copy">
            <strong>Другие устройства</strong>
            <span>Завершить все сессии, кроме текущей</span>
          </div>
          <Button variant="ghost" loading={logoutBusy === 'others'} onclick={() => void logoutOthers()}>
            Выйти из всех сессий, кроме текущей
          </Button>
        </div>

        <div class="session-row danger">
          <span class="session-icon"><Icon name="close" size={18} /></span>
          <div class="session-copy">
            <strong>Все устройства</strong>
            <span>Завершить все активные сессии без исключений</span>
          </div>
          <Button variant="danger" loading={logoutBusy === 'all'} onclick={() => void logoutAll()}>
            Выйти из всех сессий
          </Button>
        </div>
      </div>
    </div>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 980px;
    margin: 0 auto;
  }

  .hero-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-4);
  }

  .identity {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    min-width: 0;
  }

  .head-text {
    min-width: 0;
  }

  .avatar-hero {
    width: 56px;
    height: 56px;
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, var(--surface));
    font-size: 20px;
    font-weight: 600;
  }

  .avatar-hero img,
  .avatar-big img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar-hero span,
  .avatar-big span {
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    color: #fff;
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
    margin-left: auto;
  }

  .tabs button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  .tabs button:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .profile-grid {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    align-items: start;
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
    margin-bottom: var(--space-4);
  }

  .card-head .hint {
    margin-top: 4px;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .form-grid,
  .password-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-4);
  }

  .security-form {
    max-width: 680px;
  }

  .span-2 {
    grid-column: 1 / -1;
  }

  .avatar-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-3);
    text-align: center;
  }

  .avatar-card h2 {
    align-self: flex-start;
    width: 100%;
    text-align: left;
  }

  .avatar-big {
    width: 96px;
    height: 96px;
    display: grid;
    place-items: center;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, var(--surface));
    font-size: 30px;
    font-weight: 600;
  }

  .avatar-actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    width: 100%;
  }

  .upload {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    height: 32px;
    padding: 0 14px;
    border: 1px solid var(--primary);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--primary);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  .upload:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .upload input {
    display: none;
  }

  .readonly {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--primary) 3%, var(--surface));
  }

  .readonly div {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .readonly span {
    color: var(--muted);
    font-size: 12px;
  }

  .readonly strong {
    overflow-wrap: anywhere;
    font-size: 14px;
    font-weight: 500;
  }

  .form-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .alert {
    padding: 8px 12px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    font-size: 13px;
  }

  .error {
    border-color: var(--error-border);
    background: var(--danger-bg);
    color: var(--danger);
  }

  .success {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success-text);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
  }

  .notify {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-4);
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .notify-head,
  .notify-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 90px 90px;
    align-items: center;
    gap: var(--space-2);
    padding: 10px var(--space-4);
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .notify-head {
    background: color-mix(in srgb, var(--primary) 4%, var(--surface));
    color: var(--muted);
    font-size: 12px;
  }

  .notify-row:last-child {
    border-bottom: none;
  }

  .notify-row label {
    display: flex;
    justify-content: center;
  }

  .notify-title {
    min-width: 0;
  }

  .session-list {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .session-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--border);
    background: var(--surface);
  }

  .session-row:last-child {
    border-bottom: none;
  }

  .session-row.danger {
    background: color-mix(in srgb, var(--danger) 3%, var(--surface));
  }

  .session-icon {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
    color: var(--primary);
  }

  .session-row.danger .session-icon {
    background: var(--danger-bg);
    color: var(--danger);
  }

  .session-copy {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .session-copy strong {
    font-size: 14px;
    font-weight: 500;
  }

  .session-copy span {
    color: var(--muted);
    font-size: 12px;
  }

  @media (max-width: 900px) {
    .profile-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 640px) {
    .tabs {
      width: 100%;
      margin-left: 0;
    }

    .form-grid,
    .password-grid {
      grid-template-columns: 1fr;
    }

    .notify-head,
    .notify-row {
      grid-template-columns: minmax(0, 1fr) 76px 76px;
      padding: 10px 12px;
    }

    .session-row {
      flex-wrap: wrap;
    }

    .session-row :global(button) {
      width: 100%;
    }
  }
</style>
