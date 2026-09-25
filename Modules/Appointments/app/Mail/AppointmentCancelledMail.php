<?php

namespace Modules\Appointments\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Appointments\Models\Appointment;

class AppointmentCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function build(): self
    {
        return $this
            ->subject('Your appointment has been cancelled')
            ->view('appointments::emails.cancelled', [
                'appointment' => $this->appointment,
            ]);
    }
}
