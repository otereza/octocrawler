<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Prods extends Model
{
    protected $fillable = [
        'id',
        'url',
        'title',
        'images',
        'category_id',
        'category_name',
        'vendor',
        'packing',
        'currency',
        'regular_price',
        'special_price',
        'special_price_end_date',
        'description',
        'params'
    ];
    //
}
