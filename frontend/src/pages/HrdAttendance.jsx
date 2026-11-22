import { useEffect, useState } from 'react'
import { Download, Search } from 'lucide-react'
import { api, qs } from '../api.js'
import { Empty, fmtDate, fmtDateTime, fmtTime, PageHead, Pager, Spinner, StatusBadge, useLoad, useToast } from '../ui.jsx'

const Tick = ({ ok }) => <span className={`badge ${ok ? 'ok' : 'bad'}`}>{ok ? 'Ya' : 'Tidak'}</span>

// Status jam kerja dari backend (AttendanceService)
const CHECK_IN = { on_time: ['Tepat waktu', 'ok'], tolerance: ['Toleransi', 'warn'], late: ['Terlambat', 'bad'] }
const CHECK_OUT = { normal: ['Normal', 'ok'], early_leave: ['Izin (pulang awal)', 'bad'] }
const REASON = { sakit: 'Sakit', izin: 'Izin', kunjungan: 'Kunjungan' }

// Kategori kehadiran: masuk = hijau, izin / tidak masuk = merah, cuti = kuning
const CATEGORY = { masuk: 'Masuk', izin: 'Izin / Tidak masuk', cuti: 'Cuti' }
const Cat = ({ value }) => (
  <span className={`badge cat ${value}`}><i className={`dot ${value}`} />{CATEGORY[value] || '-'}</span>
)

// Satu sisi keterangan (masuk atau pulang): badge di atas, alasan di bawahnya
function Half({ map, status, type, note }) {
  if (!status) return <div className="att-half muted small">-</div>
  const [label, tone] = map[status] || [status, '']
  const reason = type ? `${REASON[type] || type}${note ? `: ${note}` : ''}` : ''
  return (
    <div className="att-half">
      <span className={`badge ${tone}`}>{label}</span>
      {reason && <div className="small att-reason">{reason}</div>}
    </div>
  )
}

export default function HrdAttendance() {
  const toast = useToast()
  const [tab, setTab] = useState('records')
  const [f, setF] = useState({ from: '', to: '', location_id: '', category: '', search: '', page: 1 })
  const [q, setQ] = useState('')
  const [af, setAf] = useState({ only_failed: '', page: 1 })
  const locs = useLoad(() => api.get('/hrd/locations'))
  const rec = useLoad(() => api.get('/hrd/attendance' + qs(f)), [JSON.stringify(f)])
  const att = useLoad(() => (tab === 'attempts' ? api.get('/hrd/attendance/attempts' + qs(af)) : Promise.resolve(null)), [tab, JSON.stringify(af)])
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value, page: 1 })

  // Pencarian nama: tunggu 400 ms setelah berhenti mengetik agar tidak memanggil API di setiap huruf
  useEffect(() => {
    const t = setTimeout(() => setF((p) => (p.search === q.trim() ? p : { ...p, search: q.trim(), page: 1 })), 400)
    return () => clearTimeout(t)
  }, [q])

  const exp = () => api.download('/hrd/attendance/export' + qs({ ...f, page: '' }), 'rekap-absensi.csv').catch((e) => toast(e.message, 'bad'))
  return (
    <>
      <PageHead title="Rekap Absensi" subtitle="Riwayat kehadiran dan percobaan absensi untuk audit."><button className="btn" onClick={exp}><Download size={16} /> Ekspor CSV</button></PageHead>
      <div className="tabs"><button className={tab === 'records' ? 'on' : ''} onClick={() => setTab('records')}>Kehadiran</button><button className={tab === 'attempts' ? 'on' : ''} onClick={() => setTab('attempts')}>Percobaan absensi</button></div>
      {tab === 'records' ? (<>
        <div className="card row" style={{ marginBottom: 16 }}>
          <label className="search-box">
            <Search size={16} />
            <input type="search" placeholder="Cari nama / no. karyawan" value={q} onChange={(e) => setQ(e.target.value)} />
          </label>
          <label className="small muted">Dari <input type="date" value={f.from} onChange={set('from')} /></label>
          <label className="small muted">Sampai <input type="date" value={f.to} onChange={set('to')} /></label>
          <select style={{ width: 200 }} value={f.location_id} onChange={set('location_id')}><option value="">Semua lokasi</option>{(locs.data || []).map((l) => <option key={l.id} value={l.id}>{l.location_name}</option>)}</select>
          <select style={{ width: 190 }} value={f.category} onChange={set('category')} aria-label="Kategori kehadiran">
            <option value="">Semua kategori</option>
            <option value="masuk">🟢 Masuk</option>
            <option value="izin">🔴 Izin / Tidak masuk</option>
            <option value="cuti">🟡 Cuti</option>
          </select>
        </div>
        <div className="card flush"><div className="table-wrap">
          {rec.loading ? <Spinner /> : rec.error ? <div className="alert bad">{rec.error}</div> : !rec.data.data.length ? <Empty>Tidak ada data absensi.</Empty> : (
            <table><thead><tr><th>Tanggal</th><th>Karyawan</th><th>Lokasi</th><th>Check-in</th><th>Check-out</th><th>Kehadiran</th>
              <th><div className="att-grid"><span>Keterangan masuk</span><span>Keterangan pulang</span></div></th><th>Status</th></tr></thead>
              <tbody>{rec.data.data.map((r) => <tr key={r.id}><td>{fmtDate(r.attendance_date)}</td><td><b>{r.employee?.full_name}</b><div className="muted small">{r.employee?.employee_number}</div></td><td>{r.location?.location_name || '-'}</td>
                <td>{fmtTime(r.check_in_at)}<div className="muted small">{r.check_in_distance_meters ?? '-'} m</div></td><td>{fmtTime(r.check_out_at)}<div className="muted small">{r.check_out_distance_meters ?? '-'} m</div></td>
                <td><Cat value={r.presence_category} /></td>
                <td><div className="att-grid">
                  <Half map={CHECK_IN} status={r.check_in_status} type={r.check_in_reason_type} note={r.check_in_reason_note} />
                  <Half map={CHECK_OUT} status={r.check_out_status} type={r.check_out_reason_type} note={r.check_out_reason_note} />
                </div></td>
                <td><StatusBadge value={r.attendance_status} /></td></tr>)}</tbody></table>)}
        </div>{rec.data && <Pager meta={rec.data} onPage={(p) => setF({ ...f, page: p })} />}</div>
      </>) : (<>
        <div className="card row" style={{ marginBottom: 16 }}><label className="check"><input type="checkbox" checked={!!af.only_failed} onChange={(e) => setAf({ only_failed: e.target.checked ? '1' : '', page: 1 })} /> Hanya yang gagal</label></div>
        <div className="card flush"><div className="table-wrap">
          {att.loading || !att.data ? <Spinner /> : !att.data.data.length ? <Empty>Belum ada percobaan.</Empty> : (
            <table><thead><tr><th>Waktu</th><th>Karyawan</th><th>Jenis</th><th>Lokasi</th><th>Wajah</th><th>Liveness</th><th>Hasil</th></tr></thead>
              <tbody>{att.data.data.map((r) => <tr key={r.id}><td className="small">{fmtDateTime(r.created_at)}</td><td><b>{r.employee?.full_name}</b></td><td>{r.type === 'check_in' ? 'Check-in' : 'Check-out'}</td>
                <td><Tick ok={r.location_ok} /><div className="muted small">{r.distance_meters ?? '-'} m</div></td><td><Tick ok={r.face_ok} /></td><td><Tick ok={r.liveness_ok} /></td>
                <td>{r.success ? <span className="badge ok">Berhasil</span> : <span className="small" style={{ color: 'var(--bad)' }}>{r.reason}</span>}</td></tr>)}</tbody></table>)}
        </div>{att.data && <Pager meta={att.data} onPage={(p) => setAf({ ...af, page: p })} />}</div>
      </>)}
    </>
  )
}
