<?php

use App\Models\College;
use App\Models\CollegeRequest;
use App\Models\CollegeRequestItem;
use App\Models\ProductCategories;
use App\Models\ProductItem;
use App\Enums\CollegeRequestStatus;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public string $statusFilter = 'active';

    public string $itemSearch = '';

    public string $itemCategoryFilter = 'all';

    public string $referenceNumber = '';

    public string $collegeId = '';

    public string $newCollegeName = '';

    public array $requestItems = [];

    public array $editRequestItems = [];

    public bool $editingSignedItems = false;

    public string $subject = '';

    public string $recipientName = '';

    public string $letterReceivedAt = '';

    public string $notes = '';

    public string $detailsStatus = 'pending';

    public ?int $selectedRequestId = null;

    public function mount(): void
    {
        $this->letterReceivedAt = now()->toDateString();
    }

    public function updatedCollegeId(string $value): void
    {
        if ($value === 'add-college') {
            $this->collegeId = '';
            Flux::modal('add-college')->show();
        }
    }

    #[Computed]
    public function colleges()
    {
        return College::query()->orderBy('name')->get();
    }

    #[Computed]
    public function inventoryCategories()
    {
        return ProductCategories::query()->orderBy('name')->get();
    }

    #[Computed]
    public function inventoryProducts()
    {
        $reservedItems = DB::table('college_request_items')
            ->selectRaw('COALESCE(SUM(college_request_items.quantity), 0)')
            ->join('college_requests', 'college_requests.id', '=', 'college_request_items.college_request_id')
            ->whereColumn('college_request_items.product_id', 'product_items.id')
            ->where('college_requests.status', CollegeRequestStatus::Signed->value);

        return ProductItem::query()
            ->leftJoin('product_categories', 'product_categories.id', '=', 'product_items.category_id')
            ->whereRaw('product_items.warehouse_quantity > ('.$reservedItems->toSql().')', $reservedItems->getBindings())
            ->where('product_items.status', 'available')
            ->addSelect([
                'reserved_warehouse_quantity' => (clone $reservedItems),
            ])
            ->when(trim($this->itemSearch) !== '', function ($query) {
                $search = '%'.trim($this->itemSearch).'%';
                $query->where(function ($query) use ($search) {
                    $query->where('product_items.name', 'like', $search)
                        ->orWhere('product_categories.name', 'like', $search);
                });
            })
            ->when($this->itemCategoryFilter !== 'all', fn ($query) => $query->where('product_items.category_id', $this->itemCategoryFilter))
            ->orderBy('product_items.name')
            ->get([
                'product_items.id',
                'product_items.name',
                'product_items.price',
                'product_items.warehouse_quantity',
                'product_items.status',
                'product_items.category_id',
                'product_categories.name as category_name',
            ]);
    }

    #[Computed]
    public function selectedRequestProducts()
    {
        $productIds = collect($this->requestItems)
            ->pluck('product_id')
            ->filter()
            ->merge(collect($this->editRequestItems)->pluck('product_id')->filter())
            ->unique()
            ->all();

        return ProductItem::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    #[Computed]
    public function requests()
    {
        return CollegeRequest::query()
            ->with('items.product')
            ->when($this->statusFilter !== 'active' && $this->statusFilter !== 'all', fn ($query) => $query->where('college_requests.status', $this->statusFilter)
            )
            ->when($this->statusFilter === 'active', fn ($query) => $query->whereIn('college_requests.status', ['pending', 'signed'])
            )
            ->when(trim($this->search) !== '', function ($query) {
                $search = '%'.trim($this->search).'%';

                $query->where(function ($query) use ($search) {
                    $query->where('college_requests.reference_number', 'like', $search)
                        ->orWhere('college_requests.college_name', 'like', $search)
                        ->orWhere('college_requests.subject', 'like', $search)
                        ->orWhere('college_requests.recipient_name', 'like', $search);
                });
            })
            ->orderByRaw("CASE college_requests.status WHEN 'pending' THEN 0 WHEN 'signed' THEN 1 ELSE 2 END")
            ->orderByDesc('college_requests.letter_received_at')
            ->orderByDesc('college_requests.id')
            ->get();
    }

    #[Computed]
    public function selectedRequestDetails(): ?CollegeRequest
    {
        if (! $this->selectedRequestId) {
            return null;
        }

        $request = CollegeRequest::query()
            ->with(['items.product', 'recordedBy', 'signedBy', 'releasedBy'])
            ->find($this->selectedRequestId);

        if ($request && Schema::hasTable('college_request_activities')) {
            $request->load('activities.user');
        }

        return $request;
    }

    public function viewRequest(int $requestId): void
    {
        $this->selectedRequestId = $requestId;
        $this->editingSignedItems = false;
        $this->editRequestItems = [];
        unset($this->selectedRequestDetails);
        Flux::modal('college-request-details')->show();
    }

    public function closeRequestDetails(): void
    {
        Flux::modal('college-request-details')->close();
        $this->selectedRequestId = null;
        $this->editingSignedItems = false;
        $this->editRequestItems = [];
        unset($this->selectedRequestDetails);
    }

    #[Computed]
    public function statusCounts(): array
    {
        $counts = CollegeRequest::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'signed' => (int) ($counts['signed'] ?? 0),
            'released' => (int) ($counts['released'] ?? 0),
        ];
    }

    #[Computed]
    public function statusDetailRequests()
    {
        return CollegeRequest::query()
            ->with('items.product')
            ->where('status', $this->detailsStatus)
            ->orderByDesc('letter_received_at')
            ->get();
    }

    public function openStatusDetails(string $status): void
    {
        abort_unless(in_array($status, ['pending', 'signed', 'released'], true), 404);

        $this->detailsStatus = $status;
        unset($this->statusDetailRequests);
        Flux::modal('college-request-status-details')->show();
    }

    public function addProductToRequest(int $productId): void
    {
        $this->ensureProductHasUnreservedWarehouseStock($productId);
        $this->addProductToItems($this->requestItems, $productId);
        unset($this->selectedRequestProducts);
    }

    public function addProductToSignedRequest(int $productId): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        $this->ensureProductHasUnreservedWarehouseStock($productId);
        $this->addProductToItems($this->editRequestItems, $productId);
        unset($this->selectedRequestProducts);
    }

    private function ensureProductHasUnreservedWarehouseStock(int $productId): void
    {
        $product = ProductItem::query()->findOrFail($productId);
        $reservedQuantity = CollegeRequestItem::reservedWarehouseQuantity($productId);

        if (
            $product->status !== 'available'
            || (int) $product->warehouse_quantity <= $reservedQuantity
        ) {
            throw ValidationException::withMessages([
                $this->editingSignedItems ? 'editRequestItems' : 'requestItems' => 'This item has no unreserved warehouse stock.',
            ]);
        }
    }

    private function addProductToItems(array &$items, int $productId): void
    {
        ProductItem::query()->findOrFail($productId);

        foreach ($items as $index => $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId) {
                $items[$index]['quantity'] = (int) ($item['quantity'] ?? 0) + 1;

                return;
            }
        }

        $items[] = [
            'product_id' => (string) $productId,
            'quantity' => 1,
        ];
    }

    public function removeSignedRequestItem(int $index): void
    {
        unset($this->editRequestItems[$index]);
        $this->editRequestItems = array_values($this->editRequestItems);
        unset($this->selectedRequestProducts);
    }

    public function cancelEditSignedItems(): void
    {
        $this->editingSignedItems = false;
        $this->editRequestItems = [];
    }

    public function removeRequestItem(int $index): void
    {
        unset($this->requestItems[$index]);
        $this->requestItems = array_values($this->requestItems);
        unset($this->selectedRequestProducts);
    }

    public function createCollege(): void
    {
        $this->validate([
            'newCollegeName' => ['required', 'string', 'max:255', 'unique:colleges,name'],
        ]);

        $college = College::create(['name' => trim($this->newCollegeName)]);
        $this->collegeId = (string) $college->id;
        $this->newCollegeName = '';

        Flux::modal('add-college')->close();
        unset($this->colleges);
        Flux::toast(variant: 'success', text: 'College added.');
    }

    public function recordRequest(): void
    {
        $this->validate([
            'referenceNumber' => ['nullable', 'string', 'max:100'],
            'collegeId' => ['required', 'exists:colleges,id'],
            'subject' => ['required', 'string', 'max:255'],
            'recipientName' => ['required', 'string', 'max:255'],
            'letterReceivedAt' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'requestItems' => ['array'],
            'requestItems.*.product_id' => ['required', 'integer', 'exists:product_items,id'],
            'requestItems.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () {
            $request = CollegeRequest::create([
                'reference_number' => trim($this->referenceNumber) ?: null,
                'college_name' => College::findOrFail($this->collegeId)->name,
                'subject' => trim($this->subject),
                'recipient_name' => trim($this->recipientName),
                'letter_received_at' => $this->letterReceivedAt,
                'notes' => trim($this->notes) ?: null,
                'status' => CollegeRequestStatus::Pending,
                'recorded_by' => auth()->id(),
            ]);

            foreach ($this->normalizeItems($this->requestItems) as $productId => $item) {
                $request->items()->create([
                    'product_id' => $productId,
                    'quantity' => $item['quantity'],
                ]);
            }

            $request->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'Letter recorded',
                'details' => 'Request entered and marked as awaiting signature.',
            ]);
        });

        Flux::modal('record-college-request')->close();
        $this->resetRequestForm();
        unset($this->requests, $this->statusCounts);
        Flux::toast(variant: 'success', text: 'College request recorded as pending.');
    }

    public function markSigned(int $requestId): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        DB::transaction(function () use ($requestId) {
            $request = CollegeRequest::query()
                ->whereKey($requestId)
                ->where('status', CollegeRequestStatus::Pending->value)
                ->lockForUpdate()
                ->firstOrFail();

            $items = $request->items()->lockForUpdate()->get();
            $products = ProductItem::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $requestedQuantities = $items->groupBy('product_id')
                ->map(fn ($productItems) => $productItems->sum('quantity'));

            foreach ($requestedQuantities as $productId => $quantity) {
                $product = $products->get($productId);
                $reservedQuantity = CollegeRequestItem::reservedWarehouseQuantity((int) $productId);

                if (
                    ! $product
                    || $product->status !== 'available'
                    || (int) $product->warehouse_quantity - $reservedQuantity < (int) $quantity
                ) {
                    throw ValidationException::withMessages([
                        'sign' => 'One or more requested items have insufficient unreserved warehouse stock. The request was not signed.',
                    ]);
                }
            }

            $request->update([
                'status' => CollegeRequestStatus::Signed,
                'signed_by' => auth()->id(),
                'signed_at' => now(),
            ]);

            $request->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'Request signed',
                'details' => 'Admin marked the letter as signed.',
            ]);
        });

        unset($this->requests, $this->statusCounts, $this->selectedRequestDetails);
        Flux::toast(variant: 'success', text: 'College request marked as signed and warehouse stock reserved.');
    }

    public function releaseToCollege(int $requestId): void
    {
        DB::transaction(function () use ($requestId) {
            $request = CollegeRequest::query()
                ->whereKey($requestId)
                ->where('status', CollegeRequestStatus::Signed->value)
                ->lockForUpdate()
                ->firstOrFail();

            $items = $request->items()->lockForUpdate()->get();
            $products = ProductItem::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                $reservedQuantity = CollegeRequestItem::reservedWarehouseQuantity((int) $item->product_id);

                if (
                    ! $product
                    || $product->status !== 'available'
                    || (int) $product->warehouse_quantity < $reservedQuantity
                ) {
                    throw ValidationException::withMessages([
                        'release' => 'One or more reserved items are unavailable or warehouse stock no longer covers all signed requests. No inventory was released.',
                    ]);
                }
            }

            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $warehouseQuantity = (int) $product->warehouse_quantity - (int) $item->quantity;
                $totalQuantity = (int) $product->front_quantity + $warehouseQuantity;

                $product->update([
                    'warehouse_quantity' => $warehouseQuantity,
                    'quantity' => $totalQuantity,
                    'status' => $totalQuantity > 0 ? 'available' : 'out_of_stock',
                ]);
            }

            $request->update([
                'status' => CollegeRequestStatus::Released,
                'released_by' => auth()->id(),
                'released_at' => now(),
            ]);

            $request->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'Items released to college',
                'details' => 'Requested items were released; warehouse inventory was deducted.',
            ]);
        });

        unset($this->requests, $this->statusCounts);
        Flux::toast(variant: 'success', text: 'Requested items released to the college.');
    }

    public function beginEditSignedItems(int $requestId): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        $request = CollegeRequest::query()
            ->whereKey($requestId)
            ->where('status', CollegeRequestStatus::Signed->value)
            ->with('items')
            ->firstOrFail();

        $this->selectedRequestId = $request->id;
        $this->editRequestItems = $request->items
            ->map(fn ($item) => [
                'product_id' => (string) $item->product_id,
                'quantity' => (int) $item->quantity,
            ])
            ->values()
            ->all();
        $this->editingSignedItems = true;
        $this->itemSearch = '';
        $this->itemCategoryFilter = 'all';
    }

    public function saveSignedRequestItems(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        $this->validate([
            'editRequestItems' => ['array'],
            'editRequestItems.*.product_id' => ['required', 'integer', 'exists:product_items,id'],
            'editRequestItems.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () {
            $request = CollegeRequest::query()
                ->whereKey($this->selectedRequestId)
                ->where('status', CollegeRequestStatus::Signed->value)
                ->lockForUpdate()
                ->firstOrFail();
            $items = $this->normalizeItems($this->editRequestItems);
            $products = ProductItem::query()
                ->whereIn('id', array_keys($items))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $productId => $item) {
                $product = $products->get($productId);
                $reservedByOtherRequests = CollegeRequestItem::reservedWarehouseQuantity(
                    (int) $productId,
                    $request->id
                );

                if (
                    ! $product
                    || $product->status !== 'available'
                    || (int) $product->warehouse_quantity - $reservedByOtherRequests < $item['quantity']
                ) {
                    throw ValidationException::withMessages([
                        'editRequestItems' => 'The updated quantities exceed unreserved warehouse stock. No changes were saved.',
                    ]);
                }
            }

            $request->items()->delete();
            foreach ($items as $productId => $item) {
                $request->items()->create([
                    'product_id' => $productId,
                    'quantity' => $item['quantity'],
                ]);
            }

            $request->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'Reserved items updated',
                'details' => 'Signed request items were changed and warehouse reservations were updated.',
            ]);
        });

        $this->editingSignedItems = false;
        $this->editRequestItems = [];
        unset($this->selectedRequestDetails, $this->statusDetailRequests, $this->requests);
        Flux::toast(variant: 'success', text: 'Signed request items and reservations updated.');
    }

    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->groupBy(fn ($item) => (int) $item['product_id'])
            ->map(fn ($productItems) => [
                'quantity' => $productItems->sum(fn ($item) => (int) $item['quantity']),
            ])
            ->all();
    }

    public function statusLabel(CollegeRequestStatus|string $status): string
    {
        $status = $status instanceof CollegeRequestStatus ? $status->value : $status;

        return match ($status) {
            'pending' => 'Awaiting signature',
            'signed' => 'Signed · awaiting release',
            'released' => 'Released to college',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    public function statusColor(CollegeRequestStatus|string $status): string
    {
        $status = $status instanceof CollegeRequestStatus ? $status->value : $status;

        return match ($status) {
            'pending' => 'amber',
            'signed' => 'blue',
            'released' => 'green',
            default => 'zinc',
        };
    }

    public function resetRequestForm(): void
    {
        $this->referenceNumber = '';
        $this->collegeId = '';
        $this->requestItems = [];
        $this->subject = '';
        $this->recipientName = '';
        $this->letterReceivedAt = now()->toDateString();
        $this->notes = '';
        $this->itemSearch = '';
        $this->itemCategoryFilter = 'all';
        $this->editingSignedItems = false;
        $this->editRequestItems = [];
        $this->resetValidation();
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="flex items-center gap-4">
        <div>
            <h1 class="text-xl font-bold">College Requests</h1>
            <p class="text-sm text-zinc-500">Track letters from receipt through signature and pickup.</p>
        </div>

        <flux:spacer />

        <flux:modal.trigger name="record-college-request">
            <flux:button variant="primary" icon="plus">Record letter</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <button type="button" wire:click="openStatusDetails('pending')" class="rounded-lg border border-zinc-200 p-4 text-left transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <span class="text-sm text-zinc-500">Awaiting signature</span>
            <span class="mt-1 block text-2xl font-semibold">{{ $this->statusCounts['pending'] }}</span>
        </button>
        <button type="button" wire:click="openStatusDetails('signed')" class="rounded-lg border border-zinc-200 p-4 text-left transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <span class="text-sm text-zinc-500">Signed · awaiting release</span>
            <span class="mt-1 block text-2xl font-semibold">{{ $this->statusCounts['signed'] }}</span>
        </button>
        <button type="button" wire:click="openStatusDetails('released')" class="rounded-lg border border-zinc-200 p-4 text-left transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900">
            <span class="text-sm text-zinc-500">Released to college</span>
            <span class="mt-1 block text-2xl font-semibold">{{ $this->statusCounts['released'] }}</span>
        </button>
    </div>

    <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_260px]">
        <flux:field>
            <flux:label>Search requests</flux:label>
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Subject, college, recipient, or reference" />
        </flux:field>
        <flux:field>
            <flux:label>Status</flux:label>
            <flux:select wire:model.live="statusFilter">
                <flux:select.option value="active">Awaiting signature / release</flux:select.option>
                <flux:select.option value="pending">Awaiting signature</flux:select.option>
                <flux:select.option value="signed">Signed · awaiting release</flux:select.option>
                <flux:select.option value="released">Released to college</flux:select.option>
                <flux:select.option value="all">All requests</flux:select.option>
            </flux:select>
        </flux:field>
    </div>

    @error('release')
        <flux:callout variant="danger">{{ $message }}</flux:callout>
    @enderror
    @error('sign')
        <flux:callout variant="danger">{{ $message }}</flux:callout>
    @enderror

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Letter</th>
                    <th class="px-4 py-3 text-left font-medium">College</th>
                    <th class="px-4 py-3 text-left font-medium">Status</th>
                    <th class="px-4 py-3 text-right font-medium">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->requests as $request)
                    <tr wire:key="college-request-{{ $request->id }}" class="border-t border-zinc-200 align-top dark:border-zinc-700">
                        <td class="max-w-xs px-4 py-3">
                            <p class="font-medium">{{ $request->subject }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $request->reference_number ?: 'No reference' }}</p>
                            @if ($request->items->isNotEmpty())
                                <ul class="mt-2 space-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                                    @foreach ($request->items as $item)
                                        <li>{{ $item->product?->name ?? 'Deleted product #'.$item->product_id }} · Qty {{ number_format($item->quantity) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($request->notes)
                                <p class="mt-1 line-clamp-2 text-xs text-zinc-500">{{ $request->notes }}</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $request->college_name }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <flux:badge size="sm" :color="$this->statusColor($request->status)">{{ $this->statusLabel($request->status) }}</flux:badge>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button
                                    icon="ellipsis-horizontal"
                                    variant="ghost"
                                    size="sm"
                                    square
                                    aria-label="College request actions"
                                />

                                <flux:menu>
                                    <flux:menu.item
                                        icon="eye"
                                        wire:click="viewRequest({{ $request->id }})"
                                    >View request</flux:menu.item>

                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-zinc-500">No college requests found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="record-college-request" class="max-w-6xl w-full">
        <div class="flex flex-col gap-5">
            <div>
                <h2 class="text-lg font-semibold">Record received letter</h2>
                <p class="text-sm text-zinc-500">New letters start as awaiting signature.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="referenceNumber" label="Letter reference number" placeholder="Optional" />
                <flux:select wire:model.live="collegeId" label="College / department" placeholder="Select college">
                    @foreach ($this->colleges as $college)
                        <flux:select.option value="{{ $college->id }}">{{ $college->name }}</flux:select.option>
                    @endforeach
                    <flux:select.option value="add-college">+ Add new college</flux:select.option>
                </flux:select>
                <flux:input wire:model="subject" label="Letter subject" placeholder="Request subject" />
                <flux:input wire:model="recipientName" label="Recipient name" placeholder="Person who will collect the letter" />
                <flux:input wire:model="letterReceivedAt" type="date" label="Date received" />
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="notes" label="Notes" rows="3" placeholder="Additional identifying details" />
                </div>
            </div>

            <div class="grid min-h-[28rem] grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(20rem,0.8fr)]">
                <section class="flex min-h-0 flex-col gap-3">
                    <div>
                        <p class="text-sm font-medium">Choose inventory items</p>
                        <p class="text-xs text-zinc-500">Search or filter the catalog, then add each requested item.</p>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                        <flux:input wire:model.live.debounce.250ms="itemSearch" icon="magnifying-glass" placeholder="Search item or category" />
                        <flux:select wire:model.live="itemCategoryFilter">
                            <flux:select.option value="all">All categories</flux:select.option>
                            @foreach ($this->inventoryCategories as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="grid max-h-80 grid-cols-1 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                        @forelse ($this->inventoryProducts as $product)
                            <button
                                type="button"
                                wire:key="request-product-{{ $product->id }}"
                                wire:click="addProductToRequest({{ $product->id }})"
                                class="rounded-lg border border-zinc-200 p-3 text-left transition hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900"
                            >
                                <span class="block truncate font-medium">{{ $product->name }}</span>
                                <span class="mt-1 block text-xs text-zinc-500">{{ $product->category_name ?: 'Uncategorized' }}</span>
                                <span class="mt-2 flex items-center justify-between text-xs">
                                    <span>Available: {{ $product->warehouse_quantity - $product->reserved_warehouse_quantity }}</span>
                                    <span class="font-medium">Add +</span>
                                </span>
                            </button>
                        @empty
                            <p class="py-8 text-center text-sm text-zinc-500 sm:col-span-2">No matching inventory items.</p>
                        @endforelse
                    </div>
                </section>

                <section class="flex min-h-0 flex-col rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">Request list</p>
                            <p class="text-xs text-zinc-500">Adding an item again increases its quantity.</p>
                        </div>
                        <flux:badge size="sm" color="zinc">{{ count($requestItems) }} items</flux:badge>
                    </div>
                    <div class="mt-3 flex-1 space-y-2 overflow-y-auto">
                        @forelse ($requestItems as $index => $requestItem)
                            <div wire:key="college-request-item-{{ $index }}" class="flex items-center gap-2 rounded-md bg-zinc-50 p-2 dark:bg-zinc-900">
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $this->selectedRequestProducts[$requestItem['product_id']]->name ?? 'Inventory item' }}</span>
                                <flux:input wire:model="requestItems.{{ $index }}.quantity" type="number" min="1" class="w-20" aria-label="Quantity" />
                                <flux:button size="sm" variant="ghost" icon="x-mark" square aria-label="Remove item" wire:click="removeRequestItem({{ $index }})" />
                            </div>
                        @empty
                            <p class="py-8 text-center text-sm text-zinc-500">No items selected.</p>
                        @endforelse
                    </div>
                    @error('requestItems')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </section>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost" wire:click="resetRequestForm">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="recordRequest">Save as pending</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="add-college" class="max-w-md w-full">
        <div class="flex flex-col gap-5">
            <div>
                <h2 class="text-lg font-semibold">Add college</h2>
                <p class="text-sm text-zinc-500">The new college will be selected for this letter.</p>
            </div>
            <flux:input wire:model="newCollegeName" label="College name" placeholder="College / department" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('newCollegeName', '')" x-on:click="$flux.modal('add-college').close()">Cancel</flux:button>
                <flux:button variant="primary" wire:click="createCollege">Add college</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="college-request-status-details" class="max-w-2xl w-full">
        <div class="flex flex-col gap-5">
            <div>
                <h2 class="text-lg font-semibold">{{ $this->statusLabel($detailsStatus) }}</h2>
                <p class="text-sm text-zinc-500">{{ $this->statusDetailRequests->count() }} request(s) in this stage.</p>
            </div>

            <div class="max-h-[60vh] overflow-y-auto divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->statusDetailRequests as $detailRequest)
                    <div class="py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="font-medium">{{ $detailRequest->subject }}</p>
                                <p class="text-sm text-zinc-500">{{ $detailRequest->college_name }} · {{ $detailRequest->recipient_name }}</p>
                            </div>
                            <span class="text-sm text-zinc-500">{{ $detailRequest->reference_number ?: 'No reference' }}</span>
                        </div>
                        @if ($detailRequest->items->isNotEmpty())
                            <ul class="mt-2 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                                @foreach ($detailRequest->items as $item)
                                    <li>{{ $item->product?->name ?? 'Product unavailable' }} × {{ $item->quantity }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-2 text-sm text-zinc-400">No inventory items</p>
                        @endif
                    </div>
                @empty
                    <p class="py-10 text-center text-sm text-zinc-500">No requests in this stage.</p>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="ghost">Close</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="college-request-details" class="max-w-3xl w-full">
        @if ($requestDetails = $this->selectedRequestDetails)
            <div class="flex max-h-[85vh] flex-col gap-5 overflow-y-auto pr-1">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $requestDetails->subject }}</h2>
                        <p class="text-sm text-zinc-500">Request #{{ $requestDetails->id }} · {{ $requestDetails->reference_number ?: 'No reference number' }}</p>
                    </div>
                    <flux:badge size="sm" :color="$this->statusColor($requestDetails->status)">{{ $this->statusLabel($requestDetails->status) }}</flux:badge>
                </div>

                <dl class="grid grid-cols-1 gap-4 rounded-lg border border-zinc-200 p-4 text-sm sm:grid-cols-2 dark:border-zinc-700">
                    <div>
                        <dt class="text-zinc-500">College / department</dt>
                        <dd class="mt-1 font-medium">{{ $requestDetails->college_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Recipient</dt>
                        <dd class="mt-1 font-medium">{{ $requestDetails->recipient_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Date letter received</dt>
                        <dd class="mt-1 font-medium">{{ \Illuminate\Support\Carbon::parse($requestDetails->letter_received_at)->format('M d, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Recorded by</dt>
                        <dd class="mt-1 font-medium">{{ trim(($requestDetails->recordedBy?->first_name ?? '') . ' ' . ($requestDetails->recordedBy?->last_name ?? '')) ?: 'Unknown' }}</dd>
                    </div>
                    @if ($requestDetails->signed_at)
                        <div>
                            <dt class="text-zinc-500">Signed by</dt>
                            <dd class="mt-1 font-medium">{{ trim(($requestDetails->signedBy?->first_name ?? '') . ' ' . ($requestDetails->signedBy?->last_name ?? '')) ?: 'Unknown' }} · {{ \Illuminate\Support\Carbon::parse($requestDetails->signed_at)->format('M d, Y h:i A') }}</dd>
                        </div>
                    @endif
                    @if ($requestDetails->released_at)
                        <div>
                            <dt class="text-zinc-500">Released by</dt>
                            <dd class="mt-1 font-medium">{{ trim(($requestDetails->releasedBy?->first_name ?? '') . ' ' . ($requestDetails->releasedBy?->last_name ?? '')) ?: 'Unknown' }} · {{ \Illuminate\Support\Carbon::parse($requestDetails->released_at)->format('M d, Y h:i A') }}</dd>
                        </div>
                    @endif
                    @if ($requestDetails->notes)
                        <div class="sm:col-span-2">
                            <dt class="text-zinc-500">Notes</dt>
                            <dd class="mt-1 whitespace-pre-wrap">{{ $requestDetails->notes }}</dd>
                        </div>
                    @endif
                </dl>

                <section>
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold">Requested items</h3>
                        @if ($requestDetails->status === CollegeRequestStatus::Signed && auth()->user()->role === 'admin' && ! $editingSignedItems)
                            <flux:button size="sm" variant="outline" icon="pencil" wire:click="beginEditSignedItems({{ $requestDetails->id }})">Change items</flux:button>
                        @endif
                    </div>
                    @if ($editingSignedItems && $selectedRequestId === $requestDetails->id)
                        <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <div class="flex flex-col gap-3">
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <flux:input wire:model.live.debounce.250ms="itemSearch" icon="magnifying-glass" placeholder="Search inventory" />
                                    <flux:select wire:model.live="itemCategoryFilter">
                                        <flux:select.option value="all">All categories</flux:select.option>
                                        @foreach ($this->inventoryCategories as $category)
                                            <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <div class="grid max-h-56 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                    @foreach ($this->inventoryProducts as $product)
                                        <button
                                            type="button"
                                            wire:key="signed-request-product-{{ $product->id }}"
                                            wire:click="addProductToSignedRequest({{ $product->id }})"
                                            class="rounded-lg border border-zinc-200 p-2 text-left text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900"
                                        >
                                            <span class="block truncate font-medium">{{ $product->name }}</span>
                                            <span class="text-xs text-zinc-500">Available: {{ $product->warehouse_quantity - $product->reserved_warehouse_quantity }} · Add +</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex flex-col rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <p class="text-sm font-medium">Reserved items</p>
                                <div class="mt-2 flex-1 space-y-2">
                                    @forelse ($editRequestItems as $index => $requestItem)
                                        <div wire:key="signed-edit-item-{{ $index }}" class="flex items-center gap-2">
                                            <span class="min-w-0 flex-1 truncate text-sm">{{ $this->selectedRequestProducts[$requestItem['product_id']]->name ?? 'Inventory item' }}</span>
                                            <flux:input wire:model="editRequestItems.{{ $index }}.quantity" type="number" min="1" class="w-20" aria-label="Quantity" />
                                            <flux:button size="sm" variant="ghost" icon="x-mark" square aria-label="Remove item" wire:click="removeSignedRequestItem({{ $index }})" />
                                        </div>
                                    @empty
                                        <p class="py-4 text-sm text-zinc-500">No items selected.</p>
                                    @endforelse
                                </div>
                                @error('editRequestItems')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                                <div class="mt-4 flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" wire:click="cancelEditSignedItems">Cancel</flux:button>
                                    <flux:button size="sm" variant="primary" wire:click="saveSignedRequestItems">Save items</flux:button>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @forelse ($requestDetails->items as $item)
                                <div class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                    <span>{{ $item->product?->name ?? 'Product unavailable' }}</span>
                                    <span class="font-medium">Quantity {{ $item->quantity }}</span>
                                </div>
                            @empty
                                <p class="px-4 py-5 text-sm text-zinc-500">No inventory items were requested.</p>
                            @endforelse
                        </div>
                    @endif
                </section>

                <section>
                    <h3 class="text-sm font-semibold">Activity log</h3>
                    <div class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @forelse ($requestDetails->activities as $activity)
                            <div class="flex items-start justify-between gap-4 px-4 py-3 text-sm">
                                <div>
                                    <p class="font-medium">{{ $activity->action }}</p>
                                    <p class="mt-1 text-zinc-500">{{ $activity->details }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ trim(($activity->user?->first_name ?? '') . ' ' . ($activity->user?->last_name ?? '')) ?: 'Unknown user' }}</p>
                                </div>
                                <time class="whitespace-nowrap text-xs text-zinc-500">{{ $activity->created_at ? \Illuminate\Support\Carbon::parse($activity->created_at)->format('M d, Y h:i A') : '' }}</time>
                            </div>
                        @empty
                            <p class="px-4 py-5 text-sm text-zinc-500">No activity has been recorded.</p>
                        @endforelse
                    </div>
                </section>

                <div class="flex justify-end gap-2">
                    @if ($requestDetails->status === CollegeRequestStatus::Pending && auth()->user()->role === 'admin')
                        <flux:button variant="primary" icon="check" wire:click="markSigned({{ $requestDetails->id }})">Mark signed</flux:button>
                    @endif
                    @if ($requestDetails->status === CollegeRequestStatus::Signed)
                        <flux:button variant="primary" icon="arrow-up-right" wire:click="releaseToCollege({{ $requestDetails->id }})">Release to College</flux:button>
                    @endif
                    <flux:button variant="ghost" wire:click="closeRequestDetails">Close</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
