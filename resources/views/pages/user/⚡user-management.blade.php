<?php

use Livewire\Component;
use App\Models\User;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;

new class extends Component
{
    use WithPagination;

    public $sortBy = 'created_at';
    public $sortDirection = 'desc';
    public $summary = [];

    public function sort($column) {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }


    #[On('onRefreshUsers')]
    #[\Livewire\Attributes\Computed]
    public function orders()
    {
        return \App\Models\User::query()
            ->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            ->paginate(8);
    }

    #[Renderless]
    public function onUpdateUser($id) {
        $this->dispatch("onUpdateUser", $id);
    }
};
?>

<div class="flex flex-col w-full">
    <div class="flex flex-row items-center mb-2">
        <div class="flex flex-col">
            <p class="text-xl font-bold">User Management</p>
            <p>Manage user's access</p>
        </div>

        <flux:spacer />

        <flux:modal.trigger name="new-user">
            <flux:button
                variant="primary"
                icon="plus"
                class="bg-primary hover:bg-primary"
            >
                New User
            </flux:button>
        </flux:modal.trigger>
    </div>

    <flux:table :paginate="$this->orders">
        <flux:table.columns>
            <flux:table.column>User</flux:table.column>
            <flux:table.column>Email Address</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->orders as $order)
                <flux:table.row :key="$order->id">
                    <flux:table.cell class="flex items-center gap-3">
                        <flux:avatar size="xs" src="{{ $order->customer_avatar }}" />

                        {{ $order->first_name . ' ' . $order->last_name}}
                    </flux:table.cell>

                    <flux:table.cell class="whitespace-nowrap">{{ $order->email }}</flux:table.cell>

                    <flux:table.cell class="py-0">
                        <flux:badge size="sm" :color="$order->status_color">{{ ucwords($order->role) }}</flux:badge>
                    </flux:table.cell>

                    <flux:table.cell class="py-0">
                        <flux:badge size="sm" :color="$order->status_color">{{ ucwords($order->status) }}</flux:badge>
                    </flux:table.cell>

                    <flux:table.cell variant="strong">{{ $order->amount }}</flux:table.cell>

                    <flux:table.cell class="py-0">
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon:trailing="ellipsis-horizontal"></flux:button>

                            <flux:menu>
                                <flux:modal.trigger name="update-user">
                                    <flux:menu.item wire:click='onUpdateUser({{ $order->id }})' icon="pencil-square">Update</flux:menu.item>
                                </flux:modal.trigger>

                                <flux:menu.separator />

                                <flux:menu.item variant="danger" icon="trash">Delete</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <livewire:modals.add-new-user />
    <livewire:modals.update-user />
</div>
