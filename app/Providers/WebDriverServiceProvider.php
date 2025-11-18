<?php

namespace App\Providers;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class WebDriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // === WAJIB: pakai bind, bukan singleton ===
        $this->app->bind(RemoteWebDriver::class, function () {

            // ====== Konfigurasi dari .env ======
            $host  = env('WEBDRIVER_HOST', 'http://127.0.0.1:9515'); 
            $bin   = env('CHROME_BINARY', '/usr/bin/google-chrome'); 
            $pls   = env('WEBDRIVER_PAGELOAD_STRATEGY', 'eager');

            // === Cek binary chrome ===
            if (!file_exists($bin)) {
                throw new \Exception("Chrome binary not found at: $bin");
            }

            $connTimeoutMs = (int) env('WEBDRIVER_CONNECTION_TIMEOUT', 60000);
            $reqTimeoutMs  = (int) env('WEBDRIVER_REQUEST_TIMEOUT',   120000);

            $pageLoadSec   = (int) env('WEBDRIVER_PAGELOAD_SECONDS', 60);
            $implicitSec   = (int) env('WEBDRIVER_IMPLICIT_SECONDS', 5);
            $scriptSec     = (int) env('WEBDRIVER_SCRIPT_SECONDS',   30);

            // ====== Chrome Options ======
            $options = new ChromeOptions();
            $options->addArguments([
                '--headless=new',
                '--window-size=1366,768',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--lang=id-ID',
                '--disable-blink-features=AutomationControlled',
                '--user-agent=Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140 Safari/537.36',
            ]);

            $options->setBinary($bin);

            // ====== Capabilities ======
            $caps = DesiredCapabilities::chrome();
            $caps->setCapability(ChromeOptions::CAPABILITY, $options);
            $caps->setCapability('pageLoadStrategy', $pls);
            $caps->setCapability('acceptInsecureCerts', true);

            // ====== Create driver ======
            $driver = RemoteWebDriver::create($host, $caps, $connTimeoutMs, $reqTimeoutMs);

            // ====== Timeouts ======
            $driver->manage()->timeouts()
                ->pageLoadTimeout($pageLoadSec)
                ->implicitlyWait($implicitSec)
                ->setScriptTimeout($scriptSec);

            // ==== Coba ping session — mencegah INVALID SESSION ID ====
            try {
                $driver->getWindowHandles();
            } catch (\Throwable $e) {
                throw new \Exception("Unable to start Chrome session: " . $e->getMessage());
            }

            return $driver;
        });
    }
}
