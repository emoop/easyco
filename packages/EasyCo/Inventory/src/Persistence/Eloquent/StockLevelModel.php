<?php

namespace EasyCo\Inventory\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class StockLevelModel extends Model
{
    protected $table = 'stock_levels';

    protected $fillable = ['variation_id', 'quantity'];
}
