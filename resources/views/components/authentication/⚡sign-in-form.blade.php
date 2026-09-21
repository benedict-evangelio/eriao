<?php

use Livewire\Component;

new class extends Component
{
    public $emailAddress;
    public $password;

    public function onSignIn() {
        $this->validate([
            'emailAddress' => 'required|max:100',
            'password' => 'required',
        ]);

        if(Auth::attempt(['email' => $this->emailAddress, 'password' => $this->password])) {
            $this->redirect('/dashboard');
        } else {
            $this->addError('password', 'Incorrect email address or password.');
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
        <p class="text-center text-lg font-bold">Sign In to Continue</p>
    </div>

    <div class="flex flex-col mt-10 gap-4 w-full">
        <flux:input wire:model="emailAddress" label="Email Address" />
        <flux:input wire:model="password" label="Password" type="password" viewable />
        <div class="flex flex-row w-full justify-end text-primary">
            <p class="text-right">Forgot Password</p>
        </div>

        <div class="flex flex-col gap-4">
            <flux:button wire:click='onSignIn' class="bg-primary hover:bg-primary mt-8" variant="primary">Sign In</flux:button>
            <div class="flex flex-row gap-4 items-center">
                <flux:separator />
                <p>or</p>
                <flux:separator />
            </div>
            <a class="flex flex-col w-full" href="/sign-up"><flux:button variant="outline">Create Account</flux:button></a>
        </div>
    </div>

    <flux:spacer />

    <div class="flex flex-row items-center justify-center gap-12 w-full">
        <p>Privacy Policy</p>
        <p>Terms and Conditions</p>
    </div>
</div>