import React, {Children, isValidElement, useEffect, useId, useRef, useState} from "react";
import * as RadixSelect from "@radix-ui/react-select";

// Radix tidak mengizinkan nilai "" pada item, padahal opsi seperti "Semua status" atau
// "Pilih merek" memakai "". Nilai kosong dipetakan ke penanda ini dan dikembalikan sebagai "".
const EMPTY = "__empty__";
const toRadix = (value) => (value === "" || value == null ? EMPTY : String(value));
const fromRadix = (value) => (value === EMPTY ? "" : value);

function readOptions(children) {
  return Children.toArray(children)
    .filter((child) => isValidElement(child) && child.type === "option")
    .map((child) => ({
      value: toRadix(child.props.value ?? ""),
      label: Children.toArray(child.props.children).join(""),
      disabled: Boolean(child.props.disabled),
    }));
}

function Chevron() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}

function Check() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

/**
 * Pengganti <select> dengan daftar pilihan yang selalu muat di layar.
 * Pemakaian sama seperti <select>: anak berupa <option>, onChange menerima event
 * dengan event.target.value, serta mendukung name, required, dan disabled untuk form.
 */
function Select({value, onChange, children, disabled, required, name, className = "", id, ...aria}) {
  const allOptions = readOptions(children);
  const emptyOption = allOptions.find((option) => option.value === EMPTY);
  // Pada select wajib, opsi kosong ("Pilih merek") adalah placeholder, bukan pilihan: nilai
  // dikirim ke Radix sebagai "" agar validasi required pada form tetap berjalan.
  // Pada select opsional, opsi kosong ("Semua status") adalah pilihan biasa.
  const placeholderMode = Boolean(required);
  const options = placeholderMode ? allOptions.filter((option) => option.value !== EMPTY) : allOptions;
  const current = placeholderMode && toRadix(value) === EMPTY ? "" : toRadix(value);
  const selected = options.find((option) => option.value === current);
  const generatedId = useId();
  const triggerRef = useRef(null);
  const [invalid, setInvalid] = useState(false);
  // Radix menaruh <select> tersembunyi di samping trigger untuk validasi form. Saat form
  // ditolak karena select wajib kosong, fokus dipindah ke trigger dan border ditandai merah.
  useEffect(() => {
    const trigger = triggerRef.current;
    const parent = trigger?.parentElement;
    if (!required || !parent) return undefined;
    // Event "invalid" tidak bubble, tetapi bisa ditangkap di fase capture oleh elemen induk.
    const handle = (event) => {
      if (event.target.tagName !== "SELECT" || event.target.parentElement !== parent) return;
      if (name && event.target.name !== name) return;
      setInvalid(true);
      trigger.focus();
    };
    parent.addEventListener("invalid", handle, true);
    return () => parent.removeEventListener("invalid", handle, true);
  }, [required, name]);
  const change = (next) => {
    setInvalid(false);
    const plain = fromRadix(next);
    onChange?.({target: {value: plain, name}, currentTarget: {value: plain, name}});
  };
  return (
    <RadixSelect.Root value={current} onValueChange={change} disabled={disabled} required={required} name={name}>
      <RadixSelect.Trigger
        ref={triggerRef}
        id={id || generatedId}
        className={`ui-select-trigger ${className}`.trim()}
        aria-invalid={invalid || undefined}
        {...aria}
      >
        <span className="ui-select-value">
          <RadixSelect.Value placeholder={emptyOption?.label}>{selected?.label ?? ""}</RadixSelect.Value>
        </span>
        <RadixSelect.Icon className="ui-select-icon">
          <Chevron />
        </RadixSelect.Icon>
      </RadixSelect.Trigger>
      <RadixSelect.Portal>
        <RadixSelect.Content className="ui-select-content" position="popper" sideOffset={6} collisionPadding={12}>
          <RadixSelect.ScrollUpButton className="ui-select-scroll">
            <Chevron />
          </RadixSelect.ScrollUpButton>
          <RadixSelect.Viewport className="ui-select-viewport">
            {options.map((option) => (
              <RadixSelect.Item key={option.value} value={option.value} disabled={option.disabled} className="ui-select-item">
                <RadixSelect.ItemText>{option.label}</RadixSelect.ItemText>
                <RadixSelect.ItemIndicator className="ui-select-check">
                  <Check />
                </RadixSelect.ItemIndicator>
              </RadixSelect.Item>
            ))}
          </RadixSelect.Viewport>
          <RadixSelect.ScrollDownButton className="ui-select-scroll is-down">
            <Chevron />
          </RadixSelect.ScrollDownButton>
        </RadixSelect.Content>
      </RadixSelect.Portal>
    </RadixSelect.Root>
  );
}

export default Select;
