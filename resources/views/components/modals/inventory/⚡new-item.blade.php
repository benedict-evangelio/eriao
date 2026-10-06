<?php

use App\Models\ProductCategories;
use App\Models\ProductItem;
use Flux\Flux;
use Livewire\Component;

new class extends Component
{
    public $productCategories = [];

    public $name;

    public $category;

    public $price;

    public $quantity;

    public $max;

    public $reorderLevel;

    public $frontQuantity;

    public $warehouseQuantity;

    public $status;

    public $newCategoryName = '';

    public function mount()
    {
        $this->refreshCategories();
        $this->status = 'available';
        $this->price = 0;
        $this->quantity = 0;
        $this->max = 0;
        $this->reorderLevel = 0;
        $this->frontQuantity = 0;
        $this->warehouseQuantity = 0;
    }

    public function refreshCategories()
    {
        $this->productCategories = ProductCategories::orderBy('name')->get();

        if (blank($this->category) || ! $this->productCategories->contains('id', $this->category)) {
            $this->category = $this->productCategories->first()?->id;
        }
    }

    public function onSaveProduct()
    {
        $this->validate([
            'name' => 'required',
            'category' => 'required',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required',
            'max' => 'required',
            'reorderLevel' => 'required|integer|min:0',
            'frontQuantity' => 'required|integer|min:0',
            'warehouseQuantity' => 'required|integer|min:0',
            'status' => 'required',
        ]);

        if (blank($this->category)) {
            $this->addError('category', 'Please select a category or add a new one.');

            return;
        }

        ProductItem::create([
            'name' => $this->name,
            'category_id' => $this->category,
            'price' => $this->price,
            'quantity' => (int) $this->frontQuantity + (int) $this->warehouseQuantity,
            'max' => $this->max,
            'reorder_level' => $this->reorderLevel,
            'front_quantity' => $this->frontQuantity,
            'warehouse_quantity' => $this->warehouseQuantity,
            'status' => $this->status,
        ]);

        $this->dispatch('onRefreshProducts');
        $this->onClose();
    }

    public function openNewCategoryModal()
    {
        $this->newCategoryName = '';
        Flux::modal('new-category')->show();
    }

    public function onCreateCategory()
    {
        $this->validate([
            'newCategoryName' => ['required', 'string', 'min:2'],
        ]);

        $category = ProductCategories::firstOrCreate([
            'name' => trim($this->newCategoryName),
        ]);

        $this->newCategoryName = '';
        $this->refreshCategories();
        $this->category = $category->id;
        Flux::modal('new-category')->close();
    }

    public function closeNewCategoryModal()
    {
        $this->newCategoryName = '';
        Flux::modal('new-category')->close();
    }

    public function onClose()
    {
        Flux::modal('new-item')->close();
    }

    public function onReset()
    {
        $this->name = '';
        $this->category = $this->productCategories->first()?->id;
        $this->price = 0;
        $this->quantity = 0;
        $this->max = 0;
        $this->reorderLevel = 0;
        $this->frontQuantity = 0;
        $this->warehouseQuantity = 0;
        $this->status = 'available';
        $this->newCategoryName = '';
    }
};
?>

<div>
    <flux:modal name="new-item" class="max-w-1/3 w-full" @close="onReset">
        <div class="flex flex-col">
            <p class="text-xl font-bold">New Item</p>
            <p>Create new item</p>

            <div class="flex flex-col gap-4 mt-4">
                <flux:input wire:model='name' label="Name" />

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex items-end gap-2">
                        <flux:select
                            wire:model='category'
                            label="Category"
                            placeholder="Select Category"
                            class="min-w-0 flex-1"
                        >
                            @foreach ($productCategories as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button wire:click='openNewCategoryModal' variant="outline" size="sm">Add category</flux:button>
                    </div>

                    <flux:input wire:model='quantity' type="number" label="Quantity" />
                    <flux:input wire:model='price' type="number" min="0" step="0.01" label="Price" />

                    <flux:input wire:model='max' type="number" label="Maximum Quantity" />
                    <flux:input wire:model='reorderLevel' type="number" min="0" label="Re-order Level" />
                    <flux:input wire:model='frontQuantity' type="number" min="0" label="Front Inventory" />
                    <flux:input wire:model='warehouseQuantity' type="number" min="0" label="Warehouse Inventory" />
                    <flux:select wire:model='status' label="Status" placeholder="Status">
                        <flux:select.option value="available">Available</flux:select.option>
                        <flux:select.option value="unavailable">Unavailable</flux:select.option>
                        <flux:select.option value="out_of_stock">Out of Stock</flux:select.option>
                        <flux:select.option value="defective">Defective</flux:select.option>
                    </flux:select>
                </div>

                <div class="grid grid-cols-2 gap-4 mt-8">
                    <flux:button wire:click='onClose' variant="ghost">Cancel</flux:button>
                    <flux:button wire:click='onSaveProduct' variant="primary">Save</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="new-category" class="max-w-md w-full">
        <div class="flex flex-col gap-6">
            <div>
                <p class="text-xl font-bold">Add New Category</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Create a category for your inventory items.</p>
            </div>

            <flux:input wire:model='newCategoryName' label="Category Name" placeholder="Enter category name" />

            <div class="grid grid-cols-2 gap-4">
                <flux:button wire:click='closeNewCategoryModal' variant="ghost">Cancel</flux:button>
                <flux:button wire:click='onCreateCategory' variant="primary">Save</flux:button>
            </div>
        </div>
    </flux:modal>
</div>