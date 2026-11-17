<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManageSocialsRequest;
use App\Http\Requests\Admin\UpdateAppSettingsRequest;
use App\Http\Traits\ApiResponse;
use App\Models\PlatformSetting;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminSettingsController extends Controller
{
    use ApiResponse;

    /**
     * Keys managed by this controller.
     *
     * @var list<string>
     */
    private const APP_KEYS = [
        'app_name', 'app_description', 'app_logo', 'app_favicon',
        'official_website', 'business_address',
        'ad_inquiry_email', 'ad_contact_phone', 'ad_instructions',
        'default_timezone', 'default_currency',
        'maintenance_mode', 'public_booking_enabled', 'registration_enabled',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'app_name' => 'string',
        'app_description' => 'string',
        'app_logo' => 'string',
        'app_favicon' => 'string',
        'official_website' => 'string',
        'business_address' => 'string',
        'ad_inquiry_email' => 'string',
        'ad_contact_phone' => 'string',
        'ad_instructions' => 'string',
        'default_timezone' => 'string',
        'default_currency' => 'string',
        'maintenance_mode' => 'boolean',
        'public_booking_enabled' => 'boolean',
        'registration_enabled' => 'boolean',
    ];

    /**
     * @var array<string, bool>
     */
    private const PUBLIC_MAP = [
        'app_name' => true,
        'app_description' => true,
        'app_logo' => true,
        'app_favicon' => true,
        'official_website' => true,
        'business_address' => true,
        'ad_inquiry_email' => true,
        'ad_contact_phone' => true,
        'ad_instructions' => true,
        'default_timezone' => false,
        'default_currency' => false,
        'maintenance_mode' => true,
        'public_booking_enabled' => true,
        'registration_enabled' => true,
    ];

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly FileUploadService $fileUpload,
    ) {}

    public function getAppSettings(): JsonResponse
    {
        $settings = PlatformSetting::whereIn('key', self::APP_KEYS)
            ->get()
            ->mapWithKeys(fn (PlatformSetting $s): array => [$s->key => PlatformSetting::get($s->key)])
            ->toArray();

        return $this->successResponse(
            data: $settings,
            message: 'Application settings retrieved.',
        );
    }

    public function updateAppSettings(UpdateAppSettingsRequest $request): JsonResponse
    {
        $changes = [];

        DB::transaction(function () use ($request, &$changes): void {
            foreach (self::APP_KEYS as $key) {
                if ($key === 'app_logo' || $key === 'app_favicon') {
                    if ($request->hasFile($key)) {
                        $path = $this->fileUpload->upload($request->file($key), 'settings');
                        $this->upsert($key, $path);
                        $changes[] = "{$key} updated";
                    }

                    continue;
                }

                if (! $request->has($key)) {
                    continue;
                }

                $value = $request->input($key);
                $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
                $this->upsert($key, $value === null ? null : $stringValue);
                $changes[] = "{$key} updated";
            }

            if ($changes !== []) {
                $this->auditLog->log(
                    action: 'app_settings.updated',
                    newValues: ['changes' => $changes],
                );
            }
        });

        if ($changes === []) {
            return $this->successResponse(message: 'No changes detected.');
        }

        return $this->successResponse(message: 'Application settings updated.');
    }

    public function getSocials(): JsonResponse
    {
        $socials = PlatformSetting::get('platform_socials', []);

        return $this->successResponse(
            data: is_array($socials) ? $socials : [],
            message: 'Platform social links retrieved.',
        );
    }

    public function addSocial(ManageSocialsRequest $request): JsonResponse
    {
        $socials = PlatformSetting::get('platform_socials', []);
        $socials = is_array($socials) ? $socials : [];

        if (count($socials) >= 10) {
            return $this->errorResponse(
                'Maximum of 10 social links allowed.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $socials[] = [
            'platform' => $request->string('platform')->toString(),
            'url' => $request->string('url')->toString(),
            'display_name' => $request->input('display_name'),
        ];

        $this->saveSocials($socials);

        $this->auditLog->log(
            action: 'app_settings.social_added',
            newValues: ['platform' => $request->input('platform'), 'url' => $request->input('url')],
        );

        return $this->successResponse(
            data: $socials,
            message: 'Social link added.',
            code: Response::HTTP_CREATED,
        );
    }

    public function updateSocial(ManageSocialsRequest $request, int $index): JsonResponse
    {
        $socials = PlatformSetting::get('platform_socials', []);
        $socials = is_array($socials) ? $socials : [];

        if (! isset($socials[$index])) {
            return $this->errorResponse('Social link not found.', code: Response::HTTP_NOT_FOUND);
        }

        $socials[$index] = [
            'platform' => $request->string('platform')->toString(),
            'url' => $request->string('url')->toString(),
            'display_name' => $request->input('display_name'),
        ];

        $this->saveSocials($socials);

        $this->auditLog->log(
            action: 'app_settings.social_updated',
            newValues: ['index' => $index, 'platform' => $request->input('platform')],
        );

        return $this->successResponse(
            data: $socials,
            message: 'Social link updated.',
        );
    }

    public function removeSocial(int $index): JsonResponse
    {
        $socials = PlatformSetting::get('platform_socials', []);
        $socials = is_array($socials) ? $socials : [];

        if (! isset($socials[$index])) {
            return $this->errorResponse('Social link not found.', code: Response::HTTP_NOT_FOUND);
        }

        $removed = $socials[$index];
        array_splice($socials, $index, 1);

        $this->saveSocials($socials);

        $this->auditLog->log(
            action: 'app_settings.social_removed',
            newValues: ['removed' => $removed],
        );

        return $this->successResponse(
            data: $socials,
            message: 'Social link removed.',
        );
    }

    private function upsert(string $key, ?string $value): void
    {
        PlatformSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => self::TYPE_MAP[$key] ?? 'string',
                'is_public' => self::PUBLIC_MAP[$key] ?? false,
            ],
        );
    }

    /**
     * @param  list<array{platform: string, url: string, display_name: string|null}>  $socials
     */
    private function saveSocials(array $socials): void
    {
        PlatformSetting::updateOrCreate(
            ['key' => 'platform_socials'],
            [
                'value' => json_encode(array_values($socials)),
                'type' => 'json',
                'is_public' => true,
            ],
        );
    }
}
