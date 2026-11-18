<?php

namespace Modules\Invoices\Mail;

use Brick\Money\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Invoices\Models\Invoice;
use Modules\Tenant\Models\Business;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public Business $business) {}

    public function build(): self
    {
        $locale = $this->business->locale ?: 'en';
        $format = fn (int $minor) => Money::ofMinor($minor, $this->invoice->currency_code)->formatToLocale($locale);

        return $this
            ->subject("Invoice #{$this->invoice->number} from {$this->business->name}")
            ->view('invoices::emails.invoice', [
                'invoice' => $this->invoice,
                'business' => $this->business,
                'format' => $format,
            ]);
    }
}
