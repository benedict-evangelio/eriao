<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use App\Models\ProductItem;
use App\Models\ProductCategories;
use App\Models\PurchaseOrderItems;
use Flux\Flux;

new class extends Component
{
    public $productItems;

    public $categories;

    public ?int $selectedProductId = null;

    public string $productName = '';

    public string $productCategory = '';

    public $productPrice = 0;

    public $productQuantity = 0;

    public $productMax = 0;

    public string $productStatus = 'available';
    public $summary = [];

    public function mount()
    {
        $this->loadProducts();
        $this->loadCategories();
    }

    public function loadProducts()
    {
        $this->productItems = ProductItem::query()
            ->leftJoin(
                'product_categories',
                'product_categories.id',
                '=',
                'product_items.category_id'
            )
            ->select([
                'product_items.*',
                'product_categories.name as product_category_name',
            ])
            ->orderBy('product_items.name')
            ->get();
    }

    public function loadCategories()
    {
        $this->categories = ProductCategories::query()
            ->orderBy('name')
            ->get();
    }

    #[On('onRefreshProducts')]
    public function refreshProducts()
    {
        $this->loadProducts();
    }

    #[Renderless]
    public function onStockIn($id)
    {
        $this->dispatch('onLoadProductID', id: $id);
    }

    #[Renderless]
    public function onStockOut($id)
    {
        $this->dispatch('onLoadProductIDStockOut', id: $id);
    }

    #[Renderless]
    public function onUpdateProduct(int $id)
    {
        $product = ProductItem::find($id);

        if (!$product) {
            return;
        }

        $this->selectedProductId = $product->id;
        $this->productName = $product->name;
        $this->productCategory = (string) $product->category_id;
        $this->productPrice = $product->price;
        $this->productQuantity = $product->quantity;
        $this->productMax = $product->max;
        $this->productStatus = $product->status;

        Flux::modal('update-item')->show();
    }

    public function updateProduct()
    {
        $this->validate([
            'selectedProductId' => [
                'required',
                'exists:product_items,id',
            ],
            'productName' => [
                'required',
                'string',
                'max:255',
            ],
            'productCategory' => [
                'required',
                'exists:product_categories,id',
            ],
            'productPrice' => [
                'required',
                'numeric',
                'min:0',
            ],
            'productMax' => [
                'required',
                'integer',
                'min:1',
            ],
            'productStatus' => [
                'required',
                'in:available,unavailable,out_of_stock',
            ],
        ]);

        $product = ProductItem::find($this->selectedProductId);

        if (!$product) {
            $this->closeUpdateModal();
            return;
        }

        if ((int) $product->quantity > (int) $this->productMax) {
            $this->productMax = $product->quantity;
        }

        $product->update([
            'name' => $this->productName,
            'category_id' => $this->productCategory,
            'price' => $this->productPrice,
            'max' => $this->productMax,
            'status' => $product->quantity > 0
                ? (
                    $this->productStatus === 'out_of_stock'
                        ? 'available'
                        : $this->productStatus
                )
                : $this->productStatus,
        ]);

        $this->loadProducts();

        $this->dispatch('onRefreshProducts');

        $this->closeUpdateModal();
    }

    public function onDeleteProduct(int $id)
    {
        $product = ProductItem::find($id);

        if (!$product) {
            return;
        }

        $this->selectedProductId = $product->id;

        Flux::modal('delete-item')->show();
    }

    public function deleteProduct()
    {
        if (!$this->selectedProductId) {
            return;
        }

        $product = ProductItem::find($this->selectedProductId);

        if (!$product) {
            $this->closeDeleteModal();
            return;
        }

        $hasPurchaseOrders = PurchaseOrderItems::query()
            ->where('product_id', $product->id)
            ->exists();

        if ($hasPurchaseOrders) {
            Flux::modal('delete-item')->close();
            Flux::modal('delete-item-error')->show();
            return;
        }

        $product->delete();

        $this->loadProducts();

        $this->dispatch('onRefreshProducts');

        $this->closeDeleteModal();
    }

    public function closeUpdateModal()
    {
        Flux::modal('update-item')->close();

        $this->resetProductForm();
    }

    public function closeDeleteModal()
    {
        Flux::modal('delete-item')->close();

        $this->selectedProductId = null;
    }

    public function closeDeleteErrorModal()
    {
        Flux::modal('delete-item-error')->close();

        $this->selectedProductId = null;
    }

    public function resetProductForm()
    {
        $this->selectedProductId = null;

        $this->productName = '';
        $this->productCategory = '';
        $this->productPrice = 0;
        $this->productQuantity = 0;
        $this->productMax = 0;
        $this->productStatus = 'available';

        $this->resetValidation();
    }
};
?>

<div class="flex flex-col gap-4 w-full">

    <div class="flex flex-row items-center">

        <div class="flex flex-col">
            <p class="text-xl font-bold">Inventory</p>
            <p>Manage your Inventory</p>
        </div>

        <flux:spacer />

        @if (Auth::user()->role === 'admin' || Auth::user()->role === 'employee')
            <flux:modal.trigger name="new-item">
                <flux:button
                    variant="primary"
                    icon="plus"
                    class="bg-primary hover:bg-primary"
                >
                    New Item
                </flux:button>
            </flux:modal.trigger>
        @endif

    </div>

    <div class="grid grid-cols-3 gap-4">

        @forelse ($productItems as $product)

            <div
                wire:key="product-{{ $product->id }}"
                class="flex flex-col gap-4 border border-zinc-200 p-6 rounded-xl dark:border-zinc-700"
            >

                <div class="flex flex-row items-start gap-3">

                    <div class="flex flex-col min-w-0 flex-1">

                        <div class="flex items-center gap-2">

                            <span class="font-medium truncate">
                                {{ $product->name }}
                            </span>
                                
                            </x-wirekit::badge>

                            <flux:badge
                                    size="sm"
                                    color="lime"
                                >
                                    {{ $product->product_category_name }}
                                </flux:badge>

                            <div class="flex items-center">

                                @switch($product->status)

                                    @case('available')

                                        <flux:badge
                                            size="sm"
                                            color="green"
                                        >
                                            Available
                                        </flux:badge>

                                        @break

                                    @case('unavailable')

                                        <flux:badge
                                            size="sm"
                                            color="red"
                                        >
                                            Unavailable
                                        </flux:badge>

                                        @break

                                    @case('out_of_stock')

                                        <flux:badge
                                            size="sm"
                                            color="amber"
                                        >
                                            Out of Stock
                                        </flux:badge>

                                        @break

                                    @default

                                        <flux:badge
                                            size="sm"
                                            color="zinc"
                                        >
                                            {{ ucwords(str_replace('_', ' ', $product->status)) }}
                                        </flux:badge>

                                @endswitch

                            </div>

                        </div>

                        <span class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                            PHP {{ number_format($product->price, 2) }} per item
                        </span>

                    </div>

                    @if (Auth::user()->role === 'admin' || Auth::user()->role === 'employee' || Auth::user()->role === 'staff')
                        <flux:dropdown
                            position="bottom"
                            align="end"
                        >

                            <flux:button
                                icon="ellipsis-horizontal"
                                variant="ghost"
                                size="sm"
                                square
                                aria-label="Inventory item actions"
                            />

                            <flux:menu>

                                <flux:menu.item
                                    wire:click="onUpdateProduct({{ $product->id }})"
                                    icon="pencil"
                                >
                                    Update
                                </flux:menu.item>

                                <flux:menu.item
                                    wire:click="onDeleteProduct({{ $product->id }})"
                                    icon="trash"
                                    variant="danger"
                                >
                                    Delete
                                </flux:menu.item>

                            </flux:menu>

                        </flux:dropdown>
                    @endif

                </div>

                <x-wirekit::progress
                    :value="intval($product->quantity)"
                    :max="intval($product->max)"
                    intent="success"
                    label="Quantity"
                    show-value
                />


                @if(Auth::user()->role === 'admin' || Auth::user()->role === 'employee' || Auth::user()->role === 'staff')
                    <div class="grid grid-cols-2 gap-2">

                        <flux:modal.trigger name="stock-in">

                            <flux:button
                                wire:click="onStockIn({{ $product->id }})"
                                variant="outline"
                                class="w-full"
                            >
                                Stock In
                            </flux:button>

                        </flux:modal.trigger>

                        <flux:modal.trigger name="stock-out">

                            <flux:button
                                wire:click="onStockOut({{ $product->id }})"
                                variant="outline"
                                class="w-full"
                            >
                                Stock Out
                            </flux:button>

                        </flux:modal.trigger>

                    </div>
                @endif

            </div>

        @empty

            <div class="col-span-3">

                <div class="flex flex-col items-center justify-center py-16 border border-dashed border-zinc-300 rounded-xl dark:border-zinc-700">

                    <flux:icon
                        name="cube"
                        class="size-10 text-zinc-400"
                    />

                    <p class="mt-3 text-sm font-medium">
                        No inventory items
                    </p>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Create your first inventory item to get started.
                    </p>

                </div>

            </div>

        @endforelse

    </div>

    <flux:modal
        name="update-item"
        class="max-w-xl w-full"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-xl font-bold">
                    Update Item
                </p>

                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Update inventory item information.
                </p>

            </div>

            <div class="flex flex-col gap-4">

                <flux:input
                    wire:model="productName"
                    label="Name"
                    placeholder="Product name"
                />

                <flux:select
                    wire:model="productCategory"
                    label="Category"
                    placeholder="Select category"
                >

                    @foreach ($categories as $category)

                        <flux:select.option value="{{ $category->id }}">
                            {{ $category->name }}
                        </flux:select.option>

                    @endforeach

                </flux:select>

                <div class="grid grid-cols-2 gap-4">

                    <flux:input
                        wire:model="productPrice"
                        type="number"
                        min="0"
                        step="0.01"
                        label="Price"
                    />

                    <flux:input
                        wire:model="productMax"
                        type="number"
                        min="1"
                        label="Maximum Quantity"
                    />

                </div>

                <div class="grid grid-cols-2 gap-4">

                    <flux:input
                        wire:model="productQuantity"
                        type="number"
                        label="Current Quantity"
                        readonly
                    />

                    <flux:select
                        wire:model="productStatus"
                        label="Status"
                        placeholder="Select status"
                    >

                        <flux:select.option value="available">
                            Available
                        </flux:select.option>

                        <flux:select.option value="unavailable">
                            Unavailable
                        </flux:select.option>

                        <flux:select.option value="out_of_stock">
                            Out of Stock
                        </flux:select.option>

                    </flux:select>

                </div>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>

                    <flux:button
                        variant="ghost"
                        wire:click="resetProductForm"
                    >
                        Cancel
                    </flux:button>

                </flux:modal.close>

                <flux:button
                    wire:click="updateProduct"
                    variant="primary"
                >
                    Update
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <flux:modal
        name="delete-item"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Delete inventory item?
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Are you sure you want to delete this inventory item?
                    This action cannot be undone.
                </p>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>

                    <flux:button
                        variant="ghost"
                        wire:click="$set('selectedProductId', null)"
                    >
                        Cancel
                    </flux:button>

                </flux:modal.close>

                <flux:button
                    wire:click="deleteProduct"
                    variant="danger"
                >
                    Delete
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <flux:modal
        name="delete-item-error"
        class="min-w-[22rem] max-w-md"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-lg font-semibold">
                    Item cannot be deleted
                </p>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    This inventory item is already associated with one or more purchase orders.
                    It cannot be deleted because those purchase-order records depend on this product.
                </p>

            </div>

            <div class="flex justify-end">

                <flux:button
                    wire:click="closeDeleteErrorModal"
                    variant="primary"
                >
                    Close
                </flux:button>

            </div>

        </div>

    </flux:modal>

    <livewire:modals.inventory.new-item />
    <livewire:modals.inventory.stock-in />
    <livewire:modals.inventory.stock-out />

</div>
