<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesClub;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Tenant;
use App\Support\Compat\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/PATCH /api/v1/clubs/{club} — the SPA's Club contract, backed by a tenant
 * plus its venue profile.
 */
class ClubController extends Controller
{
    use ApiResponse, AuthorizesClub;

    public function show(Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $club->load(['venueProfile', 'subscription']);

        return $this->successResponse(data: Contract::club($club));
    }

    public function update(Request $request, Tenant $club): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($club)) {
            return $denied;
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'tagline' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'rules' => ['sometimes', 'string'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'contact_phone' => ['sometimes', 'string', 'max:50'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'address' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'province' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $profile = $club->venueProfile()->firstOrCreate(['tenant_id' => $club->id]);
        // Eloquent does not reload column defaults after an insert, so a brand-new
        // profile still reports null for the NOT NULL text columns. Refresh first.
        $profile->refresh();

        $profile->fill([
            'tagline' => $data['tagline'] ?? $profile->tagline ?? '',
            'description' => $data['description'] ?? $profile->description ?? '',
            'rules' => $data['rules'] ?? $profile->rules ?? '',
            'cover_url' => array_key_exists('cover_url', $data) ? $data['cover_url'] : $profile->cover_url,
            'contact_phone' => $data['contact_phone'] ?? $profile->contact_phone ?? '',
            'contact_email' => $data['contact_email'] ?? $profile->contact_email ?? '',
            'address' => $data['address'] ?? $profile->address ?? '',
            'city' => $data['city'] ?? $profile->city ?? '',
            'province' => $data['province'] ?? $profile->province ?? '',
        ]);

        if (array_key_exists('settings', $data)) {
            $profile->settings = array_replace_recursive(Contract::settings($profile), $data['settings']);
        }

        $profile->save();

        $clubFields = [];
        if (isset($data['name'])) {
            $clubFields['name'] = $data['name'];
        }
        if (array_key_exists('logo_url', $data)) {
            $clubFields['logo'] = $data['logo_url'];
        }
        if ($clubFields !== []) {
            $club->update($clubFields);
        }

        $club->load(['venueProfile', 'subscription']);

        return $this->successResponse(data: Contract::club($club), message: 'Club updated.');
    }
}
