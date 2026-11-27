import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { post } from '../lib/api'
import Accesibilidad from './Accesibilidad'
import Buscador from './Buscador'
import Icono from './Icono'

/** Pie de pagina con el contador de visitas persistente de la pagina actual (tabla visita: un contador por pagina y usuario). */
export function Pie() {
  const { pathname } = useLocation()
  const [visitas, setVisitas] = useState(null)
  useEffect(() => {
    // agrupa rutas con parametros: /parrillas/2026-11-20 -> /parrillas/:fecha
    const pagina = pathname.replace(/\/\d{4}-\d{2}-\d{2}/, '/:fecha').replace(/\/(materiales|pautas)\/[^/]+$/, '/$1/:id')
    post('/visitas', { pagina }).then((r) => setVisitas(r.visitas)).catch(() => setVisitas(null))
  }, [pathname])
  return (
    <footer className="border-t border-borde bg-superficie px-4 py-4 text-sm text-suave">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-end gap-2">
        <p className="flex items-center gap-1.5" aria-live="polite">
          <Icono nombre="eye" className="size-4" />
          Esta página fue visitada <strong className="text-texto tabular-nums">{visitas ?? '…'}</strong> {visitas === 1 ? 'vez' : 'veces'}
        </p>
      </div>
    </footer>
  )
}

/**
 * Menu lateral renderizado desde la BD (tabla recurso filtrada por los permisos del rol, en su orden),
 * agrupado en los paquetes del sistema que manda el servidor (Inicio y Notificaciones van arriba, sin paquete).
 */
function Menu({ menu, onNavegar }) {
  const { pathname } = useLocation()
  const grupos = []
  for (const i of menu) {
    const g = grupos.find((x) => x.paquete === (i.paquete ?? null))
    if (g) g.items.push(i)
    else grupos.push({ paquete: i.paquete ?? null, items: [i] })
  }
  const activa = (r) => (r === '/' ? pathname === '/' : pathname === r || pathname.startsWith(`${r}/`))
  // todas las carpetas empiezan abiertas; el usuario puede cerrar las que quiera
  const [abiertas, setAbiertas] = useState(() => new Set(menu.map((i) => i.paquete).filter(Boolean)))
  useEffect(() => {
    const g = grupos.find((x) => x.paquete && x.items.some((i) => activa(i.ruta)))
    if (g) setAbiertas((s) => (s.has(g.paquete) ? s : new Set(s).add(g.paquete)))
  }, [pathname]) // eslint-disable-line react-hooks/exhaustive-deps
  const alternar = (p) => setAbiertas((s) => { const n = new Set(s); n.has(p) ? n.delete(p) : n.add(p); return n })

  const enlace = (i, sangria) => (
    <li key={i.ruta}>
      <NavLink to={i.ruta} end={i.ruta === '/'} onClick={onNavegar}
        className={({ isActive }) => `flex items-center gap-3 rounded-tema px-3 py-2 text-sm transition-colors ${sangria ? 'ml-4 border-l border-borde pl-4' : ''} ${isActive ? 'bg-primario text-sobre-primario font-semibold' : 'hover:bg-superficie-2'}`}>
        {i.titulo}
      </NavLink>
    </li>
  )
  return (
    <nav aria-label="Menú principal" className="p-3">
      <ul className="space-y-0.5">
        {grupos.map((g) => {
          if (!g.paquete) return g.items.map((i) => enlace(i, false))
          const abierta = abiertas.has(g.paquete)
          const id = `paquete-${g.paquete.replace(/\W+/g, '-')}`
          return (
            <li key={g.paquete}>
              <button type="button" onClick={() => alternar(g.paquete)} aria-expanded={abierta} aria-controls={id}
                className="flex w-full items-center gap-2.5 rounded-tema px-3 py-2 text-left text-sm font-semibold transition-colors hover:bg-superficie-2">
                <Icono nombre={abierta ? 'folderOpen' : 'folder'} className="size-5 text-primario" />
                <span className="flex-1">{g.paquete}</span>
                <Icono nombre="chevron" className={`size-4 text-suave transition-transform ${abierta ? 'rotate-90' : ''}`} />
              </button>
              {abierta && <ul id={id} className="mt-0.5 space-y-0.5">{g.items.map((i) => enlace(i, true))}</ul>}
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

export default function Layout() {
  const { usuario, logout } = useAuth()
  const [lateral, setLateral] = useState(false)
  const { pathname } = useLocation()
  useEffect(() => { setLateral(false) }, [pathname])

  return (
    <div className="flex min-h-screen flex-col">
      <a href="#contenido" className="sr-only z-50 rounded bg-primario p-2 text-sobre-primario focus:not-sr-only focus:absolute focus:top-2 focus:left-2">Saltar al contenido</a>
      <header className="sticky top-0 z-30 border-b border-borde bg-superficie/95 backdrop-blur">
        <div className="flex items-center gap-3 px-3 py-2 sm:px-4">
          <button type="button" className="rounded-tema p-2 hover:bg-superficie-2 lg:hidden" onClick={() => setLateral(!lateral)} aria-label="Abrir menú" aria-expanded={lateral}>
            <Icono nombre="menu" />
          </button>
          <Link to="/" className="flex shrink-0 items-center gap-2" aria-label="Canal 11 - inicio">
            <span className="grid size-9 place-items-center rounded-tema bg-primario font-titulo text-lg font-black text-sobre-primario">11</span>
            <span className="hidden leading-tight sm:block"><span className="block font-titulo font-bold">Canal 11</span><span className="block text-xs text-suave">Programación y Contenidos</span></span>
          </Link>
          <div className="flex flex-1 justify-center px-2"><Buscador /></div>
          <Accesibilidad />
          <div className="flex items-center gap-2 p-1.5">
            <span className="grid size-8 place-items-center rounded-full bg-acento text-sm font-bold text-white contraste:text-sobre-primario">{usuario.nombre[0]}{usuario.apellido[0]}</span>
            <span className="hidden text-left leading-tight md:block"><span className="block text-sm font-semibold">{usuario.nombre_completo}</span><span className="block text-xs text-suave">{usuario.rol_nombre}</span></span>
          </div>
          <button type="button" onClick={logout} className="rounded-tema p-2 text-peligro hover:bg-superficie-2" aria-label="Cerrar sesión" title="Cerrar sesión"><Icono nombre="logout" className="size-5" /></button>
        </div>
      </header>

      <div className="flex flex-1">
        <aside className={`fixed inset-y-0 left-0 z-20 w-64 overflow-y-auto border-r border-borde bg-superficie pt-16 transition-transform lg:sticky lg:top-[57px] lg:h-[calc(100vh-57px)] lg:translate-x-0 lg:pt-0 ${lateral ? 'translate-x-0' : '-translate-x-full'}`}>
          <Menu menu={usuario.menu} onNavegar={() => setLateral(false)} />
        </aside>
        {lateral && <div className="fixed inset-0 z-10 bg-black/40 lg:hidden" onClick={() => setLateral(false)} aria-hidden />}
        <main id="contenido" tabIndex={-1} className="min-w-0 flex-1 p-4 sm:p-6">
          <div className="mx-auto max-w-7xl"><Outlet /></div>
        </main>
      </div>
      <Pie />
    </div>
  )
}
