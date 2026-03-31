<template>
  <div class="app">
    <header>
      <h1>{{ $t('app.title') }}</h1>
      <p>{{ $t('app.subtitle') }}</p>
      <div class="status" :class="{ connected: ws.connected.value }">
        {{ ws.connected.value ? $t('status.connected') : $t('status.disconnected') }}
      </div>
    </header>

    <div class="callsign-input" v-if="!store.callsign">
      <input
        v-model="inputCallsign"
        :placeholder="$t('flight.enter_callsign')"
        @keyup.enter="lookupFlight"
      />
      <button @click="lookupFlight">{{ $t('common.loading').replace('...', '') }}</button>
    </div>

    <div class="dashboard" v-else>
      <FlightStatus />
      <TOSFiling />
      <TMIAdvisories />
      <AMANSequence />
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useFlightStore } from './stores/flight.js'
import { useWebSocket } from './composables/useWebSocket.js'
import FlightStatus from './components/FlightStatus.vue'
import TOSFiling from './components/TOSFiling.vue'
import TMIAdvisories from './components/TMIAdvisories.vue'
import AMANSequence from './components/AMANSequence.vue'

const store = useFlightStore()
const ws = useWebSocket()
const inputCallsign = ref('')

function lookupFlight() {
  if (inputCallsign.value.trim()) {
    store.loadFlight(inputCallsign.value.trim().toUpperCase())
    store.loadTOS()
    store.loadPrograms()
  }
}

// React to WebSocket events
watch(() => ws.lastEvent.value, (event) => {
  if (!event) return
  // Refresh data on relevant events
  if (event.type?.startsWith('cdm.') || event.type?.startsWith('tmi.')) {
    if (store.callsign) {
      store.loadFlight(store.callsign)
      store.loadTOS()
    }
    store.loadPrograms()
  }
})
</script>

<style>
.app { max-width: 800px; margin: 0 auto; padding: 20px; font-family: system-ui, sans-serif; }
header { text-align: center; margin-bottom: 24px; }
.status { font-size: 12px; color: #999; }
.status.connected { color: #4caf50; }
.callsign-input { text-align: center; margin: 40px 0; }
.callsign-input input { padding: 8px 12px; font-size: 16px; border: 1px solid #ccc; border-radius: 4px; }
.callsign-input button { padding: 8px 16px; margin-left: 8px; cursor: pointer; }
.dashboard { display: grid; gap: 16px; }
</style>
