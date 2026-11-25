<?php

use App\Models\Category;
use App\Models\ImageBackdrop;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** PNG 600×600; $fill = bagian tengah yang buram (0..1), sisanya transparan. */
function backdropTestPng(float $fill = 0.5, bool $transparent = true): string
{
    $image = imagecreatetruecolor(600, 600);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 240, 240, 240, $transparent ? 127 : 0));
    if ($fill > 0) {
        $size = (int) round(600 * sqrt($fill));
        $offset = intdiv(600 - $size, 2);
        imagefilledrectangle($image, $offset, $offset, $offset + $size - 1, $offset + $size - 1, imagecolorallocatealpha($image, 20, 30, 40, 0));
    }
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

function uploadProductImage($test, User $admin, Product $product, string $bytes, string $name = 'shoe.png')
{
    return $test->actingAs($admin)->post('/api/v1/admin/products/'.$product->id.'/images',
        ['image' => UploadedFile::fake()->createWithContent($name, $bytes), 'is_primary' => true], ['Accept' => 'application/json']);
}

beforeEach(function () {
    Storage::fake('public');
});

it('seeds one default backdrop and exposes it publicly', function () {
    $this->getJson('/api/v1/image-backdrops')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.0.color_from', '#f7f8f8');
});

it('marks transparent uploads to use the template and keeps opaque photos as they are', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();

    uploadProductImage($this, $admin, $product, backdropTestPng(0.5))->assertCreated()
        ->assertJsonPath('data.has_transparency', true)
        ->assertJsonPath('data.use_template', true);

    uploadProductImage($this, $admin, $product, backdropTestPng(0.5, false), 'poster.png')->assertCreated()
        ->assertJsonPath('data.has_transparency', false)
        ->assertJsonPath('data.use_template', false);

    $this->actingAs($admin)->post('/api/v1/admin/products/'.$product->id.'/images', ['image' => UploadedFile::fake()->image('photo.jpg', 600, 600)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.use_template', false);
});

it('keeps transparency in the stored webp', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $path = uploadProductImage($this, $admin, $product, backdropTestPng(0.5))->assertCreated()->json('data.path');

    expect(app(\App\Domain\Catalog\ImageSanitizer::class)->transparency(Storage::disk('public')->get($path))['transparent'])->toBeTrue();
});

it('rejects an image that is almost entirely transparent', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();

    uploadProductImage($this, $admin, $product, backdropTestPng(0.0))->assertUnprocessable()->assertJsonValidationErrors('image');
    expect(ProductImage::count())->toBe(0);
});

it('lets an admin toggle the template per image and warns for opaque images', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $image = ProductImage::create(['product_id' => $product->id, 'path' => 'products/x.webp', 'is_primary' => true]);

    $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$product->id}/images/{$image->id}", ['use_template' => true])
        ->assertOk()->assertJsonPath('data.use_template', true)
        ->assertJsonPath('warning', fn ($warning) => str_contains((string) $warning, 'tidak transparan'));

    $other = Product::factory()->create();
    $this->actingAs($admin)->patchJson("/api/v1/admin/products/{$other->id}/images/{$image->id}", ['use_template' => false])->assertNotFound();
    $this->actingAs(User::factory()->create())->patchJson("/api/v1/admin/products/{$product->id}/images/{$image->id}", ['use_template' => false])->assertForbidden();
});

it('validates backdrop templates and keeps exactly one default', function () {
    $admin = User::factory()->admin()->create();
    $default = ImageBackdrop::where('is_default', true)->firstOrFail();

    $this->actingAs($admin)->postJson('/api/v1/admin/image-backdrops', ['name' => 'Salah', 'type' => 'solid', 'color_from' => 'red'])
        ->assertUnprocessable()->assertJsonValidationErrors('color_from');
    $this->actingAs($admin)->postJson('/api/v1/admin/image-backdrops', ['name' => 'Gradien', 'type' => 'gradient', 'color_from' => '#ffffff'])
        ->assertUnprocessable()->assertJsonValidationErrors('color_to');

    $id = $this->actingAs($admin)->postJson('/api/v1/admin/image-backdrops', ['name' => 'Laut', 'type' => 'gradient', 'color_from' => '#e0f2fe', 'color_to' => '#7dd3fc', 'angle' => 135, 'is_default' => true])
        ->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
    expect(ImageBackdrop::where('is_default', true)->pluck('id')->all())->toBe([$id]);

    $this->actingAs($admin)->putJson("/api/v1/admin/image-backdrops/{$id}", ['name' => 'Laut', 'type' => 'gradient', 'color_from' => '#e0f2fe', 'color_to' => '#7dd3fc', 'is_default' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('is_default');
    $this->actingAs($admin)->deleteJson("/api/v1/admin/image-backdrops/{$id}")->assertUnprocessable();
    $this->actingAs($admin)->deleteJson("/api/v1/admin/image-backdrops/{$default->id}")->assertOk();
    $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/image-backdrops')->assertForbidden();
});

it('uploads a background image for a template', function () {
    $admin = User::factory()->admin()->create();
    $backdrop = ImageBackdrop::create(['name' => 'Foto', 'type' => 'image', 'color_from' => '#eeeeee']);

    $path = $this->actingAs($admin)->post("/api/v1/admin/image-backdrops/{$backdrop->id}/image", ['image' => UploadedFile::fake()->image('bg.jpg', 1200, 900)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.image_path');
    Storage::disk('public')->assertExists($path);
    $this->actingAs($admin)->post("/api/v1/admin/image-backdrops/{$backdrop->id}/image", ['image' => UploadedFile::fake()->image('bg.jpg', 300, 300)], ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->getJson('/api/v1/image-backdrops')->assertJsonFragment(['image_path' => $path]);
});

it('resolves the backdrop per product from the category override or the default', function () {
    $admin = User::factory()->admin()->create();
    $default = ImageBackdrop::where('is_default', true)->firstOrFail();
    $running = ImageBackdrop::create(['name' => 'Lari', 'type' => 'solid', 'color_from' => '#e0f2fe']);
    $lari = Category::create(['name' => 'Lari', 'slug' => 'lari']);
    $basket = Category::create(['name' => 'Basket', 'slug' => 'basket']);
    $this->actingAs($admin)->putJson("/api/v1/admin/categories/{$lari->id}", ['name' => 'Lari', 'slug' => 'lari', 'image_backdrop_id' => $running->id])
        ->assertOk()->assertJsonPath('data.image_backdrop_id', $running->id);

    $runner = Product::factory()->create(['status' => 'PUBLISHED', 'slug' => 'runner']);
    $runner->categories()->attach($lari);
    ProductImage::create(['product_id' => $runner->id, 'path' => 'products/a.webp', 'is_primary' => true, 'use_template' => true]);
    $hoop = Product::factory()->create(['status' => 'PUBLISHED', 'slug' => 'hoop']);
    $hoop->categories()->attach($basket);

    $this->getJson('/api/v1/products/runner')->assertOk()
        ->assertJsonPath('data.backdrop_id', $running->id)
        ->assertJsonPath('data.images.0.use_template', true);
    $this->getJson('/api/v1/products/hoop')->assertOk()->assertJsonPath('data.backdrop_id', $default->id);

    // Menghapus template override mengembalikan kategori ke default.
    $this->actingAs($admin)->deleteJson("/api/v1/admin/image-backdrops/{$running->id}")->assertOk();
    $this->getJson('/api/v1/products/runner')->assertJsonPath('data.backdrop_id', $default->id);
});

it('refreshes the public backdrop list after an update', function () {
    $admin = User::factory()->admin()->create();
    $default = ImageBackdrop::where('is_default', true)->firstOrFail();
    $this->getJson('/api/v1/image-backdrops')->assertJsonPath('data.0.color_from', '#f7f8f8');

    $this->actingAs($admin)->putJson("/api/v1/admin/image-backdrops/{$default->id}", ['name' => 'Abu', 'type' => 'solid', 'color_from' => '#eeeeee'])->assertOk();
    $this->getJson('/api/v1/image-backdrops')->assertJsonPath('data.0.color_from', '#eeeeee');
});

it('backfills transparency for existing images and only switches templates with --apply', function () {
    $product = Product::factory()->create();
    Storage::disk('public')->put('products/t.png', backdropTestPng(0.5));
    Storage::disk('public')->put('products/o.png', backdropTestPng(0.5, false));
    $transparent = ProductImage::create(['product_id' => $product->id, 'path' => 'products/t.png']);
    $opaque = ProductImage::create(['product_id' => $product->id, 'path' => 'products/o.png']);

    $this->artisan('gutshoes:detect-image-transparency')->assertSuccessful();
    expect($transparent->refresh()->has_transparency)->toBeTrue()
        ->and($transparent->use_template)->toBeFalse()
        ->and($opaque->refresh()->has_transparency)->toBeFalse();

    $this->artisan('gutshoes:detect-image-transparency', ['--apply' => true])->assertSuccessful();
    expect($transparent->refresh()->use_template)->toBeTrue()
        ->and($opaque->refresh()->use_template)->toBeFalse();
});
