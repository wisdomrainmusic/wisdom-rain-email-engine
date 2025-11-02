<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription expired</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body style="margin:0; padding:40px; background-color:#f8fafc; font-family:'Helvetica Neue', Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px; margin:0 auto; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 12px 32px rgba(15, 23, 42, 0.08);">
        <tr>
            <td style="padding:36px;">
                <p style="margin:0 0 12px; font-size:14px; letter-spacing:0.08em; text-transform:uppercase; color:#64748b;">Subscription ended</p>
                <h1 style="margin:0 0 16px; font-size:28px; line-height:1.2; color:#0f172a;">We're grateful for your time with us, {{recipient_name}}</h1>
                <p style="margin:0 0 24px; font-size:16px; line-height:1.7; color:#1e293b;">Your {{plan_name}}{{plan_interval}} subscription wrapped up{{expired_date}}. When you're ready to continue exploring Wisdom Rain practices, we'd love to welcome you back.</p>
                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 28px;">
                    <tr>
                        <td>
                            <a href="{{renew_url}}" style="display:inline-block; padding:14px 28px; background-color:#0f766e; color:#ffffff !important; text-decoration:none; border-radius:999px; font-weight:600;">Renew my membership</a>
                        </td>
                    </tr>
                </table>
                <p style="margin:0; font-size:15px; line-height:1.6; color:#475569;">Need help choosing a plan or have questions? Just reply to this email and our team will be happy to assist.</p>
            </td>
        </tr>
    </table>
    <p style="text-align:center; font-size:12px; color:#94a3b8; margin:24px 0 0;">Sent with gratitude by {{site_name}}. <a href="{{unsubscribe_url}}" style="color:#64748b;">Unsubscribe</a></p>
</body>
</html>
