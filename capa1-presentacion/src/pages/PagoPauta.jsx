import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import Icono from '../components/Icono'
import { Alerta, Boton, Campo, Cargando, Insignia, Modal, Tabla, Tarjeta, useApi } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { post, seg } from '../lib/api'
import { duracion as textoDuracion, hoyLocal, sumarDias } from '../lib/formato'
import { FRECUENCIAS, METODOS, diasVigencia, dinero, etiquetaFecha, previaCuotas, textoEspacios, textoPlan, usePautasConfig, validarCuotas } from '../lib/pagosPauta'

/* ============================================================================ piezas compartidas (pauta y solicitud) */

/**
 * Espacios publicitarios: se marcan los horarios donde sale el spot y cuántas veces por día. Muestra lo que queda
 * libre de cada espacio en esas fechas (cupo - lo ya vendido) y el subtotal con su precio. valor = { idEspacio: salidas }.
 * onResumen recibe { costo, salidas, texto: "id:salidas,...", errores }.
 */
export function ElegirEspacios({ inicio, fin, duracion, valor, onChange, onResumen }) {
  const { simbolo } = usePautasConfig()
  const listo = !!inicio && !!fin && fin >= inicio
  const { datos, cargando, error } = useApi(listo ? '/espacios/disponibles' : null, { fecha_inicio: inicio, fecha_fin: fin })
  const espacios = datos?.datos ?? []
  const dias = diasVigencia(inicio, fin)
  const filas = espacios.map((e) => {
    const marcado = valor[e.id] !== undefined
    const n = Number(valor[e.id]) || 0
    const max = duracion > 0 ? Math.floor(e.libre / duracion) : 0
    const err = !marcado ? null : !Number.isInteger(n) || n < 1 || n > 20 ? 'De 1 a 20 por día.'
      : duracion > 0 && n * duracion > e.libre ? `Solo quedan ${e.libre} s por día: máximo ${max}.` : null
    return { ...e, marcado, n, max, err, subtotal: marcado && !err ? n * dias * duracion * e.precio_segundo : 0 }
  })
  const elegidas = filas.filter((f) => f.marcado)
  const costo = elegidas.reduce((t, f) => t + f.subtotal, 0)
  const salidas = elegidas.reduce((t, f) => t + (f.err ? 0 : f.n * dias), 0)
  const errores = [...(elegidas.length ? [] : ['Elija al menos un espacio publicitario.']), ...elegidas.filter((f) => f.err).map((f) => `${f.nombre}: ${f.err}`)]
  const texto = elegidas.map((f) => `${f.id}:${f.n}`).join(',')
  const clave = `${costo}|${salidas}|${texto}|${errores.join('|')}`
  useEffect(() => { onResumen?.({ costo, salidas, texto, errores }) }, [clave]) // eslint-disable-line react-hooks/exhaustive-deps
  const marcar = (e, si) => {
    const x = { ...valor }
    if (si) x[e.id] = '1'; else delete x[e.id]
    onChange(x)
  }
  if (!listo) return <p className="text-sm text-suave">Elija primero las fechas de vigencia para ver los espacios libres.</p>
  if (cargando && !datos) return <Cargando />
  if (error) return <Alerta tipo="error" mensajes={error.errores ?? ['No se pudieron cargar los espacios.']} />
  return (
    <div className="space-y-2">
      <p className="text-sm font-semibold">Espacios publicitarios <span className="text-peligro">*</span>
        <span className="font-normal text-suave"> · marque dónde sale su spot y cuántas veces por día ({dias} día(s) de vigencia)</span></p>
      <Tabla filtrable={false} titulo="Espacios" vacio="El canal todavía no tiene espacios publicitarios." filas={filas} columnas={[
        { clave: 'marcado', titulo: '', render: (e) => <input type="checkbox" aria-label={`Elegir ${e.nombre}`} checked={e.marcado} disabled={!e.marcado && e.max < 1}
          onChange={(ev) => marcar(e, ev.target.checked)} className="size-4 accent-primario" /> },
        { clave: 'nombre', titulo: 'Espacio', render: (e) => <span><b>{e.nombre}</b> <span className="font-mono text-suave">{e.hora_inicio}–{e.hora_fin}</span></span> },
        { clave: 'libre', titulo: 'Libre por día', alinear: 'right', render: (e) => <span className={e.max < 1 ? 'text-peligro' : ''}>{e.libre} s{duracion > 0 && ` (máx. ${e.max})`}</span> },
        { clave: 'precio_segundo', titulo: 'Precio / s', alinear: 'right', render: (e) => dinero(e.precio_segundo, simbolo) },
        { clave: 'n', titulo: 'Salidas por día', render: (e) => (e.marcado
          ? <div className="w-28">
              <input type="number" min={1} max={20} aria-label={`Salidas por día en ${e.nombre}`} value={valor[e.id]} onChange={(ev) => onChange({ ...valor, [e.id]: ev.target.value })}
                className={`w-full rounded-tema border bg-superficie px-2 py-1 ${e.err ? 'border-peligro' : 'border-borde'}`} />
              {e.err && <p className="mt-1 text-xs text-peligro">{e.err}</p>}
            </div>
          : '—') },
        { clave: 'subtotal', titulo: 'Subtotal', alinear: 'right', render: (e) => (e.marcado && !e.err ? <span title={`${e.n} × ${dias} días × ${duracion} s × ${dinero(e.precio_segundo, simbolo)}`}>{dinero(e.subtotal, simbolo)}</span> : '—') },
      ]} />
    </div>
  )
}

/** Forma de pago (frecuencia + numero de cuotas) con el calendario de cuotas. */
export function FormaPago({ frecuencia, setFrecuencia, cuotas, setCuotas, monto, desde }) {
  const { simbolo, maxCuotas } = usePautasConfig()
  const n = Number(cuotas)
  const error = validarCuotas(frecuencia, n, maxCuotas)
  const lista = !error && monto > 0 ? previaCuotas(monto, n, desde, frecuencia) : []
  return (
    <div className="space-y-3">
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Forma de pago" tipo="select" valor={frecuencia} onChange={setFrecuencia} requerido
          opciones={Object.entries(FRECUENCIAS).map(([valor, texto]) => ({ valor, texto }))} />
        {frecuencia !== 'CONTADO' && (
          <Campo etiqueta="Número de cuotas" tipo="number" valor={cuotas} onChange={setCuotas} min={2} max={maxCuotas} requerido
            error={cuotas !== '' ? error : null} ayuda="Sin intereses." />
        )}
      </div>
      {lista.length > 0 && (
        <Tabla filtrable={false} titulo="Calendario de pagos" filas={lista} columnas={[
          { clave: 'numero', titulo: 'Cuota' },
          { clave: 'vence', titulo: 'Vence', render: (c) => `${etiquetaFecha(c.vence)} · ${c.vence}` },
          { clave: 'monto', titulo: 'Monto', alinear: 'right', render: (c) => dinero(c.monto, simbolo) },
        ]} />
      )}
    </div>
  )
}

/* ============================================================================ nueva pauta (Administrador): con su contrato */

export function FormularioPauta({ anunciantes, onCancelar, onCreada }) {
  const { simbolo, maxCuotas } = usePautasConfig()
  const materiales = useApi('/opciones').datos?.datos?.materiales ?? []
  const hoy = hoyLocal()
  const [v, setV] = useState({ nombre: '', anunciante: '', spot: '', fecha_inicio: hoy, fecha_fin: sumarDias(hoy, 30), duracion: '30', frecuencia: 'CONTADO', cuotas: '3' })
  const [espacios, setEspacios] = useState({})
  const [res, setRes] = useState({ costo: 0, salidas: 0, texto: '', errores: [] })
  // los spots: videos registrados como material SPOT (pauta usa material)
  const spots = materiales.filter((m) => m.tipo === 'SPOT')
  const elegirSpot = (id) => {
    const m = spots.find((x) => String(x.id) === String(id))
    setV((o) => ({ ...o, spot: id, duracion: m ? String(m.duracion_seg) : o.duracion }))
  }
  const [err, setErr] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const set = (k) => (x) => setV((o) => ({ ...o, [k]: x }))
  const duracion = Number(v.duracion) || 0
  const costo = res.costo

  const enviar = async () => {
    const x = []
    if (!v.nombre.trim()) x.push('Escriba el nombre de la pauta.')
    if (!v.anunciante) x.push('Elija el anunciante.')
    if (!v.spot) x.push('Elija el spot (el video) que sale en esta pauta.')
    if (v.fecha_fin < v.fecha_inicio) x.push('La fecha de fin debe ser posterior a la de inicio.')
    if (!Number.isInteger(duracion) || duracion < 1 || duracion > 300) x.push('La duración del spot debe ser de 1 a 300 segundos.')
    x.push(...res.errores)
    const ec = validarCuotas(v.frecuencia, Number(v.cuotas), maxCuotas)
    if (ec) x.push(ec)
    if (x.length) { setErr(x); return }
    setEnviando(true); setErr(null)
    try {
      const r = await post('/pautas', { nombre: v.nombre.trim(), anunciante: v.anunciante, fecha_inicio: v.fecha_inicio, fecha_fin: v.fecha_fin,
        espacios: res.texto, duracion_spot: String(duracion), frecuencia: v.frecuencia, cuotas: v.frecuencia === 'CONTADO' ? '1' : v.cuotas, spot: v.spot })
      onCreada(r)
    } catch (e) { setErr(e.errores) } finally { setEnviando(false) }
  }

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2"><Campo etiqueta="Nombre de la pauta" valor={v.nombre} onChange={set('nombre')} requerido /></div>
        <div className="sm:col-span-2"><Campo etiqueta="Anunciante" tipo="select" valor={v.anunciante} onChange={set('anunciante')} requerido
          placeholder={anunciantes.length ? '— Elija —' : '— No hay usuarios anunciantes: créelos en Usuarios —'}
          opciones={anunciantes.map((a) => ({ valor: a.ci, texto: `${a.nombre} · CI ${a.ci}` }))} /></div>
        <div className="sm:col-span-2 lg:col-span-4">
          <Campo etiqueta="Spot (video)" tipo="select" valor={v.spot} onChange={elegirSpot} requerido
            placeholder={spots.length ? '— Elija el spot —' : '— No hay spots: registre el video en Materiales como Spot publicitario —'}
            opciones={spots.map((m) => ({ valor: String(m.id), texto: `${m.titulo} (${m.duracion_seg} s)` }))}
            ayuda="Es el video que el sistema pone solo en las pausas comerciales de la parrilla durante la vigencia." />
        </div>
        <Campo etiqueta="Vigente desde" tipo="date" valor={v.fecha_inicio} onChange={set('fecha_inicio')} requerido />
        <Campo etiqueta="Vigente hasta" tipo="date" valor={v.fecha_fin} onChange={set('fecha_fin')} min={v.fecha_inicio} requerido />
        <Campo etiqueta="Duración del spot (s)" tipo="number" valor={v.duracion} onChange={set('duracion')} min={1} max={300} requerido />
      </div>
      <ElegirEspacios inicio={v.fecha_inicio} fin={v.fecha_fin} duracion={duracion} valor={espacios} onChange={setEspacios} onResumen={setRes} />
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-tema bg-superficie-2 p-3 text-sm">
          <p>{res.salidas} salidas de {duracion} s</p>
          <p className="font-titulo text-2xl font-bold">{dinero(costo, simbolo)}</p>
          <p className="text-suave">{textoDuracion(res.salidas * duracion)} de aire · sin intereses</p>
        </div>
        <div className="lg:col-span-2">
          <FormaPago frecuencia={v.frecuencia} setFrecuencia={set('frecuencia')} cuotas={v.cuotas} setCuotas={set('cuotas')} monto={costo} desde={hoy} />
        </div>
      </div>
      {err && <Alerta tipo="error" mensajes={err} onCerrar={() => setErr(null)} />}
      <div className="flex justify-end gap-2">
        <Boton variante="secundario" onClick={onCancelar}>Cancelar</Boton>
        <Boton icono="check" cargando={enviando} onClick={enviar}>Registrar pauta</Boton>
      </div>
    </div>
  )
}

/* ============================================================================ pago del anunciante con tarjeta (Stripe) */

/** Formas de pago en Stripe: tarjeta (en bolivianos) o QR con Cash App Pay (Stripe lo cobra en dolares). */
const FORMAS_PAGO = [
  { valor: 'TARJETA', icono: 'card', titulo: 'Tarjeta', detalle: 'Débito o crédito (Visa, Mastercard…)' },
  { valor: 'QR', icono: 'qr', titulo: 'QR · Cash App', detalle: 'Escanee el código con la app Cash App' },
]

/**
 * El anunciante elige cuantas cuotas paga (las siguientes, primero las vencidas) y como (tarjeta o QR), paga en la
 * pagina segura de Stripe y vuelve a "Mis pautas", donde se confirma el pago.
 */
function ModalStripe({ abierto, pauta, cta, onCerrar }) {
  const { simbolo, qrMoneda, tipoCambio } = usePautasConfig()
  const faltan = cta.cuotas.filter((c) => c.estado !== 'PAGADO')
  const sugeridas = Math.max(1, faltan.filter((c) => c.estado === 'VENCIDO').length + (faltan.some((c) => c.estado === 'PENDIENTE') ? 1 : 0))
  const [n, setN] = useState(1)
  const [metodo, setMetodo] = useState('TARJETA')
  const [nit, setNit] = useState('')
  const [razon, setRazon] = useState('')
  const [err, setErr] = useState(null)
  const [enviando, setEnviando] = useState(false)
  useEffect(() => {
    if (abierto) {
      setN(Math.min(sugeridas, faltan.length)); setMetodo('TARJETA'); setErr(null)
      // la factura sale a nombre del anunciante salvo que escriba otro NIT y razon social
      setNit(/^\d{4,20}$/.test(pauta.anunciante_ci ?? '') ? pauta.anunciante_ci : ''); setRazon(pauta.anunciante ?? '')
    }
  }, [abierto]) // eslint-disable-line react-hooks/exhaustive-deps
  const elegidas = faltan.slice(0, n)
  const monto = elegidas.reduce((t, c) => t + Number(c.monto), 0)
  const enDolares = metodo === 'QR' && tipoCambio > 0 ? Math.round((monto / tipoCambio) * 100) / 100 : null
  const errNit = nit !== '' && !/^\d{4,20}$/.test(nit.trim()) ? 'Solo números, de 4 a 20 dígitos (puede usar su CI).' : null
  const pagar = async () => {
    if (!nit.trim() || errNit || !razon.trim()) { setErr(['Escriba el NIT (solo números) y la razón social para su factura.']); return }
    setEnviando(true); setErr(null)
    try {
      const r = await post(`/mis-pautas/${seg(pauta.nombre)}/stripe`, { cuotas: String(n), metodo, nit: nit.trim(), razon_social: razon.trim() })
      window.location.href = r.datos.url
    } catch (e) { setErr(e.errores); setEnviando(false) }
  }
  return (
    <Modal abierto={abierto} titulo={`Pagar · ${pauta.nombre}`} onCerrar={onCerrar}>
      <div className="space-y-4">
        <p className="text-sm text-suave">Corresponde pagar <b>{dinero(cta.exigible, simbolo)}</b> (cuotas vencidas + la próxima). Saldo total: {dinero(cta.saldo, simbolo)}.</p>
        <Campo etiqueta="¿Cuántas cuotas paga ahora?" tipo="select" valor={String(n)} onChange={(x) => setN(Number(x))} requerido
          opciones={faltan.map((c, i) => ({ valor: String(i + 1), texto: `${i + 1} cuota(s): hasta la N° ${c.numero} (vence ${c.vence})` }))}
          ayuda="Se pagan en orden: primero las vencidas y después las siguientes." />
        <ul className="space-y-1 rounded-tema border border-borde p-3 text-sm">
          {elegidas.map((c) => <li key={c.numero} className="flex justify-between"><span>Cuota {c.numero} · vence {c.vence} <Insignia valor={c.estado} /></span><b>{dinero(c.monto, simbolo)}</b></li>)}
        </ul>
        <fieldset>
          <legend className="mb-2 text-sm font-semibold">¿Cómo quiere pagar?</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {FORMAS_PAGO.map((f) => (
              <label key={f.valor} className={`flex cursor-pointer items-start gap-3 rounded-tema border p-3 ${metodo === f.valor ? 'border-primario bg-primario-tenue' : 'border-borde hover:bg-superficie-2'}`}>
                <input type="radio" name="metodo" value={f.valor} checked={metodo === f.valor} onChange={() => setMetodo(f.valor)} className="mt-1 accent-primario" />
                <span><span className="flex items-center gap-2 font-semibold"><Icono nombre={f.icono} className="size-5" />{f.titulo}</span>
                  <span className="block text-xs text-suave">{f.detalle}</span></span>
              </label>
            ))}
          </div>
        </fieldset>
        <fieldset>
          <legend className="mb-2 text-sm font-semibold">Datos para su factura</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            <Campo etiqueta="NIT o CI" valor={nit} onChange={setNit} requerido error={errNit} ayuda="Solo números." />
            <Campo etiqueta="Razón social" valor={razon} onChange={setRazon} requerido />
          </div>
          <p className="mt-1 text-xs text-suave">Cada cuota pagada genera su factura con estos datos.</p>
        </fieldset>
        {metodo === 'QR' && (
          <Alerta tipo="info" mensajes={enDolares !== null
            ? `Cash App cobra en dólares: ${dinero(monto, simbolo)} ÷ ${tipoCambio} = <b>${dinero(enDolares, qrMoneda)}</b>. La cuota se registra pagada por ${dinero(monto, simbolo)}.`
            : 'El pago por QR no está configurado (falta el tipo de cambio).'} />
        )}
        <p className="text-sm text-suave">Se abre la página segura de Stripe{metodo === 'QR' ? ', que muestra el código QR para escanear con Cash App' : ' para pagar con su tarjeta'}; al terminar vuelve aquí.</p>
        {err && <Alerta tipo="error" mensajes={err} onCerrar={() => setErr(null)} />}
        <div className="flex justify-end gap-2">
          <Boton variante="secundario" onClick={onCerrar}>Cancelar</Boton>
          <Boton icono={metodo === 'QR' ? 'qr' : 'card'} cargando={enviando} disabled={!faltan.length || (metodo === 'QR' && enDolares === null)} onClick={pagar}>
            {`Pagar ${enDolares !== null ? dinero(enDolares, qrMoneda) : dinero(monto, simbolo)}`}</Boton>
        </div>
      </div>
    </Modal>
  )
}

/* ============================================================================ spot asignado a la pauta */

/** El video de la pauta (material SPOT) con sus datos. */
export function TarjetaSpot({ spot }) {
  return (
    <Tarjeta titulo="Spot de la pauta" acciones={spot && <span className="font-mono text-sm">Material N° {spot.id}</span>}>
      {!spot ? <p className="text-sm text-suave">La pauta todavía no tiene un spot asignado.</p> : (
        <div className="grid gap-4 md:grid-cols-2">
          {spot.video_url
            ? <video src={spot.video_url} controls className="aspect-video w-full rounded-tema bg-black" />
            : <div className="grid aspect-video w-full place-items-center rounded-tema bg-superficie-2 text-sm text-suave">El archivo del video no está disponible.</div>}
          <dl className="grid h-fit grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt className="text-suave">Título</dt><dd>{spot.titulo}</dd>
            <dt className="text-suave">Duración</dt><dd>{spot.duracion_seg} s</dd>
            <dt className="text-suave">Formato</dt><dd>{spot.formato}</dd>          </dl>
        </div>
      )}
    </Tarjeta>
  )
}

/* ============================================================================ salidas del spot (las programa el sistema) */

const ESPERA = ['SIN PARRILLA', 'EN ESPERA']

/** Cada fecha y hora en que sale el spot de la pauta. Nadie las inserta a mano: el sistema las pone en la parrilla. */
export function TablaSalidas({ pauta, enlaces }) {
  const filas = (pauta.salidas_detalle ?? []).map((s, i) => ({ ...s, id: i }))
  const cuenta = (e) => filas.filter((s) => s.estado === e).length
  return (
    <Tarjeta titulo={`Salidas del spot (${pauta.salidas} contratadas: ${textoEspacios(pauta.espacios)})`}>
      <div className="space-y-3">
        <p className="text-sm text-suave">
          El sistema pone el spot solo, cada día, en las pausas comerciales de cada espacio elegido (una salida por pausa), mientras la pauta esté al día con su pago.
          Si una salida no se emite, se repone otro día en el mismo espacio.
          {filas.length > 0 && ` Emitidas ${cuenta('EMITIDO')} · programadas ${cuenta('PROGRAMADO')} · esperando su parrilla ${cuenta('SIN PARRILLA')}`
            + (cuenta('EN ESPERA') ? ` · en espera del pago ${cuenta('EN ESPERA')}` : '') + (cuenta('NO_EMITIDO') ? ` · no emitidas ${cuenta('NO_EMITIDO')} (se reponen)` : '') + '.'}
        </p>
        <Tabla filtrable={false} titulo="Salidas" vacio="No hay salidas: la pauta ya usó sus salidas o terminó su vigencia." filas={filas} columnas={[
          { clave: 'fecha', titulo: 'Día', render: (s) => (enlaces && !ESPERA.includes(s.estado)
            ? <Link to={`/parrillas/${s.fecha}`} className="text-primario underline">{etiquetaFecha(s.fecha)}</Link> : etiquetaFecha(s.fecha)) },
          { clave: 'f', titulo: 'Fecha', render: (s) => s.fecha },
          { clave: 'espacio', titulo: 'Espacio', render: (s) => s.espacio || '—' },
          { clave: 'hora', titulo: 'Hora', render: (s) => <span className="font-mono">{s.hora || '—'}</span> },
          { clave: 'pausa', titulo: 'Pausa', render: (s) => (s.pausa ? s.pausa.split('-').map((x) => x.slice(0, 5)).join('–') : '—') },
          { clave: 'estado', titulo: 'Estado', render: (s) => <Insignia valor={s.estado} /> },
        ]} />
      </div>
    </Tarjeta>
  )
}

/* ============================================================================ tarjeta del detalle de la pauta */

const AVISO = {
  PENDIENTE: ['aviso', 'Pendiente del primer pago: al pagarlo, la pauta se activa y su spot se programa solo en las pausas comerciales.'],
  SUSPENDIDA: ['error', 'Pauta SUSPENDIDA: tiene una cuota vencida sin pagar. Sus salidas futuras se retiraron de la parrilla y vuelven solas cuando se ponga al día (sin intereses).'],
  FINALIZADA: ['info', 'Pauta finalizada: pagada y con sus salidas usadas o la vigencia terminada.'],
}

/** Contrato, cuotas y pagos. Solo el anunciante paga (tarjeta o QR en Stripe); el Administrador solo consulta. */
export function TarjetaPago({ pauta }) {
  const { simbolo } = usePautasConfig()
  const { puede } = useAuth()
  const [stripe, setStripe] = useState(false)
  const cta = pauta.cuenta
  const aviso = AVISO[cta.estado]
  return (
    <Tarjeta titulo="Contrato y pagos" acciones={<Insignia valor={cta.estado} />}>
      <div className="space-y-4">
        {aviso && <Alerta tipo={aviso[0]} mensajes={aviso[1]} />}
        <p className="text-sm">
          <b>Contrato:</b> spot de {cta.duracion_spot} s en {textoEspacios(pauta.espacios)} · {cta.salidas} salidas
          <span className="text-suave"> · {textoPlan(cta.frecuencia, cta.numero_cuotas)} · salidas usadas {cta.salidas_usadas} de {cta.salidas}</span>
        </p>
        {(pauta.espacios ?? []).length > 0 && (
          <Tabla filtrable={false} titulo="Espacios contratados" filas={pauta.espacios} columnas={[
            { clave: 'nombre', titulo: 'Espacio', render: (e) => <span><b>{e.nombre}</b> <span className="font-mono text-suave">{e.hora_inicio}–{e.hora_fin}</span></span> },
            { clave: 'salidas_por_dia', titulo: 'Por día', alinear: 'right' },
            { clave: 'salidas', titulo: 'Salidas', alinear: 'right', render: (e) => `${e.usadas} / ${e.salidas}` },
            { clave: 'precio_segundo', titulo: 'Precio / s', alinear: 'right', render: (e) => dinero(e.precio_segundo, simbolo) },
            { clave: 'subtotal', titulo: 'Subtotal', alinear: 'right', render: (e) => dinero(e.subtotal, simbolo) },
          ]} />
        )}
        <div className="grid gap-3 sm:grid-cols-4">
          {[['Costo', cta.costo], ['Pagado', cta.pagado], ['Saldo', Math.max(0, cta.saldo)], ['Corresponde pagar', cta.exigible]].map(([t, x]) => (
            <div key={t} className="rounded-tema border border-borde p-3"><p className="text-sm text-suave">{t}</p><p className="font-titulo text-xl font-bold tabular-nums">{dinero(x, simbolo)}</p></div>
          ))}
        </div>
        {pauta.estado !== 'APROBADA' && cta.cuotas.length === 0 && <p className="text-sm text-suave">Las cuotas se crean cuando el canal aprueba la pauta.</p>}
        {pauta.estado === 'APROBADA' && cta.saldo > 0 && puede('pagStr') && (
          <div className="flex flex-wrap items-center gap-3">
            <Boton icono="card" onClick={() => setStripe(true)}>Pagar</Boton>
            <span className="text-sm text-suave">Con tarjeta o QR · pago seguro con Stripe.</span>
          </div>
        )}
        <Tabla filtrable={false} titulo="Cuotas" filas={cta.cuotas} columnas={[
          { clave: 'numero', titulo: 'Cuota' },
          { clave: 'vence', titulo: 'Vence', render: (x) => `${etiquetaFecha(x.vence)} · ${x.vence}` },
          { clave: 'monto', titulo: 'Monto', alinear: 'right', render: (x) => dinero(x.monto, simbolo) },
          { clave: 'estado', titulo: 'Estado', render: (x) => <span className="flex flex-wrap items-center gap-1"><Insignia valor={x.estado} />{x.en_proceso && <span className="text-xs text-suave">pago en proceso en Stripe</span>}</span> },
        ]} />
        {cta.pagos.length > 0 && (
          <Tabla filtrable={false} titulo="Pagos" filas={cta.pagos.map((x, i) => ({ ...x, id: i }))} columnas={[
            { clave: 'fecha', titulo: 'Fecha' },
            { clave: 'numero_cuota', titulo: 'Cuota' },
            { clave: 'monto', titulo: 'Monto', alinear: 'right', render: (x) => dinero(x.monto, simbolo) },
            { clave: 'metodo', titulo: 'Método', render: (x) => `${METODOS[x.metodo] ?? x.metodo}${x.comprobante ? ` · ${x.comprobante}` : ''}` },
            { clave: 'registrado_por', titulo: 'Pagó' },
            { clave: 'numero_factura', titulo: 'Factura', render: (x) => (x.numero_factura
              ? <span title={`Emitida ${x.fecha_factura}`}>N° {x.numero_factura} · NIT {x.nit} · {x.razon_social}</span> : '—') },
          ]} />
        )}
      </div>
      {puede('pagStr') && <ModalStripe abierto={stripe} pauta={pauta} cta={cta} onCerrar={() => setStripe(false)} />}
    </Tarjeta>
  )
}
