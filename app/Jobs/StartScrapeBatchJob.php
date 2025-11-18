<?php
namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\ImportItem;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Throwable;

class StartScrapeBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        $batch->update(['status' => 'process']);

        $jobs = ImportItem::where('import_batch_id', $batch->id)
            ->get()
            ->map(fn ($i) => new ScrapeKeywordJob($i->id))
            ->toArray();

        Bus::batch($jobs)
            ->name("ScrapeBatch #{$this->batchId}")
            ->onQueue('scraping')
            ->allowFailures()
            ->dispatch();
    }

    private function refreshProgress(ImportBatch $batch): void
    {
        $finishedCount = ImportItem::where('import_batch_id', $batch->id)
            ->whereIn('status', ['finished', 'failed'])
            ->count();

        $batch->update([
            'finished_rows' => $finishedCount,
            'status' =>
                $finishedCount >= $batch->total_rows
                    ? 'finished'
                    : 'process'
        ]);
    }

   public function failed(Throwable $e): void
    {
        if ($batch = ImportBatch::find($this->batchId)) {
            $batch->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }
}
