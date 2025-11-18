<?php
namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\ScrapeResult;
use App\Services\Scrapers\MarketplaceScraper;
use App\Services\Scrapers\TokopediaScraper;
use App\Services\Scrapers\ShopeeScraper;
use App\Services\Scrapers\LazadaScraper;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScrapeKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $timeout = 600;
    public $tries   = 3;
    public $backoff = [10, 30];

    public function __construct(public int $itemId) {}

    public function handle(): void
    {
        $item = ImportItem::findOrFail($this->itemId);
        $batch = $item->batch;

        if (in_array($item->status, ['finished', 'failed'])) {
            $this->refreshBatchProgress($batch->id);
            return;
        }

        $item->update(['status' => 'process']);

        $sources = collect(explode(',', $batch->source_tabs))->filter()->values();
        $scrapers = [
            'tokopedia' => app(TokopediaScraper::class),
            'lazada'    => app(LazadaScraper::class),
            'shopee'    => app(ShopeeScraper::class)
        ];

        try {

            foreach ($sources as $src) {
                /** @var MarketplaceScraper $scraper */
                $scraper = $scrapers[$src] ?? null;
                if (!$scraper) continue;

                $results = $scraper->searchTop($item->keyword, 3);

                ScrapeResult::where('import_item_id', $item->id)
                    ->where('marketplace', $src)
                    ->delete();

                foreach ($results as $rank => $raw) {
                    $r = $this->normalizeResult($raw, $src);

                    ScrapeResult::create([
                        'import_item_id' => $item->id,
                        'marketplace' => $src,
                        'rank' => $rank + 1,
                        'url' => $r['url'],
                        'store_name' => $r['store_name'],
                        'price_discount' => $r['price_discount'],
                        'price_regular' => $r['price_regular'],
                        'sold' => $r['sold'],
                        'kota' => $r['kota'],
                        'provinsi' => $r['provinsi'],
                        'image' => $r['image'],
                    ]);
                }
            }

        } catch (Throwable $e) {

            if ($this->isChromeCrash($e)) {
                // biar retry
                throw $e;
            }

            // error lain → job gagal
            throw $e;
        }

        $item->update(['status' => 'finished']);
        $this->refreshBatchProgress($batch->id);
    }


    private function isChromeCrash(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        $patterns = [
            'chrome not reachable',
            'chrome failed to start',
            'session not created',
            'timed out receiving message from renderer',
            'cannot connect to chrome',
            'disconnected',
            'devtoolsactiveport',
            'chrome driver only supports',
            'unknown error: chrome'
        ];

        foreach ($patterns as $p) {
            if (str_contains($msg, strtolower($p))) {
                return true;
            }
        }

        return false;
    }


    private function refreshBatchProgress(int $batchId): void
    {
        $batch = ImportBatch::find($batchId);
        if (!$batch) return;

        $finishedCount = ImportItem::where('import_batch_id', $batchId)
            ->whereIn('status', ['finished', 'failed'])
            ->count();

        $batch->update([
            'finished_rows' => $finishedCount,
            'status' => $finishedCount >= $batch->total_rows ? 'finished' : 'process'
        ]);
    }


    private function normalizeResult(array $raw, string $market): array
    {
        if (array_is_list($raw)) {
            $raw = [
                'url'           => $raw[0] ?? '',
                'store_name'    => $raw[1] ?? '',
                'price_discount'=> $raw[2] ?? '',
                'price_regular' => $raw[3] ?? '',
                'sold'          => $raw[4] ?? '',
                'kota'          => $raw[5] ?? '',
                'provinsi'      => $raw[6] ?? '',
                'image'         => $raw[7] ?? '',
            ];
        }

        return [
            'url'           => (string)($raw['url'] ?? ''),
            'store_name'    => trim((string)($raw['store_name'] ?? '')),
            'price_discount'=> trim((string)($raw['price_discount'] ?? '')),
            'price_regular' => trim((string)($raw['price_regular'] ?? '')),
            'sold'          => trim((string)($raw['sold'] ?? '')),
            'kota'          => trim((string)($raw['kota'] ?? '')),
            'provinsi'      => trim((string)($raw['provinsi'] ?? '')),
            'image'         => null,
        ];
    }


    public function failed(Throwable $e): void
    {
        if ($item = ImportItem::find($this->itemId)) {
            $item->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->refreshBatchProgress($item->import_batch_id);
        }
    }
}
