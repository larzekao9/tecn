import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Medidor } from '../components/Graficos'
import Icono from '../components/Icono'
import { Alerta, Boton, Cargando, Campo, Confirmar, ErrorCarga, Formulario, Insignia, Modal, Pagina, Tabla, Tarjeta, Vacio, useApi, useMensaje } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { del, post, put, seg } from '../lib/api'
import { duracion, fechaLarga, hora, hoyLocal } from '../lib/formato'
import { reglas as r } from '../lib/validar'

/* =================================================================== lista */

export function Parrillas() {
  const { puede } = useAuth()
  const nav = useNavigate()
  const [mes, setMes] = useState('')
  const { datos, cargando, error, recargar } = useApi('/parrillas', { filtro: mes || '*' })
  const [nueva, setNueva] = useState(false)
  const hoy = hoyLocal()
  return (
    <Pagina titulo="Parrillas de programación" subtitulo="CU3 · Una parrilla por día, por bloques horarios; se arma en borrador y se publica."
      acciones={puede('insPar') && <Boton icono="plus" onClick={() => setNueva(true)}>Nueva parrilla</Boton>}>
      <div className="flex flex-wrap items-end gap-3">
        <div className="w-48"><Campo etiqueta="Filtrar por mes" tipo="month" valor={mes} onChange={setMes} /></div>
        {mes && <Boton variante="fantasma" onClick={() => setMes('')}>Ver todas</Boton>}
      </div>
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <Tabla titulo="Parrillas" filas={datos?.datos} columnas={[
          { clave: 'fecha', titulo: 'Fecha', render: (p) => <Link to={`/parrillas/${p.fecha}`} className="font-semibold text-primario underline">{p.fecha}</Link> },
          { clave: 'dia', titulo: 'Día', render: (p) => <span className="inline-block first-letter:uppercase">{fechaLarga(p.fecha).split(',')[0]}</span> },
          { clave: 'estado', titulo: 'Estado', render: (p) => <Insignia valor={p.estado} /> },
          { clave: 'bloques', titulo: 'Bloques', alinear: 'right' },
          { clave: 'creado_por', titulo: 'Creada por' },
          { clave: 'fecha_publicacion', titulo: 'Publicada' },
        ]} filaClase={(p) => (p.fecha === hoy ? 'bg-primario-tenue/50' : '')} />
      )}
      <Modal abierto={nueva} titulo="Nueva parrilla" onCerrar={() => setNueva(false)}>
        <Formulario textoBoton="Crear en borrador" onCancelar={() => setNueva(false)} inicial={{ fecha: hoy }}
          campos={[{ nombre: 'fecha', etiqueta: 'Fecha', tipo: 'date', requerido: true, min: hoy }]}
          esquema={{ fecha: [r.requerido('La fecha'), r.fecha('La fecha'), r.noPasada(hoy, 'La fecha')] }}
          onEnviar={async (v) => { await post('/parrillas', v); nav(`/parrillas/${v.fecha}`) }} />
      </Modal>
    </Pagina>
  )
}

/* =================================================================== detalle */

const PRENSA = ['NOTICIERO']

/** 'HH:MM:SS' + segundos -> 'HH:MM:SS' (hora en que termina un spot). */
const finSpot = (hhmmss, seg) => {
  const [h, m, s] = String(hhmmss).split(':').map(Number)
  const t = Math.min(h * 3600 + m * 60 + (s || 0) + (Number(seg) || 0), 86399)
  return [Math.floor(t / 3600), Math.floor(t / 60) % 60, t % 60].map((x) => String(x).padStart(2, '0')).join(':')
}

/** 'HH:MM' + segundos de duración -> 'HH:MM' (tope 23:59 del mismo día). */
const sumarHora = (hhmm, seg) => {
  const [h, m] = String(hhmm).split(':').map(Number)
  const total = Math.min(h * 60 + m + Math.ceil((Number(seg) || 0) / 60), 1439)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

/** Opcion de un select de materiales: se envia el id y se muestra el titulo con su duracion. */
const opcionMaterial = (m) => ({ valor: String(m.id), texto: `${m.titulo} (${duracion(m.duracion_seg)})` })

export function ParrillaDetalle() {
  const { fecha } = useParams()
  const nav = useNavigate()
  const { puede } = useAuth()
  const { datos, cargando, error, recargar, setDatos } = useApi(`/parrillas/${fecha}`)
  const ops = useApi('/opciones')
  const [msg, mostrar] = useMensaje()
  const [nuevoBloque, setNuevoBloque] = useState(false)
  const [quitar, setQuitar] = useState(null)
  const [cambiar, setCambiar] = useState(null)
  const [verSpots, setVerSpots] = useState(null)
  const [escaleta, setEscaleta] = useState(null)
  const [publicar, setPublicar] = useState(false)
  const [eliminar, setEliminar] = useState(false)
  const val = useApi(puede('lisVal') ? `/parrillas/${fecha}/validacion` : null)
  const p = datos?.datos
  const o = ops.datos?.datos
  const v5 = val.datos?.datos
  const validacionDe = Object.fromEntries((v5?.bloques ?? []).map((b) => [b.id, b.estado]))
  const tras = (res) => { mostrar(res); if (res?.datos?.bloques) setDatos({ datos: res.datos }); else recargar(); val.recargar() }

  const siguienteHora = p?.bloques.length ? hora(p.bloques[p.bloques.length - 1].hora_fin) : '20:00'
  const programas = (o?.programas ?? []).map((x) => x.nombre)
  const materialesPara = (prog) => (o?.materiales ?? []).filter((m) => m.tipo !== 'SPOT' && (!prog || !m.programa || m.programa.toLowerCase() === prog.toLowerCase()))

  if (cargando && !p) return <Cargando />
  return (
    <Pagina titulo={`Parrilla del ${fecha}`} subtitulo={<span className="inline-block first-letter:uppercase">{fechaLarga(fecha)}</span>}
      acciones={
        <>
          <Boton variante="secundario" onClick={() => nav('/parrillas')}>Volver</Boton>
          {p && puede('lisVal') && <Boton variante="secundario" icono="check" onClick={() => nav(`/validacion?fecha=${fecha}`)}>Validar contenido</Boton>}
          {p?.estado === 'PUBLICADA' && puede('conPar') && <Boton variante="secundario" icono="radio" onClick={() => nav(`/emision?fecha=${fecha}`)}>Conciliación</Boton>}
          {p?.editable && puede('eliPar') && <Boton variante="secundario" icono="trash" onClick={() => setEliminar(true)}>Eliminar</Boton>}
          {p?.editable && puede('pubPar') && <Boton icono="send" onClick={() => setPublicar(true)}>Revisar y publicar</Boton>}
        </>
      }>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {p && (
        <>
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <Insignia valor={p.estado} />
            <span className="text-suave">Creada por {p.creado_por}{p.fecha_publicacion && ` · publicada ${p.fecha_publicacion}`}</span>
          </div>
          {p.estado === 'PUBLICADA' && <Alerta tipo="info" mensajes="La parrilla está PUBLICADA: es la única fuente del orden de transmisión. Aún se puede cambiar el material de un bloque o la escaleta mientras el bloque no haya empezado; el Programador y el Encargado de Contenidos reciben un aviso y el bloque debe volver a validarse." />}
          {p.estado === 'BORRADOR' && v5 && (v5.validada
            ? <Alerta tipo="exito" titulo="Contenido validado" mensajes="El Encargado de Contenidos dejó LISTOS todos los bloques: la parrilla ya se puede publicar." />
            : <Alerta tipo="aviso" titulo="Falta validar el contenido (CU5)" mensajes={`Para publicar, el Encargado de Contenidos debe revisar y dejar LISTOS todos los bloques: van ${v5.resumen.listos} de ${v5.resumen.total}.`} />)}
          <Tarjeta titulo={`Bloques (${p.bloques.length})`} acciones={p.editable && puede('insBlo') && <Boton tam="sm" icono="plus" onClick={() => setNuevoBloque(true)}>Agregar bloque</Boton>}>
              {p.bloques.length === 0 ? <Vacio texto="La parrilla no tiene bloques. Agregue el primero." /> : (
                <ol className="space-y-2">
                  {p.bloques.map((b) => {
                    const deNoticias = b.tipo === 'PROGRAMA' && PRENSA.includes(b.tipo_programa)
                    const spots = p.spots.filter((s) => s.id_bloque === b.id)
                    return (
                      <li key={b.id} className={`rounded-tema border border-borde p-3 ${b.tipo === 'PAUSA_COMERCIAL' ? 'bg-superficie-2' : ''}`}>
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <div className="flex items-start gap-3">
                            <span className="rounded-tema bg-primario-tenue px-2 py-1 font-mono text-sm font-semibold text-primario">{hora(b.hora_inicio)}–{hora(b.hora_fin)}</span>
                            <div>
                              <p className="font-semibold">{b.tipo === 'PAUSA_COMERCIAL' ? 'Pausa comercial' : b.programa}
                                <span className="ml-2 text-xs font-normal text-suave">{b.tipo_programa || b.tipo.replaceAll('_', ' ')} · {duracion(b.duracion_seg)}</span>
                                {p.estado === 'PUBLICADA' && <span className="ml-2"><Insignia valor={b.estado_bloque} /></span>}
                                {validacionDe[b.id] && <span className="ml-2"><Insignia valor={validacionDe[b.id]} /></span>}</p>
                              {b.tipo === 'PROGRAMA' && (
                                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                  {b.notas > 0 ? <span>Escaleta de {b.notas} nota(s)</span>
                                    : b.material ? <Link to={`/materiales/${b.material}`} className="text-primario underline">{b.titulo_material}</Link>
                                      : <Insignia valor="SIN MATERIAL" />}
                                </p>
                              )}
                              {b.cancelado && (
                                <p className="mt-1 text-sm text-peligro"><b>Cancelado</b>{b.reemplazo && ` · en su lugar ${b.titulo_reemplazo}`}</p>
                              )}
                              {b.tipo === 'PAUSA_COMERCIAL' && (
                                <p className="mt-1 text-sm text-suave">{spots.length ? `${spots.length} spot(s) · ${duracion(spots.reduce((t, s) => t + Number(s.duracion_seg), 0))} ocupados` : 'Sin spots'}</p>
                              )}
                            </div>
                          </div>
                          <div className="flex flex-wrap gap-1">
                            {b.tipo === 'PAUSA_COMERCIAL' && <Boton tam="sm" variante="secundario" icono="list" onClick={() => setVerSpots(b)}>Ver spots ({spots.length})</Boton>}
                            {deNoticias && puede('lisLea') && <Boton tam="sm" variante="secundario" icono="list" onClick={() => setEscaleta(b)}>Escaleta</Boton>}
                            {b.tipo === 'PROGRAMA' && b.notas === 0 && puede('camBlo') && <Boton tam="sm" variante="secundario" icono="edit" onClick={() => setCambiar(b)}>Cambiar material</Boton>}
                            {p.editable && puede('eliBlo') && <Boton tam="sm" variante="secundario" icono="trash" onClick={() => setQuitar(b)}>Quitar</Boton>}
                          </div>
                        </div>
                      </li>
                    )
                  })}
                </ol>
              )}
            </Tarjeta>
        </>
      )}

      {/* --- agregar bloque --- */}
      <Modal abierto={nuevoBloque} titulo="Agregar bloque" onCerrar={() => setNuevoBloque(false)} ancho="max-w-2xl">
        <Formulario textoBoton="Agregar bloque" onCancelar={() => setNuevoBloque(false)} inicial={{ hora_inicio: siguienteHora, tipo: 'PROGRAMA' }}
          campos={[
            { nombre: 'hora_inicio', etiqueta: 'Hora de inicio', tipo: 'time', requerido: true },
            { nombre: 'hora_fin', etiqueta: 'Hora de fin', tipo: 'time', requerido: true,
              disabled: (v) => v.tipo === 'PROGRAMA' && !!v.material,
              ayuda: (v) => (v.tipo === 'PROGRAMA' && v.material ? 'Automática: hora de inicio + duración del material.'
                : v.tipo === 'PAUSA_COMERCIAL' ? 'Elija la hora en que termina la pausa comercial.'
                  : 'Elija la hora, o seleccione un material y se calcula sola.') },
            { nombre: 'tipo', etiqueta: 'Tipo de bloque', tipo: 'select', opciones: ['PROGRAMA', 'PAUSA_COMERCIAL'], requerido: true },
            { nombre: 'programa', etiqueta: 'Programa', tipo: 'select', opciones: programas, oculto: (v) => v.tipo !== 'PROGRAMA' },
            { nombre: 'material', etiqueta: 'Material (opcional)', tipo: 'select', placeholder: '— Sin material (se asigna después o con escaleta) —',
              oculto: (v) => v.tipo !== 'PROGRAMA',
              opciones: (v) => materialesPara(v.programa).map(opcionMaterial) },
          ]}
          esquema={{
            hora_inicio: [r.requerido('La hora de inicio'), r.hora('La hora de inicio')],
            hora_fin: [r.requerido('La hora de fin'), r.horaFin('La hora de fin'), r.despuesDe('hora_inicio', 'La hora de fin', 'la hora de inicio')],
            tipo: [r.requerido('El tipo')],
            programa: [(v, c) => (c.tipo === 'PROGRAMA' && !v ? 'Un bloque de tipo PROGRAMA necesita el programa.' : null)],
          }}
          onCambio={(v, setV) => {
            if (v.tipo === 'PROGRAMA' && v.material && v.hora_inicio) {
              const m = materialesPara(v.programa).find((x) => String(x.id) === String(v.material))
              const fin = m ? sumarHora(v.hora_inicio, m.duracion_seg) : null
              if (fin && fin !== v.hora_fin) setV((o) => ({ ...o, hora_fin: fin }))
            }
          }}
          onEnviar={async (v) => {
            const pausa = v.tipo === 'PAUSA_COMERCIAL'
            tras(await post(`/parrillas/${fecha}/bloques`, { ...v, programa: pausa ? '' : v.programa, material: pausa ? '' : v.material ?? '' }))
            setNuevoBloque(false)
          }} />
      </Modal>

      {/* --- cambiar material (camBlo) --- */}
      <Modal abierto={!!cambiar} titulo={`Cambiar material · ${cambiar ? hora(cambiar.hora_inicio) : ''} ${cambiar?.programa ?? ''}`} onCerrar={() => setCambiar(null)}>
        {cambiar && (
          <Formulario textoBoton="Cambiar material" onCancelar={() => setCambiar(null)}
            campos={[{ nombre: 'material', etiqueta: 'Material nuevo', tipo: 'select', requerido: true, ancho: 'completo',
              opciones: materialesPara(cambiar.programa).filter((m) => String(m.id) !== String(cambiar.material)).map(opcionMaterial) }]}
            esquema={{ material: [r.requerido('El material')] }}
            extra={() => p?.estado === 'PUBLICADA' && <Alerta tipo="aviso" mensajes="La parrilla está publicada: se avisará del cambio al Programador y al Encargado de Contenidos." />}
            onEnviar={async (v) => { tras(await put(`/parrillas/${fecha}/bloques/${cambiar.hora_inicio}/material`, v)); setCambiar(null) }} />
        )}
      </Modal>

      <Escaleta fecha={fecha} bloque={escaleta} onCerrar={() => { setEscaleta(null); recargar() }} materiales={o?.materiales ?? []} />

      <Modal abierto={!!verSpots} ancho="max-w-4xl" onCerrar={() => setVerSpots(null)}
        titulo={verSpots ? `Spots de la pausa ${hora(verSpots.hora_inicio)}–${hora(verSpots.hora_fin)}` : ''}>
        {verSpots && (
          <div className="space-y-4">
            <Tabla filtrable={false} titulo="Spots" vacio="Esta pausa comercial todavía no tiene spots."
              filas={[...p.spots.filter((s) => s.id_bloque === verSpots.id)].sort((a, b) => a.hora.localeCompare(b.hora))} columnas={[
                { clave: 'hora', titulo: 'Hora inicio', render: (s) => <span className="font-mono">{s.hora}</span> },
                { clave: 'fin', titulo: 'Hora fin', render: (s) => <span className="font-mono">{finSpot(s.hora, s.duracion_seg)}</span> },
                { clave: 'titulo', titulo: 'Video', render: (s) => <Link to={`/materiales/${s.material}`} className="text-primario underline">{s.titulo}</Link> },
                { clave: 'pauta', titulo: 'Pauta', render: (s) => <span>{s.pauta}<span className="block text-xs text-suave">{s.anunciante}</span></span> },
                { clave: 'duracion_seg', titulo: 'Duración', alinear: 'right', render: (s) => `${s.duracion_seg} s` },
                { clave: 'estado', titulo: 'Estado', render: (s) => <Insignia valor={s.estado} /> },
              ]} />
            <div className="flex justify-end"><Boton variante="secundario" onClick={() => setVerSpots(null)}>Cerrar</Boton></div>
          </div>
        )}
      </Modal>

      <Confirmar abierto={!!quitar} titulo="Quitar bloque" onCerrar={() => setQuitar(null)} textoBoton="Quitar"
        mensaje={`¿Quitar el bloque de las <b>${quitar ? hora(quitar.hora_inicio) : ''}</b>?`}
        onConfirmar={async () => tras(await del(`/parrillas/${fecha}/bloques/${quitar.hora_inicio}`))} />
      <Confirmar abierto={publicar} titulo="Publicar parrilla" onCerrar={() => setPublicar(false)} variante="primario" textoBoton="Publicar"
        mensaje={`Se revisará que todos los bloques estén validados como LISTOS por el Encargado de Contenidos (CU5)${v5 && !v5.validada ? ` — <b>ahora van ${v5.resumen.listos} de ${v5.resumen.total}</b>` : ''} y que los bloques de programa tengan material (o escaleta). Los huecos sin programación se informan como avisos. Al publicar se avisa al Programador y al Encargado de Contenidos.`}
        onConfirmar={async () => tras(await post(`/parrillas/${fecha}/publicar`))} />
      <Confirmar abierto={eliminar} titulo="Eliminar parrilla" onCerrar={() => setEliminar(false)} textoBoton="Eliminar"
        mensaje={`¿Eliminar la parrilla del <b>${fecha}</b> con todos sus bloques? Solo se permite en borrador y sin spots.`}
        onConfirmar={async () => { await del(`/parrillas/${fecha}`); nav('/parrillas') }} />
    </Pagina>
  )
}

/* =================================================================== escaleta (orden de las notas del noticiero) */

function Escaleta({ fecha, bloque, onCerrar, materiales }) {
  const { puede } = useAuth()
  const url = bloque ? `/parrillas/${fecha}/bloques/${bloque.hora_inicio}/escaleta` : null
  const { datos, error, recargar, setDatos } = useApi(url)
  const [err, setErr] = useState(null)
  const [nota, setNota] = useState('')
  const e = datos?.datos
  const editable = e?.editable && puede('insLea')
  const accion = async (fn) => {
    setErr(null)
    try { const r = await fn(); setDatos({ datos: r.datos }) } catch (x) { setErr(x) }
  }
  const disponibles = useMemo(() => materiales.filter((m) => m.tipo !== 'SPOT'
    && (!m.programa || m.programa.toLowerCase() === bloque?.programa?.toLowerCase()) && !e?.notas.some((n) => String(n.material) === String(m.id))), [materiales, bloque, e])
  return (
    <Modal abierto={!!bloque} titulo={`Escaleta · ${bloque?.programa ?? ''} ${bloque ? hora(bloque.hora_inicio) : ''}`} onCerrar={onCerrar} ancho="max-w-3xl">
      <ErrorCarga error={error} onReintentar={recargar} />
      {!e ? <Cargando /> : (
        <div className="space-y-4">
          <p className="text-sm text-suave">Orden de transmisión de las notas (lead 1 / video 1...). Duración usada {duracion(e.duracion_usada)} de {duracion(e.bloque.duracion_seg)}; libre {duracion(e.duracion_libre)}.
            {!e.editable && ' El bloque ya empezó: la escaleta no se puede cambiar.'}</p>
          {err && <Alerta tipo="error" mensajes={err.errores} onCerrar={() => setErr(null)} />}
          {e.notas.length === 0 ? <Vacio texto="La escaleta no tiene notas." /> : (
            <ol className="divide-y divide-borde rounded-tema border border-borde">
              {e.notas.map((n, i) => (
                <li key={n.material} className="flex flex-wrap items-center gap-3 px-3 py-2">
                  <span className="grid size-8 place-items-center rounded-full bg-primario font-bold text-sobre-primario">{n.orden}</span>
                  <span className="font-mono text-sm">{hora(n.entrada)}</span>
                  <span className="min-w-0 flex-1"><span className="font-semibold">{n.titulo}</span> <span className="text-xs text-suave">({duracion(n.duracion_seg)})</span></span>
                  {editable && puede('modLea') && (
                    <span className="flex gap-1">
                      <Boton tam="sm" variante="secundario" icono="arrowUp" disabled={i === 0} aria-label={`Subir ${n.titulo}`}
                        onClick={() => accion(() => put(`${url}/${n.material}`, { nuevo_orden: n.orden - 1 }))} />
                      <Boton tam="sm" variante="secundario" icono="arrowDown" disabled={i === e.notas.length - 1} aria-label={`Bajar ${n.titulo}`}
                        onClick={() => accion(() => put(`${url}/${n.material}`, { nuevo_orden: n.orden + 1 }))} />
                    </span>
                  )}
                  {editable && puede('eliLea') && <Boton tam="sm" variante="secundario" icono="trash" aria-label={`Quitar ${n.titulo}`} onClick={() => accion(() => del(`${url}/${n.material}`))} />}
                </li>
              ))}
            </ol>
          )}
          {editable && (
            <div className="flex flex-wrap items-end gap-2 rounded-tema bg-superficie-2 p-3">
              <div className="min-w-64 flex-1">
                <Campo etiqueta="Agregar nota al final (material del programa)" tipo="select" valor={nota} onChange={setNota}
                  opciones={disponibles.map(opcionMaterial)} />
              </div>
              <Boton icono="plus" disabled={!nota} onClick={() => accion(async () => { const r = await post(url, { orden: e.notas.length + 1, material: nota }); setNota(''); return r })}>Agregar</Boton>
            </div>
          )}
          {e.parrilla_estado === 'PUBLICADA' && editable && <Alerta tipo="info" mensajes="La parrilla está publicada: cada cambio del orden se avisa al Programador." />}
        </div>
      )}
    </Modal>
  )
}

/* =================================================================== CU5 validar estado de emision (antes de salir al aire) */

/** Selector de fecha comun a las pantallas de validacion y emision. */
function SelectorFecha({ fecha, onCambio, onActualizar }) {
  const nav = useNavigate()
  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="w-52"><Campo etiqueta="Fecha" tipo="date" valor={fecha} onChange={(v) => v && onCambio(v)} /></div>
      <Boton variante="secundario" icono="refresh" onClick={onActualizar}>Actualizar</Boton>
      <Boton variante="fantasma" icono="calendar" onClick={() => nav(`/parrillas/${fecha}`)}>Ver parrilla</Boton>
    </div>
  )
}

/** Lista de videos de un bloque (en orden de salida) con su reproductor. */
function VideosDelBloque({ videos }) {
  const [actual, setActual] = useState(0)
  if (!videos.length) return <Vacio texto="El bloque no tiene contenido que revisar." />
  const v = videos[Math.min(actual, videos.length - 1)]
  return (
    <div className="grid gap-3 md:grid-cols-5">
      <ol className="max-h-80 space-y-1 overflow-y-auto md:col-span-2">
        {videos.map((x, i) => (
          <li key={`${x.hora}-${x.id_material}`}>
            <button type="button" onClick={() => setActual(i)}
              className={`w-full rounded-tema border px-3 py-2 text-left text-sm ${i === actual ? 'border-primario bg-primario-tenue' : 'border-borde hover:bg-superficie-2'}`}>
              <span className="font-mono text-xs">{x.hora}–{x.hora_fin}</span>
              <span className="block font-semibold">{x.titulo}</span>
              <span className="block text-xs text-suave">{x.detalle} · {duracion(x.duracion_seg)}</span>
              {!x.video_url && <span className="text-xs text-peligro">Sin video cargado</span>}
            </button>
          </li>
        ))}
      </ol>
      <div className="md:col-span-3">
        {v.video_url
          ? <video key={v.video_url} src={v.video_url} controls className="aspect-video w-full rounded-tema bg-black"
              onEnded={() => actual < videos.length - 1 && setActual(actual + 1)} />
          : <div className="grid aspect-video w-full place-items-center rounded-tema bg-superficie-2 text-sm text-suave">El material {v.titulo} no tiene su video cargado.</div>}
        <p className="mt-2 text-sm"><b>{v.titulo}</b> <span className="text-suave">({v.hora}–{v.hora_fin})</span></p>
        {videos.length > 1 && <p className="text-xs text-suave">Video {actual + 1} de {videos.length}. Al terminar pasa al siguiente.</p>}
      </div>
    </div>
  )
}

export function Validacion() {
  const { puede } = useAuth()
  const nav = useNavigate()
  const [params, setParams] = useSearchParams()
  const fecha = params.get('fecha') || hoyLocal()
  const { datos, cargando, error, recargar, setDatos } = useApi(`/parrillas/${fecha}/validacion`)
  const [validar, setValidar] = useState(null)
  const [anular, setAnular] = useState(null)
  const [cancelar, setCancelar] = useState(null)
  const [reanudar, setReanudar] = useState(null)
  const ops = useApi('/opciones')
  const [msg, mostrar] = useMensaje()
  const d = datos?.datos
  const listo = (res) => { mostrar(res); setDatos({ datos: res.datos }) }
  const url = (b) => `/parrillas/${fecha}/bloques/${b.hora_inicio}/validacion`
  const urlCancelacion = (b) => `/parrillas/${fecha}/bloques/${b.hora_inicio}/cancelacion`
  const duracionDe = (b) => { const s = (x) => x.split(':').reduce((t, n) => t * 60 + Number(n), 0); return s(b.hora_fin) - s(b.hora_inicio) }
  const reemplazos = (b) => (ops.datos?.datos?.materiales ?? []).filter((m) => m.tipo !== 'SPOT' && Number(m.duracion_seg) <= duracionDe(b))
  const validado = (b) => b.estado !== 'PENDIENTE'
  const nuevo = validar && !validado(validar)
  const puedeValidar = validar?.editable && (nuevo ? puede('insVal') : puede('modVal'))
  return (
    <Pagina titulo="Validar estado de emisión" subtitulo="CU5 · El Encargado de Contenidos revisa los videos de cada bloque de la parrilla en borrador y lo deja LISTO u OBSERVADO. Con todos LISTOS la parrilla se puede publicar.">
      {msg}
      <SelectorFecha fecha={fecha} onCambio={(v) => setParams({ fecha: v })} onActualizar={recargar} />
      {error && <Alerta tipo="aviso" mensajes={error.errores} />}
      {cargando && !d ? <Cargando /> : d && (
        <>
          {d.parrilla_estado === 'BORRADOR' && (d.validada
            ? <Alerta tipo="exito" titulo="Parrilla validada" mensajes="Todos los bloques están LISTOS: el Administrador ya puede publicarla." />
            : <Alerta tipo="aviso" titulo="Parrilla en borrador" mensajes={`No se puede publicar hasta que todos los bloques estén LISTOS. Faltan ${d.resumen.sin_validar} sin validar y ${d.resumen.observados} observado(s).`} />)}
          {d.parrilla_estado === 'PUBLICADA' && (d.validada
            ? <Alerta tipo="info" titulo="Parrilla publicada" mensajes="Todos los bloques están LISTOS para salir al aire." />
            : <Alerta tipo="aviso" titulo="Parrilla publicada con cambios" mensajes={`Hay ${d.resumen.sin_validar} bloque(s) sin validar y ${d.resumen.observados} observado(s): un bloque que no está LISTO no sale al aire.`} />)}
          {d.parrilla_estado === 'BORRADOR' && d.validada && puede('pubPar') && (
            <div><Boton icono="send" onClick={() => nav(`/parrillas/${fecha}`)}>Ir a publicar la parrilla</Boton></div>
          )}
          <Medidor valor={d.resumen.total ? (d.resumen.listos * 100) / d.resumen.total : 0} etiqueta={`Bloques LISTOS: ${d.resumen.listos} de ${d.resumen.total}`} />
          <Tabla filtrable={false} titulo="Bloques" vacio="La parrilla no tiene bloques." filas={d.bloques} columnas={[
            { clave: 'hora_inicio', titulo: 'Horario', render: (b) => <span className="font-mono">{hora(b.hora_inicio)}–{hora(b.hora_fin)}</span> },
            { clave: 'nombre', titulo: 'Bloque', render: (b) => <span className="font-semibold">{b.nombre}</span> },
            { clave: 'estado_bloque', titulo: 'Estado', render: (b) => (
              <div className="space-y-1">
                <Insignia valor={b.estado_bloque} />
                {b.cancelacion && (
                  <div className="text-sm">
                    {b.cancelacion.reemplazo && (
                      <p><b>En su lugar:</b> {b.cancelacion.titulo_reemplazo}
                        {b.cancelacion.reemplazo_video_url && <a href={b.cancelacion.reemplazo_video_url} target="_blank" rel="noreferrer" className="ml-1 text-primario underline">ver video</a>}</p>
                    )}
                  </div>
                )}
              </div>
            ) },
            { clave: 'contenido', titulo: 'Contenido', render: (b) => (
              <div className="text-sm">
                <p>{b.contenido}</p>
                {b.videos.length > 0 && <p className="text-xs text-suave">{b.videos.map((x) => x.titulo).join(', ')}</p>}
              </div>
            ) },
            { clave: 'estado', titulo: 'Validación', render: (b) => (
              <div className="space-y-1">
                <Insignia valor={b.estado} />
                {b.observacion && <p className="text-sm">{b.observacion}</p>}
                {b.validado_por && <p className="text-xs text-suave">Validó: {b.validado_por}</p>}
              </div>
            ) },
            { clave: 'acc', titulo: 'Acciones', render: (b) => (
              <div className="flex flex-wrap gap-1">
                {b.editable && !validado(b) && puede('insVal')
                  ? <Boton tam="sm" icono="check" onClick={() => setValidar(b)}>Revisar y validar</Boton>
                  : <Boton tam="sm" variante="secundario" icono="film" onClick={() => setValidar(b)}>{b.editable && puede('modVal') ? 'Revisar / modificar' : 'Ver videos'}</Boton>}
                {b.editable && validado(b) && puede('eliVal') && <Boton tam="sm" variante="secundario" icono="trash" onClick={() => setAnular(b)}>Anular</Boton>}
                {b.puede_cancelar && puede('canBlo') && <Boton tam="sm" variante="peligro" icono="x" onClick={() => setCancelar(b)}>Cancelar</Boton>}
                {b.puede_reanudar && puede('reaBlo') && <Boton tam="sm" variante="secundario" icono="play" onClick={() => setReanudar(b)}>Reanudar</Boton>}
                {!b.editable && <span className="w-full text-xs text-suave">{b.bloqueo}: ya no se valida</span>}
              </div>
            ) },
          ]} />
        </>
      )}
      <Modal abierto={!!validar} ancho="max-w-5xl" onCerrar={() => setValidar(null)}
        titulo={validar ? `${validar.nombre} · ${hora(validar.hora_inicio)}–${hora(validar.hora_fin)}` : ''}>
        {validar && (
          <div className="space-y-4">
            <p className="text-sm text-suave">{validar.contenido}. Revise cada video antes de decidir.</p>
            <VideosDelBloque videos={validar.videos} />
            {!puedeValidar ? (
              <div className="flex items-center justify-between gap-2 border-t border-borde pt-3">
                <span className="flex items-center gap-2 text-sm"><Insignia valor={validar.estado} />{validar.observacion}</span>
                <Boton variante="secundario" onClick={() => setValidar(null)}>Cerrar</Boton>
              </div>
            ) : (
          <div className="border-t border-borde pt-3">
          <Formulario textoBoton={nuevo ? 'Registrar validación' : 'Guardar cambios'} onCancelar={() => setValidar(null)}
            inicial={nuevo
              ? { estado: validar.problemas.length ? 'OBSERVADO' : 'LISTO', observacion: validar.problemas.join(' ') }
              : { estado: validar.estado, observacion: validar.observacion }}
            campos={[
              { nombre: 'estado', etiqueta: 'Estado de emisión', tipo: 'select', requerido: true, ancho: 'completo',
                opciones: validar.problemas.length ? ['OBSERVADO'] : ['LISTO', 'OBSERVADO'] },
              { nombre: 'observacion', etiqueta: 'Observación', tipo: 'textarea', ayuda: 'Obligatoria si el bloque queda OBSERVADO: indique qué falta o qué está mal.' },
            ]}
            esquema={{ estado: [r.requerido('El estado')],
              observacion: [r.max(255, 'La observación'), (v, c) => (c.estado === 'OBSERVADO' && !String(v ?? '').trim() ? 'Al observar un bloque, la observación es obligatoria.' : null)] }}
            extra={() => validar.problemas.length > 0 && <Alerta tipo="aviso" titulo="No puede quedar LISTO" mensajes={validar.problemas} />}
            onEnviar={async (v) => { listo(nuevo ? await post(url(validar), v) : await put(url(validar), v)); setValidar(null) }} />
          </div>
            )}
          </div>
        )}
      </Modal>
      <Modal abierto={!!cancelar} onCerrar={() => setCancelar(null)}
        titulo={cancelar ? `Cancelar bloque · ${hora(cancelar.hora_inicio)}–${hora(cancelar.hora_fin)} ${cancelar.nombre}` : ''}>
        {cancelar && (
          <Formulario textoBoton="Cancelar bloque" onCancelar={() => setCancelar(null)} inicial={{ reemplazo: '' }}
            campos={cancelar.tipo === 'PAUSA_COMERCIAL' ? [] : [
              { nombre: 'reemplazo', etiqueta: 'Qué sale en su lugar (opcional)', tipo: 'select', ancho: 'completo', placeholder: '— Nada / se transmite en vivo —',
                opciones: reemplazos(cancelar).map(opcionMaterial) },
            ]}
            extra={() => <Alerta tipo="aviso" mensajes="El bloque queda CANCELADO y su video pasa a ser el de reemplazo (o ninguno). En la conciliación (CU7) solo puede registrarse como NO EMITIDO. Se avisa al Programador. Mientras el bloque no termine se puede reanudar: vuelve con el video que tenga asignado." />}
            onEnviar={async (v) => { listo(await post(urlCancelacion(cancelar), v)); setCancelar(null) }} />
        )}
      </Modal>
      <Confirmar abierto={!!reanudar} titulo="Reanudar bloque" onCerrar={() => setReanudar(null)} variante="primario" textoBoton="Reanudar"
        mensaje={`¿Reanudar el bloque de las <b>${reanudar ? hora(reanudar.hora_inicio) : ''}</b>? Deja de estar cancelado y vuelve a salir lo programado.`}
        onConfirmar={async () => listo(await del(urlCancelacion(reanudar)))} />
      <Confirmar abierto={!!anular} titulo="Anular validación" onCerrar={() => setAnular(null)} textoBoton="Anular"
        mensaje={`¿Anular la validación del bloque de las <b>${anular ? hora(anular.hora_inicio) : ''}</b>? Quedará SIN VALIDAR.`}
        onConfirmar={async () => listo(await del(url(anular)))} />
    </Pagina>
  )
}

/* =================================================================== CU7 conciliar emision real (despues de salir al aire) */

/* =================================================================== CU7 conciliacion: lo planificado contra lo que salio al aire */

const aSeg = (h) => { if (!h) return null; const [a, b, c] = h.split(':').map(Number); return a * 3600 + b * 60 + (c || 0) }
const conSeg = (h) => (h && h.length === 5 ? `${h}:00` : h)
/** "3 min 20 s" (sin signo) */
const lapso = (s) => { const t = Math.abs(Math.round(s)); const m = Math.floor(t / 60); const r = t % 60; return m ? `${m} min${r ? ` ${r} s` : ''}` : `${r} s` }
const RESULTADOS = { EMITIDO: 'Salió completo', PARCIAL: 'Salió a medias', NO_EMITIDO: 'No salió' }

/**
 * Comparacion de un bloque: atraso del inicio y duracion real contra la planificada.
 * Guardado: usa lo que calculo la BD (conciliacion.desvio_inicio y duracion_real); editando: lo calcula al escribir.
 */
function Diferencia({ b, inicio, fin, estado, guardado = false }) {
  if (estado === 'NO_EMITIDO') return <span className="text-sm text-peligro">No salió al aire</span>
  const ini = aSeg(inicio)
  const f = aSeg(fin)
  if (ini === null || f === null) return <span className="text-sm text-suave">—</span>
  const atraso = guardado && b.desviacion_seg !== null ? Number(b.desviacion_seg) : ini - aSeg(b.hora_inicio)
  const plan = aSeg(b.hora_fin) - aSeg(b.hora_inicio)
  const real = guardado && b.duracion_seg !== null ? Number(b.duracion_seg) : f - ini
  const dif = real - plan
  return (
    <span className="block text-sm">
      <span className={`block ${atraso > 0 ? 'text-aviso' : atraso < 0 ? 'text-primario' : 'text-exito'}`}>
        Inicio: {atraso === 0 ? 'a tiempo' : atraso > 0 ? `${lapso(atraso)} tarde` : `${lapso(atraso)} antes`}
      </span>
      <span className={`block ${dif < 0 ? 'text-aviso' : 'text-suave'}`}>
        Duró {real > 0 ? lapso(real) : '—'}{dif !== 0 && real > 0 ? ` (${dif > 0 ? '+' : '−'}${lapso(dif)})` : ''}
      </span>
    </span>
  )
}

export function Emision() {
  const { puede } = useAuth()
  const [params, setParams] = useSearchParams()
  const fecha = params.get('fecha') || hoyLocal()
  const { datos, cargando, error, recargar, setDatos } = useApi(`/parrillas/${fecha}/conciliacion`)
  const [editando, setEditando] = useState({}) // id del bloque -> { inicio, fin, estado, nota }
  const [errores, setErrores] = useState({}) // id del bloque -> texto
  const [guardando, setGuardando] = useState(null)
  const [eliminar, setEliminar] = useState(null)
  const [verSpots, setVerSpots] = useState(null)
  const [errSpot, setErrSpot] = useState(null)
  const [msg, mostrar] = useMensaje()
  const c = datos?.datos
  const listo = (res) => { mostrar(res); setDatos({ datos: res.datos }) }
  const publicada = c?.parrilla_estado === 'PUBLICADA'
  const spotsDe = (b) => (c?.spots ?? []).filter((s) => s.id_bloque === b.id)
  const pausa = verSpots && c?.bloques.find((b) => b.id === verSpots.id)
  const puedeSalir = (b) => b.validacion === 'LISTO' && !b.cancelado
  const motivo = (b) => (!publicada ? 'Parrilla sin publicar' : !b.empezo ? 'Aún no sale al aire' : null)

  const abrir = (b) => setEditando((e) => ({ ...e, [b.id]: {
    inicio: (b.inicio_real || b.hora_inicio).slice(0, 5),
    fin: (b.fin_real || b.hora_fin).slice(0, 5),
    estado: b.registrada ? b.estado : (puedeSalir(b) ? 'EMITIDO' : 'NO_EMITIDO'),
    nota: b.observacion || (b.cancelado ? 'Bloque cancelado' : ''),
  } }))
  const cerrar = (id) => { setEditando((e) => { const n = { ...e }; delete n[id]; return n }); setErrores((e) => ({ ...e, [id]: null })) }
  const cambiar = (id, campo, valor) => setEditando((e) => ({ ...e, [id]: { ...e[id], [campo]: valor } }))

  const guardar = async (b) => {
    const x = editando[b.id]
    let err = null
    if (x.estado !== 'NO_EMITIDO' && (!x.inicio || !x.fin)) err = 'Escriba la hora real de inicio y de fin.'
    else if (x.estado !== 'NO_EMITIDO' && aSeg(x.fin) <= aSeg(x.inicio)) err = 'La hora de fin debe ser posterior a la de inicio.'
    else if (x.estado !== 'EMITIDO' && !x.nota.trim()) err = 'Escriba una nota: qué pasó.'
    setErrores((e) => ({ ...e, [b.id]: err }))
    if (err) return
    setGuardando(b.id)
    try {
      const cuerpo = x.estado === 'NO_EMITIDO'
        ? { estado: x.estado, inicio_real: '', fin_real: '', observacion: x.nota.trim() }
        : { estado: x.estado, inicio_real: conSeg(x.inicio), fin_real: conSeg(x.fin), observacion: x.nota.trim() }
      const ruta = `/parrillas/${fecha}/bloques/${b.hora_inicio}/emision`
      listo(b.registrada ? await put(ruta, cuerpo) : await post(ruta, cuerpo))
      cerrar(b.id)
    } catch (e) {
      setErrores((er) => ({ ...er, [b.id]: (e.errores ?? ['No se pudo guardar.']).join(' ').replace(/<[^>]+>/g, '') }))
    } finally { setGuardando(null) }
  }
  const marcarSpot = async (s, estado) => {
    setErrSpot(null)
    try { listo(await put(`/parrillas/${fecha}/spots/${s.hora}/emision`, { estado })) } catch (e) { setErrSpot(e) }
  }

  const campo = 'rounded-tema border border-borde bg-superficie px-2 py-1 text-sm'

  return (
    <Pagina titulo="Conciliación de la emisión" subtitulo="CU7 · Compare el horario planificado con el que realmente salió al aire.">
      {msg}
      <SelectorFecha fecha={fecha} onCambio={(v) => { setEditando({}); setParams({ fecha: v }) }} onActualizar={recargar} />
      {error && <Alerta tipo="aviso" mensajes={error.errores} />}
      {cargando && !c ? <Cargando /> : c && (
        <>
          {!publicada && <Alerta tipo="aviso" mensajes="Esta parrilla sigue en BORRADOR: publíquela primero; solo se concilian parrillas publicadas." />}
          <Tabla filtrable={false} titulo="Planificado contra transmitido" filas={c.bloques} columnas={[
            { clave: 'programa', titulo: 'Bloque', render: (b) => (
              <span><span className="font-semibold">{b.programa || 'Pausa comercial'}</span>
                {b.cancelado && <span className="block text-xs text-peligro">Cancelado</span>}
                {!b.cancelado && b.validacion !== 'LISTO' && <span className="block text-xs text-suave">No validado (CU5)</span>}</span>
            ) },
            { clave: 'hora_inicio', titulo: 'Planificado', render: (b) => (
              <span className="font-mono text-sm">{hora(b.hora_inicio)} – {hora(b.hora_fin)}
                <span className="block font-sans text-xs text-suave">{lapso(aSeg(b.hora_fin) - aSeg(b.hora_inicio))}</span></span>
            ) },
            { clave: 'inicio_real', titulo: 'Transmitido', render: (b) => {
              const x = editando[b.id]
              if (x) {
                return x.estado === 'NO_EMITIDO' ? <span className="text-sm text-suave">—</span> : (
                  <span className="flex items-center gap-1">
                    <input type="time" aria-label="Inicio real" value={x.inicio} onChange={(e) => cambiar(b.id, 'inicio', e.target.value)} className={`${campo} font-mono`} />
                    <span>–</span>
                    <input type="time" aria-label="Fin real" value={x.fin} onChange={(e) => cambiar(b.id, 'fin', e.target.value)} className={`${campo} font-mono`} />
                  </span>
                )
              }
              return b.inicio_real ? <span className="font-mono text-sm">{hora(b.inicio_real)} – {hora(b.fin_real)}</span> : <span className="text-sm text-suave">—</span>
            } },
            { clave: 'diferencia', titulo: 'Diferencia', render: (b) => {
              const x = editando[b.id]
              if (x) return <Diferencia b={b} inicio={x.inicio} fin={x.fin} estado={x.estado} />
              return b.registrada ? <Diferencia b={b} inicio={b.inicio_real} fin={b.fin_real} estado={b.estado} guardado /> : <span className="text-sm text-suave">—</span>
            } },
            { clave: 'estado', titulo: 'Resultado', render: (b) => {
              const x = editando[b.id]
              if (!x) return <Insignia valor={b.estado} />
              return (
                <select aria-label="Resultado" value={x.estado} onChange={(e) => cambiar(b.id, 'estado', e.target.value)} className={campo}>
                  {(puedeSalir(b) ? ['EMITIDO', 'PARCIAL', 'NO_EMITIDO'] : ['NO_EMITIDO']).map((v) => <option key={v} value={v}>{RESULTADOS[v]}</option>)}
                </select>
              )
            } },
            { clave: 'observacion', titulo: 'Nota', render: (b) => {
              const x = editando[b.id]
              if (!x) return <span className="text-sm">{b.observacion || <span className="text-suave">—</span>}</span>
              return (
                <span className="block">
                  <input type="text" maxLength={255} aria-label="Nota" value={x.nota} onChange={(e) => cambiar(b.id, 'nota', e.target.value)}
                    placeholder={x.estado === 'EMITIDO' ? 'Opcional' : 'Obligatoria: qué pasó'} className={`${campo} w-full min-w-40`} />
                  {errores[b.id] && <span className="mt-1 block text-xs text-peligro">{errores[b.id]}</span>}
                </span>
              )
            } },
            { clave: 'acc', titulo: '', render: (b) => {
              const x = editando[b.id]
              const m = motivo(b)
              return (
                <div className="flex flex-wrap items-center gap-1">
                  {x ? (
                    <>
                      <Boton tam="sm" icono="check" cargando={guardando === b.id} onClick={() => guardar(b)}>Guardar</Boton>
                      <Boton tam="sm" variante="fantasma" onClick={() => cerrar(b.id)}>Cancelar</Boton>
                    </>
                  ) : (
                    <>
                      {m && !b.registrada && <span className="text-xs text-suave">{m}</span>}
                      {!m && !b.registrada && puede('insEmi') && <Boton tam="sm" icono="radio" onClick={() => abrir(b)}>Conciliar</Boton>}
                      {b.registrada && puede('modEmi') && <Boton tam="sm" variante="secundario" icono="edit" onClick={() => abrir(b)}>Corregir</Boton>}
                      {b.registrada && puede('eliEmi') && <Boton tam="sm" variante="fantasma" icono="trash" onClick={() => setEliminar(b)}>Deshacer</Boton>}
                      {b.tipo === 'PAUSA_COMERCIAL' && <Boton tam="sm" variante="secundario" icono="list" onClick={() => { setErrSpot(null); setVerSpots(b) }}>Spots ({spotsDe(b).length})</Boton>}
                    </>
                  )}
                </div>
              )
            } },
          ]} />
        </>
      )}

      <Modal abierto={!!pausa} ancho="max-w-4xl" onCerrar={() => setVerSpots(null)}
        titulo={pausa ? `Spots de la pausa ${hora(pausa.hora_inicio)}–${hora(pausa.hora_fin)}` : ''}>
        {pausa && (
          <div className="space-y-4">
            {errSpot && <Alerta tipo="error" mensajes={errSpot.errores} onCerrar={() => setErrSpot(null)} />}
            <Tabla filtrable={false} titulo="Spots" vacio="Esta pausa comercial no tiene spots." filas={spotsDe(pausa)} columnas={[
              { clave: 'hora', titulo: 'Planificado', render: (s) => <span className="font-mono">{hora(s.hora)} – {hora(finSpot(s.hora, s.duracion_seg))}</span> },
              { clave: 'titulo', titulo: 'Spot', render: (s) => <Link to={`/materiales/${s.material}`} className="text-primario underline">{s.titulo}</Link> },
              { clave: 'pauta', titulo: 'Anunciante', render: (s) => <span>{s.anunciante}<span className="block text-xs text-suave">{s.pauta}</span></span> },
              { clave: 'estado', titulo: '¿Salió al aire?', render: (s) => <Insignia valor={s.estado} /> },
              { clave: 'acc', titulo: '', render: (s) => pausa.registrada && puede('modSpo') && (s.estado === 'EMITIDO'
                ? <Boton tam="sm" variante="secundario" icono="x" onClick={() => marcarSpot(s, 'NO_EMITIDO')}>No salió</Boton>
                : <Boton tam="sm" variante="secundario" icono="check" onClick={() => marcarSpot(s, 'EMITIDO')}>Salió</Boton>) },
            ]} />
            <div className="flex justify-end"><Boton variante="secundario" onClick={() => setVerSpots(null)}>Cerrar</Boton></div>
          </div>
        )}
      </Modal>

      <Confirmar abierto={!!eliminar} titulo="Deshacer conciliación" onCerrar={() => setEliminar(null)} textoBoton="Deshacer"
        mensaje={`¿Deshacer la conciliación del bloque de las <b>${eliminar ? hora(eliminar.hora_inicio) : ''}</b>? Vuelve a quedar sin conciliar y sus spots vuelven a PROGRAMADO.`}
        onConfirmar={async () => listo(await del(`/parrillas/${fecha}/bloques/${eliminar.hora_inicio}/emision`))} />
    </Pagina>
  )
}
