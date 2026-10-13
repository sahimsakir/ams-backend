<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('employee');
            $t->boolean('active')->default(true);
            $t->string('designation')->nullable();
            $t->string('phone')->nullable();
        });
        Schema::create('access_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
        });
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('team_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('team_role');
            $t->unique(['team_id', 'user_id']);
        });
        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->unsignedInteger('annual_limit');
            $t->timestamps();
        });
        Schema::create('calendar_days', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->string('name');
            $t->boolean('is_working')->default(false);
        });
        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('kind');
            $t->foreignId('leave_type_id')->nullable()->constrained();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->unsignedInteger('days')->default(0);
            $t->unsignedInteger('minutes')->nullable();
            $t->text('reason');
            $t->string('contact')->nullable();
            $t->string('covering')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
            $t->index(['user_id', 'kind', 'status']);
        });
        Schema::create('approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('status')->default('pending');
            $t->text('comment')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unique(['application_id', 'reviewer_id']);
        });
        Schema::create('face_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->text('embedding');
            $t->foreignId('enrolled_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->date('work_date');
            $t->timestampTz('check_in');
            $t->timestampTz('check_out')->nullable();
            $t->string('mode');
            $t->string('in_photo');
            $t->string('out_photo')->nullable();
            $t->float('in_distance');
            $t->float('out_distance')->nullable();
            $t->unique(['user_id', 'work_date']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->string('subject');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'attendances', 'face_profiles', 'approvals', 'applications', 'calendar_days', 'leave_types', 'team_user', 'teams', 'access_tokens'] as $t) {
            Schema::dropIfExists($t);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active', 'designation', 'phone']));
    }
};
