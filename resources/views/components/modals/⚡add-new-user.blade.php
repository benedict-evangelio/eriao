<?php

use Livewire\Component;
use App\Models\User;
use Flux\Flux;

new class extends Component
{
    public $firstName;
    public $lastName;
    public $emailAddress;
    public $password;
    public $role = 'staff';
    public $status = 'inactive';

    public function onSave() {
        $this->validate([
            'firstName' => 'required',
            'lastName' => 'required',
            'emailAddress' => 'required',
            'password' => 'required',
            'role' => 'required',
            'status' => 'required',
        ]);

        User::create([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->emailAddress,
            'password' => $this->password,
            'role' => $this->role,
            'status' => $this->status,
         ]);

         $this->dispatch('onRefreshUsers');
         $this->onClose();
         Flux::toast(variant: 'success', text: 'User created.');
    }

    public function onClose() {
        Flux::modal('new-user')->close();
    }

    public function onReset() {
        $this->firstName = '';
        $this->lastName = '';
        $this->emailAddress = '';
        $this->password = '';
        $this->role = 'staff';
        $this->status = 'inactive';
    }
};
?>

<flux:modal name="new-user" class="max-w-96" @close="onReset">
    <div class="flex flex-col w-full">
        <p class="font-medium text-lg">New User</p>
        <p>Create new user</p>

        <div class="flex flex-col gap-4 py-4">
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model='firstName' label="First Name" />
                <flux:input wire:model='lastName' label="Last Name" />
            </div>
            <flux:input wire:model='emailAddress' label="Email Address" />
            <flux:input wire:model='password' type="password" label="Password" viewable />
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model='role' label="Role">
                    <flux:select.option value="admin">Admin</flux:select.option>
                    <flux:select.option value="cashier">Cashier</flux:select.option>
                    <flux:select.option value="employee">Employee</flux:select.option>
                    <flux:select.option value="staff">Staff</flux:select.option>
                </flux:select>

                <flux:select wire:model='status' label="Status">
                    <flux:select.option value="active">Active</flux:select.option>
                    <flux:select.option value="inactive">Inactive</flux:select.option>
                    <flux:select.option value="suspended">Suspended</flux:select.option>
                    <flux:select.option value="terminated">Terminated</flux:select.option>
                </flux:select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mt-8">
            <flux:button wire:click='onClose' variant="ghost">Cancel</flux:button>
            <flux:button wire:click='onSave' variant="primary" class="bg-primary hover:bg-primary">Save</flux:button>
        </div>
    </div>
</flux:modal>