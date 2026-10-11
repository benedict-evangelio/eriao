<?php

use App\Models\College;
use App\Models\CollegeRequest;
use App\Models\CollegeRequestItem;
use App\Models\ProductItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function invokeProtected(object $object, string $method, array $arguments = []): mixed
{
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
}

function createCollegeRequestProduct(array $attributes = []): ProductItem
{
    return ProductItem::create(array_merge([
        'name' => 'Request item',
        'category_id' => 1,
        'quantity' => 4,
        'max' => 10,
        'reorder_level' => 1,
        'front_quantity' => 0,
        'warehouse_quantity' => 4,
        'defective_quantity' => 0,
        'price' => 10,
        'status' => 'available',
    ], $attributes));
}

function createSignedCollegeRequest(User $user, ProductItem $product, int $quantity): CollegeRequest
{
    $request = CollegeRequest::create([
        'college_name' => 'Test College',
        'subject' => 'Inventory request',
        'recipient_name' => 'Recipient',
        'letter_received_at' => now()->toDateString(),
        'status' => 'signed',
        'recorded_by' => $user->id,
        'signed_by' => $user->id,
        'signed_at' => now(),
    ]);

    $request->items()->create([
        'product_id' => $product->id,
        'quantity' => $quantity,
    ]);

    return $request;
}

function createTestUser(): User
{
    return User::create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => uniqid('test-', true).'@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);
}

it('releases college requests from warehouse stock when front inventory is empty', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct();
    $request = createSignedCollegeRequest($user, $product, 3);

    Livewire::actingAs($user)
        ->test('pages::user.college-requests')
        ->call('releaseToCollege', $request->id);

    $product->refresh();
    $request->refresh();

    expect($product->front_quantity)->toBe(0)
        ->and($product->warehouse_quantity)->toBe(1)
        ->and($product->quantity)->toBe(1)
        ->and($request->status->value)->toBe('released')
        ->and($request->activities()->latest()->first()->details)
        ->toContain('warehouse inventory was deducted');
});

it('does not release a college request when warehouse stock is insufficient despite front stock', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct([
        'quantity' => 11,
        'front_quantity' => 10,
        'warehouse_quantity' => 1,
    ]);
    $request = createSignedCollegeRequest($user, $product, 2);

    Livewire::actingAs($user)
        ->test('pages::user.college-requests')
        ->call('releaseToCollege', $request->id)
        ->assertHasErrors('release');

    $product->refresh();
    $request->refresh();

    expect($product->front_quantity)->toBe(10)
        ->and($product->warehouse_quantity)->toBe(1)
        ->and($request->status->value)->toBe('signed');
});

it('deducts newly defective stock from front inventory when warehouse stock is empty', function () {
    $user = createTestUser();
    $categoryId = DB::table('product_categories')->insertGetId([
        'name' => 'Test category',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $product = createCollegeRequestProduct([
        'category_id' => $categoryId,
        'quantity' => 5,
        'front_quantity' => 5,
        'warehouse_quantity' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('pages::user.inventory')
        ->call('onUpdateProduct', $product->id)
        ->set('productCategory', (string) $categoryId)
        ->set('productDefectiveQuantity', 2)
        ->call('updateProduct')
        ->assertHasNoErrors();

    $product->refresh();

    expect($product->front_quantity)->toBe(3)
        ->and($product->warehouse_quantity)->toBe(0)
        ->and($product->quantity)->toBe(3)
        ->and($product->defective_quantity)->toBe(2);
});

it('reserves warehouse stock when signing and rejects an over-reserved request', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct();
    $requests = collect([3, 2])->map(function (int $quantity) use ($user, $product) {
        $request = CollegeRequest::create([
            'college_name' => 'Test College',
            'subject' => 'Inventory request',
            'recipient_name' => 'Recipient',
            'letter_received_at' => now()->toDateString(),
            'status' => 'pending',
            'recorded_by' => $user->id,
        ]);
        $request->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $request;
    });

    Livewire::actingAs($user)
        ->test('pages::user.college-requests')
        ->call('markSigned', $requests[0]->id)
        ->assertHasNoErrors()
        ->call('markSigned', $requests[1]->id)
        ->assertHasErrors('sign');

    expect(CollegeRequestItem::totalReservedWarehouseQuantity())->toBe(3)
        ->and($requests[0]->refresh()->status->value)->toBe('signed')
        ->and($requests[1]->refresh()->status->value)->toBe('pending');
});

it('combines duplicate product lines when recording a college request', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct();
    $college = College::create(['name' => 'Test College']);

    Livewire::actingAs($user)
        ->test('pages::user.college-requests')
        ->set('collegeId', (string) $college->id)
        ->set('subject', 'Inventory request')
        ->set('recipientName', 'Recipient')
        ->set('letterReceivedAt', now()->toDateString())
        ->set('requestItems', [
            ['product_id' => (string) $product->id, 'quantity' => 4],
            ['product_id' => (string) $product->id, 'quantity' => 6],
        ])
        ->call('recordRequest')
        ->assertHasNoErrors();

    $request = CollegeRequest::query()->firstOrFail();

    expect($request->items)->toHaveCount(1)
        ->and($request->items->first()->quantity)->toBe(10);
});

it('updates items on a signed request and moves its warehouse reservation', function () {
    $user = createTestUser();
    $originalProduct = createCollegeRequestProduct();
    $replacementProduct = createCollegeRequestProduct([
        'name' => 'Replacement request item',
        'quantity' => 3,
        'warehouse_quantity' => 3,
    ]);
    $request = createSignedCollegeRequest($user, $originalProduct, 2);

    Livewire::actingAs($user)
        ->test('pages::user.college-requests')
        ->call('beginEditSignedItems', $request->id)
        ->set('editRequestItems', [
            ['product_id' => (string) $replacementProduct->id, 'quantity' => 3],
        ])
        ->call('saveSignedRequestItems')
        ->assertHasNoErrors();

    expect(CollegeRequestItem::reservedWarehouseQuantity($originalProduct->id))->toBe(0)
        ->and(CollegeRequestItem::reservedWarehouseQuantity($replacementProduct->id))->toBe(3)
        ->and($request->fresh()->items->first()->product_id)->toBe($replacementProduct->id);
});

it('shows requested products in the college request list and signing action in the details panel', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct(['name' => 'Requested polo shirt']);
    $request = CollegeRequest::create([
        'college_name' => 'Test College',
        'subject' => 'Uniform request',
        'recipient_name' => 'Recipient',
        'letter_received_at' => now()->toDateString(),
        'status' => 'pending',
        'recorded_by' => $user->id,
    ]);
    $request->items()->create([
        'product_id' => $product->id,
        'quantity' => 3,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::user.college-requests')
        ->assertSee('Requested polo shirt')
        ->assertSee('Qty 3')
        ->assertDontSee('Mark signed')
        ->call('viewRequest', $request->id)
        ->assertSee('Mark signed');

    expect($component->instance()->selectedRequestId)->toBe($request->id);
});

it('shows purchase order actions in the details panel instead of the row menu', function () {
    $user = createTestUser();
    $order = PurchaseOrder::create([
        'requested_by' => $user->id,
        'quantity' => 1,
        'total_amount' => 10,
        'status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('pages::user.purchase-order')
        ->assertDontSee('confirmApprove('.$order->id.')', false)
        ->call('viewOrder', $order->id)
        ->assertSee('confirmApprove('.$order->id.')', false)
        ->assertSee('confirmReject('.$order->id.')', false);
});

it('recommends restocking at the reorder level even when no maximum is configured', function () {
    $product = createCollegeRequestProduct([
        'max' => 0,
        'quantity' => 2,
        'front_quantity' => 2,
        'warehouse_quantity' => 0,
        'reorder_level' => 4,
    ]);

    Livewire::actingAs(createTestUser())
        ->test('pages::user.home')
        ->assertSet('restockNeeded', 1)
        ->assertSee('Recommended Restock')
        ->assertSee($product->name);
});

it('excludes warehouse stock reserved for signed college requests from dashboard restock availability', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct([
        'max' => 10,
        'quantity' => 10,
        'front_quantity' => 0,
        'warehouse_quantity' => 10,
        'reorder_level' => 0,
    ]);
    createSignedCollegeRequest($user, $product, 7);

    Livewire::actingAs(createTestUser())
        ->test('pages::user.home')
        ->assertSet('restockRecommendations.0.current_stock', 3)
        ->assertSee($product->name);
});

it('keeps dashboard statistic widgets rendered after refreshing dashboard data', function () {
    Livewire::actingAs(createTestUser())
        ->test('pages::user.home')
        ->call('loadData')
        ->assertSee('Total Products')
        ->assertSee('Current Stock')
        ->assertSee('Inventory Value')
        ->assertSee('Restock Needed');
});

it('links dashboard statistic widgets to inventory', function () {
    $user = createTestUser();

    Livewire::actingAs($user)
        ->test('pages::user.home')
        ->assertSee('href="'.route('admin.inventory').'"', false)
        ->assertSee('aria-label="Total Products, open inventory"', false)
        ->assertSee('aria-label="Current Stock, open inventory"', false)
        ->assertSee('aria-label="Inventory Value, open inventory"', false)
        ->assertSee('aria-label="Restock Needed, open inventory"', false);
});

it('keeps the original transaction total and logs a replacement refund adjustment', function () {
    $user = createTestUser();
    $originalProduct = createCollegeRequestProduct([
        'name' => 'Original product',
        'price' => 10,
    ]);
    $replacementProduct = createCollegeRequestProduct([
        'name' => 'Replacement product',
        'price' => 5,
    ]);
    $transactionId = DB::table('transactions')->insertGetId([
        'employee_id' => $user->id,
        'id_number' => 'STUDENT-1',
        'first_name' => '',
        'last_name' => '',
        'payment_method' => 'Pending',
        'reference_num' => null,
        'discount_category' => null,
        'discount_price' => 0,
        'tax' => 0,
        'total_amount' => 10,
        'cash' => 0,
        'change' => 0,
        'notes' => null,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('orders')->insert([
        'transaction_id' => $transactionId,
        'product_id' => $originalProduct->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::user.transaction-history')
        ->call('editPendingTransaction', $transactionId)
        ->set('editItems.0.product_id', $replacementProduct->id)
        ->call('savePendingTransaction')
        ->assertHasNoErrors();

    $transaction = DB::table('transactions')->where('id', $transactionId)->first();
    $adjustment = DB::table('transaction_adjustments')
        ->where('transaction_id', $transactionId)
        ->first();

    expect((float) $transaction->total_amount)->toBe(10.0)
        ->and((float) $transaction->adjusted_total_amount)->toBe(5.0)
        ->and((float) $adjustment->previous_amount)->toBe(10.0)
        ->and((float) $adjustment->adjusted_amount)->toBe(5.0)
        ->and((float) $adjustment->refund_amount)->toBe(5.0);
});

it('reports the adjusted amount actually collected without changing the original total', function () {
    $user = createTestUser();
    DB::table('transactions')->insert([
        'employee_id' => $user->id,
        'id_number' => 'STUDENT-2',
        'first_name' => '',
        'last_name' => '',
        'payment_method' => 'Cash',
        'reference_num' => null,
        'discount_category' => null,
        'discount_price' => 0,
        'tax' => 0,
        'total_amount' => 100,
        'adjusted_total_amount' => 80,
        'cash' => 80,
        'change' => 0,
        'notes' => null,
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::user.reports')
        ->assertSet('totalSales', 80.0);

    expect((float) DB::table('transactions')->value('total_amount'))->toBe(100.0);
});

it('ranks inventory categories by stock for the category bar chart', function () {
    $highStockCategoryId = DB::table('product_categories')->insertGetId([
        'name' => 'High stock category',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $lowStockCategoryId = DB::table('product_categories')->insertGetId([
        'name' => 'Low stock category',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    createCollegeRequestProduct([
        'name' => 'High stock item',
        'category_id' => $highStockCategoryId,
        'quantity' => 12,
        'front_quantity' => 4,
        'warehouse_quantity' => 8,
    ]);
    createCollegeRequestProduct([
        'name' => 'Low stock item',
        'category_id' => $lowStockCategoryId,
        'quantity' => 3,
        'front_quantity' => 1,
        'warehouse_quantity' => 2,
    ]);

    Livewire::actingAs(createTestUser())
        ->test('pages::user.reports')
        ->assertSet('categoryLabels', ['High stock category', 'Low stock category'])
        ->assertSet('categoryData', [12, 3])
        ->assertSee('Inventory stock by category')
        ->assertSee('Horizontal bar chart showing inventory stock by category');
});

it('allows creating and selecting the first inventory category', function () {
    expect(DB::table('product_categories')->count())->toBe(0);

    Livewire::test('modals.inventory.new-item')
        ->call('openNewCategoryModal')
        ->set('newCategoryName', 'Office supplies')
        ->call('onCreateCategory')
        ->assertSet('category', 1);

    expect(DB::table('product_categories')->where('name', 'Office supplies')->exists())->toBeTrue();
});

it('applies inclusive custom dates and exports uniform sales details in CSV and PDF', function () {
    $user = createTestUser();
    $product = createCollegeRequestProduct(['name' => 'Exported notebook']);
    $includedTransactionIds = [];

    foreach ([
        ['OUT-BEFORE', '2026-10-04 23:59:59'],
        ['CUSTOM-START', '2026-10-05 00:00:00'],
        ['CUSTOM-END', '2026-10-06 23:59:59'],
        ['OUT-AFTER', '2026-10-07 00:00:00'],
    ] as [$idNumber, $createdAt]) {
        $transactionId = DB::table('transactions')->insertGetId([
            'employee_id' => $user->id,
            'id_number' => $idNumber,
            'first_name' => 'Sample',
            'last_name' => 'Customer',
            'payment_method' => 'Cash',
            'reference_num' => null,
            'discount_category' => null,
            'discount_price' => 0,
            'tax' => 0,
            'total_amount' => 10,
            'cash' => 10,
            'change' => 0,
            'notes' => null,
            'status' => 'completed',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if (in_array($idNumber, ['CUSTOM-START', 'CUSTOM-END'], true)) {
            $includedTransactionIds[] = $transactionId;
        }

        if ($idNumber === 'CUSTOM-END') {
            DB::table('orders')->insert([
                'transaction_id' => $transactionId,
                'product_id' => $product->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    $component = Livewire::actingAs($user)
        ->test('pages::user.reports')
        ->set('exportRange', 'custom')
        ->set('exportFrom', '2026-10-05')
        ->set('exportTo', '2026-10-06');

    $instance = $component->instance();
    [$from, $to] = invokeProtected($instance, 'getExportDates');
    $transactions = invokeProtected($instance, 'getSalesExportData', [$from, $to]);

    expect($from->toDateTimeString())->toBe('2026-10-05 00:00:00')
        ->and($to->toDateTimeString())->toBe('2026-10-06 23:59:59')
        ->and($transactions->pluck('id')->all())->toBe(array_reverse($includedTransactionIds))
        ->and($transactions->first()->purchased_items)->toBe('Exported notebook × 1');

    $csv = fopen('php://temp', 'w+');
    invokeProtected($instance, 'writeSalesCsv', [$csv, $from, $to]);
    rewind($csv);
    $csvContents = stream_get_contents($csv);
    fclose($csv);

    expect($csvContents)->toContain('Purchased Items')
        ->toContain('Exported notebook × 1')
        ->not->toContain('Customer ID')
        ->not->toContain('Customer')
        ->not->toContain('Discount')
        ->not->toContain('Tax')
        ->not->toContain('OUT-BEFORE')
        ->not->toContain('OUT-AFTER');

    $pdfHtml = invokeProtected($instance, 'buildPdfHtml', [$transactions, collect(), collect()]);

    expect($pdfHtml)->toContain('<th>Purchased Items</th>')
        ->toContain('Exported notebook × 1')
        ->toContain('<th>Transaction ID</th>')
        ->toContain('<th>Payment Method</th>')
        ->toContain('Generated At:')
        ->toContain('Generated By: Test User')
        ->not->toContain('Customer ID')
        ->not->toContain('<th>Customer</th>')
        ->not->toContain('<th>Discount</th>')
        ->not->toContain('<th>Tax</th>');

    $component->set('exportData', 'sales');
    $streamedCsv = invokeProtected($component->instance(), 'exportCsv', [$from, $to]);
    ob_start();
    $streamedCsv->sendContent();
    $downloadContents = ob_get_clean();

    expect($downloadContents)->toContain('"Generated By","Test User"')
        ->toContain('"Generated At",')
        ->toContain('Timeframe,')
        ->toContain('Exported notebook × 1')
        ->not->toContain('Customer ID')
        ->not->toContain('Discount')
        ->not->toContain('Tax');
});

it('rejects malformed custom export date ranges', function () {
    Livewire::test('pages::user.reports')
        ->set('exportRange', 'custom')
        ->set('exportFrom', 'not-a-date')
        ->set('exportTo', '2026-10-06')
        ->call('exportReport')
        ->assertHasErrors('exportFrom');
});

it('rejects reversed custom export date ranges', function () {
    Livewire::test('pages::user.reports')
        ->set('exportRange', 'custom')
        ->set('exportFrom', '2026-10-06')
        ->set('exportTo', '2026-10-05')
        ->call('exportReport')
        ->assertHasErrors('exportTo');
});
