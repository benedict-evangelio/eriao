<?php

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public $totalSales = 0;

    public $totalTransactions = 0;

    public $totalProducts = 0;

    public $totalStock = 0;

    public $inventoryValue = 0;

    public $activeUsers = 0;

    public $salesChange = 0;

    public $transactionChange = 0;

    public $stockChange = 0;

    public $userChange = 0;

    public $salesLabels = [];

    public $salesData = [];

    public $categoryLabels = [];

    public $categoryData = [];

    public $paymentLabels = [];

    public $paymentData = [];

    public $recentTransactions = [];

    public string $reportRange = 'today';

    public $reportFrom;

    public $reportTo;

    public $selectedKpi = null;

    public $kpiDetails = [];

    public $kpiRecords = [];

    public array $transactionRecordDetails = [];

    public int $kpiRecordCount = 0;

    public int $kpiRecordsShown = 0;

    public int $kpiRecordLimit = 100;

    public string $exportFormat = 'pdf';

    public string $exportData = 'all';

    public string $exportRange = 'today';

    public $exportFrom;

    public $exportTo;

    public function mount()
    {
        $this->reportFrom = now()->format('Y-m-d');
        $this->reportTo = now()->format('Y-m-d');

        $this->exportFrom = now()->format('Y-m-d');
        $this->exportTo = now()->format('Y-m-d');

        $this->loadReport();
    }

    public function loadReport()
    {
        $this->loadStatistics();
        $this->loadSalesChart();
        $this->loadCategoryChart();
        $this->loadPaymentChart();
        $this->loadRecentTransactions();
    }

    protected function loadStatistics()
    {
        [$from, $to] = $this->getReportDates();

        $salesQuery = DB::table('transactions')
            ->whereIn('status', ['completed', 'paid']);

        $transactionQuery = DB::table('transactions')
            ->whereIn('status', ['completed', 'paid']);

        if ($from && $to) {
            $salesQuery->whereBetween('created_at', [$from, $to]);
            $transactionQuery->whereBetween('created_at', [$from, $to]);
        }

        $this->totalSales = (float) $salesQuery->sum('total_amount');
        $this->totalTransactions = $transactionQuery->count();

        $this->totalProducts = DB::table('product_items')->count();
        $this->totalStock = (int) DB::table('product_items')
            ->selectRaw('COALESCE(SUM(front_quantity + warehouse_quantity), 0) as total_stock')
            ->value('total_stock');

        $this->inventoryValue = (float) DB::table('product_items')
            ->selectRaw(
                'COALESCE(SUM(quantity * price), 0) as value'
            )
            ->value('value');

        $this->activeUsers = DB::table('users')
            ->where('status', 'active')
            ->when(
                $from && $to,
                fn ($query) => $query->whereBetween('created_at', [$from, $to])
            )
            ->count();

        if ($this->reportRange === 'overall') {
            $this->salesChange = 0;
            $this->transactionChange = 0;
            $this->stockChange = 0;
            $this->userChange = 0;

            return;
        }

        [$previousFrom, $previousTo] = $this->getPreviousReportDates();

        $previousSalesQuery = DB::table('transactions')
            ->whereIn('status', ['completed', 'paid']);

        $previousTransactionQuery = DB::table('transactions')
            ->whereIn('status', ['completed', 'paid']);

        if ($previousFrom && $previousTo) {
            $previousSalesQuery->whereBetween('created_at', [$previousFrom, $previousTo]);
            $previousTransactionQuery->whereBetween('created_at', [$previousFrom, $previousTo]);
        }

        $previousSales = (float) $previousSalesQuery->sum('total_amount');
        $previousTransactions = $previousTransactionQuery->count();

        $previousActiveUsers = DB::table('users')
            ->where('status', 'active')
            ->when(
                $previousFrom && $previousTo,
                fn ($query) => $query->whereBetween('created_at', [$previousFrom, $previousTo])
            )
            ->count();

        $this->salesChange = $this->calculateChange(
            $this->totalSales,
            $previousSales
        );

        $this->transactionChange = $this->calculateChange(
            $this->totalTransactions,
            $previousTransactions
        );

        $this->stockChange = 0;

        $this->userChange = $this->calculateChange(
            $this->activeUsers,
            $previousActiveUsers
        );
    }

    protected function getReportDates(): array
    {
        $now = now();

        return match ($this->reportRange) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            'past_7_days' => [
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'past_14_days' => [
                $now->copy()->subDays(13)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'past_30_days' => [
                $now->copy()->subDays(29)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'past_quarter' => [
                $now->copy()->subMonths(3)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'past_6_months' => [
                $now->copy()->subMonths(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'past_year' => [
                $now->copy()->subYear()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'custom' => [
                $this->reportFrom ? Carbon::parse($this->reportFrom)->startOfDay() : null,
                $this->reportTo ? Carbon::parse($this->reportTo)->endOfDay() : null,
            ],
            'overall' => [null, null],
            default => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
        };
    }

    protected function getPreviousReportDates(): array
    {
        [$from, $to] = $this->getReportDates();

        if (! $from || ! $to) {
            return [null, null];
        }

        $duration = $from->diffInSeconds($to) + 1;

        return [
            $from->copy()->subSeconds($duration),
            $to->copy()->subSeconds($duration),
        ];
    }

    public function updatedReportRange()
    {
        $now = now();

        switch ($this->reportRange) {
            case 'today':
                $this->reportFrom = $now->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'yesterday':
                $this->reportFrom = $now->copy()->subDay()->format('Y-m-d');
                $this->reportTo = $this->reportFrom;
                break;

            case 'past_7_days':
                $this->reportFrom = $now->copy()->subDays(6)->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'past_14_days':
                $this->reportFrom = $now->copy()->subDays(13)->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'past_30_days':
                $this->reportFrom = $now->copy()->subDays(29)->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'past_quarter':
                $this->reportFrom = $now->copy()->subMonths(3)->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'past_6_months':
                $this->reportFrom = $now->copy()->subMonths(6)->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'past_year':
                $this->reportFrom = $now->copy()->subYear()->format('Y-m-d');
                $this->reportTo = $now->format('Y-m-d');
                break;

            case 'custom':
                Flux::modal('report-timeframe')->show();

                return;

            case 'overall':
                $this->reportFrom = null;
                $this->reportTo = null;
                break;
        }

        $this->loadStatistics();
    }

    public function updatedReportFrom()
    {
        if ($this->reportRange === 'custom') {
            $this->loadCustomReport();
        }
    }

    public function updatedReportTo()
    {
        if ($this->reportRange === 'custom') {
            $this->loadCustomReport();
        }
    }

    protected function loadCustomReport(): void
    {
        if (! $this->reportFrom || ! $this->reportTo || $this->reportFrom > $this->reportTo) {
            return;
        }

        $this->loadStatistics();
        Flux::modal('report-timeframe')->close();
    }

    public function openKpiModal(string $kpi): void
    {
        $this->selectedKpi = $kpi;
        $this->kpiRecords = [];
        $this->kpiRecordCount = 0;
        $this->kpiRecordsShown = 0;

        [$from, $to] = $this->getReportDates();

        if ($kpi === 'sales' || $kpi === 'transactions') {
            $query = DB::table('transactions')
                ->leftJoin(
                    'users',
                    'users.id',
                    '=',
                    'transactions.employee_id'
                )
                ->whereIn(
                    'transactions.status',
                    ['completed', 'paid']
                )
                ->when(
                    $from && $to,
                    fn ($query) => $query->whereBetween(
                        'transactions.created_at',
                        [$from, $to]
                    )
                );

            $this->kpiRecordCount = (clone $query)->count('transactions.id');

            $this->kpiRecords = $query
                ->select([
                    'transactions.id',
                    'transactions.id_number',
                    'transactions.first_name',
                    'transactions.last_name',
                    'transactions.payment_method',
                    'transactions.reference_num',
                    'transactions.discount_category',
                    'transactions.discount_price',
                    'transactions.tax',
                    'transactions.total_amount',
                    'transactions.status',
                    'transactions.created_at',
                    'users.first_name as employee_first_name',
                    'users.last_name as employee_last_name',
                ])
                ->orderByDesc('transactions.created_at')
                ->limit($this->kpiRecordLimit)
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'student_id' => $record->id_number ?: trim(
                            ($record->first_name ?? '').
                            ' '.
                            ($record->last_name ?? '')
                        ),
                        'payment_method' => $record->payment_method ?? '—',
                        'reference_num' => $record->reference_num ?? '—',
                        'discount_category' => $record->discount_category ?? '—',
                        'discount_price' => '₱'.number_format(
                            (float) ($record->discount_price ?? 0),
                            2
                        ),
                        'tax' => '₱'.number_format(
                            (float) ($record->tax ?? 0),
                            2
                        ),
                        'total_amount' => '₱'.number_format(
                            (float) ($record->total_amount ?? 0),
                            2
                        ),
                        'status' => $record->status ?? '—',
                        'employee' => trim(
                            ($record->employee_first_name ?? '').
                            ' '.
                            ($record->employee_last_name ?? '')
                        ) ?: '—',
                        'created_at' => $record->created_at
                            ? Carbon::parse($record->created_at)->format('M d, Y h:i A')
                            : '—',
                    ];
                })
                ->values()
                ->all();

            $this->kpiDetails = [
                'title' => $kpi === 'sales' ? 'Total Sales Records' : 'Transaction Records',
                'timeframe' => $this->reportRangeLabel(),
                'value' => $kpi === 'sales'
                    ? '₱'.number_format($this->totalSales, 2)
                    : number_format($this->totalTransactions),
                'description' => $kpi === 'sales'
                    ? 'These completed and paid transactions are the records contributing to Total Sales.'
                    : 'These completed and paid transactions are the records counted in Transactions.',
                'change' => $kpi === 'sales'
                    ? (($this->salesChange > 0 ? '+' : '').number_format($this->salesChange, 1).'%')
                    : (($this->transactionChange > 0 ? '+' : '').number_format($this->transactionChange, 1).'%'),
            ];
        } elseif ($kpi === 'stock') {
            $query = DB::table('product_items')
                ->leftJoin(
                    'product_categories',
                    'product_categories.id',
                    '=',
                    'product_items.category_id'
                );

            $this->kpiRecordCount = (clone $query)->count('product_items.id');

            $this->kpiRecords = $query
                ->select([
                    'product_items.id',
                    'product_items.name',
                    'product_categories.name as category_name',
                    'product_items.front_quantity',
                    'product_items.warehouse_quantity',
                    'product_items.reorder_level',
                    'product_items.max',
                    'product_items.price',
                    'product_items.status',
                    'product_items.created_at',
                    'product_items.updated_at',
                ])
                ->orderBy('product_items.name')
                ->limit($this->kpiRecordLimit)
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'product' => $record->name ?? '—',
                        'category' => $record->category_name ?? 'Uncategorized',
                        'quantity' => number_format((int) ($record->front_quantity ?? 0) + (int) ($record->warehouse_quantity ?? 0)),
                        'front_quantity' => number_format((int) ($record->front_quantity ?? 0)),
                        'warehouse_quantity' => number_format((int) ($record->warehouse_quantity ?? 0)),
                        'reorder_level' => number_format((int) ($record->reorder_level ?? 0)),
                        'maximum' => number_format((int) ($record->max ?? 0)),
                        'price' => '₱'.number_format(
                            (float) ($record->price ?? 0),
                            2
                        ),
                        'inventory_value' => '₱'.number_format(
                            ((float) ($record->front_quantity ?? 0) + (float) ($record->warehouse_quantity ?? 0)) *
                            (float) ($record->price ?? 0),
                            2
                        ),
                        'status' => $record->status ?? '—',
                        'updated_at' => $record->updated_at
                            ? Carbon::parse($record->updated_at)->format('M d, Y h:i A')
                            : '—',
                    ];
                })
                ->values()
                ->all();

            $this->kpiDetails = [
                'title' => 'Inventory Stock Records',
                'timeframe' => 'Current Inventory',
                'value' => number_format($this->totalStock),
                'description' => 'These product records contribute their quantities to the Inventory Stock total.',
                'change' => ($this->stockChange > 0 ? '+' : '').number_format($this->stockChange, 1).'%',
                'products' => number_format($this->totalProducts),
                'inventory_value' => '₱'.number_format($this->inventoryValue, 2),
            ];
        } elseif ($kpi === 'users') {
            $query = DB::table('users')
                ->where('status', 'active')
                ->when(
                    $from && $to,
                    fn ($query) => $query->whereBetween(
                        'created_at',
                        [$from, $to]
                    )
                );

            $this->kpiRecordCount = (clone $query)->count('id');

            $this->kpiRecords = $query
                ->select([
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'status',
                    'created_at',
                ])
                ->orderByDesc('created_at')
                ->limit($this->kpiRecordLimit)
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'name' => trim(
                            ($record->first_name ?? '').
                            ' '.
                            ($record->last_name ?? '')
                        ) ?: '—',
                        'email' => $record->email ?? '—',
                        'status' => $record->status ?? '—',
                        'created_at' => $record->created_at
                            ? Carbon::parse($record->created_at)->format('M d, Y h:i A')
                            : '—',
                    ];
                })
                ->values()
                ->all();

            $this->kpiDetails = [
                'title' => 'Active User Records',
                'timeframe' => $this->reportRangeLabel(),
                'value' => number_format($this->activeUsers),
                'description' => 'These active user records match the selected timeframe and contribute to the Active Users total.',
                'change' => ($this->userChange > 0 ? '+' : '').number_format($this->userChange, 1).'%',
            ];
        }

        $this->kpiRecordsShown = count($this->kpiRecords);

        Flux::modal('kpi-details')->show();
    }

    public function viewTransactionRecord(int $transactionId): void
    {
        $transaction = DB::table('transactions')
            ->leftJoin('users', 'users.id', '=', 'transactions.employee_id')
            ->where('transactions.id', $transactionId)
            ->whereIn('transactions.status', ['completed', 'paid'])
            ->select([
                'transactions.*',
                'users.first_name as employee_first_name',
                'users.last_name as employee_last_name',
            ])
            ->first();

        if (! $transaction) {
            return;
        }

        $items = DB::table('orders')
            ->leftJoin('product_items', 'product_items.id', '=', 'orders.product_id')
            ->where('orders.transaction_id', $transactionId)
            ->select([
                'orders.id',
                'orders.product_id',
                'product_items.name as product_name',
                'product_items.price',
            ])
            ->orderBy('orders.id')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->product_name ?? 'Missing product #'.$item->product_id,
                'price' => (float) ($item->price ?? 0),
            ])
            ->all();

        $this->transactionRecordDetails = [
            'id' => $transaction->id,
            'student_id' => $transaction->id_number ?: trim($transaction->first_name.' '.$transaction->last_name),
            'payment_method' => $transaction->payment_method,
            'reference_num' => $transaction->reference_num,
            'discount_category' => $transaction->discount_category,
            'discount_price' => (float) $transaction->discount_price,
            'tax' => (float) $transaction->tax,
            'total_amount' => (float) $transaction->total_amount,
            'status' => $transaction->status,
            'employee' => trim(($transaction->employee_first_name ?? '').' '.($transaction->employee_last_name ?? '')),
            'created_at' => $transaction->created_at,
            'items' => $items,
        ];

        Flux::modal('transaction-record-details')->show();
    }

    protected function reportRangeLabel(): string
    {
        return match ($this->reportRange) {
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'past_7_days' => 'Past 7 Days',
            'past_14_days' => 'Past 14 Days',
            'past_30_days' => 'Past 30 Days',
            'past_quarter' => 'Past Quarter',
            'past_6_months' => 'Past 6 Months',
            'past_year' => 'Past Year',
            'overall' => 'Overall',
            'custom' => (
                $this->reportFrom && $this->reportTo
            )
                ? Carbon::parse($this->reportFrom)->format('M d, Y')
                    .' - '
                    .Carbon::parse($this->reportTo)->format('M d, Y')
                : 'Custom Date',
            default => 'Today',
        };
    }

    protected function calculateChange($current, $previous)
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100 : 0;
        }

        return round(
            (($current - $previous) / $previous) * 100,
            1
        );
    }

    protected function loadSalesChart()
    {
        $this->salesLabels = [];
        $this->salesData = [];

        for ($i = 5; $i >= 0; $i--) {

            $date = now()->subMonths($i);

            $this->salesLabels[] = $date->format('M Y');

            $sales = DB::table('transactions')
                ->whereIn('status', ['completed', 'paid'])
                ->whereBetween('created_at', [
                    $date->copy()->startOfMonth(),
                    $date->copy()->endOfMonth(),
                ])
                ->sum('total_amount');

            $this->salesData[] = round(
                (float) $sales,
                2
            );
        }
    }

    protected function loadCategoryChart()
    {
        $categories = DB::table('product_categories')
            ->leftJoin(
                'product_items',
                'product_items.category_id',
                '=',
                'product_categories.id'
            )
            ->select(
                'product_categories.name',
                DB::raw(
                    'COALESCE(SUM(product_items.front_quantity + product_items.warehouse_quantity), 0) as quantity'
                )
            )
            ->groupBy(
                'product_categories.id',
                'product_categories.name'
            )
            ->orderBy(
                'product_categories.name'
            )
            ->get();

        $this->categoryLabels = $categories
            ->pluck('name')
            ->values()
            ->all();

        $this->categoryData = $categories
            ->pluck('quantity')
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    protected function loadPaymentChart()
    {
        $payments = DB::table('transactions')
            ->select(
                'payment_method',
                DB::raw('COUNT(*) as total')
            )
            ->whereIn('status', ['completed', 'paid'])
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $this->paymentLabels = $payments
            ->pluck('payment_method')
            ->values()
            ->all();

        $this->paymentData = $payments
            ->pluck('total')
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        if (empty($this->paymentLabels)) {
            $this->paymentLabels = ['No Transactions'];
            $this->paymentData = [0];
        }
    }

    protected function loadRecentTransactions()
    {
        $this->recentTransactions = DB::table('transactions')
            ->leftJoin(
                'users',
                'users.id',
                '=',
                'transactions.employee_id'
            )
            ->select([
                'transactions.id',
                'transactions.first_name',
                'transactions.last_name',
                'transactions.payment_method',
                'transactions.total_amount',
                'transactions.status',
                'transactions.created_at',
                'users.first_name as employee_first_name',
                'users.last_name as employee_last_name',
            ])
            ->orderByDesc('transactions.created_at')
            ->limit(8)
            ->get();
    }

    protected function getExportDates(): array
    {
        $now = now();

        return match ($this->exportRange) {

            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],

            'past_7_days' => [
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'past_14_days' => [
                $now->copy()->subDays(13)->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'past_30_days' => [
                $now->copy()->subDays(29)->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'past_quarter' => [
                $now->copy()->subMonths(3)->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'past_6_months' => [
                $now->copy()->subMonths(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'past_year' => [
                $now->copy()->subYear()->startOfDay(),
                $now->copy()->endOfDay(),
            ],

            'custom' => [
                Carbon::parse($this->exportFrom)->startOfDay(),
                Carbon::parse($this->exportTo)->endOfDay(),
            ],

            'overall' => [
                null,
                null,
            ],

            default => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
        };
    }

    public function updatedExportRange()
    {
        $now = now();

        switch ($this->exportRange) {

            case 'today':

                $this->exportFrom = $now->format('Y-m-d');
                $this->exportTo = $now->format('Y-m-d');

                break;

            case 'yesterday':

                $this->exportFrom = $now
                    ->copy()
                    ->subDay()
                    ->format('Y-m-d');

                $this->exportTo = $this->exportFrom;

                break;

            case 'past_7_days':

                $this->exportFrom = $now
                    ->copy()
                    ->subDays(6)
                    ->format('Y-m-d');

                $this->exportTo = $now->format('Y-m-d');

                break;

            case 'past_14_days':

                $this->exportFrom = $now
                    ->copy()
                    ->subDays(13)
                    ->format('Y-m-d');

                $this->exportTo = $now->format('Y-m-d');

                break;

            case 'past_30_days':

                $this->exportFrom = $now
                    ->copy()
                    ->subDays(29)
                    ->format('Y-m-d');

                $this->exportTo = $now->format('Y-m-d');

                break;

            case 'past_quarter':

                $this->exportFrom = $now
                    ->copy()
                    ->subMonths(3)
                    ->format('Y-m-d');

                $this->exportTo = $now->format('Y-m-d');

                break;

            case 'past_6_months':

                $this->exportFrom = $now
                    ->copy()
                    ->subMonths(6)
                    ->format('Y-m-d');

                $this->exportTo = $now->format('Y-m-d');

                break;

            case 'past_year':

                $this->exportFrom = $now
                    ->copy()
                    ->subYear()
                    ->format('Y-m-d');

                $this->exportTo = $now->format('Y-m-d');

                break;
        }
    }

    public function openExportModal(): void
    {
        $this->resetValidation();

        Flux::modal('export-report')->show();
    }

    protected function validateExport(): void
    {
        if ($this->exportRange !== 'custom') {
            return;
        }

        $this->validate([
            'exportFrom' => ['required', 'date_format:Y-m-d'],
            'exportTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:exportFrom'],
        ], [
            'exportFrom.required' => 'Please select a start date.',
            'exportFrom.date_format' => 'Please select a valid start date.',
            'exportTo.required' => 'Please select an end date.',
            'exportTo.date_format' => 'Please select a valid end date.',
            'exportTo.after_or_equal' => 'The end date cannot be earlier than the start date.',
        ]);
    }

    protected function exportTitle(): string
    {
        return match ($this->exportData) {
            'sales' => 'Sales Report',
            'inventory' => 'Inventory Report',
            'purchase_orders' => 'Purchase Orders Report',
            default => 'External Relations and International Affairs Office',
        };
    }

    protected function exportRangeLabel(): string
    {
        return match ($this->exportRange) {

            'today' => 'Today',

            'yesterday' => 'Yesterday',

            'past_7_days' => 'Past 7 Days',

            'past_14_days' => 'Past 14 Days',

            'past_30_days' => 'Past 30 Days',

            'past_quarter' => 'Past Quarter',

            'past_6_months' => 'Past 6 Months',

            'past_year' => 'Past Year',

            'overall' => 'Overall',

            'custom' => (
                Carbon::canBeCreatedFromFormat($this->exportFrom, 'Y-m-d') &&
                Carbon::canBeCreatedFromFormat($this->exportTo, 'Y-m-d')
            )
                ? Carbon::parse($this->exportFrom)->format('M d, Y')
                    .' - '
                    .Carbon::parse($this->exportTo)->format('M d, Y')
                : 'Custom Date',

            default => 'Today',
        };
    }

    protected function getSalesExportData($from, $to)
    {
        return DB::table('transactions')
            ->leftJoin(
                'users',
                'users.id',
                '=',
                'transactions.employee_id'
            )
            ->select([
                'transactions.id',
                'transactions.id_number',
                'transactions.first_name',
                'transactions.last_name',
                'transactions.payment_method',
                'transactions.reference_num',
                'transactions.discount_category',
                'transactions.discount_price',
                'transactions.tax',
                'transactions.total_amount',
                'transactions.cash',
                'transactions.change',
                'transactions.status',
                'transactions.created_at',
                'users.first_name as employee_first_name',
                'users.last_name as employee_last_name',
            ])
            ->when(
                $from && $to,
                fn ($query) => $query->whereBetween(
                    'transactions.created_at',
                    [$from, $to]
                )
            )
            ->whereIn(
                'transactions.status',
                ['completed', 'paid']
            )
            ->orderByDesc(
                'transactions.created_at'
            )
            ->get();
    }

    protected function getInventoryExportData($from, $to)
    {
        return DB::table('product_items')
            ->leftJoin(
                'product_categories',
                'product_categories.id',
                '=',
                'product_items.category_id'
            )
            ->select([
                'product_items.id',
                'product_items.name',
                'product_categories.name as category_name',
                DB::raw('(product_items.front_quantity + product_items.warehouse_quantity) as quantity'),
                'product_items.max',
                'product_items.price',
                'product_items.status',
                'product_items.created_at',
                'product_items.updated_at',
            ])
            ->when(
                $from && $to,
                fn ($query) => $query->whereBetween(
                    'product_items.created_at',
                    [$from, $to]
                )
            )
            ->orderBy(
                'product_items.name'
            )
            ->get();
    }

    protected function getPurchaseOrderExportData($from, $to)
    {
        return DB::table('purchase_order')
            ->join(
                'users',
                'users.id',
                '=',
                'purchase_order.requested_by'
            )
            ->select([
                'purchase_order.id',
                'purchase_order.requested_by',
                'purchase_order.approved_by',
                'purchase_order.quantity',
                'purchase_order.total_amount',
                'purchase_order.status',
                'purchase_order.created_at',
                'users.first_name as requester_first_name',
                'users.last_name as requester_last_name',
                'users.email as requester_email',
            ])
            ->selectSub(
                DB::table('product_order_items')
                    ->join(
                        'product_items',
                        'product_items.id',
                        '=',
                        'product_order_items.product_id'
                    )
                    ->selectRaw(
                        "GROUP_CONCAT(product_items.name ORDER BY product_items.name SEPARATOR ', ')"
                    )
                    ->whereColumn(
                        'product_order_items.purchase_order_id',
                        'purchase_order.id'
                    ),
                'product_names'
            )
            ->when(
                $from && $to,
                fn ($query) => $query->whereBetween(
                    'purchase_order.created_at',
                    [$from, $to]
                )
            )
            ->orderByDesc(
                'purchase_order.created_at'
            )
            ->get();
    }

    public function exportReport()
    {
        $this->validateExport();

        [$from, $to] = $this->getExportDates();

        if ($this->exportFormat === 'csv') {
            Flux::modal('export-report')->close();

            return $this->exportCsv(
                $from,
                $to
            );
        }

        return $this->exportPdf(
            $from,
            $to
        );
    }

    protected function exportCsv($from, $to)
    {
        $filename = match ($this->exportData) {

            'sales' => 'sales-report',

            'inventory' => 'inventory-report',

            'purchase_orders' => 'purchase-orders-report',

            default => 'business-report',
        };

        $filename .= '-'
            .$this->exportRange
            .'-'
            .now()->format('Y-m-d-His')
            .'.csv';

        return response()->streamDownload(
            function () use ($from, $to) {

                $handle = fopen(
                    'php://output',
                    'w'
                );

                if ($this->exportData === 'all') {

                    $this->writeSalesCsv(
                        $handle,
                        $from,
                        $to
                    );

                    $this->writeInventoryCsv(
                        $handle,
                        $from,
                        $to
                    );

                    $this->writePurchaseOrdersCsv(
                        $handle,
                        $from,
                        $to
                    );
                }

                if ($this->exportData === 'sales') {

                    $this->writeSalesCsv(
                        $handle,
                        $from,
                        $to
                    );
                }

                if ($this->exportData === 'inventory') {

                    $this->writeInventoryCsv(
                        $handle,
                        $from,
                        $to
                    );
                }

                if ($this->exportData === 'purchase_orders') {

                    $this->writePurchaseOrdersCsv(
                        $handle,
                        $from,
                        $to
                    );
                }

                fclose($handle);
            },
            $filename
        );
    }

    protected function writeSalesCsv(
        $handle,
        $from,
        $to
    ): void {

        fputcsv($handle, [
            'SALES',
        ]);

        fputcsv($handle, [
            'Transaction ID',
            'Customer ID',
            'Customer',
            'Payment Method',
            'Reference Number',
            'Discount Category',
            'Discount',
            'Tax',
            'Total Amount',
            'Cash',
            'Change',
            'Status',
            'Employee',
            'Date',
        ]);

        $transactions = $this->getSalesExportData(
            $from,
            $to
        );

        foreach ($transactions as $transaction) {

            fputcsv($handle, [
                $transaction->id,

                $transaction->id_number,

                trim(
                    $transaction->first_name.
                    ' '.
                    $transaction->last_name
                ),

                $transaction->payment_method,

                $transaction->reference_num,

                $transaction->discount_category,

                number_format(
                    (float) $transaction->discount_price,
                    2,
                    '.',
                    ''
                ),

                number_format(
                    (float) $transaction->tax,
                    2,
                    '.',
                    ''
                ),

                number_format(
                    (float) $transaction->total_amount,
                    2,
                    '.',
                    ''
                ),

                number_format(
                    (float) $transaction->cash,
                    2,
                    '.',
                    ''
                ),

                number_format(
                    (float) $transaction->change,
                    2,
                    '.',
                    ''
                ),

                $transaction->status,

                trim(
                    ($transaction->employee_first_name ?? '').
                    ' '.
                    ($transaction->employee_last_name ?? '')
                ),

                $transaction->created_at,
            ]);
        }

        fputcsv($handle, []);
    }

    protected function writeInventoryCsv(
        $handle,
        $from,
        $to
    ): void {

        fputcsv($handle, [
            'INVENTORY',
        ]);

        fputcsv($handle, [
            'Product ID',
            'Product',
            'Category',
            'Quantity',
            'Maximum Quantity',
            'Price',
            'Inventory Value',
            'Status',
            'Created At',
            'Updated At',
        ]);

        $products = $this->getInventoryExportData(
            $from,
            $to
        );

        foreach ($products as $product) {

            fputcsv($handle, [
                $product->id,

                $product->name,

                $product->category_name,

                $product->quantity,

                $product->max,

                number_format(
                    (float) $product->price,
                    2,
                    '.',
                    ''
                ),

                number_format(
                    (float) $product->quantity *
                    (float) $product->price,
                    2,
                    '.',
                    ''
                ),

                $product->status,

                $product->created_at,

                $product->updated_at,
            ]);
        }

        fputcsv($handle, []);
    }

    protected function writePurchaseOrdersCsv(
        $handle,
        $from,
        $to
    ): void {

        fputcsv($handle, [
            'PURCHASE ORDERS',
        ]);

        fputcsv($handle, [
            'PO Number',
            'Products',
            'Requested By',
            'Requester Email',
            'Quantity',
            'Total Amount',
            'Status',
            'Created At',
        ]);

        $orders = $this->getPurchaseOrderExportData(
            $from,
            $to
        );

        foreach ($orders as $order) {

            fputcsv($handle, [
                'PO-'.str_pad(
                    $order->id,
                    5,
                    '0',
                    STR_PAD_LEFT
                ),

                $order->product_names,

                trim(
                    $order->requester_first_name.
                    ' '.
                    $order->requester_last_name
                ),

                $order->requester_email,

                $order->quantity,

                number_format(
                    (float) $order->total_amount,
                    2,
                    '.',
                    ''
                ),

                $order->status,

                $order->created_at,
            ]);
        }

        fputcsv($handle, []);
    }

    protected function sanitizePdfText($value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        if (function_exists('iconv')) {
            $clean = iconv(
                'UTF-8',
                'UTF-8//IGNORE',
                $value
            );

            if ($clean !== false) {
                return $clean;
            }
        }

        return mb_convert_encoding(
            $value,
            'UTF-8',
            'UTF-8'
        );
    }

    protected function pdfText($value): string
    {
        return e(
            $this->sanitizePdfText($value)
        );
    }

    protected function exportPdf($from, $to)
    {
        $sales = collect();
        $inventory = collect();
        $purchaseOrders = collect();

        if (
            in_array(
                $this->exportData,
                ['all', 'sales']
            )
        ) {

            $sales = $this->getSalesExportData(
                $from,
                $to
            );
        }

        if (
            in_array(
                $this->exportData,
                ['all', 'inventory']
            )
        ) {

            $inventory = $this->getInventoryExportData(
                $from,
                $to
            );
        }

        if (
            in_array(
                $this->exportData,
                ['all', 'purchase_orders']
            )
        ) {

            $purchaseOrders =
                $this->getPurchaseOrderExportData(
                    $from,
                    $to
                );
        }

        $html = $this->buildPdfHtml(
            $sales,
            $inventory,
            $purchaseOrders
        );

        $pdfContent = Pdf::loadHTML($html)
            ->setPaper(
                'a4',
                'landscape'
            )
            ->output();

        $filename = match ($this->exportData) {

            'sales' => 'sales-report',

            'inventory' => 'inventory-report',

            'purchase_orders' => 'purchase-orders-report',

            default => 'business-report',
        };

        $filename .= '-'
            .$this->exportRange
            .'-'
            .now()->format('Y-m-d-His')
            .'-'
            .Str::lower(
                Str::random(8)
            )
            .'.pdf';

        Storage::disk('local')->put(
            'reports/'.$filename,
            $pdfContent
        );

        Flux::modal('export-report')->close();

        $this->dispatch(
            'report-download-ready',
            url: route(
                'reports.download',
                [
                    'filename' => $filename,
                ]
            ),
            filename: $filename
        );
    }

    protected function buildPdfHtml(
        $sales,
        $inventory,
        $purchaseOrders
    ): string {

        $title = $this->pdfText(
            $this->exportTitle()
        );

        $range = $this->pdfText(
            $this->exportRangeLabel()
        );

        $generatedAt = $this->pdfText(
            now()->format(
                'M d, Y h:i A'
            )
        );

        $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">

                <style>
                    body {
                        font-family: DejaVu Sans, sans-serif;
                        font-size: 9px;
                        color: #18181b;
                    }

                    h1 {
                        font-size: 20px;
                        margin: 0 0 4px 0;
                    }

                    h2 {
                        font-size: 13px;
                        margin: 22px 0 8px 0;
                    }

                    p {
                        margin: 0 0 3px 0;
                    }

                    .meta {
                        color: #71717a;
                        margin-bottom: 18px;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-bottom: 18px;
                    }

                    th {
                        background: #f4f4f5;
                        text-align: left;
                        font-weight: bold;
                    }

                    th,
                    td {
                        border: 1px solid #d4d4d8;
                        padding: 5px;
                        vertical-align: top;
                    }

                    .right {
                        text-align: right;
                    }

                    .summary {
                        margin-top: 5px;
                        margin-bottom: 12px;
                    }
                </style>
            </head>

            <body>

                <h1>'.$title.'</h1>

                <div class="meta">

                    <p>
                        Timeframe: '.$range.'
                    </p>

                    <p>
                        Generated: '.$generatedAt.'
                    </p>

                </div>
        ';

        if (
            in_array(
                $this->exportData,
                ['all', 'sales']
            )
        ) {

            $totalSales = $sales->sum(
                fn ($transaction) => (float) $transaction->total_amount
            );

            $html .= '
                <h2>Sales</h2>

                <div class="summary">
                    Transactions: '.
                    number_format(
                        $sales->count()
                    ).
                    '&nbsp;&nbsp;&nbsp;
                    Total Sales: PHP '.
                    number_format(
                        $totalSales,
                        2
                    ).
                '</div>

                <table>

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Customer ID</th>
                            <th>Customer</th>
                            <th>Payment</th>
                            <th>Reference</th>
                            <th>Discount</th>
                            <th>Tax</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Employee</th>
                            <th>Date</th>
                        </tr>

                    </thead>

                    <tbody>
            ';

            foreach ($sales as $transaction) {

                $customer = trim(
                    $transaction->first_name.
                    ' '.
                    $transaction->last_name
                );

                $employee = trim(
                    ($transaction->employee_first_name ?? '').
                    ' '.
                    ($transaction->employee_last_name ?? '')
                );

                $transactionDate =
                    Carbon::parse(
                        $transaction->created_at
                    )->format(
                        'M d, Y h:i A'
                    );

                $html .= '
                    <tr>

                        <td>'.
                            $this->pdfText(
                                $transaction->id
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $transaction->id_number
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $customer
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $transaction->payment_method
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $transaction->reference_num
                            ).
                        '</td>

                        <td class="right">
                            PHP '.
                            number_format(
                                (float) $transaction->discount_price,
                                2
                            ).
                        '</td>

                        <td class="right">
                            PHP '.
                            number_format(
                                (float) $transaction->tax,
                                2
                            ).
                        '</td>

                        <td class="right">
                            PHP '.
                            number_format(
                                (float) $transaction->total_amount,
                                2
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $transaction->status
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $employee
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $transactionDate
                            ).
                        '</td>

                    </tr>
                ';
            }

            $html .= '
                    </tbody>

                </table>
            ';
        }

        if (
            in_array(
                $this->exportData,
                ['all', 'inventory']
            )
        ) {

            $inventoryValue = $inventory->sum(
                fn ($product) => (float) $product->quantity *
                    (float) $product->price
            );

            $html .= '
                <h2>Inventory</h2>

                <div class="summary">
                    Products: '.
                    number_format(
                        $inventory->count()
                    ).
                    '&nbsp;&nbsp;&nbsp;
                    Inventory Value: PHP '.
                    number_format(
                        $inventoryValue,
                        2
                    ).
                '</div>

                <table>

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Maximum</th>
                            <th>Price</th>
                            <th>Value</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>

                    </thead>

                    <tbody>
            ';

            foreach ($inventory as $product) {

                $productValue =
                    (float) $product->quantity *
                    (float) $product->price;

                $productDate =
                    Carbon::parse(
                        $product->created_at
                    )->format(
                        'M d, Y h:i A'
                    );

                $html .= '
                    <tr>

                        <td>'.
                            $this->pdfText(
                                $product->id
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $product->name
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $product->category_name
                            ).
                        '</td>

                        <td class="right">'.
                            number_format(
                                (int) $product->quantity
                            ).
                        '</td>

                        <td class="right">'.
                            number_format(
                                (int) $product->max
                            ).
                        '</td>

                        <td class="right">
                            PHP '.
                            number_format(
                                (float) $product->price,
                                2
                            ).
                        '</td>

                        <td class="right">
                            PHP '.
                            number_format(
                                $productValue,
                                2
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $product->status
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $productDate
                            ).
                        '</td>

                    </tr>
                ';
            }

            $html .= '
                    </tbody>

                </table>
            ';
        }

        if (
            in_array(
                $this->exportData,
                ['all', 'purchase_orders']
            )
        ) {

            $totalPurchaseOrders =
                $purchaseOrders->count();

            $totalPurchaseOrderAmount =
                $purchaseOrders->sum(
                    fn ($order) => (float) $order->total_amount
                );

            $html .= '
                <h2>Purchase Orders</h2>

                <div class="summary">
                    Purchase Orders: '.
                    number_format(
                        $totalPurchaseOrders
                    ).
                    '&nbsp;&nbsp;&nbsp;
                    Total Amount: PHP '.
                    number_format(
                        $totalPurchaseOrderAmount,
                        2
                    ).
                '</div>

                <table>

                    <thead>

                        <tr>
                            <th>PO #</th>
                            <th>Products</th>
                            <th>Requested By</th>
                            <th>Quantity</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>

                    </thead>

                    <tbody>
            ';

            foreach ($purchaseOrders as $order) {

                $requester = trim(
                    $order->requester_first_name.
                    ' '.
                    $order->requester_last_name
                );

                $orderDate =
                    Carbon::parse(
                        $order->created_at
                    )->format(
                        'M d, Y h:i A'
                    );

                $html .= '
                    <tr>

                        <td>
                            PO-'.
                            str_pad(
                                $order->id,
                                5,
                                '0',
                                STR_PAD_LEFT
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $order->product_names
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $requester
                            ).
                        '</td>

                        <td class="right">'.
                            number_format(
                                (int) $order->quantity
                            ).
                        '</td>

                        <td class="right">
                            PHP '.
                            number_format(
                                (float) $order->total_amount,
                                2
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $order->status
                            ).
                        '</td>

                        <td>'.
                            $this->pdfText(
                                $orderDate
                            ).
                        '</td>

                    </tr>
                ';
            }

            $html .= '
                    </tbody>

                </table>
            ';
        }

        $html .= '
            </body>
            </html>
        ';

        return $html;
    }
};
?>

<div
    class="flex flex-col w-full h-full gap-8"
    x-data
    x-on:report-download-ready.window="
        const detail = $event.detail;

        fetch(detail.url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Unable to download report.');
                }

                return response.blob();
            })
            .then(blob => {
                const blobUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');

                link.href = blobUrl;
                link.download = detail.filename;

                document.body.appendChild(link);
                link.click();
                link.remove();

                URL.revokeObjectURL(blobUrl);
            })
            .catch(() => {
                window.open(detail.url, '_blank');
            });
    "
>

    <div class="flex flex-row items-center">

        <div class="flex flex-col">

            <p class="text-xl font-bold">
                Reports
            </p>

            <p>
                Manage your reports
            </p>

        </div>

        <flux:spacer />

        <div class="flex items-center gap-2">
            <flux:select
                wire:model.live="reportRange"
                placeholder="Select timeframe"
                class="w-44"
            >
                <flux:select.option value="today">Today</flux:select.option>
                <flux:select.option value="yesterday">Yesterday</flux:select.option>
                <flux:select.option value="past_7_days">Past 7 Days</flux:select.option>
                <flux:select.option value="past_14_days">Past 14 Days</flux:select.option>
                <flux:select.option value="past_30_days">Past 30 Days</flux:select.option>
                <flux:select.option value="past_quarter">Past Quarter</flux:select.option>
                <flux:select.option value="past_6_months">Past 6 Months</flux:select.option>
                <flux:select.option value="past_year">Past Year</flux:select.option>
                <flux:select.option value="overall">Overall</flux:select.option>
                <flux:select.option value="custom">Custom</flux:select.option>
            </flux:select>

            <flux:button
                wire:click="openExportModal"
                variant="primary"
                icon="arrow-down-tray"
                class="bg-primary hover:bg-primary"     
            >
                Export Report
            </flux:button>
        </div>

    </div>

    

    <x-wirekit::stats cols="4">

        <x-wirekit::stat
            wire:click="openKpiModal('sales')"
            class="cursor-pointer"
            label="Total Sales"
            value="₱{{ number_format($totalSales, 2) }}"
            change="{{ $salesChange > 0 ? '+' : '' }}{{ number_format($salesChange, 1) }}%"
            trend="{{ $salesChange > 0 ? 'up' : ($salesChange < 0 ? 'down' : 'neutral') }}"
            description="{{ $this->reportRangeLabel() }}"
        />

        <x-wirekit::stat
            wire:click="openKpiModal('transactions')"
            class="cursor-pointer"
            label="Transactions"
            value="{{ number_format($totalTransactions) }}"
            change="{{ $transactionChange > 0 ? '+' : '' }}{{ number_format($transactionChange, 1) }}%"
            trend="{{ $transactionChange > 0 ? 'up' : ($transactionChange < 0 ? 'down' : 'neutral') }}"
            description="{{ $this->reportRangeLabel() }}"
        />

        <x-wirekit::stat
            wire:click="openKpiModal('stock')"
            class="cursor-pointer"
            label="Inventory Stock"
            value="{{ number_format($totalStock) }}"
            change="{{ $stockChange > 0 ? '+' : '' }}{{ number_format($stockChange, 1) }}%"
            trend="{{ $stockChange > 0 ? 'up' : ($stockChange < 0 ? 'down' : 'neutral') }}"
            description="{{ number_format($totalProducts) }} products"
        />

        <x-wirekit::stat
            wire:click="openKpiModal('users')"
            class="cursor-pointer"
            label="Active Users"
            value="{{ number_format($activeUsers) }}"
            change="{{ $userChange > 0 ? '+' : '' }}{{ number_format($userChange, 1) }}%"
            trend="{{ $userChange > 0 ? 'up' : ($userChange < 0 ? 'down' : 'neutral') }}"
            description="{{ $this->reportRangeLabel() }}"
        />

    </x-wirekit::stats>

    <div wire:ignore class="grid grid-cols-3 gap-4 w-full h-full">

        <div class="flex flex-col w-full col-span-2">

            <x-wirekit-chart
                library="apexcharts"
                type="line"
                valueDecimals="2"
                valuePrefix="₱"
                height="100%"
                :labels="$salesLabels"
                :datasets="[
                    [
                        'label' => 'Sales',
                        'data' => $salesData,
                    ]
                ]"
                aria-label="Line chart showing sales for the last six months"
            />

        </div>

        <div class="grid grid-cols-1 grid-rows-2 gap-4">

            <x-wirekit-chart
                type="doughnut"
                :labels="$categoryLabels"
                :datasets="[
                    [
                        'data' => $categoryData,
                    ]
                ]"
                height="100"
                aria-label="Doughnut chart showing current inventory by category"
            />

            <x-wirekit-chart
                type="doughnut"
                :labels="$paymentLabels"
                :datasets="[
                    [
                        'data' => $paymentData,
                    ]
                ]"
                height="100"
                aria-label="Doughnut chart showing transactions by payment method"
            />

        </div>

    </div>

    <flux:modal
        name="report-timeframe"
        class="max-w-lg w-full"
    >
        <div class="flex flex-col gap-6">
            <div>
                <p class="text-xl font-bold">Custom Timeframe</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Select the dates to use for the KPI cards.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:input
                    type="date"
                    wire:model.live="reportFrom"
                    label="Start Date"
                />

                <flux:input
                    type="date"
                    wire:model.live="reportTo"
                    label="End Date"
                />
            </div>

            @if ($reportFrom && $reportTo && $reportFrom > $reportTo)
                <flux:text size="sm" class="text-red-600">
                    The end date cannot be earlier than the start date.
                </flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button
                    wire:click="loadCustomReport"
                    wire:loading.attr="disabled"
                    variant="primary"
                >
                    Apply
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal
        name="kpi-details"
        class="max-w-7xl w-full"
    >
        <div class="flex flex-col gap-6">
            <div>
                <p class="text-xl font-bold">
                    {{ $kpiDetails['title'] ?? 'KPI Details' }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $kpiDetails['timeframe'] ?? '' }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">KPI Value</p>
                    <p class="mt-1 text-2xl font-bold">
                        {{ $kpiDetails['value'] ?? '—' }}
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Records Contributing</p>
                    <p class="mt-1 text-2xl font-bold">
                        {{ number_format($kpiRecordCount) }}
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Change</p>
                    <p class="mt-1 text-2xl font-bold">
                        {{ $kpiDetails['change'] ?? '0.0%' }}
                    </p>
                </div>
            </div>

            <div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $kpiDetails['description'] ?? '' }}
                </p>

                @if ($kpiRecordCount > $kpiRecordsShown)
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Showing the latest {{ number_format($kpiRecordsShown) }} of {{ number_format($kpiRecordCount) }} contributing records.
                    </p>
                @else
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Showing all contributing records.
                    </p>
                @endif
            </div>

            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="max-h-[28rem] overflow-auto">
                    @if ($selectedKpi === 'sales' || $selectedKpi === 'transactions')
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-800">
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="px-4 py-3 text-left font-medium">ID</th>
                                    <th class="px-4 py-3 text-left font-medium">Student ID</th>
                                    <th class="px-4 py-3 text-left font-medium">Payment</th>
                                    <th class="px-4 py-3 text-left font-medium">Reference</th>
                                    <th class="px-4 py-3 text-right font-medium">Discount</th>
                                    <th class="px-4 py-3 text-right font-medium">Tax</th>
                                    <th class="px-4 py-3 text-right font-medium">Total</th>
                                    <th class="px-4 py-3 text-left font-medium">Status</th>
                                    <th class="px-4 py-3 text-left font-medium">Employee</th>
                                    <th class="px-4 py-3 text-left font-medium">Date</th>
                                    <th class="px-4 py-3 text-left font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($kpiRecords as $record)
                                    <tr class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700">
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['id'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['student_id'] ?: '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['payment_method'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['reference_num'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ $record['discount_price'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ $record['tax'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ $record['total_amount'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['status'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['employee'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['created_at'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                wire:click="viewTransactionRecord({{ $record['id'] }})"
                                            >
                                                View more
                                            </flux:button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                            No contributing records were found for this timeframe.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif ($selectedKpi === 'stock')
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-800">
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="px-4 py-3 text-left font-medium">ID</th>
                                    <th class="px-4 py-3 text-left font-medium">Product</th>
                                    <th class="px-4 py-3 text-left font-medium">Category</th>
                                    <th class="px-4 py-3 text-right font-medium">Quantity</th>
                                    <th class="px-4 py-3 text-right font-medium">Maximum</th>
                                    <th class="px-4 py-3 text-right font-medium">Price</th>
                                    <th class="px-4 py-3 text-right font-medium">Inventory Value</th>
                                    <th class="px-4 py-3 text-left font-medium">Status</th>
                                    <th class="px-4 py-3 text-left font-medium">Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($kpiRecords as $record)
                                    <tr class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700">
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['id'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['product'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['category'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ $record['quantity'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ $record['maximum'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">{{ $record['price'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ $record['inventory_value'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['status'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['updated_at'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                            No inventory records were found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif ($selectedKpi === 'users')
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-800">
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="px-4 py-3 text-left font-medium">ID</th>
                                    <th class="px-4 py-3 text-left font-medium">Name</th>
                                    <th class="px-4 py-3 text-left font-medium">Email</th>
                                    <th class="px-4 py-3 text-left font-medium">Status</th>
                                    <th class="px-4 py-3 text-left font-medium">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($kpiRecords as $record)
                                    <tr class="border-b border-zinc-200 last:border-b-0 dark:border-zinc-700">
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['id'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['name'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['email'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['status'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">{{ $record['created_at'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                            No active user records were found for this timeframe.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="transaction-record-details" class="max-w-xl w-full">
        <div class="flex flex-col gap-5">
            <div>
                <p class="text-lg font-semibold">Transaction #{{ $transactionRecordDetails['id'] ?? '' }}</p>
                <p class="text-sm text-zinc-500">Purchased items and transaction details</p>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">Student ID</dt><dd class="font-medium">{{ $transactionRecordDetails['student_id'] ?? '—' }}</dd></div>
                <div><dt class="text-zinc-500">Status</dt><dd class="font-medium">{{ ucfirst($transactionRecordDetails['status'] ?? '—') }}</dd></div>
                <div><dt class="text-zinc-500">Payment</dt><dd class="font-medium">{{ $transactionRecordDetails['payment_method'] ?? '—' }}</dd></div>
                <div><dt class="text-zinc-500">Reference</dt><dd class="font-medium">{{ ($transactionRecordDetails['reference_num'] ?? '') ?: '—' }}</dd></div>
                <div><dt class="text-zinc-500">Discount</dt><dd class="font-medium">{{ ($transactionRecordDetails['discount_category'] ?? '') ?: 'None' }} · ₱{{ number_format((float) ($transactionRecordDetails['discount_price'] ?? 0), 2) }}</dd></div>
                <div><dt class="text-zinc-500">Tax</dt><dd class="font-medium">₱{{ number_format((float) ($transactionRecordDetails['tax'] ?? 0), 2) }}</dd></div>
                <div><dt class="text-zinc-500">Employee</dt><dd class="font-medium">{{ ($transactionRecordDetails['employee'] ?? '') ?: '—' }}</dd></div>
                <div><dt class="text-zinc-500">Date</dt><dd class="font-medium">{{ !empty($transactionRecordDetails['created_at']) ? Carbon::parse($transactionRecordDetails['created_at'])->format('M d, Y h:i A') : '—' }}</dd></div>
            </dl>

            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="max-h-64 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse (($transactionRecordDetails['items'] ?? []) as $item)
                        <div class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                            <span>{{ $item['name'] }}</span>
                            <span class="font-medium">₱{{ number_format($item['price'], 2) }}</span>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-zinc-500">No purchased item records found.</p>
                    @endforelse
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <span class="font-semibold">Total</span>
                <span class="text-lg font-bold">₱{{ number_format((float) ($transactionRecordDetails['total_amount'] ?? 0), 2) }}</span>
            </div>

            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="ghost">Close</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal
        name="export-report"
        class="max-w-2xl w-full"
    >

        <div class="flex flex-col gap-6">

            <div>

                <p class="text-xl font-bold">
                    Export Report
                </p>

                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Choose the format, data, and timeframe for your report.
                </p>

            </div>

            <div class="flex flex-col gap-3">

                <flux:label>
                    Export As
                </flux:label>

                <flux:radio.group
                    wire:model.live="exportFormat"
                    variant="segmented"
                    class="w-full"
                >

                    <flux:radio
                        value="pdf"
                        label="Export as PDF"
                    />

                    <flux:radio
                        value="csv"
                        label="Export as CSV"
                    />

                </flux:radio.group>

            </div>

            <div class="flex flex-col gap-3">

                <flux:label>
                    Data to Export
                </flux:label>

                <flux:radio.group
                    wire:model.live="exportData"
                    variant="segmented"
                    class="w-full"
                >

                    <flux:radio
                        value="all"
                        label="All"
                    />

                    <flux:radio
                        value="sales"
                        label="Sales"
                    />

                    <flux:radio
                        value="inventory"
                        label="Inventory"
                    />

                    <flux:radio
                        value="purchase_orders"
                        label="Purchase Orders"
                    />

                </flux:radio.group>

            </div>

            <div class="flex flex-col gap-3">

                <flux:select
                    wire:model.live="exportRange"
                    label="Timeframe"
                    placeholder="Select timeframe"
                >

                    <flux:select.option value="today">
                        Today
                    </flux:select.option>

                    <flux:select.option value="yesterday">
                        Yesterday
                    </flux:select.option>

                    <flux:select.option value="past_7_days">
                        Past 7 Days
                    </flux:select.option>

                    <flux:select.option value="past_14_days">
                        Past 14 Days
                    </flux:select.option>

                    <flux:select.option value="past_30_days">
                        Past 30 Days
                    </flux:select.option>

                    <flux:select.option value="past_quarter">
                        Past Quarter
                    </flux:select.option>

                    <flux:select.option value="past_6_months">
                        Past 6 Months
                    </flux:select.option>

                    <flux:select.option value="past_year">
                        Past Year
                    </flux:select.option>

                    <flux:select.option value="overall">
                        Overall
                    </flux:select.option>

                    <flux:select.option value="custom">
                        Custom Date
                    </flux:select.option>

                </flux:select>

            </div>

            @if ($exportRange === 'custom')

                <div class="grid grid-cols-2 gap-4">

                    <flux:input
                        type="date"
                        wire:model.live="exportFrom"
                        label="Start Date"
                    />

                    <flux:input
                        type="date"
                        wire:model.live="exportTo"
                        label="End Date"
                    />

                </div>

                @error('exportFrom')

                    <flux:text
                        size="sm"
                        class="text-red-600"
                    >
                        {{ $message }}
                    </flux:text>

                @enderror

                @error('exportTo')

                    <flux:text
                        size="sm"
                        class="text-red-600"
                    >
                        {{ $message }}
                    </flux:text>

                @enderror

            @endif

            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">

                <p class="text-sm font-medium">
                    Export Summary
                </p>

                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Review your export configuration before downloading.
                </p>

                <div class="mt-4 grid grid-cols-3 gap-4">

                    <div>

                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Format
                        </p>

                        <p class="mt-1 text-sm font-medium">
                            {{ strtoupper($exportFormat) }}
                        </p>

                    </div>

                    <div>

                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Data
                        </p>

                        <p class="mt-1 text-sm font-medium">

                            {{ match ($exportData) {

                                'sales' => 'Sales',

                                'inventory' => 'Inventory',

                                'purchase_orders' => 'Purchase Orders',

                                default => 'All',

                            } }}

                        </p>

                    </div>

                    <div>

                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Timeframe
                        </p>

                        <p class="mt-1 text-sm font-medium">
                            {{ $this->exportRangeLabel() }}
                        </p>

                    </div>

                </div>

            </div>

            <div class="flex items-center justify-end gap-2">

                <flux:modal.close>

                    <flux:button variant="ghost">
                        Cancel
                    </flux:button>

                </flux:modal.close>

                <flux:button
                    wire:click="exportReport"
                    wire:loading.attr="disabled"
                    wire:target="exportReport"
                    variant="primary"
                    icon="arrow-down-tray"
                    class="bg-primary hover:bg-primary"
                >

                    <span
                        wire:loading.remove
                        wire:target="exportReport"
                    >
                        Download Report
                    </span>

                    <span
                        wire:loading
                        wire:target="exportReport"
                    >
                        Preparing...
                    </span>

                </flux:button>

            </div>

        </div>

    </flux:modal>

</div>