<script setup lang="ts">
import { pl } from '@nuxt/ui/locale'

useHead({ titleTemplate: t => (t && t !== 'CRM SZO' ? `${t} — CRM SZO` : 'CRM SZO') })
</script>

<template>
  <UApp :locale="pl" :toaster="{ position: 'bottom-right' }">
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
