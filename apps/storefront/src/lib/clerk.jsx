import React, {useEffect} from "react";
import {ClerkProvider, useAuth, useClerk} from "@clerk/react";
import {idID} from "@clerk/localizations/id-ID";
import {setAuthTokenGetter} from "../api";

// Login pelanggan memakai Clerk. Tanpa VITE_CLERK_PUBLISHABLE_KEY, toko tetap berjalan
// untuk belanja tanpa akun dan tombol login menampilkan status tidak tersedia.
const publishableKey = import.meta.env.VITE_CLERK_PUBLISHABLE_KEY || "";
export const clerkEnabled = Boolean(publishableKey);

let clerkInstance = null;

// Paket id-ID Clerk belum menerjemahkan placeholder kolom masuk/daftar; lengkapi di sini.
const localization = {
  ...idID,
  formFieldInputPlaceholder__emailAddress: "nama@email.com",
  formFieldInputPlaceholder__emailAddress_username: "Masukkan email atau nama pengguna",
  formFieldInputPlaceholder__username: "Masukkan nama pengguna",
  formFieldInputPlaceholder__firstName: "Nama depan",
  formFieldInputPlaceholder__lastName: "Nama belakang",
  formFieldInputPlaceholder__password: "Masukkan kata sandi",
  formFieldInputPlaceholder__signUpPassword: "Buat kata sandi",
  formFieldInputPlaceholder__phoneNumber: "Nomor telepon",
  formFieldInputPlaceholder__backupCode: "Kode cadangan",
};

export function AuthProvider({children}) {
  if (!clerkEnabled) return children;
  return (
    <ClerkProvider publishableKey={publishableKey} localization={localization}>
      {children}
    </ClerkProvider>
  );
}

// Menghubungkan sesi Clerk ke API toko: token dikirim di setiap request pelanggan dan
// App diberi tahu setiap kali pelanggan masuk/keluar (onChange: null saat Clerk belum siap).
export function ClerkSessionBridge({onChange}) {
  const clerk = useClerk();
  const {isLoaded, isSignedIn, userId, getToken} = useAuth();

  useEffect(() => {
    clerkInstance = clerk;
    setAuthTokenGetter(() => getToken());
    return () => {
      clerkInstance = null;
      setAuthTokenGetter(null);
    };
  }, [clerk, getToken]);

  useEffect(() => {
    onChange(isLoaded ? (isSignedIn ? `clerk:${userId}` : "signed-out") : null);
  }, [isLoaded, isSignedIn, userId, onChange]);

  return null;
}

// Membuka modal masuk/daftar Clerk. Modal tidak mengubah hash URL, jadi tidak bentrok
// dengan router toko; setelah berhasil pelanggan diarahkan ke halaman tujuan (returnTo).
function useClerkSignIn() {
  const clerk = useClerk();
  return (returnTo = "/profile") => {
    const target = `${window.location.origin}${returnTo.startsWith("/") ? returnTo : "/profile"}`;
    clerk.openSignIn({forceRedirectUrl: target, signUpForceRedirectUrl: target});
  };
}
export const useSignInLauncher = clerkEnabled ? useClerkSignIn : () => null;

export async function clerkSignOut() {
  if (clerkInstance) await clerkInstance.signOut();
}
