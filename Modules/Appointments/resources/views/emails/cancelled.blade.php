<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #22303E; margin: 0; padding: 24px; background: #f5f5f9;">
    <div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px;">
        <h2 style="margin-top: 0;">{{ $appointment->business->name }}</h2>
        <p>Hi {{ $appointment->client->name }},</p>
        <p>Your appointment has been cancelled:</p>
        <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Service</td>
                <td style="padding: 8px 0; text-align: right;"><strong>{{ $appointment->service->name }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">With</td>
                <td style="padding: 8px 0; text-align: right;"><strong>{{ $appointment->staff->name }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Was scheduled for</td>
                <td style="padding: 8px 0; text-align: right;"><strong>{{ $appointment->starts_at->copy()->setTimezone($appointment->business->timezone)->format('l, F j, Y \a\t g:i A') }}</strong></td>
            </tr>
        </table>
        <p style="color: #6b7280; font-size: 13px; margin-top: 32px;">
            If you'd like to book a new time, please contact {{ $appointment->business->name }} directly.
        </p>
    </div>
</body>
</html>
