<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\InventoryService;
use App\Domain\Store\StoreInfo;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\StoreConfiguration;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminOperationsController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $lowStock = (int) (StoreConfiguration::where('key', 'low_stock_threshold')->value('value') ?? 5);

        return response()->json(['data' => [
            'orders' => [
                'pending_payment' => Order::where('status', 'PENDING_PAYMENT')->count(),
                'paid' => Order::where('status', 'PAID')->count(),
                'processing' => Order::where('status', 'PROCESSING')->count(),
                'shipped' => Order::where('status', 'SHIPPED')->count(),
                'delivered' => Order::where('status', 'DELIVERED')->count(),
                'today' => Order::whereDate('created_at', today())->count(),
            ],
            'revenue' => [
                'paid_total' => number_format((float) Order::whereIn('status', ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'])->sum('grand_total'), 2, '.', ''),
                'today' => number_format((float) Order::whereDate('created_at', today())->whereIn('status', ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'])->sum('grand_total'), 2, '.', ''),
                'last_30_days' => number_format((float) Order::where('created_at', '>=', now()->subDays(30))->whereIn('status', ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'])->sum('grand_total'), 2, '.', ''),
                'currency' => 'IDR',
            ],
            'operations' => [
                // Belum dibayar tetapi sudah lewat batas waktu: menunggu job kedaluwarsa (scheduler).
                'payments_overdue' => Order::where('status', 'PENDING_PAYMENT')->where('expires_at', '<', now())->count(),
                // Hanya pengiriman milik pesanan yang sudah dibayar yang perlu ditangani tim.
                'shipments_pending' => Shipment::whereIn('status', ['PENDING', 'READY'])
                    ->whereHas('order', fn (Builder $q) => $q->whereIn('status', ['PAID', 'PROCESSING']))->count(),
                'refunds_pending' => Refund::whereIn('status', ['PENDING', 'FAILED'])->count(),
            ],
            'recent_orders' => Order::query()->with('payment:id,order_id,status')->latest()->limit(5)
                ->get(['id', 'order_number', 'customer_name', 'customer_email', 'status', 'grand_total', 'created_at']),
            'low_stock_count' => Inventory::whereRaw('(on_hand - reserved) <= ?', [$lowStock])->count(), 'low_stock_threshold' => $lowStock,
        ]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = Order::query()->with(['payment', 'shipment']);
        if ($request->input('status') === 'OVERDUE') {
            $query->where('status', 'PENDING_PAYMENT')->where('expires_at', '<', now());
            $request->query->remove('status');
        }

        return $this->listing($query, $request, ['status', 'customer_email', 'order_number'], 'created_at', ['order_number', 'customer_name', 'customer_email']);
    }

    public function order(Order $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['items', 'addresses', 'payment', 'shipment', 'refunds',
            'statusHistories' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'statusHistories.actor:id,name'])]);
    }

    public function payments(Request $request): JsonResponse
    {
        return $this->listing(Payment::query()->with('order'), $request, ['status', 'provider'], 'created_at');
    }

    public function shipments(Request $request): JsonResponse
    {
        return $this->listing(Shipment::query()->with('order'), $request, ['status', 'courier', 'service'], 'created_at');
    }

    public function cancellations(Request $request): JsonResponse
    {
        return $this->listing(OrderCancellation::query()->with('order'), $request, ['status'], 'created_at');
    }

    public function refunds(Request $request): JsonResponse
    {
        $query = Refund::query()->with(['order', 'payment']);
        if ($request->filled('search')) {
            $term = $this->likeTerm($request->input('search'));
            $query->whereHas('order', fn (Builder $q) => $q->where('order_number', 'like', $term)->orWhere('customer_email', 'like', $term));
        }

        return $this->listing($query, $request, ['status'], 'created_at');
    }

    public function customers(Request $request): JsonResponse
    {
        $query = User::query()->where('role', 'CUSTOMER')->withCount('orders');
        match ($request->input('verified')) {
            '1' => $query->whereNotNull('email_verified_at'),
            '0' => $query->whereNull('email_verified_at'),
            default => null,
        };

        return $this->listing($query, $request, ['email'], 'created_at', ['name', 'email']);
    }

    public function customer(User $customer): JsonResponse
    {
        abort_unless($customer->role->value === 'CUSTOMER', 404);
        $paidStatuses = ['PAID', 'PROCESSING', 'SHIPPED', 'DELIVERED'];

        return response()->json(['data' => [
            'customer' => $customer->load(['customerProfile', 'addresses']),
            'summary' => [
                'order_count' => $customer->orders()->count(),
                'paid_total' => number_format((float) $customer->orders()->whereIn('status', $paidStatuses)->sum('grand_total'), 2, '.', ''),
            ],
            'orders' => $customer->orders()->with(['payment', 'shipment'])->latest()->limit(20)->get(),
        ]]);
    }

    public function warehouse(): JsonResponse
    {
        return response()->json(['data' => Warehouse::query()->where('is_active', true)->first()]);
    }

    public function updateWarehouse(Request $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('is_active', true)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'],
            'address_line' => ['required', 'string', 'max:500'], 'province' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'], 'district' => ['required', 'string', 'max:255'], 'subdistrict' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:10'], 'provider_area_id' => ['required', 'string', 'max:255'],
        ]);
        $warehouse->update($data);

        return response()->json(['data' => $warehouse->refresh()]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = AuditLog::query()->with('admin:id,name,email');
        $query->when($request->input('from'), fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()));
        $query->when($request->input('to'), fn (Builder $q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        return $this->listing($query, $request, ['admin_id', 'action', 'entity_type'], 'created_at', ['action', 'entity_type', 'description']);
    }

    public function promotions(Request $request): JsonResponse
    {
        return $this->listing(Promotion::query()->with('products:id,name'), $request, ['type', 'is_active'], 'priority', ['name']);
    }

    public function vouchers(Request $request): JsonResponse
    {
        return $this->listing(Voucher::query()->with('products:id,name'), $request, ['type', 'is_active', 'code'], 'created_at', ['code', 'name']);
    }

    public function inventories(Request $request): JsonResponse
    {
        $query = Inventory::query()->with(['warehouse', 'variant.product', 'variant.size']);
        if ($request->filled('search')) {
            $term = $this->likeTerm($request->input('search'));
            $query->where(fn (Builder $q) => $q
                ->whereHas('variant', fn (Builder $v) => $v->where('sku', 'like', $term)->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', $term)))
                ->orWhereHas('warehouse', fn (Builder $w) => $w->where('name', 'like', $term)));
        }
        $threshold = (int) (StoreConfiguration::where('key', 'low_stock_threshold')->value('value') ?? 5);
        match ($request->input('stock')) {
            'out' => $query->whereRaw('on_hand - reserved <= 0'),
            'low' => $query->whereRaw('on_hand - reserved > 0')->whereRaw('on_hand - reserved <= ?', [$threshold]),
            'available' => $query->whereRaw('on_hand - reserved > ?', [$threshold]),
            default => null,
        };

        $response = $this->listing($query, $request, ['warehouse_id', 'product_variant_id'], 'updated_at');
        $payload = $response->getData(true);
        $payload['meta']['low_stock_threshold'] = $threshold;

        return $response->setData($payload);
    }

    public function inventoryOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'variants' => ProductVariant::query()->where('is_active', true)->with(['product:id,name', 'size:id,label,value'])->orderBy('sku')->get(['id', 'product_id', 'size_id', 'sku']),
        ]]);
    }

    public function createInventory(Request $request, InventoryService $service): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id', Rule::unique('inventories')->where(fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))],
            'initial_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $inventory = Inventory::create(['warehouse_id' => $data['warehouse_id'], 'product_variant_id' => $data['product_variant_id'], 'on_hand' => 0, 'reserved' => 0, 'sold' => 0]);
        if ($data['initial_stock'] > 0) {
            $service->add($inventory, $data['initial_stock'], $data['reason'], $request->user());
        }

        return response()->json(['data' => $inventory->refresh()->load(['warehouse', 'variant.product', 'variant.size'])], 201);
    }

    public function inventoryMovements(Inventory $inventory): JsonResponse
    {
        return response()->json(['data' => $inventory->movements()->with('actor:id,name,email')->latest()->limit(50)->get()]);
    }

    public function adjustInventory(Request $request, Inventory $inventory, InventoryService $service): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'integer', 'not_in:0'], 'reason' => ['required', 'string', 'max:255']]);
        $updated = $service->adjust($inventory, $data['delta'], $data['reason'], $request->user());

        return response()->json(['data' => $updated->load(['warehouse', 'variant.product', 'variant.size'])]);
    }

    public function deleteInventory(Inventory $inventory): JsonResponse
    {
        abort_if($inventory->on_hand > 0 || $inventory->reserved > 0 || $inventory->sold > 0 || InventoryMovement::where('inventory_id', $inventory->id)->exists(), 422, 'Inventory with stock or movement history cannot be deleted. Set it to zero and retain it for audit.');
        $inventory->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function configurations(): JsonResponse
    {
        return response()->json(['data' => StoreConfiguration::orderBy('key')->get()]);
    }

    /**
     * Simpan banyak pengaturan sekaligus dalam satu transaksi: semua valid lalu tersimpan,
     * atau tidak ada yang berubah. Kesalahan dilaporkan per kunci (values.<kunci>).
     */
    public function updateConfigurations(Request $request, StoreInfo $storeInfo): JsonResponse
    {
        $request->validate(['values' => ['required', 'array', 'min:1', 'max:50']]);
        $rules = [];
        foreach (array_keys($request->input('values')) as $key) {
            abort_unless(is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1, 422, 'Kunci pengaturan tidak valid.');
            $rules["values.{$key}"] = StoreInfo::isField($key) ? array_merge(['present'], StoreInfo::FIELDS[$key]) : ['required'];
        }
        $values = $request->validate($rules)['values'];

        $saved = DB::transaction(function () use ($values, $request): array {
            $saved = [];
            foreach ($values as $key => $value) {
                $isStoreField = StoreInfo::isField($key);
                $value = is_string($value) ? trim($value) : $value;
                $saved[] = StoreConfiguration::updateOrCreate(['key' => $key], [
                    'value' => $isStoreField ? ($value ?? '') : $value,
                    'is_public' => $isStoreField || (bool) StoreConfiguration::where('key', $key)->value('is_public'),
                    'updated_by' => $request->user()->id,
                ]);
            }

            return $saved;
        });
        $storeInfo->forget();

        return response()->json(['data' => $saved]);
    }

    public function updateConfiguration(Request $request, string $key, StoreInfo $storeInfo): JsonResponse
    {
        // Informasi toko: aturan per kolom, boleh dikosongkan, dan selalu publik.
        if (StoreInfo::isField($key)) {
            $data = $request->validate(['value' => array_merge(['present'], StoreInfo::FIELDS[$key])]);
            $value = is_string($data['value']) ? trim($data['value']) : $data['value'];
            $configuration = StoreConfiguration::updateOrCreate(['key' => $key], ['value' => $value ?? '', 'is_public' => true, 'updated_by' => $request->user()->id]);
            $storeInfo->forget();

            return response()->json(['data' => $configuration]);
        }

        $data = $request->validate(['value' => ['required'], 'is_public' => ['sometimes', 'boolean']]);
        $configuration = StoreConfiguration::updateOrCreate(['key' => $key], ['value' => $data['value'], 'is_public' => $data['is_public'] ?? false, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $configuration]);
    }

    public function savePromotion(Request $request, ?Promotion $promotion = null): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string'], 'type' => ['required', Rule::in(['PERCENTAGE', 'FIXED_AMOUNT'])], 'value' => ['required', 'numeric', 'min:0', Rule::when($request->input('type') === 'PERCENTAGE', ['max:100'])],
            'priority' => ['required', 'integer', 'min:0'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'is_active' => ['required', 'boolean'], 'product_ids' => ['sometimes', 'array'], 'product_ids.*' => ['integer', 'exists:products,id']]);
        $productIds = $data['product_ids'] ?? [];
        unset($data['product_ids']);
        $promotion ??= new Promotion;
        $promotion->fill($data)->save();
        $promotion->products()->sync($productIds);

        return response()->json(['data' => $promotion->load('products')], $promotion->wasRecentlyCreated ? 201 : 200);
    }

    public function deletePromotion(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function saveVoucher(Request $request, ?Voucher $voucher = null): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64', Rule::unique('vouchers', 'code')->ignore($voucher?->id)], 'name' => ['required', 'string'],
            'type' => ['required', Rule::in(['PERCENTAGE', 'FIXED_AMOUNT', 'FREE_SHIPPING'])], 'value' => ['required', 'numeric', 'min:0', Rule::when($request->input('type') === 'PERCENTAGE', ['max:100'])], 'minimum_amount' => ['required', 'numeric', 'min:0'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0'], 'usage_limit' => ['nullable', 'integer', 'min:1'], 'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'is_active' => ['required', 'boolean'], 'product_ids' => ['sometimes', 'array'], 'product_ids.*' => ['integer', 'exists:products,id']]);
        $productIds = $data['product_ids'] ?? [];
        unset($data['product_ids']);
        $voucher ??= new Voucher;
        $voucher->fill($data)->save();
        $voucher->products()->sync($productIds);

        return response()->json(['data' => $voucher->load('products')], $voucher->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteVoucher(Voucher $voucher): JsonResponse
    {
        $voucher->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @param Builder<*> $query
     * @param  list<string>  $filters
     * @param  list<string>  $searchable
     */
    private function listing(Builder $query, Request $request, array $filters, string $defaultSort, array $searchable = []): JsonResponse
    {
        if ($searchable !== [] && $request->filled('search')) {
            $term = $this->likeTerm($request->input('search'));
            $query->where(function (Builder $q) use ($searchable, $term): void {
                foreach ($searchable as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }
        foreach ($filters as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), array_merge($filters, [$defaultSort]), true) ? $request->input('sort') : $defaultSort;
        $page = $query->orderBy($sort, $direction)->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    private function likeTerm(mixed $value): string
    {
        return '%'.addcslashes(mb_substr(trim((string) $value), 0, 100), '%_\\').'%';
    }
}
