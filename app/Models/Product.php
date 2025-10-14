<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'buy_price',
        'sell_price',
        'quantity',
        'is_deleted',
    ];

    protected $casts = [
        'buy_price' => 'float',
        'sell_price' => 'float',
        'is_deleted' => 'boolean',
    ];

    protected $hidden = ['is_deleted'];

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }
}
