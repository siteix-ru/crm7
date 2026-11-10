-- WCAG AA: тёмные варианты цветов статусов (контраст текста на белом >= 4.5:1,
-- в т.ч. на подложке 10% того же цвета в чипах).
-- Былые: in_progress #d97706 (3.2), waiting #0891b2 (3.7), resolved #059669 (3.8),
-- closed #6b7280 (4.45 на подложке).

UPDATE `request_statuses` SET `color` = '#b45309' WHERE `code` = 'in_progress';
UPDATE `request_statuses` SET `color` = '#0e7490' WHERE `code` = 'waiting';
UPDATE `request_statuses` SET `color` = '#047857' WHERE `code` = 'resolved';
UPDATE `request_statuses` SET `color` = '#4b5563' WHERE `code` = 'closed';
