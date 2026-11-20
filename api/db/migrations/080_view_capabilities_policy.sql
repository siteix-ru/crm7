-- Этап 3.4.1: все сотрудники просматривают всех клиентов и все заявки.
-- Права view.own/view.all остаются в level_capabilities для совместимости:
-- они не ограничивают просмотр списков и карточек, но view.all по-прежнему
-- используется для чатов, замещений и флагов управления.
UPDATE `capabilities` SET `title` = 'Заявки: замещения и управление чужими' WHERE `code` = 'requests.view.all';
UPDATE `capabilities` SET `title` = 'Заявки: просмотр (устаревшее, не ограничивает доступ)' WHERE `code` = 'requests.view.own';
UPDATE `capabilities` SET `title` = 'Клиенты: доступ ко всем чатам' WHERE `code` = 'clients.view.all';
UPDATE `capabilities` SET `title` = 'Клиенты: просмотр (устаревшее, не ограничивает доступ)' WHERE `code` = 'clients.view.own';
