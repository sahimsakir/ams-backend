<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_profiles', function (Blueprint $t) {
            $t->unsignedSmallInteger('verification_version')->default(1);
        });
        Schema::create('face_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 12);
            $t->text('steps');
            $t->unsignedSmallInteger('step')->default(0);
            $t->string('nonce', 64);
            $t->string('profile_hash', 64)->nullable();
            $t->text('state');
            $t->string('photo')->nullable();
            $t->timestampTz('expires_at')->index();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('consumed_at')->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_challenges');
        Schema::table('face_profiles', fn (Blueprint $t) => $t->dropColumn('verification_version'));
    }
};
