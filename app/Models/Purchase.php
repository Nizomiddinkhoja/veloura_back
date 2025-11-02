<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'dealer_id',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'float',
    ];

    public function dealer()
    {
        return $this->belongsTo(Dealer::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
