import { computed, ref } from "vue";
import { defineStore } from "pinia";

import {
  authApi,
  type AuthApi,
  type AuthPermission,
  type AuthPrincipal,
} from "@/api/authApi";

export const useAuthStore = defineStore("auth", () => {
  const principal = ref<AuthPrincipal | null>(null);
  const csrfToken = ref<string | null>(null);
  const initialized = ref(false);
  const loading = ref(false);

  const authenticated = computed(() => principal.value !== null);
  const hasPermission = (permission: AuthPermission): boolean =>
    principal.value?.permissions.includes(permission) ?? false;

  function apply(session: Awaited<ReturnType<AuthApi["session"]>>): void {
    principal.value = session.user;
    csrfToken.value = session.csrfToken;
    initialized.value = true;
  }

  function clear(): void {
    principal.value = null;
    csrfToken.value = null;
    initialized.value = true;
  }

  async function restore(api: AuthApi = authApi): Promise<void> {
    if (initialized.value || loading.value) return;
    loading.value = true;
    try {
      apply(await api.session());
    } catch {
      clear();
    } finally {
      loading.value = false;
    }
  }

  async function login(
    username: string,
    password: string,
    api: AuthApi = authApi,
  ): Promise<void> {
    loading.value = true;
    try {
      apply(await api.login(username, password));
    } finally {
      loading.value = false;
    }
  }

  async function logout(api: AuthApi = authApi): Promise<void> {
    const token = csrfToken.value;
    try {
      if (token !== null) await api.logout(token);
    } finally {
      clear();
    }
  }

  return {
    principal,
    csrfToken,
    initialized,
    loading,
    authenticated,
    hasPermission,
    restore,
    login,
    logout,
    clear,
  };
});
