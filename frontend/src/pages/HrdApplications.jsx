import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, qs } from '../api.js'
import { Empty, Field, fmtDate, fmtDateTime, fmtSize, Modal, PageHead, Pager, Spinner, StatusBadge, STAGE, useForm, useLoad, useToast } from '../ui.jsx'

export function HrdApplications() {
  const [filter, setFilter] = useState({ status: '', vacancy_id: '', q: '', page: 1 })
  const vac = useLoad(() => api.get('/hrd/vacancies'))
  const { data, loading, error } = useLoad(() => api.get('/hrd/applications' + qs(filter)), [JSON.stringify(filter)])
  const set = (k) => (e) => setFilter({ ...filter, [k]: e.target.value, page: 1 })
  return (
    <>
      <PageHead title="Lamaran Masuk" subtitle="Tinjau pelamar, periksa dokumen, dan catat hasil seleksi." />
      <div className="card row" style={{ marginBottom: 16 }}>
        <input style={{ flex: 1, minWidth: 180 }} placeholder="Cari nama pelamar…" value={filter.q} onChange={set('q')} />
        <select style={{ width: 200 }} value={filter.vacancy_id} onChange={set('vacancy_id')}><option value="">Semua lowongan</option>{(vac.data || []).map((v) => <option key={v.id} value={v.id}>{v.title}</option>)}</select>
        <select style={{ width: 160 }} value={filter.status} onChange={set('status')}><option value="">Semua status</option>{['submitted', 'reviewing', 'interview', 'accepted', 'rejected'].map((s) => <option key={s} value={s}>{s}</option>)}</select>
      </div>
      <div className="card flush">
        <div className="table-wrap">
          {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.data.length ? <Empty>Tidak ada lamaran.</Empty> : (
            <table><thead><tr><th>Pelamar</th><th>Lowongan</th><th>Dikirim</th><th>Status</th><th /></tr></thead>
              <tbody>{data.data.map((a) => <tr key={a.id}><td><b>{a.applicant}</b><div className="muted small">{a.education || '-'}</div></td><td>{a.vacancy}</td><td>{fmtDate(a.created_at)}</td>
                <td><StatusBadge value={a.status} /> {a.announced && <span className="badge">diumumkan</span>}</td><td className="right"><Link className="btn sm" to={`/app/hrd/lamaran/${a.id}`}>Tinjau</Link></td></tr>)}</tbody></table>)}
        </div>
        {data && <Pager meta={data} onPage={(p) => setFilter({ ...filter, page: p })} />}
      </div>
    </>
  )
}

function ConvertModal({ app, onClose, onDone }) {
  const toast = useToast()
  const units = useLoad(() => api.get('/hrd/units'))
  const sts = useLoad(() => api.get('/hrd/employee-statuses'))
  const f = useForm({ department_id: '', position_id: '', employee_status_id: '', join_date: new Date().toISOString().slice(0, 10) })
  const [busy, setBusy] = useState(false)
  const v = f.values
  const submit = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await api.post(`/hrd/applications/${app.id}/convert`, { ...v, department_id: v.department_id || null, position_id: v.position_id || null }); toast('Pelamar dikonversi menjadi karyawan.'); onDone() }
    catch (err) { toast(err.message, 'bad') } finally { setBusy(false) }
  }
  const U = units.data || []
  return (
    <Modal title="Jadikan karyawan" onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <p className="muted small">Data profil {app.applicant.full_name} akan disalin ke data karyawan. Akun pelamar berubah menjadi akun karyawan (dapat melakukan absensi).</p>
        <Field label="Departemen"><select value={v.department_id} onChange={f.set('department_id')}><option value="">-</option>{U.filter((u) => u.type === 'department' && u.is_active).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
        <Field label="Jabatan"><select value={v.position_id} onChange={f.set('position_id')}><option value="">-</option>{U.filter((u) => u.type === 'position' && u.is_active).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
        <Field label="Status karyawan"><select required value={v.employee_status_id} onChange={f.set('employee_status_id')}><option value="">Pilih…</option>{(sts.data || []).filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
        <Field label="Tanggal mulai kerja"><input type="date" required value={v.join_date} onChange={f.set('join_date')} /></Field>
        <div className="row"><button className="btn primary" disabled={busy}>Konversi</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export function HrdApplicationDetail() {
  const { id } = useParams()
  const toast = useToast()
  const { data: a, loading, error, reload, setData } = useLoad(() => api.get(`/hrd/applications/${id}`), [id])
  const f = useForm({ stage: 'screening', result: 'pass', notes: '' })
  const [busy, setBusy] = useState(false)
  const [convert, setConvert] = useState(false)
  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>
  const p = a.applicant
  const final = ['accepted', 'rejected'].includes(a.status)
  const addResult = async (e) => {
    e.preventDefault(); setBusy(true)
    try { setData(await api.post(`/hrd/applications/${id}/results`, f.values)); f.setValues({ ...f.values, notes: '' }); toast('Hasil seleksi dicatat.') }
    catch (err) { toast(err.message, 'bad') } finally { setBusy(false) }
  }
  const announce = async () => {
    if (!confirm('Umumkan hasil akhir kepada pelamar? Setelah diumumkan hasil tidak dapat diubah.')) return
    try { setData(await api.post(`/hrd/applications/${id}/announce`)); toast('Hasil diumumkan.') } catch (err) { toast(err.message, 'bad') }
  }
  return (
    <>
      <PageHead title={p.full_name} subtitle={`Melamar: ${a.vacancy.title} · ${fmtDateTime(a.created_at)}`}>
        <Link className="btn" to="/app/hrd/lamaran">← Kembali</Link><StatusBadge value={a.status} />
      </PageHead>
      <div className="grid g2" style={{ alignItems: 'start' }}>
        <div className="stack">
          <div className="card"><h2>Data pelamar</h2>
            <dl className="kv">
              <dt>Email</dt><dd>{p.email}</dd><dt>Telepon</dt><dd>{p.phone || '-'}</dd><dt>Tanggal lahir</dt><dd>{fmtDate(p.birth_date)}</dd><dt>Jenis kelamin</dt><dd>{p.gender || '-'}</dd>
              <dt>Pendidikan</dt><dd>{p.education || '-'}{p.institution ? ` — ${p.institution}` : ''}</dd><dt>Alamat</dt><dd>{p.address || '-'}</dd><dt>Keahlian</dt><dd>{p.skills || '-'}</dd><dt>Ringkasan</dt><dd className="prewrap">{p.summary || '-'}</dd>
            </dl></div>
          {a.cover_letter && <div className="card"><h2>Surat lamaran</h2><p className="prewrap muted">{a.cover_letter}</p></div>}
          <div className="card"><h2>Dokumen</h2>
            {a.documents.map((d) => <div key={d.id} className="row between" style={{ padding: '8px 0', borderTop: '1px solid var(--line)' }}>
              <span><span className="badge prim">{d.type.toUpperCase()}</span> {d.original_name} <span className="muted small">({fmtSize(d.size)})</span></span>
              <button className="btn sm" onClick={() => api.download(`/hrd/applications/${id}/documents/${d.id}`, d.original_name).catch((e) => toast(e.message, 'bad'))}>Unduh</button></div>)}</div>
        </div>
        <div className="stack">
          <div className="card"><h2>Catat hasil seleksi</h2>
            {a.announced_at ? <div className="alert ok">Hasil sudah diumumkan pada {fmtDateTime(a.announced_at)} dan tidak dapat diubah.</div> : (
              <form className="stack" onSubmit={addResult}>
                <div className="grid g2">
                  <Field label="Tahap"><select value={f.values.stage} onChange={f.set('stage')}>{Object.entries(STAGE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
                  <Field label="Hasil"><select value={f.values.result} onChange={f.set('result')}><option value="pass">Lulus</option><option value="fail">Tidak lulus</option><option value="pending">Menunggu</option></select></Field>
                </div>
                <Field label="Catatan"><textarea value={f.values.notes} onChange={f.set('notes')} /></Field>
                <p className="muted small">Tahap wawancara bersifat opsional. “Keputusan akhir: Lulus” menandai pelamar diterima; “Tidak lulus” pada tahap mana pun menandai ditolak.</p>
                <div><button className="btn primary" disabled={busy}>Simpan hasil</button></div>
              </form>)}
          </div>
          <div className="card"><h2>Riwayat seleksi</h2>
            {!a.results.length ? <Empty>Belum ada hasil.</Empty> : a.results.map((r) => <div key={r.id} style={{ padding: '10px 0', borderTop: '1px solid var(--line)' }}>
              <div className="row between"><span className="row"><b>{STAGE[r.stage]}</b><StatusBadge value={r.result} /></span><span className="muted small">{fmtDateTime(r.at)}</span></div>
              {r.notes && <p className="muted small">{r.notes}</p>}<span className="muted small">oleh {r.by || '-'}{r.is_announced ? ' · diumumkan' : ''}</span></div>)}
            <div className="row" style={{ marginTop: 14 }}>
              {final && !a.announced_at && <button className="btn primary" onClick={announce}>Umumkan hasil ke pelamar</button>}
              {a.status === 'accepted' && !a.employee_id && <button className="btn" onClick={() => setConvert(true)}>Jadikan karyawan</button>}
              {a.employee_id && <Link className="btn" to="/app/hrd/karyawan">Sudah menjadi karyawan →</Link>}
            </div></div>
        </div>
      </div>
      {convert && <ConvertModal app={a} onClose={() => setConvert(false)} onDone={() => { setConvert(false); reload() }} />}
    </>
  )
}
