import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchFlight, fetchTOSStatus, fileTOS, fetchActivePrograms } from '../api/swim.js'

export const useFlightStore = defineStore('flight', () => {
  const callsign = ref('')
  const flight = ref(null)
  const tosOptions = ref([])
  const programs = ref([])
  const loading = ref(false)
  const error = ref(null)

  async function loadFlight(cs) {
    callsign.value = cs
    loading.value = true
    error.value = null
    try {
      const data = await fetchFlight(cs)
      flight.value = data.flight || data
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  async function loadTOS() {
    if (!callsign.value) return
    try {
      const data = await fetchTOSStatus(callsign.value)
      tosOptions.value = data.options || []
    } catch { /* silent */ }
  }

  async function submitTOS(departure, destination, options) {
    return fileTOS(callsign.value, departure, destination, options)
  }

  async function loadPrograms() {
    try {
      const data = await fetchActivePrograms()
      programs.value = data.programs || []
    } catch { /* silent */ }
  }

  return {
    callsign, flight, tosOptions, programs,
    loading, error,
    loadFlight, loadTOS, submitTOS, loadPrograms,
  }
})
