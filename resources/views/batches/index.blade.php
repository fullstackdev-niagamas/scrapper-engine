@extends('layouts.app')
@section('content')
    <div class="p-6">
        <div class="flex items-center mb-4">
            <h1 class="text-xl font-bold">Daftar Proses Scraping</h1>
            <a href="{{ route('import.create') }}" class="px-3 ml-4 py-2 bg-emerald-600 text-white rounded">Mulai
                Scraping</a>
        </div>

        <div class="mb-4">
            <label for="filter-source" class="mr-2 font-semibold">Filter Sumber:</label>
            <select id="filter-source" class="border rounded px-2 py-1">
                <option value="">Semua</option>
                <option value="Shopee">Shopee</option>
                <option value="Lazada">Lazada</option>
                <option value="Tokopedia">Tokopedia</option>
            </select>
        </div>

        <table id="list-batch-table" class="w-full border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-2">Batch</th>
                    <th class="p-2">Nama File</th>
                    <th class="p-2">Sumber</th>
                    <th class="p-2">Tanggal</th>
                    <th class="p-2">Status</th>
                    <th class="p-2">Progress</th>
                    <th class="p-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($batches as $b)
                    <tr class="border-t">
                        <td class="p-2">#{{ $b->id }}</td>
                        <td class="p-2">{{ $b->filename }}</td>
                        <td class="p-2">
                            @php
                                $source = strtolower($b->source_tabs);
                                $icons = [
                                    'tokopedia' => asset('tokopedia.png'),
                                    'shopee' => asset('shopee.png'),
                                    'lazada' => asset('lazada.png'),
                                ];
                                $icon = $icons[$source] ?? null;
                            @endphp

                            @if ($icon)
                                <div class="flex items-center gap-2">
                                    <img src="{{ $icon }}" alt="{{ ucfirst($source) }}" class="w-5 h-5">
                                    <span class="capitalize">{{ $source }}</span>
                                </div>
                            @else
                                <span class="capitalize">{{ $source }}</span>
                            @endif
                        </td>
                        <td class="p-2">{{ $b->created_at }}</td>
                        <td class="p-2">
                            @php
                                $status = strtoupper($b->status);
                                $colors = [
                                    'FINISHED' => 'bg-green-100 text-green-700',
                                    'FAILED' => 'bg-red-100 text-red-700',
                                    'PROCESS' => 'bg-yellow-100 text-yellow-700',
                                    'QUEUE' => 'bg-gray-100 text-gray-700',
                                ];
                                $color = $colors[$status] ?? 'bg-gray-100 text-gray-700';
                            @endphp
                            <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $color }}">
                                {{ $status }}
                            </span>
                        </td>
                        <td class="p-2">
                            <div class="flex">
                                <span class="text-sm font-semibold text-slate-800">
                                    {{ $b->finished_rows }} <span class="text-slate-400">/ {{ $b->total_rows }}</span>
                                </span>
                                <span class="ml-2 text-sm font-medium text-emerald-600 tracking-wide">
                                    - {{ $b->progressPercent() }}%
                                </span>
                            </div>
                        </td>
                        <td class="p-2"><a class="text-blue-600 underline" href="{{ route('batches.show', $b) }}">Detail</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vanilla-datatables@latest/dist/vanilla-dataTables.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/vanilla-datatables@latest/dist/vanilla-dataTables.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = new DataTable("#list-batch-table", {
                perPage: 10,
                fixedHeight: true,
                searchable: true
            });

            const filter = document.getElementById('filter-source');
            filter.addEventListener('change', function () {
                const val = this.value;
                if (val) {
                    table.search(val, 2);
                } else {
                    table.search('', 2);
                }
            });
        });
    </script>
@endsection