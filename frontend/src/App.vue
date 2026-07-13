<script setup lang="ts">
import { computed } from "vue";
import { RouterView, useRoute } from "vue-router";
import AppShell from "@/components/layout/AppShell.vue";

const route = useRoute();
const publicLayout = computed(() => route.meta.public === true);
</script>

<template>
  <RouterView v-if="publicLayout" />
  <AppShell v-else />
</template>
