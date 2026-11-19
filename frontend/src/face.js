// Pembungkus face-api.js (dimuat malas agar halaman lain tetap ringan). Seluruh model ada di /models (lokal, tanpa CDN).
let lib = null

export async function loadFace() {
  if (lib) return lib
  const m = await import('@vladmandic/face-api')
  const f = m.nets ? m : m.default
  const url = import.meta.env.BASE_URL + 'models'
  await f.nets.tinyFaceDetector.loadFromUri(url)
  await f.nets.faceLandmark68Net.loadFromUri(url)
  await f.nets.faceRecognitionNet.loadFromUri(url)
  lib = f
  return f
}

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)
const ear = (p) => (dist(p[1], p[5]) + dist(p[2], p[4])) / (2 * dist(p[0], p[3]))
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

export async function detect(f, video) {
  const res = await f.detectAllFaces(video, new f.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 })).withFaceLandmarks().withFaceDescriptors()
  if (res.length === 0) return { status: 'none' }
  if (res.length > 1) return { status: 'multi' }
  const r = res[0]
  return { status: 'ok', score: r.detection.score, descriptor: Array.from(r.descriptor), ear: (ear(r.landmarks.getLeftEye()) + ear(r.landmarks.getRightEye())) / 2 }
}

/** Tantangan kedipan mata: wajah harus terbuka -> tertutup -> terbuka. Mengembalikan descriptor dari frame mata terbuka. */
export async function runBlinkChallenge(f, video, onHint, seconds = 12) {
  const end = Date.now() + seconds * 1000
  let phase = 0 // 0 menunggu mata terbuka, 1 menunggu kedip, 2 menunggu mata terbuka lagi
  let best = null
  onHint('Posisikan wajah di dalam lingkaran…')
  while (Date.now() < end) {
    const d = await detect(f, video)
    if (d.status === 'none') onHint('Wajah tidak terdeteksi. Hadap kamera dengan pencahayaan cukup.')
    else if (d.status === 'multi') onHint('Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di kamera.')
    else if (d.score >= 0.6) {
      if (phase === 0 && d.ear > 0.27) { phase = 1; onHint('Sekarang kedipkan mata Anda…') }
      else if (phase === 1 && d.ear < 0.21) { phase = 2; onHint('Bagus, buka mata kembali…') }
      else if (phase === 2 && d.ear > 0.26) return { passed: true, descriptor: d.descriptor }
      if (d.ear > 0.27 && (!best || d.score > best.score)) best = d
    }
    await sleep(80)
  }
  return { passed: false, descriptor: best?.descriptor || null }
}

/** Rata-rata beberapa descriptor frame mata terbuka untuk registrasi wajah. */
export async function captureAverage(f, video, onHint, n = 3) {
  const list = []
  const end = Date.now() + 15000
  while (list.length < n && Date.now() < end) {
    onHint(`Menangkap wajah (${list.length + 1}/${n})… tetap hadap kamera`)
    const d = await detect(f, video)
    if (d.status === 'ok' && d.score >= 0.7 && d.ear > 0.25) { list.push(d.descriptor); await sleep(450) } else await sleep(150)
  }
  if (list.length < n) return null
  return list[0].map((_, i) => list.reduce((s, d) => s + d[i], 0) / list.length)
}

export async function startCamera(video) {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('Peramban tidak mendukung kamera atau halaman tidak memakai HTTPS/localhost.')
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false })
  video.srcObject = stream
  await video.play()
  return () => stream.getTracks().forEach((t) => t.stop())
}

export function getPosition() {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve({ error: 'Perangkat tidak mendukung geolokasi.' })
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => resolve({ error: e.code === 1 ? 'Izin lokasi ditolak. Aktifkan izin lokasi pada peramban.' : 'Lokasi tidak tersedia saat ini.' }),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  })
}

export function haversine(lat1, lon1, lat2, lon2) {
  const r = 6371000, rad = (x) => (x * Math.PI) / 180
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lon2 - lon1) / 2) ** 2
  return 2 * r * Math.asin(Math.sqrt(a))
}
