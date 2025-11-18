<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@example.com';
        $exists = DB::table('users')->where('email', $email)->exists();
        if ($exists) return;

        DB::table('users')->insert([
            'name'              => 'Administrator',
            'email'             => $email,
            'email_verified_at' => now(),
            'password'          => Hash::make('Avada!@#$%mkz1'),
            'remember_token'    => Str::random(60),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
