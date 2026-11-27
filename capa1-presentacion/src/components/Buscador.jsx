import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { get } from '../lib/api'
import Icono from './Icono'

/** Busqueda global predictiva de informacion (programas, materiales, parrillas, pautas y usuarios que el rol puede consultar). */
export default function Buscador() {
  const [q, setQ] = useState('')
  const [res, setRes] = useState([])
  const [abierto, setAbierto] = useState(false)
  const [activo, setActivo] = useState(-1)
  const [cargando, setCargando] = useState(false)
  const nav = useNavigate()
  const ref = useRef(null)

  useEffect(() => {
    if (q.trim().length < 2) { setRes([]); return undefined }
    setCargando(true)
    const t = setTimeout(() => {
      get('/buscar', { q }).then((r) => { setRes(r.datos); setActivo(-1); setAbierto(true) }).catch(() => setRes([])).finally(() => setCargando(false))
    }, 280)
    return () => clearTimeout(t)
  }, [q])

  useEffect(() => {
    const fuera = (e) => !ref.current?.contains(e.target) && setAbierto(false)
    document.addEventListener('mousedown', fuera)
    return () => document.removeEventListener('mousedown', fuera)
  }, [])

  const ir = (r) => { setAbierto(false); setQ(''); nav(r.enlace) }
  const tecla = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActivo((a) => Math.min(res.length - 1, a + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActivo((a) => Math.max(0, a - 1)) }
    else if (e.key === 'Enter' && res[Math.max(0, activo)]) { e.preventDefault(); ir(res[Math.max(0, activo)]) }
    else if (e.key === 'Escape') setAbierto(false)
  }

  return (
    <div className="relative w-full max-w-xl" ref={ref}>
      <label htmlFor="buscador-global" className="sr-only">Buscar en el sistema</label>
      <Icono nombre="search" className="pointer-events-none absolute top-2.5 left-3 size-5 text-suave" />
      <input id="buscador-global" type="search" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={tecla} onFocus={() => res.length && setAbierto(true)}
        placeholder="Buscar programas, materiales, pautas, parrillas..." autoComplete="off"
        role="combobox" aria-expanded={abierto} aria-controls="resultados-busqueda" aria-activedescendant={activo >= 0 ? `res-${activo}` : undefined}
        className="w-full rounded-full border border-borde bg-superficie-2 py-2 pr-4 pl-10 text-sm focus:bg-superficie" />
      {cargando && <span className="absolute top-3 right-3 size-4 animate-spin rounded-full border-2 border-primario border-t-transparent" aria-hidden />}
      {abierto && q.trim().length >= 2 && (
        <ul id="resultados-busqueda" role="listbox" className="absolute z-40 mt-2 max-h-96 w-full overflow-y-auto rounded-tema-lg border border-borde bg-superficie py-1 shadow-2xl">
          {res.length === 0 && <li className="px-4 py-3 text-sm text-suave">Sin resultados para “{q}”.</li>}
          {res.map((r, i) => (
            <li key={i} id={`res-${i}`} role="option" aria-selected={i === activo}>
              <button type="button" onMouseEnter={() => setActivo(i)} onClick={() => ir(r)}
                className={`flex w-full items-start gap-3 px-4 py-2 text-left ${i === activo ? 'bg-primario-tenue' : 'hover:bg-superficie-2'}`}>
                <span className="mt-0.5 rounded-full bg-superficie-2 px-2 py-0.5 text-xs font-semibold text-suave">{r.tipo}</span>
                <span className="min-w-0">
                  <span className="block truncate font-medium">{r.titulo}</span>
                  <span className="block truncate text-xs text-suave">{r.detalle}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
