<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItems;
use App\Models\User;
use App\Models\ProductItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Flux\Flux;
use Carbon\Carbon;
use Livewire\Attributes\On;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $requestedBy = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $viewMode = 'active';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public ?int $selectedOrderId = null;

    public ?int $confirmationOrderId = null;

    public array $receivedQuantities = [];

    public string $confirmationAction = '';

    public function updatedSearch(): void
    {
        $this->resetPagination();
    }

    public function updatedRequestedBy(): void
    {
        $this->resetPagination();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPagination();
    }

    public function updatedDateTo(): void
    {
        $this->resetPagination();
    }

    public function updatedViewMode(): void
    {
        $this->resetPagination();
    }

    public function resetPagination(): void
    {
        $this->resetPage('activePage');
        $this->resetPage('archivedPage');
    }

    public function sort($column): void
    {
        $allowedColumns = [
            'id',
            'created_at',
            'status',
            'quantity',
            'total_amount',
            'requested_by',
        ];

        if (!in_array($column, $allowedColumns)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc'
                ? 'desc'
                : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPagination();
    }

    #[Computed]
    public function requesters()
    {
        return User::query()
            ->select([
                'id',
                'first_name',
                'last_name',
            ])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    protected function baseOrdersQuery(bool $archived = false)
    {
        return PurchaseOrder::query()
            ->join(
                'users',
                'users.id',
                '=',
                'purchase_order.requested_by'
            )
            ->select([
                'purchase_order.*',
                'users.first_name as requester_first_name',
                'users.last_name as requester_last_name',
                'users.email as requester_email',
            ])
            ->selectSub(
                DB::table('product_order_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'product_order_items.purchase_order_id',
                        'purchase_order.id'
                    ),
                'product_count'
            )
            ->selectSub(
                DB::table('product_order_items')
                    ->join(
                        'product_items',
                        'product_items.id',
                        '=',
                        'product_order_items.product_id'
                    )
                    ->select('product_items.name')
                    ->whereColumn(
                        'product_order_items.purchase_order_id',
                        'purchase_order.id'
                    )
                    ->orderBy('product_order_items.id')
                    ->limit(1),
                'first_product_name'
            )
            ->when(
                $archived,
                fn ($query) => $query->where(
                    'purchase_order.status',
                    'archived'
                ),
                fn ($query) => $query->where(
                    'purchase_order.status',
                    '!=',
                    'archived'
                )
            )
            ->when(
                trim($this->search) !== '',
                function ($query) {
                    $search = trim($this->search);

                    $query->where(function ($query) use ($search) {
                        $query
                            ->where(
                                'purchase_order.id',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'users.first_name',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'users.last_name',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'users.email',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhere(
                                'purchase_order.status',
                                'like',
                                '%' . $search . '%'
                            )
                            ->orWhereExists(function ($query) use ($search) {
                                $query
                                    ->select(DB::raw(1))
                                    ->from('product_order_items')
                                    ->join(
                                        'product_items',
                                        'product_items.id',
                                        '=',
                                        'product_order_items.product_id'
                                    )
                                    ->whereColumn(
                                        'product_order_items.purchase_order_id',
                                        'purchase_order.id'
                                    )
                                    ->where(
                                        'product_items.name',
                                        'like',
                                        '%' . $search . '%'
                                    );
                            });
                    });
                }
            )
            ->when(
                $this->requestedBy !== '',
                fn ($query) => $query->where(
                    'purchase_order.requested_by',
                    $this->requestedBy
                )
            )
            ->when(
                $this->dateFrom !== '',
                fn ($query) => $query->whereDate(
                    'purchase_order.created_at',
                    '>=',
                    $this->dateFrom
                )
            )
            ->when(
                $this->dateTo !== '',
                fn ($query) => $query->whereDate(
                    'purchase_order.created_at',
                    '<=',
                    $this->dateTo
                )
            );
    }

    protected function applySorting($query)
    {
        if ($this->sortBy === 'requested_by') {
            return $query
                ->orderBy(
                    'users.first_name',
                    $this->sortDirection
                )
                ->orderBy(
                    'users.last_name',
                    $this->sortDirection
                );
        }

        return $query->orderBy(
            'purchase_order.' . $this->sortBy,
            $this->sortDirection
        );
    }

    #[On('onRefreshAllProducts')]
    #[Computed]
    public function orders()
    {
        $query = $this->baseOrdersQuery(false);

        $query = $this->applySorting($query);

        return $query->paginate(
            10,
            ['*'],
            'activePage'
        );
    }

    #[Computed]
    public function archivedOrders()
    {
        $query = $this->baseOrdersQuery(true);

        $query = $this->applySorting($query);

        return $query->paginate(
            10,
            ['*'],
            'archivedPage'
        );
    }

    #[Computed]
    public function selectedOrder()
    {
        if (!$this->selectedOrderId) {
            return null;
        }

        return PurchaseOrder::query()
            ->join(
                'users',
                'users.id',
                '=',
                'purchase_order.requested_by'
            )
            ->leftJoin(
                'users as approver',
                'approver.id',
                '=',
                'purchase_order.approved_by'
            )
            ->select([
                'purchase_order.*',
                'users.first_name as requester_first_name',
                'users.last_name as requester_last_name',
                'users.email as requester_email',
                'approver.first_name as approver_first_name',
                'approver.last_name as approver_last_name',
            ])
            ->where(
                'purchase_order.id',
                $this->selectedOrderId
            )
            ->first();
    }

    #[Computed]
    public function selectedOrderItems()
    {
        if (!$this->selectedOrderId) {
            return collect();
        }

        return PurchaseOrderItems::query()
            ->join(
                'product_items',
                'product_items.id',
                '=',
                'product_order_items.product_id'
            )
            ->select([
                'product_order_items.*',
                'product_items.name as product_name',
            ])
            ->where(
                'product_order_items.purchase_order_id',
                $this->selectedOrderId
            )
            ->orderBy('product_order_items.id')
            ->get();
    }

    public function statusColor($status): string
    {
        return match ($status) {
            'pending' => 'amber',
            'approved' => 'blue',
            'incomplete' => 'amber',
            'received' => 'green',
            'completed' => 'green',
            'rejected' => 'red',
            'cancelled' => 'red',
            'archived' => 'zinc',
            default => 'zinc',
        };
    }

    public function statusLabel($status): string
    {
        return ucwords(
            str_replace('_', ' ', $status)
        );
    }

    public function viewOrder(int $orderId): void
    {
        $exists = PurchaseOrder::query()
            ->where('id', $orderId)
            ->exists();

        if (!$exists) {
            return;
        }

        $this->selectedOrderId = $orderId;

        Flux::modal('view-purchase-order')->show();
    }

    public function confirmApprove(int $orderId): void
    {
        $exists = PurchaseOrder::query()
            ->where('id', $orderId)
            ->where('status', 'pending')
            ->exists();

        if (!$exists) {
            return;
        }

        $this->confirmationOrderId = $orderId;
        $this->confirmationAction = 'approve';

        Flux::modal('confirm-approve-purchase-order')->show();
    }

    public function confirmReject(int $orderId): void
    {
        $exists = PurchaseOrder::query()
            ->where('id', $orderId)
            ->where('status', 'pending')
            ->exists();

        if (!$exists) {
            return;
        }

        $this->confirmationOrderId = $orderId;
        $this->confirmationAction = 'reject';

        Flux::modal('confirm-reject-purchase-order')->show();
    }

    public function confirmReceive(int $orderId): void
    {
        $exists = PurchaseOrder::query()
            ->where('id', $orderId)
            ->whereIn('status', ['approved', 'incomplete'])
            ->exists();

        if (!$exists) {
            return;
        }

        $this->confirmationOrderId = $orderId;
        $this->confirmationAction = 'receive';
        $this->selectedOrderId = $orderId;
        $this->receivedQuantities = PurchaseOrderItems::query()
            ->where('purchase_order_id', $orderId)
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->id => 0,
            ])
            ->all();

        Flux::modal('confirm-receive-purchase-order')->show();
    }

    public function confirmCancel(int $orderId): void
    {
        $exists = PurchaseOrder::query()
            ->where('id', $orderId)
            ->where('status', 'approved')
            ->exists();

        if (!$exists) {
            return;
        }

        $this->confirmationOrderId = $orderId;
        $this->confirmationAction = 'cancel';

        Flux::modal('confirm-cancel-purchase-order')->show();
    }

    public function confirmArchive(int $orderId): void
    {
        $exists = PurchaseOrder::query()
            ->where('id', $orderId)
            ->where('status', '!=', 'archived')
            ->exists();

        if (!$exists) {
            return;
        }

        $this->confirmationOrderId = $orderId;
        $this->confirmationAction = 'archive';

        Flux::modal('confirm-archive-purchase-order')->show();
    }

    public function approveOrder(): void
    {
        if (!$this->confirmationOrderId) {
            return;
        }

        $order = PurchaseOrder::query()
            ->where('id', $this->confirmationOrderId)
            ->where('status', 'pending')
            ->first();

        if (!$order) {
            $this->closeConfirmationModals();
            return;
        }

        $order->update([
            'approved_by' => auth()->id(),
            'status' => 'approved',
        ]);

        $this->closeConfirmationModals();

        $this->dispatch('onRefreshPurchaseOrders');

        $this->resetPagination();
        Flux::toast(variant: 'success', text: 'Purchase order approved.');
    }

    public function rejectOrder(): void
    {
        if (!$this->confirmationOrderId) {
            return;
        }

        $order = PurchaseOrder::query()
            ->where('id', $this->confirmationOrderId)
            ->where('status', 'pending')
            ->first();

        if (!$order) {
            $this->closeConfirmationModals();
            return;
        }

        $order->update([
            'approved_by' => null,
            'status' => 'rejected',
        ]);

        $this->closeConfirmationModals();

        $this->dispatch('onRefreshPurchaseOrders');

        $this->resetPagination();
        Flux::toast(variant: 'success', text: 'Purchase order rejected.');
    }

    public function receiveOrder(): void
    {
        if (!$this->confirmationOrderId) {
            return;
        }

        $received = DB::transaction(function () {

            $order = PurchaseOrder::query()
                ->where('id', $this->confirmationOrderId)
                ->whereIn('status', ['approved', 'incomplete'])
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return false;
            }

            $items = PurchaseOrderItems::query()
                ->where(
                    'purchase_order_id',
                    $order->id
                )
                ->lockForUpdate()
                ->get();

            $receivedThisDelivery = 0;

            foreach ($items as $item) {

                $receivedNow = (int) ($this->receivedQuantities[$item->id] ?? 0);
                $remaining = (int) $item->quantity - (int) $item->received_quantity;

                if ($receivedNow < 0 || $receivedNow > $remaining) {
                    throw ValidationException::withMessages([
                        'receivedQuantities.' . $item->id => 'Received quantity must be between 0 and the remaining quantity.',
                    ]);
                }

                $receivedThisDelivery += $receivedNow;

                $product = ProductItem::query()
                    ->whereKey($item->product_id)
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    continue;
                }

                $currentWarehouseQuantity = (int) $product->warehouse_quantity;

                $incomingQuantity = $receivedNow;

                $currentMax = (int) $product->max;

                $newWarehouseQuantity = $currentWarehouseQuantity + $incomingQuantity;
                $newTotalQuantity = (int) $product->front_quantity + $newWarehouseQuantity;

                if ($newTotalQuantity > $currentMax) {
                    $product->max = $newTotalQuantity;
                }

                $product->warehouse_quantity = $newWarehouseQuantity;
                $product->quantity = $newTotalQuantity;

                if ($newTotalQuantity > 0) {
                    $product->status = 'available';
                }

                $product->save();

                $item->received_quantity = (int) $item->received_quantity + $receivedNow;
                $item->save();
            }

            if ($receivedThisDelivery === 0) {
                throw ValidationException::withMessages([
                    'receivedQuantities' => 'Enter at least one item received in this delivery.',
                ]);
            }

            $isComplete = $items->every(fn ($item) =>
                (int) $item->received_quantity >= (int) $item->quantity
            );

            $order->update([
                'status' => $isComplete ? 'completed' : 'incomplete',
            ]);

            return true;
        });

        if (! $received) {
            $this->closeConfirmationModals();

            return;
        }

        $this->closeConfirmationModals();

        $this->dispatch('onRefreshPurchaseOrders');
        $this->dispatch('onRefreshProducts');

        $this->resetPagination();
        Flux::toast(variant: 'success', text: 'Purchase order delivery received and inventory updated.');
    }

    public function cancelOrder(): void
    {
        if (!$this->confirmationOrderId) {
            return;
        }

        $order = PurchaseOrder::query()
            ->where('id', $this->confirmationOrderId)
            ->where('status', 'approved')
            ->first();

        if (!$order) {
            $this->closeConfirmationModals();
            return;
        }

        $order->update([
            'status' => 'cancelled',
        ]);

        $this->closeConfirmationModals();

        $this->dispatch('onRefreshPurchaseOrders');

        $this->resetPagination();
        Flux::toast(variant: 'success', text: 'Purchase order cancelled.');
    }

    public function archiveOrder(): void
    {
        if (!$this->confirmationOrderId) {
            return;
        }

        $order = PurchaseOrder::query()
            ->where('id', $this->confirmationOrderId)
            ->where('status', '!=', 'archived')
            ->first();

        if (!$order) {
            $this->closeConfirmationModals();
            return;
        }

        $order->update([
            'status' => 'archived',
        ]);

        $this->closeConfirmationModals();

        $this->dispatch('onRefreshPurchaseOrders');

        $this->resetPagination();
        Flux::toast(variant: 'success', text: 'Purchase order archived.');
    }

    public function closeConfirmationModals(): void
    {
        Flux::modal('confirm-approve-purchase-order')->close();
        Flux::modal('confirm-reject-purchase-order')->close();
        Flux::modal('confirm-receive-purchase-order')->close();
        Flux::modal('confirm-cancel-purchase-order')->close();
        Flux::modal('confirm-archive-purchase-order')->close();

        $this->confirmationOrderId = null;
        $this->confirmationAction = '';
        $this->receivedQuantities = [];
        unset($this->selectedOrder, $this->selectedOrderItems);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->requestedBy = '';
        $this->dateFrom = '';
        $this->dateTo = '';

        $this->resetPagination();
    }

    public function closeViewOrder(): void
    {
        $this->selectedOrderId = null;

        Flux::modal('view-purchase-order')->close();
    }
};
?>

<div class="flex flex-col w-full">

    <div class="flex flex-row items-center gap-3">

        <div class="flex flex-col">
            <p class="text-xl font-bold">Purchase Order</p>
            <p>Manage all purchase orders</p>
        </div>

        <flux:spacer />

        <flux:radio.group
            wire:model.live="viewMode"
            variant="segmented"
            size="md"
        >
            <flux:radio
                value="active"
                label="Purchase Orders"
            />

            <flux:radio
                value="archived"
                label="Archived"
            />
        </flux:radio.group>

        <flux:modal.trigger name="new-purchase-order">
            <flux:button
                variant="primary"
                icon="plus"
                class="bg-primary hover:bg-primary"
            >
                New Purchase Order
            </flux:button>
        </flux:modal.trigger>

    </div>

    <div class="mt-6">

        <div class="flex flex-col xl:flex-row gap-3">

            <div class="flex-1">

                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    label="Search"
                    placeholder="Search purchase orders, products, or requester..."
                />

            </div>

            <div class="w-full xl:w-60">

                <flux:select
                    wire:model.live="requestedBy"
                    placeholder="All requesters"
                    label="Filter by"
                >
                    <flux:select.option value="">
                        All requesters
                    </flux:select.option>

                    @foreach ($this->requesters as $requester)

                        <flux:select.option value="{{ $requester->id }}">
                            {{ $requester->first_name }}
                            {{ $requester->last_name }}
                        </flux:select.option>

                    @endforeach

                </flux:select>

            </div>

            <div class="grid grid-cols-2 gap-3 w-full xl:w-72">

                <flux:input
                    wire:model.live="dateFrom"
                    type="date"
                    label="From"
                />

                <flux:input
                    wire:model.live="dateTo"
                    type="date"
                    label="To"
                />

            </div>

        </div>

    </div>

    @if ($viewMode === 'active')

        <div class="mt-4">

            <flux:table :paginate="$this->orders">

                <flux:table.columns>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'id'"
                        :direction="$sortDirection"
                        wire:click="sort('id')"
                    >
                        PO #
                    </flux:table.column>

                    <flux:table.column>
                        Products
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'requested_by'"
                        :direction="$sortDirection"
                        wire:click="sort('requested_by')"
                    >
                        Requested By
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'quantity'"
                        :direction="$sortDirection"
                        wire:click="sort('quantity')"
                    >
                        Quantity
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'status'"
                        :direction="$sortDirection"
                        wire:click="sort('status')"
                    >
                        Status
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'created_at'"
                        :direction="$sortDirection"
                        wire:click="sort('created_at')"
                    >
                        Date
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'total_amount'"
                        :direction="$sortDirection"
                        wire:click="sort('total_amount')"
                    >
                        Total Amount
                    </flux:table.column>

                    <flux:table.column>
                        Action
                    </flux:table.column>

                </flux:table.columns>

                <flux:table.rows>

                    @forelse ($this->orders as $order)

                        <flux:table.row :key="'active-po-' . $order->id">

                            <flux:table.cell variant="strong">
                                PO-{{ str_pad($order->id, 5, '0', STR_PAD_LEFT) }}
                            </flux:table.cell>

                            <flux:table.cell class="min-w-60">

                                <div class="flex flex-col">

                                    <p class="truncate font-medium">
                                        {{ $order->first_product_name ?? 'No products' }}
                                    </p>

                                    @if ($order->product_count > 1)

                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            +{{ $order->product_count - 1 }}
                                            {{ $order->product_count - 1 === 1 ? 'other product' : 'other products' }}
                                        </p>

                                    @endif

                                </div>

                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">

                                <div class="flex flex-col">

                                    <span class="font-medium">
                                        {{ $order->requester_first_name }}
                                        {{ $order->requester_last_name }}
                                    </span>

                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $order->requester_email }}
                                    </span>

                                </div>

                            </flux:table.cell>

                            <flux:table.cell>
                                {{ number_format($order->quantity) }}
                            </flux:table.cell>

                            <flux:table.cell class="py-0">

                                <flux:badge
                                    size="sm"
                                    :color="$this->statusColor($order->status)"
                                >
                                    {{ $this->statusLabel($order->status) }}
                                </flux:badge>

                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                {{ Carbon::parse($order->created_at)->format('M d, Y') }}
                            </flux:table.cell>

                            <flux:table.cell variant="strong">
                                PHP {{ number_format($order->total_amount, 2) }}
                            </flux:table.cell>

                            <flux:table.cell class="py-0">

                                <flux:dropdown
                                    position="bottom"
                                    align="end"
                                >

                                    <flux:button
                                        icon="ellipsis-horizontal"
                                        variant="ghost"
                                        size="sm"
                                        square
                                        aria-label="Purchase order actions"
                                    />

                                    <flux:menu>

                                        <flux:menu.item
                                            wire:click="viewOrder({{ $order->id }})"
                                            icon="eye"
                                        >
                                            View
                                        </flux:menu.item>
                                    </flux:menu>

                                </flux:dropdown>

                            </flux:table.cell>

                        </flux:table.row>

                    @empty

                        <flux:table.row>

                            <flux:table.cell colspan="8">

                                <div class="flex flex-col items-center justify-center py-12">

                                    <flux:icon
                                        name="clipboard-document-list"
                                        class="size-10 text-zinc-400"
                                    />

                                    <p class="mt-3 text-sm font-medium">
                                        No purchase orders found
                                    </p>

                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">

                                        @if ($search || $requestedBy || $dateFrom || $dateTo)
                                            No purchase orders match your filters.
                                        @else
                                            Create your first purchase order to get started.
                                        @endif

                                    </p>

                                </div>

                            </flux:table.cell>

                        </flux:table.row>

                    @endforelse

                </flux:table.rows>

            </flux:table>

        </div>

    @else

        <div class="mt-4">

            <flux:table :paginate="$this->archivedOrders">

                <flux:table.columns>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'id'"
                        :direction="$sortDirection"
                        wire:click="sort('id')"
                    >
                        PO #
                    </flux:table.column>

                    <flux:table.column>
                        Products
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'requested_by'"
                        :direction="$sortDirection"
                        wire:click="sort('requested_by')"
                    >
                        Requested By
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'quantity'"
                        :direction="$sortDirection"
                        wire:click="sort('quantity')"
                    >
                        Quantity
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'status'"
                        :direction="$sortDirection"
                        wire:click="sort('status')"
                    >
                        Status
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'created_at'"
                        :direction="$sortDirection"
                        wire:click="sort('created_at')"
                    >
                        Date
                    </flux:table.column>

                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'total_amount'"
                        :direction="$sortDirection"
                        wire:click="sort('total_amount')"
                    >
                        Total Amount
                    </flux:table.column>

                    <flux:table.column>
                        Action
                    </flux:table.column>

                </flux:table.columns>

                <flux:table.rows>

                    @forelse ($this->archivedOrders as $order)

                        <flux:table.row :key="'archived-po-' . $order->id">

                            <flux:table.cell variant="strong">
                                PO-{{ str_pad($order->id, 5, '0', STR_PAD_LEFT) }}
                            </flux:table.cell>

                            <flux:table.cell class="min-w-60">

                                <div class="flex flex-col">

                                    <p class="truncate font-medium">
                                        {{ $order->first_product_name ?? 'No products' }}
                                    </p>

                                    @if ($order->product_count > 1)

                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            +{{ $order->product_count - 1 }}
                                            {{ $order->product_count - 1 === 1 ? 'other product' : 'other products' }}
                                        </p>

                                    @endif

                                </div>

                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">

                                <div class="flex flex-col">

                                    <span class="font-medium">
                                        {{ $order->requester_first_name }}
                                        {{ $order->requester_last_name }}
                                    </span>

                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $order->requester_email }}
                                    </span>

                                </div>

                            </flux:table.cell>

                            <flux:table.cell>
                                {{ number_format($order->quantity) }}
                            </flux:table.cell>

                            <flux:table.cell class="py-0">

                                <flux:badge
                                    size="sm"
                                    :color="$this->statusColor($order->status)"
                                >
                                    {{ $this->statusLabel($order->status) }}
                                </flux:badge>

                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                {{ Carbon::parse($order->created_at)->format('M d, Y') }}
                            </flux:table.cell>

                            <flux:table.cell variant="strong">
                                PHP {{ number_format($order->total_amount, 2) }}
                            </flux:table.cell>

                            <flux:table.cell class="py-0">

                                <flux:dropdown
                                    position="bottom"
                                    align="end"
                                >

                                    <flux:button
                                        icon="ellipsis-horizontal"
                                        variant="ghost"
                                        size="sm"
                                        square
                                        aria-label="Archived purchase order actions"
                                    />

                                    <flux:menu>

                                        <flux:menu.item
                                            wire:click="viewOrder({{ $order->id }})"
                                            icon="eye"
                                        >
                                            View
                                        </flux:menu.item>

                                    </flux:menu>

                                </flux:dropdown>

                            </flux:table.cell>

                        </flux:table.row>

                    @empty

                        <flux:table.row>

                            <flux:table.cell colspan="8">

                                <div class="flex flex-col items-center justify-center py-12">

                                    <flux:icon
                                        name="archive-box"
                                        class="size-10 text-zinc-400"
                                    />

                                    <p class="mt-3 text-sm font-medium">
                                        No archived purchase orders
                                    </p>

                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                        Archived purchase orders will appear here.
                                    </p>

                                </div>

                            </flux:table.cell>

                        </flux:table.row>

                    @endforelse

                </flux:table.rows>

            </flux:table>

        </div>

    @endif

    <flux:modal
        name="view-purchase-order"
        class="max-w-3xl w-full"
        :closable='false'
    >

        @if ($this->selectedOrder)

            <div class="flex flex-col gap-6">

                <div class="flex items-start">

                    <div class="flex flex-col">

                        <p class="text-xl font-bold">
                            PO-{{ str_pad($this->selectedOrder->id, 5, '0', STR_PAD_LEFT) }}
                        </p>

                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Purchase order details
                        </p>

                    </div>

                    <flux:spacer />

                    <flux:badge
                        size="sm"
                        :color="$this->statusColor($this->selectedOrder->status)"
                    >
                        {{ $this->statusLabel($this->selectedOrder->status) }}
                    </flux:badge>

                </div>

                <div class="grid grid-cols-2 gap-4">

                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">

                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Requested By
                        </p>

                        <p class="mt-1 font-medium">
                            {{ $this->selectedOrder->requester_first_name }}
                            {{ $this->selectedOrder->requester_last_name }}
                        </p>

                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $this->selectedOrder->requester_email }}
                        </p>

                    </div>

                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">

                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Date Requested
                        </p>

                        <p class="mt-1 font-medium">
                            {{ Carbon::parse($this->selectedOrder->created_at)->format('M d, Y h:i A') }}
                        </p>

                    </div>

                </div>

                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">

                    <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">

                        <p class="text-sm font-semibold">
                            Ordered Products
                        </p>

                    </div>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">

                        @forelse ($this->selectedOrderItems as $item)

                            <div class="flex items-center justify-between gap-4 px-4 py-4">

                                <div class="min-w-0">

                                    <p class="font-medium">
                                        {{ $item->product_name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        Ordered: {{ number_format($item->quantity) }}
                                        · Received: {{ number_format($item->received_quantity) }}
                                        · Remaining: {{ number_format($item->quantity - $item->received_quantity) }}
                                        ×
                                        PHP {{ number_format($item->unit_price, 2) }}
                                    </p>

                                </div>

                                <p class="font-semibold whitespace-nowrap">
                                    PHP {{ number_format($item->quantity * $item->unit_price, 2) }}
                                </p>

                            </div>

                        @empty

                            <div class="px-4 py-8 text-center text-sm text-zinc-500">
                                No products found for this purchase order.
                            </div>

                        @endforelse

                    </div>

                </div>

                <div class="rounded-xl bg-zinc-50 p-5 dark:bg-zinc-800/50">

                    <div class="flex items-center justify-between">

                        <div>

                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Total Quantity
                            </p>

                            <p class="mt-1 text-lg font-semibold">
                                {{ number_format($this->selectedOrder->quantity) }}
                            </p>

                        </div>

                        <div class="text-right">

                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Total Amount
                            </p>

                            <p class="mt-1 text-2xl font-bold">
                                PHP {{ number_format($this->selectedOrder->total_amount, 2) }}
                            </p>

                        </div>

                    </div>

                </div>

                @if ($this->selectedOrder->approved_by)

                    <div class="text-sm text-zinc-500 dark:text-zinc-400">

                        Approved by

                        <span class="font-medium text-zinc-900 dark:text-white">
                            {{ $this->selectedOrder->approver_first_name }}
                            {{ $this->selectedOrder->approver_last_name }}
                        </span>

                    </div>

                @endif

                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($this->selectedOrder->status === 'pending' && Auth::user()->role === 'admin')
                        <flux:button icon="check" variant="primary" wire:click="confirmApprove({{ $this->selectedOrder->id }})">
                            Approve
                        </flux:button>
                        <flux:button icon="x-mark" variant="danger" wire:click="confirmReject({{ $this->selectedOrder->id }})">
                            Reject
                        </flux:button>
                    @endif

                    @if (in_array($this->selectedOrder->status, ['approved', 'incomplete'], true))
                        @if (in_array(Auth::user()->role, ['admin', 'employee', 'staff'], true))
                            <flux:button icon="cube" variant="primary" wire:click="confirmReceive({{ $this->selectedOrder->id }})">
                                Received
                            </flux:button>
                        @endif

                        @if ($this->selectedOrder->status === 'approved' && Auth::user()->role === 'admin')
                            <flux:button icon="x-circle" variant="danger" wire:click="confirmCancel({{ $this->selectedOrder->id }})">
                                Cancel
                            </flux:button>
                        @endif
                    @endif

                    @if ($this->selectedOrder->status !== 'archived' && Auth::user()->role === 'admin')
                        <flux:button icon="archive-box" variant="outline" wire:click="confirmArchive({{ $this->selectedOrder->id }})">
                            Archive
                        </flux:button>
                    @endif

                    <flux:modal.close>
                        <flux:button variant="ghost">
                            Close
                        </flux:button>
                    </flux:modal.close>

                </div>

            </div>

        @endif

    </flux:modal>

    <flux:modal
        name="confirm-approve-purchase-order"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Approve purchase order?
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Are you sure you want to approve this purchase order?
                    This will mark the order as approved and record you as the approver.
                </p>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>
                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>
                </flux:modal.close>

                <flux:button
                    wire:click="approveOrder"
                    variant="primary"
                >
                    Approve
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <flux:modal
        name="confirm-reject-purchase-order"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Reject purchase order?
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Are you sure you want to reject this purchase order?
                    The order will be marked as rejected.
                </p>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>
                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>
                </flux:modal.close>

                <flux:button
                    wire:click="rejectOrder"
                    variant="danger"
                >
                    Reject
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <flux:modal
        name="confirm-receive-purchase-order"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Receive purchase order?
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Enter the quantity received for this delivery. Partial deliveries remain incomplete.
                </p>

            </div>

            @if ($this->selectedOrder)

                <div class="flex flex-col gap-3">
                    @foreach ($this->selectedOrderItems as $item)
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="font-medium">{{ $item->product_name }}</p>
                                <p class="text-xs text-zinc-500">
                                    Remaining: {{ number_format($item->quantity - $item->received_quantity) }}
                                </p>
                            </div>
                            <flux:input
                                wire:model="receivedQuantities.{{ $item->id }}"
                                type="number"
                                min="0"
                                max="{{ $item->quantity - $item->received_quantity }}"
                                label="Received now"
                            />
                        </div>
                    @endforeach
                </div>

                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">

                    <div class="flex items-center justify-between">

                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            Total Quantity
                        </span>

                        <span class="font-semibold">
                            {{ number_format($this->selectedOrder->quantity) }}
                        </span>

                    </div>

                    <div class="mt-3 flex items-center justify-between">

                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            Purchase Order Total
                        </span>

                        <span class="font-semibold">
                            PHP {{ number_format($this->selectedOrder->total_amount, 2) }}
                        </span>

                    </div>

                </div>

            @endif

            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">

                <p class="text-sm text-amber-800 dark:text-amber-200">
                    Receiving these units will increase warehouse inventory.
                    If the resulting inventory quantity exceeds a product's current maximum,
                    the maximum will automatically be increased to the new quantity.
                </p>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>
                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>
                </flux:modal.close>

                <flux:button
                    wire:click="receiveOrder"
                    variant="primary"
                >
                    Receive delivery
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <flux:modal
        name="confirm-cancel-purchase-order"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Cancel purchase order?
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Are you sure you want to cancel this approved purchase order?
                    The order will be marked as cancelled and will not be added to inventory.
                </p>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>
                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>
                </flux:modal.close>

                <flux:button
                    wire:click="cancelOrder"
                    variant="danger"
                >
                    Cancelled
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <flux:modal
        name="confirm-archive-purchase-order"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Archive purchase order?
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Are you sure you want to archive this purchase order?
                    It will be removed from the active purchase-order list and moved to Archived.
                </p>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>
                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>
                </flux:modal.close>

                <flux:button
                    wire:click="archiveOrder"
                    variant="primary"
                >
                    Archive
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <livewire:modals.purchase-order.new-purchase-order />

</div>