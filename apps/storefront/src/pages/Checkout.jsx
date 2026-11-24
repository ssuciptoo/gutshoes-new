import React, {useEffect, useRef, useState} from "react";
import {api, isBackendUnavailable} from "../api";
import {rupiah} from "../lib/currency";
import RegionFields from "../components/forms/RegionFields";
import Button from "../components/ui/Button";
import Feedback from "../components/ui/Feedback";
import Icon from "../components/ui/Icon";
import OrderSummary from "../components/checkout/OrderSummary";

// Satu pilihan pengiriman = kurir + kode layanan, mis. "sicepat:REG".
const shippingKey = (option) => (option ? `${option.courier || ""}:${option.service || ""}` : "");
const splitShippingKey = (key = "") => {
  const index = key.indexOf(":");
  return index === -1 ? ["", key] : [key.slice(0, index), key.slice(index + 1)];
};

function Checkout({
  cart,
  navigate,
  createOrder,
  user,
  addresses,
  onCheckoutCreated,
  ensureServerCart,
}) {
  const [step, setStep] = useState(1);
  const [buyer, setBuyer] = useState(null);
  const [shippingService, setShippingService] = useState("");
  const [shippingOptions, setShippingOptions] = useState([]);
  const [serverShipping, setServerShipping] = useState(null);
  const [pricing, setPricing] = useState(null);
  const [voucher, setVoucher] = useState("");
  const [appliedVoucher, setAppliedVoucher] = useState("");
  const [operation, setOperation] = useState("");
  const [error, setError] = useState("");
  const [invalidFields, setInvalidFields] = useState([]);
  // Alamat tersimpan yang dipilih ("new" = isi alamat baru). Default: alamat utama.
  const [addressChoice, setAddressChoice] = useState(null);
  const defaultAddress = addresses.find((address) => address.isDefault) || addresses[0];
  const choice = user && addresses.length ? addressChoice ?? String(defaultAddress.id) : "new";
  const chosenAddress = addresses.find((address) => String(address.id) === choice);
  // Telepon profil hanya dipakai bila formatnya valid; jika tidak, pakai telepon alamat utama.
  const validPhone = (value) => /^[0-9+ -]{8,20}$/.test(String(value || "").trim());
  const suggestedPhone = [user?.phone, defaultAddress?.phone].find(validPhone) || "";
  const checkoutFormRef = useRef(null);
  const attemptStorageKey = "gutshoes-checkout-" + (user?.id || "guest");
  const orderAttempt = useRef(null);
  if (!orderAttempt.current) {
    try { orderAttempt.current = JSON.parse(sessionStorage.getItem(attemptStorageKey)); } catch { /* Ignore invalid local state. */ }
  }
  const submittingOrder = useRef(false);
  // Totals always come from the backend quote; there is no client-side pricing.
  const shownPricing = pricing;

  const messageFor = (err, fallback) => {
    const fieldMessage = Object.values(err?.errors || {}).flat()[0];
    return fieldMessage || err?.message || fallback;
  };
  useEffect(() => {
    if (!error || step !== 1) return;
    checkoutFormRef.current
      ?.querySelector('[aria-invalid="true"], input:invalid, textarea:invalid')
      ?.focus();
  }, [error, invalidFields, step]);
  const quotePayload = (selection = shippingService, code = appliedVoucher) => {
    const [courier, service] = splitShippingKey(selection);
    return {
    destination_area_id: buyer?.village_code || "destination",
    province_code: buyer?.province_code,
    regency_code: buyer?.regency_code,
    district_code: buyer?.district_code,
    village_code: buyer?.village_code,
    courier: courier || null,
    service: service || null,
    voucher_code: code || null,
    };
  };
  const validateQuote = async (
    service = shippingService,
    code = appliedVoucher,
  ) => {
    setOperation(code ? "voucher" : "quote");
    setError("");
    setInvalidFields([]);
    try {
      const serverCart = await ensureServerCart();
      const response = await api.quote(
        quotePayload(service, code),
        serverCart.token,
      );
      setPricing({
        ...response.data.pricing,
        shipping_fee: Number(response.data.pricing.shipping_fee),
        voucher_discount: Number(response.data.pricing.voucher_discount),
        grand_total: Number(response.data.pricing.grand_total),
      });
      setServerShipping(response.data.shipping);
      setShippingOptions(response.data.shipping_options || [response.data.shipping]);
      setShippingService(shippingKey(response.data.shipping));
      setAppliedVoucher(code);
      return true;
    } catch (err) {
      setPricing(null);
      setInvalidFields(Object.keys(err?.errors || {}));
      setError(
        messageFor(
          err,
          "Keranjang, alamat, ongkir, atau voucher belum valid. Periksa data lalu coba lagi.",
        ),
      );
      return false;
    } finally {
      setOperation("");
    }
  };
  const next = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    setInvalidFields([]);
    if (!form.reportValidity()) return;
    const data = Object.fromEntries(new FormData(form));
    const selectedAddress = chosenAddress;
    if (
      !selectedAddress &&
      (!data.province_code ||
        !data.regency_code ||
        !data.district_code ||
        !data.village_code)
    ) {
      setError(
        "Pilih provinsi, kota/kabupaten, kecamatan, dan kelurahan/desa sebelum melanjutkan.",
      );
      setInvalidFields([
        "province_code",
        "regency_code",
        "district_code",
        "village_code",
        "postal",
      ]);
      return;
    }
    const nextBuyer = selectedAddress
      ? {
          ...data,
          name: selectedAddress.recipient,
          phone: selectedAddress.phone || data.phone,
          address: selectedAddress.address,
          province: selectedAddress.province,
          city: selectedAddress.city,
          province_code: selectedAddress.provinceCode,
          regency_code: selectedAddress.regencyCode,
          district_code: selectedAddress.districtCode,
          village_code: selectedAddress.villageCode,
          postal: selectedAddress.postal,
          providerAreaId:
            selectedAddress.providerAreaId || selectedAddress.city,
        }
      : data;
    setBuyer(nextBuyer);
    setError("");
    setOperation("quote");
    try {
      const serverCart = await ensureServerCart();
      const response = await api.quote(
        {
          destination_area_id: nextBuyer.village_code,
          province_code: nextBuyer.province_code,
          regency_code: nextBuyer.regency_code,
          district_code: nextBuyer.district_code,
          village_code: nextBuyer.village_code,
          courier: null,
          service: null,
          voucher_code: null,
        },
        serverCart.token,
      );
      setPricing({
        ...response.data.pricing,
        shipping_fee: Number(response.data.pricing.shipping_fee),
        voucher_discount: Number(response.data.pricing.voucher_discount),
        grand_total: Number(response.data.pricing.grand_total),
      });
      setServerShipping(response.data.shipping);
      setShippingOptions(response.data.shipping_options || [response.data.shipping]);
      setShippingService(shippingKey(response.data.shipping));
      setStep(2);
    } catch (err) {
      setPricing(null);
      setError(messageFor(err, "Ongkir belum dapat diperiksa. Coba lagi; checkout belum dilanjutkan."));
    } finally {
      setOperation("");
    }
  };
  const changeShipping = async (service) => {
    setShippingService(service);
    await validateQuote(service, appliedVoucher);
  };
  const applyVoucher = async () => {
    const code = voucher.trim().toUpperCase();
    if (!code) {
      setAppliedVoucher("");
      await validateQuote(shippingService, "");
      return;
    }
    await validateQuote(shippingService, code);
  };
  const orderPayload = {
    customer: {name: buyer?.name, email: buyer?.email, phone: buyer?.phone},
    address: {
      recipient_name: buyer?.name,
      phone: buyer?.phone,
      address_line: buyer?.address,
      province_code: buyer?.province_code,
      regency_code: buyer?.regency_code,
      district_code: buyer?.district_code,
      village_code: buyer?.village_code,
      postal_code: buyer?.postal,
      provider_area_id: buyer?.village_code,
    },
    shipping: {
      courier: serverShipping?.courier || splitShippingKey(shippingService)[0] || "jne",
      service: serverShipping?.service || splitShippingKey(shippingService)[1],
    },
    voucher_code: appliedVoucher || null,
  };
  const submitOrder = async () => {
    if (submittingOrder.current || operation || (!orderAttempt.current && (!buyer || !pricing))) return;
    submittingOrder.current = true;
    setOperation("order");
    setError("");

    try {
      // Keep the exact request and key when the response is lost. Do not create a new order.
      if (!orderAttempt.current) {
        const serverCart = await ensureServerCart();
        orderAttempt.current = {payload: orderPayload, token: serverCart.token, key: crypto.randomUUID()};
        sessionStorage.setItem(attemptStorageKey, JSON.stringify(orderAttempt.current));
      }
      const attempt = orderAttempt.current;
      const created = await api.checkout(attempt.payload, attempt.token, attempt.key);
      sessionStorage.removeItem(attemptStorageKey);
      orderAttempt.current = null;
      const accessToken = created.data.guest_access_token || "";
      const customerType = user ? "customer" : "guest";
      const orderData = {
        number: created.data.order_number,
        total: Number(created.data.grand_total),
        email: created.data.customer_email,
        accessToken,
        customerType,
        paymentStatus: "initializing",
        status: "PENDING_PAYMENT",
        createdAt: new Date().toISOString(),
      };
      createOrder(orderData);
      setOperation("payment");
      try {
        if (customerType === "guest" && !accessToken)
          throw new Error(
            "Pesanan sudah dibuat, tetapi halaman pembayaran belum bisa dibuka. Simpan nomor pesanan ini dan buka tautan di email konfirmasi.",
          );
        const payment =
          customerType === "guest"
            ? await api.createGuestPayment(orderData.number, accessToken)
            : await api.createCustomerPayment(orderData.number, orderData.email);
        createOrder({
          ...orderData,
          redirectUrl: payment.data.redirect_url,
          paymentStatus: "ready",
        });
      } catch (paymentError) {
        createOrder({
          ...orderData,
          paymentStatus: "error",
          paymentError: messageFor(
            paymentError,
            "Sesi pembayaran Midtrans belum dapat dibuat.",
          ),
        });
      }
      onCheckoutCreated();
      navigate("payment");
    } catch (err) {
      if (err.status === 422) {
        orderAttempt.current = null;
        sessionStorage.removeItem(attemptStorageKey);
      }
      setError(isBackendUnavailable(err)
        ? "Status pembuatan pesanan belum pasti. Tekan coba lagi untuk memeriksa permintaan yang sama; jangan membuat pesanan baru."
        : messageFor(err, "Pesanan belum dapat dibuat. Periksa data lalu coba lagi."));
    } finally {
      submittingOrder.current = false;
      setOperation("");
    }
  };

  if (orderAttempt.current && !buyer) return (
    <main className="checkout-page">
      <h1>Periksa pesanan sebelumnya</h1>
      <p>Hasil permintaan sebelumnya belum diterima. Periksa kembali sebelum membuat pesanan baru.</p>
      <Feedback>{error}</Feedback>
      <Button onClick={submitOrder} disabled={Boolean(operation)}>
        {operation ? "Memeriksa pesanan…" : "Periksa pesanan"}
      </Button>
    </main>
  );

  if (!cart.length)
    return (
      <main className="checkout-page">
        <div className="empty">
          <Icon name="cart" size={36} />
          <h1>Keranjang belum siap di-checkout</h1>
          <p>Tambahkan setidaknya satu sepatu sebelum melanjutkan.</p>
          <Button onClick={() => navigate("catalog")}>
            Lihat semua sepatu
          </Button>
        </div>
      </main>
    );
  const busy = Boolean(operation);
  return (
    <main className="checkout-page">
      <button className="back-link" onClick={() => navigate("cart")}>
        <Icon name="back" /> Kembali ke keranjang
      </button>
      <div className="checkout-head">
        <h1>Checkout</h1>
        <div className="steps" aria-label="Tahapan checkout">
          {["Data pembeli", "Pengiriman", "Pembayaran"].map((label, index) => (
            <span
              className={step >= index + 1 ? "active" : ""}
              aria-current={step === index + 1 ? "step" : undefined}
              key={label}
            >
              <b>
                {step > index + 1 ? <Icon name="check" size={15} /> : index + 1}
              </b>
              {label}
            </span>
          ))}
        </div>
      </div>
      <div className="checkout-layout">
        <section className="checkout-form" aria-busy={busy}>
          <div className="checkout-mode api" role="status">
            <strong>Checkout aman</strong>
            <span>Harga, ongkir, dan diskon dihitung ulang sebelum pesanan dibuat.</span>
          </div>
          {step === 1 && (
            <form ref={checkoutFormRef} onSubmit={next}>
              <h2>{user ? "Pilih alamat pengiriman" : "Data pembeli"}</h2>
              <p>
                {user
                  ? "Gunakan alamat tersimpan atau masukkan tujuan baru."
                  : "Masuk tidak wajib. Informasi ini digunakan untuk pembaruan pesanan."}
              </p>
              {user && addresses.length > 0 && (
                <div
                  className="saved-address-choice"
                  role="radiogroup"
                  aria-label="Alamat tersimpan"
                >
                  {addresses.map((address) => (
                    <label
                      className={choice === String(address.id) ? "selected" : ""}
                      key={address.id}
                    >
                      <input
                        type="radio"
                        name="savedAddress"
                        value={address.id}
                        checked={choice === String(address.id)}
                        onChange={() => { setAddressChoice(String(address.id)); setInvalidFields([]); setError(""); }}
                      />
                      <span>
                        <strong>
                          {address.label} · {address.recipient}
                        </strong>
                        <small>
                          {address.phone && <>{address.phone} · </>}
                          {address.address}, {address.city} {address.postal}
                        </small>
                      </span>
                      {address.isDefault && <b>Utama</b>}
                    </label>
                  ))}
                  <label className={choice === "new" ? "selected" : ""}>
                    <input
                      type="radio"
                      name="savedAddress"
                      value="new"
                      checked={choice === "new"}
                      onChange={() => { setAddressChoice("new"); setInvalidFields([]); setError(""); }}
                    />
                    <span>
                      <strong>Kirim ke alamat baru</strong>
                      <small>Isi nama penerima, nomor telepon, dan alamat tujuan.</small>
                    </span>
                  </label>
                </div>
              )}
              {chosenAddress ? (
                // Alamat tersimpan sudah berisi penerima, telepon, dan wilayah: tidak perlu diisi ulang.
                <>
                  <input type="hidden" name="email" value={user?.email || ""} />
                  {!chosenAddress.phone && (
                    <div className="form-grid">
                      <label>
                        Nomor telepon penerima
                        <input
                          name="phone"
                          required
                          inputMode="tel"
                          autoComplete="tel"
                          pattern="[0-9+ -]{8,20}"
                          defaultValue={suggestedPhone}
                        />
                      </label>
                    </div>
                  )}
                </>
              ) : (
              <div className="form-grid">
                <label>
                  Nama lengkap
                  <input
                    name="name"
                    required
                    maxLength="100"
                    autoComplete="name"
                    aria-invalid={invalidFields.includes("name")}
                    aria-describedby={invalidFields.includes("name") ? "checkout-error" : undefined}
                    defaultValue={user?.name || ""}
                  />
                </label>
                <label>
                  Email
                  <input
                    name="email"
                    required
                    type="email"
                    autoComplete="email"
                    aria-invalid={invalidFields.includes("email")}
                    aria-describedby={invalidFields.includes("email") ? "checkout-error" : undefined}
                    defaultValue={user?.email || ""}
                    readOnly={Boolean(user)}
                  />
                </label>
                <label>
                  Nomor telepon
                  <input
                    name="phone"
                    required
                    inputMode="tel"
                    autoComplete="tel"
                    aria-invalid={invalidFields.includes("phone")}
                    aria-describedby={invalidFields.includes("phone") ? "checkout-error" : undefined}
                    pattern="[0-9+ -]{8,20}"
                    defaultValue={suggestedPhone}
                  />
                </label>
                <label className="span-2">
                  Alamat lengkap
                  <textarea
                    name="address"
                    required
                    maxLength="255"
                    autoComplete="street-address"
                    aria-invalid={invalidFields.includes("address")}
                    aria-describedby={invalidFields.includes("address") ? "checkout-error" : undefined}
                  />
                </label>
                <RegionFields
                  initial={{}}
                  searchable
                  invalidFields={invalidFields}
                  errorId="checkout-error"
                />
              </div>
              )}
              <Feedback id="checkout-error">{error}</Feedback>
              <Button
                type="submit"
                disabled={busy}
                aria-busy={operation === "quote"}
              >
                {operation === "quote" ? (
                  "Memvalidasi keranjang dan alamat…"
                ) : (
                  <>
                    Validasi & pilih pengiriman <Icon name="arrow" />
                  </>
                )}
              </Button>
            </form>
          )}
          {step === 2 && (
            <div>
              <h2>Pilih layanan pengiriman</h2>
              <p>
                {serverShipping?.provider === "GUTSHOES_REGIONAL"
                  ? "Tujuan di luar Jabodetabek memakai tarif regional tetap per item dan seluruh ongkir dibayar pembeli."
                  : "Pilih kurir yang kamu suka. Biaya dihitung dari berat paket dan alamat tujuan."}
              </p>
              <div className="shipping-options">
                {serverShipping?.provider === "GUTSHOES_REGIONAL" ? (
                  <label className="selected">
                    <input type="radio" name="shipping" checked readOnly />
                    <Icon name="truck" />
                    <span>
                      <strong>Tarif regional per item</strong>
                      <small>
                        {serverShipping.description} ·{" "}
                        {cart.reduce((sum, item) => sum + item.qty, 0)} item
                      </small>
                    </span>
                    <b>{rupiah(Number(serverShipping.fee))}</b>
                  </label>
                ) : (
                  shippingOptions.map((option) => (
                    <label
                      className={
                        shippingService === shippingKey(option) ? "selected" : ""
                      }
                      key={shippingKey(option)}
                    >
                      <input
                        type="radio"
                        name="shipping"
                        checked={shippingService === shippingKey(option)}
                        onChange={() => changeShipping(shippingKey(option))}
                        disabled={busy}
                      />
                      <Icon name="truck" />
                      <span>
                        <strong>{option.description || option.service}</strong>
                        <small>
                          {option.estimated_days ? `Estimasi ${option.estimated_days} hari` : "Estimasi mengikuti kurir"}
                        </small>
                      </span>
                      <b>
                        {rupiah(
                          Number(option.fee || 0),
                        )}
                      </b>
                    </label>
                  ))
                )}
              </div>
              <div className="voucher">
                <label>
                  Kode voucher
                  <input
                    value={voucher}
                    onChange={(event) => setVoucher(event.target.value)}
                    maxLength="64"
                    autoComplete="off"
                    placeholder="Masukkan kode"
                    disabled={busy}
                  />
                </label>
                <Button
                  variant="secondary"
                  onClick={applyVoucher}
                  disabled={busy}
                  aria-busy={operation === "voucher"}
                >
                  {operation === "voucher" ? "Memeriksa…" : "Terapkan"}
                </Button>
              </div>
              {appliedVoucher && (
                <p className="voucher-result">
                  Kode <strong>{appliedVoucher}</strong>{" "}
                  {Number(shownPricing?.voucher_discount) > 0
                    ? "diterapkan."
                    : "valid tanpa potongan tambahan."}
                </p>
              )}
              <Feedback>{error}</Feedback>
              <div className="form-actions">
                <Button
                  variant="ghost"
                  onClick={() => setStep(1)}
                  disabled={busy}
                >
                  Kembali
                </Button>
                <Button
                  onClick={() => {
                    setError("");
                    setStep(3);
                  }}
                  disabled={busy || !shownPricing}
                >
                  Tinjau pembayaran <Icon name="arrow" />
                </Button>
              </div>
            </div>
          )}
          {step === 3 && (
            <div>
              <h2>Metode pembayaran</h2>
              <p>
                Pesananmu dibuat lebih dulu, lalu kamu diarahkan ke halaman pembayaran Midtrans.
              </p>
              <div className="payment-choice selected">
                <span className="midtrans-mark">M</span>
                <span>
                  <strong>Bayar melalui Midtrans</strong>
                  <small>
                    Pilihan metode dilakukan di halaman Midtrans. Kembali ke
                    GutShoes tidak berarti pembayaran berhasil.
                  </small>
                </span>
                <Icon name="check" />
              </div>
              <div className="review-box">
                <h3>Pembayaran aman</h3>
                <p>
                  Stok dan total diperiksa ulang saat pesanan dibuat. Status
                  pembayaran berubah menjadi lunas setelah Midtrans
                  mengonfirmasi pembayaranmu.
                </p>
              </div>
              <Feedback>{error}</Feedback>

              <div className="form-actions">
                <Button
                  variant="ghost"
                  onClick={() => setStep(2)}
                  disabled={busy}
                >
                  Kembali
                </Button>
                <Button
                  onClick={submitOrder}
                  disabled={busy}
                  aria-busy={busy}
                >
                  {operation === "order" ? (
                    "Membuat pesanan…"
                  ) : operation === "payment" ? (
                    "Menyiapkan pembayaran…"
                  ) : (
                    <>
                      Buat pesanan & lanjut bayar <Icon name="arrow" />
                    </>
                  )}
                </Button>
              </div>
            </div>
          )}
        </section>
        <OrderSummary
          cart={cart}
          pricing={shownPricing}
          totalLabel="Total pembayaran"
        />
      </div>
    </main>
  );
}

export default Checkout;

