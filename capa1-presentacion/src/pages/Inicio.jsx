import { useState } from 'react'
import { Link } from 'react-router-dom'
import { GraficoArea, GraficoDona, GraficoRanking } from '../components/GraficosPro'
import Icono from '../components/Icono'
import { Boton, Cargando, Chips, ErrorCarga, Html, Pagina, Tarjeta, Vacio, useApi } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { post } from '../lib/api'

/** Pantalla de inicio: solo una bienvenida y accesos rápidos a las secciones que el rol puede usar. */
export function Inicio() {
  const { usuario } = useAuth()
  const accesos = usuario.menu.filter((i) => !['/', '/notificaciones'].includes(i.ruta))
  const hoy = new Date().toLocaleDateString('es-BO', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  return (
    <Pagina titulo={`Bienvenido, ${usuario.nombre}`} subtitulo={<span className="inline-block first-letter:uppercase">{hoy} · {usuario.rol_nombre}</span>}>
      <Tarjeta>
        <div className="flex flex-col items-center gap-3 py-8 text-center">
          <span className="grid size-16 place-items-center rounded-tema-lg bg-primario font-titulo text-3xl font-black text-sobre-primario">11</span>
          <h2 className="font-titulo text-2xl font-bold">Canal 11 · Programación y Contenidos</h2>
          <p className="max-w-xl text-suave">
            Nos alegra verte, {usuario.nombre_completo}. Usa el menú de la izquierda o los accesos de abajo para gestionar
            la programación, los materiales y la pauta publicitaria del canal.
          </p>
        </div>
      </Tarjeta>
      {accesos.length > 0 && (
        <div>
          <h3 className="mb-3 font-titulo text-lg font-semibold">Accesos rápidos</h3>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {accesos.map((i) => (
              <Link key={i.ruta} to={i.ruta}
                className="flex items-center gap-3 rounded-tema-lg border border-borde bg-superficie p-4 shadow-sm transition-colors hover:bg-superficie-2 hover:ring-2 hover:ring-primario">
                <span className="font-medium">{i.titulo}</span>
              </Link>
            ))}
          </div>
        </div>
      )}
    </Pagina>
  )
}

export function Notificaciones() {
  const [filtro, setFiltro] = useState('todas')
  const { datos, cargando, error, recargar } = useApi('/notificaciones', { no_leidas: filtro === 'no' ? 1 : 0 })
  const [abierta, setAbierta] = useState(null)
  const avisar = () => window.dispatchEvent(new Event('canal11:notificaciones'))
  const leer = async (n) => {
    setAbierta(abierta === n.id ? null : n.id)
    if (!n.leida) { await post(`/notificaciones/${n.id}/leer`); recargar(); avisar() }
  }
  const todas = async () => { await post('/notificaciones/leer-todas'); recargar(); avisar() }
  return (
    <Pagina titulo="Notificaciones" subtitulo="Avisos del sistema: parrillas publicadas y cambios, validaciones, recordatorios y alertas."
      acciones={<Boton variante="secundario" icono="check" onClick={todas} disabled={!datos?.sin_leer}>Marcar todas como leídas</Boton>}>
      <Chips valor={filtro} onChange={setFiltro} opciones={[{ valor: 'todas', texto: 'Todas' }, { valor: 'no', texto: `Sin leer (${datos?.sin_leer ?? 0})` }]} />
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <Tarjeta sinPadding>
          {datos?.datos.length === 0 && <Vacio icono="bell" texto="No tiene notificaciones." />}
          <ul className="divide-y divide-borde">
            {datos?.datos.map((n) => (
              <li key={n.id} className={n.leida ? '' : 'bg-primario-tenue/40'}>
                <button type="button" onClick={() => leer(n)} aria-expanded={abierta === n.id} className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-superficie-2">
                  <span className={`mt-1.5 size-2.5 shrink-0 rounded-full ${n.leida ? 'bg-borde' : 'bg-primario'}`} aria-hidden />
                  <span className="min-w-0 flex-1">
                    <span className={`block ${n.leida ? '' : 'font-semibold'}`}>{n.titulo}</span>
                    <span className="block text-xs text-suave">{n.fecha}{n.leida ? '' : ' · sin leer'}</span>
                  </span>
                  <Icono nombre={abierta === n.id ? 'arrowUp' : 'arrowDown'} className="size-4 text-suave" />
                </button>
                {abierta === n.id && (
                  <div className="space-y-2 px-11 pb-4 text-sm">
                    <Html html={n.mensaje} />
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Tarjeta>
      )}
    </Pagina>
  )
}

export function Estadisticas() {
  const [dias, setDias] = useState(30)
  const { datos, cargando, error, recargar } = useApi('/estadisticas', { dias })
  const d = datos?.datos
  const colorEmision = { EMITIDO: 'var(--color-exito)', PARCIAL: 'var(--color-aviso)', NO_EMITIDO: 'var(--color-peligro)' }
  const nombreTipo = useApi('/opciones').datos?.datos.tipos_material ?? {}
  return (
    <Pagina titulo="Estadísticas" subtitulo={d ? `Del ${d.desde} al ${d.hasta}` : ''}
      acciones={<Chips etiqueta="Período" valor={dias} onChange={setDias} opciones={[7, 30, 90, 365].map((n) => ({ valor: n, texto: `${n} días` }))} />}>
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !d ? <Cargando /> : d && (
        <>
          <h2 className="font-titulo text-xl font-semibold">Flujo de accesos</h2>
          <div className="grid items-start gap-5 lg:grid-cols-2">
            <GraficoArea className="lg:col-span-2" titulo="Visitas por día" descripcion="Páginas vistas en el sitio"
              datos={d.visitas_por_dia.map((x) => ({ etiqueta: x.fecha, valor: x.visitas }))} />
            <GraficoRanking titulo="Páginas más visitadas" descripcion="Total histórico por página"
              datos={d.visitas_por_pagina.map((x) => ({ etiqueta: x.pagina, valor: x.visitas }))} />
          </div>

          <h2 className="font-titulo text-xl font-semibold">Actividad del negocio</h2>
          <div className="grid items-start gap-5 lg:grid-cols-2">
            <GraficoDona titulo="Conciliación de los bloques" descripcion="Parrillas publicadas del período (CU7)"
              datos={d.emision_por_estado.map((x) => ({ etiqueta: x.estado.replaceAll('_', ' '), valor: Number(x.cantidad), color: colorEmision[x.estado] }))} />
            <GraficoDona titulo="Materiales por tipo"
              datos={d.materiales_por_tipo.map((x) => ({ etiqueta: nombreTipo[x.tipo] ?? x.tipo, valor: Number(x.cantidad) }))} />
          </div>
        </>
      )}
    </Pagina>
  )
}
