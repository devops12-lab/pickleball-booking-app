<?php

namespace App\Http\Controllers\Api\V1\Owners;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CourtFront;
use App\Models\StorefrontSocialLink;
use App\Services\TenantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontSocialLinkController extends Controller
{
    use ApiResponse;

    private const ALLOWED_PLATFORMS = [
        'facebook',
        'instagram',
        'tiktok',
        'youtube',
        'linkedin',
        'twitter',
        'website',
    ];

    private const MAX_ACTIVE_LINKS = 5;

    public function __construct(
        private TenantContextService $tenantContext
    ) {}

    public function index(): JsonResponse
    {
        $courtFront = $this->getCourtFront();

        if (! $courtFront) {
            return $this->successResponse(data: []);
        }

        return $this->successResponse(
            data: $courtFront->socialLinks()->orderBy('sort_order')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_PLATFORMS)],
            'url' => ['required', 'url', 'max:500'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $courtFront = $this->getOrCreateCourtFront();

        $activeCount = $courtFront->socialLinks()->where('is_active', true)->count();
        $isActive = $request->boolean('is_active', true);

        if ($isActive && $activeCount >= self::MAX_ACTIVE_LINKS) {
            return $this->errorResponse(
                'Maximum of '.self::MAX_ACTIVE_LINKS.' active social links allowed.',
                code: Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $maxOrder = $courtFront->socialLinks()->max('sort_order') ?? -1;

        $link = StorefrontSocialLink::create([
            'court_front_id' => $courtFront->id,
            'platform' => $request->input('platform'),
            'display_name' => $request->input('display_name'),
            'url' => $request->input('url'),
            'sort_order' => $maxOrder + 1,
            'is_active' => $isActive,
        ]);

        return $this->successResponse(
            data: $link,
            message: 'Social link added.',
            code: Response::HTTP_CREATED
        );
    }

    public function update(Request $request, StorefrontSocialLink $link): JsonResponse
    {
        $courtFront = $this->getCourtFront();

        if (! $courtFront || $link->court_front_id !== $courtFront->id) {
            return $this->errorResponse('Unauthorized.', code: Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'platform' => ['sometimes', 'string', 'in:'.implode(',', self::ALLOWED_PLATFORMS)],
            'url' => ['sometimes', 'url', 'max:500'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->has('is_active') && $request->boolean('is_active') && ! $link->is_active) {
            $activeCount = $courtFront->socialLinks()->where('is_active', true)->count();

            if ($activeCount >= self::MAX_ACTIVE_LINKS) {
                return $this->errorResponse(
                    'Maximum of '.self::MAX_ACTIVE_LINKS.' active social links allowed.',
                    code: Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $link->update($request->only(['platform', 'url', 'display_name', 'is_active']));

        return $this->successResponse(
            data: $link->fresh(),
            message: 'Social link updated.'
        );
    }

    public function destroy(StorefrontSocialLink $link): JsonResponse
    {
        $courtFront = $this->getCourtFront();

        if (! $courtFront || $link->court_front_id !== $courtFront->id) {
            return $this->errorResponse('Unauthorized.', code: Response::HTTP_FORBIDDEN);
        }

        $link->delete();

        return $this->successResponse(message: 'Social link removed.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:storefront_social_links,id'],
        ]);

        $courtFront = $this->getCourtFront();

        if (! $courtFront) {
            return $this->errorResponse('No storefront found.', code: Response::HTTP_NOT_FOUND);
        }

        foreach ($request->input('order') as $position => $linkId) {
            StorefrontSocialLink::where('id', $linkId)
                ->where('court_front_id', $courtFront->id)
                ->update(['sort_order' => $position]);
        }

        return $this->successResponse(
            data: $courtFront->socialLinks()->orderBy('sort_order')->get(),
            message: 'Links reordered.'
        );
    }

    private function getCourtFront(): ?CourtFront
    {
        return CourtFront::where('tenant_id', $this->tenantContext->getTenantId())->first();
    }

    private function getOrCreateCourtFront(): CourtFront
    {
        return CourtFront::firstOrCreate(
            ['tenant_id' => $this->tenantContext->getTenantId()],
            ['is_open' => true]
        );
    }
}
