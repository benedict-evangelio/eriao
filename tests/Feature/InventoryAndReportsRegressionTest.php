<?php

use App\Models\CollegeRequest;
use App\Models\ProductItem;
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
        ->and($request->status)->toBe('released')
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
        ->and($request->status)->toBe('signed');
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

it('applies inclusive custom dates and exports customer IDs in CSV and PDF sales rows', function () {
    $user = createTestUser();

    foreach ([
        ['OUT-BEFORE', '2026-10-04 23:59:59'],
        ['CUSTOM-START', '2026-10-05 00:00:00'],
        ['CUSTOM-END', '2026-10-06 23:59:59'],
        ['OUT-AFTER', '2026-10-07 00:00:00'],
    ] as [$idNumber, $createdAt]) {
        DB::table('transactions')->insert([
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
        ->and($transactions->pluck('id_number')->all())->toBe(['CUSTOM-END', 'CUSTOM-START']);

    $csv = fopen('php://temp', 'w+');
    invokeProtected($instance, 'writeSalesCsv', [$csv, $from, $to]);
    rewind($csv);
    $csvContents = stream_get_contents($csv);
    fclose($csv);

    expect($csvContents)->toContain('Customer ID')
        ->toContain('CUSTOM-START')
        ->toContain('CUSTOM-END')
        ->not->toContain('OUT-BEFORE')
        ->not->toContain('OUT-AFTER');

    $pdfHtml = invokeProtected($instance, 'buildPdfHtml', [$transactions, collect(), collect()]);

    expect($pdfHtml)->toContain('<th>Customer ID</th>')
        ->toContain('<td>CUSTOM-START</td>')
        ->toContain('<td>CUSTOM-END</td>');
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
