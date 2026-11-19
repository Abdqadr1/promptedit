<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('login screen offers the configured demo accounts', function () {
    config(['auth.demo_accounts' => [
        ['label' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password'],
    ]]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('demoAccounts.0.email', 'admin@example.com'));
});

test('login screen offers no demo accounts when they are disabled', function () {
    config(['auth.demo_accounts' => []]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('demoAccounts', []));
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
