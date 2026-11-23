import React from "react";
import {rupiah} from "../../data";
import {orderStatusView, statusLabel, statusTone} from "../lib/helpers";
import Badge from "./Badge";
import Icon from "./Icon";

function OrdersTable({rows, onOpen}) {
  return (
    <div className="adm-table-wrap">
      <table className="adm-responsive-table adm-card-table">
        <thead>
          <tr>
            <th>Pesanan</th>
            <th>Pelanggan</th>
            <th>Pembayaran</th>
            <th>Status</th>
            <th className="num">Total</th>
            <th>
              <span className="sr-only">Aksi</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((o) => {
            const view = orderStatusView(o.status, o.expiresAt);
            return (
            <tr key={o.id}>
              <td data-label="Pesanan">
                <strong>{o.id}</strong>
                <small>{o.time}</small>
              </td>
              <td data-label="Pelanggan">{o.customer}</td>
              <td data-label="Pembayaran">
                <Badge tone={o.payment ? statusTone(o.payment) : "neutral"}>{statusLabel(o.payment)}</Badge>
              </td>
              <td data-label="Status">
                <Badge tone={view.tone}>{view.label}</Badge>
                {view.hint && <small>{view.hint}</small>}
              </td>
              <td data-label="Total" className="num">
                <strong>{rupiah(o.total)}</strong>
              </td>
              <td data-label="Aksi">
                <button
                  className="adm-row-action"
                  onClick={() => onOpen?.(o)}
                  aria-label={`Buka ${o.id}`}
                >
                  <Icon name="arrow" />
                </button>
              </td>
            </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export default OrdersTable;
