<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();
const title = computed(() =>
  typeof route.meta.title === "string" ? route.meta.title : "Bereich",
);
const description = computed(() =>
  typeof route.meta.description === "string"
    ? route.meta.description
    : "Dieser Bereich wird in einer späteren Ausbaustufe umgesetzt.",
);
</script>

<template>
  <section class="placeholder-view">
    <span class="placeholder-view__icon" aria-hidden="true">
      <i class="pi pi-wrench" />
    </span>
    <span class="section-kicker">Grundgerüst</span>
    <h2>{{ title }}</h2>
    <p>{{ description }}</p>
    <small
      >Die Oberfläche wird mit dem zugehörigen API-Vertrag implementiert.</small
    >
  </section>
</template>
