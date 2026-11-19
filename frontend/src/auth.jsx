import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import { api, getToken, setToken, setUnauthorizedHandler } from './api.js'

const Ctx = createContext(null)
export const useAuth = () => useContext(Ctx)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [ready, setReady] = useState(false)

  const logoutLocal = useCallback(() => { setToken(null); setUser(null) }, [])
  useEffect(() => { setUnauthorizedHandler(logoutLocal) }, [logoutLocal])

  useEffect(() => {
    if (!getToken()) { setReady(true); return }
    api.get('/auth/me').then((r) => setUser(r.user)).catch(() => setToken(null)).finally(() => setReady(true))
  }, [])

  const login = async (email, password) => {
    const r = await api.post('/auth/login', { email, password })
    setToken(r.token); setUser(r.user); return r.user
  }
  const register = async (data) => {
    const r = await api.post('/auth/register', data)
    setToken(r.token); setUser(r.user); return r.user
  }
  const logout = async () => { try { await api.post('/auth/logout') } catch { /* sesi sudah habis */ } logoutLocal() }

  return <Ctx.Provider value={{ user, ready, login, register, logout }}>{children}</Ctx.Provider>
}
