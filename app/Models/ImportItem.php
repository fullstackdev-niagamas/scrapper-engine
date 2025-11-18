<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportItem extends Model
{
    protected $fillable = ['import_batch_id', 'sku', 'nama_barang', 'brand', 'keyword', 'status', 'error_message'];


    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
    public function results(): HasMany
    {
        return $this->hasMany(ScrapeResult::class);
    }
}