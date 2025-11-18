<?php
namespace App\Services\Scrapers;

interface MarketplaceScraper
{
    /** @return array<int,array{url?:string,store_name?:string,price_discount?:string,price_regular?:string,sold?:string,kota?:string,provinsi?:string,image?:string}> */
    public function searchTop(string $keyword, int $limit = 3): array;
}