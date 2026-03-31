import { ref, onUnmounted } from 'vue'

export function useWebSocket(channels = ['cdm.*', 'aman.*', 'tmi.*']) {
  const connected = ref(false)
  const lastEvent = ref(null)
  let ws = null
  let reconnectTimer = null
  let attempt = 0

  const wsUrl = import.meta.env.VITE_WS_URL || 'wss://perti.vatcscc.org/ws/swim/v1'
  const apiKey = localStorage.getItem('SWIM_API_KEY') || ''

  function connect() {
    ws = new WebSocket(`${wsUrl}?apiKey=${encodeURIComponent(apiKey)}`)

    ws.onopen = () => {
      connected.value = true
      attempt = 0
      ws.send(JSON.stringify({ action: 'subscribe', channels }))
    }

    ws.onmessage = (event) => {
      try {
        lastEvent.value = JSON.parse(event.data)
      } catch { /* ignore parse errors */ }
    }

    ws.onclose = () => {
      connected.value = false
      scheduleReconnect()
    }

    ws.onerror = () => {
      connected.value = false
    }
  }

  function scheduleReconnect() {
    const delay = Math.min(1000 * Math.pow(2, attempt), 300000)
    attempt++
    reconnectTimer = setTimeout(connect, delay)
  }

  function disconnect() {
    if (reconnectTimer) clearTimeout(reconnectTimer)
    if (ws) ws.close()
  }

  connect()

  onUnmounted(disconnect)

  return { connected, lastEvent, disconnect }
}
