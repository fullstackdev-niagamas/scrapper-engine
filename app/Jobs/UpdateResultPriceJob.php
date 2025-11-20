<?php

namespace App\Jobs;

use App\Models\ScrapeResult;
use App\Services\Scrapers\LazadaUpdatePriceScraper;
use App\Services\Scrapers\TokopediaUpdatePriceScraper;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UpdateResultPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries   = 3;
    public $backoff = [10, 30];

    public function __construct(public int $scrapeResultId) {}

    public function handle(): void
    {
        $res = ScrapeResult::find($this->scrapeResultId);
        if (!$res) {
            return;
        }

        if (!$res->url) {
            $res->status = 'finish';
            $res->save();
            return;
        }

        /** @var RemoteWebDriver $driver */
        $driver = app(RemoteWebDriver::class);

        // pakai 1 driver yang sama untuk semua scraper di job ini
        $tokopedia = new TokopediaUpdatePriceScraper($driver);
        $lazada    = new LazadaUpdatePriceScraper($driver);

        try {
            $marketplace = strtolower($res->marketplace);
            $oldRegular  = $res->price_regular;
            $oldDiscount = $res->price_discount;

            $prices = [];
            if ($marketplace === 'tokopedia') {
                $prices = $tokopedia->scrape($res->url);
            } elseif ($marketplace === 'lazada') {
                $prices = $lazada->scrape($res->url);
            } else {
                $res->status = 'finish';
                $res->save();
                return;
            }

            $updates = [];
            if (!empty($prices['price_regular'])) {
                $updates['price_regular'] = $prices['price_regular'];
            }
            if (!empty($prices['price_discount'])) {
                $updates['price_discount'] = $prices['price_discount'];
            }

            $changed =
                (isset($updates['price_regular']) && $updates['price_regular'] !== $oldRegular) ||
                (isset($updates['price_discount']) && $updates['price_discount'] !== $oldDiscount);

            if (!empty($updates) && $changed) {
                $updates['price_updated_at'] = now();
            }

            if (!empty($updates)) {
                $res->fill($updates);
            }

            $res->status = 'finish';
            $res->save();

        } catch (Throwable $e) {
            if ($this->isChromeCrash($e)) {
                throw $e;
            }

            $res->status = 'error';
            $res->save();
            report($e);

        } finally {
            try {
                $driver->quit();
            } catch (Throwable $e2) {
                // Safe exit
            }
        }
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
            'unknown error: chrome',
        ];

        foreach ($patterns as $p) {
            if (str_contains($msg, strtolower($p))) {
                return true;
            }
        }

        return false;
    }
}