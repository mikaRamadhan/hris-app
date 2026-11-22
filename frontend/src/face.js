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

/** Pelacak kedipan adaptif: kalibrasi EAR mata terbuka milik pengguna, lalu deteksi tertutup -> terbuka. */
export function makeBlinkTracker() {
  const samples = []
  let baseline = 0
  let phase = 0 // 0 kalibrasi, 1 menunggu kedip, 2 mata tertutup, 3 lulus
  return {
    update(ear) {
      if (phase === 0) {
        samples.push(ear)
        if (samples.length >= 5) {
          const s = [...samples].sort((x, y) => x - y)
          baseline = s[s.length - 2] // nilai tinggi yang stabil (kebal terhadap satu kedipan saat kalibrasi)
          phase = 1
        }
        return phase === 0 ? 'calibrating' : 'ready'
      }
      if (phase === 1 && ear < baseline * 0.78) phase = 2
      else if (phase === 2 && ear > baseline * 0.9) phase = 3
      return ['calibrating', 'ready', 'closed', 'passed'][phase]
    },
    get baseline() { return baseline },
  }
}

async function detectLandmarks(f, video) {
  const res = await f.detectAllFaces(video, new f.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 })).withFaceLandmarks()
  if (res.length === 0) return { status: 'none' }
  if (res.length > 1) return { status: 'multi' }
  const r = res[0]
  return { status: 'ok', score: r.detection.score, ear: (ear(r.landmarks.getLeftEye()) + ear(r.landmarks.getRightEye())) / 2 }
}

/** Tantangan kedipan mata. Setelah lulus, descriptor wajah diambil dari frame mata terbuka. */
export async function runBlinkChallenge(f, video, onHint, seconds = 20) {
  const end = Date.now() + seconds * 1000
  const tracker = makeBlinkTracker()
  onHint('Posisikan wajah di dalam lingkaran, mata terbuka…')
  let passed = false
  while (Date.now() < end && !passed) {
    const d = await detectLandmarks(f, video)
    if (d.status === 'none') onHint('Wajah tidak terdeteksi. Hadap kamera dengan pencahayaan cukup.')
    else if (d.status === 'multi') onHint('Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di kamera.')
    else if (d.score >= 0.5) {
      const st = tracker.update(d.ear)
      if (st === 'calibrating') onHint('Tatap kamera dengan mata terbuka…')
      else if (st === 'ready') onHint('Sekarang KEDIPKAN mata Anda (agak jelas/lambat)…')
      else if (st === 'closed') onHint('Bagus, buka mata kembali…')
      else if (st === 'passed') passed = true
    }
    await sleep(30)
  }
  if (!passed) return { passed: false, descriptor: null }
  onHint('Kedipan terdeteksi. Mengambil data wajah…')
  for (let i = 0; i < 8; i++) {
    const d = await detect(f, video)
    if (d.status === 'ok' && d.score >= 0.5) return { passed: true, descriptor: d.descriptor }
    await sleep(100)
  }
  return { passed: true, descriptor: null }
}

/** Absensi ringan: ambil satu descriptor wajah yang jelas (tanpa tantangan kedip). */
export async function captureOnce(f, video, onHint, seconds = 12) {
  const end = Date.now() + seconds * 1000
  onHint('Hadap kamera dengan wajah di dalam lingkaran…')
  while (Date.now() < end) {
    const d = await detect(f, video)
    if (d.status === 'ok' && d.score >= 0.5) return d.descriptor
    if (d.status === 'multi') onHint('Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di kamera.')
    else if (d.status === 'none') onHint('Wajah tidak terdeteksi. Hadap kamera dengan pencahayaan cukup.')
    await sleep(150)
  }
  return null
}

/** Registrasi wajah sederhana: rata-rata beberapa descriptor dari frame yang jelas (tanpa tantangan kedip). */
export async function captureAverage(f, video, onHint, n = 2) {
  const list = []
  const end = Date.now() + 20000
  while (list.length < n && Date.now() < end) {
    onHint(`Menangkap wajah (${list.length + 1}/${n})… tetap hadap kamera`)
    const d = await detect(f, video)
    if (d.status === 'ok' && d.score >= 0.5) { list.push(d.descriptor); await sleep(400) }
    else {
      if (d.status === 'multi') onHint('Terdeteksi lebih dari satu wajah. Pastikan hanya Anda di kamera.')
      else if (d.status === 'none') onHint('Wajah tidak terdeteksi. Hadap kamera dengan pencahayaan cukup.')
      await sleep(150)
    }
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

/** Ambil foto dari video sebagai JPEG (lebar maks 480 px). */
export function captureFrame(video) {
  const w = Math.min(480, video.videoWidth || 480)
  const h = Math.round(w * ((video.videoHeight || 360) / (video.videoWidth || 480)))
  const c = document.createElement('canvas')
  c.width = w; c.height = h
  c.getContext('2d').drawImage(video, 0, 0, w, h)
  return new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.75))
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
