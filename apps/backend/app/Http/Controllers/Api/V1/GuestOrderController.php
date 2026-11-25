<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Order\GuestOrderAccess;
use App\Domain\Order\GuestOrderPresenter;
use App\Domain\Payment\CreatePaymentService;
use App\Domain\Payment\RefreshPendingPayment;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestOrderController extends Controller
{
    public function show(Request $request, string $orderNumber, GuestOrderAccess $access, GuestOrderPresenter $presenter, RefreshPendingPayment $payments): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $access->authorize($request, $order);
        // Pembeli yang baru kembali dari Midtrans langsung melihat status bayar terbaru.
        $payments->refresh($order);

        return response()->json(['data' => $presenter->present($order)]);
    }

    public function payment(Request $request, string $orderNumber, GuestOrderAccess $access, CreatePaymentService $payments, GuestOrderPresenter $presenter): JsonResponse
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $access->authorize($request, $order);
        $result = $payments->create($order, $request->boolean('change_method'));

        return response()->json(['data' => [
            'order' => $presenter->present($order->refresh()),
            'payment' => $result['payment'],
            'redirect_url' => $result['redirect_url'],
        ]], 201);
    }
}
