<?php

use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertRedirect(route('sign-in'));

    $this->get(route('sign-in'))->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    Livewire::test('authentication.sign-in-form')
        ->set('emailAddress', $user->email)
        ->set('password', 'password')
        ->call('onSignIn')
        ->assertRedirect('/employee/dashboard');

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    Livewire::test('authentication.sign-in-form')
        ->set('emailAddress', $user->email)
        ->set('password', 'wrong-password')
        ->call('onSignIn')
        ->assertHasErrors(['password']);

    $this->assertGuest();
});

test('inactive users are not left authenticated after a sign-in attempt', function () {
    $user = User::factory()->create(['status' => 'inactive']);

    Livewire::test('authentication.sign-in-form')
        ->set('emailAddress', $user->email)
        ->set('password', 'password')
        ->call('onSignIn');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    Livewire::test('authentication.sign-in-form')
        ->set('emailAddress', $user->email)
        ->set('password', 'password')
        ->call('onSignIn')
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
