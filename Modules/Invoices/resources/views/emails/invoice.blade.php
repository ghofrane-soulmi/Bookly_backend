<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #22303E; margin: 0; padding: 24px; background: #f5f5f9;">
    <div style="max-width: 560px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px;">
        <h2 style="margin-top: 0;">{{ $business->name }}</h2>
        <p>Hi {{ $invoice->client->name }},</p>
        <p>Here is invoice <strong>#{{ $invoice->number }}</strong>, issued {{ $invoice->issued_on->format('F j, Y') }} and due {{ $invoice->due_on->format('F j, Y') }}.</p>

        <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 14px;">
            <tr style="color: #6b7280; text-align: left;">
                <th style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">Item</th>
                <th style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; text-align: right;">Qty</th>
                <th style="padding: 8px 0; border-bottom: 1px solid #e5e7eb; text-align: right;">Amount</th>
            </tr>
            @foreach ($invoice->items as $item)
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">{{ $item->description }}</td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6; text-align: right;">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6; text-align: right;">{{ $format($item->line_total) }}</td>
                </tr>
            @endforeach
            <tr><td colspan="2" style="padding: 6px 0; color: #6b7280;">Subtotal</td><td style="padding: 6px 0; text-align: right;">{{ $format($invoice->subtotal) }}</td></tr>
            @if ($invoice->discount_total)
                <tr><td colspan="2" style="padding: 6px 0; color: #6b7280;">Discount ({{ $invoice->discount_percent + 0 }}%)</td><td style="padding: 6px 0; text-align: right;">−{{ $format($invoice->discount_total) }}</td></tr>
            @endif
            @if ($invoice->tax_total)
                <tr><td colspan="2" style="padding: 6px 0; color: #6b7280;">Tax ({{ $invoice->tax_percent + 0 }}%)</td><td style="padding: 6px 0; text-align: right;">{{ $format($invoice->tax_total) }}</td></tr>
            @endif
            <tr><td colspan="2" style="padding: 8px 0;"><strong>Total</strong></td><td style="padding: 8px 0; text-align: right;"><strong>{{ $format($invoice->total) }}</strong></td></tr>
            @if ($invoice->amount_paid)
                <tr><td colspan="2" style="padding: 6px 0; color: #6b7280;">Paid</td><td style="padding: 6px 0; text-align: right;">{{ $format($invoice->amount_paid) }}</td></tr>
                <tr><td colspan="2" style="padding: 6px 0;"><strong>Balance due</strong></td><td style="padding: 6px 0; text-align: right;"><strong>{{ $format($invoice->balance()) }}</strong></td></tr>
            @endif
        </table>

        @if ($invoice->notes)
            <p style="color: #6b7280;">{{ $invoice->notes }}</p>
        @endif

        <p style="color: #6b7280; font-size: 13px; margin-top: 32px;">
            Questions about this invoice? Contact {{ $business->name }}@if ($business->email) at {{ $business->email }}@endif.
        </p>
    </div>
</body>
</html>
