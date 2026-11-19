import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api } from '../api.js'
import { Empty, Field, fmtDate, fmtDateTime, fmtSize, PageHead, Spinner, StatusBadge, STAGE, useForm, useLoad, useToast } from '../ui.jsx'

const EDU = ['SMA/SMK', 'D3', 'D4', 'S1', 'S2', 'S3']

export function Profile() {
  const toast = useToast()
  const { data, loading, reload } = useLoad(() => api.get('/applicant/profile'))
  const f = useForm({})
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (data) f.setValues(Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v ?? '']))) }, [data]) // eslint-disable-line
  if (loading) return <Spinner />
  const E = (k) => f.errors[k]?.[0]
  const v = f.values
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); f.setErrors({})
    try { await api.put('/applicant/profile', v); toast('Profil disimpan.'); reload() }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') } finally { setBusy(false) }
  }
  return (
    <>
      <PageHead title="Profil Saya" subtitle="Data ini dipakai untuk setiap lamaran Anda." />
      <form className="card stack" onSubmit={submit}>
        <div className="grid g2">
          <Field label="Nama lengkap" error={E('full_name')}><input required value={v.full_name || ''} onChange={f.set('full_name')} /></Field>
          <Field label="Telepon" error={E('phone')}><input required value={v.phone || ''} onChange={f.set('phone')} placeholder="08xxxxxxxxxx" /></Field>
          <Field label="Tanggal lahir" error={E('birth_date')}><input type="date" value={v.birth_date || ''} onChange={f.set('birth_date')} /></Field>
          <Field label="Jenis kelamin" error={E('gender')}><select value={v.gender || ''} onChange={f.set('gender')}><option value="">-</option><option>Laki-laki</option><option>Perempuan</option></select></Field>
          <Field label="Pendidikan terakhir" error={E('education')}><select required value={v.education || ''} onChange={f.set('education')}><option value="">Pilih…</option>{EDU.map((x) => <option key={x}>{x}</option>)}</select></Field>
          <Field label="Institusi" error={E('institution')}><input value={v.institution || ''} onChange={f.set('institution')} /></Field>
        </div>
        <Field label="Alamat" error={E('address')}><textarea required value={v.address || ''} onChange={f.set('address')} /></Field>
        <Field label="Keahlian" error={E('skills')}><input value={v.skills || ''} onChange={f.set('skills')} placeholder="Contoh: Microsoft Excel, komunikasi" /></Field>
        <Field label="Ringkasan diri" error={E('summary')}><textarea value={v.summary || ''} onChange={f.set('summary')} /></Field>
        <div><button className="btn primary" disabled={busy}>{busy ? 'Menyimpan…' : 'Simpan profil'}</button></div>
      </form>
    </>
  )
}

export function MyApplications() {
  const { data, loading, error } = useLoad(() => api.get('/applicant/applications'))
  return (
    <>
      <PageHead title="Lamaran Saya" subtitle="Pantau status dan hasil seleksi lamaran Anda."><Link className="btn" to="/">Cari lowongan</Link></PageHead>
      <div className="card flush table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.length ? <Empty>Belum ada lamaran.</Empty> : (
          <table><thead><tr><th>Lowongan</th><th>Dikirim</th><th>Status</th><th /></tr></thead>
            <tbody>{data.map((a) => <tr key={a.id}><td><b>{a.vacancy_title}</b></td><td>{fmtDate(a.created_at)}</td><td><StatusBadge value={a.status} /></td><td className="right"><Link className="btn sm" to={`/app/lamaran/${a.id}`}>Detail</Link></td></tr>)}</tbody></table>)}
      </div>
    </>
  )
}

export function MyApplicationDetail() {
  const { id } = useParams()
  const toast = useToast()
  const { data: a, loading, error, reload } = useLoad(() => api.get(`/applicant/applications/${id}`), [id])
  const [file, setFile] = useState(null)
  const [type, setType] = useState('ijazah')
  const [busy, setBusy] = useState(false)
  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>
  const final = ['accepted', 'rejected'].includes(a.status)
  const upload = async (e) => {
    e.preventDefault(); if (!file) return
    const fd = new FormData(); fd.append('type', type); fd.append('file', file); setBusy(true)
    try { await api.post(`/applicant/applications/${id}/documents`, fd); toast('Dokumen diunggah.'); setFile(null); e.target.reset(); reload() }
    catch (err) { toast(err.message, 'bad') } finally { setBusy(false) }
  }
  const remove = async (d) => {
    if (!confirm(`Hapus dokumen "${d.original_name}"?`)) return
    try { await api.del(`/applicant/applications/${id}/documents/${d.id}`); toast('Dokumen dihapus.'); reload() } catch (err) { toast(err.message, 'bad') }
  }
  return (
    <>
      <PageHead title={a.vacancy_title} subtitle={`Dikirim ${fmtDateTime(a.created_at)}`}><Link className="btn" to="/app/lamaran">← Kembali</Link></PageHead>
      <div className="stack">
        <div className="card"><div className="row between"><h2 style={{ margin: 0 }}>Status</h2><StatusBadge value={a.status} /></div>
          {a.status === 'accepted' && <div className="alert ok" style={{ marginTop: 12 }}>Selamat! Anda dinyatakan diterima. HRD akan menghubungi Anda untuk tahap berikutnya.</div>}
          {a.status === 'rejected' && <div className="alert bad" style={{ marginTop: 12 }}>Mohon maaf, Anda belum lolos pada seleksi ini. Terima kasih atas partisipasinya.</div>}
          {a.results.length > 0 && <div style={{ marginTop: 14 }}>{a.results.map((r, i) => <div key={i} className="row" style={{ padding: '8px 0', borderTop: '1px solid var(--line)' }}>
            <b>{STAGE[r.stage]}</b><StatusBadge value={r.result} />{r.notes && <span className="muted small">{r.notes}</span>}</div>)}</div>}
        </div>
        {a.cover_letter && <div className="card"><h2>Surat lamaran</h2><p className="prewrap muted">{a.cover_letter}</p></div>}
        <div className="card"><h2>Dokumen</h2>
          {a.documents.map((d) => (
            <div key={d.id} className="row between" style={{ padding: '8px 0', borderTop: '1px solid var(--line)' }}>
              <span><span className="badge prim">{d.type.toUpperCase()}</span> {d.original_name} <span className="muted small">({fmtSize(d.size)})</span></span>
              <span className="row"><button className="btn sm" onClick={() => api.download(`/applicant/applications/${id}/documents/${d.id}`, d.original_name).catch((e) => toast(e.message, 'bad'))}>Unduh</button>
                {!final && a.documents.length > 1 && <button className="btn sm danger" onClick={() => remove(d)}>Hapus</button>}</span>
            </div>))}
          {!final && (
            <form className="row" style={{ marginTop: 14 }} onSubmit={upload}>
              <select style={{ width: 140 }} value={type} onChange={(e) => setType(e.target.value)}><option value="ijazah">Ijazah</option><option value="ktp">KTP</option><option value="cv">CV</option><option value="lainnya">Lainnya</option></select>
              <input type="file" style={{ flex: 1, minWidth: 180 }} accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files[0])} required />
              <button className="btn" disabled={busy}>Unggah</button>
            </form>)}
          <p className="muted small" style={{ marginTop: 10 }}>Format PDF/DOC/DOCX/JPG/PNG, maksimal 2 MB per file.</p>
        </div>
      </div>
    </>
  )
}

export function Apply() {
  const { vacancyId } = useParams()
  const nav = useNavigate()
  const toast = useToast()
  const vac = useLoad(() => api.get(`/public/vacancies/${vacancyId}`), [vacancyId])
  const prof = useLoad(() => api.get('/applicant/profile'))
  const [cover, setCover] = useState('')
  const [cv, setCv] = useState(null)
  const [extra, setExtra] = useState([])
  const [busy, setBusy] = useState(false)
  if (vac.loading || prof.loading) return <Spinner />
  if (vac.error) return <div className="alert bad">{vac.error}</div>
  const incomplete = !(prof.data?.phone && prof.data?.address && prof.data?.education)
  const submit = async (e) => {
    e.preventDefault(); setBusy(true)
    const fd = new FormData(); fd.append('cover_letter', cover); fd.append('cv', cv)
    extra.forEach((f) => fd.append('extra[]', f))
    try { const r = await api.post(`/applicant/vacancies/${vacancyId}/apply`, fd); toast('Lamaran terkirim.'); nav(`/app/lamaran/${r.id}`) }
    catch (err) { toast(err.message, 'bad') } finally { setBusy(false) }
  }
  return (
    <>
      <PageHead title={`Lamar: ${vac.data.title}`} subtitle="Pastikan data profil dan dokumen Anda benar sebelum mengirim."><Link className="btn" to={`/lowongan/${vacancyId}`}>← Detail lowongan</Link></PageHead>
      {incomplete && <div className="alert warn" style={{ marginBottom: 16 }}>Lengkapi <Link to="/app/profil">profil</Link> (telepon, alamat, pendidikan) sebelum melamar.</div>}
      <form className="card stack" onSubmit={submit}>
        <Field label="Surat lamaran (opsional)"><textarea value={cover} onChange={(e) => setCover(e.target.value)} placeholder="Ceritakan singkat mengapa Anda cocok untuk posisi ini" /></Field>
        <Field label="CV (wajib)" hint="PDF/DOC/DOCX, maksimal 2 MB"><input type="file" required accept=".pdf,.doc,.docx" onChange={(e) => setCv(e.target.files[0])} /></Field>
        <Field label="Dokumen pendukung (opsional)" hint="Ijazah, KTP, sertifikat — PDF/JPG/PNG, maksimal 2 MB per file"><input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setExtra([...e.target.files])} /></Field>
        <div><button className="btn primary" disabled={busy || incomplete || !cv}>{busy ? 'Mengirim…' : 'Kirim lamaran'}</button></div>
      </form>
    </>
  )
}
