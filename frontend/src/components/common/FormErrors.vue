<script setup lang="ts">
import { nextTick, ref, watch } from "vue";
const props = defineProps<{
  errors: Record<string, string>;
  attempt: number;
}>();
const summary = ref<HTMLElement | null>(null);
watch(
  () => props.attempt,
  async () => {
    await nextTick();
    if (Object.values(props.errors).some(Boolean)) summary.value?.focus();
  },
);
function focusField(id: string): void {
  const field = document.getElementById(id);
  const details = field?.closest("details");
  if (details) details.open = true;
  field?.focus();
}
</script>
<template>
  <div
    v-if="Object.values(errors).some(Boolean)"
    ref="summary"
    class="form-errors"
    role="alert"
    tabindex="-1"
    aria-label="Bitte die Eingaben prüfen"
  >
    <strong>Bitte die Eingaben prüfen</strong>
    <ul>
      <template v-for="(message, id) in errors" :key="id"
        ><li v-if="message">
          <a :href="`#${id}`" @click.prevent="focusField(String(id))">{{
            message
          }}</a>
        </li></template
      >
    </ul>
  </div>
</template>
