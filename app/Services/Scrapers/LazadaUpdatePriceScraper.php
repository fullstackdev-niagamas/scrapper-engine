<?php

namespace App\Services\Scrapers;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy as By;
use Illuminate\Support\Facades\Log;

class LazadaUpdatePriceScraper
{
    public function __construct(private RemoteWebDriver $driver)
    {
    }

    /**
     * Ambil harga dari halaman PDP Lazada
     * @return array{price_regular:string|null, price_discount:string|null}
     */
    public function scrape(string $url): array
    {
        $d = $this->driver;

        try {
            $d->get($url);

            $els = $d->findElements(By::xpath('//*[contains(text(),"Rp")]'));
            if (!$els) {
                Log::warning('[Lazada PDP] Price not found (no Rp text)', ['url' => $url]);
                return ['price_regular' => null, 'price_discount' => null];
            }

            $price_regular = null;
            $price_discount = null;

            foreach ($els as $el) {
                $txt = trim($el->getText());
                if ($txt === '') {
                    $txt = trim((string) $el->getAttribute('innerText') ?: $el->getAttribute('textContent'));
                }
                if (!preg_match('/Rp\s?[0-9\.\,]+/u', $txt, $m)) {
                    continue;
                }

                $val = $m[0];
                $class = strtolower((string) $el->getAttribute('class'));
                $style = strtolower((string) $el->getAttribute('style'));

                if (str_contains($class, 'deleted') || str_contains($class, 'line') || str_contains($class, 'strike') || str_contains($style, 'line-through')) {
                    $price_regular = $val;
                } else {
                    if (!$price_discount) {
                        $price_discount = $val;
                    }
                }
            }

            if (!$price_regular && $price_discount) {
                $price_regular = $price_discount;
                $price_discount = null;
            }
            return compact('price_regular', 'price_discount');
        } catch (\Throwable $e) {
            Log::error('[Lazada PDP] Exception', ['url' => $url, 'err' => $e->getMessage()]);
            return ['price_regular' => null, 'price_discount' => null];
        }
    }
}
