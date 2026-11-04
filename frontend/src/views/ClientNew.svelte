<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { createClient, type ClientCreateResult } from '../lib/api/clients';
  import { listPriceTypes } from '../lib/api/prices';
  import { listManagers } from '../lib/api/requests';
  import type { ManagerItem, PriceType } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const innPattern = /^\d{10}(\d{2})?$/;
  const loginPattern = /^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/;

  let form = $state({
    name: '',
    company: '',
    inn: '',
    position: '',
    city: '',
    address: '',
    phone: '',
    email: '',
    login: ''
  });
  let errors = $state<Record<string, string>>({});
  let saving = $state(false);
  let actionError = $state('');
  let managers = $state<ManagerItem[]>([]);
  let managerId = $state('');
  let priceTypes = $state<PriceType[]>([]);
  let selectedPriceTypes = $state<string[]>([]);
  let optionsLoading = $state(false);
  let created = $state<ClientCreateResult | null>(null);
  let createdOpen = $state(false);
  let copied = $state('');

  const canAssign = $derived(auth.can('clients.assign') && appSettings.managerAssignEnabled);
  const canAssignPrices = $derived(appSettings.pricesEnabled && auth.can('prices.assign'));
  const priceTypeOptions = $derived(
    priceTypes.map((type) => ({ value: String(type.id), label: type.title }))
  );
  const allowed = $derived(auth.level >= 10);

  onMount(() => {
    if (allowed) {
      void loadOptions();
    }
  });

  async function loadOptions(): Promise<void> {
    optionsLoading = true;
    const failures: string[] = [];
    const needManagers = canAssign;
    const needPrices = canAssignPrices;

    if (needManagers) {
      try {
        managers = (await listManagers()).items;
      } catch (cause) {
        failures.push(cause instanceof ApiError ? cause.message : 'Не удалось загрузить менеджеров');
      }
    }

    if (needPrices) {
      try {
        priceTypes = (await listPriceTypes()).items;

        const retail = priceTypes.find((type) => type.code === 'retail');
        selectedPriceTypes = retail ? [String(retail.id)] : [];
      } catch (cause) {
        failures.push(cause instanceof ApiError ? cause.message : 'Не удалось загрузить типы цен');
      }
    }

    if (failures.length > 0) {
      actionError = failures.join('. ');
    }

    optionsLoading = false;
  }

  function validate(): boolean {
    const next: Record<string, string> = {};
    const name = form.name.trim();
    const email = form.email.trim();
    const inn = form.inn.trim();
    const phone = form.phone.trim();
    const login = form.login.trim();
    const digits = phone.replace(/\D/g, '');

    if (name.length < 2) {
      next.name = 'Укажите имя клиента (минимум 2 символа)';
    }

    if (email !== '' && !emailPattern.test(email)) {
      next.email = 'Некорректный e-mail';
    }

    if (inn !== '' && !innPattern.test(inn)) {
      next.inn = 'ИНН должен содержать 10 или 12 цифр';
    }

    if (phone !== '' && (digits.length < 10 || digits.length > 15)) {
      next.phone = 'Телефон должен содержать от 10 до 15 цифр';
    }

    if (login !== '' && !loginPattern.test(login)) {
      next.login = '3–64 символа: латиница, цифры, точка, дефис, подчёркивание или @';
    }

    errors = next;

    return Object.keys(next).length === 0;
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    actionError = '';

    if (!validate()) {
      return;
    }

    saving = true;

    try {
      created = await createClient({
        name: form.name.trim(),
        company: form.company.trim(),
        inn: form.inn.trim(),
        position: form.position.trim(),
        city: form.city.trim(),
        address: form.address.trim(),
        phone: form.phone.trim(),
        email: form.email.trim(),
        login: form.login.trim(),
        manager_id: managerId === '' ? null : Number(managerId),
        price_type_ids: selectedPriceTypes.map(Number)
      });
      createdOpen = true;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось создать клиента';
    } finally {
      saving = false;
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
      router.navigate(`/clients/${created.id}`);
    }
  }
</script>

<section class="page">
  <div class="page-hero">
    <div class="head">
      <a class="back" href="#/clients" title="К списку клиентов" aria-label="К списку клиентов">
        <Icon name="arrow-left" size={16} />
      </a>
      <div>
        <h1>Новый клиент</h1>
        <p class="sub">Создание карточки и учётной записи клиента</p>
      </div>
    </div>
  </div>

  {#if allowed}
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
            <h2>Личные данные</h2>
            <div class="form-groups">
              <div class="form-group">
                <span class="form-group-title">Контактное лицо</span>
                <div class="grid">
                  <Input
                    inline
                    label="Имя клиента *"
                    bind:value={form.name}
                    maxlength={255}
                    error={errors.name}
                    placeholder="Например, Иван Петров"
                  />
                  <Input
                    inline
                    label="Должность"
                    bind:value={form.position}
                    maxlength={255}
                    error={errors.position}
                    placeholder="Например, директор"
                  />
                </div>
              </div>

              <div class="form-group">
                <span class="form-group-title">Компания</span>
                <div class="grid">
                  <Input
                    inline
                    label="Компания"
                    bind:value={form.company}
                    maxlength={255}
                    error={errors.company}
                    placeholder="Название компании"
                  />
                  <Input
                    inline
                    label="ИНН"
                    bind:value={form.inn}
                    inputmode="numeric"
                    maxlength={12}
                    error={errors.inn}
                    placeholder="10 или 12 цифр"
                  />
                </div>
              </div>

              <div class="form-group">
                <span class="form-group-title">Адрес</span>
                <div class="grid">
                  <Input
                    inline
                    label="Город"
                    bind:value={form.city}
                    maxlength={128}
                    error={errors.city}
                    placeholder="Например, Москва"
                  />
                  <Input
                    inline
                    label="Адрес"
                    bind:value={form.address}
                    maxlength={255}
                    error={errors.address}
                    placeholder="Улица, дом, офис"
                  />
                </div>
              </div>

              <div class="form-group">
                <div class="form-group-head">
                  <span class="form-group-title">Контакты</span>
                  <HelpTip text="E-mail используется для писем о доступе и сбросе пароля." />
                </div>
                <div class="grid">
                  <Input
                    inline
                    label="Телефон"
                    type="tel"
                    inputmode="tel"
                    bind:value={form.phone}
                    maxlength={32}
                    error={errors.phone}
                    placeholder="+7 999 000-00-00"
                  />
                  <Input
                    inline
                    label="E-mail"
                    type="email"
                    inputmode="email"
                    bind:value={form.email}
                    maxlength={255}
                    error={errors.email}
                    placeholder="client@example.ru"
                  />
                </div>
              </div>
            </div>
          </section>

          <section class="card">
            <div class="card-head">
              <h2>Учётная запись</h2>
              <HelpTip
                text="Логин можно не указывать: он будет создан из e-mail или телефона. Пароль генерируется автоматически и показывается один раз после создания. Передайте клиенту логин и пароль — повторно пароль посмотреть нельзя."
              />
            </div>
            <div class="form-group">
              <div class="grid">
                <Input
                  inline
                  label="Логин"
                  bind:value={form.login}
                  maxlength={64}
                  error={errors.login}
                  placeholder="Если пусто — сгенерируем"
                />
              </div>
            </div>
          </section>
        </div>

        <aside class="side">
          <section class="card">
            <div class="card-head">
              <h2>Менеджер</h2>
              <HelpTip
                text={canAssign
                  ? 'По умолчанию клиент закрепляется за автором создания.'
                  : 'Клиент будет закреплён за вами как автором создания.'}
              />
            </div>
            {#if canAssign}
              {#if optionsLoading}
                <div class="options-loading"><Spinner size={16} /></div>
              {:else}
                <label class="field">
                  <span class="label">Ответственный менеджер</span>
                  <select class="input" bind:value={managerId}>
                    <option value="">{auth.name}</option>
                    {#each managers as manager (manager.id)}
                      <option value={String(manager.id)}>{manager.name}</option>
                    {/each}
                  </select>
                </label>
              {/if}
            {/if}
          </section>

          {#if canAssignPrices}
            <section class="card">
              <div class="card-head">
                <h2>Типы цен</h2>
                <HelpTip text="Если ничего не выбрано, клиент получит базовый тип цен." />
              </div>
              {#if optionsLoading}
                <div class="options-loading"><Spinner size={16} /></div>
              {:else if priceTypes.length === 0}
                <p class="hint">Типы цен не настроены.</p>
              {:else}
                <MultiSelect
                  label="Типы цен"
                  options={priceTypeOptions}
                  bind:values={selectedPriceTypes}
                  allLabel="Все типы цен"
                  emptyValuesLabel="Базовый тип"
                  maxHeight="280px"
                />
              {/if}
            </section>
          {/if}
        </aside>
      </div>

      <div class="form-actions">
        <Button variant="ghost" onclick={() => router.navigate('/clients')}>Отмена</Button>
        <Button type="submit" loading={saving}>Создать клиента</Button>
      </div>
    </form>
  {/if}
</section>

<Modal open={createdOpen} title="Клиент создан" closeButton={false} persistent onclose={openCreatedCard}>
  {#if created}
    <p class="created-text">
      Клиент <strong>{created.name}</strong> создан. Передайте ему данные для входа — пароль
      показывается один раз.
    </p>

    <div class="credentials">
      <div class="credential">
        <span>Логин</span>
        <code>{created.login}</code>
        <button
          type="button"
          class="copy-btn"
          onclick={() => copyValue(created?.login ?? '', 'login')}
        >
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

    <div class="modal-actions">
      <Button variant="ghost" onclick={() => router.navigate('/clients')}>К списку</Button>
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
    color: var(--text);
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
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr);
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

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .card-head h2 {
    margin: 0;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-3);
  }

  .form-groups {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .form-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .form-group-head {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .form-group-title {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
  }

  .form-group .grid {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .form-group :global(.field.inline:last-child) {
    border-bottom: none;
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  .input {
    height: 32px;
    padding: 0 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
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
    flex: 0 0 auto;
    width: 22px;
    height: 22px;
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

  .options-loading {
    display: flex;
    justify-content: center;
    padding: var(--space-2);
  }

  .form-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
  }

  .created-text {
    margin: 0 0 var(--space-4);
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

  @media (max-width: 520px) {
    .credential {
      grid-template-columns: 1fr auto;
    }

    .credential span {
      grid-column: 1 / -1;
    }
  }
</style>
