import React, {useEffect, useState} from "react";
import {api} from "../../api";
import {rupiah} from "../../data";
import useDialogFocus from "../hooks/useDialogFocus";
import {apiErrorMessage, statusLabel, statusTone} from "../lib/helpers";
import Badge from "./Badge";
import Button from "./Button";
import Icon from "./Icon";

function CustomerDrawer({customerId, onClose}) {
  const dialogRef = useDialogFocus(onClose);
  const [detail, setDetail] = useState(null);
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    setError("");
    api
      .adminCustomer(customerId)
      .then((response) => active && setDetail(response.data))
      .catch((requestError) => active && setError(apiErrorMessage(requestError, "Detail pelanggan gagal dimuat.")));
    return () => {
      active = false;
    };
  }, [customerId, attempt]);

  const customer = detail?.customer;
  return (
    <div
      className="adm-overlay"
      role="presentation"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <aside
        ref={dialogRef}
        className="adm-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="customer-detail-title"
      >
        <header>
          <div>
            <small>PELANGGAN</small>
            <h2 id="customer-detail-title">{customer?.name || (error ? "Pelanggan" : "Memuat…")}</h2>
          </div>
          <button onClick={onClose} aria-label="Tutup detail pelanggan">
            <Icon name="close" />
          </button>
        </header>
        {error && (
          <div className="adm-feedback error adm-order-feedback" role="alert">
            {error}
            <button type="button" onClick={() => setAttempt((value) => value + 1)}>
              Coba lagi
            </button>
          </div>
        )}
        {!detail && !error && (
          <div className="adm-resource-state" role="status">Memuat detail pelanggan…</div>
        )}
        {detail && (
          <>
            <section>
              <h3>Kontak</h3>
              <p>
                {customer?.email}
                {customer?.email_verified_at ? " · terverifikasi" : " · belum verifikasi"}
                <br />
                {customer?.customer_profile?.phone || "Telepon belum tersedia"}
              </p>
            </section>
            <section>
              <h3>Ringkasan</h3>
              <div className="adm-line-item">
                <span>
                  {detail.summary?.order_count || 0} pesanan
                  <small>Total belanja terbayar</small>
                </span>
                <strong>{rupiah(Number(detail.summary?.paid_total || 0))}</strong>
              </div>
            </section>
            <section>
              <h3>Alamat</h3>
              {customer?.addresses?.length ? (
                customer.addresses.map((address) => (
                  <p key={address.id}>
                    <strong>{address.label}</strong>: {address.address_line}, {address.district}, {address.city}{" "}
                    {address.postal_code}
                  </p>
                ))
              ) : (
                <p>Belum ada alamat.</p>
              )}
            </section>
            <section>
              <h3>Pesanan terakhir</h3>
              {detail.orders?.length ? (
                detail.orders.map((order) => (
                  <div className="adm-line-item adm-order-item" key={order.id}>
                    <span>
                      {order.order_number}
                      <small>
                        <Badge tone={statusTone(order.status)}>{statusLabel(order.status)}</Badge>
                      </small>
                    </span>
                    <strong>{rupiah(Number(order.grand_total || 0))}</strong>
                  </div>
                ))
              ) : (
                <p>Belum ada pesanan.</p>
              )}
            </section>
          </>
        )}
        <footer>
          <Button kind="secondary" onClick={onClose}>
            Tutup
          </Button>
        </footer>
      </aside>
    </div>
  );
}

export default CustomerDrawer;
