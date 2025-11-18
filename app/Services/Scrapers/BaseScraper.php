<?php
namespace App\Services\Scrapers;

use Facebook\WebDriver\Remote\RemoteWebDriver;

abstract class BaseScraper
{
    public function __construct(protected ?RemoteWebDriver $driver = null)
    {
    }

    protected function driver(): RemoteWebDriver
    {
        if (!$this->driver) {
            $this->driver = app(RemoteWebDriver::class);
        }
        return $this->driver;
    }

    protected function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }

    protected function safeText($el): string
    {
        return trim((string) ($el?->getText() ?? ''));
    }
}