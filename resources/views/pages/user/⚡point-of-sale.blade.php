<?php

use Livewire\Component;
use App\Models\ProductItem;
use App\Models\ProductCategories;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

new class extends Component
{
    public $products;
    public $categories;

    public function mount()
    {
        $this->loadProducts();
        $this->loadCategories();
    }

    public function loadProducts()
    {
        $this->products = ProductItem::query()
            ->leftJoin(
                'product_categories',
                'product_categories.id',
                '=',
                'product_items.category_id'
            )
            ->select([
                'product_items.id',
                'product_items.category_id',
                'product_items.name',
                'product_items.front_quantity',
                'product_items.warehouse_quantity',
                'product_items.reorder_level',
                'product_items.max',
                'product_items.price',
                'product_items.status',
                'product_categories.name as category_name',
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

    public function checkout(array $payload)
    {
        $validated = validator($payload, [
            'id_number' => ['required', 'string', 'max:255'],
            'discount_category' => ['nullable', 'string', 'max:255'],
            'discount_price' => ['required', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ])->validate();

        if (!auth()->check()) {
            throw ValidationException::withMessages([
                'payment_method' => 'You must be logged in to process a transaction.',
            ]);
        }

        DB::transaction(function () use ($validated) {
            $items = collect($validated['items']);

            $products = ProductItem::query()
                ->whereIn('id', $items->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $products->get($item['id']);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => 'One of the selected products no longer exists.',
                    ]);
                }

                if ($product->status !== 'available') {
                    throw ValidationException::withMessages([
                        'items' => "{$product->name} is currently unavailable.",
                    ]);
                }

                if ((int) $product->front_quantity < (int) $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient stock for {$product->name}.",
                    ]);
                }
            }

            $total = round((float) $validated['total_amount'], 2);

            $transactionId = DB::table('transactions')->insertGetId([
                'employee_id' => auth()->id(),
                'id_number' => $validated['id_number'],
                'first_name' => '',
                'last_name' => '',
                'payment_method' => 'Pending',
                'reference_num' => null,
                'discount_category' => $validated['discount_category'] ?: null,
                'discount_price' => round((float) $validated['discount_price'], 2),
                'tax' => 0,
                'total_amount' => $total,
                'cash' => 0,
                'change' => 0,
                'notes' => $validated['notes'] ?: null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['id']);
                $quantity = (int) $item['quantity'];

                for ($i = 0; $i < $quantity; $i++) {
                    DB::table('orders')->insert([
                        'transaction_id' => $transactionId,
                        'product_id' => $product->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

            }
        });

        $this->loadProducts();

        $this->dispatch('pos-slip-created');
    }
};
?>

<div
    class="flex flex-col w-full gap-4"
    x-data="{
        products: @js($products->map(fn ($product) => [
            'id' => $product->id,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'category' => $product->category_name ?? 'Uncategorized',
            'quantity' => (int) $product->front_quantity,
            'front_quantity' => (int) $product->front_quantity,
            'warehouse_quantity' => (int) $product->warehouse_quantity,
            'reorder_level' => (int) $product->reorder_level,
            'max' => (int) $product->max,
            'price' => (float) $product->price,
            'status' => $product->status,
        ])->values()),

        search: '',
        category: 'all',

        cart: [],

        customer: {
            id_number: ''
        },

        discount_category: '',
        discount_value: 0,
        discount_type: 'fixed',
        notes: '',
        processing: false,

        get filteredProducts() {
            return this.products.filter(product => {
                const query = this.search.toLowerCase().trim();

                const matchesSearch =
                    !query ||
                    product.name.toLowerCase().includes(query) ||
                    product.category.toLowerCase().includes(query);

                const matchesCategory =
                    this.category === 'all' ||
                    String(product.category_id) === String(this.category);

                return matchesSearch && matchesCategory;
            });
        },

        get subtotal() {
            return this.cart.reduce((total, item) => {
                return total + (item.price * item.quantity);
            }, 0);
        },

        get discountAmount() {
            const value = Number(this.discount_value) || 0;

            if (this.discount_type === 'percentage') {
                return Math.min(
                    this.subtotal,
                    this.subtotal * (value / 100)
                );
            }

            return Math.min(this.subtotal, value);
        },

        get total() {
            return Math.max(
                0,
                this.subtotal - this.discountAmount
            );
        },

        get itemCount() {
            return this.cart.reduce((total, item) => {
                return total + item.quantity;
            }, 0);
        },

        addToCart(product) {
            if (
                product.status !== 'available' ||
                product.quantity <= 0
            ) {
                return;
            }

            const existing = this.cart.find(
                item => item.id === product.id
            );

            if (existing) {
                if (existing.quantity < product.quantity) {
                    existing.quantity++;
                }

                return;
            }

            this.cart.push({
                id: product.id,
                name: product.name,
                category: product.category,
                price: product.price,
                stock: product.quantity,
                quantity: 1
            });
        },

        increase(item) {
            const product = this.products.find(
                product => product.id === item.id
            );

            if (!product) {
                return;
            }

            if (item.quantity < product.quantity) {
                item.quantity++;
            }
        },

        decrease(item) {
            if (item.quantity > 1) {
                item.quantity--;
                return;
            }

            this.removeFromCart(item.id);
        },

        removeFromCart(id) {
            this.cart = this.cart.filter(
                item => item.id !== id
            );
        },

        clearCart() {
            this.cart = [];
        },

        resetCustomer() {
            this.customer.id_number = '';
            this.notes = '';
        },

        resetPayment() {
            this.discount_category = '';
            this.discount_value = 0;
            this.discount_type = 'fixed';
        },

        async processCheckout() {
            if (this.processing) {
                return;
            }

            if (!this.cart.length) {
                this.$dispatch('pos-error', {
                    message: 'Add at least one product to the cart.'
                });

                return;
            }

            if (!this.customer.id_number.trim()) {
                this.$dispatch('pos-error', {
                    message: 'Please enter the student ID number.'
                });

                return;
            }

            this.processing = true;

            const payload = {
                id_number: this.customer.id_number.trim(),
                discount_category: this.discount_category,
                discount_price: Number(
                    this.discountAmount.toFixed(2)
                ),
                total_amount: Number(
                    this.total.toFixed(2)
                ),
                notes: this.notes.trim(),
                items: this.cart.map(item => ({
                    id: item.id,
                    quantity: item.quantity
                }))
            };

            try {
                await this.$wire.checkout(payload);
            } catch (error) {
                this.$dispatch('pos-error', {
                    message:
                        error?.message ||
                        'Unable to complete the transaction.'
                });
            } finally {
                this.processing = false;
            }
        },

        init() {
            this.$wire.on(
                'pos-slip-created',
                () => {
                    this.$flux.modal('checkout').close();
                    this.clearCart();
                    this.resetCustomer();
                    this.resetPayment();

                    this.products = this.products.map(
                        product => ({
                            ...product
                        })
                    );

                    this.$dispatch('pos-success', {
                        message: 'Payment slip created. Send the student to the cashier.'
                    });
                }
            );
        }
    }"
    x-on:pos-success.window="
        $flux.toast({
            heading: 'Payment slip created',
            text: $event.detail.message,
            variant: 'success'
        })
    "
    x-on:pos-error.window="
        $flux.toast({
            heading: 'Unable to complete transaction',
            text: $event.detail.message,
            variant: 'danger'
        })
    "
>
    <div class="flex flex-row items-center">
        <div class="flex flex-col">
            <p class="text-xl font-bold">Point of Sale</p>
            <p>Process sales and manage customer transactions</p>
        </div>

        <flux:spacer />

        <div class="flex items-center gap-2">
            <x-wirekit::badge intent="accent">
                <span
                    x-text="`${itemCount} ${itemCount === 1 ? 'item' : 'items'}`"
                ></span>
            </x-wirekit::badge>

            <flux:button
                variant="outline"
                icon="trash"
                x-on:click="clearCart()"
                x-bind:disabled="cart.length === 0"
            >
                Clear Cart
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 items-start">
        <div class="xl:col-span-8 flex flex-col gap-4">
            <div class="border border-zinc-200 rounded-xl p-4">
                <div class="flex flex-col lg:flex-row gap-3">
                    <div class="flex-1">
                        <flux:input
                            x-model="search"
                            icon="magnifying-glass"
                            placeholder="Search products..."
                            autocomplete="off"
                        />
                    </div>

                    <div class="w-full lg:w-56">
                        <flux:select x-model="category">
                            <flux:select.option value="all">
                                All Categories
                            </flux:select.option>

                            @foreach ($categories as $categoryItem)
                                <flux:select.option value="{{ $categoryItem->id }}">
                                    {{ $categoryItem->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <template
                    x-for="product in filteredProducts"
                    :key="product.id"
                >
                    <button
                        type="button"
                        class="group text-left border border-zinc-200 rounded-xl p-5 transition hover:border-zinc-400 hover:shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                        x-on:click="addToCart(product)"
                        x-bind:disabled="
                            product.status !== 'available' ||
                            product.quantity <= 0
                        "
                    >
                        <div class="flex flex-col gap-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="font-semibold line-clamp-2 leading-tight"
                                        x-text="product.name"
                                    ></p>

                                    <x-wirekit::badge
                                        intent="accent"
                                        class="mt-1"
                                    >
                                        <span
                                            x-text="product.category"
                                        ></span>
                                    </x-wirekit::badge>
                                </div>

                                <span class="text-lg font-semibold whitespace-nowrap">
                                    ₱<span
                                        x-text="Number(product.price).toFixed(2)"
                                    ></span>
                                </span>
                            </div>

                            <div class="flex flex-col gap-2">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-zinc-500">
                                        Available
                                    </span>

                                    <span
                                        class="font-medium"
                                        x-text="product.quantity"
                                    ></span>
                                </div>

                                <div class="h-2 rounded-full bg-zinc-100 overflow-hidden">
                                    <div
                                        class="h-full rounded-full bg-zinc-900 transition-all"
                                        x-bind:style="`width: ${Math.min(
                                            100,
                                            product.max > 0
                                                ? (product.quantity / product.max) * 100
                                                : 0
                                        )}%`"
                                    ></div>
                                </div>

                                <div
                                    class="text-xs text-red-500"
                                    x-show="
                                        product.status !== 'available' ||
                                        product.quantity <= 0
                                    "
                                >
                                    <span
                                        x-text="
                                            product.quantity <= 0
                                                ? 'Out of stock'
                                                : 'Unavailable'
                                        "
                                    ></span>
                                </div>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-sm text-zinc-500">
                                    Click to add
                                </span>

                                <flux:icon
                                    name="plus-circle"
                                    variant="mini"
                                    class="transition group-hover:scale-110 text-primary"
                                />
                            </div>
                        </div>
                    </button>
                </template>
            </div>

            <div
                x-show="filteredProducts.length === 0"
                x-cloak
                class="border border-dashed border-zinc-300 rounded-xl p-10 text-center"
            >
                <div class="flex flex-col items-center gap-2">
                    <flux:icon
                        name="magnifying-glass"
                        class="text-zinc-400"
                    />

                    <p class="font-medium">
                        No products found
                    </p>

                    <p class="text-sm text-zinc-500">
                        Try another search term or category.
                    </p>
                </div>
            </div>
        </div>

        <div class="xl:col-span-4 xl:sticky xl:top-4 flex flex-col gap-4">
            <div class="border border-zinc-200 rounded-xl overflow-hidden">
                <div class="flex items-center justify-between p-5 border-b border-zinc-200">
                    <div>
                        <p class="font-semibold">
                            Current Order
                        </p>

                        <p class="text-sm text-zinc-500">
                            Review items before checkout
                        </p>
                    </div>

                    <x-wirekit::badge intent="accent">
                        <span x-text="itemCount"></span>
                    </x-wirekit::badge>
                </div>

                <div class="p-4 flex flex-col gap-3 max-h-[420px] overflow-y-auto">
                    <template
                        x-for="item in cart"
                        :key="item.id"
                    >
                        <div class="border border-zinc-200 rounded-xl p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p
                                        class="font-medium truncate"
                                        x-text="item.name"
                                    ></p>

                                    <p class="text-xs text-zinc-500">
                                        ₱<span
                                            x-text="Number(item.price).toFixed(2)"
                                        ></span>
                                        each
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    class="text-zinc-400 hover:text-red-500"
                                    x-on:click="removeFromCart(item.id)"
                                >
                                    <flux:icon
                                        name="x-mark"
                                        variant="mini"
                                    />
                                </button>
                            </div>

                            <div class="flex items-center justify-between mt-3">
                                <div class="flex items-center border border-zinc-200 rounded-lg overflow-hidden">
                                    <button
                                        type="button"
                                        class="px-3 py-1.5 hover:bg-zinc-50"
                                        x-on:click="decrease(item)"
                                    >
                                        −
                                    </button>

                                    <span
                                        class="px-3 py-1.5 text-sm font-medium border-x border-zinc-200"
                                        x-text="item.quantity"
                                    ></span>

                                    <button
                                        type="button"
                                        class="px-3 py-1.5 hover:bg-zinc-50 disabled:opacity-40"
                                        x-on:click="increase(item)"
                                        x-bind:disabled="
                                            item.quantity >= item.stock
                                        "
                                    >
                                        +
                                    </button>
                                </div>

                                <span class="font-semibold">
                                    ₱<span
                                        x-text="
                                            Number(
                                                item.price *
                                                item.quantity
                                            ).toFixed(2)
                                        "
                                    ></span>
                                </span>
                            </div>
                        </div>
                    </template>

                    <div
                        x-show="cart.length === 0"
                        x-cloak
                        class="py-12 text-center"
                    >
                        <div class="flex flex-col items-center gap-2">
                            <flux:icon
                                name="shopping-cart"
                                class="text-zinc-400"
                            />

                            <p class="font-medium">
                                Cart is empty
                            </p>

                            <p class="text-sm text-zinc-500">
                                Select a product to add it to the order.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-200 p-5 flex flex-col gap-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">
                            Subtotal
                        </span>

                        <span class="font-medium">
                            ₱<span
                                x-text="Number(subtotal).toFixed(2)"
                            ></span>
                        </span>
                    </div>

                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">
                            Discount
                        </span>

                        <span class="font-medium text-green-600">
                            −₱<span
                                x-text="Number(discountAmount).toFixed(2)"
                            ></span>
                        </span>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-zinc-200">
                        <span class="font-semibold">
                            Total
                        </span>

                        <span class="text-2xl font-bold">
                            ₱<span
                                x-text="Number(total).toFixed(2)"
                            ></span>
                        </span>
                    </div>
                </div>
            </div>

            <flux:modal.trigger name="checkout">
                <flux:button
                    variant="primary"
                    class="w-full bg-primary hover:bg-primary"
                    icon="credit-card"
                    x-bind:disabled="cart.length === 0"
                >
                    Checkout
                </flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    <flux:modal
        name="checkout"
        class="md:w-[650px]"
    >
        <div class="flex flex-col gap-6">
            <div>
                <p class="text-lg font-semibold">
                    Create Payment Slip
                </p>

                <p class="text-sm text-zinc-500">
                    Enter the student ID and order details. Payment is completed at the cashier.
                </p>
            </div>

            <div class="flex flex-col gap-4">
                <div>
                    <flux:field>
                        <flux:label>
                            Student ID Number
                        </flux:label>

                        <flux:input
                            x-model="customer.id_number"
                            placeholder="Student ID number"
                        />
                    </flux:field>
                </div>

                <div class="border border-zinc-200 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="font-medium">
                                Price Adjustments
                            </p>

                            <p class="text-xs text-zinc-500">
                                Configure an optional discount for this slip.
                            </p>
                        </div>

                        <x-wirekit::badge intent="accent">
                            Optional
                        </x-wirekit::badge>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        <flux:field>
                            <flux:label>
                                Category
                            </flux:label>

                            <flux:select x-model="discount_category">
                                <flux:select.option value="">
                                    None
                                </flux:select.option>

                                <flux:select.option value="Student">
                                    Student
                                </flux:select.option>

                                <flux:select.option value="Senior Citizen">
                                    Senior Citizen
                                </flux:select.option>

                                <flux:select.option value="PWD">
                                    PWD
                                </flux:select.option>

                                <flux:select.option value="Custom">
                                    Custom
                                </flux:select.option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label>
                                Type
                            </flux:label>

                            <flux:select x-model="discount_type">
                                <flux:select.option value="fixed">
                                    Fixed Amount
                                </flux:select.option>

                                <flux:select.option value="percentage">
                                    Percentage
                                </flux:select.option>
                            </flux:select>
                        </flux:field>

                        <flux:field>
                            <flux:label>
                                <span
                                    x-text="
                                        discount_type === 'percentage'
                                            ? 'Percentage'
                                            : 'Amount'
                                    "
                                ></span>
                            </flux:label>

                            <flux:input
                                type="number"
                                min="0"
                                step="0.01"
                                x-model.number="discount_value"
                                x-bind:max="
                                    discount_type === 'percentage'
                                        ? 100
                                        : subtotal
                                "
                            />
                        </flux:field>

                    </div>
                </div>

                <flux:field>
                    <flux:label>
                        Notes
                    </flux:label>

                    <flux:textarea
                        x-model="notes"
                        placeholder="Optional transaction notes..."
                        rows="3"
                    />
                </flux:field>

                <div class="border border-zinc-200 rounded-xl p-4">
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500">
                                Subtotal
                            </span>

                            <span>
                                ₱<span
                                    x-text="Number(subtotal).toFixed(2)"
                                ></span>
                            </span>
                        </div>

                        <div class="flex justify-between text-sm">
                            <span class="text-zinc-500">
                                Discount
                            </span>

                            <span class="text-green-600">
                                −₱<span
                                    x-text="Number(discountAmount).toFixed(2)"
                                ></span>
                            </span>
                        </div>

                        <div class="flex justify-between pt-3 mt-1 border-t border-zinc-200">
                            <span class="font-semibold">
                                Amount Due
                            </span>

                            <span class="text-xl font-bold">
                                ₱<span
                                    x-text="Number(total).toFixed(2)"
                                ></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col-reverse sm:flex-row gap-2 sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    icon="check"
                    class="bg-primary hover:bg-primary"
                    x-on:click="processCheckout()"
                    x-bind:disabled="
                        processing ||
                        cart.length === 0
                    "
                >
                    <span x-show="!processing">
                        Complete Sale
                    </span>

                    <span
                        x-show="processing"
                        x-cloak
                    >
                        Processing...
                    </span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>