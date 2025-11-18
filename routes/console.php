<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Facebook\WebDriver\Remote\RemoteWebDriver;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('shopee:warmup {--minutes=5}', function () {
    /** @var RemoteWebDriver $driver */
    $driver = app(RemoteWebDriver::class);

    $this->info('Buka https://shopee.co.id — silakan LOGIN manual (QR/Email/Google).');
    $driver->get('https://shopee.co.id/');
    $until = time() + ((int)$this->option('minutes') * 60);

    while (time() < $until) {
        try {
            $cookies = $driver->manage()->getCookies();
            $hasSess = collect($cookies)->contains(fn($c) => in_array($c['name'], [
                'SPC_R_T_ID','SPC_T_ID','SPC_SI','csrftoken'
            ]));

            if ($hasSess) {
                $this->info('Login terdeteksi. Cookies tersimpan di profil Chrome. Done!');
                return;
            }
        } catch (\Throwable $e) {
            // abaikan
        }
        sleep(3);
    }

    $this->warn('Belum terdeteksi login. Jalankan ulang perintah ini bila perlu.');
});
