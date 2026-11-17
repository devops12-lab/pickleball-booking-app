<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        $settings = PlatformSetting::where('is_public', true)
            ->get()
            ->mapWithKeys(fn (PlatformSetting $s): array => [$s->key => PlatformSetting::get($s->key)])
            ->toArray();

        return $this->successResponse(
            data: $settings,
            message: 'Application configuration retrieved.',
        );
    }
}
