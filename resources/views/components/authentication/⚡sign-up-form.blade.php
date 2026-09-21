<?php

use Livewire\Component;
use App\Models\User;

new class extends Component
{
    public $firstName;
    public $lastName;
    public $emailAddress;
    public $password;

    public function onCreateAccount() {
        $this->validate([
            'firstName' => 'required|max:30',
            'lastName' => 'required|max:30',
            'emailAddress' => 'required|max:100',
            'password' => 'required'
        ]);

        User::create([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->emailAddress,
            'password' => $this->password,
        ]);

        if(Auth::attempt(['email' => $this->emailAddress, 'password' => $this->password])) {
            $this->redirect('/dashboard');
        } else {
            $this->addError('password', 'An unknown error occured');
        }
    }
};
?>

<div class="flex flex-col w-full h-full py-8 px-12 gap-4">
    <div class="flex flex-row items-center justify-center gap-4 w-full">
        <img class="size-18" src="{{ asset('images/logo_eriao.png') }}" alt="">
        <p class="text-xl font-bold leading-tight">External Relations and <br>International Affairs Office</p>
    </div>

    <div class="flex flex-col mt-8">
        <p class="text-center text-lg">Welcome Back!</p>
        <p class="text-center text-lg font-bold">Create an Account</p>
    </div>

    <div class="flex flex-col mt-10 gap-4 w-full">
        <div class="grid grid-cols-2 gap-4">
            <flux:input wire:model='firstName' label="First Name" />
            <flux:input wire:model='lastName' label="Last Name" />
        </div>
        <flux:input wire:model='emailAddress' label="Email Address" />
        <flux:input wire:model='password' label="Password" type="password" viewable />

        <div class="flex flex-col gap-4">
            <flux:button wire:click='onCreateAccount' class="bg-primary hover:bg-primary mt-8" variant="primary">Create Account</flux:button>
            <div class="flex flex-row gap-4 items-center">
                <flux:separator />
                <p>or</p>
                <flux:separator />
            </div>
            <a class="flex flex-col w-full" href="/sign-in"><flux:button variant="outline">Back to Login</flux:button></a>
        </div>
    </div>

    <flux:spacer />

    <div class="flex flex-row items-center justify-center gap-12 w-full">
        <p>Privacy Policy</p>
        <p>Terms and Conditions</p>
    </div>
</div>