-- 055: типы номенклатуры как редактируемый справочник.
-- Раньше список типов был статичным (App\Support\ItemTypes), а включённость хранилась
-- в настройке stocks.item_types. Теперь типы хранятся в таблице item_types.

CREATE TABLE IF NOT EXISTS `item_types` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `CODE` varchar(50) NOT NULL,
  `TITLE` varchar(255) NOT NULL,
  `DESCRIPTION` text DEFAULT NULL,
  `ENABLED` tinyint(1) NOT NULL DEFAULT 1,
  `SORT` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_item_types_code` (`CODE`),
  KEY `idx_item_types_sort` (`SORT`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `item_types` (`CODE`, `TITLE`, `DESCRIPTION`, `ENABLED`, `SORT`) VALUES
  ('product', 'Товар', 'Материальная номенклатура с остатками и ценами', 1, 10),
  ('service', 'Услуга', 'Работа или услуга; учитывается по складам (где оказана или заказана)', 1, 20),
  ('set', 'Набор', 'Состав из другой номенклатуры (товаров и услуг)', 1, 30)
ON DUPLICATE KEY UPDATE
  `TITLE` = VALUES(`TITLE`),
  `DESCRIPTION` = VALUES(`DESCRIPTION`);

-- Сохраняем ранее выключенные типы, если настройка stocks.item_types уже задана.
UPDATE `item_types`
SET `ENABLED` = 0
WHERE EXISTS (
    SELECT 1 FROM `settings`
    WHERE `key` = 'stocks.item_types' AND `value` IS NOT NULL AND `value` <> ''
  )
  AND FIND_IN_SET(`CODE`, (
    SELECT `value` FROM `settings` WHERE `key` = 'stocks.item_types' LIMIT 1
  )) = 0;
