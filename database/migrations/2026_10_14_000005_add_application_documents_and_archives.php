<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->softDeletes());
        Schema::table('leave_types', fn (Blueprint $t) => $t->softDeletes());
        Schema::table('applications', function (Blueprint $t) {
            $t->string('document')->nullable();
            $t->string('document_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['document', 'document_name']));
        Schema::table('users', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
