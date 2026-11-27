import { useState } from 'react'
import { numero } from '../lib/formato'

/**
 * Graficos sin librerias (SVG + Tailwind). Reglas: una sola serie por grafico (color primario del tema, sin leyenda:
 * el titulo la nombra), marcas finas con extremo redondeado anclado a la linea base, ejes y grilla discretos,
 * tooltip al pasar el cursor o enfocar con teclado, y una vista de tabla equivalente (accesibilidad).
 */

/** Tope "redondo" para el eje Y (1, 2, 5 x 10^n): da marcas legibles como 0 / 50 / 100. */
const topeRedondo = (v) => {
  if (v <= 0) return 1
  const p = 10 ** Math.floor(Math.log10(v))
  const n = v / p
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p
}

/** "2026-11-25" -> "25 nov" (en el idioma del navegador); otro texto se deja igual. */
const fechaCorta = (t) => (/^\d{4}-\d{2}-\d{2}/.test(String(t))
  ? new Date(`${String(t).slice(0, 10)}T00:00`).toLocaleDateString('es-BO', { day: 'numeric', month: 'short' }).replace('.', '')
  : String(t))

function Marco({ titulo, descripcion, filas, columnas, children }) {
  const [tabla, setTabla] = useState(false)
  return (
    <figure className="space-y-2">
      <div className="flex items-start justify-between gap-2">
        <figcaption>
          <p className="font-semibold">{titulo}</p>
          {descripcion && <p className="text-xs text-suave">{descripcion}</p>}
        </figcaption>
        <button type="button" onClick={() => setTabla(!tabla)} aria-pressed={tabla}
          className="rounded-tema border border-borde px-2 py-0.5 text-xs text-suave hover:bg-superficie-2">
          {tabla ? 'Ver gráfico' : 'Ver tabla'}
        </button>
      </div>
      {tabla ? (
        <table className="w-full text-sm">
          <thead><tr className="border-b border-borde text-left">{columnas.map((c) => <th key={c} className="py-1">{c}</th>)}</tr></thead>
          <tbody>{filas.map((f, i) => <tr key={i} className="border-b border-borde/60">{f.map((v, j) => <td key={j} className={`py-1 ${j ? 'text-right tabular-nums' : ''}`}>{v}</td>)}</tr>)}</tbody>
        </table>
      ) : children}
    </figure>
  )
}

/** Barras horizontales (magnitud por categoria). datos: [{ etiqueta, valor }] */
export function BarrasH({ titulo, descripcion, datos, formato = numero, unidad = '', limite = 8 }) {
  const max = Math.max(1, ...datos.map((d) => Number(d.valor) || 0))
  const visibles = datos.slice(0, limite)
  const resto = datos.length - visibles.length
  return (
    <Marco titulo={titulo} descripcion={descripcion} columnas={['Categoría', `Valor${unidad ? ` (${unidad})` : ''}`]}
      filas={datos.map((d) => [d.etiqueta, formato(d.valor)])}>
      {datos.length === 0 ? <p className="py-6 text-center text-sm text-suave">Sin datos en el período.</p> : (
        <ul className="space-y-2.5">
          {visibles.map((d) => (
            <li key={d.etiqueta} className="group" tabIndex={0} title={`${d.etiqueta}: ${formato(d.valor)} ${unidad}`}>
              <div className="mb-1 flex justify-between gap-2 text-sm">
                <span className="truncate">{String(d.etiqueta).replaceAll('_', ' ')}</span>
                <span className="font-semibold tabular-nums">{formato(d.valor)}{unidad && ` ${unidad}`}</span>
              </div>
              <div className="h-2 w-full rounded-full bg-superficie-2">
                <div className="h-2 rounded-full bg-primario transition-all group-hover:bg-primario-fuerte group-focus:bg-primario-fuerte"
                  style={{ width: `${Math.max(1.5, (Number(d.valor) / max) * 100)}%` }} />
              </div>
            </li>
          ))}
          {resto > 0 && <li className="text-xs text-suave">y {resto} más (ver tabla)</li>}
        </ul>
      )}
    </Marco>
  )
}

/** Columnas en el tiempo (una serie). datos: [{ etiqueta, valor }] */
export function Columnas({ titulo, descripcion, datos, formato = numero, unidad = '', max: maxFijo, alto = 220 }) {
  const [activo, setActivo] = useState(null)
  const W = 640
  const H = alto
  const pad = { l: 38, r: 12, t: 12, b: 26 }
  const valores = datos.map((d) => Number(d.valor) || 0)
  const max = maxFijo ?? topeRedondo(Math.max(0, ...valores))
  const ancho = (W - pad.l - pad.r) / Math.max(1, datos.length)
  const barra = Math.max(2, Math.min(24, ancho - 2))
  const y = (v) => pad.t + (H - pad.t - pad.b) * (1 - v / max)
  const marcas = [0, max / 2, max]
  // fechas del eje X: como maximo 6, repartidas y sin pisarse (siempre la primera y la ultima)
  const n = Math.min(datos.length, 6)
  const conEtiqueta = new Set(Array.from({ length: n }, (_, k) => Math.round((k * (datos.length - 1)) / Math.max(1, n - 1))))
  const total = valores.reduce((a, b) => a + b, 0)
  const promedio = datos.length ? total / datos.length : 0
  const d = activo !== null ? datos[activo] : null
  const izq = d ? ((pad.l + ancho * activo + ancho / 2) / W) * 100 : 0
  return (
    <Marco titulo={titulo} descripcion={descripcion} columnas={['Fecha', `Valor${unidad ? ` (${unidad})` : ''}`]}
      filas={datos.map((x) => [x.etiqueta, formato(x.valor)])}>
      {datos.length === 0 ? <p className="py-6 text-center text-sm text-suave">Sin datos en el período.</p> : (
        <div className="relative">
          <p className="mb-1 text-xs text-suave">
            {unidad === '%'
              ? <>Promedio del período: <b className="text-texto tabular-nums">{formato(Math.round(promedio * 10) / 10)} %</b></>
              : <>Total: <b className="text-texto tabular-nums">{formato(total)}</b> · promedio por día: <b className="text-texto tabular-nums">{formato(Math.round(promedio * 10) / 10)}</b></>}
          </p>
          <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={titulo} onMouseLeave={() => setActivo(null)}>
            {marcas.map((m) => (
              <g key={m}>
                <line x1={pad.l} x2={W - pad.r} y1={y(m)} y2={y(m)} className="stroke-borde" strokeDasharray={m ? '3 4' : undefined} />
                <text x={pad.l - 6} y={y(m) + 4} textAnchor="end" className="fill-suave text-[11px]">{formato(Math.round(m * 10) / 10)}</text>
              </g>
            ))}
            {datos.map((x, i) => {
              const v = Number(x.valor) || 0
              const cx = pad.l + ancho * i + ancho / 2
              const top = y(v)
              const h = Math.max(0, y(0) - top)
              const r = Math.min(4, barra / 2, h)
              return (
                <g key={i} onMouseEnter={() => setActivo(i)} onFocus={() => setActivo(i)} tabIndex={0} aria-label={`${x.etiqueta}: ${formato(v)} ${unidad}`}>
                  <rect x={pad.l + ancho * i} y={pad.t} width={ancho} height={H - pad.t - pad.b} className="fill-transparent" />
                  {h > 0 && (
                    <path className={activo === i ? 'fill-primario-fuerte' : 'fill-primario'}
                      d={`M${cx - barra / 2},${y(0)} V${top + r} Q${cx - barra / 2},${top} ${cx - barra / 2 + r},${top} H${cx + barra / 2 - r} Q${cx + barra / 2},${top} ${cx + barra / 2},${top + r} V${y(0)} Z`} />
                  )}
                  {conEtiqueta.has(i) && (
                    <text x={cx} y={H - 7} textAnchor={i === 0 && datos.length > 1 ? 'start' : i === datos.length - 1 && datos.length > 1 ? 'end' : 'middle'}
                      className="fill-suave text-[11px]">{fechaCorta(x.etiqueta)}</text>
                  )}
                </g>
              )
            })}
          </svg>
          {d && (
            <div className="pointer-events-none absolute top-6 rounded-tema border border-borde bg-superficie px-3 py-1.5 text-sm whitespace-nowrap shadow-lg"
              style={izq > 70 ? { right: `${100 - izq}%` } : { left: `${izq}%`, transform: izq < 30 ? 'none' : 'translateX(-50%)' }}>
              <p className="text-xs text-suave">{fechaCorta(d.etiqueta)}</p>
              <p className="font-semibold tabular-nums">{formato(d.valor)}{unidad && ` ${unidad}`}</p>
            </div>
          )}
        </div>
      )}
    </Marco>
  )
}

/** Medidor de progreso (ej. cumplimiento) con texto. */
export function Medidor({ valor, etiqueta }) {
  const v = Math.max(0, Math.min(100, Number(valor) || 0))
  return (
    <div className="space-y-1" role="meter" aria-valuenow={v} aria-valuemin={0} aria-valuemax={100} aria-label={etiqueta}>
      <div className="flex justify-between text-sm"><span>{etiqueta}</span><span className="font-semibold tabular-nums">{v.toFixed(1)} %</span></div>
      <div className="h-2.5 w-full rounded-full bg-superficie-2"><div className="h-2.5 rounded-full bg-primario" style={{ width: `${v}%` }} /></div>
    </div>
  )
}
