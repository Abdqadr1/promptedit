<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Safe to run repeatedly: demo users are matched by email and reset to their known state.
     */
    public function run(): void
    {
        $this->seedDemoUser('Test User', 'test@example.com', isAdmin: false);
        $this->seedDemoUser('Admin User', 'admin@example.com', isAdmin: true);
    }

    /**
     * Create or reset a demo user. These match the login page's demo buttons (config/auth.php).
     */
    private function seedDemoUser(string $name, string $email, bool $isAdmin): void
    {
        User::query()->firstOrNew(['email' => $email])
            ->forceFill([
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
                'is_admin' => $isAdmin,
            ])
            ->save();
    }
}
