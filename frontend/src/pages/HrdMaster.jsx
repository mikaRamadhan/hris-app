import { useState } from 'react'
import { api } from '../api.js'
import { ActiveBadge, Empty, Field, Modal, PageHead, Spinner, useForm, useLoad, useToast } from '../ui.jsx'

function UnitForm({ item, units, onClose, onDone }) {
  const toast = useToast()
  const f = useForm({ name: item?.name || '', type: item?.type || 'department', parent_id: item?.parent_id || '', is_active: item?.is_active ?? true })
  const v = f.values
  const submit = async (e) => {
    e.preventDefault()
    const body = { ...v, parent_id: v.parent_id || null }
    try { item ? await api.put(`/hrd/units/${item.id}`, body) : await api.post('/hrd/units', body); toast('Unit disimpan.'); onDone() } catch (err) { toast(err.message, 'bad') }
  }
  return (
    <Modal title={item ? 'Ubah unit' : 'Unit baru'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Nama"><input required value={v.name} onChange={f.set('name')} /></Field>
        <Field label="Tipe"><select value={v.type} onChange={f.set('type')}><option value="department">Departemen</option><option value="position">Jabatan</option></select></Field>
        <Field label="Induk (opsional)" hint="Mis. jabatan berada di bawah departemen"><select value={v.parent_id} onChange={f.set('parent_id')}><option value="">-</option>{units.filter((u) => u.id !== item?.id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
        <label className="check"><input type="checkbox" checked={v.is_active} onChange={f.set('is_active')} /> Aktif</label>
        <div className="row"><button className="btn primary">Simpan</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

function StatusForm({ item, onClose, onDone }) {
  const toast = useToast()
  const f = useForm({ name: item?.name || '', is_active: item?.is_active ?? true })
  const submit = async (e) => {
    e.preventDefault()
    try { item ? await api.put(`/hrd/employee-statuses/${item.id}`, f.values) : await api.post('/hrd/employee-statuses', f.values); toast('Status disimpan.'); onDone() } catch (err) { toast(err.message, 'bad') }
  }
  return (
    <Modal title={item ? 'Ubah status' : 'Status baru'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Nama status"><input required value={f.values.name} onChange={f.set('name')} /></Field>
        <label className="check"><input type="checkbox" checked={f.values.is_active} onChange={f.set('is_active')} /> Aktif</label>
        <div className="row"><button className="btn primary">Simpan</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export default function HrdMaster() {
  const toast = useToast()
  const [tab, setTab] = useState('units')
  const units = useLoad(() => api.get('/hrd/units'))
  const sts = useLoad(() => api.get('/hrd/employee-statuses'))
  const [edit, setEdit] = useState(null)
  const del = async (url, name, reload) => {
    if (!confirm(`Hapus "${name}"?`)) return
    try { await api.del(url); toast('Dihapus.'); reload() } catch (e) { toast(e.message, 'bad') }
  }
  return (
    <>
      <PageHead title="Data Master" subtitle="Unit organisasi (departemen & jabatan) dan status karyawan.">
        <button className="btn primary" onClick={() => setEdit({})}>{tab === 'units' ? 'Unit baru' : 'Status baru'}</button>
      </PageHead>
      <div className="tabs"><button className={tab === 'units' ? 'on' : ''} onClick={() => setTab('units')}>Unit organisasi</button><button className={tab === 'status' ? 'on' : ''} onClick={() => setTab('status')}>Status karyawan</button></div>
      <div className="card flush table-wrap">
        {tab === 'units' ? (units.loading ? <Spinner /> : !units.data.length ? <Empty /> : (
          <table><thead><tr><th>Nama</th><th>Tipe</th><th>Induk</th><th>Status</th><th /></tr></thead>
            <tbody>{units.data.map((u) => <tr key={u.id}><td><b>{u.name}</b></td><td>{u.type === 'department' ? 'Departemen' : 'Jabatan'}</td><td>{u.parent?.name || '-'}</td><td><ActiveBadge on={u.is_active} /></td>
              <td className="right"><div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" onClick={() => setEdit(u)}>Ubah</button><button className="btn sm danger" onClick={() => del(`/hrd/units/${u.id}`, u.name, units.reload)}>Hapus</button></div></td></tr>)}</tbody></table>))
          : (sts.loading ? <Spinner /> : !sts.data.length ? <Empty /> : (
            <table><thead><tr><th>Status</th><th>Aktif</th><th /></tr></thead>
              <tbody>{sts.data.map((s) => <tr key={s.id}><td><b>{s.name}</b></td><td><ActiveBadge on={s.is_active} /></td>
                <td className="right"><div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" onClick={() => setEdit(s)}>Ubah</button><button className="btn sm danger" onClick={() => del(`/hrd/employee-statuses/${s.id}`, s.name, sts.reload)}>Hapus</button></div></td></tr>)}</tbody></table>))}
      </div>
      {edit && tab === 'units' && <UnitForm item={edit.id ? edit : null} units={units.data || []} onClose={() => setEdit(null)} onDone={() => { setEdit(null); units.reload() }} />}
      {edit && tab === 'status' && <StatusForm item={edit.id ? edit : null} onClose={() => setEdit(null)} onDone={() => { setEdit(null); sts.reload() }} />}
    </>
  )
}
