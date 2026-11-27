import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { get, post, put, sesion } from '../lib/api'
import { useTema } from './TemaContext'

/**
 * Sesion del usuario: token (Sanctum), perfil, permisos (matriz RBAC de la BD) y menu dinamico.
 * puede('insPar') -> el rol activo tiene ese permiso.
 */
const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [usuario, setUsuario] = useState(null)
  const [cargando, setCargando] = useState(!!sesion.token())
  const [aviso, setAviso] = useState(null)
  const tema = useTema()
  const temporizador = useRef(null)

  const aplicarPerfil = useCallback((u) => {
    setUsuario(u)
    if (u?.preferencias) tema.cargar(u.preferencias)
  }, [tema.cargar]) // eslint-disable-line react-hooks/exhaustive-deps

  // Las preferencias de tema se guardan en el perfil (con un pequeno retardo para el deslizador)
  useEffect(() => {
    tema.registrarSincronizacion((p) => {
      if (!sesion.token()) return
      clearTimeout(temporizador.current)
      temporizador.current = setTimeout(() => put('/auth/preferencias', p).catch(() => {}), 600)
    })
  }, [tema.registrarSincronizacion]) // eslint-disable-line react-hooks/exhaustive-deps

  const refrescar = useCallback(async () => {
    if (!sesion.token()) {
      setCargando(false)
      return null
    }
    try {
      const r = await get('/auth/yo')
      aplicarPerfil(r.usuario)
      return r.usuario
    } catch {
      setUsuario(null)
      return null
    } finally {
      setCargando(false)
    }
  }, [aplicarPerfil])

  useEffect(() => {
    refrescar()
    const expirada = () => {
      setUsuario(null)
      setAviso('Su sesión expiró. Ingrese nuevamente.')
    }
    window.addEventListener('canal11:sesion-expirada', expirada)
    return () => window.removeEventListener('canal11:sesion-expirada', expirada)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const login = async (email, password) => {
    const r = await post('/auth/login', { email, password })
    sesion.guardar(r.token)
    setAviso(null)
    aplicarPerfil(r.usuario)
    return r.usuario
  }

  const logout = async () => {
    try {
      await post('/auth/logout')
    } catch {
      /* el token pudo haber vencido */
    }
    sesion.borrar()
    setUsuario(null)
  }

  const puede = useCallback((permiso) => !!usuario?.permisos?.some((p) => p.toLowerCase() === permiso.toLowerCase()), [usuario])

  return (
    <AuthContext.Provider value={{ usuario, cargando, aviso, login, logout, refrescar, puede, setUsuario: aplicarPerfil }}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => useContext(AuthContext)
