import Aura from "@primeuix/themes/aura";
import PrimeVue from "primevue/config";
import { createApp } from "vue";

import "primeicons/primeicons.css";

import App from "./App.vue";
import router from "./app/router";
import { pinia } from "./app/pinia";
import { useAuthStore } from "./stores/auth";
import "./styles.css";

const app = createApp(App);

app.use(pinia);
app.use(router);
app.use(PrimeVue, {
  theme: {
    preset: Aura,
    options: {
      darkModeSelector: ".app-dark",
    },
  },
});

window.addEventListener("hoddmimir:unauthorized", () => {
  useAuthStore(pinia).clear();
  if (router.currentRoute.value.name !== "login") {
    void router.replace({
      name: "login",
      query: { redirect: router.currentRoute.value.fullPath },
    });
  }
});

app.mount("#app");
