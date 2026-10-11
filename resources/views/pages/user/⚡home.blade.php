<?php

use Livewire\Component;
use App\Enums\CollegeRequestStatus;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    public $totalProducts = 0;
    public $totalStock = 0;
    public $inventoryValue = 0;
    public $restockNeeded = 0;
    public $salesLabels = [];
    public $salesData = [];
    public $restockRecommendations = [];

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->loadStatistics();
        $this->loadSalesChart();
        $this->loadRecommendations();
    }

    protected function loadStatistics()
    {
        $this->totalProducts =
            DB::table('product_items')
                ->count();

        $this->totalStock =
            (int) DB::table('product_items')
                ->selectRaw('COALESCE(SUM(front_quantity + warehouse_quantity), 0) as total_stock')
                ->value('total_stock');

        $this->inventoryValue =
            (float) DB::table('product_items')
                ->selectRaw(
                    'COALESCE(SUM(quantity * price), 0) as value'
                )
                ->value('value');
    }

    protected function loadSalesChart()
    {
        $this->salesLabels = [];
        $this->salesData = [];

        for (
            $i = 5;
            $i >= 0;
            $i--
        ) {
            $date =
                now()->subMonths($i);

            $this->salesLabels[] =
                $date->format('M Y');

            $sales =
                DB::table('orders')
                    ->join(
                        'transactions',
                        'transactions.id',
                        '=',
                        'orders.transaction_id'
                    )
                    ->whereIn(
                        'transactions.status',
                        [
                            'completed',
                            'paid',
                        ]
                    )
                    ->whereBetween(
                        'orders.created_at',
                        [
                            $date
                                ->copy()
                                ->startOfMonth(),

                            $date
                                ->copy()
                                ->endOfMonth(),
                        ]
                    )
                    ->count();

            $this->salesData[] =
                (int) $sales;
        }
    }

    protected function loadRecommendations()
    {
        $products =
            DB::table('product_items')
                ->leftJoin(
                    'product_categories',
                    'product_categories.id',
                    '=',
                    'product_items.category_id'
                )
                ->select([
                    'product_items.id',
                    'product_items.name',
                    'product_items.front_quantity',
                    'product_items.warehouse_quantity',
                    'product_items.reorder_level',
                    'product_items.max',
                    'product_items.price',
                    'product_items.status',
                    'product_categories.name as category_name',
                ])
                ->addSelect([
                    'reserved_warehouse_quantity' => DB::table('college_request_items')
                        ->selectRaw('COALESCE(SUM(college_request_items.quantity), 0)')
                        ->join('college_requests', 'college_requests.id', '=', 'college_request_items.college_request_id')
                        ->whereColumn('college_request_items.product_id', 'product_items.id')
                        ->where('college_requests.status', CollegeRequestStatus::Signed->value),
                ])
                ->get();

        if ($products->isEmpty()) {
            $this->restockRecommendations = [];
            $this->restockNeeded = 0;

            return;
        }

        $productSales =
            DB::table('orders')
                ->join(
                    'transactions',
                    'transactions.id',
                    '=',
                    'orders.transaction_id'
                )
                ->select(
                    'orders.product_id',
                    DB::raw(
                        'COUNT(*) as total_sold'
                    )
                )
                ->whereIn(
                    'transactions.status',
                    [
                        'completed',
                        'paid',
                    ]
                )
                ->where(
                    'orders.created_at',
                    '>=',
                    now()
                        ->subMonths(3)
                        ->startOfMonth()
                )
                ->groupBy(
                    'orders.product_id'
                )
                ->pluck(
                    'total_sold',
                    'orders.product_id'
                );

        $recommendations =
            $products
                ->map(
                    function (
                        $product
                    ) use (
                        $productSales
                    ) {
                        $currentStock =
                            max(
                                0,
                                (int) $product->front_quantity
                                    + (int) $product->warehouse_quantity
                                    - (int) $product->reserved_warehouse_quantity
                            );

                        $maximumStock =
                            max(
                                0,
                                (int) $product->max
                            );
                        $reorderLevel =
                            max(
                                0,
                                (int) $product->reorder_level
                            );

                        $restockTarget = $maximumStock > 0
                            ? $maximumStock
                            : max(1, $reorderLevel * 2);

                        if ($restockTarget <= 0) {
                            return null;
                        }

                        $soldLastThreeMonths =
                            (int) (
                                $productSales[
                                    $product->id
                                ] ?? 0
                            );

                        $averageMonthlySales =
                            round(
                                $soldLastThreeMonths / 3,
                                1
                            );

                        $stockPercentage = $maximumStock > 0
                            ? ($currentStock / $maximumStock) * 100
                            : ($currentStock <= $reorderLevel ? 0 : 100);

                        $restockQuantity =
                            max(
                                0,
                                $restockTarget -
                                $currentStock
                            );

                        $estimatedMonthsRemaining =
                            $averageMonthlySales > 0
                                ? round(
                                    $currentStock /
                                    $averageMonthlySales,
                                    1
                                )
                                : null;

                        $score = 0;

                        if (
                            $currentStock <= 0
                        ) {
                            $score += 100;
                        } elseif (
                            $stockPercentage <= 10
                        ) {
                            $score += 95;
                        } elseif (
                            $stockPercentage <= 20
                        ) {
                            $score += 85;
                        } elseif (
                            $stockPercentage <= 30
                        ) {
                            $score += 70;
                        } elseif (
                            $stockPercentage <= 40
                        ) {
                            $score += 50;
                        } elseif (
                            $stockPercentage <= 60
                        ) {
                            $score += 25;
                        }

                        if (
                            $averageMonthlySales > 0
                        ) {
                            $score +=
                                min(
                                    50,
                                    $averageMonthlySales * 5
                                );
                        }

                        if (
                            $currentStock > 0 &&
                            $averageMonthlySales > 0 &&
                            $estimatedMonthsRemaining <= 1
                        ) {
                            $score += 30;
                        }

                        if (
                            $currentStock <= 0 &&
                            $averageMonthlySales > 0
                        ) {
                            $reason =
                                'Out of stock with recent sales activity.';

                            $priority =
                                'Critical';

                            $intent =
                                'danger';
                        } elseif (
                            $averageMonthlySales > 0 &&
                            $estimatedMonthsRemaining <= 1
                        ) {
                            $reason =
                                'Stock may run out within the next month.';

                            $priority =
                                'High';

                            $intent =
                                'danger';
                        } elseif ($currentStock <= $reorderLevel) {
                            $reason =
                                'Stock has reached its configured re-order level.';

                            $priority =
                                'High';

                            $intent =
                                'warning';
                        } elseif (
                            $stockPercentage <= 20
                        ) {
                            $reason =
                                'Stock is already below the recommended level.';

                            $priority =
                                'High';

                            $intent =
                                'warning';
                        } elseif (
                            $stockPercentage <= 40
                        ) {
                            $reason =
                                'Stock is declining and may need replenishment soon.';

                            $priority =
                                'Medium';

                            $intent =
                                'warning';
                        } else {
                            return null;
                        }

                        return [
                            'id' =>
                                $product->id,

                            'name' =>
                                $product->name,

                            'category_name' =>
                                $product->category_name ??
                                'Uncategorized',

                            'current_stock' =>
                                $currentStock,

                            'maximum_stock' =>
                                $maximumStock,

                            'average_monthly_sales' =>
                                $averageMonthlySales,

                            'restock_quantity' =>
                                $restockQuantity,

                            'estimated_months_remaining' =>
                                $estimatedMonthsRemaining,

                            'stock_percentage' =>
                                round(
                                    $stockPercentage
                                ),

                            'priority' =>
                                $priority,

                            'intent' =>
                                $intent,

                            'reason' =>
                                $reason,

                            'score' =>
                                $score,
                        ];
                    }
                )
                ->filter()
                ->sortByDesc('score')
                ->values();

        $this->restockRecommendations =
            $recommendations->all();

        $this->restockNeeded =
            $recommendations->count();
    }
};
?>

<div wire:poll.60s="loadData" class="flex flex-col gap-8 h-[calc(100vh-8rem)] min-h-0 overflow-hidden">
    <div class="shrink-0">
        <x-wirekit::stats
            cols="4"
        >
            <a href="{{ route(auth()->user()->role.'.inventory') }}" class="block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Total Products, open inventory">
                <x-wirekit::stat
                    label="Total Products"
                    value="{{ number_format($totalProducts) }}"
                    class="h-full cursor-pointer transition-shadow hover:shadow-md"
                />
            </a>

            <a href="{{ route(auth()->user()->role.'.inventory') }}" class="block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Current Stock, open inventory">
                <x-wirekit::stat
                    label="Current Stock"
                    value="{{ number_format($totalStock) }}"
                    class="h-full cursor-pointer transition-shadow hover:shadow-md"
                />
            </a>

            <a href="{{ route(auth()->user()->role.'.inventory') }}" class="block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Inventory Value, open inventory">
                <x-wirekit::stat
                    label="Inventory Value"
                    value="₱{{ number_format($inventoryValue, 2) }}"
                    class="h-full cursor-pointer transition-shadow hover:shadow-md"
                />
            </a>

            <a href="{{ route(auth()->user()->role.'.inventory') }}" class="block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" aria-label="Restock Needed, open inventory">
                <x-wirekit::stat
                    label="Restock Needed"
                    value="{{ number_format($restockNeeded) }}"
                    class="h-full cursor-pointer transition-shadow hover:shadow-md"
                />
            </a>
        </x-wirekit::stats>
    </div>

    <div class="grid grid-cols-3 w-full flex-1 min-h-0 gap-8 overflow-hidden">
        <div class="flex flex-col col-span-2 w-full min-h-0 overflow-hidden">
            <x-wirekit-chart
                library="apexcharts"
                type="line"
                height="100%"
                valueDecimals="0"
                valueSuffix=" units"
                :labels="$salesLabels"
                :datasets="[
                    [
                        'label' => 'Units sold',
                        'data' => $salesData,
                    ],
                ]"
                aria-label="Line chart showing units sold over the last six months"
            />
        </div>

        <div class="flex flex-col w-full min-h-0 overflow-hidden border border-zinc-200 rounded-xl p-6 gap-6">
            <div class="flex items-center justify-between gap-4 shrink-0">
                <div class="flex flex-col">
                    <p class="text-xl font-bold">
                        Recommended Restock
                    </p>

                    <p class="text-sm text-zinc-500">
                        Products that may need replenishment
                    </p>
                </div>

                <flux:button
                    size="sm"
                    variant="outline"
                    icon="arrow-path"
                    wire:click="loadData"
                >
                    Refresh
                </flux:button>

                @if ($restockNeeded > 0)
                    <x-wirekit::badge intent="accent">
                        {{ number_format($restockNeeded) }}
                    </x-wirekit::badge>
                @endif
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain pr-1">
                @if (count($restockRecommendations))
                    <div class="flex flex-col gap-4">
                        @foreach ($restockRecommendations as $recommendation)
                            <div
                                wire:key="restock-{{ $recommendation['id'] }}"
                                class="flex flex-col gap-4 border border-zinc-200 rounded-xl p-4 shrink-0"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex flex-col min-w-0">
                                        <p class="font-semibold truncate">
                                            {{ $recommendation['name'] }}
                                        </p>

                                        <p class="text-sm text-zinc-500 truncate">
                                            {{ $recommendation['category_name'] }}
                                        </p>
                                    </div>

                                    <x-wirekit::badge
                                        intent="{{ $recommendation['intent'] }}"
                                    >
                                        {{ $recommendation['priority'] }}
                                    </x-wirekit::badge>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-zinc-500">
                                            Stock Level
                                        </span>

                                        <span class="font-medium">
                                            {{ number_format($recommendation['current_stock']) }}
                                            /
                                            {{ number_format($recommendation['maximum_stock']) }}
                                        </span>
                                    </div>

                                    <div class="w-full h-2 rounded-full bg-zinc-100 overflow-hidden">
                                        <div
                                            class="h-full rounded-full bg-zinc-900"
                                            style="width: {{ min(100, $recommendation['stock_percentage']) }}%"
                                        ></div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="flex flex-col">
                                        <span class="text-xs text-zinc-500">
                                            Avg. Monthly Sales
                                        </span>

                                        <span class="font-semibold">
                                            {{ number_format($recommendation['average_monthly_sales'], 1) }}
                                        </span>
                                    </div>

                                    <div class="flex flex-col">
                                        <span class="text-xs text-zinc-500">
                                            Recommended Restock
                                        </span>

                                        <span class="font-semibold">
                                            +{{ number_format($recommendation['restock_quantity']) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="border-t border-zinc-200 pt-3">
                                    <p class="text-sm text-zinc-500">
                                        {{ $recommendation['reason'] }}
                                    </p>

                                    @if ($recommendation['estimated_months_remaining'] !== null)
                                        <p class="text-xs text-zinc-400 mt-1">
                                            Estimated stock remaining:
                                            {{ number_format($recommendation['estimated_months_remaining'], 1) }}
                                            month(s)
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center h-full text-center py-8">
                        <p class="font-medium">
                            Inventory looks good
                        </p>

                        <p class="text-sm text-zinc-500 mt-1 max-w-xs">
                            No products currently meet the criteria for a restock recommendation.
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div
        wire:ignore
        x-data="gabAiChat"
        x-init="init()"
        data-start-url="{{ route('gab-ai.start') }}"
        data-csrf-token="{{ csrf_token() }}"
        class="fixed bottom-6 right-6 z-50"
    >
        <div
            x-show="open"
            x-cloak
            x-transition
            class="absolute bottom-16 right-0 w-[380px] overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="flex items-center gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <div class="flex size-9 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-900/40 dark:text-primary-400">
                    <x-wirekit::icon
                        name="sparkles"
                        class="size-5"
                    />
                </div>

                <div class="min-w-0">
                    <div class="font-semibold text-zinc-900 dark:text-white">
                        Gab AI
                    </div>

                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        ERIAO AI
                    </div>
                </div>

                <div
                    class="ml-auto size-2.5 shrink-0 rounded-full"
                    :class="busy ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500'"
                ></div>
            </div>

            <div class="flex h-[450px] flex-col">
                <div
                    x-ref="messages"
                    class="flex-1 overflow-y-auto overscroll-contain p-4"
                >
                    <div class="flex flex-col gap-4">
                        <template
                            x-for="item in messages"
                            :key="item.id"
                        >
                            <div>
                                <template x-if="item.role === 'user'">
                                    <x-wirekit::message
                                        :author="['name' => 'You']"
                                        side="right"
                                    >
                                        <div
                                            class="whitespace-pre-wrap break-words text-sm"
                                            x-text="item.raw"
                                        ></div>
                                    </x-wirekit::message>
                                </template>

                                <template x-if="item.role === 'assistant'">
                                    <x-wirekit::assistant-message
                                        model="Assistant"
                                        name="GabAI"
                                    >
                                        <div
                                            class="text-sm leading-6 space-y-2 [&_p]:m-0 [&_strong]:font-semibold [&_em]:italic [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:my-1 [&_h3]:font-semibold [&_h4]:font-semibold [&_code]:rounded-md [&_code]:bg-zinc-100 [&_code]:px-1.5 [&_code]:py-0.5 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-zinc-100 [&_pre]:p-3 [&_table]:w-full [&_table]:text-xs [&_th]:border [&_th]:border-zinc-200 [&_th]:p-2 [&_td]:border [&_td]:border-zinc-200 [&_td]:p-2 dark:[&_code]:bg-zinc-800 dark:[&_pre]:bg-zinc-800 dark:[&_th]:border-zinc-700 dark:[&_td]:border-zinc-700"
                                            x-html="item.html"
                                        ></div>
                                    </x-wirekit::assistant-message>
                                </template>
                            </div>
                        </template>

                        <div
                            x-show="waitingForFirstToken"
                            x-cloak
                            x-transition
                        >
                            <x-wirekit::message-typing
                                author="Gab AI"
                                announce
                            />
                        </div>

                        <div
                            x-show="streaming"
                            x-cloak
                            x-transition
                        >
                            <x-wirekit::assistant-message
                                model="Assistant"
                                name="GabAI"
                                streaming
                            >
                                <div
                                    class="whitespace-pre-wrap break-words text-sm leading-6"
                                    x-text="streamDisplay"
                                ></div>
                            </x-wirekit::assistant-message>
                        </div>

                        <div
                            x-show="errorMessage"
                            x-cloak
                            x-transition
                        >
                            <x-wirekit::assistant-message
                                model="Gemma 3 27B"
                            >
                                <div
                                    class="text-sm leading-6"
                                    x-text="errorMessage"
                                ></div>
                            </x-wirekit::assistant-message>
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    <form
                        x-on:submit.prevent="send()"
                        class="flex flex-row items-end gap-2"
                    >
                        <flux:textarea
                            x-model="draft"
                            rows="auto"
                            resize="none"
                            class="max-h-[9rem] overflow-y-auto"
                            placeholder="Ask Gab AI about your business..."
                            x-bind:disabled="busy"
                            x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send() }"
                        />

                        <flux:button
                            type="submit"
                            variant="primary"
                            icon="paper-airplane"
                            x-bind:disabled="busy || !draft.trim()"
                            class="bg-primary hover:bg-primary"
                        />
                    </form>
                </div>
            </div>
        </div>

        <x-wirekit::fab.button
            label="AI Assistant"
            icon="sparkles"
            haspopup="false"
            x-on:click="open = !open"
            class="bg-primary hover:bg-primary"
        />
    </div>
</div>