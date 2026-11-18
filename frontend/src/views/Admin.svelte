<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { Sequence } from '../lib/async-sequence';
  import {
    listModules,
    saveModules,
    type ModuleItem,
    type ModuleRole,
  } from '../lib/api/admin';
  import { listLegalDocuments, saveLegalDocument, type LegalDocument } from '../lib/api/legal';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import HelpTip from '../lib/components/ui/HelpTip.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import MultiSelect from '../lib/components/ui/MultiSelect.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  type AdminSection = 'modules' | 'docs';

  let {
    section = null,
    onsaved,
    moduleCode = null,
    onmoduleschange
  }: {
    section?: AdminSection | null;
    onsaved?: () => void;
    moduleCode?: string | null;
    onmoduleschange?: (modules: ModuleItem[]) => void;
  } = $props();

  const embedded = $derived(section !== null);




  let tab = $state<'modules' | 'docs'>('modules');

  $effect(() => {
    if (section !== null && tab !== section) {
      tab = section;
    }
  });


  let docs = $state<LegalDocument[]>([]);  let docCode = $state('');
  let docTitle = $state('');
  let docBody = $state('');
  let docBusy = $state(false);
  let docNotice = $state('');

  let error = $state('');
  let docsError = $state('');

  const modulesSequence = new Sequence();
  const docsSequence = new Sequence();

  let modules = $state<ModuleItem[]>([]);
  let moduleRoles = $state<ModuleRole[]>([]);
  let modulesBaseline = $state('');
  let modulesSaved = $state(false);

  const moduleOrder = [
    'requests',
    'clients',
    'manager_assign',
    'staff',
    'substitutions',
    'registration',
    'chat',
    'notifications',
    'mail',
    'stock_edit',
    'warehouses',
    'reports'
  ];

  const sortedModules = $derived(
    [...modules].sort((a, b) => {
      const ai = moduleOrder.indexOf(a.code);
      const bi = moduleOrder.indexOf(b.code);

      return (ai === -1 ? moduleOrder.length : ai) - (bi === -1 ? moduleOrder.length : bi);
    })
  );
  const visibleModules = $derived(
    moduleCode === null
      ? sortedModules
      : sortedModules.filter((module) => module.code === moduleCode)
  );
  const moduleRoleOptions = $derived(
    moduleRoles.map((role) => ({ value: String(role.level), label: role.title }))
  );
  const modulesDirty = $derived(modulesBaseline !== JSON.stringify(modules));
  let modulesLoading = $state(false);
  let modulesBusy = $state(false);
  let modulesNotice = $state('');
  let modulesError = $state('');

  const canManageRoles = $derived(auth.can('roles.manage'));

  onMount(() => {
    if (section !== null) {
      void openTab(section);

      return;
    }

    if (canManageRoles) {
      tab = 'modules';
      void loadModules();
    }
  });


  function selectDoc(document: LegalDocument): void {
    docCode = document.code;
    docTitle = document.title;
    docBody = document.body;
    docNotice = '';
  }

  const docPlaceholders = $derived([...new Set(docBody.match(/\[[^\]]+\]/g) ?? [])]);

  async function saveDoc(): Promise<void> {
    docBusy = true;
    docNotice = '';
    error = '';

    try {
      const result = await saveLegalDocument(docCode, docTitle, docBody);
      docs = result.items;
      docNotice = 'Документ сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить документ';
    } finally {
      docBusy = false;
    }
  }

  async function openTab(next: 'modules' | 'docs'): Promise<void> {
    tab = next;

    if (next === 'modules' && modules.length === 0) {
      await loadModules();
    }

    if (next === 'docs' && docs.length === 0) {
      const token = docsSequence.next();

      docsError = '';

      try {
        const result = await listLegalDocuments();

        if (!docsSequence.isCurrent(token)) {
          return;
        }

        docs = result.items;

        if (docs.length > 0) {
          selectDoc(docs[0]);
        }
      } catch {
        if (docsSequence.isCurrent(token)) {
          docsError = 'Не удалось загрузить документы';
        }
      }
    }

  }

  async function loadModules(): Promise<void> {
    const token = modulesSequence.next();

    modulesLoading = true;
    modulesError = '';
    modulesNotice = '';
    modulesSaved = false;

    try {
      const data = await listModules();

      if (!modulesSequence.isCurrent(token)) {
        return;
      }

      modules = data.modules;
      moduleRoles = data.roles;
      modulesBaseline = JSON.stringify(data.modules);
      onmoduleschange?.(data.modules);
    } catch (cause) {
      if (modulesSequence.isCurrent(token)) {
        modulesError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить модули';
      }
    } finally {
      modulesLoading = false;
    }
  }

  function toggleModuleEnabled(code: string): boolean {
    const current = modules.find((module) => module.code === code);

    if (code === 'warehouses' || !current || !canManageRoles) {
      return false;
    }

    const message = current.enabled
      ? `Выключить модуль «${current.title}»? Пользователи потеряют доступ к разделу.`
      : `Включить модуль «${current.title}»? Доступ к разделу появится у выбранных ролей.`;
    const warning =
      code === 'warehouses' && current.enabled
        ? ' Складские остатки и связи с заявками сохранятся.'
        : '';

    if (!confirm(message + warning)) {
      return false;
    }

    const nextModules = modules.map((module) =>
      module.code === code ? { ...module, enabled: !module.enabled } : module
    );
    modules = nextModules;
    modulesNotice = '';
    modulesSaved = false;
    onmoduleschange?.(nextModules);
    return true;
  }

  function setModuleSetting(code: string, key: string, value: string | number | boolean | number[]): void {
    modules = modules.map((module) =>
      module.code === code
        ? { ...module, settings: { ...module.settings, [key]: value } }
        : module
    );
    modulesNotice = '';
    modulesSaved = false;
  }

  function setModuleLevels(code: string, values: string[]): void {
    const levels = values
      .map(Number)
      .filter((level) => Number.isFinite(level));

    modules = modules.map((module) =>
      module.code === code ? { ...module, levels } : module
    );
    modulesNotice = '';
    modulesSaved = false;
  }

  function setModuleGroupLevels(code: string, groupCode: string, values: string[]): void {
    const levels = values
      .map(Number)
      .filter((level) => Number.isFinite(level));

    modules = modules.map((module) => {
      if (module.code !== code) {
        return module;
      }

      const groups = module.groups.map((group) =>
        group.code === groupCode ? { ...group, levels } : group
      );
      const allLevels = [...new Set(groups.flatMap((group) => group.levels))].sort((a, b) => a - b);

      return { ...module, groups, levels: allLevels };
    });
    modulesNotice = '';
    modulesSaved = false;
  }

  function moveModuleGroup(code: string, index: number, direction: -1 | 1): void {
    if (!canManageRoles) {
      return;
    }

    modules = modules.map((module) => {
      if (module.code !== code) {
        return module;
      }

      const target = index + direction;

      if (target < 0 || target >= module.groups.length) {
        return module;
      }

      const groups = [...module.groups];
      [groups[index], groups[target]] = [groups[target], groups[index]];

      return { ...module, groups };
    });
    modulesNotice = '';
    modulesSaved = false;
  }

  function resetModulesDraft(): void {
    if (!modulesBaseline) {
      return;
    }

    const nextModules = JSON.parse(modulesBaseline) as ModuleItem[];
    modules = nextModules;
    modulesNotice = '';
    modulesSaved = false;
    onmoduleschange?.(nextModules);
  }

  async function saveModulesDraft(): Promise<void> {
    modulesBusy = true;
    modulesError = '';
    modulesNotice = '';
    modulesSaved = false;

    try {
      const data = await saveModules(modules);
      modules = data.modules;
      moduleRoles = data.roles;
      modulesBaseline = JSON.stringify(data.modules);
      modulesNotice = 'Сохранено';
      modulesSaved = true;
      onmoduleschange?.(data.modules);
      await appSettings.load();
      onsaved?.();
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить модули';
    } finally {
      modulesBusy = false;
    }
  }

</script>

<svelte:element this={embedded ? 'div' : 'section'} class="page">
  {#if !embedded}
    <div class="page-hero">
      <div class="head">
        <h1>Администрирование</h1>
      </div>
    </div>

    <div class="tabs tab-scroll">
      {#if canManageRoles}
        <button type="button" class:active={tab === 'modules'} onclick={() => void openTab('modules')}>Модули</button>
      {/if}
      {#if auth.can('settings.manage')}
        <button type="button" class:active={tab === 'docs'} onclick={() => void openTab('docs')}>Документы</button>
      {/if}
    </div>
  {/if}

  {#if error}<div class="alert">{error}</div>{/if}
  {#if docsError}<div class="alert">{docsError}</div>{/if}

  {#if tab === 'modules'}
    {#if modulesError}<div class="alert">{modulesError}</div>{/if}
    {#if modulesNotice}<div class="notice">{modulesNotice}</div>{/if}

    {#if modulesLoading}
      <div class="center"><Spinner /></div>
    {:else}
      <div class="module-page" class:embedded>
        {#each visibleModules as module (module.code)}
          {@const moduleSettings = module.settings ?? {}}
          <div
            class="module-card"
            class:off={!module.enabled}
            class:has-groups={module.groups.length > 0}
          >
            <div class="module-head">
              <div class="module-heading">
                <div class="module-title-row">
                  <span class="module-title">{module.title}</span>
                  <span class="module-status" class:on={module.enabled}>
                    {module.enabled ? 'Включён' : 'Отключён'}
                  </span>
                  {#if module.code === 'warehouses'}
                    <span class="module-required-badge">Обязательный</span>
                  {/if}
                </div>
                <p class="module-desc">{module.description}</p>
              </div>

              <div class="module-actions">
                {#if module.capabilities.length > 0}
                  <HelpTip
                    text="Роли определяют, какие действия доступны сотрудникам. Изменения вступают в силу после сохранения."
                  />
                {/if}
                <label
                  class="switch"
                  title={module.code === 'warehouses' ? 'Модуль «Склады» нельзя отключить' : module.enabled ? 'Выключить модуль' : 'Включить модуль'}
                >
                  <input
                    type="checkbox"
                    checked={module.enabled}
                    disabled={!canManageRoles || module.code === 'warehouses'}
                    aria-label={module.code === 'warehouses' ? 'Модуль «Склады» обязателен' : module.enabled ? `Выключить модуль ${module.title}` : `Включить модуль ${module.title}`}
                    onchange={(event) => {
                      const input = event.currentTarget as HTMLInputElement;

                      if (!toggleModuleEnabled(module.code)) {
                        input.checked = !input.checked;
                      }
                    }}
                  />
                  <span class="switch-track" aria-hidden="true"></span>
                </label>
              </div>
            </div>

            {#if !module.enabled}
              <div class="module-off-note">
                Модуль отключён. Настройки доступа сохраняются, но не применяются.
              </div>
            {/if}

            {#if module.code === 'requests' || module.code === 'stock_edit' || module.code === 'mail'}
              <div class="module-settings">
                <div class="module-access-head">
                  <h3>
                    {module.code === 'requests'
                      ? 'Параметры заявок'
                      : module.code === 'stock_edit'
                        ? 'Параметры номенклатуры'
                        : 'Параметры почты'}
                  </h3>
                </div>

                {#if module.code === 'requests'}
                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['sales.enabled'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'sales.enabled',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Оформление заявок и черновиков</span>
                  </label>

                  <div class="module-settings-grid">
                    <label class="module-setting-field">
                      <span>SLA: срок реакции, часов</span>
                      <input
                        type="number"
                        min="1"
                        max="168"
                        value={Number(moduleSettings['sla.reaction_hours'] ?? 2)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'sla.reaction_hours',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                    <label class="module-setting-field">
                      <span>SLA: срок решения, часов</span>
                      <input
                        type="number"
                        min="1"
                        max="720"
                        value={Number(moduleSettings['sla.resolution_hours'] ?? 24)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'sla.resolution_hours',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                  </div>

                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['stock.reserve_enabled'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'stock.reserve_enabled',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Уменьшать остатки складов при оформлении заявки</span>
                  </label>

                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['stock.allow_zero'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'stock.allow_zero',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Разрешать нулевой и отрицательный остаток</span>
                  </label>
                {:else if module.code === 'stock_edit'}
                  <div class="module-settings-grid">
                    <label class="module-setting-field">
                      <span>Формат фото</span>
                      <select
                        value={String(moduleSettings['stocks.photo_ratio'] ?? 'square')}
                        disabled={!canManageRoles || !module.enabled}
                        onchange={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_ratio',
                            event.currentTarget.value
                          )}
                      >
                        <option value="dynamic">По фото</option>
                        <option value="square">Квадрат 1:1</option>
                        <option value="landscape">Горизонтальный 4:3</option>
                        <option value="portrait">Вертикальный 3:4</option>
                      </select>
                    </label>
                    <label class="module-setting-field">
                      <span>Несовпадение пропорций</span>
                      <select
                        value={String(moduleSettings['stocks.photo_fit'] ?? 'contain')}
                        disabled={!canManageRoles || !module.enabled}
                        onchange={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_fit',
                            event.currentTarget.value
                          )}
                      >
                        <option value="contain">Вписывать без обрезки</option>
                        <option value="cover">Заполнять с обрезкой</option>
                      </select>
                    </label>
                  </div>

                  <div class="module-settings-grid">
                    <label class="module-setting-field">
                      <span>Фото: превью, px</span>
                      <input
                        type="number"
                        min="80"
                        max="4000"
                        value={Number(moduleSettings['stocks.photo_size_preview'] ?? 160)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_size_preview',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                    <label class="module-setting-field">
                      <span>Фото: карточка, px</span>
                      <input
                        type="number"
                        min="80"
                        max="4000"
                        value={Number(moduleSettings['stocks.photo_size_card'] ?? 600)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_size_card',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                    <label class="module-setting-field">
                      <span>Фото: максимум, px</span>
                      <input
                        type="number"
                        min="80"
                        max="4000"
                        value={Number(moduleSettings['stocks.photo_size_max'] ?? 1600)}
                        disabled={!canManageRoles || !module.enabled}
                        oninput={(event) =>
                          setModuleSetting(
                            module.code,
                            'stocks.photo_size_max',
                            Number(event.currentTarget.value)
                          )}
                      />
                    </label>
                  </div>
                {:else}
                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['mail.export_enabled'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'mail.export_enabled',
                          event.currentTarget.checked
                        )}
                    />
                    <span>Разрешить экспорт на электронную почту</span>
                  </label>

                  <label class="module-check">
                    <input
                      type="checkbox"
                      checked={Boolean(moduleSettings['mail.spf_checklist'])}
                      disabled={!canManageRoles || !module.enabled}
                      onchange={(event) =>
                        setModuleSetting(
                          module.code,
                          'mail.spf_checklist',
                          event.currentTarget.checked
                        )}
                    />
                    <span>SPF/DKIM/DMARC для домена настроены</span>
                  </label>
                {/if}
              </div>
            {/if}

            {#if module.groups.length > 0}
              <div class="module-access">
                <div class="module-access-head">
                  <h3>
                    {module.groups.length === 1 ? 'Группа доступа' : 'Группы доступа'}
                  </h3>
                </div>

                <div
                  class="module-groups"
                  class:off={!module.enabled}
                >
                  {#if module.code === 'stock_edit'}
                    <div class="module-group">
                      <div class="module-group-head">
                        <div class="module-group-heading">
                          <span class="module-group-title">Доступ к ценам</span>
                          <p class="module-group-desc">
                            Роли, которым показываются цены и доступны операции с ними.
                          </p>
                        </div>
                      </div>
                      <MultiSelect
                        label="Доступ к ценам"
                        options={moduleRoleOptions}
                        values={(Array.isArray(moduleSettings['prices.levels'])
                          ? moduleSettings['prices.levels']
                          : []
                        ).map(String)}
                        onchange={(values) =>
                          setModuleSetting(module.code, 'prices.levels', values.map(Number))}
                        allLabel="Все роли"
                        emptyLabel="Никому"
                        emptyValuesLabel="Никому"
                        maxHeight="240px"
                        disabled={!canManageRoles || !module.enabled}
                      />
                    </div>

                    <div class="module-group">
                      <div class="module-group-head">
                        <div class="module-group-heading">
                          <span class="module-group-title">Доступ к группам</span>
                          <p class="module-group-desc">
                            Роли, которым показываются группы и доступна фильтрация по ним.
                          </p>
                        </div>
                      </div>
                      <MultiSelect
                        label="Доступ к группам"
                        options={moduleRoleOptions}
                        values={(Array.isArray(moduleSettings['groups.levels'])
                          ? moduleSettings['groups.levels']
                          : []
                        ).map(String)}
                        onchange={(values) =>
                          setModuleSetting(module.code, 'groups.levels', values.map(Number))}
                        allLabel="Все роли"
                        emptyLabel="Никому"
                        emptyValuesLabel="Никому"
                        maxHeight="240px"
                        disabled={!canManageRoles || !module.enabled}
                      />
                    </div>
                  {/if}

                  {#each module.groups as group, index (group.code)}
                    <div class="module-group">
                      <div class="module-group-head">
                        <div class="module-group-heading">
                          <span class="module-group-title">{group.title}</span>
                          {#if group.description}
                            <p class="module-group-desc">{group.description}</p>
                          {/if}
                        </div>
                        <div class="module-group-order">
                          <button
                            type="button"
                            class="module-order-btn"
                            title="Выше"
                            aria-label="Выше"
                            disabled={!canManageRoles || index === 0}
                            onclick={() => moveModuleGroup(module.code, index, -1)}
                          >
                            <Icon name="arrow-up" size={14} />
                          </button>
                          <button
                            type="button"
                            class="module-order-btn"
                            title="Ниже"
                            aria-label="Ниже"
                            disabled={!canManageRoles || index === module.groups.length - 1}
                            onclick={() => moveModuleGroup(module.code, index, 1)}
                          >
                            <Icon name="arrow-down" size={14} />
                          </button>
                        </div>
                      </div>

                      <MultiSelect
                        label={group.title}
                        options={moduleRoleOptions}
                        values={group.levels.map(String)}
                        onchange={(values) => setModuleGroupLevels(module.code, group.code, values)}
                        allLabel="Все роли"
                        emptyLabel="Нет ролей"
                        emptyValuesLabel="Нет ролей"
                        maxHeight="240px"
                        disabled={!canManageRoles || !module.enabled}
                      />
                    </div>
                  {/each}
                </div>
              </div>
            {:else if module.capabilities.length > 0}
              <div class="module-access">
                <div class="module-access-head">
                  <h3>Доступ по ролям</h3>
                </div>

                <div
                  class="module-single-access"
                  class:off={!module.enabled}
                >
                  <div class="module-group module-group-single">
                    <div class="module-group-head">
                      <div class="module-group-heading">
                        <span class="module-group-title">Роли с доступом</span>
                        <p class="module-group-desc">
                          Отметьте роли, которым доступен модуль.
                        </p>
                      </div>
                    </div>

                    <MultiSelect
                      label="Роли с доступом"
                      options={moduleRoleOptions}
                      values={module.levels.map(String)}
                      onchange={(values) => setModuleLevels(module.code, values)}
                      allLabel="Все роли"
                      emptyLabel="Нет ролей"
                      emptyValuesLabel="Нет ролей"
                      maxHeight="240px"
                      disabled={!canManageRoles || !module.enabled}
                    />
                  </div>
                </div>
              </div>
            {/if}
          </div>
        {/each}

        {#if visibleModules.length === 0}
          <p class="hint">Модуль не найден</p>
        {/if}
      </div>

      {#if !canManageRoles}
        <p class="hint">Изменять доступ к модулям могут только администраторы.</p>
      {/if}

      <div
        class="module-footer"
      >
        <span class="module-save-state" class:dirty={modulesDirty}>
          {#if modulesDirty}
            Есть несохранённые изменения
          {:else if modulesSaved}
            Изменения сохранены
          {/if}
        </span>
        <div class="module-footer-actions">
          {#if modulesDirty}
            <Button variant="ghost" disabled={modulesBusy} onclick={resetModulesDraft}>
              Отменить
            </Button>
          {/if}
          <Button loading={modulesBusy} disabled={!modulesDirty} onclick={() => void saveModulesDraft()}>
            Сохранить
          </Button>
        </div>
      </div>
    {/if}
  {/if}


  {#if tab === 'docs'}
    <div class="docs">
      <div class="doc-tabs tab-scroll">
        {#each docs as document (document.code)}
          <button
            type="button"
            class:active={document.code === docCode}
            onclick={() => selectDoc(document)}
          >
            {document.title}
          </button>
        {/each}
      </div>

      {#if docCode}
        <div class="card form">
          <label><span>Заголовок</span><input bind:value={docTitle} /></label>
          <label>
            <span>Текст документа (редактируется администратором)</span>
            <textarea bind:value={docBody} rows="18"></textarea>
          </label>
          {#if docPlaceholders.length > 0}
            <div class="alert">
              В документе остались незаполненные реквизиты:
              {docPlaceholders.join(', ')}. Замените их данными оператора — для 152-ФЗ политика
              должна содержать наименование, адрес и контакты.
            </div>
          {/if}
          {#if docNotice}<div class="notice">{docNotice}</div>{/if}
          <div class="actions">
            <Button loading={docBusy} onclick={() => void saveDoc()}>Сохранить</Button>
            <Button variant="ghost" onclick={() => router.navigate(`/legal/${docCode}`)}>Открыть страницу</Button>
          </div>
        </div>
      {/if}
    </div>
  {/if}

</svelte:element>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .module-page {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .module-page.embedded {
    grid-template-columns: minmax(0, 1fr);
  }

  @media (max-width: 720px) {
    .module-page {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .module-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    padding: var(--space-5);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    transition: opacity 0.15s ease, background 0.15s ease;
  }

  .module-card.off {
    background: var(--fill-tertiary);
    border-color: var(--border-secondary);
  }

  .module-card.has-groups {
    grid-column: 1 / -1;
  }

  .module-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    padding-bottom: var(--space-3);
    border-bottom: 1px solid var(--border-secondary);
  }

  .module-heading {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }

  .module-title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }

  .module-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    transition: color 0.15s ease;
  }

  .module-card.off .module-title {
    color: var(--text-description);
  }

  .module-status {
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--fill-tertiary);
    color: var(--text-description);
    font-size: 11px;
    font-weight: 500;
  }

  .module-status.on {
    background: var(--success-bg);
    color: var(--success-text);
  }
  .module-required-badge {
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--primary-bg);
    color: var(--primary);
    font-size: 11px;
    font-weight: 500;
  }

  .module-off-note {
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    background: var(--warning-bg);
    color: var(--warning-text);
    font-size: 12px;
    line-height: 1.4;
  }

  .module-actions {
    display: flex;
    align-items: center;
    gap: var(--space-1);
    flex: 0 0 auto;
  }

  .switch {
    position: relative;
    flex: 0 0 40px;
    width: 40px;
    height: 22px;
  }

  .switch input {
    position: absolute;
    inset: 0;
    z-index: 1;
    margin: 0;
    opacity: 0;
    cursor: pointer;
  }

  .switch-track {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: var(--border);
    transition: background 0.15s ease;
  }

  .switch-track::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    transition: transform 0.15s ease;
  }

  .switch input:checked + .switch-track {
    background: var(--primary);
  }

  .switch input:checked + .switch-track::after {
    transform: translateX(18px);
  }

  .switch input:focus-visible + .switch-track {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  .switch input:disabled {
    cursor: not-allowed;
  }

  .switch input:disabled + .switch-track {
    opacity: 0.55;
  }

  .module-desc {
    margin: 0;
    font-size: 13px;
    line-height: 1.45;
    color: var(--text-description);
  }

  .module-settings {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .module-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
  }

  .module-check {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text);
    font-size: 13px;
    cursor: pointer;
  }

  .module-check input {
    width: 16px;
    height: 16px;
    accent-color: var(--primary);
  }

  .module-setting-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: var(--text-description);
    font-size: 12px;
  }

  .module-setting-field input,
  .module-setting-field select {
    width: 100%;
    height: 34px;
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 13px;
    box-sizing: border-box;
  }

  .module-setting-field input:focus,
  .module-setting-field select:focus {
    box-shadow: 0 0 0 2px var(--focus-ring);
    border-color: var(--primary);
    outline: none;
  }

  .module-setting-field input:disabled,
  .module-setting-field select:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  .module-access {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .module-access-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .module-access-head h3 {
    margin: 0;
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
  }

  .module-groups.off {
    opacity: 0.55;
  }

  .module-single-access {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-3);
    align-items: stretch;
  }

  .module-single-access.off {
    opacity: 0.55;
  }


  .module-groups {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--space-3);
  }

  .module-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 0;
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .module-group-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .module-group-heading {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
  }

  .module-group-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    transition: color 0.15s ease;
  }

  .module-card.off .module-group-title {
    color: var(--text-description);
  }

  .module-group-desc {
    margin: 0;
    max-width: 80ch;
    color: var(--text-description);
    font-size: 13px;
    line-height: 1.5;
  }

  .module-group-order {
    display: flex;
    flex: 0 0 auto;
    gap: 2px;
  }

  .module-order-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    cursor: pointer;
  }

  .module-order-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .module-order-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .module-footer {
    position: sticky;
    bottom: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-top: 1px solid var(--border-secondary);
    background: var(--surface);
  }

  .module-save-state {
    color: var(--muted);
    font-size: 12px;
  }

  .module-save-state.dirty {
    color: var(--warning-text);
  }

  .module-footer-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }



  .tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .tabs button {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }




  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }


  label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  label input,
  label select,
  label textarea {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: inherit;
    background: var(--surface);
    color: var(--text);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  label input:focus,
  label select:focus,
  label textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  label textarea {
    resize: vertical;
    line-height: 1.5;
  }


  .docs {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .doc-tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .doc-tabs button {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .doc-tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }


  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--success-bg);
    color: var(--success-text);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  /* Телефон: панель сохранения держим над нижней навигацией. */
  @media (max-width: 720px) {
    .module-footer {
      bottom: calc(60px + env(safe-area-inset-bottom));
    }
  }

</style>
