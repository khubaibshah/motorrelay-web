<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your MotorRelay password</title>
</head>
<body style="margin:0;background:#eef4f6;color:#071024;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        Reset your MotorRelay password securely.
    </div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;">
                    <tr>
                        <td style="padding:0 8px 18px;color:#087c5a;font-size:20px;font-weight:800;letter-spacing:5px;">
                            MOTORRELAY
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #dce5e8;border-radius:24px;padding:44px 42px;box-shadow:0 12px 30px rgba(7,16,36,.08);">
                            <div style="display:inline-block;background:#d9f8eb;border:1px solid #8de7c2;border-radius:999px;padding:7px 13px;color:#087c5a;font-size:12px;font-weight:800;letter-spacing:2px;">
                                ACCOUNT SECURITY
                            </div>
                            <h1 style="margin:24px 0 14px;color:#071024;font-size:32px;line-height:1.15;letter-spacing:-.8px;">
                                Reset your password
                            </h1>
                            <p style="margin:0;color:#52627a;font-size:16px;line-height:1.65;">
                                We received a request to reset the password for your MotorRelay account. Use the button below to choose a new password.
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:30px 0;">
                                <tr>
                                    <td style="border-radius:12px;background:#071024;">
                                        <a href="{{ $resetUrl }}" style="display:inline-block;padding:15px 24px;border:1px solid #071024;border-radius:12px;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;">
                                            Reset password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 10px;color:#52627a;font-size:14px;line-height:1.6;">
                                This secure link expires in {{ $expiresIn }}.
                            </p>
                            <p style="margin:0;color:#52627a;font-size:14px;line-height:1.6;">
                                If you did not request a password reset, you can safely ignore this email. Your password will remain unchanged.
                            </p>
                            <hr style="margin:30px 0 22px;border:0;border-top:1px solid #e2e9ec;">
                            <p style="margin:0;color:#8390a2;font-size:12px;line-height:1.6;">
                                If the button does not work, copy and paste this link into your browser:<br>
                                <a href="{{ $resetUrl }}" style="color:#087c5a;word-break:break-all;">{{ $resetUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:22px 8px 0;color:#8390a2;font-size:12px;line-height:1.6;">
                            MotorRelay · Move vehicles with less chasing.<br>
                            This is an automated message; please do not reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
