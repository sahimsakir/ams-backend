<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('OWNER_PASSWORD');
        if (! $password || mb_strlen($password) < 6) {
            throw new \RuntimeException('Set OWNER_PASSWORD to at least 6 characters before seeding.');
        }
        User::firstOrCreate(['email' => env('OWNER_EMAIL', 'owner@example.com')], ['name' => 'AMS Owner', 'password' => $password, 'role' => 'owner', 'active' => true]);
        foreach (['Casual leave' => 10, 'Sick leave' => 14, 'Annual leave' => 20, 'Bereavement leave' => 3] as $name => $limit) {
            DB::table('leave_types')->insertOrIgnore(['name' => $name, 'annual_limit' => $limit, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
