<script setup lang="ts">
// Status z kolorem ze słownika SZO. Kolor (dowolny hex z bazy) jest tylko kropką — tekst ma zawsze pełny kontrast,
// więc znaczenie nie zależy od koloru (WCAG 1.4.1 i 1.4.3).
defineProps<{ label: string, color?: string | null }>()
</script>

<template>
  <span class="inline-flex items-center gap-1.5 rounded-full border border-default bg-default px-2.5 py-0.5 text-sm font-medium text-highlighted whitespace-nowrap">
    <span class="size-2.5 shrink-0 rounded-full" :style="{ background: color || 'var(--ui-color-neutral-500)' }" aria-hidden="true" />
    {{ label }}
  </span>
</template>
