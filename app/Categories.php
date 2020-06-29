<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Categories extends Model
{
    protected $fillable = [
        'id',
        'name',
        'url',
        'parent_id',
    ];

}
