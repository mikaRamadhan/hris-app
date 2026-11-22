const KEY = 'hris_token'
export const getToken = () => { try { return localStorage.getItem(KEY) } catch { return null } }
export const setToken = (t) => { try { t ? localStorage.setItem(KEY, t) : localStorage.removeItem(KEY) } catch { /* abaikan */ } }

let onUnauthorized = () => {}
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn }

async function request(method, url, body, { raw } = {}) {
  const headers = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  let payload
  if (body instanceof FormData) payload = body
  else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }

  let res
  try {
    res = await fetch('/api' + url, { method, headers, body: payload })
  } catch {
    throw Object.assign(new Error('Tidak dapat terhubung ke server. Periksa jaringan atau server backend.'), { status: 0 })
  }
  if (raw && res.ok) return res
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && token) onUnauthorized()
    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null
    throw Object.assign(new Error(first || data.message || `Terjadi kesalahan (${res.status})`), { status: res.status, errors: data.errors || {}, data })
  }
  return data
}

export const api = {
  get: (u) => request('GET', u),
  post: (u, b) => request('POST', u, b ?? {}),
  put: (u, b) => request('PUT', u, b ?? {}),
  del: (u) => request('DELETE', u),
  // Ambil gambar yang dilindungi token dan ubah menjadi object URL
  async blobUrl(url) {
    const res = await request('GET', url, undefined, { raw: true })
    return URL.createObjectURL(await res.blob())
  },
  // Unduh file yang dilindungi token (dokumen, CSV)
  async download(url, filename) {
    const res = await request('GET', url, undefined, { raw: true })
    const blob = await res.blob()
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(a.href), 2000)
  },
}

export const qs = (o) => {
  const p = new URLSearchParams()
  Object.entries(o).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) p.set(k, v) })
  const s = p.toString()
  return s ? '?' + s : ''
}
