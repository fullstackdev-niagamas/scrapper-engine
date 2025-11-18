<?php
namespace App\Http\Controllers;
use App\Models\ImportBatch;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index()
    {
        $batches = ImportBatch::latest()->get();
        return view('batches.index', compact('batches'));
    }
    public function show(ImportBatch $batch)
    {
        $items = $batch->items()->with('results')->get();
        return view('batches.show', compact('batch', 'items'));
    }
    public function keywords(ImportBatch $batch)
    {
        $keywords = $batch->items()->pluck('keyword', 'sku');
        return view('batches.keywords', compact('batch', 'keywords'));
    }

    public function refreshPrices(Request $request, Batch $batch)
    {
        // Ambil semua result id milik batch ini
        $resultIds = BatchItemResult::query()
            ->whereIn('batch_item_id', $batch->items()->pluck('id'))
            ->pluck('id');

        // Set status=refresh secara massal
        DB::table('batch_item_results')
            ->whereIn('id', $resultIds)
            ->update(['status' => 'refresh', 'updated_at' => now()]);

        // Dispatch job untuk masing-masing result
        foreach ($resultIds as $rid) {
            UpdateResultPriceJob::dispatch($rid)->onQueue('scraper'); // optional: atur queue name
        }

        return response()->json(['ok' => true, 'count' => count($resultIds)]);
    }

    public function pollStatus(Request $request, Batch $batch)
    {
        // Kembalikan status per item: kalau ADA salah satu result status=refresh, baris dianggap refreshing
        $items = $batch->items()
            ->with(['results' => function ($q) {
                $q->orderBy('rank'); }])
            ->get()
            ->map(function ($item) {
                $anyRefreshing = $item->results->contains(fn($r) => $r->status === 'refresh');
                $anyError = $item->results->contains(fn($r) => $r->status === 'error');

                // lastUpdate = updated_at terbaru dari 3 results
                $lastUpdate = optional($item->results->max('updated_at'))
                        ?->format('Y-m-d H:i:s');

                // siapkan snapshot nilai sel untuk Data1..Data3
                $snap = [];
                foreach ([1, 2, 3] as $i) {
                    $r = $item->results->sortBy('rank')->values()->get($i - 1);
                    $snap[$i] = [
                        'price_discount' => $r->price_discount ?? '',
                        'price_regular' => $r->price_regular ?? '',
                        'status' => $r->status ?? 'idle',
                        'updated_at' => optional($r->updated_at)?->format('Y-m-d H:i:s'),
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

        // Hitung global: kalau semua baris tidak refreshing, berarti selesai
        $allFinished = $items->every(fn($x) => $x['refreshing'] === false);

        return response()->json([
            'all_finished' => $allFinished,
            'items' => $items,
        ]);
    }
}