import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Alerta, Boton, Campo, Cargando, Confirmar, ErrorCarga, Insignia, Modal, Pagina, Tabla, Tarjeta, useApi, useMensaje } from '../components/ui'
import { get, post, subirArchivo } from '../lib/api'
import { duracion as textoDuracion, hoyLocal, sumarDias } from '../lib/formato'
import { dinero, previaCuotas, textoEspacios, textoPlan, usePautasConfig, validarCuotas } from '../lib/pagosPauta'
import { ElegirEspacios, FormaPago, TablaSalidas, TarjetaPago, TarjetaSpot } from './PagoPauta'

/* CU6 - Solicitudes de pauta: el anunciante la pide con su video; el Administrador la acepta o la rechaza. */

export const EN_REVISION = 'EN REVISION'

function VideoSolicitud({ s }) {
  if (s.video_url) return <video src={s.video_url} controls className="aspect-video w-full rounded-tema bg-black" />
  return <div className="grid aspect-video w-full place-items-center rounded-tema bg-superficie-2 text-sm text-suave">El archivo del video no está disponible.</div>
}

function DatosSolicitud({ s }) {
  const { simbolo } = usePautasConfig()
  const cuotas = previaCuotas(s.costo, s.numero_cuotas, hoyLocal(), s.frecuencia)
  return (
    <div className="space-y-3 text-sm">
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
        <dt className="text-suave">Anunciante</dt><dd>{s.anunciante} <span className="text-suave">(CI {s.anunciante_ci})</span></dd>
        <dt className="text-suave">Spot</dt><dd>{s.titulo_spot} · {s.duracion_spot} s{s.spot && ` · ${s.spot.formato}`}</dd>
        <dt className="text-suave">Vigencia</dt><dd>{s.fecha_inicio} a {s.fecha_fin}</dd>
        <dt className="text-suave">Espacios</dt><dd>{textoEspacios(s.espacios)}</dd>
        <dt className="text-suave">Salidas</dt><dd>{s.salidas} en total</dd>
        <dt className="text-suave">Costo</dt><dd><b>{dinero(s.costo, simbolo)}</b> <span className="text-suave">(sin intereses)</span></dd>
        <dt className="text-suave">Forma de pago</dt><dd>{textoPlan(s.frecuencia, s.numero_cuotas)}</dd>
      </dl>
      {(s.espacios ?? []).length > 0 && (
        <ul className="space-y-1 rounded-tema border border-borde p-2">
          {s.espacios.map((e) => (
            <li key={e.id} className="flex flex-wrap justify-between gap-2">
              <span><b>{e.nombre}</b> <span className="font-mono text-suave">{e.hora_inicio}–{e.hora_fin}</span> · {e.salidas_por_dia} por día ({e.salidas} salidas) × {s.duracion_spot} s × {dinero(e.precio_segundo, simbolo)}</span>
              <b>{dinero(e.subtotal, simbolo)}</b>
            </li>
          ))}
        </ul>
      )}
      {cuotas.length > 1 && <p className="text-suave">Cuotas: {cuotas.map((c) => `${c.vence} ${dinero(c.monto, simbolo)}`).join(' · ')}</p>}
    </div>
  )
}

/* ============================================================================ anunciante: nueva solicitud */

function NuevaSolicitud({ abierto, onCerrar, onEnviada }) {
  const { simbolo, maxCuotas } = usePautasConfig()
  const hoy = hoyLocal()
  const [campania, setCampania] = useState('')
  const [video, setVideo] = useState(null) // { ruta, formato, duracion, nombre, tamano_mb }
  const [vista, setVista] = useState(null)
  const [progreso, setProgreso] = useState(null)
  const [titulo, setTitulo] = useState('')
  const [inicio, setInicio] = useState(hoy)
  const [fin, setFin] = useState(sumarDias(hoy, 30))
  const [espacios, setEspacios] = useState({}) // { idEspacio: salidas por dia }
  const [res, setRes] = useState({ costo: 0, salidas: 0, texto: '', errores: [] })
  const [frecuencia, setFrecuencia] = useState('CONTADO')
  const [cuotas, setCuotas] = useState('3')
  const [err, setErr] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const duracion = video?.duracion ?? 0
  const costo = res.costo

  useEffect(() => {
    if (!abierto) {
      setCampania(''); setVideo(null); setVista(null); setProgreso(null); setTitulo(''); setInicio(hoy); setFin(sumarDias(hoy, 30))
      setEspacios({}); setFrecuencia('CONTADO'); setCuotas('3'); setErr(null)
    }
  }, [abierto]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => () => { if (vista) URL.revokeObjectURL(vista) }, [vista])

  /** El video se sube al servidor apenas se elige (el servidor devuelve su ruta y su duracion). */
  const elegirVideo = async (f) => {
    setErr(null)
    if (!f) return
    if (!['mp4', 'mov'].includes(f.name.split('.').pop().toLowerCase())) { setErr(['El video debe ser .mp4 o .mov.']); return }
    setVista(URL.createObjectURL(f)); setVideo(null); setProgreso(0)
    try {
      const fd = new FormData()
      fd.append('video', f)
      const r = (await subirArchivo('/solicitudes/video', fd, (e) => e.total && setProgreso(Math.round((e.loaded * 100) / e.total)))).datos
      if (!r.duracion_seg) throw { errores: ['No se pudo leer la duración del video: verifique que no esté dañado.'] }
      setVideo({ ruta: r.ruta, formato: r.formato, duracion: r.duracion_seg, nombre: r.nombre, tamano_mb: r.tamano_mb })
      if (!titulo) setTitulo(f.name.replace(/\.[^.]+$/, ''))
    } catch (e) { setErr(e.errores ?? ['No se pudo subir el video.']); setVista(null) } finally { setProgreso(null) }
  }

  const enviar = async () => {
    const x = []
    if (!campania.trim()) x.push('Escriba el nombre de la campaña.')
    if (!video) x.push('Suba el video del spot.')
    if (!titulo.trim()) x.push('Escriba el título del spot.')
    if (fin < inicio) x.push('La fecha de fin debe ser posterior a la de inicio.')
    if (video) x.push(...res.errores)
    const ec = validarCuotas(frecuencia, Number(cuotas), maxCuotas)
    if (ec) x.push(ec)
    if (x.length) { setErr(x); return }
    setEnviando(true); setErr(null)
    try {
      onEnviada(await post('/solicitudes', {
        nombre: campania.trim(), titulo_spot: titulo.trim(), fecha_inicio: inicio, fecha_fin: fin, espacios: res.texto, frecuencia,
        cuotas: frecuencia === 'CONTADO' ? '1' : cuotas, ruta: video.ruta, formato: video.formato, duracion_seg: String(video.duracion),
      }))
    } catch (e) { setErr(e.errores) } finally { setEnviando(false) }
  }

  return (
    <Modal abierto={abierto} titulo="Solicitar una pauta publicitaria" onCerrar={onCerrar} ancho="max-w-6xl">
      <div className="space-y-5">
        <div className="grid gap-4 lg:grid-cols-2">
          <div className="space-y-3">
            <Campo etiqueta="Nombre de la campaña" valor={campania} onChange={setCampania} requerido ayuda="Así se llamará su pauta. Ej.: Navidad Banco Sol 2026." />
            <div>
              <label className="mb-1 block text-sm font-semibold">Video del spot <span className="text-peligro">*</span></label>
              <input type="file" accept=".mp4,.mov,video/mp4,video/quicktime" onChange={(e) => elegirVideo(e.target.files?.[0])}
                className="block w-full text-sm file:mr-3 file:rounded-tema file:border-0 file:bg-primario file:px-3 file:py-2 file:text-sobre-primario" />
              {progreso !== null && <p className="mt-1 text-xs text-suave">Subiendo… {progreso} %</p>}
              {video && <p className="mt-1 text-xs text-suave">{video.nombre} · {video.tamano_mb} MB · dura <b>{video.duracion} s</b></p>}
            </div>
            <Campo etiqueta="Título del spot" valor={titulo} onChange={setTitulo} requerido />
            <div className="grid gap-3 sm:grid-cols-2">
              <Campo etiqueta="Vigente desde" tipo="date" valor={inicio} onChange={setInicio} min={hoy} requerido />
              <Campo etiqueta="Vigente hasta" tipo="date" valor={fin} onChange={setFin} min={inicio} requerido />
            </div>
          </div>
          {vista ? <video src={vista} controls className="aspect-video w-full rounded-tema bg-black" />
            : <div className="grid aspect-video w-full place-items-center rounded-tema bg-superficie-2 text-sm text-suave">Aquí verá su spot al subirlo.</div>}
        </div>
        {video
          ? <ElegirEspacios inicio={inicio} fin={fin} duracion={duracion} valor={espacios} onChange={setEspacios} onResumen={setRes} />
          : <p className="text-sm text-suave">Suba su video para elegir los espacios: lo libre de cada espacio depende de lo que dura su spot.</p>}
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="space-y-2">
            <div className="rounded-tema bg-superficie-2 p-3 text-sm">
              <p>{res.salidas} salidas de {duracion} s</p>
              <p className="font-titulo text-2xl font-bold">{dinero(costo, simbolo)}</p>
              <p className="text-suave">{textoDuracion(res.salidas * duracion)} de aire · sin intereses</p>
            </div>
          </div>
          <div className="lg:col-span-2"><FormaPago frecuencia={frecuencia} setFrecuencia={setFrecuencia} cuotas={cuotas} setCuotas={setCuotas} monto={costo} desde={hoy} /></div>
        </div>
        <Alerta tipo="info" mensajes="El canal revisará su solicitud y su video. Si la acepta, su pauta queda aprobada y su spot empieza a salir al pagar la primera cuota." />
        {err && <Alerta tipo="error" mensajes={err} onCerrar={() => setErr(null)} />}
        <div className="flex justify-end gap-2">
          <Boton variante="secundario" onClick={onCerrar}>Cancelar</Boton>
          <Boton icono="send" cargando={enviando} disabled={progreso !== null} onClick={enviar}>Enviar solicitud</Boton>
        </div>
      </div>
    </Modal>
  )
}

/* ============================================================================ pagina del anunciante */

export function MisPautas() {
  const { simbolo } = usePautasConfig()
  const srv = useApi('/mis-pautas')
  const sol = useApi('/solicitudes')
  const [msg, mostrar] = useMensaje()
  const [nueva, setNueva] = useState(false)
  const [ver, setVer] = useState(null)
  const [verPauta, setVerPauta] = useState(null)
  const [cancelar, setCancelar] = useState(null)
  const anunciante = srv.datos?.datos?.anunciante ?? ''
  const pautas = srv.datos?.datos?.pautas ?? []
  const pautaVer = verPauta && pautas.find((p) => p.nombre === verPauta)
  const recargar = () => { srv.recargar(); sol.recargar() }
  // vuelta desde la pagina de Stripe (pago con tarjeta): ?sesion=ID confirma el pago con el servidor; ?cancelado=1 si se arrepintio
  const [params, setParams] = useSearchParams()
  useEffect(() => {
    const sesion = params.get('sesion')
    const cancelado = params.has('cancelado')
    if (!sesion && !cancelado) return
    setParams({}, { replace: true })
    if (cancelado) { mostrar({ titulo: 'Pago cancelado', mensaje: 'No se cobró nada. Puede intentarlo de nuevo.' }); return }
    get(`/mis-cobros/${sesion}`).then((r) => {
      mostrar(r.datos.estado === 'APROBADO' ? r : { titulo: 'Pago en proceso', mensaje: 'Stripe todavía no confirmó el pago; se actualizará solo en unos minutos.' })
      recargar()
    }).catch((e) => mostrar(e))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <Pagina titulo="Mis pautas" subtitulo={`${anunciante} · solicite sus pautas publicitarias, siga su revisión y el estado de sus pagos.`}
      acciones={<Boton icono="plus" onClick={() => setNueva(true)}>Solicitar pauta</Boton>}>
      {msg}
      <Tarjeta titulo={`Mis solicitudes (${sol.datos?.datos?.length ?? 0})`}>
        <ErrorCarga error={sol.error} onReintentar={sol.recargar} />
        {sol.cargando && !sol.datos ? <Cargando /> : (
          <Tabla filtrable={false} titulo="Solicitudes" vacio="Todavía no envió solicitudes. Use «Solicitar pauta»." filas={sol.datos?.datos ?? []} columnas={[
            { clave: 'nombre', titulo: 'Campaña', render: (s) => <span className="font-semibold">{s.nombre}</span> },
            { clave: 'titulo_spot', titulo: 'Spot', render: (s) => `${s.titulo_spot} · ${s.duracion_spot} s` },
            { clave: 'fecha_inicio', titulo: 'Vigencia', render: (s) => `${s.fecha_inicio} a ${s.fecha_fin}` },
            { clave: 'espacios', titulo: 'Espacios', render: (s) => textoEspacios(s.espacios) },
            { clave: 'salidas', titulo: 'Salidas', alinear: 'right' },
            { clave: 'costo', titulo: 'Costo', alinear: 'right', render: (s) => dinero(s.costo, simbolo) },
            { clave: 'estado', titulo: 'Estado', render: (s) => <Insignia valor={s.estado} /> },
            { clave: 'acc', titulo: '', render: (s) => <Boton tam="sm" variante="secundario" icono="eye" onClick={() => setVer(s)}>Ver</Boton> },
          ]} />
        )}
      </Tarjeta>
      <Tarjeta titulo="Mis pautas contratadas">
        <ErrorCarga error={srv.error} onReintentar={srv.recargar} />
        {srv.cargando && !srv.datos ? <Cargando /> : (
          <Tabla filtrable={false} titulo="Pautas" vacio="Todavía no tiene pautas contratadas." filas={pautas} columnas={[
            { clave: 'nombre', titulo: 'Pauta', render: (p) => <span className="font-semibold">{p.nombre}</span> },
            { clave: 'fecha_inicio', titulo: 'Vigencia', render: (p) => `${p.fecha_inicio} a ${p.fecha_fin}` },
            { clave: 'espacios', titulo: 'Espacios', render: (p) => textoEspacios(p.espacios) },
            { clave: 'salidas_usadas', titulo: 'Salidas', alinear: 'right', render: (p) => `${p.salidas_usadas} / ${p.salidas}` },
            { clave: 'saldo', titulo: 'Saldo', alinear: 'right', render: (p) => dinero(Math.max(0, p.cuenta.saldo), simbolo) },
            { clave: 'estado_pago', titulo: 'Estado', render: (p) => <Insignia valor={p.estado === 'APROBADA' ? p.estado_pago : p.estado} /> },
            { clave: 'acc', titulo: '', render: (p) => <Boton tam="sm" variante="secundario" icono="eye" onClick={() => setVerPauta(p.nombre)}>Ver</Boton> },
          ]} />
        )}
      </Tarjeta>

      <NuevaSolicitud abierto={nueva} onCerrar={() => setNueva(false)} onEnviada={(r) => { setNueva(false); mostrar(r); recargar() }} />
      <Modal abierto={!!ver} titulo={ver ? `Solicitud · ${ver.nombre}` : ''} onCerrar={() => setVer(null)} ancho="max-w-4xl">
        {ver && (
          <div className="space-y-4">
            <div className="flex items-center gap-2"><Insignia valor={ver.estado} /></div>
            {ver.estado === 'RECHAZADA' && <Alerta tipo="error" titulo="Solicitud rechazada" mensajes="El canal rechazó esta solicitud. Puede enviar una nueva." />}
            {ver.estado === 'APROBADA' && <Alerta tipo="exito" mensajes="Su solicitud fue aceptada: su pauta está aprobada. Empieza a salir cuando pague la primera cuota (con tarjeta o QR) (en «Mis pautas contratadas»)." />}
            <div className="grid gap-4 md:grid-cols-2"><VideoSolicitud s={ver} /><DatosSolicitud s={ver} /></div>
            <div className="flex justify-end gap-2">
              {ver.estado === EN_REVISION && <Boton variante="secundario" icono="x" onClick={() => setCancelar(ver)}>Cancelar solicitud</Boton>}
              <Boton variante="secundario" onClick={() => setVer(null)}>Cerrar</Boton>
            </div>
          </div>
        )}
      </Modal>
      <Modal abierto={!!pautaVer} titulo={pautaVer?.nombre ?? ''} onCerrar={() => setVerPauta(null)} ancho="max-w-5xl">
        {pautaVer && (
          <div className="space-y-4">
            <p className="text-sm text-suave">Vigente del {pautaVer.fecha_inicio} al {pautaVer.fecha_fin}. Pague con tarjeta o con QR (Cash App) en la página segura de Stripe.</p>
            <TarjetaSpot spot={pautaVer.spot} />
            <TablaSalidas pauta={pautaVer} />
            <TarjetaPago pauta={pautaVer} />
          </div>
        )}
      </Modal>
      <Confirmar abierto={!!cancelar} titulo="Cancelar solicitud" onCerrar={() => setCancelar(null)} textoBoton="Cancelar solicitud"
        mensaje={`¿Cancelar la solicitud <b>${cancelar?.nombre ?? ''}</b>? El canal ya no la revisará.`}
        onConfirmar={async () => { mostrar(await post(`/solicitudes/${cancelar.id}/cancelar`)); setVer(null); recargar() }} />
    </Pagina>
  )
}

/* ============================================================================ administrador: aceptar o rechazar */

/** Revisa una solicitud (ve el video y los datos) y la acepta o la rechaza. modo = 'aceptar' | 'rechazar'. */
export function ModalRevisar({ solicitud: ver, modo, onCerrar, onHecho }) {
  const [rechazando, setRechazando] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const [err, setErr] = useState(null)
  useEffect(() => { setRechazando(modo === 'rechazar'); setErr(null) }, [ver?.id, modo])
  const enviar = async (url, body) => {
    setEnviando(true); setErr(null)
    try { onHecho(await post(url, body)) } catch (e) { setErr(e.errores) } finally { setEnviando(false) }
  }
  return (
    <Modal abierto={!!ver} titulo={ver ? `${rechazando ? 'Rechazar' : 'Revisar'} solicitud de pauta · ${ver.nombre}` : ''} onCerrar={onCerrar} ancho="max-w-5xl">
      {ver && (
        <div className="space-y-4">
          <div className="grid gap-4 md:grid-cols-2"><VideoSolicitud s={ver} /><DatosSolicitud s={ver} /></div>
          {!rechazando && <Alerta tipo="info" mensajes="Al aceptar: la pauta queda APROBADA y se crean sus cuotas. Su pago queda PENDIENTE hasta que el anunciante pague la primera (con tarjeta o QR)." />}
          {rechazando && <Alerta tipo="aviso" mensajes="La solicitud queda RECHAZADA y se avisa al anunciante." />}
          {err && <Alerta tipo="error" mensajes={err} onCerrar={() => setErr(null)} />}
          <div className="flex flex-wrap justify-end gap-2">
            <Boton variante="secundario" onClick={onCerrar}>Cerrar</Boton>
            {!rechazando ? <Boton variante="peligro" icono="x" onClick={() => setRechazando(true)}>Rechazar</Boton>
              : <Boton variante="peligro" icono="x" cargando={enviando} onClick={() => enviar(`/solicitudes/${ver.id}/rechazar`)}>Confirmar rechazo</Boton>}
            {!rechazando && <Boton variante="exito" icono="check" cargando={enviando} onClick={() => enviar(`/solicitudes/${ver.id}/aprobar`)}>Aceptar pauta</Boton>}
          </div>
        </div>
      )}
    </Modal>
  )
}
