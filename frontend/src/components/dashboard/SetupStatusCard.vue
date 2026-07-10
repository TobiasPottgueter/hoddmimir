<script setup lang="ts">
import Tag from "primevue/tag";
import { RouterLink } from "vue-router";

interface Props {
  title: string;
  description: string;
  icon: string;
  actionLabel: string;
  actionTo: string;
  configured?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  configured: false,
});
</script>

<template>
  <article class="setup-card">
    <div class="setup-card__header">
      <span class="setup-card__icon" aria-hidden="true">
        <i :class="props.icon" />
      </span>
      <Tag
        :value="props.configured ? 'Eingerichtet' : 'Offen'"
        :severity="props.configured ? 'success' : 'warn'"
      />
    </div>

    <div>
      <h3>{{ props.title }}</h3>
      <p>{{ props.description }}</p>
    </div>

    <RouterLink class="setup-card__action" :to="props.actionTo">
      {{ props.actionLabel }}
      <i class="pi pi-arrow-right" aria-hidden="true" />
    </RouterLink>
  </article>
</template>
