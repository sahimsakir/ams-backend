<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_challenges', fn (Blueprint $t) => $t->string('capture_hash', 64)->nullable());
        Schema::table('attendances', function (Blueprint $t) {
            $t->string('in_capture_hash', 64)->nullable()->index();
            $t->string('out_capture_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('face_challenges', fn (Blueprint $t) => $t->dropColumn('capture_hash'));
        Schema::table('attendances', fn (Blueprint $t) => $t->dropColumn(['in_capture_hash', 'out_capture_hash']));
    }
};
