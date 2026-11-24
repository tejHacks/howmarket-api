<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One person's report of what an item costs in a market. */
#[Table('price_reports')]
#[Fillable(['item_id', 'market_id', 'price'])]
class PriceReport extends Model
{
    protected function casts(): array
    {
        return ['price' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ReportVote::class, 'report_id');
    }
}
