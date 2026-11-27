import { useId, useState } from 'react'
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, LabelList, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import { numero } from '../lib/formato'

/**
 * Graficos de Estadisticas con Recharts. Los colores salen de variables CSS del tema (modo dia/noche):
 * una serie = color primario; categorias = --serie-1..4 (paleta validada); estados = exito / aviso / peligro.
 */

const EJE = { fill: 'var(--color-suave)', fontSize: 12 }
const fechaCorta = (t) => (/^\d{4}-\d{2}-\d{2}/.test(String(t))
  ? new Date(`${String(t).slice(0, 10)}T00:00`).toLocaleDateString('es-BO', { day: 'numeric', month: 'short' }).replace('.', '')
  : String(t))

/** Tarjeta del grafico con titulo y boton "Ver tabla" (alternativa accesible). */
function Panel({ titulo, descripcion, filas, columnas, children, className = '' }) {
  const [tabla, setTabla] = useState(false)
  return (
    <figure className={`rounded-tema-lg border border-borde bg-superficie p-5 shadow-sm ${className}`}>
      <div className="mb-4 flex items-start justify-between gap-2">
        <figcaption>
          <p className="font-semibold">{titulo}</p>
          {descripcion && <p className="text-xs text-suave">{descripcion}</p>}
        </figcaption>
        <button type="button" onClick={() => setTabla(!tabla)} aria-pressed={tabla}
          className="shrink-0 rounded-tema border border-borde px-2 py-0.5 text-xs text-suave hover:bg-superficie-2">
          {tabla ? 'Ver gráfico' : 'Ver tabla'}
        </button>
      </div>
      {filas.length === 0 ? <p className="py-10 text-center text-sm text-suave">Sin datos en el período.</p> : tabla ? (
        <table className="w-full text-sm">
          <thead><tr className="border-b border-borde text-left">{columnas.map((c) => <th key={c} className="py-1">{c}</th>)}</tr></thead>
          <tbody>{filas.map((f, i) => <tr key={i} className="border-b border-borde/60">{f.map((v, j) => <td key={j} className={`py-1 ${j ? 'text-right tabular-nums' : ''}`}>{v}</td>)}</tr>)}</tbody>
        </table>
      ) : children}
    </figure>
  )
}

/** Tooltip con el estilo del sistema. */
function Globo({ active, payload, label, unidad = '', formatoEtiqueta = (x) => x }) {
  if (!active || !payload?.length) return null
  const p = payload[0]
  return (
    <div className="rounded-tema border border-borde bg-superficie px-3 py-2 text-sm shadow-lg">
      <p className="text-xs text-suave">{formatoEtiqueta(label ?? p.name)}</p>
      <p className="font-semibold tabular-nums">{numero(p.value)}{unidad && ` ${unidad}`}</p>
    </div>
  )
}

/** Area en el tiempo (una serie). datos: [{ etiqueta: 'AAAA-MM-DD', valor }] */
export function GraficoArea({ titulo, descripcion, datos, unidad = '', max, className }) {
  const id = useId().replace(/:/g, '')
  return (
    <Panel titulo={titulo} descripcion={descripcion} className={className}
      columnas={['Fecha', unidad ? `Valor (${unidad})` : 'Valor']} filas={datos.map((d) => [d.etiqueta, numero(d.valor)])}>
      <div className="h-64">
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={datos} margin={{ top: 8, right: 8, left: -12, bottom: 0 }}>
            <defs>
              <linearGradient id={`g${id}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--color-primario)" stopOpacity={0.35} />
                <stop offset="100%" stopColor="var(--color-primario)" stopOpacity={0.02} />
              </linearGradient>
            </defs>
            <CartesianGrid vertical={false} stroke="var(--color-borde)" strokeDasharray="3 4" />
            <XAxis dataKey="etiqueta" tickFormatter={fechaCorta} tick={EJE} axisLine={false} tickLine={false} minTickGap={28} />
            <YAxis tick={EJE} axisLine={false} tickLine={false} allowDecimals={false} domain={max ? [0, max] : [0, 'auto']}
              tickFormatter={(v) => `${numero(v)}${unidad === '%' ? '%' : ''}`} />
            <Tooltip content={<Globo unidad={unidad} formatoEtiqueta={fechaCorta} />} cursor={{ stroke: 'var(--color-borde)' }} />
            <Area type="monotone" dataKey="valor" stroke="var(--color-primario)" strokeWidth={2} fill={`url(#g${id})`}
              activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--color-superficie)' }} />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </Panel>
  )
}

/** Barras horizontales ordenadas (ranking). datos: [{ etiqueta, valor }] */
export function GraficoRanking({ titulo, descripcion, datos, unidad = '', limite = 8, className }) {
  const visibles = datos.slice(0, limite)
  return (
    <Panel titulo={titulo} descripcion={descripcion} className={className}
      columnas={['Categoría', unidad ? `Valor (${unidad})` : 'Valor']} filas={datos.map((d) => [d.etiqueta, numero(d.valor)])}>
      <div style={{ height: Math.max(120, visibles.length * 38) }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={visibles} layout="vertical" margin={{ top: 0, right: 40, left: 0, bottom: 0 }} barCategoryGap={8}>
            <XAxis type="number" hide />
            <YAxis type="category" dataKey="etiqueta" width={170} tick={EJE} axisLine={false} tickLine={false}
              tickFormatter={(t) => (String(t).length > 24 ? `${String(t).slice(0, 23)}…` : t)} />
            <Tooltip content={<Globo unidad={unidad} />} cursor={{ fill: 'var(--color-superficie-2)' }} />
            <Bar dataKey="valor" fill="var(--color-primario)" radius={[0, 4, 4, 0]} barSize={16}>
              <LabelList dataKey="valor" position="right" formatter={(v) => numero(v)} style={{ fill: 'var(--color-texto)', fontSize: 12, fontWeight: 600 }} />
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
      {datos.length > limite && <p className="mt-2 text-xs text-suave">y {datos.length - limite} más (ver tabla)</p>}
    </Panel>
  )
}

const SERIES = ['var(--serie-1)', 'var(--serie-2)', 'var(--serie-3)', 'var(--serie-4)']

/** Dona (parte de un todo, pocas categorias). datos: [{ etiqueta, valor, color? }] */
export function GraficoDona({ titulo, descripcion, datos, className }) {
  const total = datos.reduce((a, d) => a + (Number(d.valor) || 0), 0)
  const conColor = datos.map((d, i) => ({ ...d, color: d.color ?? SERIES[i % SERIES.length] }))
  return (
    <Panel titulo={titulo} descripcion={descripcion} className={className}
      columnas={['Categoría', 'Cantidad', '%']} filas={datos.map((d) => [d.etiqueta, numero(d.valor), total ? `${((d.valor / total) * 100).toFixed(1)} %` : '—'])}>
      <div className="flex flex-col items-center gap-6 sm:flex-row">
        <div className="relative size-48 shrink-0">
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={conColor} dataKey="valor" nameKey="etiqueta" innerRadius="62%" outerRadius="100%" paddingAngle={2}
                stroke="var(--color-superficie)" strokeWidth={2} startAngle={90} endAngle={-270}>
                {conColor.map((d) => <Cell key={d.etiqueta} fill={d.color} />)}
              </Pie>
              <Tooltip content={<Globo />} />
            </PieChart>
          </ResponsiveContainer>
          <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
            <div><p className="font-titulo text-2xl font-bold tabular-nums">{numero(total)}</p><p className="text-xs text-suave">total</p></div>
          </div>
        </div>
        <ul className="w-full space-y-2 text-sm">
          {conColor.map((d) => (
            <li key={d.etiqueta} className="flex items-center gap-2">
              <span className="size-3 shrink-0 rounded-sm" style={{ background: d.color }} aria-hidden />
              <span className="flex-1 truncate">{d.etiqueta}</span>
              <span className="font-semibold tabular-nums">{numero(d.valor)}</span>
              <span className="w-14 text-right text-xs text-suave tabular-nums">{total ? `${((d.valor / total) * 100).toFixed(0)} %` : '—'}</span>
            </li>
          ))}
        </ul>
      </div>
    </Panel>
  )
}
