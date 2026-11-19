import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth.jsx'
import { Field, useForm, useToast } from '../ui.jsx'

function Wrap({ title, subtitle, children }) {
  return (
    <div className="auth-wrap">
      <div className="auth-card stack">
        <Link to="/" className="brand" style={{ justifyContent: 'center' }}><span className="logo">H</span> HRIS</Link>
        <div className="card stack">
          <div><h1>{title}</h1><p className="muted" style={{ marginTop: 4 }}>{subtitle}</p></div>
          {children}
        </div>
      </div>
    </div>
  )
}

export function Login() {
  const { login } = useAuth()
  const nav = useNavigate()
  const loc = useLocation()
  const toast = useToast()
  const f = useForm({ email: '', password: '' })
  const [busy, setBusy] = useState(false)
  const submit = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await login(f.values.email, f.values.password); nav(loc.state?.from || '/app', { replace: true }) }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') } finally { setBusy(false) }
  }
  return (
    <Wrap title="Masuk" subtitle="Gunakan akun pelamar, karyawan, HRD, atau admin.">
      <form className="stack" onSubmit={submit}>
        <Field label="Email" error={f.errors.email?.[0]}><input type="email" required autoFocus value={f.values.email} onChange={f.set('email')} /></Field>
        <Field label="Kata sandi"><input type="password" required value={f.values.password} onChange={f.set('password')} /></Field>
        <button className="btn primary block" disabled={busy}>{busy ? 'Memproses…' : 'Masuk'}</button>
      </form>
      <p className="small muted">Belum punya akun pelamar? <Link to="/register">Daftar di sini</Link></p>
    </Wrap>
  )
}

export function Register() {
  const { register } = useAuth()
  const nav = useNavigate()
  const toast = useToast()
  const f = useForm({ name: '', email: '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const submit = async (e) => {
    e.preventDefault(); setBusy(true)
    try { await register(f.values); toast('Akun berhasil dibuat. Lengkapi profil Anda.'); nav('/app/profil', { replace: true }) }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') } finally { setBusy(false) }
  }
  const E = (k) => f.errors[k]?.[0]
  return (
    <Wrap title="Daftar Pelamar" subtitle="Buat akun untuk melamar lowongan dan memantau hasil seleksi.">
      <form className="stack" onSubmit={submit}>
        <Field label="Nama lengkap" error={E('name')}><input required value={f.values.name} onChange={f.set('name')} /></Field>
        <Field label="Email" error={E('email')}><input type="email" required value={f.values.email} onChange={f.set('email')} /></Field>
        <Field label="Kata sandi" error={E('password')} hint="Minimal 8 karakter"><input type="password" required minLength={8} value={f.values.password} onChange={f.set('password')} /></Field>
        <Field label="Ulangi kata sandi"><input type="password" required value={f.values.password_confirmation} onChange={f.set('password_confirmation')} /></Field>
        <button className="btn primary block" disabled={busy}>{busy ? 'Memproses…' : 'Buat akun'}</button>
      </form>
      <p className="small muted">Sudah punya akun? <Link to="/login">Masuk</Link></p>
    </Wrap>
  )
}
