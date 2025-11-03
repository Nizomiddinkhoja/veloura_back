<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sell_price',
        'is_deleted',
    ];

    protected $casts = [
        'buy_price' => 'float',
        'sell_price' => 'float',
        'is_deleted' => 'boolean',
    ];

    protected $hidden = ['is_deleted'];

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
