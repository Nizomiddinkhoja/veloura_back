<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fund extends Model
{
    const TYPE_INVESTMENT = 'investment';
    const TYPE_PROFIT = 'profit';
    const TYPE_COGS = 'cogs';
}
