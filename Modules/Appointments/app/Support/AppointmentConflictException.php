<?php

namespace Modules\Appointments\Support;

/**
 * Thrown inside the staff-lock transaction to unwind to a 409 response when
 * the conflict check fails after acquiring the lock, without letting the
 * controller's generic catch(\Exception) turn it into a 500.
 */
class AppointmentConflictException extends \RuntimeException {}
