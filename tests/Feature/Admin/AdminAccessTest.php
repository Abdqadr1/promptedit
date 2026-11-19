<?php

use App\Models\User;

test('registration cannot grant admin access', function () {
    $this->post(route('register.store'), [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'is_admin' => true,
    ]);

    expect(User::query()->where('email', 'sneaky@example.com')->sole()->is_admin)->toBeFalse();
});

test('a profile update cannot grant admin access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Sneaky',
        'email' => $user->email,
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('the admin:grant command grants and revokes admin access', function () {
    $user = User::factory()->create();

    $this->artisan('admin:grant', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeTrue();

    $this->artisan('admin:grant', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeFalse();
});
