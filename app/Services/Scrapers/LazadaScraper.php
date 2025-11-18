<?php

namespace App\Services\Scrapers;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy as By;

class LazadaScraper implements MarketplaceScraper
{
    public function __construct(
        private RemoteWebDriver $driver
    ) {
    }

    public function searchTop(string $keyword, int $limit = 3): array
    {
        $out = [];
        $d = $this->driver;

        // Lazada: sort harga termurah
        $q = rawurlencode($keyword);
        $url = "https://www.lazada.co.id/catalog/?q={$q}&_keyori=ss&sort=priceasc";

        $this->navWithRetry($d, $url, 3);

        $this->tryCloseModals();

        $container = $this->waitAny($d, [
            'ul[data-qa-locator="general_product_list"]',
            'div[data-qa-locator="general-products"]',
            '#root', // fallback
        ], 15000);

        if (!$container) {
            $this->autoScroll($d, 3);
            $container = $this->waitAny($d, [
                'ul[data-qa-locator="general_product_list"]',
            ], 8000);

            if (!$container) {
                if ($this->isCaptchaOrDenied()) {
                    $this->debugSnapshot($d, 'lazada_captcha');
                    return [];
                }
                $this->debugSnapshot($d, 'lazada_no_container');
                return [];
            }
        }

        $this->waitAny($d, [
            'ul[data-qa-locator="general_product_list"] li',
            'ul[data-qa-locator="general_product_list"] a[href*="/products/"]',
            'a[href*="/products/"]',
        ], 8000);

        $this->autoScroll($d, 2);
        usleep(300_000);

        $cards = $d->findElements(By::cssSelector('[data-qa-locator="product-item"]'));
        if (empty($cards)) {
            $cards = $d->findElements(By::xpath('//*[@data-qa-locator="general-products"]//*[@data-qa-locator="product-item"]'));
        }

        $norm = function (string $u): string {
            if ($u === '')
                return '';
            if (str_starts_with($u, '//'))
                return 'https:' . $u;
            if (str_starts_with($u, '/'))
                return 'https://www.lazada.co.id' . $u;
            return $u;
        };

        foreach ($cards as $card) {
            if (count($out) >= $limit)
                break;

            $aEls = $card->findElements(By::xpath('.//a[contains(@href,"/products/")]'));
            if (!$aEls)
                continue;
            $urlProd = $norm((string) $aEls[0]->getAttribute('href'));

            $priceRegular = '';
            $priceNodes = $card->findElements(By::xpath('.//div[contains(@class,"aBrP0")]//*[contains(@class,"ooOxS")]'));
            if ($priceNodes) {
                $txt = trim($priceNodes[0]->getText());
                if (preg_match('/Rp\s?[0-9\.\,]+/u', $txt, $m))
                    $priceRegular = $m[0];
            }
            $priceDiscount = null;

            $sold = '';
            if ($sold === '') {
                $full = trim($card->getAttribute('innerText') ?? $card->getText());
                $full = preg_replace('/\s+/', ' ', mb_strtolower($full));
                if (preg_match('/(\d[\d\.\,]*)\s*sold(?:\s*\(\d+\))?/u', $full, $m) || preg_match('/(\d[\d\.\,]*)\s*terjual/u', $full, $m))
                    $sold = $m[1];
            }

            $kota = '';
            $locEls = $card->findElements(By::xpath('.//span[contains(@class,"oa6ri")]'));
            if ($locEls) {
                $kota = trim((string) $locEls[0]->getAttribute('title') ?: $locEls[0]->getText());
                $kota = preg_replace('/\s+/', ' ', $kota);
                if ($kota !== '' && preg_match('/^\d+(\.\d+)?$/', $kota))
                    $kota = '';
            }

            $storeName = '';
            $sn = $card->findElements(By::xpath('.//span[contains(@class,"seller-name")]'));
            if ($sn)
                $storeName = preg_replace('/\s+/', ' ', trim($sn[0]->getText()));

            if ($priceRegular === '' && $sold === '' && $storeName === '')
                continue;

            $provinsi = '';
            if ($kota) {
                $provinsi = $this->lookupProvinceByCity($kota);

                $upper = strtoupper($provinsi);
                if (str_starts_with($upper, 'DKI ')) {
                    $provinsi = 'DKI ' . ucwords(strtolower(substr($provinsi, 4)));
                } elseif (str_starts_with($upper, 'DIY')) {
                    $provinsi = 'DIY';
                } elseif (str_starts_with($upper, 'DI ')) {
                    $provinsi = 'DI ' . ucwords(strtolower(substr($provinsi, 3)));
                } else {
                    $provinsi = ucwords(strtolower($provinsi));
                }
            }

            $out[] = [
                'url' => $urlProd,
                'store_name' => $storeName,
                'price_discount' => $priceDiscount,
                'price_regular' => $priceRegular,
                'sold' => $sold,
                'kota' => $kota,
                'provinsi' => $provinsi
            ];
        }

        usort($out, function ($a, $b) {
            $pa = (int) preg_replace('/[^0-9]/', '', $a['price_discount'] ?? '') ?: (int) preg_replace('/[^0-9]/', '', $a['price_regular'] ?? '');
            $pb = (int) preg_replace('/[^0-9]/', '', $b['price_discount'] ?? '') ?: (int) preg_replace('/[^0-9]/', '', $b['price_regular'] ?? '');
            return $pa <=> $pb;
        });

        if (count($out) === 0) {
            $this->debugSnapshot($d, 'lazada_zero_after_parse');
        }

        return array_slice($out, 0, $limit);
    }

    /* ====================== Helpers ====================== */

    private ?array $CITY_PROVINCE = null;

    private function loadCityProvince(): void
    {
        if ($this->CITY_PROVINCE !== null)
            return;

        $path = storage_path('app/city_province.json');
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        // pastikan key lower-case
        $this->CITY_PROVINCE = array_change_key_case($data, CASE_LOWER);
    }

    private function normalizeCity(string $raw): string
    {
        $s = trim(mb_strtolower($raw));
        // hapus karakter non-alfanumerik ringan
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_replace(['kabupaten', 'kab.', 'kota adm.', 'kota'], '', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * @return string
     */
    private function lookupProvinceByCity(string $city): string
    {
        $this->loadCityProvince();

        if ($city === '')
            return '';

        $norm = $this->normalizeCity($city);

        // direct exact
        if (isset($this->CITY_PROVINCE[$norm])) {
            return $this->CITY_PROVINCE[$norm];
        }

        foreach (["kabupaten {$norm}", "kab. {$norm}", "kota {$norm}"] as $variant) {
            $v = mb_strtolower($variant);
            if (isset($this->CITY_PROVINCE[$v])) {
                return $this->CITY_PROVINCE[$v];
            }
        }

        if (preg_match('/jakarta|jakut|jakbar|jaksel|jaktim|jakpus/u', $norm)) {
            return 'DKI Jakarta';
        }
        if (preg_match('/yogyakarta|jogja|sleman|bantul|kulon progo|gunung kidul|gunungkidul/u', $norm)) {
            return 'DI Yogyakarta';
        }

        $best = ['', 0];
        foreach ($this->CITY_PROVINCE as $k => $prov) {
            similar_text($norm, $k, $pct);
            if ($pct > $best[1])
                $best = [$prov, $pct];
            if ($pct >= 92)
                return $prov;
        }
        if ($best[1] >= 86) {
            return $best[0];
        }

        return '';
    }

    private function waitAny(RemoteWebDriver $d, array $selectors, int $ms = 10000): ?RemoteWebElement
    {
        $end = microtime(true) + ($ms / 1000);
        do {
            foreach ($selectors as $css) {
                try {
                    if (str_starts_with(trim($css), '//')) {
                        $els = $d->findElements(By::xpath($css));
                    } else {
                        $els = $d->findElements(By::cssSelector($css));
                    }
                    if (!empty($els))
                        return $els[0];
                } catch (\Throwable $e) {
                }
            }
            usleep(200_000); // 200ms
        } while (microtime(true) < $end);
        return null;
    }

    private function tryCloseModals(): void
    {
        $d = $this->driver;
        foreach ([
            'button[aria-label="Tutup"]',
            'button[aria-label="Close"]',
            'button[aria-label="close"]',
            'button#topActionSwitch',
            'button.cookie-banner__button',
            'button[role="button"][data-qa-locator="close"]',
        ] as $css) {
            try {
                $els = $d->findElements(By::cssSelector($css));
                if ($els) {
                    $els[0]->click();
                    usleep(300_000);
                }
            } catch (\Throwable $e) {
            }
        }
        foreach ([
            '//button[contains(., "Terima semua")]',
            '//button[contains(translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "accept all")]',
        ] as $xp) {
            try {
                $els = $d->findElements(By::xpath($xp));
                if ($els) {
                    $els[0]->click();
                    usleep(300_000);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    private function autoScroll(RemoteWebDriver $d, int $steps = 4): void
    {
        for ($i = 0; $i < $steps; $i++) {
            try {
                $d->executeScript('window.scrollBy(0, window.innerHeight);');
            } catch (\Throwable $e) {
            }
            usleep(500_000);
        }
    }

    private function navWithRetry(RemoteWebDriver $d, string $url, int $tries = 2): void
    {
        for ($i = 0; $i < $tries; $i++) {
            try {
                $d->get($url);
                return;
            } catch (\Facebook\WebDriver\Exception\WebDriverCurlException $e) {
                usleep(800_000);
                if ($i === $tries - 1)
                    throw $e;
            }
        }
    }

    private function isCaptchaOrDenied(): bool
    {
        try {
            $h = strtolower($this->driver->getPageSource());
            return str_contains($h, 'captcha') || str_contains($h, 'verify you are human') || str_contains($h, 'access denied') || str_contains($h, 'robot check');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function debugSnapshot(RemoteWebDriver $d, string $prefix = 'lazada'): void
    {
        try {
            $ts = date('Ymd_His');
            @mkdir(storage_path('logs'), 0777, true);
            $d->takeScreenshot(storage_path("logs/{$prefix}_{$ts}.png"));
            file_put_contents(storage_path("logs/{$prefix}_{$ts}.html"), $d->getPageSource());
        } catch (\Throwable $e) {
        }
    }
}
