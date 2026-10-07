<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->collation('utf8mb4_bin')->after('password');   // 'parent' | 'teacher' | 'admin'
            $table->foreignId('parent_id')->nullable()->unique()->after('role')->constrained('parents')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->unique()->after('parent_id')->constrained('teachers')->restrictOnDelete();
        });

        // Role and links must agree: a parent user points at one parents row, a teacher user at one
        // teachers row, an admin at neither. Also rejects unknown or wrong-case roles ('PARENT').
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (
            (role = 'parent' AND parent_id IS NOT NULL AND teacher_id IS NULL) OR
            (role = 'teacher' AND teacher_id IS NOT NULL AND parent_id IS NULL) OR
            (role = 'admin' AND parent_id IS NULL AND teacher_id IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK chk_users_role');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropColumn('role');
        });
    }
};
