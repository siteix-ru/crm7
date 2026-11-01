-- 052: единая терминология «номенклатура» в системных текстах.
--   Меняем только автоматически сформированные формулировки («Заявка на N позиций»,
--   «Позиции со склада», «Добавлено N позиций», «Позиции убраны из заявки»).
--   Пользовательские тексты (темы, комментарии, чат, названия номенклатуры) не затрагиваются.

UPDATE `requests`
SET `subject` = REGEXP_REPLACE(
  `subject`,
  '^Заявка на ([0-9]+) (позиция|позиции|позиций) от ',
  'Заявка на \\1 ед. номенклатуры от '
)
WHERE `subject` REGEXP '^Заявка на [0-9]+ (позиция|позиции|позиций) от ';

UPDATE `requests`
SET `body` = REPLACE(`body`, 'Позиции со склада:', 'Номенклатура со склада:')
WHERE `body` LIKE '%Позиции со склада:%';

UPDATE `request_activities`
SET `body` = REPLACE(`body`, 'Позиции со склада:', 'Номенклатура со склада:')
WHERE `body` LIKE '%Позиции со склада:%';

UPDATE `request_activities`
SET `body` = REGEXP_REPLACE(
  `body`,
  'Заявка на ([0-9]+) (позиция|позиции|позиций) от',
  'Заявка на \\1 ед. номенклатуры от'
)
WHERE `body` REGEXP 'Заявка на [0-9]+ (позиция|позиции|позиций) от';

UPDATE `request_activities`
SET `body` = REGEXP_REPLACE(
  `body`,
  '(Добавлено|Осталось) ([0-9]+) (позиция|позиции|позиций)',
  '\\1 \\2 ед. номенклатуры'
)
WHERE `body` REGEXP '(Добавлено|Осталось) [0-9]+ (позиция|позиции|позиций)';

UPDATE `request_activities`
SET `body` = REPLACE(`body`, 'Осталось: позиций нет', 'Осталось: номенклатуры нет')
WHERE `body` LIKE '%Осталось: позиций нет%';

UPDATE `request_activities`
SET `body` = REPLACE(`body`, 'Позиции убраны из заявки', 'Номенклатура убрана из заявки')
WHERE `body` LIKE '%Позиции убраны из заявки%';

UPDATE `notifications`
SET `body` = REGEXP_REPLACE(
  `body`,
  'Заявка на ([0-9]+) (позиция|позиции|позиций) от',
  'Заявка на \\1 ед. номенклатуры от'
)
WHERE `body` REGEXP 'Заявка на [0-9]+ (позиция|позиции|позиций) от';

UPDATE `notifications`
SET `body` = REGEXP_REPLACE(
  `body`,
  '(Добавлено|Осталось|В заявке) ([0-9]+) (позиция|позиции|позиций)',
  '\\1 \\2 ед. номенклатуры'
)
WHERE `body` REGEXP '(Добавлено|Осталось|В заявке) [0-9]+ (позиция|позиции|позиций)';

UPDATE `notifications`
SET `body` = REPLACE(`body`, 'Позиции убраны из заявки', 'Номенклатура убрана из заявки')
WHERE `body` LIKE '%Позиции убраны из заявки%';

UPDATE `notifications`
SET `body` = REPLACE(`body`, 'Позиции со склада:', 'Номенклатура со склада:')
WHERE `body` LIKE '%Позиции со склада:%';

UPDATE `notifications`
SET `title` = REGEXP_REPLACE(
  `title`,
  'Заявка на ([0-9]+) (позиция|позиции|позиций) от',
  'Заявка на \\1 ед. номенклатуры от'
)
WHERE `title` REGEXP 'Заявка на [0-9]+ (позиция|позиции|позиций) от';

UPDATE `email_queue`
SET `body` = REGEXP_REPLACE(
  `body`,
  'Заявка на ([0-9]+) (позиция|позиции|позиций) от',
  'Заявка на \\1 ед. номенклатуры от'
)
WHERE `body` REGEXP 'Заявка на [0-9]+ (позиция|позиции|позиций) от';

UPDATE `email_queue`
SET `body` = REGEXP_REPLACE(
  `body`,
  '(Добавлено|Осталось|В заявке) ([0-9]+) (позиция|позиции|позиций)',
  '\\1 \\2 ед. номенклатуры'
)
WHERE `body` REGEXP '(Добавлено|Осталось|В заявке) [0-9]+ (позиция|позиции|позиций)';

UPDATE `email_queue`
SET `body` = REPLACE(`body`, 'Позиции со склада:', 'Номенклатура со склада:')
WHERE `body` LIKE '%Позиции со склада:%';

UPDATE `email_queue`
SET `body` = REPLACE(`body`, 'Позиции убраны из заявки', 'Номенклатура убрана из заявки')
WHERE `body` LIKE '%Позиции убраны из заявки%';

UPDATE `email_queue`
SET `subject` = REGEXP_REPLACE(
  `subject`,
  'Заявка на ([0-9]+) (позиция|позиции|позиций) от',
  'Заявка на \\1 ед. номенклатуры от'
)
WHERE `subject` REGEXP 'Заявка на [0-9]+ (позиция|позиции|позиций) от';

UPDATE `item_groups`
SET `TITLE` = REGEXP_REPLACE(`TITLE`, '^Группа позиций', 'Группа номенклатуры')
WHERE `TITLE` REGEXP '^Группа позиций( [0-9]+)?$';

UPDATE `capabilities`
SET `title` = 'Склады: номенклатура (добавление и правка)'
WHERE `code` = 'stocks.edit'
  AND `title` = 'Склады: позиции (добавление и правка)';
