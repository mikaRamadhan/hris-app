import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Camera, LogIn, LogOut, MapPin } from 'lucide-react'
import { api, qs } from '../api.js'
import { captureAverage, getPosition, haversine, loadFace, runBlinkChallenge, startCamera } from '../face.js'
import { Empty, Field, fmtDate, fmtTime, PageHead, Pager, Spinner, StatusBadge, useLoad, useToast } from '../ui.jsx'

/** Kamera + status model. Mengembalikan ref video, f (face-api) dan status siap. */
function useFaceCamera() {
  const videoRef = useRef(null)
  const [state, setState] = useState({ ready: false, error: null })
  const lib = useRef(null)
  useEffect(() => {
    let stop = () => {}
    let dead = false
    ;(async () => {
      try {
        lib.current = await loadFace()
        stop = await startCamera(videoRef.current)
        if (!dead) setState({ ready: true, error: null })
      } catch (e) {
        if (!dead) setState({ ready: false, error: e.name === 'NotAllowedError' ? 'Izin kamera ditolak. Aktifkan izin kamera pada peramban.' : e.name === 'NotFoundError' ? 'Kamera tidak ditemukan.' : e.message })
      }
    })()
    return () => { dead = true; stop() }
  }, [])
  return { videoRef, lib, ...state }
}

function CameraView({ videoRef, hint, ready, error }) {
  return (
    <>
      {error && <div className="alert bad" style={{ marginBottom: 12 }}>{error} Hubungi HRD untuk prosedur alternatif bila kamera tidak dapat digunakan.</div>}
      <div className="cam">
        <video ref={videoRef} playsInline muted />
        <div className="ring" />
        <div className="hint">{!ready && !error ? 'Memuat kamera dan model wajah…' : hint}</div>
      </div>
    </>
  )
}

export function Attend() {
  const toast = useToast()
  const { data: me, loading, error, reload } = useLoad(() => api.get('/employee/me'))
  const cam = useFaceCamera()
  const [hint, setHint] = useState('Siap. Pilih Check-in atau Check-out.')
  const [busy, setBusy] = useState(false)
  const [pos, setPos] = useState(null)
  const [result, setResult] = useState(null)

  const refreshPos = async () => setPos(await getPosition())
  useEffect(() => { refreshPos() }, [])

  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>

  const nearest = pos && !pos.error && me.locations.length
    ? me.locations.map((l) => ({ l, d: haversine(pos.latitude, pos.longitude, l.latitude, l.longitude) })).sort((a, b) => a.d - b.d)[0]
    : null
  const inRadius = nearest && nearest.d <= nearest.l.radius_meters
  const t = me.today

  const run = async (type) => {
    setBusy(true); setResult(null)
    try {
      const p = await getPosition(); setPos(p)
      setHint('Lakukan tantangan keaslian wajah…')
      const r = await runBlinkChallenge(cam.lib.current, cam.videoRef.current, setHint)
      if (!r.descriptor) throw new Error('Wajah tidak terdeteksi dengan jelas. Coba lagi dengan pencahayaan lebih baik.')
      setHint('Mengirim data absensi…')
      const body = { descriptor: r.descriptor, liveness_passed: r.passed }
      if (!p.error) Object.assign(body, { latitude: p.latitude, longitude: p.longitude, accuracy: p.accuracy })
      const res = await api.post(`/employee/attendance/${type}`, body)
      setResult({ ok: true, message: res.message })
      toast(res.message); reload()
    } catch (e) { setResult({ ok: false, message: e.message }); toast('Absensi gagal.', 'bad') }
    finally { setBusy(false); setHint('Siap. Pilih Check-in atau Check-out.') }
  }

  if (!me.face_registered) return (
    <>
      <PageHead title="Absensi" />
      <div className="alert warn">Wajah Anda belum terdaftar. <Link to="/app/wajah">Daftarkan wajah</Link> terlebih dahulu.</div>
    </>
  )

  return (
    <>
      <PageHead title="Absensi" subtitle={`Waktu server: ${fmtDate(me.server_time)} ${fmtTime(me.server_time)}`} />
      <div className="grid g2" style={{ alignItems: 'start' }}>
        <div className="card"><CameraView videoRef={cam.videoRef} hint={hint} ready={cam.ready} error={cam.error} /></div>
        <div className="stack">
          <div className="card"><h2>Kehadiran hari ini</h2>
            <div className="timeline"><div><span className="muted small">Check-in</span><b>{fmtTime(t?.check_in_at)}</b></div><div><span className="muted small">Check-out</span><b>{fmtTime(t?.check_out_at)}</b></div></div>
            <div className="row" style={{ marginTop: 16 }}>
              <button className="btn primary" disabled={busy || !cam.ready || !!t?.check_in_at} onClick={() => run('check-in')}><LogIn size={16} /> Check-in</button>
              <button className="btn" disabled={busy || !cam.ready || !t?.check_in_at || !!t?.check_out_at} onClick={() => run('check-out')}><LogOut size={16} /> Check-out</button>
              {busy && <span className="spin" />}
            </div>
            {result && <div className={`alert ${result.ok ? 'ok' : 'bad'}`} style={{ marginTop: 14 }}>{result.message}</div>}
          </div>
          <div className="card"><h2><MapPin size={16} style={{ verticalAlign: -2 }} /> Lokasi</h2>
            {!pos ? <span className="spin" /> : pos.error ? <div className="alert warn">{pos.error}</div> : !nearest ? <div className="alert warn">Belum ada lokasi absensi aktif. Hubungi HRD.</div> : (
              <>
                <p><b>{nearest.l.location_name}</b> — {Math.round(nearest.d)} m <span className={`badge ${inRadius ? 'ok' : 'bad'}`}>{inRadius ? 'Dalam radius' : `Di luar radius (${nearest.l.radius_meters} m)`}</span></p>
                <p className="muted small">Akurasi GPS ±{Math.round(pos.accuracy)} m. Pengecekan resmi dilakukan server saat absen.</p>
              </>)}
            <button className="btn sm" style={{ marginTop: 10 }} onClick={refreshPos}>Perbarui lokasi</button>
          </div>
        </div>
      </div>
    </>
  )
}

export function FaceRegister() {
  const toast = useToast()
  const { data: me, loading, error, reload } = useLoad(() => api.get('/employee/me'))
  const cam = useFaceCamera()
  const [hint, setHint] = useState('Setujui pernyataan di bawah, lalu mulai.')
  const [consent, setConsent] = useState(false)
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>

  const start = async () => {
    setBusy(true)
    try {
      const live = await runBlinkChallenge(cam.lib.current, cam.videoRef.current, setHint)
      if (!live.passed) throw new Error('Pemeriksaan keaslian gagal. Coba lagi dan kedipkan mata.')
      const avg = await captureAverage(cam.lib.current, cam.videoRef.current, setHint)
      if (!avg) throw new Error('Wajah tidak tertangkap dengan jelas. Coba lagi dengan pencahayaan lebih baik.')
      setHint('Menyimpan…')
      const r = await api.post('/employee/face', { descriptor: avg, consent: true, password })
      toast(r.message); setPassword(''); reload()
    } catch (e) { toast(e.message, 'bad') } finally { setBusy(false); setHint('Setujui pernyataan di bawah, lalu mulai.') }
  }
  const remove = async () => {
    if (!confirm('Hapus data wajah Anda? Anda tidak dapat absen sampai mendaftar ulang.')) return
    try { const r = await api.del('/employee/face'); toast(r.message); reload() } catch (e) { toast(e.message, 'bad') }
  }
  return (
    <>
      <PageHead title="Registrasi Wajah" subtitle={me.face_registered ? `Terdaftar sejak ${fmtDate(me.face_registered_at)}. Anda dapat memperbarui atau menghapusnya.` : 'Daftarkan wajah Anda untuk dapat melakukan absensi.'} />
      <div className="grid g2" style={{ alignItems: 'start' }}>
        <div className="card"><CameraView videoRef={cam.videoRef} hint={hint} ready={cam.ready} error={cam.error} /></div>
        <div className="card stack">
          <div className="alert">Wajah adalah data biometrik yang sensitif. Sistem hanya menyimpan template numerik (terenkripsi), bukan foto. Data hanya dipakai untuk verifikasi absensi dan dapat Anda hapus kapan saja.</div>
          <label className="check"><input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} /> Saya memahami dan menyetujui pemrosesan data wajah saya untuk verifikasi absensi.</label>
          <Field label="Konfirmasi kata sandi" hint="Diperlukan untuk mendaftarkan atau memperbarui wajah"><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
          <div className="row">
            <button className="btn primary" disabled={!consent || !password || busy || !cam.ready} onClick={start}><Camera size={16} /> {me.face_registered ? 'Perbarui wajah' : 'Mulai registrasi'}</button>
            {me.face_registered && <button className="btn danger" onClick={remove}>Hapus data wajah</button>}
            {busy && <span className="spin" />}
          </div>
          <p className="muted small">Saat diminta, kedipkan mata satu kali, lalu tetap menghadap kamera selama beberapa detik.</p>
        </div>
      </div>
    </>
  )
}

export function AttendHistory() {
  const [page, setPage] = useState(1)
  const { data, loading, error } = useLoad(() => api.get('/employee/attendance' + qs({ page })), [page])
  return (
    <>
      <PageHead title="Riwayat Absensi" />
      <div className="card flush"><div className="table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.data.length ? <Empty>Belum ada riwayat.</Empty> : (
          <table><thead><tr><th>Tanggal</th><th>Lokasi</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
            <tbody>{data.data.map((r) => <tr key={r.id}><td>{fmtDate(r.attendance_date)}</td><td>{r.location?.location_name || '-'}</td><td>{fmtTime(r.check_in_at)}</td><td>{fmtTime(r.check_out_at)}</td><td><StatusBadge value={r.attendance_status} /></td></tr>)}</tbody></table>)}
      </div>{data && <Pager meta={data} onPage={setPage} />}</div>
    </>
  )
}
