<?php
namespace App\Services\Scrapers;

use Facebook\WebDriver\Remote\RemoteWebDriver;

abstract class BaseScraper
{
    public function __construct(protected RemoteWebDriver $driver)
    {
    }

    protected function driver(): RemoteWebDriver
    {
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