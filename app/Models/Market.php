<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('markets', keyType: 'string', incrementing: false)]
#[Fillable(['id', 'name', 'area'])]
class Market extends Model {}
