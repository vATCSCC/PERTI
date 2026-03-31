<template>
  <div class="card">
    <h2>{{ store.flight?.callsign || store.callsign }}</h2>
    <div class="fields" v-if="store.flight">
      <div><span>{{ $t('flight.departure') }}:</span> {{ store.flight.fp_dep_icao }}</div>
      <div><span>{{ $t('flight.destination') }}:</span> {{ store.flight.fp_dest_icao }}</div>
      <div><span>{{ $t('flight.edct') }}:</span> {{ store.flight.edct_utc || $t('flight.no_edct') }}</div>
      <div><span>{{ $t('flight.ctot') }}:</span> {{ store.flight.controlled_time_of_departure || $t('flight.no_ctot') }}</div>
    </div>
    <p v-else-if="store.loading">{{ $t('common.loading') }}</p>
    <p v-else-if="store.error">{{ store.error }}</p>
  </div>
</template>

<script setup>
import { useFlightStore } from '../stores/flight.js'
const store = useFlightStore()
</script>

<style scoped>
.card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; }
.card h2 { margin: 0 0 12px; }
.fields div { margin: 4px 0; }
.fields span { font-weight: 600; }
</style>
