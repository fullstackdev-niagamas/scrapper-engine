<?php

namespace App\Services\Scrapers;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy as By;
use Illuminate\Support\Facades\Log;

class TokopediaUpdatePriceScraper extends BaseScraper
{
    public function __construct(RemoteWebDriver $driver)
    {
        parent::__construct($driver);
    }

    public function scrape(string $url): array
    {
        $d = $this->driver();

        try {
            $this->navWithRetry($d, $url, 2);
            $this->aggressiveCloseOverlays();

            $ok = $this->waitAny($d, [
                'css=#pdp_comp-product_content [data-testid="lblPDPDetailProductName"]',
                'css=#pdp_comp-product_content [data-testid="lblPDPDetailProductPrice"]',
            ], 15000);

            if (!$ok && $this->looksLikeCaptchaOrDenied()) {
                $this->debugSnapshot('tp_pdp_captcha');
                return ['price_regular' => null, 'price_discount' => null];
            }

            $this->tryCloseModals();

            $ok = $this->waitAny($d, [
                'css=#pdp_comp-product_content [data-testid="lblPDPDetailProductName"]',
                'css=#pdp_comp-product_content [data-testid="lblPDPDetailProductPrice"]',
            ], 15000);

            if (!$ok) {
                $this->debugSnapshot('tp_pdp_no_content');
                return ['price_regular' => null, 'price_discount' => null];
            }

            $priceNowText = $this->textByJsFirst([
                '#pdp_comp-product_content [data-testid="lblPDPDetailProductPrice"]',
                '#pdp_comp-product_content .price[data-testid="lblPDPDetailProductPrice"]',
            ]);

            $priceNowVal = $this->pickMinRupiah($priceNowText);

            $content = $this->firstEl('css', '#pdp_comp-product_content');
            $priceRegularCandidate = null;

            if ($content) {
                $cross = $content->findElements(By::xpath(
                    './/*[self::s or self::del or contains(@class,"strike") or contains(@class,"line-through") or contains(@style,"line-through")][contains(normalize-space(.),"Rp")]'
                ));
                foreach ($cross as $n) {
                    $t = $this->getVisibleText($n);
                    $val = $this->pickMinRupiah($t);
                    if ($val !== null) {
                        $priceRegularCandidate = $val;
                        break;
                    }
                }

                if ($priceRegularCandidate === null) {
                    $allRp = $this->collectAllRpIn($content);

                    if (!empty($allRp)) {
                        sort($allRp);
                        $min = $allRp[0];
                        $max = $allRp[count($allRp) - 1];

                        if (count(array_unique($allRp)) === 1) {
                            $priceNowVal ??= $min;
                        } else {
                            $priceNowVal ??= $min;
                            if ($max > $min) {
                                $priceRegularCandidate = $max;
                            }
                        }
                    }
                }
            }

            $price_regular = null;
            $price_discount = null;

            if ($priceRegularCandidate !== null && $priceNowVal !== null) {
                if ($priceRegularCandidate < $priceNowVal) {
                    [$priceRegularCandidate, $priceNowVal] = [$priceNowVal, $priceRegularCandidate];
                }
                $price_regular = $this->fmtRp($priceRegularCandidate);
                $price_discount = $this->fmtRp($priceNowVal);
            } elseif ($priceNowVal !== null) {
                $price_regular = $this->fmtRp($priceNowVal);
                $price_discount = null;
            } else {
                $this->debugSnapshot('tp_pdp_no_price');
            }

            return compact('price_regular', 'price_discount');
        } catch (\Throwable $e) {
            Log::error('[TP PDP] Exception', ['url' => $url, 'err' => $e->getMessage()]);
            $this->debugSnapshot('tp_pdp_exception');
            throw $e;
        }
    }

    private function looksLikeCaptchaOrDenied(): bool
    {
        try {
            $src = strtolower($this->driver->getPageSource());
            $title = strtolower((string) $this->driver->getTitle());

            $hardSignals = [
                'access denied',
                'attention required',
                'verify you are human',
                'akamai',
            ];

            $hits = 0;
            foreach ($hardSignals as $s) {
                if (str_contains($src, $s) || str_contains($title, $s))
                    $hits++;
            }

            $hasPdp = !empty($this->driver->findElements(
                By::cssSelector('#pdp_comp-product_content')
            ));

            return ($hits >= 1) && !$hasPdp;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function aggressiveCloseOverlays(): void
    {
        $d = $this->driver;

        $end = microtime(true) + 3.0;
        do {
            $closedSomething = false;

            $selectors = [
                'button[aria-label="Tutup"]',
                'button[aria-label="Close"]',
                '[data-testid="btnHeaderClose"]',
                '.css-1kx77i2-unf-btn',
                '.unf-modal__close',
                '.unf-dialog__close',
                '.unf-overlay .unf-close',
                'button:has(svg) [d*="M"]',
            ];

            foreach ($selectors as $css) {
                try {
                    $els = $d->findElements(By::cssSelector($css));
                    if ($els) {
                        $els[0]->click();
                        usleep(200_000);
                        $closedSomething = true;
                    }
                } catch (\Throwable $e) {
                }
            }

            if (!$closedSomething) {
                try {
                    $d->getKeyboard()->sendKeys([\Facebook\WebDriver\WebDriverKeys::ESCAPE]);
                    usleep(150_000);
                } catch (\Throwable $e) {
                }
            }

            try {
                $removed = $d->executeScript(<<<'JS'
                let removed = 0;
                const sel = [
                  '.unf-modal', '.unf-dialog', '.unf-overlay',
                  '[data-testid="modal"], [role="dialog"]',
                  '.css-1kx77i2-unf-modal', '.css-1kx77i2-unf-btn + div',
                ];
                sel.forEach(s => document.querySelectorAll(s).forEach(n => { n.remove(); removed++; }));
                document.documentElement.style.overflow = '';
                document.body.style.overflow = '';
                return removed;
            JS);
                if (is_numeric($removed) && $removed > 0) {
                    usleep(150_000);
                    $closedSomething = true;
                }
            } catch (\Throwable $e) {
            }

            try {
                $hasBackdrop = $d->executeScript('return !!document.querySelector(".unf-overlay, .unf-modal, .unf-dialog, [role=\'dialog\']");');
                if (!$hasBackdrop)
                    break;
            } catch (\Throwable $e) {
                break;
            }

            if (!$closedSomething)
                usleep(150_000);
        } while (microtime(true) < $end);
    }

    private function firstEl(string $kind, string $sel): ?RemoteWebElement
    {
        try {
            if ($kind === 'css') {
                $els = $this->driver->findElements(By::cssSelector($sel));
            } else {
                $els = $this->driver->findElements(By::xpath($sel));
            }
            return $els[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getVisibleText(RemoteWebElement $el): string
    {
        try {
            $txt = trim($el->getText());
            if ($txt !== '')
                return $txt;
        } catch (\Throwable $e) {
        }

        try {
            $inner = $el->getAttribute('innerText');
            if (is_string($inner) && trim($inner) !== '')
                return trim($inner);
        } catch (\Throwable $e) {
        }

        try {
            $tc = $el->getAttribute('textContent');
            if (is_string($tc) && trim($tc) !== '')
                return trim($tc);
        } catch (\Throwable $e) {
        }

        return '';
    }

    private function textByJsFirst(array $cssSelectors): string
    {
        foreach ($cssSelectors as $css) {
            try {
                $txt = $this->driver->executeScript(
                    'const el=document.querySelector(arguments[0]);
                 if(!el) return "";
                 if(el.closest("#pdpFloatingActions")) return "";
                 return (el.innerText||el.textContent||"").trim();',
                    [$css]
                );
                if (is_string($txt) && $txt !== '')
                    return $txt;
            } catch (\Throwable $e) {
            }
        }
        return '';
    }

    private function collectAllRpIn(RemoteWebElement $container): array
    {
        $vals = [];
        try {
            $txt = $container->getAttribute('innerText') ?? '';
            if ($txt !== '') {
                if (preg_match_all('/Rp\s*([0-9\.\,]+)/u', $txt, $m)) {
                    foreach ($m[1] as $raw) {
                        $n = (int) preg_replace('/[^0-9]/', '', $raw);
                        if ($n > 0)
                            $vals[] = $n;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return array_values(array_unique($vals));
    }

    private function navWithRetry(RemoteWebDriver $d, string $url, int $tries = 2): void
    {
        for ($i = 0; $i < $tries; $i++) {
            try {
                $d->get($url);
                return;
            } catch (\Throwable $e) {
                usleep(800_000);
                if ($i === $tries - 1)
                    throw $e;
            }
        }
    }

    private function tryCloseModals(): void
    {
        $d = $this->driver;
        foreach ([
            'button[aria-label="Tutup"]',
            '[data-testid="btnHeaderClose"]',
            'button[aria-label="Close"]',
            '.css-1kx77i2-unf-btn',
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

    private function waitAny(RemoteWebDriver $d, array $selectors, int $ms = 10000): ?RemoteWebElement
    {
        $end = microtime(true) + ($ms / 1000);
        do {
            foreach ($selectors as $sel) {
                try {
                    if (str_starts_with($sel, 'css=')) {
                        $css = substr($sel, 4);
                        $els = $d->findElements(By::cssSelector($css));
                    } else {
                        $xp = substr($sel, 5);
                        $els = $d->findElements(By::xpath($xp));
                    }
                    if (!empty($els))
                        return $els[0];
                } catch (\Throwable $e) {
                }
            }
            usleep(200_000);
        } while (microtime(true) < $end);
        return null;
    }

    private function isCaptchaOrDenied(): bool
    {
        try {
            $html = strtolower($this->driver->getPageSource());
            return str_contains($html, 'captcha') ||
                str_contains($html, 'access denied') ||
                str_contains($html, 'verify you are human');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function debugSnapshot(string $prefix = 'tp_pdp'): void
    {
        try {
            $ts = date('Ymd_His');
            @mkdir(storage_path('logs/scraper'), 0777, true);
            $this->driver->takeScreenshot(storage_path("logs/scraper/{$prefix}_{$ts}.png"));
            file_put_contents(storage_path("logs/scraper/{$prefix}_{$ts}.html"), $this->driver->getPageSource());
        } catch (\Throwable $e) {
        }
    }

    private function pickMinRupiah(?string $s): ?int
    {
        if (!$s)
            return null;
        if (!preg_match_all('/Rp\s*([0-9\.\,]+)/u', $s, $m))
            return null;
        $vals = [];
        foreach ($m[1] as $raw) {
            $n = (int) preg_replace('/[^0-9]/', '', $raw);
            if ($n > 0)
                $vals[] = $n;
        }
        if (!$vals)
            return null;
        return min($vals);
    }

    private function fmtRp(?int $v): ?string
    {
        if ($v === null)
            return null;
        return 'Rp' . number_format($v, 0, ',', '.');
    }
}