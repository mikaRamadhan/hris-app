import { useState } from 'react'
import { Plus } from 'lucide-react'
import { api } from '../api.js'
import { Empty, Field, fmtDate, Modal, PageHead, Spinner, StatusBadge, useForm, useLoad, useToast } from '../ui.jsx'

const blank = { title: '', organizational_unit_id: '', location: '', description: '', requirements: '', quota: 1, open_at: new Date().toISOString().slice(0, 10), close_at: '', status: 'draft' }

function VacancyForm({ item, units, onDone, onClose }) {
  const toast = useToast()
  const f = useForm(item ? { ...blank, ...Object.fromEntries(Object.entries(item).map(([k, v]) => [k, v ?? ''])) } : blank)
  const [busy, setBusy] = useState(false)
  const E = (k) => f.errors[k]?.[0]
  const v = f.values
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); f.setErrors({})
    const body = { ...v, organizational_unit_id: v.organizational_unit_id || null }
    try { item ? await api.put(`/hrd/vacancies/${item.id}`, body) : await api.post('/hrd/vacancies', body); toast('Lowongan disimpan.'); onDone() }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') } finally { setBusy(false) }
  }
  return (
    <Modal wide title={item ? 'Ubah lowongan' : 'Lowongan baru'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Judul" error={E('title')}><input required value={v.title} onChange={f.set('title')} /></Field>
        <div className="grid g2">
          <Field label="Departemen" error={E('organizational_unit_id')}><select value={v.organizational_unit_id} onChange={f.set('organizational_unit_id')}><option value="">-</option>{units.filter((u) => u.type === 'department' && u.is_active).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
          <Field label="Lokasi" error={E('location')}><input value={v.location} onChange={f.set('location')} /></Field>
          <Field label="Kuota" error={E('quota')}><input type="number" min="1" required value={v.quota} onChange={f.set('quota')} /></Field>
          <Field label="Status" error={E('status')}><select value={v.status} onChange={f.set('status')}><option value="draft">Draft</option><option value="open">Dibuka</option><option value="closed">Ditutup</option></select></Field>
          <Field label="Mulai" error={E('open_at')}><input type="date" required value={v.open_at} onChange={f.set('open_at')} /></Field>
          <Field label="Batas akhir" error={E('close_at')}><input type="date" required value={v.close_at} onChange={f.set('close_at')} /></Field>
        </div>
        <Field label="Deskripsi pekerjaan" error={E('description')}><textarea required value={v.description} onChange={f.set('description')} /></Field>
        <Field label="Persyaratan" error={E('requirements')} hint="Satu persyaratan per baris"><textarea required value={v.requirements} onChange={f.set('requirements')} /></Field>
        <div className="row"><button className="btn primary" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan'}</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export default function HrdVacancies() {
  const toast = useToast()
  const { data, loading, error, reload } = useLoad(() => api.get('/hrd/vacancies'))
  const units = useLoad(() => api.get('/hrd/units'))
  const [edit, setEdit] = useState(null)
  const remove = async (v) => {
    if (!confirm(`Tutup/arsipkan lowongan "${v.title}"?`)) return
    try { const r = await api.del(`/hrd/vacancies/${v.id}`); toast(r.message); reload() } catch (e) { toast(e.message, 'bad') }
  }
  return (
    <>
      <PageHead title="Lowongan" subtitle="Buat, ubah, buka, dan tutup lowongan."><button className="btn primary" onClick={() => setEdit({})}><Plus size={16} /> Lowongan baru</button></PageHead>
      <div className="card flush table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.length ? <Empty>Belum ada lowongan.</Empty> : (
          <table><thead><tr><th>Judul</th><th>Periode</th><th>Kuota</th><th>Lamaran</th><th>Status</th><th /></tr></thead>
            <tbody>{data.map((v) => <tr key={v.id}>
              <td><b>{v.title}</b><div className="muted small">{v.unit?.name || '-'}{v.location ? ` · ${v.location}` : ''}</div></td>
              <td className="small">{fmtDate(v.open_at)} – {fmtDate(v.close_at)}</td>
              <td>{v.accepted_count}/{v.quota}</td><td>{v.applications_count}</td><td><StatusBadge value={v.status} /></td>
              <td className="right"><div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" onClick={() => setEdit(v)}>Ubah</button><button className="btn sm danger" onClick={() => remove(v)}>Tutup</button></div></td></tr>)}</tbody></table>)}
      </div>
      {edit && <VacancyForm item={edit.id ? edit : null} units={units.data || []} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload() }} />}
    </>
  )
}
