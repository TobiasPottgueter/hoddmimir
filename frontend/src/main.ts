import { definePreset } from "@primeuix/themes";
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
    preset: definePreset(Aura, {
      semantic: {
        primary: Object.fromEntries(
          [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map(
            (level) => [level, `{blue.${level}}`],
          ),
        ),
        colorScheme: {
          light: {
            primary: {
              color: "{primary.700}",
              hoverColor: "{primary.800}",
              activeColor: "{primary.900}",
            },
          },
        },
      },
    }),
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
