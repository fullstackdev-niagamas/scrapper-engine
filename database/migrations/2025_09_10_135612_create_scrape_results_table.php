<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scrape_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_item_id')->constrained()->cascadeOnDelete();
            $table->enum('marketplace', ['tokopedia', 'shopee', 'tiktok', 'lazada']);
            $table->unsignedTinyInteger('rank');
            $table->text('url')->nullable();
            $table->string('store_name')->nullable();
            $table->string('price_discount')->nullable();
            $table->string('price_regular')->nullable();
            $table->string('sold')->nullable();
            $table->string('kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
            $table->unique(['import_item_id', 'marketplace', 'rank']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('scrape_results');
    }
};