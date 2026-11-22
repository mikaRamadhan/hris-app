import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { api } from './api.js'

// ---------- Toast ----------
const ToastCtx = createContext(() => {})
export const useToast = () => useContext(ToastCtx)
export function ToastProvider({ children }) {
  const [items, setItems] = useState([])
  const push = useCallback((message, tone = 'ok') => {
    const id = Math.random()
    setItems((i) => [...i, { id, message, tone }])
    setTimeout(() => setItems((i) => i.filter((x) => x.id !== id)), 4200)
  }, [])
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="toasts" aria-live="polite">{items.map((t) => <div key={t.id} className={`toast ${t.tone}`}>{t.message}</div>)}</div>
    </ToastCtx.Provider>
  )
}

// ---------- Komponen dasar ----------
export const Spinner = () => <div className="center"><span className="spin" /></div>
export const Empty = ({ children = 'Belum ada data.' }) => <div className="empty">{children}</div>

export function PageHead({ title, subtitle, children }) {
  return (
    <div className="page-head">
      <div><h1>{title}</h1>{subtitle && <p className="muted" style={{ marginTop: 4 }}>{subtitle}</p>}</div>
      <div className="row">{children}</div>
    </div>
  )
}

export function Field({ label, error, hint, children }) {
  return (
    <div className="field">
      {label && <label>{label}</label>}
      {children}
      {hint && !error && <span className="hint">{hint}</span>}
      {error && <span className="err">{error}</span>}
    </div>
  )
}

export function Modal({ title, onClose, children, wide }) {
  useEffect(() => {
    const h = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [onClose])
  return (
    <div className="overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`modal ${wide ? 'wide' : ''}`} role="dialog" aria-modal="true">
        <div className="row between" style={{ marginBottom: 16 }}>
          <h2 style={{ margin: 0 }}>{title}</h2>
          <button className="btn sm" onClick={onClose} aria-label="Tutup">✕</button>
        </div>
        {children}
      </div>
    </div>
  )
}

export function Pager({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <div className="pager">
      <span className="muted small">Halaman {meta.current_page} dari {meta.last_page} · {meta.total} data</span>
      <div className="row">
        <button className="btn sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Sebelumnya</button>
        <button className="btn sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Berikutnya</button>
      </div>
    </div>
  )
}

// ---------- Format & label ----------
export const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '-')
export const fmtTime = (d) => (d ? new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '-')
export const fmtDateTime = (d) => (d ? `${fmtDate(d)} ${new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}` : '-')
export const fmtSize = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB')

const STATUS = {
  submitted: ['Diajukan', 'info'], reviewing: ['Direview', 'warn'], interview: ['Wawancara', 'prim'], accepted: ['Diterima', 'ok'], rejected: ['Ditolak', 'bad'],
  draft: ['Draft', ''], open: ['Dibuka', 'ok'], closed: ['Ditutup', 'bad'],
  on_time: ['Tepat waktu', 'ok'], tolerance: ['Toleransi', 'warn'], late: ['Terlambat', 'bad'], normal: ['Pulang normal', 'ok'], early_leave: ['Izin (pulang awal)', 'warn'],
  checked_in: ['Sudah check-in', 'warn'], completed: ['Selesai', 'ok'],
  pass: ['Lulus', 'ok'], fail: ['Tidak lulus', 'bad'], pending: ['Menunggu', 'warn'],
}
export function StatusBadge({ value }) {
  const [label, tone] = STATUS[value] || [value, '']
  return <span className={`badge ${tone}`}>{label}</span>
}
export const ActiveBadge = ({ on }) => <span className={`badge ${on ? 'ok' : ''}`}>{on ? 'Aktif' : 'Nonaktif'}</span>
export const STAGE = { screening: 'Seleksi berkas', interview: 'Wawancara', final: 'Keputusan akhir' }
export const ROLE = { admin: 'Admin', hrd: 'HRD', pelamar: 'Pelamar', karyawan: 'Karyawan' }

// ---------- Hook ----------
export function useLoad(fn, deps = []) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tick, setTick] = useState(0)
  useEffect(() => {
    let live = true
    setLoading(true)
    fn().then((d) => live && (setData(d), setError(null))).catch((e) => live && setError(e.message)).finally(() => live && setLoading(false))
    return () => { live = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick])
  return { data, error, loading, reload: () => setTick((t) => t + 1), setData }
}

export function useForm(initial) {
  const [values, setValues] = useState(initial)
  const [errors, setErrors] = useState({})
  const set = (k) => (e) => setValues((v) => ({ ...v, [k]: e?.target ? (e.target.type === 'checkbox' ? e.target.checked : e.target.value) : e }))
  return { values, setValues, errors, setErrors, set }
}

export const REASON = { sakit: 'Sakit', izin: 'Izin', kunjungan: 'Kunjungan' }

// ---------- Foto privat (diambil dengan token, ditampilkan sebagai blob) ----------
export function SecureImage({ url, alt = 'foto', className, style }) {
  const [src, setSrc] = useState(null)
  const [fail, setFail] = useState(false)
  useEffect(() => {
    let live = true, obj = null
    setSrc(null); setFail(false)
    api.blobUrl(url).then((u) => { obj = u; live ? setSrc(u) : URL.revokeObjectURL(u) }).catch(() => live && setFail(true))
    return () => { live = false; if (obj) URL.revokeObjectURL(obj) }
  }, [url])
  if (fail) return <span className="muted small">Foto tidak tersedia</span>
  if (!src) return <span className="spin" />
  return <img src={src} alt={alt} className={className} style={style} />
}

/** Gambar kecil yang bisa diklik untuk memperbesar. */
export function PhotoThumb({ url, label }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" className="thumb" title={label} onClick={() => setOpen(true)}><SecureImage url={url} alt={label} /></button>
      {open && <Modal title={label} onClose={() => setOpen(false)}><SecureImage url={url} alt={label} style={{ width: '100%', borderRadius: 10 }} /></Modal>}
    </>
  )
}

export function ReasonText({ type, note }) {
  if (!type) return null
  return <div className="muted small">{REASON[type] || type}{note ? `: ${note}` : ''}</div>
}
