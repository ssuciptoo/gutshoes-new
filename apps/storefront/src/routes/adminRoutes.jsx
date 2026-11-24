import React from "react";
import Dashboard from "../admin/pages/Dashboard";
import Products from "../admin/pages/Products";
import Catalog from "../admin/pages/Catalog";
import Sizes from "../admin/pages/Sizes";
import Inventory from "../admin/pages/Inventory";
import Orders from "../admin/pages/Orders";
import Refunds from "../admin/pages/Refunds";
import Customers from "../admin/pages/Customers";
import Promotions from "../admin/pages/Promotions";
import Settings from "../admin/pages/Settings";
import Audit from "../admin/pages/Audit";
import {REFUNDS_ENABLED} from "../lib/features";

export const adminRoutes = [
  {
    section: "dashboard",
    path: "/admin/dashboard",
    render: (context) => <Dashboard navigate={context.navigate} />,
  },
  {section: "products", path: "/admin/products", render: () => <Products />},
  {section: "catalog", path: "/admin/catalog", render: () => <Catalog />},
  {section: "sizes", path: "/admin/sizes", render: () => <Sizes />},
  {section: "inventory", path: "/admin/inventory", render: () => <Inventory />},
  {
    section: "orders",
    path: "/admin/orders",
    render: (context) => (
      <Orders key={JSON.stringify(context.params || {})} params={context.params} />
    ),
  },
  ...(REFUNDS_ENABLED
    ? [{section: "refunds", path: "/admin/refunds", render: () => <Refunds />}]
    : []),
  {
    section: "customers",
    path: "/admin/customers",
    render: (context) => <Customers navigate={context.navigate} />,
  },
  {
    section: "promotions",
    path: "/admin/promotions",
    render: () => <Promotions />,
  },
  {section: "settings", path: "/admin/settings", render: () => <Settings />},
  {section: "audit", path: "/admin/audit", render: () => <Audit />},
];

export function getAdminRoute(section) {
  return (
    adminRoutes.find((route) => route.section === section) || adminRoutes[0]
  );
}
