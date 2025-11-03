<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    const REFERENCE_TYPE_SALE = 'sale';
    const REFERENCE_TYPE_PURCHASE = 'purchase';
    const REFERENCE_TYPE_SALE_RETURNS = 'sale_returns';
    const TYPE_IN = 'in';
    const TYPE_OUT = 'out';

    protected $fillable = [
        'product_id',
        'purchase_item_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
    ];

    public function purchaseItem()
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
