<script setup lang="ts">
// Podsumowanie błędów nad formularzem (WCAG 3.3.1): lista z odnośnikami do pól; po wysłaniu dostaje fokus.
const props = defineProps<{ errors: Record<string, string>, labels: Record<string, string>, message?: string }>()
const box = ref<HTMLElement | null>(null)
const entries = computed(() => Object.entries(props.errors))
watch(() => props.errors, async (v) => {
  if (Object.keys(v).length) { await nextTick(); box.value?.focus() }
}, { deep: true })
function jump(field: string) {
  // pola Nuxt UI (UFormField name=…) przekazują nazwę do kontrolki
  const el = document.querySelector<HTMLElement>(`[name="${CSS.escape(field)}"]`)
  el?.focus()
}
</script>

<template>
  <div v-if="entries.length || message" ref="box" tabindex="-1" role="alert" aria-labelledby="form-errors-title"
       class="mb-6 rounded-lg border-2 border-error bg-error/5 p-4">
    <p id="form-errors-title" class="font-semibold text-error">{{ message || 'Popraw zaznaczone pola' }}</p>
    <ul v-if="entries.length" class="mt-2 list-disc space-y-1 pl-5">
      <li v-for="[field, msg] in entries" :key="field">
        <button type="button" class="text-left underline underline-offset-2" @click="jump(field)">{{ labels[field] || field }}: {{ msg }}</button>
      </li>
    </ul>
  </div>
</template>
