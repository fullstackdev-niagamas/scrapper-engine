@extends('layouts.app')
@section('content')
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold">Batch #{{ $batch->id }}</h1>
                <p class="text-sm text-gray-600">File: {{ $batch->filename }} | Status:
                    <b>{{ strtoupper($batch->status) }}</b>
                </p>
                <div class="w-96 bg-gray-200 rounded h-3 mt-2">
                    <div class="bg-blue-600 h-3 rounded" style="width: {{ $batch->progressPercent() }}%"></div>
                </div>
                <p class="text-sm mt-1">{{ $batch->finished_rows }} dari {{ $batch->total_rows }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('batches.keywords', $batch) }}" class="px-3 py-2 bg-gray-700 text-white rounded">Preview Keywords</a>
                <a href="{{ route('batches.export.csv', $batch) }}" class="px-3 py-2 bg-emerald-600 text-white rounded">Export CSV</a>
                <button id="btn-refresh-harga" class="px-3 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">
                    Refresh Harga
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table id="batch-table" class="w-full text-sm border min-w-[1400px]">
                <thead>
                    <tr>
                        <th rowspan="2" class="p-2 bg-gray-100 text-center align-middle">SKU</th>
                        <th rowspan="2" class="p-2 bg-gray-100 text-center align-middle">Keyword Name</th>
                        <th rowspan="2" class="p-2 bg-gray-100 text-center align-middle">Keyword Brand</th>
                        <th colspan="7" class="p-2 bg-blue-100 text-center">Data 1</th>
                        <th colspan="7" class="p-2 bg-green-100 text-center">Data 2</th>
                        <th colspan="7" class="p-2 bg-yellow-100 text-center">Data 3</th>
                        <th rowspan="2" class="p-2 bg-gray-100 text-center align-middle">Status</th>
                        <th rowspan="2" class="p-2 bg-gray-100 text-center align-middle">Last Price Update</th>
                    </tr>
                    <tr class="bg-gray-50">
                        @foreach(['1','2','3'] as $i)
                            <th class="p-2 text-center">URL</th>
                            <th class="p-2 text-center">Nama Toko</th>
                            <th class="p-2 text-center">Harga Diskon</th>
                            <th class="p-2 text-center">Harga Reguler</th>
                            <th class="p-2 text-center">Sold</th>
                            <th class="p-2 text-center">Kota</th>
                            <th class="p-2 text-center">Provinsi</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $it)
                        @php
                            $grouped = $it->results->sortBy('rank')->values();
                            $name = $it->nama_barang;
                            $brand = $it->brand;

                            $rowRefreshing = $it->results->contains(fn($r) => $r->status === 'refresh');
                            $rowError      = $it->results->contains(fn($r) => $r->status === 'error');

                            $lastPriceUpdateCarbon = $it->results
                                ->map(fn($r) => $r->price_updated_at ?? $r->updated_at)
                                ->filter()
                                ->max();

                            $lastPriceUpdate = optional($lastPriceUpdateCarbon)?->format('Y-m-d H:i:s');
                        @endphp

                        <tr class="border-t align-top" data-item-id="{{ $it->id }}">
                            <td class="p-2">{{ $it->sku }}</td>
                            <td class="p-2">{{ $name }}</td>
                            <td class="p-2">{{ $brand }}</td>

                            @for($i = 1; $i <= 3; $i++)
                                @php $r = $grouped->get($i - 1); @endphp
                                <td class="p-2">
                                    @if($r && $r->url)
                                        <a class="text-blue-600 underline" target="_blank" href="{{ $r->url }}">Link</a>
                                    @endif
                                </td>
                                <td class="p-2">{{ $r->store_name ?? '' }}</td>

                                <td class="p-2" id="cell-{{ $it->id }}-{{ $i }}-price_discount">{{ $r->price_discount ?? '' }}</td>
                                <td class="p-2" id="cell-{{ $it->id }}-{{ $i }}-price_regular">{{ $r->price_regular ?? '' }}</td>

                                <td class="p-2">{{ $r->sold ?? '' }}</td>
                                <td class="p-2">{{ $r->kota ?? '' }}</td>
                                <td class="p-2">{{ $r->provinsi ?? '' }}</td>
                            @endfor

                            <td class="p-2 text-center" id="row-status-{{ $it->id }}">
                                @if($rowRefreshing)
                                    <span class="inline-flex items-center gap-2 text-indigo-700">
                                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4A4 4 0 008 12H4z"></path>
                                        </svg>
                                        Refreshing…
                                    </span>
                                @elseif($rowError)
                                    <span class="text-red-600">Error</span>
                                @else
                                    <span class="text-gray-700">Finish</span>
                                @endif
                            </td>

                            <td class="p-2 text-center" id="row-lastupdate-{{ $it->id }}">
                                {{ $lastPriceUpdate ?? '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vanilla-datatables@latest/dist/vanilla-dataTables.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/vanilla-datatables@latest/dist/vanilla-dataTables.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            new DataTable("#batch-table", { perPage: 10, fixedHeight: true, searchable: true });

            const btn = document.getElementById('btn-refresh-harga');
            const pollUrl = @json(route('batches.poll.status', $batch));
            const postUrl = @json(route('batches.refresh.prices', $batch));
            let pollTimer = null;

            function setRowStatus(itemId, payload) {
                const { refreshing, has_error, last_price_update, last_update, cells } = payload;

                const statusTd = document.getElementById(`row-status-${itemId}`);
                if (statusTd) {
                    if (has_error) {
                        statusTd.innerHTML = '<span class="text-red-600">Error</span>';
                    } else if (refreshing) {
                        statusTd.innerHTML = `
                          <span class="inline-flex items-center gap-2 text-indigo-700">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4A4 4 0 008 12H4z"></path>
                            </svg>
                            Refreshing…
                          </span>`;
                    } else {
                        statusTd.innerHTML = '<span class="text-gray-700">Finish</span>';
                    }
                }

                const luTd = document.getElementById(`row-lastupdate-${itemId}`);
                if (luTd) luTd.textContent = (last_price_update ?? last_update ?? '-');

                [1,2,3].forEach(i => {
                    const c = (cells && cells[i]) || {};
                    const dCell = document.getElementById(`cell-${itemId}-${i}-price_discount`);
                    const rCell = document.getElementById(`cell-${itemId}-${i}-price_regular`);
                    if (dCell && 'price_discount' in c) dCell.textContent = c.price_discount || '';
                    if (rCell && 'price_regular'  in c) rCell.textContent  = c.price_regular  || '';
                });
            }

            function startPolling() {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(async () => {
                    try {
                        const res = await fetch(pollUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }});
                        const json = await res.json();

                        (json.items || []).forEach(row => setRowStatus(row.item_id, row));
                        if (json.all_finished) { clearInterval(pollTimer); pollTimer = null; }
                    } catch (e) {
                        console.error('Polling error', e);
                    }
                }, 2000);
            }

            btn.addEventListener('click', async function () {
                btn.disabled = true;
                btn.classList.add('opacity-60', 'cursor-not-allowed');
                try {
                    const res = await fetch(postUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({})
                    });
                    await res.json();
                    startPolling();
                } catch (e) {
                    console.error(e);
                }
            });
        });
    </script>
@endsection
