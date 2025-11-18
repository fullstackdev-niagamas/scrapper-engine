<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = ['filename', 'total_rows', 'finished_rows', 'status', 'source_tabs', 'error_message'];


    public function items(): HasMany
    {
        return $this->hasMany(ImportItem::class);
    }


    public function progressPercent(): float
    {
        return $this->total_rows > 0 ? round($this->finished_rows * 100 / $this->total_rows, 2) : 0.0;
    }
}