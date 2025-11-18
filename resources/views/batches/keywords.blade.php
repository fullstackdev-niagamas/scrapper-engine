@extends('layouts.app')
@section('content')
    <div class="p-6">
        <h1 class="text-xl font-bold mb-3">Keywords — Batch #{{ $batch->id }}</h1>
        <table id="keyword-table" class="w-full text-sm border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-2">SKU</th>
                    <th class="p-2">Keyword</th>
                </tr>
            </thead>
            <tbody>
                @foreach($keywords as $sku => $kw)
                    <tr class="border-t">
                        <td class="p-2">{{ $sku }}</td>
                        <td class="p-2">{{ $kw }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/vanilla-datatables@latest/dist/vanilla-dataTables.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/vanilla-datatables@latest/dist/vanilla-dataTables.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            new DataTable("#keyword-table", {
                perPage: 10,
                fixedHeight: true,
                searchable: true
            });
        });
    </script>
@endsection