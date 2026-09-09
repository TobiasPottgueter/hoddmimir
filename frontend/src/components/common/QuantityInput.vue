<script setup lang="ts">
import { computed, ref, watch } from "vue";
import InputText from "primevue/inputtext";
import {
  parseQuantity,
  quantityInUnit,
  quantityUnits,
} from "@/composables/useExactQuantity";
const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    kind: "bytes" | "duration";
    modelValue: string;
    error?: string;
    hint?: string;
  }>(),
  { error: "", hint: "Leer lassen, um keine eigene Vorgabe zu setzen." },
);
const emit = defineEmits<{
  "update:modelValue": [value: string];
  validation: [error: string];
}>();
const units = computed(() => quantityUnits[props.kind]);
const unitIndex = ref(0);
const draft = ref("");
const localError = ref("");
const notice = ref("");
const touched = ref(false);
const message = computed(
  () => props.error || (touched.value ? localError.value : ""),
);
watch(
  () => props.modelValue,
  (value) => {
    const converted = quantityInUnit(
      value,
      units.value[unitIndex.value]!.factor,
    );
    if (converted === null) unitIndex.value = 0;
    draft.value = converted ?? value;
  },
  { immediate: true },
);
function input(value: string | undefined): void {
  draft.value = value ?? "";
  const parsed = parseQuantity(
    draft.value,
    units.value[unitIndex.value]!.factor,
  );
  localError.value = parsed.error ?? "";
  notice.value = "";
  emit("validation", localError.value);
  if (parsed.value !== null) emit("update:modelValue", parsed.value);
}
function changeUnit(event: Event): void {
  const select = event.target as HTMLSelectElement;
  const next = Number(select.value);
  const converted = localError.value
    ? null
    : quantityInUnit(props.modelValue, units.value[next]!.factor);
  if (converted === null) {
    select.value = String(unitIndex.value);
    notice.value =
      "Diese Umrechnung würde den Wert runden. Bitte die bisherige Einheit verwenden oder den Eingabewert korrigieren.";
    return;
  }
  unitIndex.value = next;
  draft.value = converted;
  notice.value = "";
}
</script>
<template>
  <div class="quantity-field">
    <label :for="id">{{ label }}</label>
    <div class="quantity-input">
      <InputText
        :id="id"
        :model-value="draft"
        inputmode="decimal"
        :aria-invalid="message ? true : undefined"
        :aria-describedby="`${id}-hint${message ? ` ${id}-error` : ''}`"
        @update:model-value="input"
        @blur="touched = true"
      />
      <select
        :value="unitIndex"
        :aria-label="`Einheit für ${label}`"
        @change="changeUnit"
      >
        <option v-for="(unit, index) in units" :key="unit.label" :value="index">
          {{ unit.label }}
        </option>
      </select>
    </div>
    <small :id="`${id}-hint`">{{ hint }}</small>
    <small v-if="message" :id="`${id}-error`" class="field-error">{{
      message
    }}</small>
    <small v-if="notice" role="status">{{ notice }}</small>
  </div>
</template>
