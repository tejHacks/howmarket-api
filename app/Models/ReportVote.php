<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** A person confirming (1) or flagging (-1) someone else's price report. */
#[Table('report_votes')]
#[Fillable(['report_id', 'user_id', 'vote'])]
class ReportVote extends Model
{
    protected function casts(): array
    {
        return ['vote' => 'integer'];
    }
}
