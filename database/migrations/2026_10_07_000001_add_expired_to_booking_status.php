<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Hold mode (BOOKING_MODE=hold): an unpaid hold that runs out becomes EXPIRED. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings DROP CHECK chk_bookings_status');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT chk_bookings_status CHECK (status IN ('PENDING_PAYMENT','CONFIRMED','PAYMENT_FAILED','FAILED_CLASS_FULL','CANCELLED','EXPIRED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CHECK chk_bookings_status');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT chk_bookings_status CHECK (status IN ('PENDING_PAYMENT','CONFIRMED','PAYMENT_FAILED','FAILED_CLASS_FULL','CANCELLED'))");
    }
};
