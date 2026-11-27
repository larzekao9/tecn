import { useEffect, useRef, useState } from 'react'
import { useTema } from '../context/TemaContext'
import Icono from './Icono'

const TEMAS = [
  { valor: 'NINOS', texto: 'Niños', desc: 'Colores alegres y formas redondeadas' },
  { valor: 'JOVENES', texto: 'Jóvenes', desc: 'Vibrante y moderno' },
  { valor: 'ADULTOS', texto: 'Adultos', desc: 'Sobrio e institucional' },
]
const MODOS = [
  { valor: 'DIA', texto: 'Día', icono: 'sun' },
  { valor: 'NOCHE', texto: 'Noche', icono: 'moon' },
  { valor: 'AUTO', texto: 'Automáticooo', icono: 'auto' },
]

/** Panel de temas y accesibilidad: segmento, modo dia/noche (manual o por horario), tamano de letra y contraste. */
export default function Accesibilidad() {
  const t = useTema()
  const [abierto, setAbierto] = useState(false)
  const ref = useRef(null)
  useEffect(() => {
    const fuera = (e) => abierto && !ref.current?.contains(e.target) && setAbierto(false)
    const esc = (e) => e.key === 'Escape' && setAbierto(false)
    document.addEventListener('mousedown', fuera)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', fuera); document.removeEventListener('keydown', esc) }
  }, [abierto])

  const boton = (activo) => `flex-1 rounded-tema border px-2 py-1.5 text-sm transition-colors ${activo ? 'border-primario bg-primario text-sobre-primario' : 'border-borde hover:bg-superficie-2'}`

  return (
    <div className="relative" ref={ref}>
      <button type="button" onClick={() => setAbierto(!abierto)} aria-expanded={abierto} aria-haspopup="dialog"
        className="flex items-center gap-1 rounded-tema p-2 hover:bg-superficie-2" title="Temas y accesibilidad">
        <Icono nombre="accessibility" /><span className="sr-only">Temas y accesibilidad</span>
        <Icono nombre={t.modoEfectivo === 'noche' ? 'moon' : 'sun'} className="size-4 text-suave" />
      </button>
      {abierto && (
        <div role="dialog" aria-label="Temas y accesibilidad"
          className="absolute right-0 z-40 mt-2 w-80 space-y-4 rounded-tema-lg border border-borde bg-superficie p-4 shadow-2xl">
          <fieldset>
            <legend className="mb-2 text-sm font-semibold">Tema</legend>
            <div className="grid grid-cols-3 gap-2">
              {TEMAS.map((x) => (
                <button key={x.valor} type="button" aria-pressed={t.tema === x.valor} title={x.desc} onClick={() => t.cambiar({ tema: x.valor })} className={boton(t.tema === x.valor)}>
                  {x.texto}
                </button>
              ))}
            </div>
          </fieldset>
          <fieldset>
            <legend className="mb-2 text-sm font-semibold">Modo</legend>
            <div className="flex gap-2">
              {MODOS.map((x) => (
                <button key={x.valor} type="button" aria-pressed={t.modo === x.valor} onClick={() => t.cambiar({ modo: x.valor })} className={`${boton(t.modo === x.valor)} inline-flex items-center justify-center gap-1`}>
                  <Icono nombre={x.icono} className="size-4" />{x.texto}
                </button>
              ))}
            </div>
            {t.modo === 'AUTO' && <p className="mt-1 text-xs text-suave">Día de 07:00 a 19:00, noche el resto (ahora: {t.modoEfectivo}).</p>}
          </fieldset>
          <fieldset>
            <legend className="mb-2 text-sm font-semibold">Tamaño de letra: {t.escala_fuente} %</legend>
            <div className="flex items-center gap-2">
              <button type="button" className={boton(false)} onClick={() => t.cambiar({ escala_fuente: t.escala_fuente - 10 })} aria-label="Reducir letra">A−</button>
              <input type="range" min={80} max={160} step={5} value={t.escala_fuente} aria-label="Tamaño de letra"
                onChange={(e) => t.cambiar({ escala_fuente: Number(e.target.value) })} className="w-full accent-primario" />
              <button type="button" className={boton(false)} onClick={() => t.cambiar({ escala_fuente: t.escala_fuente + 10 })} aria-label="Aumentar letra">A+</button>
            </div>
            <button type="button" className="mt-1 text-xs text-primario underline" onClick={() => t.cambiar({ escala_fuente: 100 })}>Restablecer (100 %)</button>
          </fieldset>
          <label className="flex cursor-pointer items-center justify-between gap-2">
            <span className="flex items-center gap-2 text-sm font-semibold"><Icono nombre="contrast" />Alto contraste</span>
            <input type="checkbox" role="switch" checked={t.alto_contraste} onChange={(e) => t.cambiar({ alto_contraste: e.target.checked })} className="size-5 accent-primario" />
          </label>
        </div>
      )}
    </div>
  )
}
