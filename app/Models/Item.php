<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('items', keyType: 'string', incrementing: false)]
#[Fillable(['id', 'name', 'unit', 'emoji'])]
class Item extends Model {}
