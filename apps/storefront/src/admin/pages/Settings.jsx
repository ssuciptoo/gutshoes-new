import React from "react";
import {api} from "../../api";
import useAdminResource from "../hooks/useAdminResource";
import {serverList} from "../lib/helpers";
import PageHead from "../components/PageHead";
import ResourceState from "../components/ResourceState";
import SettingsForm from "../components/SettingsForm";
import WarehouseForm from "../components/WarehouseForm";

function SettingsPage() {
  const resource = useAdminResource(api.adminConfigurations);
  const warehouse = useAdminResource(api.adminWarehouse);
  return (
    <>
      <PageHead section="settings" />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <SettingsForm items={serverList(resource.data)} reload={resource.retry} />
      )}
      <ResourceState {...warehouse} />
      {!warehouse.loading && !warehouse.error && (
        <WarehouseForm warehouse={warehouse.data} reload={warehouse.retry} />
      )}
    </>
  );
}

export default SettingsPage;
