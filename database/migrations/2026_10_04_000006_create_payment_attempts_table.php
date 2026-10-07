<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('status', 16)->collation('utf8mb4_bin');   // case-sensitive, see bookings.status
            $table->unsignedInteger('amount_cents');
            $table->string('idempotency_key', 100)->unique();
            $table->string('failure_reason', 64)->nullable();   // 'card_declined' | 'class_full' | 'duplicate_booking' | 'booking_closed'
            $table->timestamps();
        });

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT chk_payment_attempts_status CHECK (status IN ('PENDING','SUCCEEDED','FAILED','REFUNDED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
