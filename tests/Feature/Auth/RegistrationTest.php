<?php

use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertRedirect(route('sign-up'));

    $this->get(route('sign-up'))->assertOk();
});

test('new users can register', function () {
    Livewire::test('authentication.sign-up-form')
        ->set('firstName', 'John')
        ->set('lastName', 'Doe')
        ->set('emailAddress', 'test@example.com')
        ->set('password', 'password')
        ->call('onCreateAccount')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('users', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@example.com',
    ]);

    $this->assertAuthenticated();
});
