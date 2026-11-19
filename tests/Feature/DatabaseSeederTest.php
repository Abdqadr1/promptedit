<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('seeding twice keeps a single copy of each demo user', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@example.com')->count())->toBe(1);
});

test('seeding resets the demo users to their known credentials and roles', function () {
    User::factory()->admin()->create(['email' => 'test@example.com', 'password' => 'changed']);

    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->sole();
    expect($user->is_admin)->toBeFalse()
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and(User::query()->where('email', 'admin@example.com')->sole()->is_admin)->toBeTrue();
});
