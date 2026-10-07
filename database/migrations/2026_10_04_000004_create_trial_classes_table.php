<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->string('subject', 32);          // 'math' | 'science'
            $table->string('title');
            $table->dateTime('starts_at');
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->unsignedInteger('price_cents');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE trial_classes ADD CONSTRAINT chk_trial_classes_capacity CHECK (capacity BETWEEN 1 AND 4)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_classes');
    }
};
