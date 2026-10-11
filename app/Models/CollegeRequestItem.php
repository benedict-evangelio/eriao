<?php

namespace App\Models;

use App\Enums\CollegeRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollegeRequestItem extends Model
{
    protected $fillable = [
        'college_request_id',
        'product_id',
        'quantity',
    ];

    public static function reservedWarehouseQuantity(int $productId, ?int $exceptRequestId = null): int
    {
        return (int) static::query()
            ->join('college_requests', 'college_requests.id', '=', 'college_request_items.college_request_id')
            ->where('college_requests.status', CollegeRequestStatus::Signed->value)
            ->where('college_request_items.product_id', $productId)
            ->when($exceptRequestId, fn ($query) => $query->where('college_requests.id', '!=', $exceptRequestId))
            ->sum('college_request_items.quantity');
    }

    public static function totalReservedWarehouseQuantity(): int
    {
        return (int) static::query()
            ->join('college_requests', 'college_requests.id', '=', 'college_request_items.college_request_id')
            ->where('college_requests.status', CollegeRequestStatus::Signed->value)
            ->sum('college_request_items.quantity');
    }

    /** @return BelongsTo<CollegeRequest, $this> */
    public function collegeRequest(): BelongsTo
    {
        return $this->belongsTo(CollegeRequest::class);
    }

    /** @return BelongsTo<ProductItem, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductItem::class, 'product_id');
    }
}
