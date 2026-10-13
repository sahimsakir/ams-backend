<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_challenges', fn (Blueprint $t) => $t->foreignId('subject_id')->nullable()->constrained('users')->cascadeOnDelete());
    }

    public function down(): void
    {
        Schema::table('face_challenges', fn (Blueprint $t) => $t->dropConstrainedForeignId('subject_id'));
    }
};
