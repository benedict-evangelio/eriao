<?php

use App\Models\Transactions;
use App\Models\ProductItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $statusFilter = 'all';

    public ?int $selectedTransactionId = null;

    public string $editIdNumber = '';

    public array $editItems = [];

    public float $originalTransactionTotal = 0;

    public float $currentTransactionTotal = 0;

    public float $originalDiscountAmount = 0;

    public float $originalTaxAmount = 0;

    public string $paymentMethod = 'Cash';

    public string $referenceNumber = '';

    public array $availableProducts = [];

    // Only these keys may be sorted, mapped to real (table-qualified) columns
    private const SORTABLE = [
        'created_at' => 'transactions.created_at',
        'status' => 'transactions.status',
        'total_amount' => 'transactions.total_amount',
    ];

    public function sort(string $column): void
    {
        if (! array_key_exists($column, self::SORTABLE)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    #[Computed]
    public function orders()
    {
        return Transactions::query()
            ->leftJoin('users', 'users.id', '=', 'transactions.employee_id')
            ->select([
                'transactions.*',
                'users.first_name as cashier_first_name',
                'users.last_name as cashier_last_name',
            ])
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('transactions.status', $this->statusFilter))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('transactions.created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '' && $this->dateTo >= $this->dateFrom, fn ($query) => $query->whereDate('transactions.created_at', '<=', $this->dateTo))
            ->when($this->dateTo !== '' && $this->dateFrom !== '' && $this->dateTo < $this->dateFrom, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy(self::SORTABLE[$this->sortBy], $this->sortDirection)
            ->paginate(5);
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function selectedTransactionDetails(): ?Transactions
    {
        return $this->selectedTransactionId
            ? Transactions::query()->find($this->selectedTransactionId)
            : null;
    }

    #[Computed]
    public function selectedTransactionItems()
    {
        if (! $this->selectedTransactionId) {
            return collect();
        }

        return DB::table('orders')
            ->leftJoin('product_items', 'product_items.id', '=', 'orders.product_id')
            ->where('orders.transaction_id', $this->selectedTransactionId)
            ->select([
                'orders.product_id',
                'product_items.name',
                DB::raw('COUNT(*) as quantity'),
            ])
            ->groupBy('orders.product_id', 'product_items.name')
            ->orderBy('product_items.name')
            ->get();
    }

    #[Computed]
    public function selectedTransactionAdjustments()
    {
        if (! $this->selectedTransactionId) {
            return collect();
        }

        if (! Schema::hasTable('transaction_adjustments')) {
            return collect();
        }

        return DB::table('transaction_adjustments')
            ->leftJoin('users', 'users.id', '=', 'transaction_adjustments.user_id')
            ->where('transaction_adjustments.transaction_id', $this->selectedTransactionId)
            ->select([
                'transaction_adjustments.*',
                'users.first_name',
                'users.last_name',
            ])
            ->latest('transaction_adjustments.created_at')
            ->get();
    }

    public function viewTransaction(int $id): void
    {
        $this->selectedTransactionId = $id;
        unset(
            $this->selectedTransactionDetails,
            $this->selectedTransactionItems,
            $this->selectedTransactionAdjustments
        );
        Flux::modal('view-transaction')->show();
    }

    public function closeTransactionDetails(): void
    {
        Flux::modal('view-transaction')->close();
        $this->selectedTransactionId = null;
        unset(
            $this->selectedTransactionDetails,
            $this->selectedTransactionItems,
            $this->selectedTransactionAdjustments
        );
    }

    public function statusColor(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'completed', 'paid' => 'green',
            'pending' => 'yellow',
            'cancelled', 'canceled', 'refunded', 'failed' => 'red',
            default => 'zinc',
        };
    }

    public function paymentColor(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cash' => 'green',
            'card' => 'blue',
            'gcash', 'maya' => 'sky',
            default => 'zinc',
        };
    }

    public function editPendingTransaction(int $id): void
    {
        $transaction = Transactions::find($id);

        if (!$transaction || $transaction->status !== 'pending') {
            return;
        }

        $this->selectedTransactionId = $transaction->id;
        $this->editIdNumber = (string) $transaction->id_number;
        $this->originalTransactionTotal = (float) $transaction->total_amount;
        $this->currentTransactionTotal = (float) ($transaction->adjusted_total_amount ?? $transaction->total_amount);
        $this->originalDiscountAmount = (float) $transaction->discount_price;
        $this->originalTaxAmount = (float) $transaction->tax;
        $this->availableProducts = ProductItem::query()
            ->whereIn('status', ['available', 'out_of_stock'])
            ->orderBy('name')
            ->get(['id', 'name', 'price'])
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
            ])
            ->all();
        $this->editItems = DB::table('orders')
            ->leftJoin('product_items', 'product_items.id', '=', 'orders.product_id')
            ->where('orders.transaction_id', $transaction->id)
            ->select([
                'orders.id as order_line_id',
                'orders.product_id',
                'product_items.name',
                'product_items.price',
                'product_items.front_quantity',
            ])
            ->orderBy('orders.id')
            ->get()
            ->map(fn ($item) => [
                    'order_line_id' => $item->order_line_id,
                    'product_id' => $item->product_id,
                    'original_product_id' => $item->product_id,
                    'name' => $item->name ?? 'Missing product #' . $item->product_id,
                    'price' => (float) ($item->price ?? 0),
                    'front_quantity' => (int) $item->front_quantity,
                    'quantity' => 1,
                ])
            ->values()
            ->all();

        Flux::modal('edit-pending-transaction')->show();
    }

    public function replacementTotals(): array
    {
        $prices = collect($this->availableProducts)->keyBy('id');
        $replacementItemsTotal = collect($this->editItems)->sum(function ($item) use ($prices) {
            $product = $prices->get($item['product_id']);
            $unitPrice = $product['price'] ?? (
                (int) $item['product_id'] === (int) $item['original_product_id']
                    ? $item['price']
                    : 0
            );

            return (int) $item['quantity'] * (float) $unitPrice;
        });

        $itemAllowance = max(
            0,
            $this->originalTransactionTotal
                + $this->originalDiscountAmount
                - $this->originalTaxAmount
        );
        $appliedDiscount = min($this->originalDiscountAmount, $replacementItemsTotal);
        $projectedTotal = max(
            0,
            $replacementItemsTotal - $appliedDiscount + $this->originalTaxAmount
        );

        return [
            'item_allowance' => $itemAllowance,
            'replacement_items_total' => $replacementItemsTotal,
            'projected_total' => $projectedTotal,
            'estimated_refund' => max(0, $this->originalTransactionTotal - $projectedTotal),
            'exceeds_allowance' => $replacementItemsTotal > $itemAllowance,
        ];
    }

    public function savePendingTransaction(): void
    {
        if (
            ! Schema::hasColumn('transactions', 'adjusted_total_amount')
            || ! Schema::hasTable('transaction_adjustments')
        ) {
            throw ValidationException::withMessages([
                'editItems' => 'Transaction adjustment storage is not installed. Run the outstanding database migrations before saving item changes.',
            ]);
        }

        $this->validate([
            'editIdNumber' => ['required', 'string', 'max:255'],
            'editItems' => ['required', 'array', 'min:1'],
            'editItems.*.product_id' => ['required', 'integer', 'exists:product_items,id'],
            'editItems.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () {
            $transaction = Transactions::query()
                ->whereKey($this->selectedTransactionId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $productIds = collect($this->editItems)->pluck('product_id')->all();
            $products = ProductItem::whereIn('id', $productIds)->get()->keyBy('id');
            $total = 0;

            foreach ($this->editItems as $item) {
                $product = $products->get($item['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages(['editItems' => 'A selected product no longer exists.']);
                }

                $total += (int) $item['quantity'] * (float) $product->price;
            }

            $original = Transactions::findOrFail($this->selectedTransactionId);
            $targetSubtotal = (float) $original->total_amount
                + (float) $original->discount_price
                - (float) $original->tax;

            if (round($total, 2) > round($targetSubtotal, 2)) {
                throw ValidationException::withMessages([
                    'editItems' => 'Replacement items cannot exceed the original transaction amount.',
                ]);
            }

            $updatedDiscount = min((float) $original->discount_price, $total);
            $updatedTotal = max(0, $total - $updatedDiscount + (float) $original->tax);
            $previousAmount = (float) ($original->adjusted_total_amount ?? $original->total_amount);
            $previousItems = DB::table('orders')
                ->where('transaction_id', $transaction->id)
                ->select('product_id', DB::raw('COUNT(*) as quantity'))
                ->groupBy('product_id')
                ->get()
                ->map(fn ($item) => [
                    'product_id' => (int) $item->product_id,
                    'quantity' => (int) $item->quantity,
                ])
                ->all();
            $adjustedItems = collect($this->editItems)
                ->groupBy('product_id')
                ->map(fn ($items, $productId) => [
                    'product_id' => (int) $productId,
                    'quantity' => $items->sum(fn ($item) => (int) $item['quantity']),
                ])
                ->values()
                ->all();

            DB::table('orders')->where('transaction_id', $transaction->id)->delete();

            foreach ($this->editItems as $item) {
                for ($quantity = 0; $quantity < (int) $item['quantity']; $quantity++) {
                    DB::table('orders')->insert([
                        'transaction_id' => $transaction->id,
                        'product_id' => $item['product_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $transaction->update([
                'id_number' => trim($this->editIdNumber),
                'adjusted_total_amount' => round($updatedTotal, 2),
            ]);

            DB::table('transaction_adjustments')->insert([
                'transaction_id' => $transaction->id,
                'user_id' => auth()->id(),
                'previous_amount' => round($previousAmount, 2),
                'adjusted_amount' => round($updatedTotal, 2),
                'refund_amount' => round(max(0, $previousAmount - $updatedTotal), 2),
                'previous_items' => json_encode($previousItems, JSON_THROW_ON_ERROR),
                'adjusted_items' => json_encode($adjustedItems, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Flux::modal('view-transaction')->close();
        Flux::modal('edit-pending-transaction')->close();
        $this->resetTransactionForm();
        unset($this->orders, $this->selectedTransactionDetails, $this->selectedTransactionItems, $this->selectedTransactionAdjustments);
        Flux::toast(variant: 'success', text: 'Transaction items updated and refund adjustment recorded.');
    }

    public function finalizeTransaction(): void
    {
        if (!in_array(auth()->user()->role, ['admin', 'cashier'], true)) {
            abort(403);
        }

        $this->validate([
            'paymentMethod' => ['required', 'string', 'max:255'],
            'referenceNumber' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () {
            $transaction = Transactions::query()
                ->whereKey($this->selectedTransactionId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $orders = DB::table('orders')
                ->where('transaction_id', $transaction->id)
                ->select('product_id', DB::raw('COUNT(*) as quantity'))
                ->groupBy('product_id')
                ->get();

            $products = ProductItem::whereIn('id', $orders->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($orders as $order) {
                $product = $products->get($order->product_id);

                if (!$product || $product->status !== 'available' || (int) $product->front_quantity < (int) $order->quantity) {
                    throw ValidationException::withMessages([
                        'paymentMethod' => 'The slip cannot be paid because front inventory has changed.',
                    ]);
                }
            }

            foreach ($orders as $order) {
                $product = $products->get($order->product_id);
                $frontQuantity = (int) $product->front_quantity - (int) $order->quantity;
                $totalQuantity = $frontQuantity + (int) $product->warehouse_quantity;

                $product->update([
                    'front_quantity' => $frontQuantity,
                    'quantity' => $totalQuantity,
                    'status' => $totalQuantity > 0 ? 'available' : 'out_of_stock',
                ]);
            }

            $transaction->update([
                'cashier_id' => auth()->id(),
                'payment_method' => $this->paymentMethod,
                'reference_num' => trim($this->referenceNumber) ?: null,
                'cash' => 0,
                'change' => 0,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        });

        Flux::modal('finalize-transaction')->close();
        $this->resetTransactionForm();
        unset($this->orders);
        Flux::toast(variant: 'success', text: 'Transaction payment completed.');
    }

    public function openFinalizeTransaction(int $id): void
    {
        $transaction = Transactions::find($id);

        if (!$transaction || $transaction->status !== 'pending') {
            return;
        }

        $this->selectedTransactionId = $transaction->id;
        $this->paymentMethod = 'Cash';
        $this->referenceNumber = '';
        Flux::modal('finalize-transaction')->show();
    }

    public function resetTransactionForm(): void
    {
        $this->selectedTransactionId = null;
        $this->editIdNumber = '';
        $this->editItems = [];
        $this->originalTransactionTotal = 0;
        $this->currentTransactionTotal = 0;
        $this->originalDiscountAmount = 0;
        $this->originalTaxAmount = 0;
        $this->paymentMethod = 'Cash';
        $this->referenceNumber = '';
        $this->availableProducts = [];
    }

    public function startTransactionAdjustment(int $id): void
    {
        Flux::modal('view-transaction')->close();
        $this->editPendingTransaction($id);
    }

    public function startTransactionFinalization(int $id): void
    {
        Flux::modal('view-transaction')->close();
        $this->openFinalizeTransaction($id);
    }
};
?>

<div>
<div class="flex flex-col gap-4">
    <div class="flex flex-col">
        <p class="text-xl font-bold">Transactions</p>
        <p>Review and end student purchases</p>
    </div>

    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-3">
        <flux:field>
            <flux:label>From date</flux:label>
            <flux:input wire:model.live="dateFrom" type="date" />
        </flux:field>
        <flux:field>
            <flux:label>To date</flux:label>
            <flux:input wire:model.live="dateTo" type="date" />
        </flux:field>
        <flux:field>
            <flux:label>Status</flux:label>
            <flux:select wire:model.live="statusFilter">
                <flux:select.option value="all">All statuses</flux:select.option>
                <flux:select.option value="pending">Pending</flux:select.option>
                <flux:select.option value="paid">Paid</flux:select.option>
                <flux:select.option value="completed">Completed</flux:select.option>
                <flux:select.option value="refunded">Refunded</flux:select.option>
                <flux:select.option value="void">Void</flux:select.option>
            </flux:select>
        </flux:field>
    </div>

    @if ($dateFrom !== '' && $dateTo !== '' && $dateTo < $dateFrom)
        <flux:callout variant="warning">The end date must be on or after the start date.</flux:callout>
    @endif

    <flux:table :paginate="$this->orders">
        <flux:table.columns>
            <flux:table.column>Cashier</flux:table.column>
            <flux:table.column>Student ID</flux:table.column>
            <flux:table.column>Payment Method</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Date</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'status'" :direction="$sortDirection" wire:click="sort('status')">Status</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'total_amount'" :direction="$sortDirection" wire:click="sort('total_amount')">Amount</flux:table.column>
            <flux:table.column>Actions</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->orders as $order)
                @php $cashier = trim($order->cashier_first_name . ' ' . $order->cashier_last_name) ?: 'Unknown'; @endphp

                <flux:table.row :key="$order->id">
                    <flux:table.cell class="flex items-center gap-3">
                        <flux:avatar size="xs" :name="$cashier" />
                        {{ $cashier }}
                    </flux:table.cell>

                    <flux:table.cell class="whitespace-nowrap">
                        {{ $order->id_number ?: '—' }}
                    </flux:table.cell>

                    <flux:table.cell class="py-0">
                        <flux:badge size="sm" :color="$this->paymentColor($order->payment_method)">
                            {{ ucwords($order->payment_method) }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell class="whitespace-nowrap">
                        {{ $order->created_at->format('M d, Y') }}
                    </flux:table.cell>

                    <flux:table.cell class="py-0">
                        <flux:badge size="sm" :color="$this->statusColor($order->status)">
                            {{ ucwords($order->status) }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell variant="strong" class="whitespace-nowrap">
                        PHP {{ number_format($order->total_amount, 2) }}
                        @if ($order->adjusted_total_amount !== null)
                            <span class="mt-1 block text-xs font-normal text-zinc-500">
                                Payable: PHP {{ number_format($order->adjusted_total_amount, 2) }}
                            </span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell class="whitespace-nowrap">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button
                                icon="ellipsis-horizontal"
                                variant="ghost"
                                size="sm"
                                square
                                aria-label="Transaction actions"
                            />

                            <flux:menu>
                                <flux:menu.item
                                    wire:click="viewTransaction({{ $order->id }})"
                                    icon="eye"
                                >
                                    View transaction
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                        <flux:table.cell colspan="7" class="text-center text-zinc-500">
                        No transactions yet.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>

<flux:modal name="view-transaction" class="max-w-2xl w-full">
    @if ($transaction = $this->selectedTransactionDetails)
        <div class="flex max-h-[85vh] flex-col gap-5 overflow-y-auto pr-1">
            <div class="flex items-start justify-between gap-4 pr-10">
                <div>
                    <p class="text-lg font-semibold">Transaction #{{ $transaction->id }}</p>
                    <p class="text-sm text-zinc-500">Student ID: {{ $transaction->id_number ?: '—' }}</p>
                </div>
                <flux:badge size="sm" :color="$this->statusColor($transaction->status)">{{ ucwords($transaction->status) }}</flux:badge>
            </div>

            <div class="grid grid-cols-2 gap-3 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <div>
                    <p class="text-zinc-500">Original transaction total</p>
                    <p class="mt-1 font-semibold">₱{{ number_format($transaction->total_amount, 2) }}</p>
                </div>
                <div>
                    <p class="text-zinc-500">{{ $transaction->status === 'pending' ? 'Current payable amount' : 'Recorded payment' }}</p>
                    <p class="mt-1 font-semibold">₱{{ number_format($transaction->adjusted_total_amount ?? $transaction->total_amount, 2) }}</p>
                </div>
                <div>
                    <p class="text-zinc-500">Payment method</p>
                    <p class="mt-1 font-medium">{{ $transaction->payment_method ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-zinc-500">Date</p>
                    <p class="mt-1 font-medium">{{ $transaction->created_at?->format('M d, Y h:i A') }}</p>
                </div>
            </div>

            <section>
                <h3 class="text-sm font-semibold">Items</h3>
                <div class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->selectedTransactionItems as $item)
                        <div class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                            <span>{{ $item->name ?? 'Deleted product #'.$item->product_id }}</span>
                            <span class="font-medium">× {{ $item->quantity }}</span>
                        </div>
                    @empty
                        <p class="px-4 py-5 text-sm text-zinc-500">No items linked to this transaction.</p>
                    @endforelse
                </div>
            </section>

            @if ($this->selectedTransactionAdjustments->isNotEmpty())
                <section>
                    <h3 class="text-sm font-semibold">Replacement and refund log</h3>
                    <div class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($this->selectedTransactionAdjustments as $adjustment)
                            <div class="px-4 py-3 text-sm">
                                <div class="flex justify-between gap-3">
                                    <span>₱{{ number_format($adjustment->previous_amount, 2) }} → ₱{{ number_format($adjustment->adjusted_amount, 2) }}</span>
                                    <time class="text-xs text-zinc-500">{{ \Illuminate\Support\Carbon::parse($adjustment->created_at)->format('M d, Y h:i A') }}</time>
                                </div>
                                <p class="mt-1 text-zinc-500">Refund adjustment: ₱{{ number_format($adjustment->refund_amount, 2) }} · {{ trim(($adjustment->first_name ?? '').' '.($adjustment->last_name ?? '')) ?: 'Unknown user' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if ($transaction->status === 'pending')
                    <flux:button variant="outline" icon="arrow-path" wire:click="startTransactionAdjustment({{ $transaction->id }})">Refund / replace items</flux:button>
                    @if (in_array(auth()->user()->role, ['admin', 'cashier'], true))
                        <flux:button variant="primary" icon="check" wire:click="startTransactionFinalization({{ $transaction->id }})">Complete transaction</flux:button>
                    @endif
                @endif
                <flux:button variant="ghost" wire:click="closeTransactionDetails">Close</flux:button>
            </div>
        </div>
    @endif
</flux:modal>

<flux:modal name="edit-pending-transaction" class="max-w-xl w-full">
    @php($replacementTotals = $this->replacementTotals())
    <div class="flex flex-col gap-5">
        <div>
                <p class="text-lg font-semibold">Refund / Replace Items</p>
            <p class="text-sm text-zinc-500">Replace individual items. Any difference below the original is the estimated refund; the transaction cannot be increased or voided.</p>
        </div>

        <flux:input wire:model="editIdNumber" label="Student ID Number" />

        @forelse ($editItems as $itemKey => $item)
            <div wire:key="replacement-item-{{ $itemKey }}" class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-medium">{{ $item['name'] }}</p>
                    <p class="text-sm text-zinc-500">Individual item</p>
                </div>
                <flux:select wire:model.live="editItems.{{ $itemKey }}.product_id" label="Replace with">
                    @if (!collect($availableProducts)->contains('id', $item['product_id']))
                        <flux:select.option value="{{ $item['product_id'] }}" disabled>
                            {{ $item['name'] }} (unavailable)
                        </flux:select.option>
                    @endif
                    @foreach ($availableProducts as $product)
                        <flux:select.option value="{{ $product['id'] }}">{{ $product['name'] }} (PHP {{ number_format($product['price'], 2) }})</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @empty
            <p class="text-sm text-red-600">No items are linked to this slip, so there is nothing to replace.</p>
        @endforelse

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex justify-between gap-4 text-sm">
                <span class="text-zinc-500">Original transaction total</span>
                <span class="font-medium">₱{{ number_format($originalTransactionTotal, 2) }}</span>
            </div>
            <div class="mt-2 flex justify-between gap-4 text-sm">
                <span class="text-zinc-500">Current payable amount</span>
                <span class="font-medium">₱{{ number_format($currentTransactionTotal, 2) }}</span>
            </div>
            <div class="mt-2 flex justify-between gap-4 text-sm">
                <span class="text-zinc-500">Replacement items total</span>
                <span class="font-medium">₱{{ number_format($replacementTotals['replacement_items_total'], 2) }} / ₱{{ number_format($replacementTotals['item_allowance'], 2) }} allowed</span>
            </div>
            <div class="mt-2 flex justify-between gap-4 text-sm">
                <span class="text-zinc-500">New transaction total</span>
                <span class="font-medium">₱{{ number_format($replacementTotals['projected_total'], 2) }}</span>
            </div>
            <div class="mt-2 flex justify-between gap-4 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                <span class="font-semibold">Estimated adjustment / refund</span>
                <span class="font-bold">₱{{ number_format($replacementTotals['estimated_refund'], 2) }}</span>
            </div>
            @if ($replacementTotals['exceeds_allowance'])
                <p class="mt-2 text-sm text-red-600">Replacement items exceed the original amount. Choose lower-priced items.</p>
            @endif
        </div>

        <flux:button
            wire:click="savePendingTransaction"
            variant="primary"
            :disabled="$replacementTotals['exceeds_allowance'] || empty($editItems)"
        >Replace Items</flux:button>
    </div>
</flux:modal>

<flux:modal name="finalize-transaction" class="max-w-md w-full">
    <div class="flex flex-col gap-5">
        <div>
            <p class="text-lg font-semibold">Complete Transaction</p>
            <p class="text-sm text-zinc-500">Record the payment and deduct the sold items from front inventory.</p>
        </div>

        <flux:select wire:model="paymentMethod" label="Payment Method">
            <flux:select.option value="Cash">Cash</flux:select.option>
            <flux:select.option value="GCash">GCash</flux:select.option>
            <flux:select.option value="Card">Card</flux:select.option>
            <flux:select.option value="Bank Transfer">Bank Transfer</flux:select.option>
        </flux:select>

        <flux:input wire:model="referenceNumber" label="Reference Number" />
        <flux:button wire:click="finalizeTransaction" variant="primary">Complete transaction</flux:button>
    </div>
</flux:modal>
</div>