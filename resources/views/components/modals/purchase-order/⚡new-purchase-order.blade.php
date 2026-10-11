<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\ProductItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItems;
use Illuminate\Support\Facades\DB;
use Flux\Flux;

new class extends Component
{
    public string $search = '';

    public array $orderItems = [];

    #[Computed]
    public function products()
    {
        return ProductItem::query()
            ->when(
                trim($this->search) !== '',
                fn ($query) =>
                    $query->where('name', 'like', '%' . trim($this->search) . '%')
            )
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedProducts()
    {
        if (empty($this->orderItems)) {
            return collect();
        }

        $productIds = array_keys($this->orderItems);

        $products = ProductItem::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        return collect($productIds)
            ->map(function ($productId) use ($products) {
                $product = $products->get($productId);

                if (!$product) {
                    return null;
                }

                $quantity = max(
                    1,
                    (int) ($this->orderItems[$productId]['quantity'] ?? 1)
                );

                $unitPrice = (float) $product->price;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $quantity * $unitPrice,
                    'status' => $product->status,
                ];
            })
            ->filter()
            ->values();
    }

    #[Computed]
    public function orderSummary(): array
    {
        $items = $this->selectedProducts;

        $totalQuantity = $items->sum('quantity');
        $totalAmount = $items->sum('line_total');

        return [
            'product_count' => $items->count(),
            'total_quantity' => $totalQuantity,
            'total_amount' => $totalAmount,
        ];
    }

    public function addProduct(int $productId): void
    {
        $product = ProductItem::query()
            ->find($productId);

        if (!$product) {
            return;
        }

        if (isset($this->orderItems[$productId])) {
            return;
        }

        $this->orderItems[$productId] = [
            'quantity' => 1,
        ];
    }

    public function removeProduct(int $productId): void
    {
        unset($this->orderItems[$productId]);
    }

    public function onSavePurchaseOrder(): void
    {
        $this->validate([
            'orderItems' => [
                'required',
                'array',
                'min:1',
            ],
            'orderItems.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ], [
            'orderItems.required' => 'Please add at least one product.',
            'orderItems.min' => 'Please add at least one product.',
            'orderItems.*.quantity.required' => 'Quantity is required.',
            'orderItems.*.quantity.integer' => 'Quantity must be a whole number.',
            'orderItems.*.quantity.min' => 'Quantity must be at least 1.',
        ]);

        $productIds = array_keys($this->orderItems);

        $products = ProductItem::query()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            $this->addError(
                'orderItems',
                'One or more selected products are no longer available.'
            );

            return;
        }

        $totalQuantity = 0;
        $totalAmount = 0;

        foreach ($productIds as $productId) {
            $quantity = (int) $this->orderItems[$productId]['quantity'];
            $unitPrice = (float) $products[$productId]->price;

            $totalQuantity += $quantity;
            $totalAmount += $quantity * $unitPrice;
        }

        DB::transaction(function () use (
            $productIds,
            $products,
            $totalQuantity,
            $totalAmount
        ) {
            $purchaseOrder = new PurchaseOrder();

            $purchaseOrder->requested_by = auth()->id();
            $purchaseOrder->approved_by = null;
            $purchaseOrder->quantity = $totalQuantity;
            $purchaseOrder->total_amount = $totalAmount;
            $purchaseOrder->status = 'pending';

            $purchaseOrder->save();

            foreach ($productIds as $productId) {
                $quantity = (int) $this->orderItems[$productId]['quantity'];
                $unitPrice = (float) $products[$productId]->price;

                $purchaseOrderItem = new PurchaseOrderItems();

                $purchaseOrderItem->purchase_order_id = $purchaseOrder->id;
                $purchaseOrderItem->product_id = $productId;
                $purchaseOrderItem->quantity = $quantity;
                $purchaseOrderItem->unit_price = $unitPrice;

                $purchaseOrderItem->save();
            }
        });

        $this->dispatch('onRefreshAllProducts');

        $this->onReset();

        Flux::modal('new-purchase-order')->close();
        Flux::toast(variant: 'success', text: 'Purchase order created.');
    }

    public function onReset(): void
    {
        $this->search = '';
        $this->orderItems = [];

        $this->resetValidation();
    }

    public function onClose(): void
    {
        $this->onReset();

        Flux::modal('new-purchase-order')->close();
    }
};
?>

<flux:modal
    name="new-purchase-order"
    class="max-w-4xl w-full"
    @close="$wire.onReset()"
>
    <div class="flex flex-col">

        <div>
            <p class="text-xl font-bold">
                New Purchase Order
            </p>

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Select multiple products and specify the quantity needed.
            </p>
        </div>

        <div class="mt-6 flex flex-col gap-6">

            <div class="flex flex-col gap-2">

                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    placeholder="Search products..."
                    label="Products"
                />

                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

                    <div class="max-h-[60vh] overflow-y-auto overscroll-contain">

                        @forelse ($this->products as $product)

                            <div
                                wire:key="search-product-{{ $product->id }}"
                                class="flex items-center justify-between gap-4 border-b border-zinc-100 px-4 py-3 last:border-b-0 dark:border-zinc-800"
                            >

                                <div class="min-w-0 flex-1">

                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $product->name }}
                                        </p>

                                        @if ($product->status === 'out_of_stock')
                                            <flux:badge
                                                size="sm"
                                                color="amber"
                                            >
                                                Out of Stock
                                            </flux:badge>
                                        @elseif ($product->status === 'defective')
                                            <flux:badge
                                                size="sm"
                                                color="red"
                                            >
                                                Defective
                                            </flux:badge>
                                        @elseif ($product->status === 'unavailable')
                                            <flux:badge
                                                size="sm"
                                                color="zinc"
                                            >
                                                Unavailable
                                            </flux:badge>
                                        @else
                                            <flux:badge
                                                size="sm"
                                                color="green"
                                            >
                                                Available
                                            </flux:badge>
                                        @endif
                                    </div>

                                    <div class="mt-1 flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                        <span>
                                            ₱{{ number_format((float) $product->price, 2) }} / item
                                        </span>

                                        <span>
                                            Stock: {{ number_format($product->quantity) }}
                                        </span>
                                    </div>

                                </div>

                                @if (isset($orderItems[$product->id]))

                                    <flux:button
                                        wire:click="removeProduct({{ $product->id }})"
                                        variant="subtle"
                                        size="sm"
                                        icon="check"
                                    >
                                        Added
                                    </flux:button>

                                @else

                                    <flux:button
                                        wire:click="addProduct({{ $product->id }})"
                                        variant="ghost"
                                        size="sm"
                                    >
                                        Add
                                    </flux:button>

                                @endif

                            </div>

                        @empty

                            <div class="px-4 py-10 text-center">

                                <flux:icon
                                    name="magnifying-glass"
                                    class="mx-auto size-8 text-zinc-400"
                                />

                                <p class="mt-2 text-sm font-medium">
                                    No products found
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    Try searching for a different product.
                                </p>

                            </div>

                        @endforelse

                    </div>

                </div>

                @error('orderItems')
                    <flux:text
                        size="sm"
                        class="text-red-600"
                    >
                        {{ $message }}
                    </flux:text>
                @enderror

            </div>

            @if ($this->selectedProducts->isNotEmpty())

                <div class="flex flex-col gap-3">

                    <div class="flex items-center justify-between">

                        <div>
                            <p class="text-sm font-semibold">
                                Selected Products
                            </p>

                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Set the requested quantity for each product.
                            </p>
                        </div>

                        <flux:badge size="sm">
                            {{ $this->orderSummary['product_count'] }}
                            {{ $this->orderSummary['product_count'] === 1 ? 'product' : 'products' }}
                        </flux:badge>

                    </div>

                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">

                        <div class="max-h-80 overflow-y-auto">

                            @foreach ($this->selectedProducts as $item)

                                <div
                                    wire:key="selected-product-{{ $item['id'] }}"
                                    class="grid grid-cols-12 items-center gap-4 border-b border-zinc-100 px-4 py-4 last:border-b-0 dark:border-zinc-800"
                                >

                                    <div class="col-span-5 min-w-0">

                                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $item['name'] }}
                                        </p>

                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            ₱{{ number_format($item['unit_price'], 2) }} per item
                                        </p>

                                    </div>

                                    <div class="col-span-2">

                                        <flux:input
                                            wire:model.live="orderItems.{{ $item['id'] }}.quantity"
                                            type="number"
                                            min="1"
                                            label="Qty"
                                        />

                                    </div>

                                    <div class="col-span-3">

                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            Line Total
                                        </p>

                                        <p class="mt-1 font-semibold">
                                            ₱{{ number_format($item['line_total'], 2) }}
                                        </p>

                                    </div>

                                    <div class="col-span-2 flex justify-end">

                                        <flux:button
                                            wire:click="removeProduct({{ $item['id'] }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                        />

                                    </div>

                                </div>

                            @endforeach

                        </div>

                    </div>

                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">

                    <div class="flex items-start justify-between gap-6">

                        <div>

                            <p class="text-sm font-semibold">
                                Purchase Order Summary
                            </p>

                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Review the requested products and total amount.
                            </p>

                        </div>

                        <div class="text-right">

                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Total Amount
                            </p>

                            <p class="mt-1 text-2xl font-bold">
                                ₱{{ number_format($this->orderSummary['total_amount'], 2) }}
                            </p>

                        </div>

                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">

                        <div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Products
                            </p>

                            <p class="mt-1 font-semibold">
                                {{ $this->orderSummary['product_count'] }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Total Quantity
                            </p>

                            <p class="mt-1 font-semibold">
                                {{ number_format($this->orderSummary['total_quantity']) }}
                            </p>
                        </div>

                    </div>

                </div>

                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">

                    <div class="flex flex-col gap-2">

                        @foreach ($this->selectedProducts as $item)

                            <div class="flex items-center justify-between gap-4 text-sm">

                                <div class="min-w-0">
                                    <span class="truncate">
                                        {{ $item['name'] }}
                                    </span>

                                    <span class="text-zinc-500">
                                        × {{ $item['quantity'] }}
                                    </span>
                                </div>

                                <span class="font-medium">
                                    ₱{{ number_format($item['line_total'], 2) }}
                                </span>

                            </div>

                        @endforeach

                        <div class="mt-2 flex items-center justify-between border-t border-zinc-200 pt-3 dark:border-zinc-700">

                            <span class="font-semibold">
                                Total Amount
                            </span>

                            <span class="text-lg font-bold">
                                ₱{{ number_format($this->orderSummary['total_amount'], 2) }}
                            </span>

                        </div>

                    </div>

                </div>

            @else

                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700">

                    <flux:icon
                        name="shopping-cart"
                        class="mx-auto size-10 text-zinc-400"
                    />

                    <p class="mt-3 text-sm font-medium">
                        No products selected
                    </p>

                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Search for a product above and click Add.
                    </p>

                </div>

            @endif

            <div class="grid grid-cols-2 gap-4 pt-2">

                <flux:button
                    wire:click="onClose"
                    variant="ghost"
                >
                    Cancel
                </flux:button>

                <flux:button
                    wire:click="onSavePurchaseOrder"
                    variant="primary"
                    :disabled="$this->selectedProducts->isEmpty()"
                    class="bg-primary hover:bg-primary"
                >
                    Create Purchase Order
                </flux:button>

            </div>

        </div>
    </div>
</flux:modal>