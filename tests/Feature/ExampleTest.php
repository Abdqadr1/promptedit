<?php

use App\Models\User;

test('guests visiting the homepage are sent to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('signed-in users visiting the homepage are sent to their generations', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('generations.index'));
});
