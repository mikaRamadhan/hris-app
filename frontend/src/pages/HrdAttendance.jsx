import { useState } from 'react'
import { Download } from 'lucide-react'
import { api, qs } from '../api.js'
import { Empty, fmtDate, fmtDateTime, fmtTime, PageHead, Pager, PhotoThumb, ReasonText, Spinner, StatusBadge, useLoad, useToast } from '../ui.jsx'

const Tick = ({ ok }) => <span className={`badge ${ok ? 'ok' : 'bad'}`}>{ok ? 'Ya' : 'Tidak'}</span>

export default function HrdAttendance() {
  const toast = useToast()
  const [tab, setTab] = useState('records')
  const [f, setF] = useState({ from: '', to: '', location_id: '', page: 1 })
  const [af, setAf] = useState({ only_failed: '', page: 1 })
  const locs = useLoad(() => api.get('/hrd/locations'))
  const rec = useLoad(() => api.get('/hrd/attendance' + qs(f)), [JSON.stringify(f)])
  const att = useLoad(() => (tab === 'attempts' ? api.get('/hrd/attendance/attempts' + qs(af)) : Promise.resolve(null)), [tab, JSON.stringify(af)])
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value, page: 1 })
  const exp = () => api.download('/hrd/attendance/export' + qs({ ...f, page: '' }), 'rekap-absensi.csv').catch((e) => toast(e.message, 'bad'))
  return (
    <>
      <PageHead title="Rekap Absensi" subtitle="Riwayat kehadiran dan percobaan absensi untuk audit."><button className="btn" onClick={exp}><Download size={16} /> Ekspor CSV</button></PageHead>
      <div className="tabs"><button className={tab === 'records' ? 'on' : ''} onClick={() => setTab('records')}>Kehadiran</button><button className={tab === 'attempts' ? 'on' : ''} onClick={() => setTab('attempts')}>Percobaan absensi</button></div>
      {tab === 'records' ? (<>
        <div className="card row" style={{ marginBottom: 16 }}>
          <label className="small muted">Dari <input type="date" value={f.from} onChange={set('from')} /></label>
          <label className="small muted">Sampai <input type="date" value={f.to} onChange={set('to')} /></label>
          <select style={{ width: 200 }} value={f.location_id} onChange={set('location_id')}><option value="">Semua lokasi</option>{(locs.data || []).map((l) => <option key={l.id} value={l.id}>{l.location_name}</option>)}</select>
        </div>
        <div className="card flush"><div className="table-wrap">
          {rec.loading ? <Spinner /> : rec.error ? <div className="alert bad">{rec.error}</div> : !rec.data.data.length ? <Empty>Tidak ada data absensi.</Empty> : (
            <table><thead><tr><th>Tanggal</th><th>Karyawan</th><th>Lokasi</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Foto</th></tr></thead>
              <tbody>{rec.data.data.map((r) => <tr key={r.id}><td>{fmtDate(r.attendance_date)}</td><td><b>{r.employee?.full_name}</b><div className="muted small">{r.employee?.employee_number}</div></td><td>{r.location?.location_name || '-'}</td>
                <td>{fmtTime(r.check_in_at)}<div className="muted small">{r.check_in_distance_meters ?? '-'} m</div></td><td>{fmtTime(r.check_out_at)}<div className="muted small">{r.check_out_distance_meters ?? '-'} m</div></td><td><div className="row" style={{ gap: 6 }}>{r.check_in_status && <StatusBadge value={r.check_in_status} />}{r.check_out_status && <StatusBadge value={r.check_out_status} />}{!r.check_out_status && <span className="badge">Belum check-out</span>}</div><ReasonText type={r.check_in_reason_type} note={r.check_in_reason_note} /><ReasonText type={r.check_out_reason_type} note={r.check_out_reason_note} /></td>
                <td><div className="row" style={{ gap: 6 }}>{r.has_check_in_photo && <PhotoThumb url={`/hrd/attendance/${r.id}/photo/in`} label={`Check-in ${r.employee?.full_name}`} />}{r.has_check_out_photo && <PhotoThumb url={`/hrd/attendance/${r.id}/photo/out`} label={`Check-out ${r.employee?.full_name}`} />}</div></td></tr>)}</tbody></table>)}
        </div>{rec.data && <Pager meta={rec.data} onPage={(p) => setF({ ...f, page: p })} />}</div>
      </>) : (<>
        <div className="card row" style={{ marginBottom: 16 }}><label className="check"><input type="checkbox" checked={!!af.only_failed} onChange={(e) => setAf({ only_failed: e.target.checked ? '1' : '', page: 1 })} /> Hanya yang gagal</label></div>
        <div className="card flush"><div className="table-wrap">
          {att.loading || !att.data ? <Spinner /> : !att.data.data.length ? <Empty>Belum ada percobaan.</Empty> : (
            <table><thead><tr><th>Waktu</th><th>Karyawan</th><th>Jenis</th><th>Lokasi</th><th>Wajah</th><th>Hasil</th></tr></thead>
              <tbody>{att.data.data.map((r) => <tr key={r.id}><td className="small">{fmtDateTime(r.created_at)}</td><td><b>{r.employee?.full_name}</b></td><td>{r.type === 'check_in' ? 'Check-in' : 'Check-out'}</td>
                <td><Tick ok={r.location_ok} /><div className="muted small">{r.distance_meters ?? '-'} m</div></td><td><Tick ok={r.face_ok} /></td>
                <td>{r.success ? <span className="badge ok">Berhasil</span> : <span className="small" style={{ color: 'var(--bad)' }}>{r.reason}</span>}</td></tr>)}</tbody></table>)}
        </div>{att.data && <Pager meta={att.data} onPage={(p) => setAf({ ...af, page: p })} />}</div>
      </>)}
    </>
  )
}
