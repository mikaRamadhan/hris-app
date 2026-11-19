import { useEffect, useState } from 'react'
import { Plus } from 'lucide-react'
import { api, qs } from '../api.js'
import { useAuth } from '../auth.jsx'
import { ActiveBadge, Empty, Field, fmtDateTime, Modal, PageHead, Pager, ROLE, Spinner, useForm, useLoad, useToast } from '../ui.jsx'

function UserForm({ item, onClose, onDone }) {
  const toast = useToast()
  const f = useForm({ name: item?.name || '', email: item?.email || '', password: '', role: item?.role || 'hrd', is_active: item?.is_active ?? true })
  const v = f.values
  const E = (k) => f.errors[k]?.[0]
  const submit = async (e) => {
    e.preventDefault(); f.setErrors({})
    try { item ? await api.put(`/admin/users/${item.id}`, v) : await api.post('/admin/users', v); toast('Akun disimpan.'); onDone() }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') }
  }
  return (
    <Modal title={item ? 'Ubah akun' : 'Akun baru'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Nama" error={E('name')}><input required value={v.name} onChange={f.set('name')} /></Field>
        <Field label="Email" error={E('email')}><input type="email" required value={v.email} onChange={f.set('email')} /></Field>
        <Field label={item ? 'Kata sandi baru (opsional)' : 'Kata sandi'} error={E('password')} hint="Minimal 8 karakter"><input type="password" minLength={8} required={!item} value={v.password} onChange={f.set('password')} /></Field>
        <Field label="Role" error={E('role')}><select value={v.role} onChange={f.set('role')}>{Object.entries(ROLE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
        {item && <label className="check"><input type="checkbox" checked={v.is_active} onChange={f.set('is_active')} /> Akun aktif</label>}
        <div className="row"><button className="btn primary">Simpan</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export function AdminUsers() {
  const { user } = useAuth()
  const [filter, setFilter] = useState({ q: '', role: '', page: 1 })
  const { data, loading, error, reload } = useLoad(() => api.get('/admin/users' + qs(filter)), [JSON.stringify(filter)])
  const [edit, setEdit] = useState(null)
  const set = (k) => (e) => setFilter({ ...filter, [k]: e.target.value, page: 1 })
  return (
    <>
      <PageHead title="Pengguna & Role" subtitle="Kelola akun internal dan hak akses."><button className="btn primary" onClick={() => setEdit({})}><Plus size={16} /> Akun baru</button></PageHead>
      <div className="card row" style={{ marginBottom: 16 }}>
        <input style={{ flex: 1, minWidth: 180 }} placeholder="Cari nama atau email…" value={filter.q} onChange={set('q')} />
        <select style={{ width: 160 }} value={filter.role} onChange={set('role')}><option value="">Semua role</option>{Object.entries(ROLE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
      </div>
      <div className="card flush"><div className="table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.data.length ? <Empty>Tidak ada akun.</Empty> : (
          <table><thead><tr><th>Nama</th><th>Role</th><th>Status</th><th /></tr></thead>
            <tbody>{data.data.map((u) => <tr key={u.id}><td><b>{u.name}</b>{u.id === user.id && <span className="badge prim" style={{ marginLeft: 8 }}>Anda</span>}<div className="muted small">{u.email}</div></td><td><span className="badge prim">{ROLE[u.role]}</span></td><td><ActiveBadge on={u.is_active} /></td>
              <td className="right"><button className="btn sm" onClick={() => setEdit(u)}>Ubah</button></td></tr>)}</tbody></table>)}
      </div>{data && <Pager meta={data} onPage={(p) => setFilter({ ...filter, page: p })} />}</div>
      {edit && <UserForm item={edit.id ? edit : null} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload() }} />}
    </>
  )
}

export function AdminCompany() {
  const toast = useToast()
  const { data, loading } = useLoad(() => api.get('/admin/company'))
  const f = useForm({})
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (data) f.setValues(data) }, [data]) // eslint-disable-line
  if (loading) return <Spinner />
  const v = f.values
  const E = (k) => f.errors[k]?.[0]
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); f.setErrors({})
    try { await api.put('/admin/company', v); toast('Profil perusahaan disimpan.') } catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') } finally { setBusy(false) }
  }
  return (
    <>
      <PageHead title="Profil Perusahaan" subtitle="Tampil di halaman beranda untuk pengunjung." />
      <form className="card stack" onSubmit={submit}>
        <Field label="Nama perusahaan" error={E('company_name')}><input required value={v.company_name || ''} onChange={f.set('company_name')} /></Field>
        <Field label="Slogan" error={E('company_tagline')}><input value={v.company_tagline || ''} onChange={f.set('company_tagline')} /></Field>
        <Field label="Tentang perusahaan" error={E('company_about')}><textarea value={v.company_about || ''} onChange={f.set('company_about')} /></Field>
        <div className="grid g2">
          <Field label="Email" error={E('company_email')}><input type="email" value={v.company_email || ''} onChange={f.set('company_email')} /></Field>
          <Field label="Telepon" error={E('company_phone')}><input value={v.company_phone || ''} onChange={f.set('company_phone')} /></Field>
        </div>
        <Field label="Alamat" error={E('company_address')}><textarea value={v.company_address || ''} onChange={f.set('company_address')} /></Field>
        <div><button className="btn primary" disabled={busy}>Simpan</button></div>
      </form>
    </>
  )
}

export function AdminAudit() {
  const [page, setPage] = useState(1)
  const { data, loading, error } = useLoad(() => api.get('/admin/audit-logs' + qs({ page })), [page])
  return (
    <>
      <PageHead title="Log Aktivitas" subtitle="Perubahan penting: hasil seleksi, role, lokasi, data wajah, dan ekspor." />
      <div className="card flush"><div className="table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.data.length ? <Empty>Belum ada aktivitas.</Empty> : (
          <table><thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Objek</th><th>IP</th></tr></thead>
            <tbody>{data.data.map((l) => <tr key={l.id}><td className="small">{fmtDateTime(l.created_at)}</td><td>{l.user?.name || '-'}</td><td><span className="badge">{l.action}</span></td><td className="small">{l.subject_type ? `${l.subject_type} #${l.subject_id}` : '-'}</td><td className="small">{l.ip}</td></tr>)}</tbody></table>)}
      </div>{data && <Pager meta={data} onPage={setPage} />}</div>
    </>
  )
}
