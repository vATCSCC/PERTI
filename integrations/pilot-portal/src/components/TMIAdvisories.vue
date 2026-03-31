<template>
  <div class="card">
    <h3>{{ $t('tmi.title') }}</h3>
    <div v-if="store.programs.length">
      <div v-for="p in store.programs" :key="p.program_id" class="program">
        <strong>{{ $t('tmi.' + p.program_type.toLowerCase()) }}</strong> — {{ p.airport }}
        <span class="badge">{{ p.status }}</span>
      </div>
    </div>
    <p v-else>{{ $t('tmi.no_active') }}</p>
  </div>
</template>

<script setup>
import { useFlightStore } from '../stores/flight.js'
const store = useFlightStore()
</script>

<style scoped>
.card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; }
.program { margin: 8px 0; }
.badge { background: #ffc107; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px; }
</style>
