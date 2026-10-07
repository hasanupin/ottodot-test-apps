<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('trial_class_id')->constrained('trial_classes')->restrictOnDelete();
            $table->string('status', 32)->collation('utf8mb4_bin');   // case-sensitive: 'confirmed' must not pass as 'CONFIRMED'
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();

            // 1 when confirmed, NULL otherwise. MySQL unique indexes allow many NULLs,
            // so this enforces "at most one CONFIRMED booking per student per class"
            // (MySQL has no partial/filtered unique indexes).
            $table->unsignedTinyInteger('confirmed_flag')
                ->nullable()
                ->storedAs("IF(status = 'CONFIRMED', 1, NULL)");

            $table->unique(['student_id', 'trial_class_id', 'confirmed_flag'], 'uniq_confirmed_booking');
            $table->index(['trial_class_id', 'status'], 'idx_bookings_class_status');
        });

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT chk_bookings_status CHECK (status IN ('PENDING_PAYMENT','CONFIRMED','PAYMENT_FAILED','FAILED_CLASS_FULL','CANCELLED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
