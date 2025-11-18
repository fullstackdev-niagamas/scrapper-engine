<?php

namespace App\Jobs;

use App\Models\ScrapeResult;
use App\Services\Scrapers\LazadaUpdatePriceScraper;
use App\Services\Scrapers\TokopediaUpdatePriceScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UpdateResultPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $scrapeResultId) {}

    public function handle(
        TokopediaUpdatePriceScraper $tokopedia,
        LazadaUpdatePriceScraper $lazada
    ): void {
        $res = ScrapeResult::find($this->scrapeResultId);
        if (!$res) return;

        try {
            if (!$res->url) {
                $res->status = 'finish';
                $res->save();
                return;
            }

            $marketplace = strtolower($res->marketplace);
            $oldRegular  = $res->price_regular;
            $oldDiscount = $res->price_discount;

            $prices = [];
            if ($marketplace === 'tokopedia') {
                $prices = $tokopedia->scrape($res->url);
            } elseif ($marketplace === 'lazada') {
                $prices = $lazada->scrape($res->url);
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
            $res->status = 'error';
            $res->save();
            report($e);
        }
    }
}