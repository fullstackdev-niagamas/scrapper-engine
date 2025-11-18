<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeResult extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price_updated_at' => 'datetime'
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ImportItem::class, 'import_item_id');
    }
}