<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import Button from "primevue/button";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Password from "primevue/password";

import { useAuthStore } from "@/stores/auth";

const username = ref("");
const password = ref("");
const error = ref(false);
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

async function submit(): Promise<void> {
  error.value = false;
  try {
    await auth.login(username.value, password.value);
    const redirect =
      typeof route.query.redirect === "string" &&
      route.query.redirect.startsWith("/")
        ? route.query.redirect
        : "/";
    await router.replace(redirect);
  } catch {
    error.value = true;
    password.value = "";
  }
}
</script>

<template>
  <main class="login-page">
    <section class="login-card" aria-labelledby="login-title">
      <div class="login-brand">
        <i class="pi pi-shield" aria-hidden="true" /><span
          ><strong>Hoddmímir</strong><small>Backup Scheduler</small></span
        >
      </div>
      <h1 id="login-title">Anmelden</h1>
      <p>Verwende dein lokales Hoddmímir-Benutzerkonto.</p>
      <Message v-if="error" severity="error" :closable="false"
        >Anmeldung fehlgeschlagen.</Message
      >
      <form @submit.prevent="submit">
        <label for="username">Benutzername</label>
        <InputText
          id="username"
          v-model="username"
          autocomplete="username"
          required
          autofocus
        />
        <label for="password">Passwort</label>
        <Password
          id="password"
          v-model="password"
          input-id="password"
          autocomplete="current-password"
          :feedback="false"
          toggle-mask
          required
        />
        <Button
          type="submit"
          label="Anmelden"
          icon="pi pi-sign-in"
          :loading="auth.loading"
        />
      </form>
    </section>
  </main>
</template>
