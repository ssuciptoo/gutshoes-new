<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Midtrans mengikat satu order_id ke metode pembayaran pertama yang dipilih. Agar pembeli bisa
 * mengganti metode, setiap percobaan memakai order_id Midtrans sendiri; percobaan lama disimpan
 * supaya webhook terlambat untuk order_id tersebut tetap bisa dikenali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('provider_order_id', 64)->nullable()->unique();
            $table->unsignedSmallInteger('attempt')->default(1);
        });
        DB::table('payments')->whereNull('provider_order_id')->update([
            'provider_order_id' => DB::raw('(SELECT order_number FROM orders WHERE orders.id = payments.order_id)'),
        ]);
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider_order_id', 64)->unique();
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->timestamp('superseded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['provider_order_id']);
            $table->dropColumn(['provider_order_id', 'attempt']);
        });
    }
};
