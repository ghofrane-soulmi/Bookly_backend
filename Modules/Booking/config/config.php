<?php

return [
    'name' => 'Booking',

    // Public customer-facing app; used to build links in customer emails.
    'front_office_url' => env('FRONT_OFFICE_URL', 'http://localhost:5174'),

    // How far ahead customers may book, and how close to the start time they
    // may still cancel online.
    'max_days_ahead' => (int) env('BOOKING_MAX_DAYS_AHEAD', 60),
    'cancel_cutoff_hours' => (int) env('BOOKING_CANCEL_CUTOFF_HOURS', 2),
];
