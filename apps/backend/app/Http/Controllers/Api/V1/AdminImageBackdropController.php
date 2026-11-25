<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\BackdropResolver;
use App\Domain\Catalog\ImageSanitizer;
use App\Http\Controllers\Controller;
use App\Models\ImageBackdrop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Template latar foto produk (Pengaturan → Tampilan gambar). */
class AdminImageBackdropController extends Controller
{
    private const HEX = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function __construct(private readonly BackdropResolver $backdrops) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => ImageBackdrop::withCount('categories')->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $backdrop = DB::transaction(fn () => $this->save(new ImageBackdrop, $this->validated($request)));

        return response()->json(['data' => $backdrop], 201);
    }

    public function update(Request $request, ImageBackdrop $imageBackdrop): JsonResponse
    {
        $backdrop = DB::transaction(fn () => $this->save($imageBackdrop, $this->validated($request)));

        return response()->json(['data' => $backdrop]);
    }

    public function destroy(ImageBackdrop $imageBackdrop): JsonResponse
    {
        abort_if($imageBackdrop->is_default, 422, 'Template default tidak dapat dihapus. Jadikan template lain default terlebih dahulu.');
        if ($imageBackdrop->image_path) {
            Storage::disk('public')->delete($imageBackdrop->image_path);
        }
        $imageBackdrop->delete();
        $this->backdrops->forget();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function uploadImage(Request $request, ImageBackdrop $imageBackdrop, ImageSanitizer $images): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=450,max_width=4000,max_height=4000']]);
        $old = $imageBackdrop->image_path;
        $imageBackdrop->update(['image_path' => $images->store($data['image'], "backdrops/{$imageBackdrop->id}")]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        $this->backdrops->forget();

        return response()->json(['data' => $imageBackdrop->loadCount('categories')]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:solid,gradient,image'],
            'color_from' => ['required', self::HEX],
            'color_to' => ['nullable', 'required_if:type,gradient', self::HEX],
            'angle' => ['sometimes', 'integer', 'min:0', 'max:360'],
            'image_fit' => ['sometimes', 'in:cover,contain'],
            'shadow' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param array<string,mixed> $data */
    private function save(ImageBackdrop $backdrop, array $data): ImageBackdrop
    {
        // Selalu ada tepat satu default: default hanya berpindah dengan menjadikan template lain default.
        if ($backdrop->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
            throw ValidationException::withMessages(['is_default' => ['Harus selalu ada satu template default. Jadikan template lain default terlebih dahulu.']]);
        }
        $makeDefault = (bool) ($data['is_default'] ?? false);
        unset($data['is_default']);
        // Tipe "image" tanpa file tetap aman: storefront memakai color_from sebagai warna dasar.
        $backdrop->fill($data)->save();
        if ($makeDefault) {
            ImageBackdrop::whereKeyNot($backdrop->id)->update(['is_default' => false]);
            $backdrop->update(['is_default' => true]);
        }
        $this->backdrops->forget();

        return $backdrop->refresh()->loadCount('categories');
    }
}
