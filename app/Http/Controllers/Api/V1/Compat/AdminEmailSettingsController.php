<?php

namespace App\Http\Controllers\Api\V1\Compat;

use App\Http\Controllers\Api\V1\Compat\Concerns\AuthorizesPlatformAdmin;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Mail\EmailSettingsService;
use App\Support\Compat\AdminView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SMTP settings for the Super Admin. The password is write-only: the API only
 * reports whether one is saved.
 */
class AdminEmailSettingsController extends Controller
{
    use ApiResponse, AuthorizesPlatformAdmin;

    public function show(EmailSettingsService $mail): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        return $this->successResponse(data: $mail->settings());
    }

    public function update(Request $request, EmailSettingsService $mail): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $input = $this->validatedInput($request, $mail);

        if ($input['errors'] !== []) {
            return $this->validationError($input['errors']);
        }

        $before = $mail->settings();
        $after = $mail->save($input['values']);

        if ($before !== $after) {
            AdminView::writeAudit(
                action: 'settings.updated',
                summary: 'Changed email delivery settings (SMTP '.$after['host'].':'.$after['port'].').',
                type: 'settings',
                id: 0,
                name: 'Email settings',
                reason: null,
            );
        }

        return $this->successResponse(data: $after, message: 'Email settings saved.');
    }

    public function sendTest(Request $request, EmailSettingsService $mail): JsonResponse
    {
        if ($denied = $this->denyUnlessPlatformAdmin()) {
            return $denied;
        }

        $email = mb_strtolower(trim((string) $request->input('email', '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->validationError(['email' => ['Enter a valid email address.']]);
        }

        try {
            $mail->send(
                $email,
                'CourtFlex email test',
                [
                    'This is a test from CourtFlex.',
                    'Your SMTP settings are working, so verification and password reset emails can be delivered.',
                ],
                $mail->frontendUrl(),
                'Open CourtFlex',
            );
        } catch (Throwable $error) {
            report($error);

            return $this->errorResponse(
                message: 'The test email could not be sent. Check the SMTP details and try again.',
                errors: ['smtp' => ['Mail delivery failed. Check your SMTP host, port, and credentials.']],
                code: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->successResponse(data: ['sent' => true], message: 'Test email sent.');
    }

    /**
     * @return array{errors: array<string, list<string>>, values: array<string, mixed>}
     */
    private function validatedInput(Request $request, EmailSettingsService $mail): array
    {
        $errors = [];
        $enabled = $request->input('enabled');
        $host = trim((string) $request->input('host', ''));
        $portInput = $request->input('port');
        $port = null;
        $username = trim((string) $request->input('username', ''));
        $encryption = (string) $request->input('encryption', 'tls');
        $fromAddress = mb_strtolower(trim((string) $request->input('from_address', '')));
        $fromName = trim((string) $request->input('from_name', ''));
        $verificationRequired = $request->input('verification_required');
        $passwordInput = $request->input('password');

        if (! is_bool($enabled)) {
            $errors['enabled'] = ['Choose whether email delivery is on.'];
            $enabled = false;
        }

        if (is_int($portInput)) {
            $port = $portInput;
        } elseif (is_string($portInput) && ctype_digit($portInput)) {
            $port = (int) $portInput;
        }

        if ($port === null || $port < 1 || $port > 65535) {
            $errors['port'] = ['Choose a port from 1 to 65535.'];
        }

        if (! in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = ['Choose TLS, SSL or none.'];
        }

        if ($enabled && $host === '') {
            $errors['host'] = ['Enter the SMTP host.'];
        }

        if ($enabled && ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = ['Enter a valid from address.'];
        }

        if ($fromAddress !== '' && ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = ['Enter a valid from address.'];
        }

        if ($fromName === '') {
            $errors['from_name'] = ['Enter the sender name.'];
        }

        if (! is_bool($verificationRequired)) {
            $errors['verification_required'] = ['Choose whether new accounts must verify their email.'];
            $verificationRequired = true;
        }

        if ($passwordInput !== null && ! is_string($passwordInput)) {
            $errors['password'] = ['Enter a password or leave it blank.'];
        }

        if ($enabled && $username !== '' && $passwordInput === null && ! $mail->settings()['password_set']) {
            $errors['password'] = ['Enter the SMTP password for this username.'];
        }

        $values = [
            'enabled' => $enabled,
            'host' => $host,
            'port' => $port ?? 587,
            'username' => $username,
            'encryption' => $encryption,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'verification_required' => $verificationRequired,
        ];

        if ($request->exists('password')) {
            $values['password'] = $passwordInput;
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return $this->errorResponse(
            message: 'The given data was invalid.',
            errors: $errors,
            code: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
