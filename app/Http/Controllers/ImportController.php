<?php
namespace App\Http\Controllers;

use App\Http\Requests\ImportExcelRequest;
use App\Jobs\StartScrapeBatchJob;
use App\Jobs\UpdateResultPriceJob;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\ScrapeResult;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Facades\Excel;
use Request;

class ImportController extends Controller
{
    public function create()
    {
        return view('import.create');
    }

    public function store(ImportExcelRequest $req)
    {
        $file = $req->file('file');

        $batch = ImportBatch::create([
            'filename' => $file->getClientOriginalName(),
            'status' => 'pending',
            'source_tabs' => $req->input('source'),
        ]);

        $path = $file->storeAs('imports', $batch->id . '_' . $file->getClientOriginalName());

        // Validate headings
        $headings = (new HeadingRowImport)->toArray($file)[0][0] ?? [];
        $expected = ['sku', 'nama_barang', 'brand'];
        $headingsLower = array_map('strtolower', $headings);
        if (array_slice($headingsLower, 0, 3) !== $expected) {
            $batch->update(['status' => 'failed', 'error_message' => 'Header tidak sesuai. Harus: SKU, Nama Barang, Brand']);
            return back()->withErrors('Header tidak sesuai.');
        }

        $rows = Excel::toCollection(null, $file)[0];
        DB::transaction(function () use ($rows, $batch) {
            $count = 0;
            foreach ($rows->skip(1) as $row) { // skip header
                $sku = trim((string) ($row[0] ?? ''));
                $nama = trim((string) ($row[1] ?? ''));
                $brand = trim((string) ($row[2] ?? ''));
                if ($nama === '' && $brand === '')
                    continue;
                $keyword = trim($nama . ' ' . $brand);
                ImportItem::create([
                    'import_batch_id' => $batch->id,
                    'sku' => $sku,
                    'nama_barang' => $nama,
                    'brand' => $brand,
                    'keyword' => $keyword,
                    'status' => 'queue'
                ]);
                $count++;
            }
            $batch->update(['total_rows' => $count, 'status' => 'queue']);
        });

        StartScrapeBatchJob::dispatch($batch->id)->onQueue('scraping');

        return redirect()->route('batches.index');
    }

    public function refreshPrices(Request $request, ImportBatch $batch)
    {
        // Ambil semua ScrapeResult milik items di batch ini
        $resultIds = ScrapeResult::query()
            ->whereIn('import_item_id', $batch->items()->pluck('id'))
            ->pluck('id');

        // Set status=refresh massal agar semua baris muncul spinner
        DB::table('scrape_results')
            ->whereIn('id', $resultIds)
            ->update(['status' => 'refresh', 'updated_at' => now()]);

        // Dispatch job per ScrapeResult
        foreach ($resultIds as $rid) {
            UpdateResultPriceJob::dispatch($rid)->onQueue('scraping'); // opsional
        }

        return response()->json(['ok' => true, 'count' => $resultIds->count()]);
    }

    public function pollStatus(Request $request, ImportBatch $batch)
    {
        // Ambil items lengkap dgn 3 results teratas (urut rank)
        $items = $batch->items()
            ->with(['results' => function ($q) {
                $q->orderBy('rank'); }])
            ->get()
            ->map(function ($item) {
                $anyRefreshing = $item->results->contains(fn($r) => $r->status === 'refresh');
                $anyError = $item->results->contains(fn($r) => $r->status === 'error');

                // updated_at terbaru dari result-result item tsb
                $lastUpdate = optional($item->results->max('updated_at'))?->format('Y-m-d H:i:s');

                // kirim snapshot cell harga untuk Data1..Data3
                $snap = [];
                $sorted = $item->results->sortBy('rank')->values();
                foreach ([1, 2, 3] as $i) {
                    $r = $sorted->get($i - 1);
                    $snap[$i] = [
                        'price_discount' => $r->price_discount ?? '',
                        'price_regular' => $r->price_regular ?? '',
                        'status' => $r->status ?? 'idle',
                        'updated_at' => optional($r?->updated_at)?->format('Y-m-d H:i:s'),
                    ];
                }

                return [
                    'item_id' => $item->id,
                    'refreshing' => $anyRefreshing,
                    'has_error' => $anyError,
                    'last_update' => $lastUpdate,
                    'cells' => $snap,
                ];
            });

        $allFinished = $items->every(fn($x) => $x['refreshing'] === false);

        return response()->json([
            'all_finished' => $allFinished,
            'items' => $items,
        ]);
    }
}