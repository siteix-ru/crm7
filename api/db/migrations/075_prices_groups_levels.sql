INSERT IGNORE INTO `settings` (`key`, `value`)
SELECT 'prices.levels', IF(`value` = '1', '1,5,10,50,90', '')
FROM `settings`
WHERE `key` = 'prices.enabled';

INSERT IGNORE INTO `settings` (`key`, `value`)
SELECT 'groups.levels', IF(`value` = '1', '1,5,10,50,90', '')
FROM `settings`
WHERE `key` = 'groups.enabled';
