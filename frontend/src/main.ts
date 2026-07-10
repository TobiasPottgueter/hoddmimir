import { createPinia } from "pinia";
import Aura from "@primeuix/themes/aura";
import PrimeVue from "primevue/config";
import { createApp } from "vue";

import "primeicons/primeicons.css";

import App from "./App.vue";
import router from "./app/router";
import "./styles.css";

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.use(PrimeVue, {
  theme: {
    preset: Aura,
    options: {
      darkModeSelector: ".app-dark",
    },
  },
});

app.mount("#app");
