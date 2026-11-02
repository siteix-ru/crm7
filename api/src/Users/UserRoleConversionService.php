<?php

declare(strict_types=1);

namespace App\Users;

use App\Core\Database;
use App\Http\HttpException;
use App\Repositories\TokenRepository;
use App\Repositories\UserHistoryRepository;
use App\Repositories\UserRepository;
use Throwable;

final class UserRoleConversionService
{
    private const LEVELS = [5, 10, 50, 90];

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserHistoryRepository $history = new UserHistoryRepository(),
        private readonly TokenRepository $tokens = new TokenRepository()
    ) {
    }

    public function preview(array $actor, array $capabilities, int $userId): array
    {
        $this->requireRolesManage($capabilities);

        $user = $this->users->findById($userId);

        if ($user === null) {
            throw new HttpException(404, 'not_found', 'Пользователь не найден');
        }

        return $this->buildPreview($actor, $user);
    }

    public function convert(array $actor, array $capabilities, int $userId, array $input): array
    {
        $this->requireRolesManage($capabilities);

        $targetLevel = (int) ($input['target_level'] ?? 0);

        if (!in_array($targetLevel, self::LEVELS, true)) {
            throw new HttpException(422, 'validation_error', 'Неизвестная роль');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE ID = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $user = $stmt->fetch() ?: null;

            if ($user === null) {
                throw new HttpException(404, 'not_found', 'Пользователь не найден');
            }

            if ($userId === (int) ($actor['ID'] ?? 0)) {
                throw new HttpException(422, 'validation_error', 'Нельзя менять роль самому себе');
            }

            $currentLevel = (int) $user['LEVEL'];
            $toStaff = $currentLevel === 5 && $targetLevel >= 10;
            $toClient = $currentLevel >= 10 && $targetLevel === 5;

            if (!$toStaff && !$toClient) {
                throw new HttpException(
                    422,
                    'validation_error',
                    'Для смены роли внутри персонала используйте обычное изменение роли'
                );
            }

            $preview = $this->buildPreview($actor, $user, $targetLevel);

            if (!$preview['can']) {
                throw new HttpException(
                    422,
                    'role_conversion_blocked',
                    implode(' ', array_column($preview['blockers'], 'message'))
                );
            }

            $result = $toStaff
                ? $this->toStaff($userId, $user, $targetLevel, (int) $actor['ID'])
                : $this->toClient($userId, $user, (int) $actor['ID']);

            $this->tokens->revokeAllForUser($userId);

            $event = $toStaff ? 'converted_to_staff' : 'converted_to_client';
            $this->history->add(
                $userId,
                $event,
                (int) $actor['ID'],
                $toStaff
                    ? 'Клиент переведён в сотрудники, роль: ' . $targetLevel
                    : 'Сотрудник переведён в клиенты'
            );

            $pdo->commit();

            return [
                'id' => $userId,
                'from_level' => $currentLevel,
                'to_level' => $targetLevel,
                'direction' => $toStaff ? 'to_staff' : 'to_client',
                'tokens_revoked' => true,
                'counts' => $result,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function toStaff(int $userId, array $user, int $targetLevel, int $actorId): array
    {
        $pdo = Database::pdo();
        $sid = (string) ($user['SID'] ?? '');
        $counts = [];

        $stmt = $pdo->prepare(
            "UPDATE client_transfers
             SET status = 'cancelled', decided_by = ?, decided_at = NOW()
             WHERE client_id = ? AND status IN ('pending', 'scheduled', 'active')"
        );
        $stmt->execute([$actorId, $userId]);
        $counts['transfers_cancelled'] = $stmt->rowCount();

        $stmt = $pdo->prepare('DELETE FROM client_managers WHERE client_id = ?');
        $stmt->execute([$userId]);
        $counts['manager_links_removed'] = $stmt->rowCount();

        $counts['stock_acl_removed'] = $this->removeStockAccess($sid);
        $counts['price_types_removed'] = $this->removePriceTypes($userId);
        $counts['drafts_removed'] = $this->removeDrafts($userId);

        $pdo->prepare(
            "UPDATE users
             SET LEVEL = ?, ACTIVE = 'Y', STATUS = 'Y', reg_state = 'active',
                 WAREHOUSE_ID = NULL, price_type_id = NULL
             WHERE ID = ?"
        )->execute([$targetLevel, $userId]);

        return $counts;
    }

    private function toClient(int $userId, array $user, int $actorId): array
    {
        $pdo = Database::pdo();
        $sid = (string) ($user['SID'] ?? '');
        $counts = [];

        $stmt = $pdo->prepare(
            "UPDATE client_transfers
             SET status = 'cancelled', decided_by = ?, decided_at = NOW()
             WHERE status IN ('pending', 'scheduled')
               AND (from_manager_id = ? OR to_manager_id = ? OR created_by = ?)"
        );
        $stmt->execute([$actorId, $userId, $userId, $userId]);
        $counts['transfers_cancelled'] = $stmt->rowCount();

        $counts['stock_acl_removed'] = $this->removeStockAccess($sid);
        $counts['price_types_removed'] = $this->removePriceTypes($userId);
        $counts['drafts_removed'] = $this->removeDrafts($userId);

        $pdo->prepare(
            "UPDATE users
             SET LEVEL = 5, reg_state = 'active', WAREHOUSE_ID = NULL, price_type_id = NULL
             WHERE ID = ?"
        )->execute([$userId]);

        $pdo->prepare('DELETE FROM user_filters WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM saved_filters WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);

        return $counts;
    }

    private function removeStockAccess(string $sid): int
    {
        if ($sid === '') {
            return 0;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM user_level_stock WHERE USER_SID = ?');
        $stmt->execute([$sid]);
        $removed = $stmt->rowCount();

        $pdo->prepare('UPDATE stocks SET RESPONSIBLE_SID = NULL WHERE RESPONSIBLE_SID = ?')->execute([$sid]);

        return $removed;
    }

    private function removePriceTypes(int $userId): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM user_price_types WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    private function removeDrafts(int $userId): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM request_drafts WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    private function buildPreview(array $actor, array $user, ?int $targetLevel = null): array
    {
        $userId = (int) $user['ID'];
        $currentLevel = (int) $user['LEVEL'];
        $toStaff = $currentLevel === 5;
        $targetLevel ??= $toStaff ? 10 : 5;
        $blockers = [];
        $counts = [
            'clients_managed' => 0,
            'open_requests' => 0,
            'active_substitutions' => 0,
            'pending_transfers' => 0,
            'stock_acl' => 0,
            'price_types' => 0,
            'drafts' => 0,
        ];

        $isSelf = $userId === (int) ($actor['ID'] ?? 0);

        if ($isSelf) {
            $blockers[] = [
                'code' => 'self',
                'message' => 'Нельзя менять роль самому себе.',
            ];
        }

        if ($toStaff && !$this->isActive($user)) {
            $blockers[] = [
                'code' => 'client_not_active',
                'message' => 'Клиент должен быть активен. Сначала активируйте учётную запись.',
            ];
        }

        if (!$toStaff) {
            $active = $this->isActive($user);

            if ($currentLevel >= 90 && $active && $this->users->countActiveSuperAdmins() <= 1) {
                $blockers[] = [
                    'code' => 'last_superadmin',
                    'message' => 'Это последний технический администратор — его нельзя перевести в клиента.',
                ];
            } elseif ($currentLevel >= 50 && $active && $this->users->countActiveAdmins() <= 1) {
                $blockers[] = [
                    'code' => 'last_admin',
                    'message' => 'Это последний администратор — его нельзя перевести в клиента.',
                ];
            }

            $counts['clients_managed'] = $this->count(
                'SELECT COUNT(*) FROM client_managers WHERE manager_id = ?',
                [$userId]
            );
            $counts['open_requests'] = $this->count(
                'SELECT COUNT(*) FROM requests r
                 INNER JOIN request_statuses s ON s.code = r.status_id
                 WHERE r.manager_id = ? AND s.is_final = 0',
                [$userId]
            );
            $counts['active_substitutions'] = $this->count(
                'SELECT COUNT(*) FROM substitutions
                 WHERE is_active = 1 AND date_from <= CURDATE() AND date_to >= CURDATE()
                   AND (manager_id = ? OR substitute_id = ?)',
                [$userId, $userId]
            );
            $counts['pending_transfers'] = $this->count(
                "SELECT COUNT(*) FROM client_transfers
                 WHERE status IN ('pending', 'scheduled', 'active')
                   AND (from_manager_id = ? OR to_manager_id = ? OR created_by = ?)",
                [$userId, $userId, $userId]
            );

            if ($counts['clients_managed'] > 0) {
                $blockers[] = [
                    'code' => 'has_clients',
                    'message' => 'Сначала передайте клиентов другому менеджеру: '
                        . $counts['clients_managed'] . '.',
                ];
            }

            if ($counts['open_requests'] > 0) {
                $blockers[] = [
                    'code' => 'has_open_requests',
                    'message' => 'Сначала передайте открытые заявки: '
                        . $counts['open_requests'] . '.',
                ];
            }

            if ($counts['active_substitutions'] > 0) {
                $blockers[] = [
                    'code' => 'has_substitutions',
                    'message' => 'Сначала завершите активные замещения: '
                        . $counts['active_substitutions'] . '.',
                ];
            }

            if ($counts['pending_transfers'] > 0) {
                $blockers[] = [
                    'code' => 'has_pending_transfers',
                    'message' => 'Сначала завершите или отмените передачи клиентов: '
                        . $counts['pending_transfers'] . '.',
                ];
            }
        }

        $sid = (string) ($user['SID'] ?? '');
        $counts['stock_acl'] = $sid === ''
            ? 0
            : $this->count('SELECT COUNT(*) FROM user_level_stock WHERE USER_SID = ?', [$sid]);
        $counts['price_types'] = $this->count(
            'SELECT COUNT(*) FROM user_price_types WHERE user_id = ?',
            [$userId]
        );
        $counts['drafts'] = $this->count(
            'SELECT COUNT(*) FROM request_drafts WHERE user_id = ?',
            [$userId]
        );

        return [
            'id' => $userId,
            'level' => $currentLevel,
            'target_levels' => $toStaff ? [10, 50, 90] : [5],
            'suggested_level' => $targetLevel,
            'direction' => $toStaff ? 'to_staff' : 'to_client',
            'can' => $blockers === [],
            'blockers' => $blockers,
            'counts' => $counts,
        ];
    }

    private function count(string $sql, array $params): int
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function isActive(array $user): bool
    {
        return ($user['ACTIVE'] ?? 'N') === 'Y'
            && ($user['STATUS'] ?? 'N') === 'Y'
            && (($user['reg_state'] ?? 'active') === 'active');
    }

    private function requireRolesManage(array $capabilities): void
    {
        if (!in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для смены роли');
        }
    }
}
