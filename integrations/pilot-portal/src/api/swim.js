const BASE_URL = import.meta.env.VITE_SWIM_URL || '/api/swim/v1'

async function request(path, options = {}) {
  const apiKey = localStorage.getItem('SWIM_API_KEY') || ''
  const res = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': apiKey,
      ...options.headers,
    },
  })
  if (!res.ok) {
    const err = await res.json().catch(() => ({ error: res.statusText }))
    throw new Error(err.error || err.message || res.statusText)
  }
  return res.json()
}

export function fetchFlight(callsign) {
  return request(`/flight?callsign=${encodeURIComponent(callsign)}`)
}

export function fetchTOSStatus(callsign) {
  return request(`/tos/status?callsign=${encodeURIComponent(callsign)}`)
}

export function fileTOS(callsign, departure, destination, options) {
  return request('/tos/file', {
    method: 'POST',
    body: JSON.stringify({ callsign, departure, destination, options }),
  })
}

export function fetchActivePrograms() {
  return request('/tmi/active')
}
