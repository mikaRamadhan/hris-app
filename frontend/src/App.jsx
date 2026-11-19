import { useState } from 'react'
import { Link, NavLink, Navigate, Outlet, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { Briefcase, CalendarCheck, Building2, ClipboardList, LayoutDashboard, LogOut, Menu, MapPin, ScanFace, ScrollText, Settings2, ShieldCheck, UserCircle, Users, History, FileText } from 'lucide-react'
import { useAuth } from './auth.jsx'
import { ROLE, Spinner } from './ui.jsx'
import Home, { VacancyDetail } from './pages/Home.jsx'
import { Login, Register } from './pages/Auth.jsx'
import Dashboard from './pages/Dashboard.jsx'
import { Profile, MyApplications, MyApplicationDetail, Apply } from './pages/Applicant.jsx'
import HrdVacancies from './pages/HrdVacancies.jsx'
import { HrdApplications, HrdApplicationDetail } from './pages/HrdApplications.jsx'
import HrdEmployees from './pages/HrdEmployees.jsx'
import HrdMaster from './pages/HrdMaster.jsx'
import HrdLocations from './pages/HrdLocations.jsx'
import HrdAttendance from './pages/HrdAttendance.jsx'
import { AdminUsers, AdminCompany, AdminAudit } from './pages/Admin.jsx'
import { Attend, FaceRegister, AttendHistory } from './pages/Employee.jsx'

const NAV = {
  pelamar: [['/app', 'Dashboard', LayoutDashboard, true], ['/', 'Lowongan', Briefcase], ['/app/profil', 'Profil Saya', UserCircle], ['/app/lamaran', 'Lamaran Saya', FileText]],
  hrd: [['/app', 'Dashboard', LayoutDashboard, true], ['/app/hrd/lowongan', 'Lowongan', Briefcase], ['/app/hrd/lamaran', 'Lamaran Masuk', ClipboardList], ['/app/hrd/karyawan', 'Karyawan', Users],
    ['/app/hrd/master', 'Data Master', Building2], ['/app/hrd/lokasi', 'Lokasi Absensi', MapPin], ['/app/hrd/absensi', 'Rekap Absensi', CalendarCheck]],
  admin: [['/app', 'Dashboard', LayoutDashboard, true], ['/app/admin/pengguna', 'Pengguna & Role', ShieldCheck], ['/app/admin/perusahaan', 'Profil Perusahaan', Settings2], ['/app/admin/audit', 'Log Aktivitas', ScrollText]],
  karyawan: [['/app', 'Dashboard', LayoutDashboard, true], ['/app/absen', 'Absensi', CalendarCheck], ['/app/wajah', 'Registrasi Wajah', ScanFace], ['/app/riwayat', 'Riwayat Absensi', History]],
}

function Shell() {
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const loc = useLocation()
  const nav = useNavigate()
  const items = NAV[user.role] || []
  return (
    <div className="shell">
      <div className="topbar">
        <button className="btn sm" onClick={() => setOpen(!open)} aria-label="Menu"><Menu size={18} /></button>
        <b>HRIS</b>
      </div>
      <aside className={`side ${open ? 'open' : ''}`} onClick={() => setOpen(false)}>
        <Link to="/" className="brand"><span className="logo">H</span> HRIS</Link>
        <nav className="nav">
          {items.map(([to, label, Icon, end]) => (
            <NavLink key={to} to={to} end={end ?? false} className={({ isActive }) => (isActive && !(to === '/' ) ? 'active' : '')}><Icon size={18} />{label}</NavLink>
          ))}
        </nav>
        <div className="me">
          <b style={{ display: 'block' }}>{user.name}</b>
          <span className="muted small">{ROLE[user.role]} · {user.email}</span>
          <button className="btn sm block" style={{ marginTop: 10 }} onClick={async () => { await logout(); nav('/') }}><LogOut size={15} /> Keluar</button>
        </div>
      </aside>
      <main className="main" key={loc.pathname}><Outlet /></main>
    </div>
  )
}

function Protected({ roles }) {
  const { user, ready } = useAuth()
  const loc = useLocation()
  if (!ready) return <Spinner />
  if (!user) return <Navigate to="/login" state={{ from: loc.pathname }} replace />
  if (roles && !roles.includes(user.role)) return <Navigate to="/app" replace />
  return <Shell />
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Home />} />
      <Route path="/lowongan/:id" element={<VacancyDetail />} />
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />

      <Route element={<Protected />}>
        <Route path="/app" element={<Dashboard />} />
      </Route>
      <Route element={<Protected roles={['pelamar']} />}>
        <Route path="/app/profil" element={<Profile />} />
        <Route path="/app/lamaran" element={<MyApplications />} />
        <Route path="/app/lamaran/:id" element={<MyApplicationDetail />} />
        <Route path="/app/lamar/:vacancyId" element={<Apply />} />
      </Route>
      <Route element={<Protected roles={['hrd']} />}>
        <Route path="/app/hrd/lowongan" element={<HrdVacancies />} />
        <Route path="/app/hrd/lamaran" element={<HrdApplications />} />
        <Route path="/app/hrd/lamaran/:id" element={<HrdApplicationDetail />} />
        <Route path="/app/hrd/karyawan" element={<HrdEmployees />} />
        <Route path="/app/hrd/master" element={<HrdMaster />} />
        <Route path="/app/hrd/lokasi" element={<HrdLocations />} />
        <Route path="/app/hrd/absensi" element={<HrdAttendance />} />
      </Route>
      <Route element={<Protected roles={['admin']} />}>
        <Route path="/app/admin/pengguna" element={<AdminUsers />} />
        <Route path="/app/admin/perusahaan" element={<AdminCompany />} />
        <Route path="/app/admin/audit" element={<AdminAudit />} />
      </Route>
      <Route element={<Protected roles={['karyawan']} />}>
        <Route path="/app/absen" element={<Attend />} />
        <Route path="/app/wajah" element={<FaceRegister />} />
        <Route path="/app/riwayat" element={<AttendHistory />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
