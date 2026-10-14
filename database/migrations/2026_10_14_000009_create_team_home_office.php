<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_home_office', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['start_date', 'end_date']);
        });
        DB::table('permissions')->insert(['name' => 'home_office.manage', 'slug' => 'home_office.manage', 'display_name' => 'Manage team home office', 'guard_name' => 'api', 'system' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('team_home_office');
        DB::table('permissions')->where('name', 'home_office.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
