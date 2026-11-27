import { useState } from 'react'
import Accesibilidad from '../components/Accesibilidad'
import Icono from '../components/Icono'
import { Pie } from '../components/Layout'
import { Alerta, Boton, Campo } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { reglas as r, validar } from '../lib/validar'

export function Login() {
  const { login, aviso } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errores, setErrores] = useState({})
  const [error, setError] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const [ver, setVer] = useState(false)

  const enviar = async (e) => {
    e.preventDefault()
    const err = validar({ email, password }, {
      email: [r.requerido('El correo'), r.email()],
      password: [r.requerido('La contraseña')],
    })
    setErrores(err)
    setError(null)
    if (Object.keys(err).length) return
    setEnviando(true)
    try {
      await login(email.trim(), password)
    } catch (ex) {
      setError(ex)
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-screen flex-col">
      <header className="flex items-center justify-between px-4 py-3">
        <div className="flex items-center gap-2">
          <span className="grid size-10 place-items-center rounded-tema bg-primario font-titulo text-xl font-black text-sobre-primario">11</span>
          <span className="font-titulo text-lg font-bold">Canal 11</span>
        </div>
        <Accesibilidad />
      </header>
      <main className="grid flex-1 place-items-center p-4">
        <div className="grid w-full max-w-4xl overflow-hidden rounded-tema-lg border border-borde bg-superficie shadow-xl md:grid-cols-2">
          <div className="hidden flex-col justify-between bg-primario p-8 text-sobre-primario md:flex">
            <div>
              <h1 className="font-titulo text-3xl font-bold">Programación y Contenidos</h1>
              <p className="mt-3 opacity-90">Parrilla diaria, materiales, escaleta del noticiero, pauta publicitaria, emisión real y reportes del canal en un solo lugar.</p>
            </div>
            <ul className="space-y-2 text-sm opacity-95">
              {['Control de acceso por roles', 'Bitácora de seguridad', 'Temas y accesibilidad'].map((t) => (
                <li key={t} className="flex items-center gap-2"><Icono nombre="check" className="size-4" />{t}</li>
              ))}
            </ul>
          </div>
          <form onSubmit={enviar} noValidate className="space-y-4 p-6 sm:p-8">
            <div>
              <h2 className="font-titulo text-2xl font-bold">Iniciar sesión</h2>
              <p className="text-sm text-suave">Ingrese con su correo y contraseña.</p>
            </div>
            {aviso && <Alerta tipo="aviso" mensajes={aviso} />}
            {error && <Alerta tipo="error" mensajes={error.errores} />}
            <Campo etiqueta="Correo electrónico" tipo="email" valor={email} onChange={setEmail} error={errores.email} requerido autoComplete="username" />
            <div className="relative">
              <Campo etiqueta="Contraseña" tipo={ver ? 'text' : 'password'} valor={password} onChange={setPassword} error={errores.password} requerido autoComplete="current-password" />
              <button type="button" onClick={() => setVer(!ver)} className="absolute top-7 right-2 rounded p-1.5 text-suave hover:text-texto" aria-label={ver ? 'Ocultar contraseña' : 'Mostrar contraseña'}>
                <Icono nombre="eye" className="size-5" />
              </button>
            </div>
            <Boton type="submit" cargando={enviando} icono="lock" className="w-full">Ingresar</Boton>
          </form>
        </div>
      </main>
      <Pie />
    </div>
  )
}
