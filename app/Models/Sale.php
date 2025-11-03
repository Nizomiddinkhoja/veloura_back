<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_name',
        'comment',
        'total',
        'paid',
        'is_deleted'
    ];

    protected $casts = [
        'total' => 'float',
        'paid' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    protected $hidden = ['is_deleted'];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }
}
