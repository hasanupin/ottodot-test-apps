<?php

return [
    /*
     * How a seat is claimed:
     *  - hold:         booking reserves the seat for `hold_minutes`; unpaid holds expire and free the seat.
     *  - first_to_pay: booking reserves nothing; the first successful payment wins, a late payer is refunded.
     */
    'mode' => env('BOOKING_MODE', 'hold'),

    'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 1),
];
