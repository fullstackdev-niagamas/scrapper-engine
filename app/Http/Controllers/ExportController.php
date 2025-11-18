<?php
namespace App\Http\Controllers;
use App\Models\ImportBatch;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function csv(ImportBatch $batch): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="scrape_batch_' . $batch->id . '.csv"',
        ];
        $columns = [
            'SKU',
            'Keyword Name',
            'Keyword Brand',
        ];
        for ($i = 1; $i <= 3; $i++) {
            array_push($columns, ...[
                "URL $i",
                "Nama Toko $i",
                "Harga Diskon $i",
                "Harga Reguler $i",
                "Sold $i",
                "Kota $i"
            ]);
        }


        return response()->stream(function () use ($batch, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);
            $batch->load(['items.results']);
            foreach ($batch->items as $it) {
                $row = [$it->sku, $it->nama_barang, $it->brand];
                $res = $it->results->sortBy('rank')->values();
                for ($i = 0; $i < 3; $i++) {
                    $r = $res->get($i);
                    $row[] = $r->url ?? '';
                    $row[] = $r->store_name ?? '';
                    $row[] = $r->price_discount ?? '';
                    $row[] = $r->price_regular ?? '';
                    $row[] = $r->sold ?? '';
                    $row[] = $r->kota ?? '';
                }
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 200, $headers);
    }
}