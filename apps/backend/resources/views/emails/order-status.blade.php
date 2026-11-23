<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $content['headline'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f7;font-family:Arial,Helvetica,sans-serif;color:#14252e;line-height:1.6">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f7;padding:24px 12px">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:28px">
                    <tr>
                        <td>
                            <p style="margin:0 0 4px;font-size:13px;color:#60717a">GutShoes · Pesanan {{ $order->order_number }}</p>
                            <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3">{{ $content['headline'] }}</h1>
                            <p style="margin:0 0 16px">Halo {{ $order->customer_name }},</p>
                            <p style="margin:0 0 16px">{{ $content['intro'] }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e3e8eb;border-bottom:1px solid #e3e8eb;margin:0 0 16px">
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td style="padding:10px 0;font-size:14px">{{ $item->product_name }}<br><span style="color:#60717a">Ukuran {{ $item->size_label }} · {{ $item->quantity }} barang</span></td>
                                        <td style="padding:10px 0;font-size:14px;text-align:right;white-space:nowrap">Rp{{ number_format((float) $item->line_total, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td style="padding:10px 0;font-size:14px;color:#60717a">Ongkos kirim</td>
                                    <td style="padding:10px 0;font-size:14px;text-align:right">Rp{{ number_format((float) $order->shipping_fee, 0, ',', '.') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0;font-size:15px"><strong>Total</strong></td>
                                    <td style="padding:10px 0;font-size:15px;text-align:right"><strong>Rp{{ number_format((float) $order->grand_total, 0, ',', '.') }}</strong></td>
                                </tr>
                            </table>

                            @if ($content['deadline'])
                                <p style="margin:0 0 16px">Selesaikan pembayaran sebelum <strong>{{ $content['deadline'] }}</strong>.</p>
                            @endif

                            @if ($content['cta_url'])
                                <p style="margin:0 0 16px"><a href="{{ $content['cta_url'] }}" style="display:inline-block;padding:12px 18px;background:#087c9b;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold">{{ $content['cta_label'] }}</a></p>
                                @if ($content['private_link'])
                                    <p style="margin:0 0 16px;font-size:12px;color:#60717a">Tautan ini khusus untuk pesananmu. Jangan teruskan email ini kepada orang lain.</p>
                                @endif
                            @endif

                            <p style="margin:24px 0 0;font-size:12px;color:#60717a">Email ini dikirim otomatis karena ada aktivitas pada pesananmu di GutShoes.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
