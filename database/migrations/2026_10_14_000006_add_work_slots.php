<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'teams'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->time('slot_start')->nullable();
                $t->time('slot_end')->nullable();
            });
        }
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('slot_team_id')->nullable()->constrained('teams')->nullOnDelete());
        Schema::table('attendances', function (Blueprint $t) {
            $t->time('slot_start')->default('09:00:00');
            $t->time('slot_end')->default('18:00:00');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('slot_team_id'));
        foreach (['users', 'teams', 'attendances'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['slot_start', 'slot_end']));
        }
    }
};
