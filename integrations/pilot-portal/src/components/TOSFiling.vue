<template>
  <div class="card">
    <h3>{{ $t('tos.title') }}</h3>

    <div v-if="store.tosOptions.length" class="tos-list">
      <div v-for="opt in store.tosOptions" :key="opt.tos_id" class="tos-option">
        <strong>{{ $t('tos.option', { rank: opt.option_rank }) }}</strong>
        <div>{{ $t('tos.route') }}: {{ opt.route_string }}</div>
        <div>{{ $t('tos.status') }}: {{ $t('tos.' + opt.status.toLowerCase()) }}</div>
      </div>
    </div>
    <p v-else>{{ $t('tos.no_options') }}</p>

    <div class="tos-form" v-if="showForm">
      <div v-for="(opt, i) in newOptions" :key="i" class="form-row">
        <input v-model="opt.route" :placeholder="$t('tos.route')" />
        <input v-model.number="opt.flight_time_min" :placeholder="$t('tos.flight_time')" type="number" />
        <button @click="newOptions.splice(i, 1)">{{ $t('tos.remove_option') }}</button>
      </div>
      <button @click="addOption">{{ $t('tos.add_option') }}</button>
      <button @click="submit">{{ $t('tos.submit') }}</button>
    </div>
    <button v-else @click="showForm = true">{{ $t('tos.file') }}</button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useFlightStore } from '../stores/flight.js'

const store = useFlightStore()
const showForm = ref(false)
const newOptions = ref([{ route: '', flight_time_min: null, fuel_penalty_pct: null }])

function addOption() {
  newOptions.value.push({ route: '', flight_time_min: null, fuel_penalty_pct: null })
}

async function submit() {
  if (!store.flight) return
  try {
    await store.submitTOS(
      store.flight.fp_dep_icao,
      store.flight.fp_dest_icao,
      newOptions.value.filter(o => o.route.trim())
    )
    showForm.value = false
    store.loadTOS()
  } catch (e) {
    alert(e.message)
  }
}
</script>

<style scoped>
.card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px; }
.tos-option { margin: 8px 0; padding: 8px; background: #fff; border: 1px solid #e9ecef; border-radius: 4px; }
.form-row { display: flex; gap: 8px; margin: 4px 0; }
.form-row input { flex: 1; padding: 4px 8px; }
button { padding: 6px 12px; margin: 4px; cursor: pointer; }
</style>
