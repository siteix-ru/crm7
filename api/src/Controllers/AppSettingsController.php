<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Demo\DemoAccounts;
use App\Groups\ItemGroupService;
use App\Http\Request;
use App\Http\Response;
use App\Prices\PriceService;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

final class AppSettingsController extends ApiController
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly ItemGroupService $groups = new ItemGroupService(),
        private readonly UserRepository $users = new UserRepository()
    ) {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        $this->context($request);

        return Response::ok([
            'sales_enabled' => $this->settings->salesEnabled(),
            'email_export_enabled' => $this->settings->emailExportEnabled(),
            'mail_configured' => $this->settings->mailConfigured(),
            'stock_reserve_enabled' => $this->settings->stockReserveEnabled(),
            'stock_allow_zero' => $this->settings->allowZeroStock(),
            'prices_enabled' => $this->settings->pricesEnabled(),
            'groups_enabled' => $this->settings->groupsEnabled(),
            'requests_enabled' => $this->settings->requestsEnabled(),
            'clients_enabled' => $this->settings->clientsEnabled(),
            'chat_enabled' => $this->settings->chatEnabled(),
            'notifications_enabled' => $this->settings->notificationsEnabled(),
            'staff_enabled' => $this->settings->staffEnabled(),
            'registration_enabled' => $this->settings->registrationEnabled(),
            'stock_edit_enabled' => $this->settings->stockEditEnabled(),
            'mail_enabled' => $this->settings->mailEnabled(),
            'cart_enabled' => $this->settings->cartEnabled(),
            'substitutions_enabled' => $this->settings->substitutionsEnabled(),
            'manager_assign_enabled' => $this->settings->managerAssignEnabled(),
            'warehouses_enabled' => $this->settings->warehousesEnabled(),
            'reports_enabled' => $this->settings->reportsEnabled(),
            'single_name' => $this->settings->singleWarehouseName(),
            'app_title' => $this->settings->appTitle(),
            'app_description' => $this->settings->appDescription(),
            'photo_ratio' => $this->settings->photoRatio(),
            'photo_fit' => $this->settings->photoFit(),
            'demo_mode' => Config::demoMode(),
        ]);
    }

    public function publicSettings(Request $request): Response
    {
        $demo = Config::demoMode();

        return Response::ok([
            'demo_mode' => $demo,
            'app_title' => $this->settings->appTitle(),
            'mail_available' => $this->settings->mailConfigured(),
            'password_reset_available' => $this->settings->mailConfigured()
                && in_array('mail.recovery', $this->users->capabilitiesForLevel(1), true),
            'registration_enabled' => $this->settings->registrationEnabled()
                && in_array('registration.use', $this->users->capabilitiesForLevel(1), true),
            'demo_accounts' => $demo
                ? array_map(static fn (array $account): array => [
                    'login' => $account['login'],
                    'role' => $account['role'],
                    'password' => DemoAccounts::PASSWORD,
                ], DemoAccounts::all())
                : [],
        ]);
    }

    public function priceTypes(Request $request): Response
    {
        $this->context($request);

        $available = array_values(array_filter(
            $this->prices->types(),
            static fn (array $type): bool => (bool) ($type['enabled'] ?? true)
        ));

        $items = array_map(static fn (array $type): array => [
            'id' => (int) $type['id'],
            'code' => (string) $type['code'],
            'title' => (string) $type['title'],
            'description' => (string) ($type['description'] ?? ''),
            'sort' => (int) $type['sort'],
        ], $available);

        return Response::ok(['items' => $items]);
    }

    public function itemGroups(Request $request): Response
    {
        $this->context($request);

        $items = array_map(static fn (array $group): array => [
            'id' => (int) $group['id'],
            'title' => (string) $group['title'],
            'description' => (string) ($group['description'] ?? ''),
            'sort' => (int) $group['sort'],
        ], $this->groups->list(true));

        return Response::ok([
            'items' => $items,
            'has_ungrouped' => $this->groups->hasUngrouped(),
        ]);
    }
}
