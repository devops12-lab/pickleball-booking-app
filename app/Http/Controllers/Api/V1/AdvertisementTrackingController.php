<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Advertisement;
use App\Services\AdvertisementDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdvertisementTrackingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AdvertisementDistributionService $distribution
    ) {}

    public function impression(Request $request, Advertisement $advertisement): JsonResponse
    {
        $request->validate([
            'placement' => ['required', 'string', 'in:dashboard,discovery,booking,global'],
        ]);

        $isNew = $this->distribution->recordImpression(
            $advertisement,
            $request->user(),
            $request->input('placement'),
            $request
        );

        return $this->successResponse(
            message: $isNew ? 'Impression recorded.' : 'Impression already counted.',
            code: $isNew ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    public function click(Request $request, Advertisement $advertisement): JsonResponse
    {
        $isNew = $this->distribution->recordClick(
            $advertisement,
            $request->user(),
            $request
        );

        return $this->successResponse(
            data: ['destination_url' => $advertisement->destination_url],
            message: $isNew ? 'Click recorded.' : 'Click already counted.',
            code: $isNew ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }
}
