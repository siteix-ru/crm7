/**
 * Лёгкие e2e-проверки критичных сценариев CRM7 через playwright-core и системный Edge.
 *
 * Запуск:
 *   CRM_PASSWORD_FILE=%TEMP%\opencode\seed_passwords.json npm run e2e
 *
 * Переменные окружения:
 *   CRM_BASE_URL         фронтенд (по умолчанию http://localhost:5174)
 *   CRM_API_URL          API v2 (по умолчанию http://127.0.0.1:8080/api/v2)
 *   CRM_PASSWORD_FILE    JSON {"login": "password"}
 *   CRM_PASSWORD         общий пароль
 *   CRM_BROWSER_CHANNEL  канал Playwright (по умолчанию msedge; «bundled» — встроенный chromium)
 *   CRM_HEADED=1         показать окно браузера
 *   CRM_E2E_MOBILE=1     только мобильные сценарии (viewport 390×844, touch, без msedge)
 *   CRM_E2E_ONLY         список сценариев через запятую (фильтр по подстроке имени)
 */

import { chromium } from 'playwright-core';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.CRM_BASE_URL ?? 'http://localhost:5174').replace(/\/$/, '');
const API = (process.env.CRM_API_URL ?? 'http://127.0.0.1:8080/api/v2').replace(/\/$/, '');
const CHANNEL = process.env.CRM_BROWSER_CHANNEL ?? 'msedge';
const HEADED = process.env.CRM_HEADED === '1';
const MOBILE = process.env.CRM_E2E_MOBILE === '1';
const launchOptions = {
  ...(CHANNEL && CHANNEL !== 'bundled' ? { channel: CHANNEL } : {}),
  headless: !HEADED,
  args: ['--no-proxy-server', '--proxy-bypass-list=*']
};
const ONLY = (process.env.CRM_E2E_ONLY ?? '')
  .split(',')
  .map((item) => item.trim())
  .filter(Boolean);
const OUT = path.resolve('artifacts/e2e');

const log = (message) => process.stdout.write(`${message}\n`);

async function loadPasswords() {
  if (!process.env.CRM_PASSWORD_FILE) {
    return {};
  }

  const raw = await readFile(process.env.CRM_PASSWORD_FILE, 'utf8');

  return JSON.parse(raw.replace(/^\uFEFF/, ''));
}

function passwordFor(login, passwords) {
  return passwords[login] ?? process.env.CRM_PASSWORD ?? '';
}

async function apiLogin(login, password) {
  const response = await fetch(`${API}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login, password })
  });

  if (!response.ok) {
    throw new Error(`не удалось войти как ${login}: HTTP ${response.status}`);
  }

  return (await response.json()).data;
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function expectVisible(locator, message, timeout = 15000) {
  try {
    await locator.first().waitFor({ state: 'visible', timeout });
  } catch {
    throw new Error(message);
  }
}

function slug(name) {
  return name
    .toLowerCase()
    .replace(/[^a-zа-я0-9]+/gi, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 60);
}

const scenarios = [
  {
    name: 'вход через форму и выход',
    role: 'manager',
    bare: true,
    async run(page, ctx) {
      await page.goto(`${BASE}/#/login`, { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="login"]').fill(ctx.login);
      await page.locator('input[name="password"]').fill(ctx.password);
      await page.getByRole('button', { name: 'Войти' }).click();

      await expectVisible(page.locator('nav.desktop-nav'), 'после входа не появилась навигация');

      await page.goto(`${BASE}/#/profile`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('tab', { name: 'Сессии' }).click();
      await page.getByRole('button', { name: 'Выйти из текущей сессии' }).click();

      await expectVisible(
        page.locator('input[name="login"]'),
        'после выхода не вернулись на экран входа'
      );
    }
  },
  {
    name: 'профиль: поля сотрудника и компактная смена пароля',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/profile`, { waitUntil: 'domcontentloaded' });

      for (const label of ['Отдел', 'Город', 'Офис']) {
        await expectVisible(page.getByLabel(label), `в профиле сотрудника нет поля «${label}»`);
      }

      await page.getByRole('tab', { name: 'Безопасность' }).click();
      const currentPassword = page.getByLabel('Текущий пароль');
      await expectVisible(currentPassword, 'не открылась форма смены пароля');

      const box = await currentPassword.boundingBox();
      assert(box !== null && box.width <= 680, 'поля смены пароля растянуты слишком широко');
    }
  },
  {
    name: 'профиль: поля клиента',
    role: 'client',
    async run(page) {
      await page.goto(`${BASE}/#/profile`, { waitUntil: 'domcontentloaded' });

      for (const label of ['Компания', 'ИНН', 'Город', 'Адрес']) {
        await expectVisible(page.getByLabel(label), `в профиле клиента нет поля «${label}»`);
      }
    }
  },
  {
    name: 'новая заявка: выбор клиента из поиска',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/requests/new`, { waitUntil: 'domcontentloaded' });

      const input = page.locator('.client-search input');
      await expectVisible(input, 'нет поля поиска клиента');
      await input.click();

      const items = page.locator('.cl-item');
      await expectVisible(items.first(), 'не появился список клиентов');
      const count = await items.count();
      assert(count > 0 && count <= 15, `в списке должно быть 10-15 клиентов, а не ${count}`);

      const submit = page.getByRole('button', { name: 'Создать заявку' });
      assert(await submit.isDisabled(), 'без выбранного клиента создание заявки должно быть заблокировано');

      await input.fill('7700000003');
      const innItem = page.locator('.cl-item', { hasText: '7700000003' });
      await expectVisible(innItem.first(), 'поиск по ИНН не вернул клиента');
      await innItem.first().click();

      await expectVisible(page.locator('.client-selected'), 'после выбора нет подтверждения клиента');
      await expectVisible(page.getByText(/ИНН 7700000003/), 'в выбранном клиенте нет ИНН');

      const priceType = page.getByLabel('Тип цены');

      if ((await priceType.count()) > 0) {
        const current = await priceType.inputValue();

        if (!current || current === '0') {
          await priceType.selectOption({ index: 1 });
        }
      }

      assert(!(await submit.isDisabled()), 'после выбора клиента создание заявки должно быть доступно');

      await page.locator('.cs-clear').click();
      await expectVisible(input, 'после сброса не вернулось поле поиска');
      await input.fill('Иванов');
      assert(await submit.isDisabled(), 'введённый вручную текст без выбора не должен проходить проверку');
    }
  },
  {
    name: 'новая заявка: черновик сохраняется и переживает перезагрузку',
    role: 'manager',
    async run(page, { session }) {
      await page.goto(`${BASE}/#/requests/new`, { waitUntil: 'domcontentloaded' });

      const subject = page.getByLabel('Тема заявки');
      const value = `Черновик e2e ${Date.now()}`;

      await expectVisible(subject, 'нет поля «Тема заявки»');
      await subject.fill(value);
      await expectVisible(page.getByText(/Черновик сохранён/), 'черновик не сохранился автоматически');

      const match = page.url().match(/draft=(\d+)/);

      assert(match, 'после сохранения черновика в адресе нет draft=');

      const draftId = Number(match[1]);
      const updated = `${value} — дополнено`;

      await subject.fill(updated);
      await page.waitForTimeout(2500);

      const afterUpdate = page.url().match(/draft=(\d+)/);

      assert(
        afterUpdate !== null && Number(afterUpdate[1]) === draftId,
        'при изменении черновика создаётся новый вместо обновления текущего'
      );

      try {
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expectVisible(subject, 'после перезагрузки нет поля «Тема заявки»');
        await page.waitForTimeout(1000);
        assert((await subject.inputValue()) === updated, 'после перезагрузки тема черновика не восстановилась');
      } finally {
        if (session?.token) {
          await fetch(`${API}/request-drafts/${draftId}`, {
            method: 'DELETE',
            headers: { Authorization: `Bearer ${session.token}` }
          }).catch(() => {});
        }
      }
    }
  },
  {
    name: 'заявки: карточка и вкладки',
    role: 'manager',    async run(page) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      const card = page
        .locator('a.card[href^="#/requests/"]')
        .filter({ has: page.locator('.col-count', { hasText: /[1-9]/ }) })
        .first();
      await expectVisible(card, 'нет заявки с позициями в списке');
      await card.click();

      await expectVisible(page.getByRole('button', { name: /^Заявка/ }), 'нет вкладки «Заявка»');
      await expectVisible(page.getByRole('button', { name: /^Активность/ }), 'нет вкладки «Активность»');
      await expectVisible(page.locator('.main .items-card'), 'состав заявки не в основной колонке');
      await expectVisible(
        page.locator('.side .side-description-card'),
        'описание не в правой колонке'
      );
      assert(
        (await page.getByRole('heading', { name: 'Итого' }).count()) === 0,
        'блок «Итого» не удалён'
      );

      await page.getByRole('button', { name: /^Активность/ }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Новое событие' }),
        'нет формы добавления события'
      );

      const subject = page.getByLabel('Тема');
      await expectVisible(subject, 'нет поля темы');
      const saveEvent = page.getByRole('button', { name: 'Сохранить' });
      assert(await saveEvent.isDisabled(), 'кнопка «Сохранить» активна без темы');
      await subject.fill('E2E событие');
      assert(!(await saveEvent.isDisabled()), 'кнопка «Сохранить» не активировалась после темы');

      const clientVisible = page.getByRole('checkbox', { name: 'Доступно клиенту' });

      if ((await clientVisible.count()) > 0) {
        assert(!(await clientVisible.isChecked()), 'чекбокс «Доступно клиенту» включён по умолчанию');
      }
      await expectVisible(
        page.getByRole('button', { name: 'Добавить комментарий' }),
        'нет кнопки добавления комментария'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Добавить файл' }),
        'нет кнопки добавления файла'
      );
      assert(
        (await page.getByPlaceholder('Комментарий к событию (необязательно)').count()) === 0,
        'поле комментария не скрыто по умолчанию'
      );
      assert(
        (await page.getByText('Выбрать файл (до 10 МБ)').count()) === 0,
        'поле файла не скрыто по умолчанию'
      );

      await page.getByRole('button', { name: 'Добавить комментарий' }).click();
      await expectVisible(
        page.getByPlaceholder('Комментарий к событию (необязательно)'),
        'поле комментария не раскрылось'
      );
      await page.getByRole('button', { name: 'Добавить файл' }).click();
      await expectVisible(page.getByText('Выбрать файл (до 10 МБ)'), 'поле файла не раскрылось');

      const helperToggle = page.getByRole('button', { name: 'Подсказка по событиям' });
      await expectVisible(helperToggle, 'нет иконки подсказки');
      assert(
        (await page.getByText('Как добавить событие').count()) === 0,
        'текст подсказки виден без клика'
      );
      await helperToggle.click();
      await expectVisible(page.getByText('Как добавить событие'), 'подсказка не раскрылась');
      await page.getByRole('heading', { name: 'Новое событие' }).click();
      await page.waitForTimeout(150);
      assert(
        (await page.getByText('Как добавить событие').count()) === 0,
        'подсказка не закрылась по клику вне'
      );

      await page.locator('.events-head-actions .link-btn').click();
      const historyDialog = page.getByRole('dialog');
      await expectVisible(historyDialog, 'не открылась модалка истории');
      await expectVisible(
        historyDialog.getByRole('button', { name: /^Активность/ }),
        'нет вкладки «Активность» в истории'
      );
      await historyDialog.getByRole('button', { name: 'Хронология' }).click();
      await expectVisible(
        historyDialog.getByRole('button', { name: 'Хронология' }),
        'нет вкладки «Хронология» в истории'
      );
      await page.keyboard.press('Escape');

      await page.getByRole('button', { name: /^Файлы/ }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Файлы заявки' }),
        'нет вкладки «Файлы заявки»'
      );
      await expectVisible(
        page.getByRole('heading', { name: 'Добавить файл' }),
        'нет блока добавления файла'
      );
      assert(
        (await page.getByRole('heading', { name: 'Параметры' }).count()) === 0,
        'на вкладке файлов видны «Параметры»'
      );
      assert(
        (await page.getByRole('heading', { name: 'Итого' }).count()) === 0,
        'на вкладке файлов виден блок «Итого»'
      );
      await page.getByRole('button', { name: 'Добавить комментарий' }).click();
      await expectVisible(
        page.getByPlaceholder('Комментарий к файлу'),
        'комментарий к файлу не раскрылся'
      );

      await page.locator('.hero-tab[aria-label="Чат по заявке"]').click();
      await expectVisible(
        page.getByRole('heading', { name: 'Чат по заявке' }),
        'нет вкладки «Чат по заявке»'
      );
      assert(
        (await page.getByRole('heading', { name: 'Параметры' }).count()) === 0,
        'на вкладке чата видны «Параметры»'
      );
      assert(
        (await page.getByRole('heading', { name: 'Итого' }).count()) === 0,
        'на вкладке чата виден блок «Итого»'
      );

      await page.getByRole('button', { name: /^Заявка/ }).click();
      await expectVisible(page.locator('.it-row:not(.it-head)').first(), 'в составе нет позиций');
    }
  },
  {
    name: 'клиенты: создание из списка',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/clients`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('button', { name: 'Создать клиента' }), 'нет кнопки создания клиента');

      await page.getByRole('button', { name: 'Создать клиента' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Новый клиент' }), 'не открылась страница создания клиента');

      const stamp = Date.now();
      const name = `E2E Клиент ${stamp}`;
      await page.getByLabel(/Имя клиента/).fill(name);
      await page.getByLabel('Должность').fill('Директор E2E');
      await page.getByLabel('Компания').fill('E2E Test LLC');
      await page.getByLabel('Город').fill('E2E Город');
      await page.getByLabel('Адрес').fill('E2E улица, 1');
      await page.getByLabel('Телефон').fill(`+7999${String(stamp).slice(-6)}`);
      await page.getByLabel('E-mail').fill(`e2e-client-${stamp}@example.test`);

      const priceCard = page.locator('section.card', { hasText: 'Типы цен' });

      if ((await priceCard.count()) > 0) {
        const priceTrigger = priceCard.getByRole('button', { name: 'Типы цен' });

        if ((await priceTrigger.count()) > 0) {
          await priceTrigger.click();

          const firstOption = page
            .getByRole('listbox', { name: 'Типы цен' })
            .getByRole('checkbox')
            .first();

          if ((await firstOption.count()) > 0) {
            await firstOption.check();
          }
        }
      }

      await page.getByRole('button', { name: 'Создать клиента' }).last().click();
      await expectVisible(page.getByRole('heading', { name: 'Клиент создан' }), 'не появилось окно с учётными данными');

      const credentials = page.locator('.credential code');
      const login = (await credentials.nth(0).textContent()).trim();
      const password = (await credentials.nth(1).textContent()).trim();
      assert(login.length >= 3, `некорректный логин: ${login}`);
      assert(password.length >= 10, `некорректный пароль: ${password}`);

      await page.getByRole('button', { name: 'Открыть карточку' }).click();
      await expectVisible(page.locator('h1', { hasText: name }), 'не открылась карточка созданного клиента');
      assert(
        (await page.getByRole('button', { name: 'Передать клиента' }).count()) === 0,
        'в карточке клиента осталась передача клиента'
      );
      await page.getByRole('tab', { name: 'Профиль' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Личные данные' }),
        'в карточке клиента нет блока личных данных'
      );
      await expectVisible(page.getByText('E2E Город'), 'в карточке клиента нет города');
      await expectVisible(page.getByText('E2E улица, 1'), 'в карточке клиента нет адреса');

      await page.getByRole('button', { name: 'Завершить все сессии' }).click();
      const sessionsDialog = page.getByRole('dialog');
      await expectVisible(sessionsDialog, 'не открылась модалка завершения сессий');
      await sessionsDialog.getByRole('button', { name: 'Завершить сессии' }).click();
      await expectVisible(page.getByText('Все сессии клиента завершены'), 'сессии клиента не завершены');
    }
  },
  {
    name: 'клиенты: фильтры по статусу, менеджеру и городу',
    role: 'admin',
    async run(page, ctx) {
      const headers = { Authorization: `Bearer ${ctx.session.token}` };
      const [listResponse, managersResponse] = await Promise.all([
        fetch(`${API}/clients?per_page=100`, { headers }),
        fetch(`${API}/managers`, { headers })
      ]);
      assert(listResponse.ok, `не удалось загрузить клиентов: HTTP ${listResponse.status}`);
      assert(managersResponse.ok, `не удалось загрузить менеджеров: HTTP ${managersResponse.status}`);

      const managerIds = new Set(
        (await managersResponse.json()).data.items.map((manager) => manager.id)
      );
      const target = (await listResponse.json()).data.items.find(
        (client) =>
          client.manager !== null &&
          managerIds.has(client.manager.id) &&
          client.city !== '' &&
          client.reg_state === 'active' &&
          client.active
      );

      assert(target, 'нет активного клиента с менеджером и городом для проверки фильтров');

      await fetch(`${API}/preferences/filters/clients`, { method: 'DELETE', headers });
      await page.goto(`${BASE}/#/clients`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Вид и фильтры' }).click();

      const dialog = page.getByRole('dialog');
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByRole('checkbox', { name: 'Активные', exact: true }).click();
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByRole('button', { name: 'Менеджеры' }).click();
      await dialog.getByRole('checkbox', { name: target.manager.name, exact: true }).first().click();
      await dialog.getByRole('button', { name: 'Менеджеры' }).click();
      await dialog.getByRole('button', { name: 'Города' }).click();
      const cityBox = dialog.getByRole('checkbox', { name: target.city, exact: true }).first();
      assert((await cityBox.count()) === 1, `город «${target.city}» не найден в списке`);
      await cityBox.click();
      assert(await cityBox.isChecked(), `город «${target.city}» не отметился`);
      await dialog.getByRole('button', { name: 'Города' }).click();

      const requestPromise = page.waitForRequest(
        (request) =>
          request.url().includes('/api/v2/clients?') && request.url().includes('managers=')
      );

      await dialog.getByRole('button', { name: 'Применить' }).click();
      const requestUrl = new URL((await requestPromise).url());

      assert(
        requestUrl.searchParams.get('state') === 'active',
        'в запрос не попал фильтр по статусу'
      );
      assert(
        requestUrl.searchParams.get('managers') === String(target.manager.id),
        'в запрос не попал фильтр по менеджеру'
      );
      assert(requestUrl.searchParams.get('cities') === target.city, 'в запрос не попал фильтр по городу');

      await expectVisible(
        page.locator('.client-table .ct-row:not(.ct-head), .tiles-grid .tile').first(),
        'после применения фильтров список клиентов пуст'
      );
    }
  },
  {
    name: 'сотрудники: создание и карточка',
    role: 'admin',
    async run(page, ctx) {
      await page.goto(`${BASE}/#/staff`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Сотрудники' }), 'не открылся список сотрудников');
      await expectVisible(page.getByRole('button', { name: 'Добавить сотрудника' }), 'нет кнопки добавления сотрудника');

      await page.getByRole('button', { name: 'Добавить сотрудника' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Новый сотрудник' }), 'не открылась форма создания');

      const stamp = Date.now();
      const name = `E2E Сотрудник ${stamp}`;
      const email = `staff-${stamp}@example.test`;
      const city = `E2E город ${stamp}`;
      await page.getByLabel(/ФИО/).fill(name);
      await page.getByLabel('E-mail *').fill(email);
      await page.getByLabel('Телефон').fill(`+7993${String(stamp).slice(-6)}`);
      await page.getByLabel('Должность').fill('Менеджер E2E');
      await page.getByLabel('Отдел').fill('E2E отдел');
      await page.getByLabel('Город').fill(city);
      await page.getByLabel('Офис').fill('E2E офис');

      await page.getByRole('button', { name: 'Создать сотрудника' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Сотрудник создан' }), 'не появились учётные данные');

      const credentials = page.locator('.credential code');
      const generatedLogin = (await credentials.nth(0).textContent()).trim();
      assert(generatedLogin.length >= 3, 'некорректный логин сотрудника');
      assert(generatedLogin !== email, 'логин должен быть отделён от e-mail');
      assert((await credentials.nth(1).textContent()).trim().length >= 10, 'некорректный пароль сотрудника');
      await expectVisible(
        page.getByRole('button', { name: 'Отправить на почту' }),
        'нет кнопки отправки данных на почту'
      );

      await page.getByRole('button', { name: 'Открыть карточку' }).click();
      await expectVisible(page.locator('h1', { hasText: name }), 'не открылась карточка сотрудника');
      const staffId = Number(page.url().split('/').pop());
      const clientsResponse = await fetch(`${API}/users/${staffId}/clients`, {
        headers: { Authorization: `Bearer ${ctx.session.token}` }
      });
      assert(
        clientsResponse.ok,
        `не удалось получить клиентов сотрудника: HTTP ${clientsResponse.status}`
      );
      assert(
        (await clientsResponse.json()).data.items.length === 0,
        'у нового сотрудника не должно быть клиентов'
      );
      await expectVisible(
        page.getByRole('heading', { name: 'Клиенты менеджера' }),
        'в карточке нет блока клиентов менеджера'
      );
      await expectVisible(
        page.getByText('Закреплённых клиентов нет'),
        'для нового сотрудника не показано пустое состояние клиентов'
      );
      await expectVisible(
        page.getByRole('heading', { name: 'История профиля' }),
        'в профиле сотрудника нет истории учётной записи'
      );
      await expectVisible(page.getByText('Сотрудник создан'), 'в истории нет события создания');
      await expectVisible(page.getByText('E2E отдел'), 'отдел не сохранился');
      await expectVisible(page.getByText(city), 'город не сохранился');
      await expectVisible(page.getByText('E2E офис'), 'офис не сохранился');
      assert((await page.getByText('Типы цен').count()) === 0, 'у сотрудника не должно быть типов цен');
      assert((await page.getByRole('button', { name: 'Редактировать' }).count()) === 0, 'в шапке не должно быть кнопки редактирования');

      await page.getByRole('button', { name: 'Изменить Должность' }).click();
      const positionDialog = page.getByRole('dialog');
      await expectVisible(positionDialog.getByLabel('Должность'), 'не открылась модалка должности');
      await positionDialog.getByLabel('Должность').fill('Старший менеджер E2E');
      await positionDialog.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(page.getByText('Данные сохранены'), 'изменения не сохранились');
      await expectVisible(page.getByText('Старший менеджер E2E'), 'новая должность не отображается');

      await page.getByRole('button', { name: 'Изменить E-mail' }).click();
      const emailDialog = page.getByRole('dialog');
      const newEmail = `updated-${stamp}@example.test`;
      await emailDialog.getByLabel('E-mail').fill(newEmail);
      await emailDialog.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(page.getByText(newEmail), 'новый e-mail не отображается');

      await page.getByRole('button', { name: 'Изменить логин' }).click();
      const loginDialog = page.getByRole('dialog');
      const newLogin = `e2e-staff-${stamp}`;
      await loginDialog.getByLabel('Логин').fill(newLogin);
      await loginDialog.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(page.getByText('Логин изменён'), 'логин не изменён');
      await expectVisible(page.getByText(newLogin), 'новый логин не отображается');

      await page.getByRole('button', { name: 'Сбросить пароль' }).click();
      await page.getByRole('dialog').getByRole('button', { name: 'Сбросить пароль' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Новый пароль' }), 'не показан новый пароль');
      assert((await page.locator('.password-box code').textContent()).trim().length >= 10, 'некорректный новый пароль');
      await page.getByRole('button', { name: 'Готово' }).click();

      await page.getByRole('button', { name: 'Завершить все сессии' }).click();
      await page.getByRole('dialog').getByRole('button', { name: 'Завершить сессии' }).click();
      await expectVisible(page.getByText('Все сессии сотрудника завершены'), 'сессии сотрудника не завершены');

      await page.getByRole('button', { name: 'Заблокировать' }).click();
      await page.getByRole('dialog').getByRole('button', { name: 'Заблокировать' }).click();
      await expectVisible(page.locator('.state', { hasText: 'Заблокирован' }), 'сотрудник не заблокирован');

      await page.getByRole('button', { name: 'Разблокировать' }).click();
      await page.getByRole('dialog').getByRole('button', { name: 'Разблокировать' }).click();
      await expectVisible(page.locator('.state', { hasText: 'Активен' }), 'сотрудник не разблокирован');

      await page.getByRole('button', { name: /Вся история/ }).click();
      const historyDialog = page.getByRole('dialog');
      await expectVisible(historyDialog.getByText('История профиля'), 'не открылась история профиля');
      await expectVisible(historyDialog.getByText('Разблокирован'), 'история сотрудника не загрузилась');
      await historyDialog.getByRole('button', { name: 'Закрыть' }).last().click();

      const clientName = `E2E Клиент сотрудника ${stamp}`;
      const createdClient = await fetch(`${API}/clients`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${ctx.session.token}` },
        body: JSON.stringify({
          name: clientName,
          company: 'E2E Staff Client LLC',
          email: `staff-client-${stamp}@example.test`,
          phone: `+7991${String(stamp).slice(-6)}`,
          manager_id: staffId
        })
      });
      assert(createdClient.ok, `не удалось создать клиента сотрудника: HTTP ${createdClient.status}`);

      await page.reload({ waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByText(clientName), 'клиент сотрудника не появился в карточке');
      await page.getByRole('button', { name: /Все клиенты/ }).click();
      const clientsDialog = page.getByRole('dialog');
      await expectVisible(clientsDialog.getByText(clientName), 'модалка не показала клиента сотрудника');
      await clientsDialog.getByRole('button', { name: 'Закрыть' }).last().click();

      await page.goto(`${BASE}/#/staff`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Вид и фильтры' }).click();
      const filtersDialog = page.getByRole('dialog');
      await filtersDialog.getByRole('button', { name: 'Роль' }).click();
      await filtersDialog.getByLabel('Менеджер', { exact: true }).check();
      await filtersDialog.getByLabel('Администратор', { exact: true }).check();
      await filtersDialog.getByRole('button', { name: 'Роль' }).click();
      await filtersDialog.getByRole('button', { name: 'Статусы' }).click();
      await filtersDialog.getByLabel('Активные', { exact: true }).check();
      await filtersDialog.getByRole('button', { name: 'Статусы' }).click();
      await filtersDialog.getByRole('button', { name: 'Город' }).click();
      await filtersDialog.getByLabel(city, { exact: true }).check();
      await filtersDialog.getByRole('button', { name: 'Город' }).click();
      await filtersDialog.getByRole('button', { name: 'Отдел' }).click();
      await filtersDialog.getByLabel('E2E отдел', { exact: true }).check();
      await filtersDialog.getByRole('button', { name: 'Отдел' }).click();
      await filtersDialog.getByRole('button', { name: 'Офис' }).click();
      await filtersDialog.getByLabel('E2E офис', { exact: true }).check();
      await filtersDialog.getByRole('button', { name: 'Офис' }).click();
      await filtersDialog.getByRole('button', { name: 'Должность' }).click();
      await filtersDialog.getByLabel('Старший менеджер E2E', { exact: true }).check();
      await filtersDialog.getByRole('button', { name: 'Должность' }).click();
      await filtersDialog.getByRole('button', { name: 'Применить' }).click();
      await expectVisible(
        page.getByText(name, { exact: true }),
        'фильтр по роли, статусу и городу не нашёл сотрудника'
      );
    }
  },
  {
    name: 'сотрудники: замещения вкладкой',
    role: 'admin',
    async run(page, ctx) {
      await page.goto(`${BASE}/#/staff`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Сотрудники' }), 'не открылся STAFF-LIST');
      assert(
        (await page.locator('nav.desktop-nav a[href="#/substitutions"]').count()) === 0,
        'в навигации остался отдельный раздел замещений'
      );
      assert(
        (await page.getByRole('button', { name: 'Сотрудники' }).count()) === 0,
        'на вкладке сотрудников видна лишняя иконка'
      );

      await page.getByRole('button', { name: 'Замещения' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Замещения', exact: true }),
        'не открылась вкладка'
      );
      assert(
        (await page.getByRole('button', { name: 'Замещения' }).count()) === 0,
        'на вкладке замещений видна лишняя иконка'
      );
      await expectVisible(
        page.getByPlaceholder('Поиск: кто, заместитель, причина, даты'),
        'нет поиска по замещениям'
      );
      await expectVisible(page.getByRole('button', { name: 'Сортировка' }), 'нет сортировки');
      await page.getByRole('button', { name: 'Вид и фильтры' }).click();
      const substitutionFilterDialog = page.getByRole('dialog');
      await expectVisible(
        substitutionFilterDialog.getByText('Статусы'),
        'в фильтре замещений нет статусов'
      );
      await expectVisible(
        substitutionFilterDialog.getByText('Кто отсутствует'),
        'в фильтре замещений нет отсутствующего'
      );
      await expectVisible(
        substitutionFilterDialog.getByText('Заместитель'),
        'в фильтре замещений нет заместителя'
      );
      await expectVisible(
        substitutionFilterDialog.getByText('Период с'),
        'в фильтре замещений нет периода'
      );
      await substitutionFilterDialog.getByRole('button', { name: 'Статусы' }).click();
      await substitutionFilterDialog.getByLabel('Активные', { exact: true }).check();
      await substitutionFilterDialog.getByRole('button', { name: 'Статусы' }).click();
      await substitutionFilterDialog.getByRole('button', { name: 'Применить' }).click();
      await expectVisible(
        page.getByRole('button', { name: 'Справка по странице' }),
        'нет справки'
      );
      assert(
        (await page.getByRole('button', { name: 'Создать замещение' }).count()) === 0,
        'во вкладке замещений форма видна без запроса'
      );

      await page.getByRole('button', { name: 'Справка по странице' }).click();
      await expectVisible(
        page.getByText(/Здесь оформляются замещения/),
        'справка не переключилась на замещения'
      );
      await page.getByRole('button', { name: 'Понятно' }).click();

      await page.getByRole('button', { name: 'Добавить замещение' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Новое замещение' }),
        'не открылась страница нового замещения'
      );
      await expectVisible(
        page.getByText('STAFF-SUBST-NEW', { exact: true }),
        'у страницы нового замещения нет своего бейджа'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Создать замещение' }),
        'нет формы замещения на странице'
      );
      await page.getByRole('button', { name: 'Отмена' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Замещения', exact: true }),
        'после отмены не вернулись к списку замещений'
      );

      await page.getByRole('button', { name: 'К списку сотрудников' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Сотрудники', exact: true }),
        'кнопка назад не вернула к сотрудникам'
      );
      await expectVisible(
        page.locator('.tiles-grid .tile, .list .row:not(.row-head)').first(),
        'не вернулся список'
      );

      await page.goto(`${BASE}/#/substitutions`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.getByRole('heading', { name: 'Замещения', exact: true }),
        'старый адрес замещений не открылся'
      );
      await expectVisible(
        page.locator('nav.desktop-nav a[href="#/staff"].active'),
        'в шапке не активен раздел сотрудников'
      );

      const headers = {
        Authorization: `Bearer ${ctx.session.token}`,
        'Content-Type': 'application/json'
      };
      const managerResponse = await fetch(`${API}/managers`, { headers });
      assert(managerResponse.ok, `не удалось получить сотрудников: HTTP ${managerResponse.status}`);
      const managers = (await managerResponse.json()).data.items;
      assert(managers.length >= 2, 'для замещения нужно минимум два сотрудника');

      const beforeResponse = await fetch(`${API}/substitutions`, { headers });
      assert(beforeResponse.ok, `не удалось получить замещения: HTTP ${beforeResponse.status}`);
      const previous = (await beforeResponse.json()).data;

      for (const substitution of previous) {
        if (
          substitution.is_active &&
          String(substitution.reason ?? '').startsWith('E2E edit substitution')
        ) {
          await fetch(`${API}/substitutions/${substitution.id}`, { method: 'DELETE', headers });
        }
      }

      const manager = managers[0];
      const substitute = managers.find((entry) => entry.id !== manager.id);
      const createResponse = await fetch(`${API}/substitutions`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          manager_id: manager.id,
          substitute_id: substitute.id,
          date_from: '2099-12-30',
          date_to: '2099-12-31',
          reason: 'E2E edit substitution'
        })
      });
      assert(createResponse.ok, `не удалось создать замещение: HTTP ${createResponse.status}`);
      const created = (await createResponse.json()).data.item;

      await page.goto(`${BASE}/#/substitutions`, { waitUntil: 'domcontentloaded' });
      await page.reload({ waitUntil: 'domcontentloaded' });
      const substitutionSearch = page.getByPlaceholder('Поиск: кто, заместитель, причина, даты');
      await substitutionSearch.fill('E2E edit substitution');
      await substitutionSearch.press('Enter');
      await page.getByRole('button', { name: 'Редактировать замещение' }).first().click();

      await expectVisible(
        page.getByRole('heading', { name: 'Редактирование замещения' }),
        'не открылась страница редактирования замещения'
      );
      await expectVisible(
        page.getByText('STAFF-SUBST-EDIT', { exact: true }),
        'у страницы редактирования нет своего бейджа'
      );

      await page.getByLabel('Причина (необязательно)').fill('E2E edit substitution updated');
      await page.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(page.getByText('Замещение сохранено'), 'замещение не сохранилось');

      await page.getByRole('button', { name: 'Удалить замещение' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Замещения', exact: true }),
        'после удаления не вернулись к списку'
      );

      const afterResponse = await fetch(`${API}/substitutions`, { headers });
      const after = (await afterResponse.json()).data;
      assert(
        !after.some((substitution) => substitution.id === created.id),
        'удалённое замещение осталось в списке'
      );
    }
  },
  {
    name: 'сотрудники: ошибки полей',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/staff/new`, { waitUntil: 'domcontentloaded' });
      await page.getByLabel(/ФИО/).click();
      await page.getByLabel('E-mail *').click();
      await expectVisible(
        page.locator('label.field', { hasText: 'ФИО' }).locator('.error'),
        'ошибка обязательного поля не появилась после blur'
      );

      await page.getByLabel(/ФИО/).fill(`E2E Дубль ${Date.now()}`);
      await page.getByLabel('E-mail *').fill('admin@example.test');
      await page.getByRole('button', { name: 'Создать сотрудника' }).click();
      await expectVisible(
        page.locator('label.field', { hasText: 'E-mail' }).locator('.error'),
        'ошибка занятого e-mail не показана под полем'
      );
    }
  },
  {
    name: 'клиенты: перевод в сотрудника и обратно',
    role: 'admin',
    async run(page, ctx) {
      const token = ctx.session.token;
      const stamp = Date.now();
      const name = `E2E Конверсия ${stamp}`;
      const email = `convert-${stamp}@example.test`;
      const created = await fetch(`${API}/users`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ name, email, level: 5 })
      });
      assert(created.ok, `не удалось создать клиента для конвертации: HTTP ${created.status}`);
      const clientId = (await created.json()).data.id;

      const transferAttempt = await fetch(`${API}/clients/${clientId}/transfer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({})
      });
      assert(
        transferAttempt.status === 422,
        `передача клиента должна быть отключена, получен HTTP ${transferAttempt.status}`
      );

      await page.goto(`${BASE}/#/clients/${clientId}`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('tab', { name: 'Профиль' }).click();
      await page.getByRole('button', { name: 'Сделать сотрудником' }).click();

      const toStaff = page.getByRole('dialog');
      await expectVisible(toStaff, 'не открылась модалка перевода в сотрудника');
      await expectVisible(
        toStaff.getByText('Связей, мешающих переводу, нет.'),
        'перевод клиента в сотрудника заблокирован'
      );
      await toStaff.getByRole('button', { name: 'Сделать сотрудником' }).click();
      await expectVisible(page.locator('h1', { hasText: name }), 'не открылась карточка сотрудника');
      await expectVisible(page.getByText('Менеджер'), 'роль сотрудника не отображается');

      await page.getByRole('button', { name: 'Сделать клиентом' }).click();
      const toClient = page.getByRole('dialog');
      await expectVisible(toClient, 'не открылась модалка перевода в клиента');
      await expectVisible(
        toClient.getByText('Связей, мешающих переводу, нет.'),
        'перевод сотрудника в клиента заблокирован'
      );
      await toClient.getByRole('button', { name: 'Сделать клиентом' }).click();
      await expectVisible(page.locator('h1', { hasText: name }), 'не открылась карточка клиента');
    }
  },
  {
    name: 'подсказки: не выходят за экран',
    role: 'admin',
    async run(page) {
      const checkTips = async (heading) => {
        await expectVisible(page.getByRole('heading', { name: heading }), `не открылась страница «${heading}»`);
        const triggers = page.getByRole('button', { name: 'Подсказка' });
        const count = await triggers.count();
        assert(count > 0, `на странице «${heading}» нет подсказок`);

        for (let index = 0; index < count; index += 1) {
          const trigger = triggers.nth(index);
          await trigger.scrollIntoViewIfNeeded();
          await trigger.click();
          const tooltip = page.getByRole('tooltip');
          await expectVisible(tooltip, `не открылась подсказка №${index + 1} на «${heading}»`);
          const box = await tooltip.evaluate((element) => {
            const rect = element.getBoundingClientRect();

            return {
              left: rect.left,
              top: rect.top,
              right: rect.right,
              bottom: rect.bottom,
              width: window.innerWidth,
              height: window.innerHeight
            };
          });

          assert(box.left >= -0.5, `подсказка №${index + 1} выходит слева на «${heading}»`);
          assert(box.top >= -0.5, `подсказка №${index + 1} выходит сверху на «${heading}»`);
          assert(box.right <= box.width + 0.5, `подсказка №${index + 1} выходит справа на «${heading}»`);
          assert(box.bottom <= box.height + 0.5, `подсказка №${index + 1} выходит снизу на «${heading}»`);

          await page.keyboard.press('Escape');
          await tooltip.waitFor({ state: 'detached' });
        }
      };

      await page.goto(`${BASE}/#/clients/new`, { waitUntil: 'domcontentloaded' });
      await checkTips('Новый клиент');
      await page.goto(`${BASE}/#/staff/new`, { waitUntil: 'domcontentloaded' });
      await checkTips('Новый сотрудник');
    }
  },
  {
    name: 'чат: отправка сообщения',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/chat`, { waitUntil: 'domcontentloaded' });
      const thread = page.locator('.threads .thread').first();
      await expectVisible(thread, 'нет ни одного треда в чате');
      await thread.click();

      const composer = page.locator('form.composer textarea');
      await expectVisible(composer, 'нет поля ввода сообщения');
      const marker = `E2E проверка ${Date.now()}`;
      await composer.fill(marker);
      await page.locator('form.composer').getByRole('button', { name: 'Отправить' }).click();

      await expectVisible(page.getByText(marker), 'отправленное сообщение не появилось');
    }
  },
  {
    name: 'склад: поиск и сортировка',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });

      const search = page.locator('form.search input[type="search"]').first();
      await expectVisible(search, 'нет поля поиска на складе');
      await search.fill('iPhone');
      await search.press('Enter');

      await expectVisible(page.getByText(/Смартфон Apple iPhone/).first(), 'поиск не вернул позиции');

      assert(
        (await page.getByRole('button', { name: /Фильтр/ }).count()) === 0,
        'фильтры на складе должны быть убраны'
      );

      await page.getByRole('button', { name: 'Сортировка' }).click();
      await page.getByRole('button', { name: 'Остаток: по убыванию' }).click();
      await page.getByRole('button', { name: 'Применить' }).click();
      await expectVisible(page.locator('.levels .level').first(), 'после смены сортировки пропали строки');
    }
  },
  {
    name: 'склад: вид и фильтры',
    role: 'admin',
    async run(page, ctx) {
      await fetch(`${API}/preferences/filters/stocks`, {
        method: 'DELETE',
        headers: { Authorization: `Bearer ${ctx.session.token}` }
      });
      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.locator('.levels .level').first(), 'не загрузился список склада');

      await page.getByRole('button', { name: /^Вид/ }).click();

      const dialog = page.getByRole('dialog');
      await dialog.getByRole('radio', { name: 'Плитка', exact: true }).check();
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      const inactiveBox = dialog.getByRole('checkbox', {
        name: 'Деактивированные',
        exact: true
      });
      await inactiveBox.uncheck();
      assert(!(await inactiveBox.isChecked()), 'не снялся статус «Деактивированные»');
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByLabel('Условие').selectOption('gt');
      await dialog.getByLabel('Значение').fill('0');

      assert(
        await dialog.getByRole('radio', { name: 'Плитка', exact: true }).isChecked(),
        'не выбрался вид «Плитка»'
      );
      assert((await dialog.getByLabel('Условие').inputValue()) === 'gt', 'не выбрано условие остатка');
      assert((await dialog.getByLabel('Значение').inputValue()) === '0', 'не задано значение остатка');

      const requestPromise = page.waitForRequest((request) =>
        request.url().includes('/api/v2/stocks/levels?')
      );

      await dialog.getByRole('button', { name: 'Применить' }).click();
      const requestUrl = new URL((await requestPromise).url());

      assert(requestUrl.searchParams.get('active') === 'active', 'в запрос не попал фильтр по статусу');
      assert(requestUrl.searchParams.get('qty_op') === 'gt', 'в запрос не попал фильтр по остатку');
      assert(requestUrl.searchParams.get('qty') === '0', 'в запрос не попало значение остатка');

      await expectVisible(page.locator('.levels.tiles'), 'не переключился вид на плитку');
    }
  },
  {
    name: 'склады: вид и фильтры',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Номенклатура' }), 'не открылась номенклатура');
      assert(
        (await page.getByRole('link', { name: 'Склады' }).count()) === 0,
        'склады остались отдельным пунктом навигации'
      );
      const toolbarLabels = await page
        .locator('.filter-icons button')
        .evaluateAll((buttons) => buttons.map((button) => button.getAttribute('aria-label')));
      const priceTypesIndex = toolbarLabels.indexOf('Управление типами цен');
      if (priceTypesIndex >= 0) {
        assert(
          toolbarLabels[priceTypesIndex + 1] === 'Управление складами',
          'иконка управления складами не стоит рядом с типами цен'
        );
      }
      await page.getByRole('button', { name: 'Управление складами' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Управление складами' }),
        'не открылось управление складами'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Назад к номенклатуре' }),
        'нет возврата к номенклатуре'
      );
      await expectVisible(page.locator('.levels .level').first(), 'не загрузился список складов');

      await page.getByRole('button', { name: /^Вид/ }).click();

      const dialog = page.getByRole('dialog');
      await dialog.getByRole('radio', { name: 'Плитка', exact: true }).check();
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByRole('checkbox', { name: 'Деактивированные', exact: true }).uncheck();
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByRole('button', { name: 'Применить' }).click();

      await expectVisible(page.locator('.levels.tiles'), 'не переключился вид на плитку');
    }
  },
  {
    name: 'склад: типы номенклатуры',
    role: 'admin',
    async run(page) {
      const stamp = Date.now();
      const title = `E2E Тип ${stamp}`;
      const updatedTitle = `${title} обновлён`;

      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Управление типами номенклатуры' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Типы номенклатуры' }),
        'не открылась страница типов номенклатуры'
      );
      await expectVisible(
        page.locator('nav.desktop-nav a[href="#/stocks"].active'),
        'в шапке не подсвечена иконка «Номенклатура»'
      );

      await page.getByRole('button', { name: 'Добавить тип' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Новый тип номенклатуры' }),
        'не открылась страница создания типа'
      );
      await page.getByLabel('Название').fill(title);
      await page.getByLabel('Описание').fill('Тип из e2e');
      await page.getByRole('button', { name: 'Создать тип' }).click();
      await expectVisible(page.getByText(title), 'созданный тип не появился в списке');

      const row = page.locator('tr', { hasText: title });
      await row.getByRole('button', { name: 'Редактировать тип' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Редактировать тип номенклатуры' }),
        'не открылась страница редактирования типа'
      );
      await page.getByLabel('Название').fill(updatedTitle);
      await page.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(page.getByText('Изменения сохранены'), 'изменения типа не сохранились');

      await page.getByRole('button', { name: 'Удалить тип' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Типы номенклатуры' }),
        'после удаления не открылся список типов'
      );
      await page.getByText(updatedTitle).waitFor({ state: 'detached' });
    }
  },
  {
    name: 'склад: типы цен',
    role: 'admin',
    async run(page, ctx) {
      const stamp = Date.now();
      const title = `E2E цена ${stamp}`;
      const updatedTitle = `${title} опт`;
      const description = `Условия типа ${stamp}`;
      const updatedDescription = `${description} обновлено`;
      const publicTypes = async () => {
        const response = await fetch(`${API}/price-types`, {
          headers: { Authorization: `Bearer ${ctx.session.token}` }
        });
        assert(response.ok, `не удалось получить доступные типы цен: HTTP ${response.status}`);
        return (await response.json()).data.items;
      };

      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Управление типами цен' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Типы цен' }), 'не открылись типы цен');

      await page.getByRole('button', { name: 'Добавить тип цен' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Новый тип цен' }),
        'не открылась форма создания типа цен'
      );
      await page.getByLabel('Название').fill(title);
      await page.getByLabel('Описание').fill(description);
      await page.getByRole('button', { name: 'Создать тип' }).click();
      await expectVisible(page.getByText(title), 'созданный тип цен не появился в списке');
      await expectVisible(page.getByText(description), 'описание типа цен не появилось в списке');

      const row = page.locator('tr', { hasText: title });
      assert((await row.locator('code').count()) === 1, 'в строке типа цен нет кода');
      await row.getByRole('button', { name: 'Редактировать тип цен' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Редактирование типа цен' }),
        'не открылась форма редактирования типа цен'
      );
      await expectVisible(page.getByText('Использование'), 'нет блока использования типа цен');
      assert(
        (await page.getByLabel('Описание').inputValue()) === description,
        'описание типа цен не загрузилось в форму'
      );

      await page.getByLabel('Название').fill(updatedTitle);
      await page.getByLabel('Описание').fill(updatedDescription);
      const available = page.locator('.availability input[type="checkbox"]');
      await available.uncheck();
      await page.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(page.getByText('Тип цен сохранён'), 'тип цен не сохранился');
      assert(
        (await page.getByLabel('Описание').inputValue()) === updatedDescription,
        'описание типа цен не сохранилось'
      );
      assert(
        !(await publicTypes()).some((item) => item.title === updatedTitle),
        'выключенный тип цен остался в выборе'
      );

      await available.check();
      await page.getByRole('button', { name: 'Сохранить' }).click();
      await expectVisible(
        page.getByText('Тип цен сохранён'),
        'тип цен не включён обратно'
      );
      assert(
        (await publicTypes()).some((item) => item.title === updatedTitle),
        'включённый тип цен не вернулся в выбор'
      );

      await page.getByRole('button', { name: 'Удалить тип цен' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Типы цен' }), 'не вернулись к списку');
      await page.getByText(updatedTitle).waitFor({ state: 'detached' });
    }
  },
  {
    name: 'склад: тип — две колонки и состав',
    role: 'admin',
    async run(page, ctx) {
      const response = await fetch(`${API}/admin/item-types`, {
        headers: { Authorization: `Bearer ${ctx.session.token}` }
      });
      assert(response.ok, `не удалось получить типы: HTTP ${response.status}`);
      const types = (await response.json()).data.types;
      const type = types.find((item) => (item.positions_count ?? 0) > 0);
      assert(type, 'нет типа с позициями для проверки');

      await page.goto(`${BASE}/#/stocks/types/${type.id}`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.getByRole('heading', { name: 'Редактировать тип номенклатуры' }),
        'не открылась карточка типа'
      );
      await expectVisible(page.getByRole('heading', { name: 'Состав типа' }), 'нет блока состава');
      assert(
        (await page.locator('.layout > .card').count()) === 2,
        'карточка типа должна быть в две колонки'
      );

      await page.getByRole('button', { name: /Все позиции типа/ }).click();
      const dialog = page.getByRole('dialog');
      await expectVisible(dialog.getByText('Все позиции типа'), 'не открылась модалка состава');
      const expected = Math.min(20, type.positions_count);
      assert(
        (await dialog.locator('tbody tr').count()) === expected,
        `в модалке должно быть ${expected} строк`
      );

      if (type.positions_count > 20) {
        await dialog.getByRole('button', { name: 'Страница 2' }).click();
        const remaining = Math.min(20, type.positions_count - 20);
        assert(
          (await dialog.locator('tbody tr').count()) === remaining,
          `на второй странице должно быть ${remaining} строк`
        );
      }
    }
  },
  {
    name: 'склад: группа — две колонки и состав',
    role: 'admin',
    async run(page, ctx) {
      const response = await fetch(`${API}/admin/item-groups`, {
        headers: { Authorization: `Bearer ${ctx.session.token}` }
      });
      assert(response.ok, `не удалось получить группы: HTTP ${response.status}`);
      const groups = (await response.json()).data.items;
      const group = groups.find((item) => (item.positions_count ?? 0) > 0);
      assert(group, 'нет группы с позициями для проверки');

      await page.goto(`${BASE}/#/stocks/groups/${group.id}`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.getByRole('heading', { name: 'Редактирование группы номенклатуры' }),
        'не открылась карточка группы'
      );
      await expectVisible(page.getByRole('heading', { name: 'Состав группы' }), 'нет блока состава');
      assert(
        (await page.locator('.layout > .card').count()) === 2,
        'карточка группы должна быть в две колонки'
      );
      assert(
        await page.getByRole('button', { name: 'Удалить группу' }).isDisabled(),
        'группу с позициями нельзя удалять'
      );

      await page.getByRole('button', { name: /Все позиции группы/ }).click();
      const dialog = page.getByRole('dialog');
      await expectVisible(dialog.getByText('Все позиции группы'), 'не открылась модалка состава');
      const expected = Math.min(20, group.positions_count);
      assert(
        (await dialog.locator('tbody tr').count()) === expected,
        `в модалке должно быть ${expected} строк`
      );

      if (group.positions_count > 20) {
        await dialog.getByRole('button', { name: 'Страница 2' }).click();
        const remaining = Math.min(20, group.positions_count - 20);
        assert(
          (await dialog.locator('tbody tr').count()) === remaining,
          `на второй странице должно быть ${remaining} строк`
        );
      }
    }
  },
  {
    name: 'отчёты: переключение вкладок',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/reports`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByText('Создано за период'), 'не загрузились плитки отчёта');

      await page.getByRole('button', { name: 'По складам' }).click();
      await expectVisible(
        page.getByText('По складам — остатки и реализация').first(),
        'вкладка «По складам» не отрисовалась'
      );

      await page.getByRole('button', { name: 'Динамика' }).click();
      await expectVisible(page.getByText(/Динамика/).first(), 'вкладка «Динамика» не отрисовалась');

      await page.getByRole('button', { name: 'По статусам' }).click();
      await expectVisible(page.getByText('Доля'), 'в «По статусам» нет столбца «Доля»');
    }
  },
  {
    name: 'отчёты: поиски номенклатуры',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/reports`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByText('Создано за период'), 'не загрузились плитки отчёта');

      await page.getByRole('button', { name: 'Поиски номенклатуры' }).click();
      await expectVisible(page.getByText('Частые запросы'), 'не открылась вкладка поисков');
      await expectVisible(page.getByText('Последние поиски'), 'нет списка последних поисков');

      await page.goto(`${BASE}/#/settings`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Настройки' }), 'не открылись настройки');
      const adminTabs = page.locator('.page-hero .tabs');
      assert(
        (await adminTabs.getByRole('button', { name: 'Поиски номенклатуры' }).count()) === 0,
        'вкладка поисков осталась в настройках'
      );
      assert(
        (await adminTabs.getByRole('button', { name: 'Справочники' }).count()) === 0,
        'вкладка справочников осталась в настройках'
      );
    }
  },
  {
    name: 'заявки: пустое состояние при фильтрах',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.locator('.levels .card, .tiles .tile').first(),
        'не загрузился список заявок'
      );

      await page.getByRole('button', { name: /^Вид/ }).click();
      const dates = page.locator('.view-period input[type="date"]');
      await dates.nth(0).fill('2000-01-01');
      await dates.nth(1).fill('2000-01-31');
      await page.getByRole('button', { name: 'Применить' }).click();

      const title = page.locator('.empty-title');
      await expectVisible(title, 'нет сообщения «Заявки не найдены»');
      assert(
        (await title.textContent()).trim() === 'Заявки не найдены',
        'неверный заголовок пустого состояния'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Сбросить фильтры' }),
        'нет кнопки сброса фильтров'
      );

      await page.getByRole('button', { name: 'Сбросить фильтры' }).click();
      await expectVisible(
        page.locator('.levels .card, .tiles .tile').first(),
        'после сброса фильтров список не вернулся'
      );
    }
  },
  {
    name: 'заявки: фильтры по статусу, просрочке, менеджеру и клиенту',
    role: 'manager',
    async run(page, ctx) {
      const headers = { Authorization: `Bearer ${ctx.session.token}` };
      const [statusesResponse, requestsResponse] = await Promise.all([
        fetch(`${API}/request-statuses`, { headers }),
        fetch(`${API}/requests?limit=30`, { headers })
      ]);
      assert(statusesResponse.ok, `не удалось загрузить статусы: HTTP ${statusesResponse.status}`);
      assert(requestsResponse.ok, `не удалось загрузить заявки: HTTP ${requestsResponse.status}`);

      const status = (await statusesResponse.json()).data[0];
      assert(status, 'нет статусов для проверки фильтра');
      const requestWithClient = (await requestsResponse.json()).data.items.find(
        (item) => item.client !== null && item.client.name !== ''
      );
      assert(requestWithClient, 'нет заявки с клиентом для проверки фильтра');

      const client = requestWithClient.client;

      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: /^Вид/ }).click();

      const dialog = page.getByRole('dialog');
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByRole('checkbox', { name: status.title, exact: true }).click();
      await dialog.getByRole('button', { name: 'Статусы' }).click();
      await dialog.getByRole('button', { name: 'Просрочка' }).click();
      await dialog.getByRole('checkbox', { name: 'Без просрочки', exact: true }).click();
      await dialog.getByRole('button', { name: 'Просрочка' }).click();
      await dialog.getByRole('button', { name: 'Менеджеры' }).click();
      await dialog.getByRole('checkbox', { name: 'В работе у меня', exact: true }).click();
      await dialog.getByRole('button', { name: 'Менеджеры' }).click();
      await dialog.getByLabel('Клиент').fill(client.name);
      await dialog.locator('.cf-item').filter({ hasText: client.name }).first().click();

      const requestPromise = page.waitForRequest(
        (request) =>
          request.url().includes('/api/v2/requests?') &&
          request.url().includes('statuses=') &&
          request.url().includes('client_id=')
      );

      await dialog.getByRole('button', { name: 'Применить' }).click();
      const requestUrl = new URL((await requestPromise).url());

      assert(
        requestUrl.searchParams.get('statuses') === status.code,
        'в запрос не попал фильтр по статусу'
      );
      assert(requestUrl.searchParams.get('overdue') === 'no', 'в запрос не попал фильтр по просрочке');
      assert(
        requestUrl.searchParams.get('manager_scopes') === 'mine',
        'в запрос не попал фильтр по менеджеру'
      );
      assert(
        requestUrl.searchParams.get('client_id') === String(client.id),
        'в запрос не попал фильтр по клиенту'
      );
    }
  },
  {
    name: 'настройки: модули — группы прав',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/settings`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Модули', exact: true }).click();

      const card = page.locator('.module-card').filter({ hasText: 'Заявки' }).first();
      await expectVisible(card, 'нет карточки модуля «Заявки»');
      await expectVisible(card.getByText('Просмотр всех заявок', { exact: true }), 'нет группы просмотра');
      await expectVisible(card.getByText('Создание заявок', { exact: true }), 'нет группы создания');
      assert(
        (await card.getByRole('button', { name: 'Создание заявок' }).count()) > 0,
        'у группы создания нет выбора ролей'
      );
    }
  },
  {
    name: 'админ: журнал — русские подписи',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Журнал' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Журнал' }), 'не открылся журнал');

      await expectVisible(page.getByText('Вход в систему').first(), 'журнал без русской подписи действия');
      await expectVisible(page.getByText('auth.login').first(), 'журнал без английского кода действия');

      await page.goto(`${BASE}/#/settings`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Настройки' }), 'не открылись настройки');
      assert(
        (await page
          .locator('.page-hero .tabs')
          .getByRole('button', { name: 'Журнал' })
          .count()) === 0,
        'вкладка журнала осталась в настройках'
      );
    }
  },
  {
    name: 'настройки: вкладки с иконками',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/settings`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Настройки' }), 'не открылись настройки');

      const tabs = page.locator('.page-hero .tabs button');
      await expectVisible(
        page.getByRole('button', { name: 'База данных' }),
        'нет вкладки «База данных»'
      );
      assert(
        (await tabs.count()) === (await tabs.locator('svg').count()),
        'не у всех вкладок есть иконки'
      );
      assert((await tabs.first().innerText()).trim() === '', 'у вкладок остался текст');
      assert(
        (await tabs.first().getAttribute('title')) === 'Общие',
        'у вкладки нет всплывающей подсказки'
      );
      assert((await tabs.count()) === 6, 'ожидалось шесть основных вкладок');
      await expectVisible(
        page.getByRole('button', { name: 'Параметры работы' }),
        'нет вкладки «Параметры работы»'
      );
      assert(
        (await tabs.getByRole('button', { name: 'Типы цен' }).count()) === 0,
        'вкладка типов цен осталась в настройках'
      );
      assert(
        (await tabs.getByRole('button', { name: 'Роли', exact: true }).count()) === 0,
        'вкладка «Роли» всё ещё показана'
      );

      await expectVisible(
        page.getByRole('heading', { name: 'Брендинг и реквизиты' }),
        'не открылись общие настройки'
      );
      await expectVisible(
        page.getByLabel(/^Описание системы/),
        'нет поля описания системы'
      );
      await expectVisible(
        page.getByLabel('Наименование организации'),
        'нет поля наименования организации'
      );
      await expectVisible(
        page.getByLabel(/^Реквизиты/),
        'нет поля реквизитов'
      );
      assert(
        (await page.getByRole('heading', { name: 'Параметры работы' }).count()) === 0,
        'параметры работы остались на вкладке «Общие»'
      );
      await page.getByRole('button', { name: 'Параметры работы' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Параметры работы' }),
        'нет отдельной вкладки параметров работы'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Сохранить' }).first(),
        'нет кнопки сохранения параметров работы'
      );
      assert(
        (await page.getByText('Корзина (добавление номенклатуры и оформление)').count()) === 0,
        'отдельный модуль «Корзина» всё ещё показан'
      );

      await page.getByRole('button', { name: 'Модули', exact: true }).click();
      await expectVisible(
        page.getByRole('button', { name: 'Доступные роли' }).first(),
        'нет мультисписка ролей в модулях'
      );
      await page.getByRole('button', { name: 'Подсказка' }).first().click();
      await expectVisible(
        page.getByRole('tooltip'),
        'не открылся хелпер по клику'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Документы', exact: true }),
        'нет вкладки «Документы»'
      );

      await page.getByRole('button', { name: 'Почта', exact: true }).click();
      await page.getByRole('button', { name: 'SMTP', exact: true }).click();
      await expectVisible(page.getByRole('heading', { name: 'SMTP' }), 'не открылась вкладка SMTP');

      await page.getByRole('button', { name: 'Шаблоны писем' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Шаблоны писем' }),
        'не открылась вкладка шаблонов'
      );

      await page.getByRole('button', { name: 'Расписание отчётов' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Расписание отчётов' }),
        'не открылась вкладка расписаний'
      );

      await page.getByRole('button', { name: 'Очередь отправки' }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'Очередь отправки' }),
        'не открылась вкладка очереди'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Очистить' }),
        'нет кнопки очистки очереди'
      );

      await page.getByRole('button', { name: 'База данных', exact: true }).click();
      await expectVisible(
        page.getByRole('heading', { name: 'База данных' }),
        'не открылась вкладка базы данных'
      );
    }
  },
  {
    name: 'журнал: доступ только у админов',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.locator('nav.desktop-nav'), 'не загрузилась шапка');

      assert(
        (await page.getByRole('button', { name: 'Журнал' }).count()) === 0,
        'иконка журнала видна обычному менеджеру'
      );

      await page.goto(`${BASE}/#/admin/audit`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.getByRole('heading', { name: 'Страница не найдена' }),
        'менеджеру открылся журнал по прямому адресу'
      );
    }
  },
  {
    name: 'безопасность: клиент не меняет мета заявки и не читает фото номенклатуры',
    role: 'client',
    async run(page, ctx) {
      const token = ctx.session.token;
      const api = async (method, path, body) => {
        const response = await fetch(`${API}${path}`, {
          method,
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: body ? JSON.stringify(body) : undefined
        });
        let json = null;
        try {
          json = await response.json();
        } catch {
          // не JSON — не важно для этих проверок
        }
        return { status: response.status, json };
      };

      const list = (await api('GET', '/requests?limit=30')).json.data.items;
      let target = null;

      for (const item of list) {
        const detail = (await api('GET', `/requests/${item.id}`)).json.data;

        if (detail.can?.cancel === true) {
          target = { id: item.id, version: detail.request.version };
          assert(
            (detail.assignments ?? []).length === 0,
            'клиент получает список передач заявки'
          );
          assert(
            (detail.history ?? []).every((row) => (row.comment ?? '') === ''),
            'клиент получает внутренние комментарии истории'
          );
          break;
        }
      }

      assert(target, 'не найдена незавершённая заявка клиента для проверки');

      const priority = await api('POST', `/requests/${target.id}/meta`, {
        priority: 3,
        version: target.version
      });
      assert(priority.status === 403, `клиент не должен менять важность (получено ${priority.status})`);

      const subject = await api('POST', `/requests/${target.id}/meta`, {
        subject: 'e2e-forbidden',
        version: target.version
      });
      assert(subject.status === 403, `клиент не должен менять тему (получено ${subject.status})`);

      const createClient = await api('POST', '/clients', { name: 'forbidden-client' });
      assert(
        createClient.status === 403,
        `клиент не должен создавать клиентов (получено ${createClient.status})`
      );

      await page.goto(`${BASE}/#/clients/new`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.getByRole('heading', { name: 'Страница не найдена' }),
        'клиенту не должен открываться маршрут создания клиента'
      );

      const staffList = await api('GET', '/users?scope=staff');
      assert(staffList.status === 403, `клиент не должен видеть сотрудников (получено ${staffList.status})`);

      await page.goto(`${BASE}/#/staff`, { waitUntil: 'domcontentloaded' });
      await expectVisible(
        page.getByRole('heading', { name: 'Страница не найдена' }),
        'клиенту не должен открываться раздел сотрудников'
      );

      const photo = await fetch(`${API}/stocks/photos/1`, {
        headers: { Authorization: `Bearer ${token}` }
      });
      assert(photo.status === 403, `клиент не должен получать фото номенклатуры (получено ${photo.status})`);
    }
  },
  {
    name: 'req: закрытая заявка только для чтения',
    role: 'manager',
    async run(page, ctx) {
      const token = ctx.session.token;
      const api = async (method, path, body) => {
        const response = await fetch(`${API}${path}`, {
          method,
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: body ? JSON.stringify(body) : undefined
        });

        return { status: response.status, json: await response.json().catch(() => null) };
      };

      const list = await api('GET', '/requests?per_page=50');
      assert(list.status === 200, `не удалось получить список заявок (${list.status})`);

      const closed = (list.json?.data?.items ?? []).find((item) => item.status.is_final);
      assert(closed, 'нет закрытой заявки для проверки (нужны демо-данные)');

      const detail = await api('GET', `/requests/${closed.id}`);
      assert(detail.json?.data?.can?.closed === true, 'API не помечает заявку закрытой');

      const comment = await api('POST', `/requests/${closed.id}/comments`, { body: 'e2e' });
      assert(
        comment.status === 422 && comment.json?.error?.code === 'final_status',
        `комментарий в закрытую заявку не заблокирован (${comment.status})`
      );

      const activity = await api('POST', `/requests/${closed.id}/activities`, {
        type: 'custom',
        subject: 'e2e'
      });
      assert(
        activity.status === 422 && activity.json?.error?.code === 'final_status',
        `событие в закрытую заявку не заблокировано (${activity.status})`
      );

      const files = await api('GET', `/requests/${closed.id}/attachments`);
      assert(files.json?.data?.can_upload === false, 'загрузка файлов в закрытую заявку не заблокирована');

      const thread = detail.json?.data?.chat?.thread_id ?? 0;

      if (thread > 0) {
        const message = await api('POST', `/chat/threads/${thread}/messages`, {
          body: 'e2e',
          request_id: closed.id
        });
        assert(
          message.status === 422 && message.json?.error?.code === 'final_status',
          `сообщение в чат закрытой заявки не заблокировано (${message.status})`
        );
      }

      await page.goto(`${BASE}/#/requests/${closed.id}`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.locator('.closed-notice'), 'нет плашки о закрытой заявке');

      await page.getByLabel(/Активность/).click();
      await page.waitForTimeout(800);
      assert(
        (await page.locator('.event-composer-card form').count()) === 0,
        'форма события доступна в закрытой заявке'
      );

      await page.getByLabel(/Файлы/).click();
      await page.waitForTimeout(800);
      assert(
        (await page.locator('.files-upload-form').count()) === 0,
        'загрузка файлов доступна в закрытой заявке'
      );

      await page.getByLabel(/Чат по заявке/).click();
      await page.waitForTimeout(800);
      assert(
        (await page.locator('.chat-form').count()) === 0,
        'форма чата доступна в закрытой заявке'
      );
    }
  },
  {
    name: 'Мобильный клиент: список заявок и карточка',
    role: 'client',
    mobile: true,
    async run(page, ctx) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.locator('nav.mobile-nav'), 'на мобильном нет нижней навигации');
      assert(
        !(await page.locator('nav.desktop-nav').isVisible()),
        'на мобильном видна десктопная навигация'
      );

      if ((await page.locator('main a.card[href*="/requests/"]').count()) === 0) {
        const created = await fetch(`${API}/requests`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${ctx.session.token}`,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            subject: `e2e-mobile ${Date.now()}`,
            description: 'Проверка мобильного списка заявок'
          })
        });
        assert(created.ok, `не удалось создать заявку для проверки (HTTP ${created.status})`);
        await page.reload({ waitUntil: 'domcontentloaded' });
      }

      const tile = page.locator('main a.card[href*="/requests/"]').first();
      await expectVisible(tile, 'у клиента нет заявок в списке');
      await tile.click();

      const hash = await page.evaluate(() => location.hash);
      assert(/^#\/requests\/\d+/.test(hash), `не открылась карточка заявки (${hash})`);
      await expectVisible(page.locator('h1').first(), 'в карточке нет заголовка');

      await page.goBack();
      await page.waitForTimeout(700);
      await expectVisible(page.locator('main a.card[href*="/requests/"]').first(), 'возврат из карточки не вернул к списку');
      assert(
        (await page.evaluate(() => location.hash)) === '#/requests',
        'после возврата не список заявок'
      );
    }
  },
  {
    name: 'Мобильный клиент: свайп вкладок профиля',
    role: 'client',
    mobile: true,
    async run(page) {
      await page.goto(`${BASE}/#/profile`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(1500);

      const current = () =>
        page.evaluate(
          () =>
            document
              .querySelector('[role="tab"][aria-selected="true"]')
              ?.getAttribute('aria-label') ?? ''
        );

      const before = await current();
      assert(before === 'Профиль', `профиль открыт не на первой вкладке («${before}»)`);

      await swipeOnPage(page, 320, 120);
      const afterLeft = await current();
      assert(afterLeft !== before, 'свайп влево не переключил вкладку');

      await swipeOnPage(page, 120, 320);
      const afterRight = await current();
      assert(afterRight === before, `свайп вправо не вернул вкладку «${before}» (сейчас «${afterRight}»)`);
    }
  },
  {
    name: 'Мобильный клиент: корзина',
    role: 'client',
    mobile: true,
    async run(page) {
      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });

      const add = page.locator('button[aria-label^="Добавить в корзину"]').first();
      await expectVisible(add, 'нет кнопок «Добавить в корзину» (склад или корзина выключены?)');
      await add.click();

      const cart = page.locator('header button[aria-label^="Корзина"]').first();
      await expectVisible(cart, 'нет кнопки корзины в шапке');
      await cart.click();

      await page.waitForTimeout(600);
      const hash = await page.evaluate(() => location.hash);
      assert(hash === '#/cart', `корзина не открылась (${hash})`);
      assert(
        !(await page.locator('.page').first().isHidden()),
        'страница корзины пустая'
      );
    }
  }
];

async function swipeOnPage(page, fromX, toX, y = 420) {
  await page.evaluate(
    ([x1, x2, py]) => {
      const target = document.querySelector('section.page') ?? document.body;
      const touch = (x) => new Touch({ identifier: 1, target, clientX: x, clientY: py });
      target.dispatchEvent(
        new TouchEvent('touchstart', {
          touches: [touch(x1)],
          changedTouches: [touch(x1)],
          bubbles: true,
          cancelable: true
        })
      );
      target.dispatchEvent(
        new TouchEvent('touchend', {
          touches: [],
          changedTouches: [touch(x2)],
          bubbles: true,
          cancelable: true
        })
      );
    },
    [fromX, toX, y]
  );
  await page.waitForTimeout(500);
}

async function main() {
  const passwords = await loadPasswords();
  const selected = scenarios.filter(
    (scenario) =>
      Boolean(scenario.mobile) === MOBILE
      && (ONLY.length === 0 || ONLY.some((needle) => scenario.name.includes(needle)))
  );

  await mkdir(OUT, { recursive: true });

  log(`Фронтенд: ${BASE}; API: ${API}; браузер: ${CHANNEL}`);
  log(`Сценариев: ${selected.length}`);

  const browser = await chromium.launch(launchOptions);
  const results = [];

  for (const scenario of selected) {
    const password = passwordFor(scenario.role, passwords);
    const started = Date.now();

    if (!password) {
      log(`✗ ${scenario.name} — не задан пароль для роли ${scenario.role}`);
      results.push({ name: scenario.name, ok: false, reason: 'нет пароля' });
      continue;
    }

    const context = await browser.newContext({
      viewport: MOBILE ? { width: 390, height: 844 } : { width: 1440, height: 900 },
      ...(MOBILE ? { isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : {}),
      locale: 'ru-RU',
      timezoneId: 'Europe/Moscow'
    });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('dialog', (dialog) => dialog.accept());

    try {
      let session = null;

      if (!scenario.bare) {
        session = await apiLogin(scenario.role, password);
        await context.addInitScript(
          ([token, user]) => {
            localStorage.setItem('crm_token', token);
            localStorage.setItem('crm_user', user);
            localStorage.setItem('crm.cookie.accepted', '1');
          },
          [session.token, JSON.stringify(session.user)]
        );
      }

      await scenario.run(page, { login: scenario.role, password, session });

      log(`✓ ${scenario.name} (${Date.now() - started} мс)`);
      results.push({ name: scenario.name, ok: true });
    } catch (cause) {
      const file = path.join(OUT, `${slug(scenario.name)}.png`);

      try {
        await page.screenshot({ path: file, fullPage: false });
      } catch {
        // скриншот не критичен
      }

      log(`✗ ${scenario.name} — ${cause.message} (${path.basename(file)})`);
      results.push({ name: scenario.name, ok: false, reason: cause.message });
    } finally {
      await context.close();
    }
  }

  await browser.close();

  const failed = results.filter((result) => !result.ok);

  log('');
  log(`Итог: ${results.length - failed.length}/${results.length} сценариев пройдено`);

  if (failed.length > 0) {
    for (const result of failed) {
      log(`  ✗ ${result.name}: ${result.reason}`);
    }

    process.exitCode = 1;
  }
}

main().catch((cause) => {
  log(`Ошибка запуска: ${cause.message}`);
  process.exitCode = 1;
});
