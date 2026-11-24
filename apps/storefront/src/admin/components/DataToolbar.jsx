import React from "react";
import Icon from "./Icon";
import Select from "../../components/ui/Select";

function DataToolbar({
  query,
  setQuery,
  filter,
  setFilter,
  options = ["Semua status", "Aktif", "Draf"],
  placeholder = "Cari data…",
  searchLabel = "Cari data",
  filterLabel = "Filter status",
}) {
  return (
    <div className="adm-toolbar">
      <label className="adm-search">
        <Icon name="search" />
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={placeholder}
          aria-label={searchLabel}
        />
      </label>
      <Select
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        aria-label={filterLabel}
      >
        {options.map((option) => {
          const [value, label] = Array.isArray(option) ? option : [option, option];
          return (
            <option key={value} value={value}>
              {label}
            </option>
          );
        })}
      </Select>
    </div>
  );
}

export default DataToolbar;
