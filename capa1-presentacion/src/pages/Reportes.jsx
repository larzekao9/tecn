import { useState } from 'react'
import { BarrasH, Columnas } from '../components/Graficos'
import { Alerta, Boton, Campo, Chips, Pagina, Tabla, Tarjeta } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { get } from '../lib/api'
import { hoyLocal, primerDiaMes } from '../lib/formato'
import { reglas as r, validar } from '../lib/validar'

/** CU8 - Reportes por rango de fechas (agrupados en SQL). Cada reporte: tabla con TOTAL + grafico + exportar CSV. */
const REPORTES = {
  repPro: {
    titulo: 'Horas programadas por tipo de programa', url: '/reportes/programas',
    columnas: [['tipo', 'Tipo de programa'], ['bloques', 'Bloques'], ['horas', 'Horas programadas'], ['horas_decimal', 'Horas (decimal)']],
    grafico: (f) => <BarrasH titulo="Horas por tipo" unidad="h" formato={(v) => Number(v).toFixed(2)} datos={f.map((x) => ({ etiqueta: x.tipo, valor: x.horas_decimal }))} />,
  },
  repMat: {
    titulo: 'Materiales por tipo', url: '/reportes/materiales',
    columnas: [['tipo', 'Tipo'], ['cantidad', 'Total']],
    grafico: (f) => <BarrasH titulo="Materiales registrados" datos={f.map((x) => ({ etiqueta: x.tipo, valor: x.cantidad }))} />,
  },
  repPau: {
    titulo: 'Spots por anunciante', url: '/reportes/pautas',
    columnas: [['anunciante', 'Anunciante'], ['programados', 'Programados'], ['emitidos', 'Emitidos'], ['no_emitidos', 'No emitidos'], ['pendientes', 'Pendientes']],
    grafico: (f) => <BarrasH titulo="Spots emitidos por anunciante" datos={f.map((x) => ({ etiqueta: x.anunciante, valor: x.emitidos }))} />,
  },
  repCum: {
    titulo: 'Cumplimiento: programado vs. emitido', url: '/reportes/cumplimiento',
    columnas: [['fecha', 'Fecha'], ['programados', 'Programados'], ['emitidos', 'Emitidos'], ['parciales', 'Parciales'], ['no_emitidos', 'No emitidos'], ['sin_registro', 'Sin registro'], ['cumplimiento', '% Cumplimiento']],
    formato: { cumplimiento: (v) => `${Number(v).toFixed(1)} %` },
    grafico: (f) => <Columnas titulo="Cumplimiento por día" unidad="%" max={100} datos={f.map((x) => ({ etiqueta: x.fecha, valor: x.cumplimiento }))} />,
  },
}

export function Reportes() {
  const { puede } = useAuth()
  const disponibles = Object.keys(REPORTES).filter(puede)
  const [cual, setCual] = useState(disponibles[0])
  const [desde, setDesde] = useState(primerDiaMes(hoyLocal()))
  const [hasta, setHasta] = useState(hoyLocal())
  const [res, setRes] = useState(null)
  const [error, setError] = useState(null)
  const [errores, setErrores] = useState({})
  const [cargando, setCargando] = useState(false)
  const def = REPORTES[cual]

  const generar = async () => {
    const err = validar({ desde, hasta }, {
      desde: [r.requerido('La fecha desde'), r.fecha('La fecha desde')],
      hasta: [r.requerido('La fecha hasta'), r.fecha('La fecha hasta'), r.fechaDesde('desde', 'La fecha hasta', 'la fecha desde')],
    })
    setErrores(err)
    if (Object.keys(err).length) return
    setCargando(true); setError(null)
    try { setRes({ cual, ...(await get(def.url, { desde, hasta })).datos }) } catch (e) { setError(e); setRes(null) } finally { setCargando(false) }
  }
  const fmt = (k, v) => (def.formato?.[k] ? def.formato[k](v) : v)
  const exportar = () => {
    const filas = [...res.filas, ...(res.total ? [res.total] : [])]
    const csv = [def.columnas.map(([, t]) => t), ...filas.map((f) => def.columnas.map(([k]) => f[k] ?? ''))]
      .map((l) => l.map((c) => `"${String(c).replaceAll('"', '""')}"`).join(';')).join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([`﻿${csv}`], { type: 'text/csv;charset=utf-8' }))
    a.download = `${cual}_${desde}_${hasta}.csv`
    a.click()
  }

  return (
    <Pagina titulo="Reportes" subtitulo="CU8 · Reportes de programación, contenidos y pauta por rango de fechas (máximo 366 días).">
      <Chips etiqueta="Reporte" valor={cual} onChange={(v) => { setCual(v); setRes(null) }} opciones={disponibles.map((k) => ({ valor: k, texto: REPORTES[k].titulo }))} />
      <Tarjeta>
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-44"><Campo etiqueta="Desde" tipo="date" valor={desde} onChange={setDesde} error={errores.desde} requerido /></div>
          <div className="w-44"><Campo etiqueta="Hasta" tipo="date" valor={hasta} onChange={setHasta} error={errores.hasta} requerido /></div>
          <Boton icono="chart" onClick={generar} cargando={cargando}>Generar reporte</Boton>
          {res?.filas.length > 0 && <Boton variante="secundario" icono="file" onClick={exportar}>Exportar CSV</Boton>}
        </div>
      </Tarjeta>
      {error && <Alerta tipo="error" mensajes={error.errores} />}
      {res && res.cual === cual && (
        <div className="grid gap-5 lg:grid-cols-5">
          <Tarjeta titulo={`${def.titulo} · ${res.desde} a ${res.hasta}`} className="lg:col-span-3">
            <Tabla filtrable={false} porPagina={50} vacio="No hay datos en el rango elegido."
              filas={[...res.filas, ...(res.total ? [{ ...res.total, id: 'total' }] : [])]}
              filaClase={(f) => (f.id === 'total' ? 'bg-superficie-2 font-bold' : '')}
              columnas={def.columnas.map(([k, t], i) => ({ clave: k, titulo: t, alinear: i > 0 && k !== 'estado' ? 'right' : undefined, render: (f) => fmt(k, f[k]) }))} />
          </Tarjeta>
          <Tarjeta className="lg:col-span-2">{def.grafico(res.filas)}</Tarjeta>
        </div>
      )}
    </Pagina>
  )
}
