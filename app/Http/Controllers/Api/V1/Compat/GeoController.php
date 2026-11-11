<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GeoLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /geo/:level` — the location pickers (blueprint §5.2 B).
 *
 * Levels chain downwards: regions under the country, provinces under a region,
 * cities under a province, barangays under a city.
 */
class GeoController extends Controller
{
    use ApiResponse;

    /** URL level => the level its parent must be. */
    private const PARENTS = [
        'regions' => 'country',
        'provinces' => 'region',
        'cities' => 'province',
        'barangays' => 'city',
    ];

    /** URL level (plural) => the level stored on the row (singular). */
    private const LEVELS = [
        'regions' => 'region',
        'provinces' => 'province',
        'cities' => 'city',
        'barangays' => 'barangay',
    ];

    public function index(Request $request, string $level): JsonResponse
    {
        if (! isset(self::PARENTS[$level])) {
            return $this->errorResponse(message: 'Unknown level.', code: Response::HTTP_NOT_FOUND);
        }

        $parentId = $level === 'regions'
            ? 'ph'
            : trim((string) $request->query('parent_id', ''));

        $parent = $parentId === '' ? null : GeoLocation::query()->find($parentId);

        if ($parent === null || $parent->level !== self::PARENTS[$level]) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['parent_id' => ['Choose the level above first.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->successResponse(data: GeoLocation::options($parentId, self::LEVELS[$level]));
    }
}
