<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminSizeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Size::withCount(['variants as product_variants_count'])->orderBy('system')->orderByRaw('sort_value is null')->orderBy('sort_value')->orderBy('value')->get()]);
    }

    public function store(Request $r): JsonResponse
    {
        $data = $this->data($r);
        $size = DB::transaction(function () use ($data): Size {
            $this->purgeDeleted($data['system'], $data['value']);

            return Size::create($data);
        });

        return response()->json(['data' => $size], 201);
    }

    public function update(Request $r, Size $size): JsonResponse
    {
        $data = $this->data($r, $size);
        DB::transaction(function () use ($size, $data): void {
            $this->purgeDeleted($data['system'], $data['value'], $size->id);
            $size->update($data);
        });

        return response()->json(['data' => $size->refresh()]);
    }

    public function destroy(Size $size): JsonResponse
    {
        if (ProductVariant::withTrashed()->where('size_id', $size->id)->exists()) {
            return response()->json([
                'message' => 'Ukuran masih digunakan oleh varian produk.',
                'errors' => ['size' => ['Nonaktifkan atau hapus semua varian yang menggunakan ukuran ini terlebih dahulu.']],
            ], 422);
        }

        // Ukuran yang dihapus tidak pernah dipakai varian (dicek di atas), jadi dihapus permanen agar
        // nilainya bisa dipakai lagi; soft delete hanya akan mengunci indeks unik (system, value).
        $size->forceDelete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Baris lama yang ter-soft-delete (sebelum hapus permanen diterapkan) masih memegang indeks unik.
     * Baris itu dijamin tidak dipakai varian, jadi aman dihapus permanen sebelum nilainya dipakai lagi.
     */
    private function purgeDeleted(string $system, string $value, ?int $exceptId = null): void
    {
        Size::onlyTrashed()->where('system', $system)->where('value', $value)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereNotIn('id', ProductVariant::withTrashed()->select('size_id'))
            ->get()->each->forceDelete();
    }

    /** @return array<string,mixed> */
    private function data(Request $r, ?Size $size = null): array
    {
        $data = $r->validate([
            'system' => ['required', 'string', 'max:16'],
            'value' => ['required', 'string', 'max:16', Rule::unique('sizes', 'value')->where('system', (string) $r->input('system'))
                ->whereNull('deleted_at')->ignore($size?->id)],
            'label' => ['required', 'string', 'max:32'],
            'foot_length_cm' => ['nullable', 'numeric', 'between:8,40', 'decimal:0,1'],
            'sort_value' => ['nullable', 'numeric', 'between:0,9999'],
        ]);
        // Nilai urut kosong diisi otomatis dari nilai ukuran (41 1/3 → 41.33).
        $data['sort_value'] = isset($data['sort_value']) ? round((float) $data['sort_value'], 2) : Size::sortValueFrom($data['value']);

        return $data;
    }
}
