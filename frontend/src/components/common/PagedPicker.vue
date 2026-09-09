<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Select from "primevue/select";
import Button from "primevue/button";
import { apiErrorMessage } from "@/api/errors";
import { pageContinuation, type CursorPage } from "@/api/pagination";
export interface PickerOption {
  id: string;
  label: string;
}
const props = defineProps<{
  modelValue: string;
  label: string;
  id?: string;
  error?: string;
  loadPage: (cursor?: string) => Promise<CursorPage<PickerOption>>;
}>();
const emit = defineEmits<{ "update:modelValue": [value: string] }>();
const items = ref<PickerOption[]>([]);
const loading = ref(false);
const loaded = ref(false);
const loadError = ref<string | null>(null);
const cursor = ref<string>();
let generation = 0;
const options = computed(() =>
  props.modelValue && !items.value.some((item) => item.id === props.modelValue)
    ? [
        { id: props.modelValue, label: `Ausgewählt: ${props.modelValue}` },
        ...items.value,
      ]
    : items.value,
);
watch(
  () => props.loadPage,
  () => {
    generation++;
    items.value = [];
    cursor.value = undefined;
    loaded.value = false;
    loading.value = false;
    loadError.value = null;
  },
);
async function load(more = false) {
  if (loading.value || (!more && loaded.value)) return;
  const current = generation;
  loading.value = true;
  loadError.value = null;
  try {
    const result = await props.loadPage(more ? cursor.value : undefined);
    if (current !== generation) return;
    const next = pageContinuation(result.page, more ? cursor.value : undefined);
    items.value = [
      ...new Map(
        (more ? [...items.value, ...result.items] : result.items).map(
          (item) => [item.id, item],
        ),
      ).values(),
    ];
    cursor.value = next;
    loaded.value = true;
  } catch (caught) {
    if (current === generation) loadError.value = apiErrorMessage(caught);
  } finally {
    if (current === generation) loading.value = false;
  }
}
</script>
<template>
  <div class="paged-picker">
    <Select
      :model-value="modelValue || null"
      :options="options"
      option-label="label"
      option-value="id"
      :aria-label="label"
      :input-id="id"
      :aria-invalid="!!props.error || undefined"
      :aria-describedby="props.error && id ? `${id}-error` : undefined"
      :placeholder="label"
      filter
      show-clear
      :loading="loading"
      :virtual-scroller-options="{ itemSize: 44 }"
      @show="load()"
      @update:model-value="emit('update:modelValue', $event ?? '')"
    >
      <template #option="{ option }"
        ><span class="picker-option" :title="option.label">{{
          option.label
        }}</span></template
      >
      <template #empty>Keine passenden geladenen Einträge.</template>
    </Select>
    <small
      v-if="props.error"
      :id="id ? `${id}-error` : undefined"
      class="field-error"
      >{{ props.error }}</small
    >
    <small v-if="cursor"
      >Suche in {{ items.length }} geladenen Einträgen.</small
    >
    <Button
      v-if="cursor && !loadError"
      type="button"
      size="small"
      severity="secondary"
      label="Weitere Auswahlmöglichkeiten laden"
      :loading="loading"
      @click="load(true)"
    />
    <div v-if="loadError" role="alert">
      <small>{{ loadError }}</small
      ><Button
        type="button"
        label="Auswahl erneut laden"
        severity="secondary"
        :loading="loading"
        @click="load(loaded)"
      />
    </div>
  </div>
</template>
