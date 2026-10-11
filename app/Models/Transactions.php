<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{
    protected $table = 'transactions';

    protected $fillable = [
        'employee_id',
        'id_number',
        'cashier_id',
        'first_name',
        'last_name',
        'payment_method',
        'reference_num',
        'discount_category',
        'discount_price',
        'tax',
        'total_amount',
        'adjusted_total_amount',
        'cash',
        'change',
        'notes',
        'status',
        'paid_at',
    ];
}
