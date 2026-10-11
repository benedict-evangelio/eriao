<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\User;
use Flux\Flux;
use Livewire\Attributes\Renderless;

new class extends Component
{
    public $id;
    public $userDetails;
    public $firstName;
    public $lastName;
    public $emailAddress;
    public $password;
    public $role;
    public $status;
    
    #[On('onUpdateUser')]
    public function onLoadData($id) {
        $this->id = $id;
        $this->userDetails = User::where('id', $this->id)->first();
        $this->firstName = $this->userDetails['first_name'];    
        $this->lastName = $this->userDetails['last_name'];    
        $this->emailAddress = $this->userDetails['email'];    
        $this->role = $this->userDetails['role'];    
        $this->status = $this->userDetails['status'];    
    }

    public function onSave() {
        $this->validate([
            'firstName' => 'required',
            'lastName' => 'required',
            'emailAddress' => 'required',
            'password' => 'required',
            'role' => 'required',
            'status' => 'required',
        ]);

        User::where('id', $this->id)->update([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->emailAddress,
            'password' => $this->password,
            'role' => $this->role,
            'status' => $this->status,
        ]);

        $this->dispatch('onRefreshUsers');
        $this->onClose();
        Flux::toast(variant: 'success', text: 'User updated.');
    }

    public function onClose() {
        Flux::modal('update-user')->close();
    }

    public function onReset() {

    }
};
?>

<flux:modal name="update-user" class="max-w-96" @close="onReset">
    <div class="flex flex-col w-full">
        <p class="font-medium text-lg">Update User</p>
        <p>Edit user's data</p>

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