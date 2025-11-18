<?php

namespace App\Services\Scrapers;

use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy as By;
use Facebook\WebDriver\WebDriverExpectedCondition as EC;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Facades\Log;

class ShopeeScraper implements MarketplaceScraper
{
    private bool $debug;
    private string $shotDir;
    private bool $requireLogin;

    public function __construct(private RemoteWebDriver $driver)
    {
        $this->debug = (bool) env('SHOPEE_DEBUG', false);
        $this->requireLogin = (bool) env('SHOPEE_REQUIRE_LOGIN', false);
        $this->shotDir = storage_path('app/shopee_shots');
        if (!is_dir($this->shotDir))
            @mkdir($this->shotDir, 0775, true);
    }

    public function searchTop(string $keyword, int $limit = 3): array
    {
        try {
            $maxPages = (int) env('SHOPEE_MAX_PAGES', 3);
            $this->openResultsForKeyword($keyword);
            $items = [];
            for ($page = 1; $page <= $maxPages; $page++) {
                $this->naturalScrollAndWait();
                $items = array_merge($items, $this->extractItemsFromResultPage());
                if (count($items) >= $limit)
                    break;
                if (!$this->goNextPage())
                    break;
            }
            $normalized = $this->normalizeAndSort($items);
            $top = array_slice($normalized, 0, $limit);
            Log::info('[Shopee UI] items=' . count($normalized) . ' return=' . count($top));
            return $top;
        } catch (\Throwable $e) {
            Log::error('[Shopee UI] searchTop error: ' . $e->getMessage());
            if ($this->debug)
                $this->debugSnapshot('fatal');
            return [];
        }
    }

    private function openResultsForKeyword(string $keyword): void
    {
        $url = 'https://shopee.co.id/search?keyword=' . rawurlencode($keyword);
        $this->driver->get($url);
        $this->driver->wait(30)->until(EC::presenceOfElementLocated(By::tagName('body')));
        $this->dismissConsentAndOverlays();
        $this->interactionBurstLight();
        if ($this->waitForResultsReady(8))
            return;
        if ($this->isLoginPage()) {
            if (!$this->requireLogin) {
                if ($this->debug)
                    $this->debugSnapshot('redirected-to-login');
                throw new \RuntimeException('Redirect ke halaman login. (Set SHOPEE_REQUIRE_LOGIN=true + kredensial kalau mau login.)');
            }
            $this->login();
            $this->driver->get($url);
            $this->driver->wait(30)->until(EC::presenceOfElementLocated(By::tagName('body')));
            $this->dismissConsentAndOverlays();
            $this->interactionBurstLight();
        }
        if (!$this->waitForResultsReady(12)) {
            if ($this->debug) {
                Log::warning('[Shopee UI] results container not found; fallback to homepage search');
                $this->debugSnapshot('no-results-container');
            }
            $this->openHomeAndSearch($keyword);
            $this->dismissConsentAndOverlays();
            $this->interactionBurstLight();
            if (!$this->waitForResultsReady(12)) {
                throw new \RuntimeException('Search result container masih tidak ditemukan setelah fallback.');
            }
        }
    }

    private function openHomeAndSearch(string $keyword): void
    {
        $this->driver->get('https://shopee.co.id/');
        $this->driver->wait(30)->until(EC::presenceOfElementLocated(By::tagName('body')));
        $this->dismissConsentAndOverlays();
        if ($this->isLoginPage()) {
            if (!$this->requireLogin)
                throw new \RuntimeException('Homepage redirect ke login.');
            $this->login();
            $this->driver->get('https://shopee.co.id/');
            $this->driver->wait(30)->until(EC::presenceOfElementLocated(By::tagName('body')));
            $this->dismissConsentAndOverlays();
        }
        $search = $this->firstVisible([
            'css:input.shopee-searchbar__input',
            'css:input[placeholder*="Cari"]',
            'css:input[type="text"][autocomplete="off"]',
            'xpath://input[contains(@placeholder,"Cari")]',
        ], 15);
        if (!$search) {
            if ($this->debug)
                $this->debugSnapshot('searchbox-not-found');
            throw new \RuntimeException('Search box tidak ditemukan—UI Shopee berubah atau tertutup overlay.');
        }
        $search->clear();
        $search->sendKeys($keyword);
        $search->submit();
        $this->driver->wait(30)->until(
            EC::or(
                EC::urlContains('keyword=' . rawurlencode($keyword)),
                EC::presenceOfElementLocated(By::cssSelector('.shopee-search-item-result__items'))
            )
        );
    }

    private function interactionBurstLight(): void
    {
        try {
            $actions = new WebDriverActions($this->driver);
            $actions
                ->moveByOffset(rand(3, 30), rand(3, 30))
                ->pause(0.15)
                ->moveByOffset(rand(-15, 15), rand(-10, 10))
                ->perform();
            $actions->sendKeys(WebDriverKeys::ARROW_DOWN)->pause(0.1)->sendKeys(WebDriverKeys::ARROW_DOWN)->perform();
            $steps = rand(2, 4);
            for ($i = 0; $i < $steps; $i++) {
                $delta = rand(60, 110) / 100.0;
                $this->driver->executeScript('window.scrollBy(0, Math.floor(window.innerHeight * arguments[0]));', [$delta]);
                usleep(rand(250, 700) * 1000);
                if ($i === 1) {
                    $up = rand(5, 15) / 100.0;
                    $this->driver->executeScript('window.scrollBy(0, -Math.floor(window.innerHeight * arguments[0]));', [$up]);
                    usleep(rand(200, 500) * 1000);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function isLoginPage(): bool
    {
        try {
            $url = $this->driver->getCurrentURL();
            if (str_contains($url, '/buyer/login'))
                return true;
            $inputs = $this->driver->findElements(By::cssSelector('input[name="loginKey"], input[type="password"]'));
            $btns = $this->driver->findElements(By::cssSelector('button[type="submit"]'));
            return count($inputs) > 0 && count($btns) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function waitForResultsReady(int $sec): bool
    {
        try {
            $this->driver->wait($sec)->until(
                EC::or(
                    EC::presenceOfElementLocated(By::cssSelector('.shopee-search-item-result__items')),
                    EC::presenceOfElementLocated(By::cssSelector('div[data-sqe="item"]'))
                )
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function naturalScrollAndWait(): void
    {
        $roundMin = (int) env('SHOPEE_SCROLL_MIN_ROUNDS', 2);
        $roundMax = (int) env('SHOPEE_SCROLL_MAX_ROUNDS', 5);
        $stepMin = (int) env('SHOPEE_SCROLL_STEP_MIN', 6);
        $stepMax = (int) env('SHOPEE_SCROLL_STEP_MAX', 14);
        $rounds = max($roundMin, min($roundMax, random_int($roundMin, $roundMax)));
        $baseline = $this->countResultCards();
        for ($r = 0; $r < $rounds; $r++) {
            $steps = max($stepMin, min($stepMax, random_int($stepMin, $stepMax)));
            for ($i = 0; $i < $steps; $i++) {
                $delta = random_int(60, 110) / 100.0;
                $this->driver->executeScript('window.scrollBy(0, Math.floor(window.innerHeight * arguments[0]));', [$delta]);
                usleep(random_int(300, 900) * 1000);
                if ($i > 0 && $i % random_int(3, 5) === 0) {
                    $up = random_int(5, 20) / 100.0;
                    $this->driver->executeScript('window.scrollBy(0, -Math.floor(window.innerHeight * arguments[0]));', [$up]);
                    usleep(random_int(250, 600) * 1000);
                }
            }
            $this->waitNewItemsSince($baseline, 8);
            $baseline = $this->countResultCards();
        }
    }

    private function waitNewItemsSince(int $baseline, int $timeoutSec = 8): void
    {
        $deadline = microtime(true) + $timeoutSec;
        do {
            if ($this->countResultCards() > $baseline)
                return;
            usleep(400 * 1000);
        } while (microtime(true) < $deadline);
    }

    private function countResultCards(): int
    {
        try {
            $this->waitForResultsReady(10);
            $cards = $this->driver->findElements(
                By::cssSelector('.shopee-search-item-result__items > div, .shopee-search-item-result__item, div[data-sqe="item"]')
            );
            return count($cards);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function extractItemsFromResultPage(): array
    {
        $this->waitForResultsReady(20);
        $cards = $this->driver->findElements(
            By::cssSelector('.shopee-search-item-result__items > div, .shopee-search-item-result__item, div[data-sqe="item"]')
        );
        $items = [];
        foreach ($cards as $card) {
            try {
                $a = $this->firstChild($card, ['css:a[data-sqe="link"]', 'css:a']);
                $href = $a ? $a->getAttribute('href') : null;
                if ($href && !str_starts_with($href, 'http'))
                    $href = 'https://shopee.co.id' . $href;
                if (!$href)
                    continue;
                $imgEl = $this->firstChild($card, ['css:img']);
                $img = '';
                if ($imgEl) {
                    $img = $imgEl->getAttribute('src') ?: ($imgEl->getAttribute('data-src') ?: '');
                    if ($img && str_starts_with($img, '//'))
                        $img = 'https:' . $img;
                    if ($img && !filter_var($img, FILTER_VALIDATE_URL))
                        $img = '';
                }
                $priceText = $this->firstText($card, [
                    'css:span[data-sqe="price"]',
                    'css:.ZEgDH9',
                    'css:.zY7q5X',
                    'css:div[class*="hpDKMN"]',
                    'css:div[class*="WgYvse"]',
                ]) ?? '';
                $price = $this->parsePriceToInt($priceText);
                $location = $this->firstText($card, [
                    'css:._1wVLAc',
                    'css:.go5yPW',
                    'css:div[class*="go5yPW"]'
                ]) ?? '';
                $sold = '';
                foreach ($card->findElements(By::cssSelector('div, span')) as $el) {
                    $t = trim((string) $el->getText());
                    if ($t !== '' && mb_stripos($t, 'terjual') !== false) {
                        $sold = $t;
                        break;
                    }
                }
                $store = $this->firstText($card, [
                    'css:._3Gla5W',
                    'css:a[data-sqe="shop_link"]',
                    'css:div[class*="shop"]',
                ]) ?? '';
                $items[] = [
                    'url' => $href,
                    'image' => $img,
                    'store_name' => trim($store),
                    'price_text' => $priceText,
                    'price_number' => $price,
                    'sold' => $sold,
                    'kota' => trim($location),
                    'provinsi' => '',
                ];
            } catch (\Throwable $e) {
            }
        }
        if ($this->debug)
            Log::debug('[Shopee UI DEBUG] parsed cards=' . count($cards) . ' items=' . count($items));
        return $items;
    }

    private function goNextPage(): bool
    {
        $next = $this->firstVisible([
            'css:button.shopee-icon-button.shopee-icon-button--right',
            'css:button[aria-label="Next"]',
            'xpath://button[contains(@aria-label,"Next")]',
        ], 6);
        if (!$next)
            return false;
        try {
            $this->driver->executeScript('arguments[0].click();', $next);
            usleep(600 * 1000);
            $this->waitForResultsReady(15);
            $this->naturalScrollAndWait();
            return true;
        } catch (\Throwable $e) {
            Log::debug('[Shopee UI] next click failed: ' . $e->getMessage());
            return false;
        }
    }

    private function dismissConsentAndOverlays(): void
    {
        $this->clickAny([
            'css:button.cookie-banner__agree',
            'css:button[class*="cookie"]',
            'xpath://button[contains(., "Terima")]',
            'xpath://button[contains(., "Setuju")]',
            'xpath://button[contains(., "Accept")]',
            'xpath://button[contains(., "Got it")]',
        ]);
        $this->clickAny([
            'css:.shopee-popup__close-btn',
            'css:.stardust-modal__close',
            'xpath://button[contains(., "Tutup")]',
            'xpath://button[contains(., "Close")]',
            'xpath://button[contains(., "Nanti")]',
            'xpath://button[contains(., "OK")]',
        ]);
        $this->clickAny([
            'xpath://button[contains(., "Indonesia")]',
            'xpath://button[contains(., "Simpan")]',
            'xpath://button[contains(., "Save")]',
        ]);
    }

    private function clickAny(array $selectors): void
    {
        foreach ($selectors as $sel) {
            try {
                $el = $this->firstVisible([$sel], 1);
                if ($el) {
                    $this->driver->executeScript('arguments[0].click();', $el);
                    usleep(400 * 1000);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    private function firstVisible(array $selectors, int $waitSeconds = 5): ?RemoteWebElement
    {
        foreach ($selectors as $sel) {
            [$type, $expr] = $this->splitLocator($sel);
            try {
                $by = $type === 'xpath' ? By::xpath($expr) : By::cssSelector($expr);
                $el = $this->driver->wait($waitSeconds)->until(EC::visibilityOfElementLocated($by));
                if ($el)
                    return $el;
            } catch (\Throwable $e) {
            }
        }
        return null;
    }

    private function firstChild(RemoteWebElement $root, array $selectors): ?RemoteWebElement
    {
        foreach ($selectors as $sel) {
            [$type, $expr] = $this->splitLocator($sel);
            $els = $type === 'xpath' ? $root->findElements(By::xpath($expr)) : $root->findElements(By::cssSelector($expr));
            if ($els)
                return $els[0];
        }
        return null;
    }

    private function firstText(RemoteWebElement $root, array $selectors): ?string
    {
        foreach ($selectors as $sel) {
            [$type, $expr] = $this->splitLocator($sel);
            $els = $type === 'xpath' ? $root->findElements(By::xpath($expr)) : $root->findElements(By::cssSelector($expr));
            if ($els) {
                $text = trim((string) $els[0]->getText());
                if ($text !== '')
                    return $text;
            }
        }
        return null;
    }

    private function splitLocator(string $sel): array
    {
        if (str_starts_with($sel, 'xpath:'))
            return ['xpath', substr($sel, 6)];
        if (str_starts_with($sel, 'css:'))
            return ['css', substr($sel, 4)];
        return ['css', $sel];
    }

    private function parsePriceToInt(?string $txt): int
    {
        if (!$txt)
            return 0;
        if (preg_match('/(\d[\d\.]*)/', $txt, $m))
            return (int) str_replace('.', '', $m[1]);
        $digits = preg_replace('/[^\d]/', '', $txt);
        return (int) ($digits ?: 0);
    }

    private function normalizeAndSort(array $items): array
    {
        usort($items, fn($a, $b) => ($a['price_number'] ?? 0) <=> ($b['price_number'] ?? 0));
        $fmt = fn(int $v) => $v > 0 ? 'Rp' . number_format($v, 0, ',', '.') : '';
        $out = [];
        foreach ($items as $it) {
            $out[] = [
                'url' => (string) ($it['url'] ?? ''),
                'image' => (string) ($it['image'] ?? ''),
                'store_name' => trim((string) ($it['store_name'] ?? '')),
                'price_discount' => $fmt((int) ($it['price_number'] ?? 0)),
                'price_regular' => '',
                'sold' => trim((string) ($it['sold'] ?? '')),
                'kota' => trim((string) ($it['kota'] ?? '')),
                'provinsi' => '',
            ];
        }
        return $out;
    }

    private function login(): void
    {
        $email = config('services.shopee.email') ?? env('SHOPEE_EMAIL');
        $pass = config('services.shopee.password') ?? env('SHOPEE_PASSWORD');
        if (!$email || !$pass)
            throw new \RuntimeException('SHOPEE_EMAIL/SHOPEE_PASSWORD belum di-set.');
        if (!$this->isLoginPage()) {
            $this->driver->get('https://shopee.co.id/buyer/login');
            $this->driver->wait(30)->until(EC::presenceOfElementLocated(By::tagName('body')));
        }
        $this->dismissConsentAndOverlays();
        $this->clickAny(['xpath://button[contains(., "Email") or contains(., "Username") or contains(., "telepon")]']);
        $userEl = $this->firstVisible([
            'css:input[name="loginKey"]',
            'css:input[placeholder*="Username"]',
            'css:input[placeholder*="Email"]',
            'css:input[placeholder*="No. Handphone"]',
            'xpath://input[contains(@placeholder,"Username") or contains(@placeholder,"Email") or contains(@placeholder,"Handphone")]',
        ], 15);
        $passEl = $this->firstVisible([
            'css:input[name="password"]',
            'css:input[type="password"]',
            'xpath://input[@type="password" or contains(@placeholder,"Password") or contains(@placeholder,"Kata Sandi")]',
        ], 10);
        if (!$userEl || !$passEl) {
            if ($this->debug)
                $this->debugSnapshot('login-form-not-found');
            throw new \RuntimeException('Form login tidak ditemukan.');
        }
        $userEl->clear();
        $userEl->sendKeys($email);
        $passEl->clear();
        $passEl->sendKeys($pass);
        $loginBtn = $this->firstVisible([
            'css:button[type="submit"]',
            'xpath://button[contains(., "LOG IN") or contains(., "Masuk")]',
        ], 5);
        if ($loginBtn)
            $this->driver->executeScript('arguments[0].click();', $loginBtn);
        usleep(800 * 1000);
        if ($this->isCaptchaPresent()) {
            if ($this->debug)
                $this->debugSnapshot('captcha-detected');
            throw new \RuntimeException('Captcha/anti-bot terdeteksi saat login.');
        }
        if ($this->isOtpScreen()) {
            if ($this->debug)
                $this->debugSnapshot('otp-required');
            throw new \RuntimeException('OTP diperlukan. Flow otomatis dihentikan.');
        }
        $ok = $this->driver->wait(30)->until(function () {
            return !str_contains($this->driver->getCurrentURL(), '/buyer/login');
        });
        if (!$ok)
            throw new \RuntimeException('Login gagal atau masih di halaman login.');
    }

    private function isCaptchaPresent(): bool
    {
        try {
            return count($this->driver->findElements(By::cssSelector('.captcha-container, iframe[src*="captcha"], .g-recaptcha'))) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isOtpScreen(): bool
    {
        try {
            if ($this->driver->findElements(By::cssSelector('input[autocomplete="one-time-code"]')))
                return true;
            if ($this->driver->findElements(By::cssSelector('input[maxlength="6"]')))
                return true;
            $labels = $this->driver->findElements(By::xpath("//*[contains(., 'kode verifikasi') or contains(., 'OTP')]"));
            return count($labels) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function debugSnapshot(string $tag): void
    {
        try {
            $ts = date('Ymd_His');
            $url = $this->driver->getCurrentURL();
            $html = $this->driver->getPageSource();
            $htmlFile = "{$this->shotDir}/{$ts}_{$tag}.html";
            file_put_contents($htmlFile, "<!-- {$url} -->\n" . $html);
            $pngFile = "{$this->shotDir}/{$ts}_{$tag}.png";
            $this->driver->takeScreenshot($pngFile);
            Log::debug("[Shopee UI DEBUG] snapshot saved: {$htmlFile} | {$pngFile}");
        } catch (\Throwable $e) {
            Log::debug('[Shopee UI DEBUG] snapshot failed: ' . $e->getMessage());
        }
    }
}