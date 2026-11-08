<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DatabaseSettings;
use App\Groups\ItemGroupService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mail\MailService;
use App\Prices\PriceService;
use App\Repositories\SearchLogRepository;
use App\Repositories\SettingsRepository;

final class AdminController extends ApiController
{
    public function __construct(
        private readonly MailService $mail = new MailService(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly PriceService $prices = new PriceService(),
        private readonly ItemGroupService $groups = new ItemGroupService(),
        private readonly SearchLogRepository $searches = new SearchLogRepository()
    ) {
        parent::__construct();
    }

    public function systemSettings(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok($this->systemSettingsPayload());
    }

    public function saveSystemSettings(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        $appTitle = mb_substr(trim((string) $request->input('app_title', '')), 0, 60);
        $appDescription = mb_substr(trim((string) $request->input('app_description', '')), 0, 300);
        $organization = mb_substr(trim((string) $request->input('organization', '')), 0, 255);
        $legalAddress = mb_substr(trim((string) $request->input('legal_address', '')), 0, 500);
        $requisites = mb_substr(trim((string) $request->input('requisites', '')), 0, 500);
        $contactEmail = mb_substr(trim((string) $request->input('contact_email', '')), 0, 255);
        $contactPhone = mb_substr(trim((string) $request->input('contact_phone', '')), 0, 100);
        $responsible = mb_substr(trim((string) $request->input('responsible', '')), 0, 500);

        $this->settings->many([
            'branding.title' => $appTitle,
            'branding.description' => $appDescription,
            'branding.organization' => $organization,
            'branding.legal_address' => $legalAddress,
            'branding.requisites' => $requisites,
            'branding.contact_email' => $contactEmail,
            'branding.contact_phone' => $contactPhone,
            'branding.responsible' => $responsible,
        ]);

        return Response::ok($this->systemSettingsPayload());
    }

    private function systemSettingsPayload(): array
    {
        $photoSizes = $this->settings->photoSizes();
        $legal = $this->settings->legalRequisites();

        return [
            'sla_reaction_hours' => (int) ($this->settings->get('sla.reaction_hours') ?? 2),
            'sla_resolution_hours' => (int) ($this->settings->get('sla.resolution_hours') ?? 24),
            'spf_checklist' => $this->settings->get('mail.spf_checklist') === '1',
            'sales_enabled' => $this->settings->salesEnabled(),
            'email_export_enabled' => $this->settings->emailExportEnabled(),
            'mail_configured' => $this->settings->mailConfigured(),
            'stock_reserve_enabled' => $this->settings->stockReserveEnabled(),
            'stock_allow_zero' => $this->settings->allowZeroStock(),
            'prices_enabled' => $this->settings->pricesEnabled(),
            'groups_enabled' => $this->settings->groupsEnabled(),
            'requests_enabled' => $this->settings->requestsEnabled(),
            'mail_enabled' => $this->settings->mailEnabled(),
            'cart_enabled' => $this->settings->cartEnabled(),
            'substitutions_enabled' => $this->settings->substitutionsEnabled(),
            'manager_assign_enabled' => $this->settings->managerAssignEnabled(),
            'warehouses_enabled' => $this->settings->warehousesEnabled(),
            'reports_enabled' => $this->settings->reportsEnabled(),
            'single_name' => $this->settings->singleWarehouseName(),
            'app_title' => $this->settings->appTitle(),
            'app_description' => $this->settings->appDescription(),
            'organization' => $legal['organization'],
            'legal_address' => $legal['legal_address'],
            'requisites' => $legal['requisites'],
            'contact_email' => $legal['contact_email'],
            'contact_phone' => $legal['contact_phone'],
            'responsible' => $legal['responsible'],
            'photo_ratio' => $this->settings->photoRatio(),
            'photo_fit' => $this->settings->photoFit(),
            'photo_size_preview' => $photoSizes['preview'],
            'photo_size_card' => $photoSizes['card'],
            'photo_size_max' => $photoSizes['max'],
            'spf_steps' => [
                'SPF: добавьте в DNS TXT-запись домена с серверами отправки (v=spf1 …)',
                'DKIM: включите подпись в панели почтового провайдера и опубликуйте публичный ключ',
                'DMARC: добавьте TXT-запись _dmarc с политикой (p=none для старта)',
                'Проверьте отправку тестового письма на внешний ящик и заголовки SPF/DKIM',
            ],
        ];
    }

    public function priceTypes(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(['items' => $this->prices->types()]);
    }

    public function createPriceType(Request $request): Response
    {
        $user = $this->requireSettings($request);

        $type = $this->prices->createType(
            (string) $request->input('title', ''),
            (string) $request->input('description', ''),
            (bool) $request->input('enabled', true)
        );
        $this->audit($request, $user, 'prices.type.create', 'price_type', $type['id'] ?? null, [
            'title' => $type['title'] ?? '',
            'enabled' => $type['enabled'] ?? true,
        ]);

        return Response::ok(['type' => $type], 201);
    }

    public function updatePriceType(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $enabled = $request->input('enabled');
        $type = $this->prices->updateType(
            $id,
            (string) $request->input('title', ''),
            (string) $request->input('description', ''),
            $enabled === null ? null : (bool) $enabled
        );
        $this->audit($request, $user, 'prices.type.update', 'price_type', $id, [
            'title' => $type['title'] ?? '',
            'enabled' => $type['enabled'] ?? true,
        ]);

        return Response::ok(['type' => $type]);
    }

    public function deletePriceType(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $this->prices->deleteType($id);
        $this->audit($request, $user, 'prices.type.delete', 'price_type', $id);

        return Response::ok(['id' => $id]);
    }

    public function itemGroups(Request $request): Response
    {
        $this->requireSettings($request);

        return Response::ok(['items' => $this->groups->list()]);
    }

    public function itemGroupItems(Request $request, array $params): Response
    {
        $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);

        return Response::ok(['items' => $this->groups->items($id)]);
    }

    public function searchLog(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('audit.view', $capabilities, true) && !in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return Response::ok([
            'stats' => $this->searches->stats(),
            'top' => $this->searches->top((int) $request->queryParam('limit', '50')),
            'recent' => $this->searches->recent((int) $request->queryParam('limit', '100')),
        ]);
    }

    public function createItemGroup(Request $request): Response
    {
        $user = $this->requireSettings($request);

        $group = $this->groups->create(
            (string) $request->input('title', ''),
            (string) $request->input('description', ''),
            (bool) $request->input('enabled', true)
        );
        $this->audit($request, $user, 'groups.create', 'item_group', $group['id'] ?? null, [
            'title' => $group['title'] ?? '',
        ]);

        return Response::ok(['group' => $group], 201);
    }

    public function updateItemGroup(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $group = $this->groups->update(
            $id,
            (string) $request->input('title', ''),
            (string) $request->input('description', ''),
            (bool) $request->input('enabled', true)
        );
        $this->audit($request, $user, 'groups.update', 'item_group', $id, [
            'title' => $group['title'] ?? '',
        ]);

        return Response::ok(['group' => $group]);
    }

    public function deleteItemGroup(Request $request, array $params): Response
    {
        $user = $this->requireSettings($request);

        $id = (int) ($params['id'] ?? 0);
        $this->groups->delete($id);
        $this->audit($request, $user, 'groups.delete', 'item_group', $id);

        return Response::ok(['id' => $id]);
    }

    public function emailSettings(Request $request): Response
    {
        $this->requireMail($request);

        return Response::ok($this->mail->publicSettings());
    }

    public function saveEmailSettings(Request $request): Response
    {
        $this->requireMail($request);

        return Response::ok($this->mail->saveSettings($request->bodyAll()));
    }

    public function testEmail(Request $request): Response
    {
        $this->requireMail($request);

        $to = trim((string) $request->input('to', ''));

        if ($to !== '') {
            return Response::ok($this->mail->sendTest($to));
        }

        return Response::ok($this->mail->testConnection());
    }

    public function databaseSettings(Request $request): Response
    {
        $this->requireTechAdmin($request);

        return Response::ok(DatabaseSettings::publicPayload());
    }

    public function testDatabaseSettings(Request $request): Response
    {
        $this->requireTechAdmin($request);

        return Response::ok(DatabaseSettings::test($request->bodyAll()));
    }

    public function saveDatabaseSettings(Request $request): Response
    {
        $user = $this->requireTechAdmin($request);

        $payload = DatabaseSettings::save($request->bodyAll(), (string) ($user['LOGIN'] ?? ''));

        $this->audit($request, $user, 'settings.database', 'settings', null, [
            'host' => $payload['host'],
            'database' => $payload['database'],
            'user' => $payload['user'],
        ]);

        return Response::ok($payload);
    }

    public function templates(Request $request): Response
    {
        $this->requireTechAdmin($request);

        return Response::ok($this->mail->templates());
    }

    public function saveTemplate(Request $request, array $params): Response
    {
        $this->requireTechAdmin($request);

        return Response::ok($this->mail->saveTemplate(
            (string) ($params['code'] ?? ''),
            (string) $request->input('subject', ''),
            (string) $request->input('body', '')
        ));
    }

    public function queue(Request $request): Response
    {
        $this->requireTechAdmin($request);

        return Response::ok($this->mail->queueRecent((int) $request->queryParam('limit', '30')));
    }

    public function clearQueue(Request $request): Response
    {
        [$user] = $this->requireTechAdmin($request);
        $cleared = $this->mail->clearQueue();

        $this->audit($request, $user, 'admin.queue.clear', 'email_queue', null, [
            'cleared' => $cleared,
        ]);

        return Response::ok(['cleared' => $cleared]);
    }

    private function requireSettings(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('settings.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return $user;
    }

    private function requireMail(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('mail.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для настройки почты');
        }

        return [$user, $capabilities];
    }

    private function requireTechAdmin(Request $request): array
    {
        [$user, $capabilities] = $this->context($request);

        if (!in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(
                403,
                'forbidden',
                'Доступно только техническому администратору'
            );
        }

        return [$user, $capabilities];
    }
}
