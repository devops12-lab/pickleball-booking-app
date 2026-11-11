@php
    $codeLine = null;
    $code = null;

    foreach ($lines as $line) {
        if (preg_match('/\bcode is (\d{6})\b/i', $line, $match) === 1) {
            $code = $match[1];
            $codeLine = $line;
            break;
        }
    }

    $bodyLines = $codeLine === null
        ? $lines
        : array_values(array_filter($lines, fn (string $line): bool => $line !== $codeLine));

    $logo = $message->embed(public_path('brand/courtflex-logo-email.png'));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $subjectLine }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .cf-shell { padding: 24px 12px 20px !important; }
            .cf-card { padding: 24px !important; border-radius: 14px !important; }
            .cf-title { font-size: 24px !important; line-height: 1.22 !important; }
            .cf-code { font-size: 30px !important; letter-spacing: 7px !important; }
            .cf-button { display: block !important; width: 100% !important; box-sizing: border-box !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#001321;color:#e9eef4;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        {{ $subjectLine }} — CourtFlex
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#001321" style="width:100%;background:#001321;">
        <tr>
            <td align="center" class="cf-shell" style="padding:40px 16px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:580px;">
                    <tr>
                        <td style="padding:0 8px 22px;">
                            <img src="{{ $logo }}" width="154" height="46" alt="CourtFlex" style="display:block;width:154px;height:46px;max-width:100%;border:0;outline:none;text-decoration:none;">
                        </td>
                    </tr>
                    <tr>
                        <td class="cf-card" bgcolor="#07233a" style="background:#07233a;border:1px solid #1d4466;border-radius:20px;padding:32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:0 0 14px;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:11px;line-height:1.4;letter-spacing:0.16em;text-transform:uppercase;color:#00d29a;font-weight:700;">
                                        Night session · CourtFlex
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 18px;">
                                        <h1 class="cf-title" style="margin:0;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:28px;line-height:1.2;font-weight:800;letter-spacing:-0.025em;color:#e9eef4;">
                                            {{ $subjectLine }}
                                        </h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 22px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="42" style="height:2px;line-height:2px;font-size:0;background:#fcbf0e;">&nbsp;</td>
                                                <td style="height:2px;line-height:2px;font-size:0;background:#1d4466;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if ($code)
                                    <tr>
                                        <td style="padding:0 0 12px;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:16px;line-height:1.65;color:#97a8bf;">
                                            Enter this code to continue:
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:0 0 22px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#001321" style="width:100%;background:#001321;border:1px solid #1d4466;border-radius:12px;">
                                                <tr>
                                                    <td align="center" class="cf-code" style="padding:18px 12px;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:34px;line-height:1;letter-spacing:9px;font-weight:800;color:#fcbf0e;font-variant-numeric:tabular-nums;">
                                                        {{ $code }}
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @foreach ($bodyLines as $line)
                                    <tr>
                                        <td style="padding:0 0 12px;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:16px;line-height:1.65;color:#97a8bf;">
                                            {{ $line }}
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($actionUrl)
                                    <tr>
                                        <td style="padding:12px 0 0;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td bgcolor="#fcbf0e" style="background:#fcbf0e;border-radius:999px;">
                                                        <a href="{{ $actionUrl }}" class="cf-button" style="display:inline-block;padding:14px 24px;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:15px;line-height:1.2;font-weight:700;color:#001321;text-decoration:none;border-radius:999px;">
                                                            {{ $actionLabel ?: 'Open CourtFlex' }}
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 8px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-top:1px solid #1d4466;padding:18px 0 0;">
                                        <p style="margin:0 0 6px;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;font-weight:700;color:#e9eef4;">
                                            CourtFlex · Night session
                                        </p>
                                        <p style="margin:0;font-family:'Inter','Segoe UI',Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#97a8bf;">
                                            This is a transactional message about your CourtFlex account. If you did not request it, you can ignore this email.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
