import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Camera, LogIn, LogOut, MapPin } from 'lucide-react'
import { api, qs } from '../api.js'
import { captureAverage, captureFrame, getPosition, haversine, loadFace, captureOnce, startCamera } from '../face.js'
import { ActiveBadge, Empty, Field, fmtDate, fmtTime, Modal, PageHead, Pager, PhotoThumb, ReasonText, SecureImage, Spinner, StatusBadge, useLoad, useToast } from '../ui.jsx'

/** Kartu profil karyawan: nama, jabatan, departemen, dan data kepegawaian. Tampil di bagian atas halaman karyawan. */
export function ProfileCard({ me }) {
  const own = useLoad(() => (me ? Promise.resolve(null) : api.get('/employee/me')), [])
  const d = me || own.data
  if (!d) return own.loading ? <div className="card"><span className="spin" /></div> : null
  const e = d.employee
  const initials = (e.full_name || '?').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('')
  const rows = [['No. karyawan', e.employee_number], ['Status', e.status?.name || '-'], ['Tanggal masuk', fmtDate(e.join_date)], ['Email', e.email], ['Telepon', e.phone || '-']]
  return (
    <div className="card profile">
      <div className="avatar">{d.face_has_photo ? <SecureImage key={d.face_registered_at} url="/employee/face/photo" alt="Foto profil" /> : <span>{initials}</span>}</div>
      <div className="profile-main">
        <div className="row" style={{ gap: 10 }}><h2 style={{ margin: 0 }}>{e.full_name}</h2><ActiveBadge on={e.is_active} /></div>
        <p style={{ marginTop: 4 }}><b>{e.position?.name || 'Jabatan belum diisi'}</b> <span className="muted">· Departemen {e.department?.name || '-'}</span></p>
        <dl className="profile-grid">{rows.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
      </div>
    </div>
  )
}

/** Kamera + status model. Mengembalikan ref video, f (face-api) dan status siap. */
function useFaceCamera(enabled) {
  const videoRef = useRef(null)
  const [state, setState] = useState({ ready: false, error: null })
  const lib = useRef(null)
  useEffect(() => {
    if (!enabled) return undefined // tunggu sampai elemen <video> tampil di halaman
    let stop = () => {}
    let dead = false
    ;(async () => {
      try {
        lib.current = await loadFace()
        if (!videoRef.current) throw new Error('Elemen kamera belum siap. Muat ulang halaman.')
        stop = await startCamera(videoRef.current)
        if (!dead) setState({ ready: true, error: null })
      } catch (e) {
        if (!dead) setState({ ready: false, error: e.name === 'NotAllowedError' ? 'Izin kamera ditolak. Aktifkan izin kamera pada peramban.' : e.name === 'NotFoundError' ? 'Kamera tidak ditemukan.' : e.message })
      }
    })()
    return () => { dead = true; stop() }
  }, [enabled])
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
  const cam = useFaceCamera(!!me && me.face_registered)
  const [hint, setHint] = useState('Siap. Pilih Check-in atau Check-out.')
  const [busy, setBusy] = useState(false)
  const [pos, setPos] = useState(null)
  const [result, setResult] = useState(null)
  const [ask, setAsk] = useState(null)

  const refreshPos = async () => setPos(await getPosition())
  useEffect(() => { refreshPos() }, [])

  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>

  const nearest = pos && !pos.error && me.locations.length
    ? me.locations.map((l) => ({ l, d: haversine(pos.latitude, pos.longitude, l.latitude, l.longitude) })).sort((a, b) => a.d - b.d)[0]
    : null
  const inRadius = nearest && nearest.d <= nearest.l.radius_meters
  const t = me.today

  const send = async (type, payload, reason) => {
    const fd = new FormData()
    fd.append('descriptor', JSON.stringify(payload.descriptor))
    fd.append('photo', payload.photo, 'absen.jpg')
    if (!payload.pos.error) {
      fd.append('latitude', payload.pos.latitude); fd.append('longitude', payload.pos.longitude); fd.append('accuracy', payload.pos.accuracy)
    }
    if (reason) { fd.append('reason_type', reason.type); if (reason.note) fd.append('reason_note', reason.note) }
    return api.post(`/employee/attendance/${type}`, fd)
  }

  const finish = (res) => { setResult({ ok: true, message: res.message }); toast(res.message); setAsk(null); reload() }

  const run = async (type) => {
    setBusy(true); setResult(null)
    try {
      const p = await getPosition(); setPos(p)
      const descriptor = await captureOnce(cam.lib.current, cam.videoRef.current, setHint)
      if (!descriptor) throw new Error('Wajah tidak terdeteksi dengan jelas. Coba lagi dengan pencahayaan lebih baik.')
      const photo = await captureFrame(cam.videoRef.current)
      const payload = { descriptor, photo, pos: p }
      setHint('Mengirim data absensi…')
      try { finish(await send(type, payload)) }
      catch (e) {
        if (e.data?.reason_required) setAsk({ type, payload, options: e.data.options, message: e.message })
        else throw e
      }
    } catch (e) { setResult({ ok: false, message: e.message }); toast('Absensi gagal.', 'bad') }
    finally { setBusy(false); setHint('Siap. Pilih Check-in atau Check-out.') }
  }

  const confirmReason = async (reason) => {
    setBusy(true)
    try { finish(await send(ask.type, ask.payload, reason)) }
    catch (e) { setResult({ ok: false, message: e.message }); setAsk(null); toast('Absensi gagal.', 'bad') }
    finally { setBusy(false) }
  }

  if (!me.face_registered) return (
    <>
      <PageHead title="Absensi" />
      <div className="alert warn">Wajah Anda belum terdaftar. <Link to="/app/wajah">Daftarkan wajah</Link> terlebih dahulu.</div>
    </>
  )

  return (
    <>
      <ProfileCard me={me} />
      <PageHead title="Absensi" subtitle={`Waktu server: ${fmtDate(me.server_time)} ${fmtTime(me.server_time)}`} />
      <div className="grid g2" style={{ alignItems: 'start' }}>
        <div className="card"><CameraView videoRef={cam.videoRef} hint={hint} ready={cam.ready} error={cam.error} /></div>
        <div className="stack">
          <div className="card"><h2>Kehadiran hari ini</h2>
            <div className="timeline"><div><span className="muted small">Check-in</span><b>{fmtTime(t?.check_in_at)}</b>{t?.check_in_status && <StatusBadge value={t.check_in_status} />}<ReasonText type={t?.check_in_reason_type} note={t?.check_in_reason_note} />{t?.has_check_in_photo && <PhotoThumb url={`/employee/attendance/${t.id}/photo/in`} label="Foto check-in" />}</div><div><span className="muted small">Check-out</span><b>{fmtTime(t?.check_out_at)}</b>{t?.check_out_status && <StatusBadge value={t.check_out_status} />}<ReasonText type={t?.check_out_reason_type} note={t?.check_out_reason_note} />{t?.has_check_out_photo && <PhotoThumb url={`/employee/attendance/${t.id}/photo/out`} label="Foto check-out" />}</div></div>
            <p className="muted small" style={{ marginTop: 10 }}>Jam kerja: check-in paling lambat 09:00 (toleransi s.d. 09:30, setelahnya terlambat). Check-out sebelum 17:00 dicatat sebagai izin.</p>
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
      {ask && <ReasonModal ask={ask} busy={busy} onClose={() => setAsk(null)} onConfirm={confirmReason} />}
    </>
  )
}

function ReasonModal({ ask, busy, onClose, onConfirm }) {
  const [type, setType] = useState('')
  const [note, setNote] = useState('')
  return (
    <Modal title={ask.type === 'check-in' ? 'Alasan keterlambatan' : 'Alasan pulang lebih awal'} onClose={onClose}>
      <form className="stack" onSubmit={(e) => { e.preventDefault(); onConfirm({ type, note }) }}>
        <div className="alert warn">{ask.message}</div>
        <Field label="Alasan"><select required value={type} onChange={(e) => setType(e.target.value)}><option value="">Pilih alasan…</option>{ask.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
        <Field label="Keterangan (opsional)"><textarea maxLength={300} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="row"><button className="btn primary" disabled={!type || busy}>Kirim absensi</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export function FaceRegister() {
  const toast = useToast()
  const { data: me, loading, error, reload } = useLoad(() => api.get('/employee/me'))
  const cam = useFaceCamera(!!me)
  const [hint, setHint] = useState('Setujui pernyataan di bawah, lalu mulai.')
  const [consent, setConsent] = useState(false)
  const [busy, setBusy] = useState(false)
  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>

  const start = async () => {
    setBusy(true)
    try {
      const avg = await captureAverage(cam.lib.current, cam.videoRef.current, setHint)
      if (!avg) throw new Error('Wajah tidak tertangkap dengan jelas. Coba lagi dengan pencahayaan lebih baik.')
      const photo = await captureFrame(cam.videoRef.current)
      setHint('Menyimpan…')
      const fd = new FormData()
      fd.append('descriptor', JSON.stringify(avg)); fd.append('consent', 'true'); fd.append('photo', photo, 'wajah.jpg')
      const r = await api.post('/employee/face', fd)
      toast(r.message); reload()
    } catch (e) { toast(e.message, 'bad') } finally { setBusy(false); setHint('Setujui pernyataan di bawah, lalu mulai.') }
  }
  const remove = async () => {
    if (!confirm('Hapus data wajah Anda? Anda tidak dapat absen sampai mendaftar ulang.')) return
    try { const r = await api.del('/employee/face'); toast(r.message); reload() } catch (e) { toast(e.message, 'bad') }
  }
  return (
    <>
      <ProfileCard me={me} />
      <PageHead title="Registrasi Wajah" subtitle={me.face_registered ? `Terdaftar sejak ${fmtDate(me.face_registered_at)}. Anda dapat memperbarui atau menghapusnya.` : 'Daftarkan wajah Anda untuk dapat melakukan absensi.'} />
      <div className="grid g2" style={{ alignItems: 'start' }}>
        <div className="card"><CameraView videoRef={cam.videoRef} hint={hint} ready={cam.ready} error={cam.error} /></div>
        <div className="card stack">
          <div className="alert">Wajah adalah data biometrik yang sensitif. Sistem menyimpan template numerik (terenkripsi) dan satu foto registrasi di penyimpanan privat. Data hanya dipakai untuk verifikasi absensi dan dapat Anda hapus kapan saja.</div>
          <label className="check"><input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} /> Saya memahami dan menyetujui pemrosesan data wajah saya untuk verifikasi absensi.</label>
          <div className="row">
            <button className="btn primary" disabled={!consent || busy || !cam.ready} onClick={start}><Camera size={16} /> {me.face_registered ? 'Perbarui wajah' : 'Mulai registrasi'}</button>
            {me.face_registered && <button className="btn danger" onClick={remove}>Hapus data wajah</button>}
            {busy && <span className="spin" />}
          </div>
          <p className="muted small">Cukup hadap kamera beberapa detik dengan pencahayaan yang baik. Foto registrasi ikut disimpan secara privat untuk pembanding oleh HRD.</p>
          {me.face_has_photo && <div><p className="small muted" style={{ marginBottom: 6 }}>Foto registrasi Anda:</p><SecureImage key={me.face_registered_at} url="/employee/face/photo" alt="Foto registrasi" className="face-photo" /></div>}
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
      <ProfileCard />
      <PageHead title="Riwayat Absensi" />
      <div className="card flush"><div className="table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.data.length ? <Empty>Belum ada riwayat.</Empty> : (
          <table><thead><tr><th>Tanggal</th><th>Lokasi</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Foto</th></tr></thead>
            <tbody>{data.data.map((r) => <tr key={r.id}><td>{fmtDate(r.attendance_date)}</td><td>{r.location?.location_name || '-'}</td><td>{fmtTime(r.check_in_at)}</td><td>{fmtTime(r.check_out_at)}</td><td><div className="row" style={{ gap: 6 }}>{r.check_in_status && <StatusBadge value={r.check_in_status} />}{r.check_out_status && <StatusBadge value={r.check_out_status} />}</div><ReasonText type={r.check_in_reason_type} note={r.check_in_reason_note} /><ReasonText type={r.check_out_reason_type} note={r.check_out_reason_note} /></td>
                <td><div className="row" style={{ gap: 6 }}>{r.has_check_in_photo && <PhotoThumb url={`/employee/attendance/${r.id}/photo/in`} label="Foto check-in" />}{r.has_check_out_photo && <PhotoThumb url={`/employee/attendance/${r.id}/photo/out`} label="Foto check-out" />}</div></td></tr>)}</tbody></table>)}
      </div>{data && <Pager meta={data} onPage={setPage} />}</div>
    </>
  )
}
