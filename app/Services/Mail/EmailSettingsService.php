<?php

namespace App\Services\Mail;

use App\Mail\CourtFlexMail;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * SMTP settings the Super Admin controls from the admin portal.
 *
 * Secrets are stored encrypted and are never returned by the API. The mailer
 * is configured per request, so changing settings takes effect immediately
 * without a deploy or a config cache clear.
 */
class EmailSettingsService
{
    private const DEFAULTS = [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'username' => '',
        'encryption' => 'tls',
        'from_address' => '',
        'from_name' => 'CourtFlex',
        'verification_required' => true,
    ];

    /**
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     username: string,
     *     password_set: bool,
     *     encryption: string,
     *     from_address: string,
     *     from_name: string,
     *     verification_required: bool
     * }
     */
    public function settings(): array
    {
        return [
            'enabled' => (bool) PlatformSetting::get('mail_enabled', self::DEFAULTS['enabled']),
            'host' => (string) PlatformSetting::get('mail_host', self::DEFAULTS['host']),
            'port' => (int) PlatformSetting::get('mail_port', self::DEFAULTS['port']),
            'username' => (string) PlatformSetting::get('mail_username', self::DEFAULTS['username']),
            'password_set' => $this->password() !== '',
            'encryption' => $this->encryption(),
            'from_address' => (string) PlatformSetting::get('mail_from_address', self::DEFAULTS['from_address']),
            'from_name' => (string) PlatformSetting::get('mail_from_name', self::DEFAULTS['from_name']),
            'verification_required' => (bool) PlatformSetting::get(
                'email_verification_required',
                self::DEFAULTS['verification_required'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $rows = [
            ['mail_enabled', $input['enabled'] ? '1' : '0', 'boolean'],
            ['mail_host', trim((string) $input['host']), 'string'],
            ['mail_port', (string) (int) $input['port'], 'integer'],
            ['mail_username', trim((string) $input['username']), 'string'],
            ['mail_encryption', (string) $input['encryption'], 'string'],
            ['mail_from_address', mb_strtolower(trim((string) $input['from_address'])), 'string'],
            ['mail_from_name', trim((string) $input['from_name']), 'string'],
            ['email_verification_required', ($input['verification_required'] ?? true) ? '1' : '0', 'boolean'],
        ];

        if (array_key_exists('password', $input)) {
            $password = (string) $input['password'];
            $rows[] = [
                'mail_password',
                $password === '' ? '' : Crypt::encryptString($password),
                'string',
            ];
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as [$key, $value, $type]) {
                PlatformSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'type' => $type, 'is_public' => false],
                );
            }
        });

        return $this->settings();
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();

        return $settings['enabled']
            && $settings['host'] !== ''
            && $settings['port'] > 0
            && $settings['from_address'] !== '';
    }

    /**
     * Apply the saved SMTP settings to this request's mailer.
     */
    public function configure(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Email is turned off or the SMTP settings are incomplete.');
        }

        $settings = $this->settings();
        $username = $settings['username'] !== '' ? $settings['username'] : null;

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => $settings['encryption'] === 'ssl' ? 'smtps' : null,
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => $settings['port'],
            'mail.mailers.smtp.username' => $username,
            'mail.mailers.smtp.password' => $username !== null ? $this->password() : null,
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
        ]);

        Mail::purge('smtp');
    }

    /**
     * Send a transactional email, letting the caller handle delivery errors.
     *
     * @param  list<string>  $lines
     */
    public function send(
        string $to,
        string $subject,
        array $lines,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): void {
        $this->configure();

        Mail::mailer('smtp')->to($to)->send(
            new CourtFlexMail($subject, $lines, $actionUrl, $actionLabel),
        );
    }

    /**
     * Send where a delivery failure must not break the surrounding action.
     * When SMTP is off, the message is written to the log for local work.
     *
     * @param  list<string>  $lines
     */
    public function trySend(
        string $to,
        string $subject,
        array $lines,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): bool {
        if (! $this->isConfigured()) {
            Log::info('Email not sent (SMTP not configured)', [
                'to' => $to,
                'subject' => $subject,
            ]);

            return false;
        }

        defer(function () use ($to, $subject, $lines, $actionUrl, $actionLabel): void {
            try {
                $this->send($to, $subject, $lines, $actionUrl, $actionLabel);
            } catch (Throwable $error) {
                Log::warning('Email not sent', [
                    'to' => $to,
                    'subject' => $subject,
                    'error' => $error->getMessage(),
                ]);
            }
        });

        return true;
    }

    public function frontendUrl(string $path = ''): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    private function encryption(): string
    {
        $encryption = (string) PlatformSetting::get('mail_encryption', self::DEFAULTS['encryption']);

        return in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';
    }

    private function password(): string
    {
        $stored = (string) PlatformSetting::get('mail_password', '');

        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }
}
