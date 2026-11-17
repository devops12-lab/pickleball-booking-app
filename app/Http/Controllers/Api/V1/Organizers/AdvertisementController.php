<?php

namespace App\Http\Controllers\Api\V1\Organizers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertisementResource;
use App\Http\Traits\ApiResponse;
use App\Services\AdvertisementDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertisementController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AdvertisementDistributionService $distribution
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'placement' => ['required', 'string', 'in:dashboard,discovery,booking,global'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $ads = $this->distribution->getAdsForUser(
            $request->user(),
            $request->input('placement'),
            $request->integer('limit', 5)
        );

        return $this->successResponse(data: AdvertisementResource::collection($ads));
    }
}
