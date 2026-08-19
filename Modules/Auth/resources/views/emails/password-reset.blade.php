<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #22303E; margin: 0; padding: 24px; background: #f5f5f9;">
    <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px;">
        <h2 style="margin-top: 0;">Reset your password</h2>
        <p>We received a request to reset your Bookly password. Click the button below to choose a new one.</p>
        <p style="text-align: center; margin: 32px 0;">
            <a href="{{ $resetUrl }}" style="background: #696CFF; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 6px; display: inline-block;">
                Reset Password
            </a>
        </p>
        <p style="color: #6b7280; font-size: 13px;">
            This link expires in 60 minutes. If you didn't request a password reset, you can safely ignore this email.
        </p>
    </div>
</body>
</html>
