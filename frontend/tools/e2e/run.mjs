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
 *   CRM_E2E_ONLY         список сценариев через запятую (фильтр по подстроке имени)
 */

import { chromium } from 'playwright-core';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.CRM_BASE_URL ?? 'http://localhost:5174').replace(/\/$/, '');
const API = (process.env.CRM_API_URL ?? 'http://127.0.0.1:8080/api/v2').replace(/\/$/, '');
const CHANNEL = process.env.CRM_BROWSER_CHANNEL ?? 'msedge';
const HEADED = process.env.CRM_HEADED === '1';
const launchOptions =
  CHANNEL && CHANNEL !== 'bundled' ? { channel: CHANNEL, headless: !HEADED } : { headless: !HEADED };
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
      await page.getByRole('button', { name: 'Сессии' }).click();
      await page.getByRole('button', { name: 'Выйти из текущей сессии' }).click();

      await expectVisible(
        page.locator('input[name="login"]'),
        'после выхода не вернулись на экран входа'
      );
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
      assert(!(await submit.isDisabled()), 'после выбора клиента создание заявки должно быть доступно');

      await page.locator('.cs-clear').click();
      await expectVisible(input, 'после сброса не вернулось поле поиска');
      await input.fill('Иванов');
      assert(await submit.isDisabled(), 'введённый вручную текст без выбора не должен проходить проверку');
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

      await expectVisible(page.getByRole('button', { name: /^Состав/ }), 'нет вкладки «Состав»');
      await expectVisible(page.getByRole('button', { name: /^Комментарии/ }), 'нет вкладки «Комментарии»');
      await expectVisible(page.getByRole('button', { name: /^История/ }), 'нет вкладки «История»');

      await page.getByRole('button', { name: /^Состав/ }).click();
      await expectVisible(page.locator('.it-row:not(.it-head)').first(), 'в составе нет позиций');

      await page.getByRole('button', { name: /^История/ }).click();
      await expectVisible(page.getByText('Хронология'), 'нет раздела «Хронология» в истории');
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
        const firstPrice = priceCard.locator('.price-toggle').first();

        if ((await firstPrice.count()) > 0) {
          await firstPrice.click();
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
    name: 'сотрудники: создание и карточка',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/staff`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByRole('heading', { name: 'Сотрудники' }), 'не открылся список сотрудников');
      await expectVisible(page.getByRole('button', { name: 'Добавить сотрудника' }), 'нет кнопки добавления сотрудника');

      await page.getByRole('button', { name: 'Добавить сотрудника' }).click();
      await expectVisible(page.getByRole('heading', { name: 'Новый сотрудник' }), 'не открылась форма создания');

      const stamp = Date.now();
      const name = `E2E Сотрудник ${stamp}`;
      const email = `staff-${stamp}@example.test`;
      await page.getByLabel(/ФИО/).fill(name);
      await page.getByLabel('E-mail *').fill(email);
      await page.getByLabel('Телефон').fill(`+7993${String(stamp).slice(-6)}`);
      await page.getByLabel('Должность').fill('Менеджер E2E');
      await page.getByLabel('Отдел').fill('E2E отдел');
      await page.getByLabel('Город').fill('E2E город');
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
      await expectVisible(
        page.getByRole('heading', { name: 'История профиля' }),
        'в профиле сотрудника нет истории учётной записи'
      );
      await expectVisible(page.getByText('Сотрудник создан'), 'в истории нет события создания');
      await expectVisible(page.getByText('E2E отдел'), 'отдел не сохранился');
      await expectVisible(page.getByText('E2E город'), 'город не сохранился');
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
      await expectVisible(historyDialog.getByText('История профиля'), 'не открылась модалка истории');
      await expectVisible(historyDialog.getByText('Разблокирован'), 'история сотрудника не загрузилась');
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
    name: 'админ: роли — группы прав',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/admin`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Роли' }).click();

      const group = page.locator('.cap-group').filter({ hasText: 'Заявки' }).first();
      await expectVisible(group, 'нет группы прав «Заявки»');

      const head = group.locator('.cap-group-head');
      assert((await head.getAttribute('aria-expanded')) === 'false', 'группа должна быть свёрнута по умолчанию');
      await head.click();
      assert((await head.getAttribute('aria-expanded')) === 'true', 'группа не раскрылась');
      await expectVisible(group.locator('input[type="checkbox"]').first(), 'в раскрытой группе нет чекбоксов');
    }
  },
  {
    name: 'админ: аудит — русские подписи',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/admin`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Журнал аудита' }).click();

      await expectVisible(page.getByText('Вход в систему').first(), 'аудит без русской подписи действия');
      await expectVisible(page.getByText('auth.login').first(), 'аудит без технического кода действия');
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
  }
];

async function main() {
  const passwords = await loadPasswords();
  const selected = scenarios.filter(
    (scenario) => ONLY.length === 0 || ONLY.some((needle) => scenario.name.includes(needle))
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
      viewport: { width: 1440, height: 900 },
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
