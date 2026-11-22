import { Link } from 'react-router-dom'
import { Briefcase, CalendarCheck, ClipboardList, MapPin, ShieldCheck, UserCheck, Users, UserX } from 'lucide-react'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { ProfileCard } from './Employee.jsx'
import { Empty, fmtTime, PageHead, Spinner, StatusBadge, useLoad, ROLE } from '../ui.jsx'

const Stat = ({ icon: Icon, value, label, to }) => {
  const body = <div className="stat"><div className="ico"><Icon size={20} /></div><b>{value}</b><span>{label}</span></div>
  return <div className="card">{to ? <Link to={to} style={{ color: 'inherit' }}>{body}</Link> : body}</div>
}

export default function Dashboard() {
  const { user } = useAuth()
  const { data: d, loading, error } = useLoad(() => api.get('/dashboard'))
  if (loading) return <Spinner />
  if (error) return <div className="alert bad">{error}</div>
  return (
    <>
      <PageHead title={`Halo, ${user.name.split(' ')[0]}`} subtitle={`Dashboard ${ROLE[user.role]}`} />
      {d.role === 'hrd' && (
        <div className="stack">
          <div className="grid g4">
            <Stat icon={Briefcase} value={d.open_vacancies} label="Lowongan aktif" to="/app/hrd/lowongan" />
            <Stat icon={ClipboardList} value={d.applications} label="Total lamaran" to="/app/hrd/lamaran" />
            <Stat icon={Users} value={d.employees_active} label="Karyawan aktif" to="/app/hrd/karyawan" />
            <Stat icon={CalendarCheck} value={`${d.attendance_today}`} label={`Absensi hari ini (${d.completed_today} selesai)`} to="/app/hrd/absensi" />
          </div>
          <div className="card"><h2>Ringkasan hasil seleksi</h2>
            <div className="row">{['submitted', 'reviewing', 'interview', 'accepted', 'rejected'].map((s) => (
              <div key={s} className="row" style={{ gap: 6 }}><StatusBadge value={s} /><b>{d.applications_by_status[s] ?? 0}</b></div>))}</div></div>
          {d.locations === 0 && <div className="alert warn">Belum ada lokasi absensi aktif. <Link to="/app/hrd/lokasi">Tambahkan lokasi</Link> agar karyawan dapat absen.</div>}
        </div>
      )}
      {d.role === 'admin' && (
        <div className="stack">
          <div className="grid g4">
            {Object.entries(d.users_by_role).map(([r, n]) => <Stat key={r} icon={ShieldCheck} value={n} label={`Akun ${ROLE[r] || r}`} to="/app/admin/pengguna" />)}
            <Stat icon={UserX} value={d.inactive_users} label="Akun nonaktif" />
            <Stat icon={MapPin} value={d.locations} label="Lokasi absensi aktif" />
          </div>
          {!d.company_configured && <div className="alert warn">Profil perusahaan belum diisi. <Link to="/app/admin/perusahaan">Lengkapi sekarang</Link>.</div>}
        </div>
      )}
      {d.role === 'pelamar' && (
        <div className="stack">
          {!d.profile_complete && <div className="alert warn">Profil Anda belum lengkap. <Link to="/app/profil">Lengkapi profil</Link> sebelum melamar.</div>}
          <div className="grid g2">
            <Stat icon={Briefcase} value={d.open_vacancies} label="Lowongan tersedia" to="/" />
            <Stat icon={ClipboardList} value={d.applications.length} label="Lamaran terbaru" to="/app/lamaran" />
          </div>
          <div className="card flush"><div style={{ padding: '20px 20px 0' }}><h2>Status lamaran</h2></div>
            {!d.applications.length ? <Empty>Anda belum mengirim lamaran. <Link to="/">Lihat lowongan</Link></Empty> : (
              <table><tbody>{d.applications.map((a) => <tr key={a.id}><td><Link to={`/app/lamaran/${a.id}`}>{a.vacancy_title}</Link></td><td className="right"><StatusBadge value={a.status} /></td></tr>)}</tbody></table>)}</div>
        </div>
      )}
      {d.role === 'karyawan' && (
        <div className="stack">
          <ProfileCard />
          {!d.face_registered && <div className="alert warn">Wajah Anda belum terdaftar. <Link to="/app/wajah">Daftarkan wajah</Link> untuk dapat absen.</div>}
          <div className="card"><h2>Kehadiran hari ini</h2>
            {d.today ? <div className="timeline"><div><span className="muted small">Check-in</span><b>{fmtTime(d.today.check_in_at)}</b></div><div><span className="muted small">Check-out</span><b>{fmtTime(d.today.check_out_at)}</b></div></div>
              : <p className="muted">Belum ada absensi hari ini.</p>}
            <Link className="btn primary" style={{ marginTop: 16 }} to="/app/absen"><UserCheck size={16} /> Buka halaman absensi</Link></div>
        </div>
      )}
    </>
  )
}
