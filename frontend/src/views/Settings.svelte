<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    clearQueue,
    getDatabaseSettings,
    getEmailSettings,
    getSystemSettings,
    listModules,
    type ModuleItem,
    listQueue,
    listTemplates,
    saveDatabaseSettings,
    saveEmailSettings,
    saveSystemSettings,
    saveTemplate,
    sendTestEmail,
    testDatabaseSettings,
    testEmailConnection
  } from '../lib/api/admin';
  import {
    deleteSchedule,
    listSchedules,
    saveSchedule,
    sendScheduleNow
  } from '../lib/api/schedules';
  import { listManagers } from '../lib/api/requests';
  import { applyUpdate, checkUpdates, getUpdateStatus } from '../lib/api/updates';
  import type { UpdateCheck, UpdateState, UpdateStatus } from '../lib/api/updates';
  import type {
    DatabaseSettings,
    EmailQueueItem,
    EmailSettings,
    EmailTemplate,
    ManagerItem,
    ReportSchedule,
    SystemSettings
  } from '../lib/api/types';
  import Admin from './Admin.svelte';
  import PageBadge from '../lib/components/dev/PageBadge.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import { settingsBadgeId } from '../lib/dev/page-badges';
  import { APP_VERSION } from '../lib/about';
  import { emailTypeLabel, queueStatusLabel } from '../lib/labels';
  import { formatDateTime } from '../lib/format';

  let settings = $state<EmailSettings | null>(null);
  let templates = $state<EmailTemplate[]>([]);
  let queue = $state<EmailQueueItem[]>([]);
  let loading = $state(true);
  let error = $state('');
  let notice = $state('');
  let busy = $state(false);
  let queueBusy = $state(false);

  let password = $state('');
  let portInput = $state('465');
  let testTo = $state('');
  let templateCode = $state('');
  let templateSubject = $state('');
  let templateBody = $state('');

  let system = $state<SystemSettings | null>(null);
  let mailEnabled = $state(true);
  let appTitle = $state('CRM7');
  let appDescription = $state('');
  let organization = $state('');
  let legalAddress = $state('');
  let requisites = $state('');
  let contactEmail = $state('');
  let contactPhone = $state('');
  let responsible = $state('');

  let db = $state<DatabaseSettings | null>(null);
  let dbPort = $state('3306');
  let dbPassword = $state('');

  let updateCheck = $state<UpdateCheck | null>(null);
  let updateStatus = $state<UpdateStatus | null>(null);
  let updateBusy = $state(false);
  let updateStarting = $state(false);
  let updateError = $state('');
  let updateNotice = $state('');
  let updateChecked = $state(false);
  let updatePoll: ReturnType<typeof setInterval> | null = null;

  let schedules = $state<ReportSchedule[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let editingId = $state<number | null>(null);
  let scheduleTitle = $state('');
  let scheduleType = $state('summary');
  let scheduleFrequency = $state('daily');
  let scheduleTime = $state('09:00');
  let scheduleDayOfWeek = $state('1');
  let scheduleDayOfMonth = $state('1');
  let scheduleRecipients = $state<number[]>([]);
  let scheduleExtra = $state('');
  let scheduleActive = $state(true);

  type SettingsTab = 'general' | 'modules' | 'mail' | 'docs' | 'database' | 'updates';

  type MailTab = 'smtp' | 'templates' | 'schedules' | 'queue';

  const settingsTabs: { code: SettingsTab; title: string; icon: string }[] = [
    { code: 'general', title: 'Общие', icon: 'settings' },
    { code: 'modules', title: 'Модули', icon: 'types' },
    { code: 'mail', title: 'Почта', icon: 'mail' },
    { code: 'docs', title: 'Документы', icon: 'requests' },
    { code: 'database', title: 'База данных', icon: 'database' },
    { code: 'updates', title: 'Обновления', icon: 'refresh' }
  ];

  const mailTabs: { code: MailTab; title: string; icon: string }[] = [
    { code: 'smtp', title: 'SMTP', icon: 'mail' },
    { code: 'templates', title: 'Шаблоны писем', icon: 'requests' },
    { code: 'schedules', title: 'Расписание отчётов', icon: 'calendar' },
    { code: 'queue', title: 'Очередь отправки', icon: 'send' }
  ];

  let moduleItems = $state<ModuleItem[]>([]);
  let modulesLoading = $state(false);
  let modulesError = $state('');
  let modulesExpanded = $state(false);
  let mailExpanded = $state(false);

  const settingsRoute = $derived.by(() => {
    const parts = router.current.path.split('/').filter(Boolean);
    const section = parts[1] ?? '';
    const sub = parts[2] ? decodeURIComponent(parts[2]) : '';

    return { section, sub };
  });

  const canManageSettings = $derived(auth.can('settings.manage'));
  const canManageTech = $derived(auth.can('roles.manage'));
  const canManageMail = $derived(auth.can('mail.manage'));

  const visibleMailTabs = $derived(
    mailTabs.filter((item) => (item.code === 'smtp' ? canManageMail : canManageTech))
  );

  const visibleSettingsTabs = $derived(
    settingsTabs.filter((item) => {
      if (item.code === 'modules' || item.code === 'database' || item.code === 'updates') {
        return canManageTech;
      }

      if (item.code === 'mail') {
        return mailEnabled && (canManageMail || canManageTech);
      }

      return canManageSettings;
    })
  );

  const tab = $derived<SettingsTab>(
    visibleSettingsTabs.some((item) => item.code === settingsRoute.section)
      ? (settingsRoute.section as SettingsTab)
      : (visibleSettingsTabs[0]?.code ?? 'general')
  );

  const mailTab = $derived<MailTab>(
    visibleMailTabs.some((item) => item.code === settingsRoute.sub)
      ? (settingsRoute.sub as MailTab)
      : (visibleMailTabs[0]?.code ?? 'smtp')
  );

  const moduleCode = $derived(
    moduleItems.some((module) => module.code === settingsRoute.sub)
      ? settingsRoute.sub
      : (moduleItems[0]?.code ?? '')
  );

  const activeTitle = $derived.by(() => {
    const section = visibleSettingsTabs.find((item) => item.code === tab);

    if (tab === 'modules') {
      const module = moduleItems.find((item) => item.code === moduleCode);
      return module ? `${section?.title ?? ''} / ${module.title}` : section?.title ?? '';
    }

    if (tab === 'mail') {
      const mail = visibleMailTabs.find((item) => item.code === mailTab);
      return mail ? `${section?.title ?? ''} / ${mail.title}` : section?.title ?? '';
    }

    return section?.title ?? '';
  });

  const pageBadgeId = $derived(
    settingsBadgeId(tab, tab === 'modules' ? moduleCode : tab === 'mail' ? mailTab : '')
  );

  $effect(() => {
    const firstSection = visibleSettingsTabs[0]?.code;

    if (!firstSection) {
      return;
    }

    if (!settingsRoute.section) {
      router.navigate(`/settings/${firstSection}`, true);
      return;
    }

    if (!visibleSettingsTabs.some((item) => item.code === settingsRoute.section)) {
      router.navigate(`/settings/${firstSection}`, true);
      return;
    }

    if (settingsRoute.section === 'modules') {
      if (modulesLoading || moduleItems.length === 0) {
        return;
      }

      if (!moduleItems.some((module) => module.code === settingsRoute.sub)) {
        router.navigate(`/settings/modules/${encodeURIComponent(moduleItems[0].code)}`, true);
      }
    }

    if (settingsRoute.section === 'mail') {
      if (visibleMailTabs.length === 0) {
        return;
      }

      if (!visibleMailTabs.some((item) => item.code === settingsRoute.sub)) {
        router.navigate(`/settings/mail/${visibleMailTabs[0].code}`, true);
      }
    }
  });

  $effect(() => {
    if (tab === 'updates' && canManageTech && !updateChecked && !updateBusy) {
      void checkForUpdates();
    }
  });

  $effect(() => {
    if (tab === 'modules') {
      modulesExpanded = true;
    }
  });

  $effect(() => {
    if (tab === 'mail') {
      mailExpanded = true;
    }
  });

  const updateStateLabels: Record<UpdateState, string> = {
    idle: 'Проверка не запускалась',
    queued: 'Запрос отправлен',
    running: 'Обновление выполняется',
    success: 'Обновление завершено',
    failed: 'Ошибка обновления',
    busy: 'Обновление уже выполняется',
    stalled: 'Служба обновления не отвечает',
    not_configured: 'Автообновление не настроено'
  };

  function updateStateLabel(state: UpdateState): string {
    return updateStateLabels[state] ?? state;
  }

  async function checkForUpdates(): Promise<void> {
    updateBusy = true;
    updateError = '';
    updateNotice = '';

    try {
      updateCheck = await checkUpdates(APP_VERSION);
      const result = await getUpdateStatus(APP_VERSION);
      updateStatus = result.status;

      if (result.status.state === 'queued' || result.status.state === 'running') {
        startUpdatePolling();
      }
    } catch (cause) {
      updateError = cause instanceof ApiError ? cause.message : 'Не удалось проверить обновления';
    } finally {
      updateChecked = true;
      updateBusy = false;
    }
  }

  async function startUpdate(): Promise<void> {
    if (!updateCheck?.release) {
      return;
    }

    if (!confirm(`Обновить CRM7 до ${updateCheck.latest_version ?? updateCheck.release.version}? Сайт кратко перезапустится.`)) {
      return;
    }

    updateStarting = true;
    updateError = '';
    updateNotice = '';

    try {
      const result = await applyUpdate(APP_VERSION);
      updateStatus = result.status;
      updateNotice = `Обновление до ${result.target_version} запущено.`;
      startUpdatePolling();
    } catch (cause) {
      updateError = cause instanceof ApiError ? cause.message : 'Не удалось запустить обновление';
    } finally {
      updateStarting = false;
    }
  }

  function startUpdatePolling(): void {
    stopUpdatePolling();
    updatePoll = setInterval(() => void pollUpdateStatus(), 3000);
    void pollUpdateStatus();
  }

  function stopUpdatePolling(): void {
    if (updatePoll !== null) {
      clearInterval(updatePoll);
      updatePoll = null;
    }
  }

  async function pollUpdateStatus(): Promise<void> {
    try {
      const result = await getUpdateStatus(APP_VERSION);
      updateStatus = result.status;

      if (result.status.state === 'success') {
        stopUpdatePolling();
        updateNotice = `CRM7 обновлён до ${result.status.target_version ?? 'новой версии'}. Перезагрузка страницы…`;
        setTimeout(() => window.location.reload(), 3000);
      } else if (['failed', 'busy', 'stalled', 'not_configured'].includes(result.status.state)) {
        stopUpdatePolling();
        updateError = result.status.message ?? updateStateLabel(result.status.state);
      }
    } catch {
      // keep polling while the site restarts
    }
  }

  onMount(() => {
    void load();

    if (auth.can('roles.manage')) {
      void loadModuleItems();
    }

    return () => stopUpdatePolling();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const canSettings = auth.can('settings.manage');
      const canSmtp = auth.can('mail.manage');
      const canMailAdmin = auth.can('roles.manage');

      if (canSettings) {
        system = await getSystemSettings();
        mailEnabled = system.mail_enabled;
        appTitle = system.app_title;
        appDescription = system.app_description;
        organization = system.organization;
        legalAddress = system.legal_address;
        requisites = system.requisites;
        contactEmail = system.contact_email;
        contactPhone = system.contact_phone;
        responsible = system.responsible;

        if (auth.can('roles.manage')) {
          db = await getDatabaseSettings();

          if (db) {
            dbPort = String(db.port);
          }
        } else {
          db = null;
        }
      } else {
        if (!appSettings.loaded) {
          await appSettings.load();
        }

        mailEnabled = appSettings.mailEnabled;
      }

      managers = (await listManagers()).items;

      if (mailEnabled && canSmtp) {
        settings = await getEmailSettings();
        portInput = String(settings.port);
      } else {
        settings = null;
      }

      if (mailEnabled && canMailAdmin) {
        templates = await listTemplates();
        queue = await listQueue();
        schedules = await listSchedules();

        if (templates.length > 0) {
          selectTemplate(templates[0]);
        }
      } else {
        templates = [];
        queue = [];
        schedules = [];
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить настройки';
    } finally {
      loading = false;
    }
  }

  async function loadModuleItems(force = false): Promise<void> {
    if (modulesLoading) {
      return;
    }

    if (!force && moduleItems.length > 0) {
      return;
    }

    modulesLoading = true;
    modulesError = '';

    try {
      const data = await listModules();
      moduleItems = data.modules;
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить модули';
    } finally {
      modulesLoading = false;
    }
  }

  function syncModuleItems(next: ModuleItem[]): void {
    moduleItems = next;
  }

  async function onModulesSaved(): Promise<void> {
    void loadModuleItems(true);
    void appSettings.load();
    try {
      const data = await getSystemSettings();
      system = data;
      mailEnabled = data.mail_enabled;

      if (!mailEnabled) {
        settings = null;
        templates = [];
        queue = [];
        schedules = [];
        return;
      }

      if (auth.can('mail.manage') && settings === null) {
        settings = await getEmailSettings();
        portInput = String(settings.port);
      }

      if (auth.can('roles.manage')) {
        templates = await listTemplates();
        queue = await listQueue();
        schedules = await listSchedules();

        if (templates.length > 0) {
          selectTemplate(templates[0]);
        }
      } else {
        templates = [];
        queue = [];
        schedules = [];
      }
    } catch {}
  }

  async function clearQueueAction(): Promise<void> {
    if (!confirm('Очистить очередь отправки? Все записи будут удалены.')) {
      return;
    }

    queueBusy = true;
    error = '';
    notice = '';

    try {
      const result = await clearQueue();
      queue = [];
      notice =
        result.cleared > 0
          ? `Очередь очищена: удалено записей — ${result.cleared}`
          : 'Очередь уже пуста';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось очистить очередь';
    } finally {
      queueBusy = false;
    }
  }

  function selectTemplate(template: EmailTemplate): void {
    templateCode = template.code;
    templateSubject = template.subject;
    templateBody = template.body;
  }

async function saveSystem(): Promise<void> {
  busy = true;
  error = '';
  notice = '';

  try {
    system = await saveSystemSettings({
      app_title: appTitle,
      app_description: appDescription,
      organization,
      legal_address: legalAddress,
      requisites,
      contact_email: contactEmail,
      contact_phone: contactPhone,
      responsible,
    });

    mailEnabled = system.mail_enabled;
    appTitle = system.app_title;
    appDescription = system.app_description;
    organization = system.organization;
    legalAddress = system.legal_address;
    requisites = system.requisites;
    contactEmail = system.contact_email;
    contactPhone = system.contact_phone;
    responsible = system.responsible;
    await appSettings.load();
    notice = 'Настройки системы сохранены';
  } catch (cause) {
    error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить настройки';
  } finally {
    busy = false;
  }
}

async function saveSettings(): Promise<void> {
  if (!settings) return;

    busy = true;
    error = '';
    notice = '';

    try {
      settings = await saveEmailSettings({
        host: settings.host,
        port: Number(portInput) || 0,
        encryption: settings.encryption,
        username: settings.username,
        password: password !== '' ? password : undefined,
        from: settings.from,
        from_name: settings.from_name
      });
      password = '';
      notice = 'Настройки сохранены';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить настройки';
    } finally {
      busy = false;
    }
  }

  async function checkConnection(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      await testEmailConnection();
      notice = 'Соединение с SMTP установлено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось подключиться';
    } finally {
      busy = false;
    }
  }

  function databasePayload(): {
    host: string;
    port: number;
    database: string;
    user: string;
    password?: string;
  } {
    return {
      host: db?.host ?? '',
      port: Number(dbPort) || 0,
      database: db?.database ?? '',
      user: db?.user ?? '',
      ...(dbPassword !== '' ? { password: dbPassword } : {})
    };
  }

  async function checkDatabase(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      const result = await testDatabaseSettings(databasePayload());
      notice = `Подключение установлено (сервер ${result.server})`;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось подключиться к базе данных';
    } finally {
      busy = false;
    }
  }

  async function saveDatabase(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      db = await saveDatabaseSettings(databasePayload());

      if (db) {
        dbPort = String(db.port);
        dbPassword = '';

        const warnings = db.warnings ?? [];
        notice = [
          'Настройки базы данных сохранены. При необходимости войдите заново — сессии прежней базы не действуют.',
          ...warnings
        ].join(' ');
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить настройки базы данных';
    } finally {
      busy = false;
    }
  }

  async function sendTest(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      await sendTestEmail(testTo.trim());
      notice = 'Тестовое письмо отправлено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить письмо';
    } finally {
      busy = false;
    }
  }

  function resetScheduleForm(): void {
    editingId = null;
    scheduleTitle = '';
    scheduleType = 'summary';
    scheduleFrequency = 'daily';
    scheduleTime = '09:00';
    scheduleDayOfWeek = '1';
    scheduleDayOfMonth = '1';
    scheduleRecipients = [];
    scheduleExtra = '';
    scheduleActive = true;
  }

  function startEdit(schedule: ReportSchedule): void {
    editingId = schedule.id;
    scheduleTitle = schedule.title;
    scheduleType = schedule.report_type;
    scheduleFrequency = schedule.frequency;
    scheduleTime = schedule.time_of_day;
    scheduleDayOfWeek = String(schedule.day_of_week ?? 1);
    scheduleDayOfMonth = String(schedule.day_of_month ?? 1);
    scheduleRecipients = schedule.recipients.map((item) => item.id);
    scheduleExtra = schedule.extra_emails.join(', ');
    scheduleActive = schedule.is_active;
  }

  async function submitSchedule(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      schedules = await saveSchedule(editingId, {
        title: scheduleTitle.trim(),
        report_type: scheduleType,
        frequency: scheduleFrequency,
        time_of_day: scheduleTime,
        day_of_week: scheduleFrequency === 'weekly' ? Number(scheduleDayOfWeek) : null,
        day_of_month: scheduleFrequency === 'monthly' ? Number(scheduleDayOfMonth) : null,
        recipients: scheduleRecipients,
        extra_emails: scheduleExtra
          .split(',')
          .map((item) => item.trim())
          .filter((item) => item !== ''),
        is_active: scheduleActive
      });
      resetScheduleForm();
      notice = 'Расписание сохранено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить расписание';
    } finally {
      busy = false;
    }
  }

  async function removeSchedule(id: number): Promise<void> {
    busy = true;
    error = '';

    try {
      await deleteSchedule(id);
      schedules = await listSchedules();
      notice = 'Расписание удалено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить расписание';
    } finally {
      busy = false;
    }
  }

  async function sendSchedule(id: number): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      const result = await sendScheduleNow(id);
      schedules = await listSchedules();
      notice = `Отчёт отправлен получателям: ${result.sent}`;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить отчёт';
    } finally {
      busy = false;
    }
  }

  function frequencyLabel(schedule: ReportSchedule): string {
    if (schedule.frequency === 'weekly') return 'еженедельно';
    if (schedule.frequency === 'monthly') return 'ежемесячно';

    return 'ежедневно';
  }

  async function saveCurrentTemplate(): Promise<void> {
    if (!templateCode) return;

    busy = true;
    error = '';
    notice = '';

    try {
      templates = await saveTemplate(templateCode, templateSubject, templateBody);
      notice = 'Шаблон сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить шаблон';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page page-wide page-full">
  <div class="settings-layout">
    <aside class="settings-sidebar" aria-label="Настройки">
      <div class="settings-sidebar-head">
        <span class="settings-sidebar-title">Настройки</span>
      </div>

      <nav class="settings-nav">
        {#each visibleSettingsTabs as item (item.code)}
          {#if item.code === 'modules'}
            <button
              type="button"
              class="settings-nav-item"
              class:active={tab === item.code}
              aria-expanded={modulesExpanded}
              onclick={() => {
                modulesExpanded = !modulesExpanded;
                if (modulesExpanded) mailExpanded = false;
              }}
            >
              <Icon name={item.icon} size={16} />
              <span class="settings-nav-label">{item.title}</span>
              <Icon name={modulesExpanded ? 'arrow-up' : 'arrow-down'} size={14} />
            </button>

            {#if modulesExpanded}
              <div class="settings-nav-children">
                {#if modulesLoading}
                  <div class="settings-nav-state"><Spinner size={14} /></div>
                {:else if modulesError}
                  <div class="settings-nav-state error">{modulesError}</div>
                {:else if moduleItems.length === 0}
                  <div class="settings-nav-state">Нет модулей</div>
                {:else}
                  {#each moduleItems as module (module.code)}
                    <a
                      class="settings-nav-child"
                      class:active={tab === 'modules' && moduleCode === module.code}
                      href={`#/settings/modules/${encodeURIComponent(module.code)}`}
                      aria-current={tab === 'modules' && moduleCode === module.code ? 'page' : undefined}
                      onclick={() => {
                        modulesExpanded = true;
                        mailExpanded = false;
                      }}
                    >
                      <span class="settings-nav-label">{module.title}</span>
                      {#if !module.enabled}
                        <span class="settings-nav-dot" title="Отключён" aria-label="Отключён"></span>
                      {/if}
                    </a>
                  {/each}
                {/if}
              </div>
            {/if}
          {:else if item.code === 'mail'}
            <button
              type="button"
              class="settings-nav-item"
              class:active={tab === item.code}
              aria-expanded={mailExpanded}
              onclick={() => {
                mailExpanded = !mailExpanded;
                if (mailExpanded) modulesExpanded = false;
              }}
            >
              <Icon name={item.icon} size={16} />
              <span class="settings-nav-label">{item.title}</span>
              <Icon name={mailExpanded ? 'arrow-up' : 'arrow-down'} size={14} />
            </button>

            {#if mailExpanded}
              <div class="settings-nav-children">
                {#each visibleMailTabs as mailItem (mailItem.code)}
                  <a
                    class="settings-nav-child"
                    class:active={tab === 'mail' && mailTab === mailItem.code}
                    href={`#/settings/mail/${mailItem.code}`}
                    aria-current={tab === 'mail' && mailTab === mailItem.code ? 'page' : undefined}
                    onclick={() => {
                      mailExpanded = true;
                      modulesExpanded = false;
                    }}
                  >
                    <span class="settings-nav-label">{mailItem.title}</span>
                  </a>
                {/each}
              </div>
            {/if}
          {:else}
            <a
              class="settings-nav-item"
              class:active={tab === item.code}
              href={`#/settings/${item.code}`}
              aria-current={tab === item.code ? 'page' : undefined}
              onclick={() => {
                modulesExpanded = false;
                mailExpanded = false;
              }}
            >
              <Icon name={item.icon} size={16} />
              <span class="settings-nav-label">{item.title}</span>
            </a>
          {/if}
        {/each}
      </nav>
    </aside>

    <div class="settings-main">
      <div class="settings-main-head">
        <div class="head">
          <h1>{activeTitle || 'Настройки'}</h1>
        </div>
      </div>


  {#if error}
    <div class="alert">{error}</div>
  {/if}
  {#if notice}
    <div class="notice">{notice}</div>
  {/if}

  {#if visibleSettingsTabs.length === 0}
    <div class="card">
      <h2>Нет доступных настроек</h2>
      <p class="hint">Для вашей роли не настроен доступ ни к одному разделу.</p>
    </div>
  {:else if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else}


    {#if tab === 'modules'}
      {#if moduleCode}
        <Admin
          section="modules"
          moduleCode={moduleCode}
          onsaved={onModulesSaved}
          onmoduleschange={syncModuleItems}
        />
      {:else if modulesLoading}
        <div class="center"><Spinner size={26} /></div>
      {:else}
        <div class="card">
          <p class="hint">Выберите модуль в меню «Модули».</p>
        </div>
      {/if}
    {:else if tab === 'docs'}
      <Admin section="docs" />
    {/if}

    {#if tab === 'database'}
      <div class="card">
        <h2>База данных</h2>

      {#if db}
        <p class="hint">
          Источник настроек:
          {db.source === 'file' ? 'файл database.json (задан в этой форме)' : '.env и переменные окружения'} ·
          <span class="mono">{db.file}</span>
        </p>

        <div class="grid">
          <Input label="Сервер" bind:value={db.host} placeholder="127.0.0.1" />
          <Input label="Порт" type="number" bind:value={dbPort} />
          <Input label="База данных" bind:value={db.database} placeholder="crm_prod" />
          <Input label="Пользователь" bind:value={db.user} placeholder="crm_app" />
          <Input
            label={db.has_password ? 'Пароль (задан)' : 'Пароль'}
            type="password"
            bind:value={dbPassword}
            placeholder={db.has_password ? 'Оставьте пустым, чтобы не менять' : ''}
            autocomplete="new-password"
          />
        </div>

        <div class="actions">
          <Button loading={busy} onclick={saveDatabase}>Сохранить</Button>
          <Button variant="ghost" loading={busy} onclick={checkDatabase}>Проверить подключение</Button>
        </div>

        <p class="hint">
          Сохранение сначала проверяет подключение, затем пишет файл рядом с .env (прежний файл сохраняется как
          database.json.bak). После смены базы данных войдите заново: сессии хранятся в базе.
        </p>
      {/if}
      </div>
    {/if}

    {#if tab === 'updates'}
      <div class="card">
        <h2>Обновления</h2>

        <p class="hint">
          Проверка публичного фида <span class="mono">update.crm7.ru</span>. Текущая версия:
          <strong>{updateCheck?.current_version ?? APP_VERSION}</strong>.
        </p>

        {#if updateError}
          <div class="alert">{updateError}</div>
        {/if}
        {#if updateNotice}
          <div class="notice">{updateNotice}</div>
        {/if}

        <div class="actions">
          <Button loading={updateBusy} onclick={() => void checkForUpdates()}>
            <Icon name="refresh" size={16} />
            Проверить обновления
          </Button>
          {#if updateCheck?.update_available && updateCheck.release && updateCheck.configured}
            <Button
              variant="success"
              loading={updateStarting}
              disabled={updateStatus?.state === 'queued' || updateStatus?.state === 'running'}
              onclick={() => void startUpdate()}
            >
              <Icon name="download" size={16} />
              Обновить до {updateCheck.latest_version ?? updateCheck.release.version}
            </Button>
          {/if}
        </div>

        {#if updateCheck}
          <div class="grid">
            <div>
              <div class="label">Текущая версия</div>
              <div>{updateCheck.current_version ?? 'не определена'}</div>
            </div>
            <div>
              <div class="label">Доступная версия</div>
              <div>{updateCheck.latest_version ?? '—'}</div>
            </div>
          </div>

          {#if updateCheck.release}
            <p class="hint">{updateCheck.release.released_at} · {updateCheck.release.notes}</p>
          {:else}
            <p class="hint">Обновлений нет.</p>
          {/if}

          {#if updateCheck.chain.length > 1}
            <p class="hint">
              Будет применено обновлений: {updateCheck.chain.length} —
              {updateCheck.chain.map((item) => item.version).join(' → ')}
            </p>
          {/if}

          {#if !updateCheck.configured}
            <p class="hint">
              Автообновление не настроено: каталог <span class="mono">CRM7_UPDATE_DIR</span> недоступен.
              Используйте CLI <span class="mono">python deploy/crm7_updater.py --apply</span>.
            </p>
          {/if}
        {/if}

        {#if updateStatus && updateStatus.state !== 'idle'}
          <h3>Статус: {updateStateLabel(updateStatus.state)}</h3>
          {#if updateStatus.target_version}
            <p class="hint">Версия: {updateStatus.target_version}</p>
          {/if}
          {#if updateStatus.message}
            <p class="hint">{updateStatus.message}</p>
          {/if}
          {#if updateStatus.log_tail}
            <pre class="log">{updateStatus.log_tail}</pre>
          {/if}
        {/if}
      </div>
    {/if}

    {#if tab === 'mail' && mailTab === 'smtp' && settings}
      <div class="card">
        <h2>SMTP</h2>

      <p class="hint">
        {settings.enabled
          ? 'SMTP настроен — отправка писем доступна.'
          : 'SMTP не настроен — отправка писем недоступна. Заполните параметры и сохраните.'}
      </p>

      <div class="grid">
        <Input label="SMTP-хост" bind:value={settings.host} placeholder="smtp.yandex.ru" />
        <Input label="Порт" type="number" bind:value={portInput} />
        <label class="field">
          <span class="label">Шифрование</span>
          <select bind:value={settings.encryption}>
            <option value="ssl">SSL (465)</option>
            <option value="tls">STARTTLS (587)</option>
            <option value="none">Без шифрования</option>
          </select>
        </label>
        <Input label="Логин" bind:value={settings.username} placeholder="user@yandex.ru" />
        <Input
          label={settings.has_password ? 'Пароль (задан)' : 'Пароль'}
          type="password"
          bind:value={password}
          placeholder={settings.has_password ? 'Оставьте пустым, чтобы не менять' : ''}
          autocomplete="new-password"
        />
        <Input label="Адрес отправителя" bind:value={settings.from} placeholder="user@yandex.ru" />
        <Input label="Имя отправителя" bind:value={settings.from_name} placeholder="CRM" />
      </div>

      <div class="actions">
        <Button loading={busy} onclick={saveSettings}>Сохранить</Button>
        <Button variant="ghost" loading={busy} onclick={checkConnection}>Проверить соединение</Button>
      </div>

      <div class="test">
        <Input label="Тестовое письмо на адрес" bind:value={testTo} placeholder="you@example.com" />
        <Button
          variant="ghost"
          loading={busy}
          disabled={!testTo.trim() || !settings.enabled}
          onclick={sendTest}
        >
          Отправить тест
        </Button>
      </div>

      <p class="hint">
        Письма уходят через очередь: cron-задача <code>php api/bin/cron.php</code> раз в минуту.
      </p>
      </div>
    {/if}

    {#if tab === 'general'}
      <div class="card">
        <h2>Брендинг и реквизиты</h2>

        <h3>Оформление</h3>
        <div class="grid">
          <Input label="Название CRM (в шапке и на входе)" bind:value={appTitle} placeholder="CRM7" />
        </div>

        <label class="field">
          <span class="label">Описание системы (подсказка при наведении на логотип и название)</span>
          <textarea
            bind:value={appDescription}
            rows="3"
            maxlength="300"
            placeholder="Кратко о системе и её назначении"
          ></textarea>
        </label>

        <h3>Реквизиты для документов</h3>

        <div class="grid">
          <Input label="Наименование организации" bind:value={organization} />
          <Input label="E-mail" type="email" bind:value={contactEmail} />
          <Input label="Телефон" type="tel" bind:value={contactPhone} />
          <Input label="Реквизиты (ИНН, ОГРН и др.)" bind:value={requisites} />
        </div>

        <label class="field">
          <span class="label">Адрес</span>
          <textarea bind:value={legalAddress} rows="2" maxlength="500"></textarea>
        </label>

        <label class="field">
          <span class="label">ФИО, должность и контакты руководителя и ответственного за работу сайта</span>
          <textarea bind:value={responsible} rows="3" maxlength="500"></textarea>
        </label>

        <p class="hint">
          Эти данные подставляются в политику обработки персональных данных, согласие и другие
          юридические документы вместо полей в квадратных скобках.
        </p>

        <div class="actions">
          <Button loading={busy} onclick={() => void saveSystem()}>Сохранить</Button>
        </div>
      </div>
    {/if}

    {#if tab === 'mail' && mailTab === 'templates' && canManageTech}
      <div class="card">
        <h2>Шаблоны писем</h2>
      <p class="hint">Подстановки: {'{title}'}, {'{body}'}, {'{request_number}'}, {'{link}'}</p>

      <div class="templates">
        {#each templates as template}
          <button
            type="button"
            class="template-item"
            class:active={template.code === templateCode}
            title={template.code}
            onclick={() => selectTemplate(template)}
          >
            {emailTypeLabel(template.code)}
          </button>
        {/each}
      </div>

      {#if templateCode}
        <Input label="Тема" bind:value={templateSubject} />
        <label class="field">
          <span class="label">Текст письма</span>
          <textarea bind:value={templateBody} rows="8"></textarea>
        </label>
        <div class="actions">
          <Button loading={busy} onclick={saveCurrentTemplate}>Сохранить шаблон</Button>
        </div>
      {/if}
      </div>
    {/if}

    {#if tab === 'mail' && mailTab === 'schedules' && canManageTech}
      <div class="card">
        <h2>Расписание отчётов</h2>
      <p class="hint">
        Отчёты отправляются cron-задачей: кому, когда и какой отчёт. Получатели — сотрудники с
        заполненным e-mail.
      </p>

      {#if schedules.length === 0}
        <p class="hint">Расписаний пока нет</p>
      {:else}
        <div class="schedules">
          {#each schedules as schedule (schedule.id)}
            <div class="schedule-row">
              <div class="s-info">
                <span class="s-title">{schedule.title}</span>
                <span class="s-meta">
                  {schedule.report_type === 'managers' ? 'по менеджерам' : 'сводка по заявкам'} ·
                  {frequencyLabel(schedule)} · {schedule.time_of_day} · получатели:
                  {schedule.recipients.map((item) => item.name).join(', ') || '—'}
                  {schedule.extra_emails.length > 0 ? ', ' + schedule.extra_emails.join(', ') : ''}
                </span>
                <span class="s-meta">
                  {schedule.is_active ? 'активно' : 'выключено'}
                  {#if schedule.last_sent_at}
                    · последняя отправка {formatDateTime(schedule.last_sent_at)}
                  {/if}
                </span>
              </div>
              <div class="s-actions">
                <Button variant="ghost" disabled={busy} onclick={() => startEdit(schedule)}>
                  Изменить
                </Button>
                <Button variant="ghost" loading={busy} onclick={() => void sendSchedule(schedule.id)}>
                  Отправить сейчас
                </Button>
                <button
                  type="button"
                  class="s-del"
                  disabled={busy}
                  onclick={() => void removeSchedule(schedule.id)}
                >
                  Удалить
                </button>
              </div>
            </div>
          {/each}
        </div>
      {/if}

      <h3>{editingId === null ? 'Новое расписание' : 'Изменение расписания'}</h3>

      <div class="grid">
        <Input label="Название" bind:value={scheduleTitle} placeholder="Утренняя сводка" />

        <label class="field">
          <span class="label">Отчёт</span>
          <select bind:value={scheduleType}>
            <option value="summary">Сводка по заявкам</option>
            <option value="managers">По менеджерам</option>
          </select>
        </label>

        <label class="field">
          <span class="label">Периодичность</span>
          <select bind:value={scheduleFrequency}>
            <option value="daily">Ежедневно</option>
            <option value="weekly">Еженедельно</option>
            <option value="monthly">Ежемесячно</option>
          </select>
        </label>

        <Input label="Время (ЧЧ:ММ)" type="time" bind:value={scheduleTime} />

        {#if scheduleFrequency === 'weekly'}
          <label class="field">
            <span class="label">День недели</span>
            <select bind:value={scheduleDayOfWeek}>
              <option value="1">Понедельник</option>
              <option value="2">Вторник</option>
              <option value="3">Среда</option>
              <option value="4">Четверг</option>
              <option value="5">Пятница</option>
              <option value="6">Суббота</option>
              <option value="7">Воскресенье</option>
            </select>
          </label>
        {/if}

        {#if scheduleFrequency === 'monthly'}
          <label class="field">
            <span class="label">День месяца</span>
            <select bind:value={scheduleDayOfMonth}>
              {#each Array.from({ length: 28 }, (_, index) => String(index + 1)) as day}
                <option value={day}>{day}</option>
              {/each}
            </select>
          </label>
        {/if}

        <label class="field">
          <span class="label">Получатели (сотрудники)</span>
          <select multiple bind:value={scheduleRecipients} size="4">
            {#each managers as manager}
              <option value={manager.id}>
                {manager.name}{manager.email ? ` (${manager.email})` : ''}
              </option>
            {/each}
          </select>
        </label>

        <Input label="Дополнительные e-mail (через запятую)" bind:value={scheduleExtra} />
      </div>

      <label class="checkbox">
        <input type="checkbox" bind:checked={scheduleActive} />
        Активно
      </label>

      <div class="actions">
        <Button loading={busy} disabled={!scheduleTitle.trim()} onclick={submitSchedule}>
          {editingId === null ? 'Добавить расписание' : 'Сохранить изменения'}
        </Button>
        {#if editingId !== null}
          <Button variant="ghost" onclick={resetScheduleForm}>Отмена</Button>
        {/if}
      </div>
      </div>
    {/if}

    {#if tab === 'mail' && mailTab === 'queue' && canManageTech}
      <div class="card">
        <div class="queue-head">
          <div>
            <h2>Очередь отправки</h2>
            <p class="hint">Последние письма и результат отправки. Очистка удаляет все записи очереди.</p>
          </div>
          <Button
            variant="danger"
            loading={queueBusy}
            disabled={queue.length === 0}
            onclick={() => void clearQueueAction()}
          >
            Очистить
          </Button>
        </div>
      {#if queue.length === 0}
        <p class="hint">Очередь пуста</p>
      {:else}
        <div class="queue">
          {#each queue as item (item.id)}
            <div class="queue-row">
              <span class="q-to">{item.to}</span>
              <span class="q-subject">{item.subject}</span>
              <span class="q-status" class:failed={item.status === 'failed'} class:pending={item.status === 'pending'}>
                {queueStatusLabel(item.status)}
              </span>
              <span class="q-date">{formatDateTime(item.sent_at ?? item.created_at)}</span>
            </div>
            {#if item.last_error}
              <div class="q-error">{item.last_error}</div>
            {/if}
          {/each}
        </div>
      {/if}
      </div>
    {/if}
  {/if}
    </div>
  </div>

  {#if import.meta.env.DEV}
    <PageBadge id={pageBadgeId} />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: none;
    margin: 0;
  }

  .page > :not(.page-hero) {
    width: 100%;
    max-width: 900px;
    margin-inline: auto;
  }
  .page > .settings-layout {
    max-width: 1240px;
  }

  .settings-layout {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: var(--space-5);
    align-items: start;
    width: 100%;
    max-width: 1240px;
    margin-inline: auto;
  }

  .settings-sidebar {
    position: sticky;
    top: var(--space-4);
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
  }

  .settings-sidebar-head {
    padding: 6px 10px 10px;
  }

  .settings-sidebar-title {
    color: var(--text);
    font-size: 16px;
    font-weight: 600;
  }

  .settings-nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .settings-nav-item,
  .settings-nav-child {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: 34px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 13px;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
  }

  .settings-nav-item {
    gap: 8px;
    padding: 7px 10px;
  }

  .settings-nav-label {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .settings-nav-item:hover,
  .settings-nav-child:hover {
    background: var(--fill-tertiary);
  }

  .settings-nav-item.active,
  .settings-nav-child.active {
    border-color: transparent;
    background: var(--primary-bg);
    color: var(--primary);
    font-weight: 600;
  }

  .settings-nav-children {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 2px 0 6px 14px;
    padding-left: 8px;
    border-left: 1px solid var(--border-secondary);
  }

  .settings-nav-child {
    min-height: 30px;
    gap: 8px;
    padding: 6px 10px;
    color: var(--muted);
  }

  .settings-nav-state {
    padding: 6px 10px;
    color: var(--muted);
    font-size: 12px;
  }

  .settings-nav-state.error {
    color: var(--danger);
  }

  .settings-nav-dot {
    flex: 0 0 6px;
    width: 6px;
    height: 6px;
    margin-left: 2px;
    border-radius: 50%;
    background: var(--muted);
  }

  .settings-main {
    width: 100%;
    max-width: 960px;
    min-width: 0;
  }

  .settings-main-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
  }


  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .head {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
  }

  @media (max-width: 900px) {
    .settings-layout {
      grid-template-columns: minmax(0, 1fr);
    }

    .settings-sidebar {
      position: static;
      max-height: none;
    }

    .settings-main {
      max-width: none;
    }
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 13px;
    color: var(--muted);
  }

  select,
  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: inherit;
    outline: none;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    border-color: var(--primary);
  }

  .checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
  }

  .actions,
  .test {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: flex-end;
  }

  .templates {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  h3 {
    margin: var(--space-3) 0 0;
    font-size: 14px;
  }

  .log {
    margin: 0;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    max-height: 260px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
