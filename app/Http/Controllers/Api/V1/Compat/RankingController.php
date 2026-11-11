<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\Compat\CompetitionView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RankingController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'doubles');
        $scope = (string) $request->query('scope', 'country');

        if (! in_array($type, ['singles', 'doubles'], true)) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['type' => ['Choose singles or doubles.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (! in_array($scope, CompetitionView::SCOPES, true)) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['scope' => ['Choose barangay, city, province or country.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($scope === 'global') {
            return $this->errorResponse(
                message: "Global rankings aren't open yet. They start once CourtFlex covers more countries.",
                code: Response::HTTP_CONFLICT,
            );
        }

        $viewer = $request->user() ?? Auth::guard('web')->user();
        $viewerId = $viewer === null ? null : (int) $viewer->id;
        $myAreas = $viewerId === null ? [] : CompetitionView::homeAreas($viewerId);
        $homeArea = collect($myAreas)->firstWhere('scope', $scope);
        $areaId = trim((string) $request->query('area', ''));
        $areaId = $areaId !== '' ? $areaId : ($homeArea['id'] ?? ($scope === 'country' ? 'ph' : null));

        if ($areaId === null) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['area' => ['Choose an area.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (CompetitionView::area($scope, $areaId) === null) {
            return $this->errorResponse(message: "That area doesn't exist.", code: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: CompetitionView::leaderboard($type, $scope, $areaId, $viewerId));
    }

    public function areas(Request $request): JsonResponse
    {
        $scope = (string) $request->query('scope', '');

        if (! in_array($scope, CompetitionView::SCOPES, true) || $scope === 'global') {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['scope' => ['Choose barangay, city, province or country.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->successResponse(data: CompetitionView::areas($scope));
    }
}
