<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Template latar untuk foto produk transparan: satu default global, boleh di-override per kategori.
 * Gambar lama tetap use_template=false sehingga tampilannya tidak berubah saat deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_backdrops', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 16)->default('solid');
            $table->string('color_from', 7);
            $table->string('color_to', 7)->nullable();
            $table->unsignedSmallInteger('angle')->default(180);
            $table->string('image_path')->nullable();
            $table->string('image_fit', 16)->default('cover');
            $table->boolean('shadow')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('image_backdrop_id')->nullable()->after('description')->constrained('image_backdrops')->nullOnDelete();
        });
        Schema::table('product_images', function (Blueprint $table) {
            $table->boolean('has_transparency')->default(false)->after('is_primary');
            $table->boolean('use_template')->default(false)->after('has_transparency');
        });

        DB::table('image_backdrops')->insert([
            'name' => 'Abu terang', 'type' => 'solid', 'color_from' => '#f7f8f8', 'angle' => 180,
            'image_fit' => 'cover', 'shadow' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['has_transparency', 'use_template']);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_backdrop_id');
        });
        Schema::dropIfExists('image_backdrops');
    }
};
