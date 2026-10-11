<?php

use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('users with two factor enabled are sent to the challenge from the sign-in form', function () {
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
});
