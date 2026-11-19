import { Link, useNavigate, useParams } from 'react-router-dom'
import { Briefcase, CalendarClock, MapPin, Users } from 'lucide-react'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { Empty, fmtDate, Spinner, useLoad } from '../ui.jsx'

function PubNav() {
  const { user, logout } = useAuth()
  const nav = useNavigate()
  return (
    <header className="pub-nav"><div className="in">
      <Link to="/" className="brand" style={{ padding: 0 }}><span className="logo">H</span> HRIS</Link>
      <div className="row">
        {user ? (<>
          <Link className="btn primary sm" to="/app">Buka dashboard</Link>
          <button className="btn sm" onClick={async () => { await logout(); nav('/') }}>Keluar</button>
        </>) : (<>
          <Link className="btn sm" to="/login">Masuk</Link>
          <Link className="btn primary sm" to="/register">Daftar</Link>
        </>)}
      </div>
    </div></header>
  )
}

export default function Home() {
  const company = useLoad(() => api.get('/public/company'))
  const jobs = useLoad(() => api.get('/public/vacancies'))
  const c = company.data || {}
  return (
    <>
      <PubNav />
      <section className="hero"><div className="in">
        <h1>{c.company_name || 'Karier bersama kami'}</h1>
        <p>{c.company_tagline || 'Temukan lowongan terbaru dan lamar secara daring.'}</p>
      </div></section>
      <div className="container stack">
        {c.company_about && <div className="card"><h2>Tentang perusahaan</h2><p className="prewrap muted">{c.company_about}</p>
          {(c.company_address || c.company_email || c.company_phone) && <p className="small muted" style={{ marginTop: 10 }}>{[c.company_address, c.company_email, c.company_phone].filter(Boolean).join(' · ')}</p>}</div>}
        <div className="row between"><h2 style={{ margin: 0 }}>Lowongan dibuka</h2></div>
        {jobs.loading ? <Spinner /> : jobs.error ? <div className="alert bad">{jobs.error}</div> : !jobs.data.length ? <div className="card"><Empty>Belum ada lowongan yang dibuka.</Empty></div> : (
          <div className="grid g3">
            {jobs.data.map((j) => (
              <Link key={j.id} to={`/lowongan/${j.id}`} className="card job" style={{ color: 'inherit' }}>
                <h3>{j.title}</h3>
                <div className="meta">
                  {j.unit && <span><Briefcase size={14} />{j.unit}</span>}
                  {j.location && <span><MapPin size={14} />{j.location}</span>}
                  <span><Users size={14} />{j.quota} posisi</span>
                  <span><CalendarClock size={14} />s.d. {fmtDate(j.close_at)}</span>
                </div>
                <p className="muted small" style={{ display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>{j.description}</p>
              </Link>
            ))}
          </div>
        )}
      </div>
    </>
  )
}

export function VacancyDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const { data: j, loading, error } = useLoad(() => api.get(`/public/vacancies/${id}`), [id])
  return (
    <>
      <PubNav />
      <div className="container" style={{ maxWidth: 820 }}>
        <Link to="/" className="small">← Semua lowongan</Link>
        {loading ? <Spinner /> : error ? <div className="alert bad" style={{ marginTop: 16 }}>{error}</div> : (
          <div className="card stack" style={{ marginTop: 16 }}>
            <div><h1>{j.title}</h1>
              <div className="job"><div className="meta" style={{ marginTop: 8 }}>
                {j.unit && <span><Briefcase size={14} />{j.unit}</span>}{j.location && <span><MapPin size={14} />{j.location}</span>}
                <span><Users size={14} />{j.quota} posisi</span><span><CalendarClock size={14} />Dibuka s.d. {fmtDate(j.close_at)}</span></div></div></div>
            <div><h3>Deskripsi pekerjaan</h3><p className="prewrap muted" style={{ marginTop: 6 }}>{j.description}</p></div>
            <div><h3>Persyaratan</h3><p className="prewrap muted" style={{ marginTop: 6 }}>{j.requirements}</p></div>
            {!user ? <div className="alert">Masuk atau daftar sebagai pelamar untuk mengirim lamaran. <Link to="/login" state={{ from: `/app/lamar/${j.id}` }}>Masuk</Link> · <Link to="/register">Daftar</Link></div>
              : user.role === 'pelamar' ? <Link className="btn primary" to={`/app/lamar/${j.id}`}>Lamar sekarang</Link>
              : <div className="alert warn">Hanya akun pelamar yang dapat mengirim lamaran.</div>}
          </div>
        )}
      </div>
    </>
  )
}
