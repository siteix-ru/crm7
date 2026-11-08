UPDATE `settings`
SET `value` = 'Основной склад'
WHERE `key` = 'stocks.single_name'
  AND (`value` = '' OR `value` = '???????? ?????');

UPDATE `stocks`
SET `NAME` = 'Основной склад',
    `NAME_1C` = 'Основной склад'
WHERE `SID` = 'single-warehouse'
  AND (`NAME` = '' OR `NAME` = '???????? ?????');
