import { useState } from 'react'
import { Download, Plus } from 'lucide-react'
import { api, qs } from '../api.js'
import { ActiveBadge, Empty, Field, fmtDate, Modal, PageHead, Pager, Spinner, useForm, useLoad, useToast } from '../ui.jsx'

const blank = { employee_number: '', full_name: '', email: '', phone: '', address: '', birth_date: '', gender: '', department_id: '', position_id: '', employee_status_id: '', join_date: new Date().toISOString().slice(0, 10), end_date: '', is_active: true }

function EmployeeForm({ item, units, statuses, onClose, onDone }) {
  const toast = useToast()
  const f = useForm(item ? { ...blank, ...Object.fromEntries(Object.entries(item).filter(([k]) => k in blank).map(([k, v]) => [k, v ?? ''])) } : blank)
  const [busy, setBusy] = useState(false)
  const v = f.values
  const E = (k) => f.errors[k]?.[0]
  const nul = (x) => (x === '' ? null : x)
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); f.setErrors({})
    const body = { ...v, ...Object.fromEntries(['phone', 'address', 'birth_date', 'gender', 'department_id', 'position_id', 'employee_status_id', 'end_date'].map((k) => [k, nul(v[k])])) }
    try { item ? await api.put(`/hrd/employees/${item.id}`, body) : await api.post('/hrd/employees', body); toast('Data karyawan disimpan.'); onDone() }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') } finally { setBusy(false) }
  }
  return (
    <Modal wide title={item ? 'Ubah karyawan' : 'Karyawan baru'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <div className="grid g2">
          <Field label="No. karyawan" error={E('employee_number')}><input required value={v.employee_number} onChange={f.set('employee_number')} placeholder="EMP-2026-0001" /></Field>
          <Field label="Nama lengkap" error={E('full_name')}><input required value={v.full_name} onChange={f.set('full_name')} /></Field>
          <Field label="Email" error={E('email')}><input type="email" required value={v.email} onChange={f.set('email')} /></Field>
          <Field label="Telepon" error={E('phone')}><input value={v.phone} onChange={f.set('phone')} /></Field>
          <Field label="Tanggal lahir" error={E('birth_date')}><input type="date" value={v.birth_date} onChange={f.set('birth_date')} /></Field>
          <Field label="Jenis kelamin"><select value={v.gender} onChange={f.set('gender')}><option value="">-</option><option>Laki-laki</option><option>Perempuan</option></select></Field>
          <Field label="Departemen"><select value={v.department_id} onChange={f.set('department_id')}><option value="">-</option>{units.filter((u) => u.type === 'department').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
          <Field label="Jabatan"><select value={v.position_id} onChange={f.set('position_id')}><option value="">-</option>{units.filter((u) => u.type === 'position').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
          <Field label="Status karyawan"><select value={v.employee_status_id} onChange={f.set('employee_status_id')}><option value="">-</option>{statuses.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
          <Field label="Tanggal masuk" error={E('join_date')}><input type="date" required value={v.join_date} onChange={f.set('join_date')} /></Field>
          <Field label="Tanggal berakhir" error={E('end_date')}><input type="date" value={v.end_date} onChange={f.set('end_date')} /></Field>
          <Field label="Status aktif"><label className="check"><input type="checkbox" checked={!!v.is_active} onChange={f.set('is_active')} /> Karyawan aktif (dapat absen)</label></Field>
        </div>
        <Field label="Alamat"><textarea value={v.address} onChange={f.set('address')} /></Field>
        <div className="row"><button className="btn primary" disabled={busy}>Simpan</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export default function HrdEmployees() {
  const toast = useToast()
  const [filter, setFilter] = useState({ q: '', department_id: '', is_active: '', page: 1 })
  const { data, loading, error, reload } = useLoad(() => api.get('/hrd/employees' + qs(filter)), [JSON.stringify(filter)])
  const units = useLoad(() => api.get('/hrd/units'))
  const sts = useLoad(() => api.get('/hrd/employee-statuses'))
  const [edit, setEdit] = useState(null)
  const set = (k) => (e) => setFilter({ ...filter, [k]: e.target.value, page: 1 })
  const deactivate = async (x) => {
    if (!confirm(`Nonaktifkan ${x.full_name}?`)) return
    try { const r = await api.del(`/hrd/employees/${x.id}`); toast(r.message); reload() } catch (e) { toast(e.message, 'bad') }
  }
  const exp = () => api.download('/hrd/employees/export' + qs({ ...filter, page: '' }), 'data-karyawan.csv').catch((e) => toast(e.message, 'bad'))
  return (
    <>
      <PageHead title="Karyawan" subtitle="Kelola data inti karyawan.">
        <button className="btn" onClick={exp}><Download size={16} /> Ekspor CSV</button><button className="btn primary" onClick={() => setEdit({})}><Plus size={16} /> Karyawan baru</button>
      </PageHead>
      <div className="card row" style={{ marginBottom: 16 }}>
        <input style={{ flex: 1, minWidth: 180 }} placeholder="Cari nama, nomor, atau email…" value={filter.q} onChange={set('q')} />
        <select style={{ width: 200 }} value={filter.department_id} onChange={set('department_id')}><option value="">Semua departemen</option>{(units.data || []).filter((u) => u.type === 'department').map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
        <select style={{ width: 150 }} value={filter.is_active} onChange={set('is_active')}><option value="">Semua</option><option value="1">Aktif</option><option value="0">Nonaktif</option></select>
      </div>
      <div className="card flush">
        <div className="table-wrap">
          {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.data.length ? <Empty>Tidak ada data karyawan.</Empty> : (
            <table><thead><tr><th>Karyawan</th><th>Departemen / Jabatan</th><th>Status</th><th>Masuk</th><th /></tr></thead>
              <tbody>{data.data.map((x) => <tr key={x.id}>
                <td><b>{x.full_name}</b><div className="muted small">{x.employee_number} · {x.email}</div></td>
                <td>{x.department?.name || '-'}<div className="muted small">{x.position?.name || '-'}</div></td>
                <td><ActiveBadge on={x.is_active} /> <span className="badge">{x.status?.name || '-'}</span></td><td className="small">{fmtDate(x.join_date)}</td>
                <td className="right"><div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" onClick={() => setEdit(x)}>Ubah</button>{x.is_active && <button className="btn sm danger" onClick={() => deactivate(x)}>Nonaktifkan</button>}</div></td></tr>)}</tbody></table>)}
        </div>
        {data && <Pager meta={data} onPage={(p) => setFilter({ ...filter, page: p })} />}
      </div>
      {edit && <EmployeeForm item={edit.id ? edit : null} units={units.data || []} statuses={sts.data || []} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload() }} />}
    </>
  )
}
