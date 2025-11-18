<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('nama_barang')->nullable();
            $table->string('brand')->nullable();
            $table->string('keyword')->index();
            $table->enum('status', ['queue', 'pending', 'process', 'finished', 'failed'])->default('queue');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('import_items');
    }
};