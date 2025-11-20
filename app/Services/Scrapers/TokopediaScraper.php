<?php

namespace App\Services\Scrapers;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy as By;
use Log;

class TokopediaScraper implements MarketplaceScraper
{
    public function __construct(
        private RemoteWebDriver $driver
    ) {
    }

    public function searchTop(string $keyword, int $limit = 3): array
    {
        $out = [];
        $d = $this->driver;

        try{

            $q = rawurlencode($keyword);
            $url = "https://www.tokopedia.com/search?st=product&q={$q}&ob=3";

            $this->navWithRetry($d, $url, 3);

            $this->tryCloseModals();

            $container = $this->waitAny($d, [
                '[data-testid="divSRPContentProducts"][data-ssr="contentProductsSRPSSR"]',
                '[data-testid="divSRPContentProducts"]',
            ], 15000);

            if (!$container) {
                $this->autoScroll($d, 3);
                $container = $this->waitAny($d, [
                    '[data-testid="divSRPContentProducts"]',
                ], 8000);

                if (!$container) {
                    if ($this->isCaptchaOrDenied()) {
                        $this->debugSnapshot($d, 'tokopedia_captcha');
                        return [];
                    }
                    $this->debugSnapshot($d, 'tokopedia_no_container');
                    return [];
                }
            }

            $cards = $container->findElements(By::cssSelector('a[href^="https://www.tokopedia.com/"]'));
            if (count($cards) === 0) {
                $cards = $d->findElements(By::cssSelector('[data-testid="divSRPContentProducts"] a[href^="https://www.tokopedia.com/"]'));
            }

            foreach ($cards as $a) {
                if (count($out) >= $limit)
                    break;

                $urlProd = $a->getAttribute('href') ?? '';

                // Gambar
                // $image = $this->attrOr($a, './/img[@alt="product-image"]', 'src');

                // Harga
                $rpNodes = $a->findElements(By::xpath('.//span[starts-with(normalize-space(.),"Rp")] | .//div[starts-with(normalize-space(.),"Rp")] | .//s[starts-with(normalize-space(.),"Rp")] | .//del[starts-with(normalize-space(.),"Rp")]'));
                $prices = [];
                foreach ($rpNodes as $n) {
                    $t = trim($n->getText());
                    if ($t === '')
                        continue;
                    if (preg_match_all('/Rp\s?[0-9\.\,]+/u', $t, $m)) {
                        foreach ($m[0] as $hit) {
                            if (empty($prices) || end($prices) !== $hit) {
                                $prices[] = $hit;
                            }
                        }
                    }
                }

                if (count($prices) > 1) {
                    $priceDiscount = $prices[0] ?? '';
                    $priceRegular = $prices[1] ?? '';
                } else {
                    $priceRegular = $prices[0] ?? '';
                    $priceDiscount = null;
                }

                // Terjual
                $sold = $this->textOr($a, './/span[contains(normalize-space(.),"terjual")][1]');

                // Nama toko & kota
                $storeName = '';
                $kota = '';
                $hitPath = [];

                // 1) Dengan badge
                $storeName = $this->textOr($a, './/div[.//img[@alt="shop badge"]]/following-sibling::div[1]/span[contains(@class,"flip")][1]');
                $kota = $this->textOr($a, './/div[.//img[@alt="shop badge"]]/following-sibling::div[1]/span[contains(@class,"flip")][2]');
                if ($storeName !== '')
                    $hitPath[] = 'store:badge[1]';
                if ($kota !== '')
                    $hitPath[] = 'city:badge[2]';

                // 2) Tanpa badge
                if ($storeName === '') {
                    $storeName = $this->textOr($a, './/div[span[contains(@class,"flip")]][1]/span[contains(@class,"flip")][1]');
                    if ($storeName !== '')
                        $hitPath[] = 'store:nobadge[1]';
                }
                if ($kota === '') {
                    $kota = $this->textOr($a, './/div[span[contains(@class,"flip")]][1]/span[contains(@class,"flip")][2]');
                    if ($kota !== '')
                        $hitPath[] = 'city:nobadge[2]';
                }

                if ($kota === '') {
                    $kota = $this->textOr($a, '(.//span[contains(@class,"flip")])[last()]');
                    if ($kota !== '')
                        $hitPath[] = 'city:last-flip';
                }

                // 4) Format lama "Toko • Kota"
                if ($storeName !== '' && $kota === '') {
                    $shopLoc = $this->textOr($a, './/span[contains(normalize-space(.),"•")]');
                    if ($shopLoc !== '' && str_contains($shopLoc, '•')) {
                        [, $kota] = array_map('trim', explode('•', $shopLoc, 2));
                        if ($kota !== '')
                            $hitPath[] = 'city:dot-bullet';
                    }
                }

                if ($kota === '') {
                    $kota = $this->jsSelectLastFlipText($this->driver, $a);
                    if ($kota !== '')
                        $hitPath[] = 'city:js-last-flip';
                }

                if ($kota !== '') {
                    if (preg_match('/\bterjual\b|\brating\b/i', $kota))
                        $kota = '';
                    if ($kota !== '' && preg_match('/^\d+(?:[\.\,]\d+)?$/', $kota))
                        $kota = '';
                }

                if ($priceDiscount === '' && $sold === '' && $storeName === '') {
                    continue;
                }

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
                    'image' => null,
                    'store_name' => $storeName,
                    'price_discount' => $priceDiscount,
                    'price_regular' => $priceRegular,
                    'sold' => $sold,
                    'kota' => $kota,
                    'provinsi' => $provinsi
                ];
                 // cleanup
                unset($entry, $priceCandidates, $priceDiscount, $priceRegular, $storeName, $kota, $provinsi, $image);
                gc_collect_cycles();
            }

            usort($out, function ($a, $b) {
                $pa = (int) preg_replace('/[^0-9]/', '', $a['price_discount'] ?? '');
                $pb = (int) preg_replace('/[^0-9]/', '', $b['price_discount'] ?? '');
                return $pa <=> $pb;
            });

            if (count($out) === 0) {
                $this->debugSnapshot($d, 'tokopedia_zero_after_parse');
            }

            return array_slice($out, 0, $limit);
        } finally {
            try { $d->quit(); } catch (\Throwable $e) {}
            gc_collect_cycles();        }
    }

    public function fetchDetailPrices(string $productUrl): array
    {
        $d = $this->driver;

        // 1) buka halaman
        $this->navWithRetry($d, $productUrl, 3);
        $this->tryCloseModals();

        // 2) tunggu elemen inti halaman PDP
        $container = $this->waitAny($d, [
            '[data-testid="PDPMainSection"]',
            '[data-testid="pdpDesktop"]',
            'main[data-testid="pdpContent"]',
            'div[aria-label="product-detail"]',
            'body'
        ], 12000);

        if (!$container) {
            if ($this->isCaptchaOrDenied()) {
                $this->debugSnapshot($d, 'pdp_captcha_denied');
            } else {
                $this->debugSnapshot($d, 'pdp_container_missing');
            }
            return ['price_regular' => null, 'price_discount' => null, 'url' => $productUrl];
        }

        // 3) scroll dikit biar lazy-render jalan
        $this->autoScroll($d, 1);

        // 4) kumpulkan kandidat node harga (beberapa versi UI)
        $candidates = [];

        // Versi baru/sering dipakai
        $candidates = array_merge($candidates, $d->findElements(By::cssSelector('[data-testid="lblPDPDetailProductPrice"]')));
        $candidates = array_merge($candidates, $d->findElements(By::cssSelector('[data-testid="lblPDPDetailProductOriginalPrice"]')));
        $candidates = array_merge($candidates, $d->findElements(By::cssSelector('[data-testid="lblPDPDetailProductDiscountLabel"]')));

        // Elemen lain yang sering berisi "Rp ..."
        $candidates = array_merge($candidates, $d->findElements(By::xpath(
            '//*[self::span or self::div or self::p or self::s or self::del][contains(normalize-space(.),"Rp")]'
        )));

        // 5) Ambil teks “Rp …” dari node-node kandidat (urutkan agar harga aktif biasanya terakhir)
        $texts = [];
        foreach ($candidates as $el) {
            try {
                $txt = trim($el->getText());
                if ($txt !== '') $texts[] = $txt;
            } catch (\Throwable $e) {}
        }
        $texts = array_values(array_unique($texts));

        // 6) Ekstrak semua angka harga (biar tahan perubahan markup)
        $prices = $this->extractAllRupiah($texts);

        // Heuristik umum Tokopedia PDP:
        // - Jika ada 2 harga: satu dicoret (original/regular), satu aktif (discount)
        // - Jika hanya 1 harga: itu berarti regular (tanpa diskon)
        // Untuk memperkuat heuristik, cek node <s>/<del> (coret)
        $regularFromStrikethrough = $this->extractStrikethroughPrice($d);

        $priceRegular = null;
        $priceDiscount = null;

        if ($regularFromStrikethrough) {
            // ada harga coret -> itulah regular
            $priceRegular = $regularFromStrikethrough;
            // harga aktif = harga terbesar yang BUKAN coret? (umumnya harga aktif lebih kecil)
            $priceDiscount = $this->pickActivePrice($prices, $priceRegular);
        } else {
            if (count($prices) >= 2) {
                // Ambil dua harga unik: pilih yang lebih besar sebagai regular, lebih kecil sebagai discount
                [$hi, $lo] = $this->hiLo($prices);
                $priceRegular  = $hi;
                $priceDiscount = $lo;
            } elseif (count($prices) === 1) {
                $priceRegular = $prices[0];
                $priceDiscount = null;
            } else {
                // fallback: coba selector spesifik sekali lagi
                $priceRegular = $this->textFirstCss($d, '[data-testid="lblPDPDetailProductPrice"]') ?: null;
                if ($priceRegular && !str_starts_with($priceRegular, 'Rp')) {
                    $priceRegular = $this->firstRpIn($priceRegular);
                }
            }
        }

        // Normalisasi format "Rp 1.234.567"
        $priceRegular  = $this->normalizeRupiah($priceRegular);
        $priceDiscount = $this->normalizeRupiah($priceDiscount);

        if (!$priceRegular && !$priceDiscount) {
            $this->debugSnapshot($d, 'pdp_no_prices_found');
        }

        return [
            'price_regular'  => $priceRegular,
            'price_discount' => $priceDiscount,
            'url'            => $productUrl,
        ];
    }

    /* ====================== Helpers ====================== */

    private function textFirstCss(RemoteWebDriver $d, string $css): ?string
    {
        try {
            $els = $d->findElements(By::cssSelector($css));
            if (!$els) return null;
            $t = trim($els[0]->getText());
            return $t !== '' ? $t : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Ambil semua substring yang match “Rp …” dari kumpulan teks */
    private function extractAllRupiah(array $texts): array
    {
        $hits = [];
        foreach ($texts as $t) {
            if (preg_match_all('/Rp\s?[0-9\.\,]+/u', $t, $m)) {
                foreach ($m[0] as $rp) {
                    $rp = $this->normalizeRupiah($rp);
                    if ($rp && (empty($hits) || end($hits) !== $rp)) {
                        $hits[] = $rp;
                    }
                }
            }
        }
        // uniq lagi (kadang duplikat)
        $hits = array_values(array_unique($hits));
        return $hits;
    }

    /** Cari harga di elemen coret <s>/<del> sebagai regular */
    private function extractStrikethroughPrice(RemoteWebDriver $d): ?string
    {
        try {
            $els = $d->findElements(By::xpath('//s[contains(.,"Rp")] | //del[contains(.,"Rp")]'));
            foreach ($els as $el) {
                $t = trim($el->getText());
                $rp = $this->firstRpIn($t);
                if ($rp) return $this->normalizeRupiah($rp);
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /** Dari daftar harga (string Rp …), pilih harga aktif (biasanya < regular) */
    private function pickActivePrice(array $prices, string $regular): ?string
    {
        $regNum = $this->rupiahToInt($regular);
        $best = null;
        foreach ($prices as $p) {
            if ($p === $regular) continue;
            $n = $this->rupiahToInt($p);
            if ($n > 0 && $n < $regNum) {
                if ($best === null || $n < $this->rupiahToInt($best)) {
                    $best = $p;
                }
            }
        }
        return $best;
    }

    /** Kembalikan [harga_terbesar, harga_terkecil] dari kumpulan harga */
    private function hiLo(array $prices): array
    {
        $nums = [];
        foreach ($prices as $p) $nums[$p] = $this->rupiahToInt($p);
        arsort($nums, SORT_NUMERIC);
        $ordered = array_keys($nums);
        $hi = $ordered[0];
        $lo = end($ordered);
        return [$hi, $lo];
    }

    private function firstRpIn(string $text): ?string
    {
        if (preg_match('/Rp\s?[0-9\.\,]+/u', $text, $m)) return $m[0];
        return null;
    }

    private function normalizeRupiah(?string $rp): ?string
    {
        if (!$rp) return null;
        // bersihkan whitespace ganda, pastikan "Rp " + angka berformat titik ribuan
        $rp = preg_replace('/\s+/u', ' ', trim($rp));
        if (!str_starts_with($rp, 'Rp')) {
            // mungkin hanya angka, tetap balut
            if (preg_match('/^[0-9\.\,]+$/', $rp)) $rp = 'Rp ' . $rp;
        }
        return $rp;
    }

    private function rupiahToInt(?string $rp): int
    {
        if (!$rp) return 0;
        $n = preg_replace('/[^0-9]/', '', $rp);
        return (int) $n;
    }
    
    private function logFlipSpans(RemoteWebDriver $d, $node): void
    {
        try {
            $texts = $d->executeScript(
                'return Array.from(arguments[0].querySelectorAll("span.flip")).map(e => (e.textContent || "").trim());',
                [$node]
            );
            $this->logHit('flip_spans', ['count' => is_array($texts) ? count($texts) : null, 'texts' => $texts]);
        } catch (\Throwable $e) {
        }
    }

    private function jsSelectLastFlipText(RemoteWebDriver $d, $node): string
    {
        try {
            $texts = $d->executeScript(
                'return Array.from(arguments[0].querySelectorAll("span.flip")).map(e => (e.textContent || "").trim());',
                [$node]
            );
            if (is_array($texts) && count($texts) > 0) {
                return trim(end($texts));
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    private int $dbgCardDumpCount = 0;
    private int $dbgCardDumpMax = 5;

    private function logHit(string $tag, array $ctx = []): void
    {
        try {
            Log::info('[TokopediaScraper] ' . $tag, $ctx);
        } catch (\Throwable $e) {
        }
    }

    private function dumpShopBlock($node, string $reason = 'kota_empty'): void
    {
        if ($this->dbgCardDumpCount >= $this->dbgCardDumpMax)
            return;

        try {
            $html = $node->getAttribute('innerHTML') ?? '';
            $ts = date('Ymd_His');
            @mkdir(storage_path('logs/scraper'), 0777, true);
            file_put_contents(storage_path("logs/scraper/{$reason}_{$ts}.html"), $html);
            $this->dbgCardDumpCount++;
            $this->logHit('dump_shopblock_saved', ['file' => "logs/scraper/{$reason}_{$ts}.html"]);
        } catch (\Throwable $e) {
        }
    }

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

    private function waitAny(RemoteWebDriver $d, array $selectors, int $ms = 10000): ?\Facebook\WebDriver\Remote\RemoteWebElement
    {
        $end = microtime(true) + ($ms / 1000);
        do {
            foreach ($selectors as $css) {
                try {
                    $els = $d->findElements(By::cssSelector($css));
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
            '[data-testid="btnHeaderClose"]',
            'button[aria-label="Close"]',
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
                usleep(800_000); // 0.8s
                if ($i === $tries - 1) {
                    throw $e;
                }
            }
        }
    }

    private function isCaptchaOrDenied(): bool
    {
        try {
            $html = $this->driver->getPageSource();
            $h = strtolower($html);
            return str_contains($h, 'captcha') || str_contains($h, 'access denied') || str_contains($h, 'verify you are human');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function debugSnapshot(RemoteWebDriver $d, string $prefix = 'tokopedia'): void
    {
        try {
            $ts = date('Ymd_His');
            @mkdir(storage_path('logs'), 0777, true);
            $d->takeScreenshot(storage_path("logs/{$prefix}_{$ts}.png"));
            file_put_contents(storage_path("logs/{$prefix}_{$ts}.html"), $d->getPageSource());
        } catch (\Throwable $e) {
        }
    }

    private function textOr($node, string $xpath): string
    {
        try {
            $els = $node->findElements(By::xpath($xpath));
            if (!$els)
                return '';

            $txt = trim($els[0]->getText());

            if ($txt === '') {
                $inner = $els[0]->getAttribute('innerText');
                if (is_string($inner))
                    $txt = trim($inner);
            }

            if ($txt === '') {
                $tc = $els[0]->getAttribute('textContent');
                if (is_string($tc))
                    $txt = trim($tc);
            }

            return preg_replace('/\s+/u', ' ', $txt ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
