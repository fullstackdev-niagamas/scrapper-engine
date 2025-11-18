@extends('layouts.app')

@section('content')
    <div class="p-6">
        <h1 class="text-xl font-bold mb-4">Upload Excel untuk Scraping</h1>

        @if ($errors->any())
            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded">
                <ul class="list-disc list-inside text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('import.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="block font-semibold mb-1">File Excel</label>
                <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                    class="block w-full border border-gray-300 rounded p-2">
                <p class="text-xs text-gray-500 mt-1">Format header harus: <b>SKU, Nama Barang, Brand</b> (A1–C1)</p>

                <a href="{{ asset('template_scraping.xlsx') }}" download
                    class="text-blue-600 underline text-sm mt-2 inline-block">
                    Download template
                </a>
            </div>

            <div>
                <label class="block font-semibold mb-1">Pilih E-commerce</label>
                <div class="space-y-1">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="source" value="tokopedia" required>
                        <span>Tokopedia</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="source" value="shopee" disabled>
                        <span>Shopee</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="source" value="lazada">
                        <span>Lazada</span>
                    </label>
                </div>
            </div>

            <div>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Mulai Scraping
                </button>
            </div>
        </form>
    </div>
@endsection