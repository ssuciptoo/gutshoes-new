<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSizeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Size::withCount(['variants as product_variants_count'])->orderBy('system')->orderByRaw('sort_value is null')->orderBy('sort_value')->orderBy('value')->get()]);
    }

    public function store(Request $r): JsonResponse
    {
        return response()->json(['data' => Size::create($this->data($r))], 201);
    }

    public function update(Request $r, Size $size): JsonResponse
    {
        $size->update($this->data($r, $size));

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

        $size->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string,mixed> */
    private function data(Request $r, ?Size $size = null): array
    {
        $data = $r->validate([
            'system' => ['required', 'string', 'max:16'],
            'value' => ['required', 'string', 'max:16', 'unique:sizes,value,'.($size instanceof Size ? $size->id : 'NULL').',id,system,'.$r->input('system')],
            'label' => ['required', 'string', 'max:32'],
            'foot_length_cm' => ['nullable', 'numeric', 'between:8,40', 'decimal:0,1'],
            'sort_value' => ['nullable', 'numeric', 'between:0,9999'],
        ]);
        // Nilai urut kosong diisi otomatis dari nilai ukuran (41 1/3 → 41.33).
        $data['sort_value'] = isset($data['sort_value']) ? round((float) $data['sort_value'], 2) : Size::sortValueFrom($data['value']);

        return $data;
    }
}
