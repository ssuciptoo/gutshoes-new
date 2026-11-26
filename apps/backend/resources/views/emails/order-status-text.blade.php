{!! $content['headline'] !!}
GutShoes · Pesanan {!! $order->order_number !!}

Halo {!! $order->customer_name !!},

{!! $content['intro'] !!}
@if ($content['shipment'])

Kurir: {!! $content['shipment']['courier'] !!}
Nomor resi: {!! $content['shipment']['tracking_number'] !!}
@endif

@foreach ($order->items as $item)
- {!! $item->product_name !!} (ukuran {!! $item->size_label !!}, {!! $item->quantity !!} barang): Rp{{ number_format((float) $item->line_total, 0, ',', '.') }}
@endforeach
Ongkos kirim: Rp{{ number_format((float) $order->shipping_fee, 0, ',', '.') }}
Total: Rp{{ number_format((float) $order->grand_total, 0, ',', '.') }}
@if ($content['deadline'])

Selesaikan pembayaran sebelum {!! $content['deadline'] !!}.
@endif
@if ($content['cta_url'])

{!! $content['cta_label'] !!}: {!! $content['cta_url'] !!}
@if ($content['private_link'])
Tautan ini khusus untuk pesananmu. Jangan teruskan email ini kepada orang lain.
@endif
@endif

Email ini dikirim otomatis karena ada aktivitas pada pesananmu di GutShoes.
