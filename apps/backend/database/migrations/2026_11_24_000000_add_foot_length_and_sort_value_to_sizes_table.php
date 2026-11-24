<?php

use App\Models\Size;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sizes', function (Blueprint $table): void {
            // Panjang kaki dalam cm: acuan yang sama untuk semua merek (42 di satu merek ≠ 42 di merek lain).
            $table->decimal('foot_length_cm', 4, 1)->nullable()->after('label');
            // Nilai urut numerik agar 41 1/3 berada di antara 41 dan 42, bukan diurutkan sebagai teks.
            $table->decimal('sort_value', 6, 2)->nullable()->after('foot_length_cm');
        });

        DB::table('sizes')->select(['id', 'value'])->orderBy('id')->each(function (object $size): void {
            DB::table('sizes')->where('id', $size->id)->update(['sort_value' => Size::sortValueFrom((string) $size->value)]);
        });
    }

    public function down(): void
    {
        Schema::table('sizes', function (Blueprint $table): void {
            $table->dropColumn(['foot_length_cm', 'sort_value']);
        });
    }
};
