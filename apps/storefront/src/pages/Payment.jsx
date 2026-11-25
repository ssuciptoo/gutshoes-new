import React, {useState} from "react";
import {api} from "../api";
import {rupiah} from "../lib/currency";
import Button from "../components/ui/Button";
import Feedback from "../components/ui/Feedback";
import Icon from "../components/ui/Icon";

function Payment({order, navigate, user}) {
  const ordersPage = user && user.role !== "ADMIN" ? "orders" : "guest-orders";
  const [paymentState, setPaymentState] = useState(
    order?.paymentStatus || "pending",
  );
  const [redirectUrl, setRedirectUrl] = useState(order?.redirectUrl || "");
  const [error, setError] = useState(order?.paymentError || "");
  const retryPayment = async (changeMethod = false) => {
    if (!order || paymentState === "loading") return;
    setPaymentState("loading");
    setError("");
    try {
      if (order.customerType === "guest" && !order.accessToken)
        throw new Error(
          "Token akses pesanan tidak tersedia di browser ini. Buka kembali tautan pemulihan dari email.",
        );
      const response =
        order.customerType === "guest" || order.accessToken
          ? await api.createGuestPayment(order.number, order.accessToken, changeMethod)
          : await api.createCustomerPayment(order.number, order.email, changeMethod);
      setRedirectUrl(response.data.redirect_url);
      setPaymentState("ready");
      if (changeMethod) window.location.assign(response.data.redirect_url);
    } catch (err) {
      setPaymentState("error");
      setError(
        Object.values(err?.errors || {}).flat()[0] ||
          err.message ||
          "Sesi pembayaran Midtrans belum dapat dibuat.",
      );
    }
  };
  if (!order)
    return (
      <main className="status-page">
        <div className="status-symbol">
          <Icon name="package" size={34} />
        </div>
        <h1>Pesanan tidak ditemukan di halaman ini</h1>
        <p>
          Pesanan yang sudah kamu buat tetap tersimpan. Buka Pesanan saya untuk
          melihat status atau melanjutkan pembayaran.
        </p>
        <Button onClick={() => navigate(ordersPage)}>Buka Pesanan saya</Button>
        <button className="text-link" onClick={() => navigate("cart")}>
          Kembali ke keranjang
        </button>
      </main>
    );
  const ready = paymentState === "ready" && redirectUrl;
  return (
    <main className="status-page">
      <div className="status-symbol">
        <Icon name="package" size={34} />
      </div>
      <p className="status-label" role="status" aria-live="polite">
        {ready
          ? "MENUNGGU PEMBAYARAN"
          : paymentState === "loading"
            ? "MENYIAPKAN PEMBAYARAN"
            : "PESANAN SUDAH DIBUAT"}
      </p>
      <h1>
        {ready
          ? "Selesaikan pembayaranmu"
          : "Halaman pembayaran belum siap"}
      </h1>
      <p>
        Pesanan <strong>{order.number}</strong> sudah dibuat. Status berubah
        menjadi lunas otomatis setelah Midtrans mengonfirmasi pembayaranmu,
        biasanya dalam beberapa menit.
      </p>
      <div className="payment-box">
        <span>
          Total pembayaran<strong>{rupiah(order.total)}</strong>
        </span>
        <span>
          Status pembayaran<strong>Menunggu pembayaran</strong>
        </span>
      </div>
      {error && <Feedback>{error}</Feedback>}
      {ready ? (
        <>
          <Button onClick={() => window.location.assign(redirectUrl)}>
            Bayar sekarang
          </Button>
          <button className="text-link" onClick={() => retryPayment(true)}>
            Ganti metode pembayaran
          </button>
        </>
      ) : (
        <Button
          onClick={() => retryPayment()}
          disabled={paymentState === "loading"}
          aria-busy={paymentState === "loading"}
        >
          {paymentState === "loading"
            ? "Menyiapkan pembayaran…"
            : "Coba buka pembayaran lagi"}
        </Button>
      )}
      <button
        className="text-link"
        onClick={() =>
          order.customerType === "customer"
            ? navigate("order-detail", {orderNumber: order.number})
            : navigate("guest-orders")
        }
      >
        Lihat status pesanan
      </button>
      <button className="text-link" onClick={() => navigate("home")}>
        Kembali ke beranda
      </button>
    </main>
  );
}

export default Payment;
