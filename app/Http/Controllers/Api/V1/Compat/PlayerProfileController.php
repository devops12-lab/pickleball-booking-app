<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\GeoLocation;
use App\Models\PlayerProfile;
use App\Support\Compat\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The global player profile (§9.1 of the contract).
 *
 * `home` comes from the saved barangay in the geo table; `location_verified`
 * resets whenever that barangay changes (rankings only trust a checked home).
 */
class PlayerProfileController extends Controller
{
    use ApiResponse;

    private const SKILL_LEVELS = ['beginner', 'novice', 'intermediate', 'advanced', 'pro'];

    private const VISIBILITIES = ['public', 'members', 'private'];

    private const FORMATS = ['singles', 'doubles'];

    private const TIMES = ['morning', 'afternoon', 'evening'];

    private const AVATAR_STYLES = ['male', 'female'];

    public function show(Request $request): JsonResponse
    {
        $profile = PlayerProfile::query()->where('user_id', $request->user()->id)->first();

        if ($profile === null) {
            return $this->errorResponse(
                message: "You haven't set up your player profile yet.",
                code: Response::HTTP_NOT_FOUND,
            );
        }

        return $this->successResponse(data: $this->payload($profile));
    }

    public function update(Request $request): JsonResponse
    {
        $displayName = trim((string) $request->input('display_name', ''));
        $skillLevel = (string) $request->input('skill_level', '');
        $visibility = (string) $request->input('visibility', '');
        $bio = trim((string) $request->input('bio', ''));
        $avatarStyle = $request->input('avatar_style');
        $hasAvatarStyle = $request->exists('avatar_style');
        $formats = $request->input('play_formats');
        $times = $request->input('preferred_times', []);

        $errors = [];

        if (mb_strlen($displayName) < 2) {
            $errors['display_name'][] = 'Enter the name other players will see.';
        }

        if (! in_array($skillLevel, self::SKILL_LEVELS, true)) {
            $errors['skill_level'][] = 'Choose the level that fits you best.';
        }

        if (! is_array($formats) || $formats === [] || array_diff($formats, self::FORMATS) !== []) {
            $errors['play_formats'][] = 'Choose singles, doubles or both.';
        }

        if (! in_array($visibility, self::VISIBILITIES, true)) {
            $errors['visibility'][] = 'Choose who can see your profile.';
        }

        if (mb_strlen($bio) > 280) {
            $errors['bio'][] = 'Keep it under 280 characters.';
        }

        if ($avatarStyle !== null && $avatarStyle !== '' && ! in_array($avatarStyle, self::AVATAR_STYLES, true)) {
            $errors['avatar_style'][] = 'Choose a male, female or initials avatar.';
        }

        if ($errors !== []) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: $errors,
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $attributes = [
            'display_name' => $displayName,
            'skill_level' => $skillLevel,
            'bio' => $bio,
            'play_formats' => array_values($formats),
            'preferred_times' => array_values(array_intersect((array) $times, self::TIMES)),
            'visibility' => $visibility,
        ];

        if ($hasAvatarStyle) {
            $attributes['avatar_style'] = $avatarStyle === '' ? null : $avatarStyle;
        }

        $profile = PlayerProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $attributes,
        );

        return $this->successResponse(data: $this->payload($profile->refresh()));
    }

    /**
     * `PATCH /player/location` — pick the home barangay.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $profile = PlayerProfile::query()->where('user_id', $request->user()->id)->first();

        if ($profile === null) {
            return $this->errorResponse(
                message: 'Set up your profile first.',
                code: Response::HTTP_CONFLICT,
            );
        }

        $barangayId = trim((string) $request->input('barangay_id', ''));

        if ($barangayId === '' || GeoLocation::chain($barangayId) === null) {
            return $this->errorResponse(
                message: 'The given data was invalid.',
                errors: ['barangay_id' => ['Choose your barangay.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ((string) $profile->barangay_id !== $barangayId) {
            // A new home location needs checking again before it counts for local rankings (§4.4).
            $profile->location_verified = false;
        }

        $profile->barangay_id = $barangayId;
        $profile->save();

        return $this->successResponse(data: $this->payload($profile->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlayerProfile $profile): array
    {
        $profile->loadMissing('user');

        return [
            'user_id' => (string) $profile->user_id,
            'display_name' => $profile->display_name,
            'avatar_url' => $profile->user?->avatar,
            'avatar_style' => $profile->avatar_style,
            'skill_level' => $profile->skill_level,
            'bio' => $profile->bio ?? '',
            'play_formats' => $profile->play_formats ?? [],
            'preferred_times' => $profile->preferred_times ?? [],
            'visibility' => $profile->visibility,
            'home' => Contract::homeLocation($profile->barangay_id),
            'location_verified' => (bool) $profile->location_verified,
        ];
    }
}
