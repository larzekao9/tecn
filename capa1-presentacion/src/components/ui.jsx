import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { get } from '../lib/api'
import { validar } from '../lib/validar'
import Icono from './Icono'

/* ======================================================================= datos */

/** GET con estado de carga y recarga. */
export function useApi(url, params) {
  const [datos, setDatos] = useState(null)
  const [error, setError] = useState(null)
  const [cargando, setCargando] = useState(!!url)
  const clave = JSON.stringify(params ?? {})
  const recargar = useCallback(async () => {
    if (!url) return null
    setCargando(true)
    try {
      const r = await get(url, params)
      setDatos(r)
      setError(null)
      return r
    } catch (e) {
      setError(e)
      return null
    } finally {
      setCargando(false)
    }
  }, [url, clave]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { recargar() }, [recargar])
  return { datos, error, cargando, recargar, setDatos }
}

/* ======================================================================= basicos */

const VARIANTES = {
  primario: 'bg-primario text-sobre-primario hover:bg-primario-fuerte border-transparent',
  secundario: 'bg-superficie text-texto border-borde hover:bg-superficie-2',
  peligro: 'bg-peligro text-white border-transparent hover:opacity-90 contraste:text-sobre-primario',
  exito: 'bg-exito text-white border-transparent hover:opacity-90',
  fantasma: 'bg-transparent text-texto border-transparent hover:bg-superficie-2',
}

export function Boton({ variante = 'primario', icono, children, cargando, className = '', tam = 'md', ...props }) {
  const t = tam === 'sm' ? 'px-2.5 py-1 text-sm gap-1.5' : 'px-4 py-2 gap-2'
  return (
    <button type="button" {...props} disabled={props.disabled || cargando}
      className={`inline-flex items-center justify-center rounded-tema border font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${t} ${VARIANTES[variante]} ${className}`}>
      {cargando ? <span className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden /> : icono && <Icono nombre={icono} className={tam === 'sm' ? 'size-4' : 'size-5'} />}
      {children}
    </button>
  )
}

export function Tarjeta({ titulo, acciones, children, className = '', sinPadding }) {
  return (
    <section className={`rounded-tema-lg border border-borde bg-superficie shadow-sm ${className}`}>
      {(titulo || acciones) && (
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-borde px-4 py-3">
          {titulo && <h2 className="font-titulo text-lg font-semibold">{titulo}</h2>}
          {acciones && <div className="flex flex-wrap gap-2">{acciones}</div>}
        </header>
      )}
      <div className={sinPadding ? '' : 'p-4'}>{children}</div>
    </section>
  )
}

export function Pagina({ titulo, subtitulo, acciones, children }) {
  useEffect(() => { document.title = `${titulo} · Canal 11` }, [titulo])
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-titulo text-2xl font-bold sm:text-3xl">{titulo}</h1>
          {subtitulo && <p className="mt-1 text-suave">{subtitulo}</p>}
        </div>
        {acciones && <div className="flex flex-wrap gap-2">{acciones}</div>}
      </div>
      {children}
    </div>
  )
}

/** Mensajes del servidor: pueden traer <b>, <code>, <br>, tablas (los valores vienen escapados). */
export function Html({ html, className = '' }) {
  return <span className={`[&_code]:rounded [&_code]:bg-superficie-2 [&_code]:px-1 [&_code]:font-mono [&_code]:text-[0.9em] [&_table]:my-2 [&_table]:w-full [&_table]:text-sm [&_td]:border [&_td]:border-borde [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:border-borde [&_th]:bg-superficie-2 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_ul]:ml-5 [&_ul]:list-disc ${className}`}
    dangerouslySetInnerHTML={{ __html: html ?? '' }} />
}

const ALERTAS = {
  exito: ['bg-exito-tenue border-exito text-texto', 'check', 'text-exito'],
  error: ['bg-peligro-tenue border-peligro text-texto', 'alert', 'text-peligro'],
  aviso: ['bg-aviso-tenue border-aviso text-texto', 'alert', 'text-aviso'],
  info: ['bg-info-tenue border-info text-texto', 'info', 'text-info'],
}

export function Alerta({ tipo = 'info', titulo, mensajes, children, onCerrar }) {
  const [clase, icono, color] = ALERTAS[tipo]
  const lista = mensajes ? (Array.isArray(mensajes) ? mensajes : [mensajes]) : []
  return (
    <div role={tipo === 'error' ? 'alert' : 'status'} className={`flex gap-3 rounded-tema border-l-4 p-3 ${clase}`}>
      <Icono nombre={icono} className={`mt-0.5 size-5 ${color}`} />
      <div className="min-w-0 flex-1 space-y-1">
        {titulo && <p className="font-semibold">{titulo}</p>}
        {lista.length === 1 && <p><Html html={lista[0]} /></p>}
        {lista.length > 1 && <ul className="ml-5 list-disc space-y-1">{lista.map((m, i) => <li key={i}><Html html={m} /></li>)}</ul>}
        {children}
      </div>
      {onCerrar && <button type="button" onClick={onCerrar} className="self-start rounded p-1 hover:bg-superficie-2" aria-label="Cerrar mensaje"><Icono nombre="x" className="size-4" /></button>}
    </div>
  )
}

const ESTADOS = {
  // exito
  APROBADO: 'exito', PUBLICADA: 'exito', LISTO: 'exito', EMITIDO: 'exito', PAGADO: 'exito', PAGADA: 'exito', COMPLETADO: 'exito', TRANSMITIDO: 'exito', ACTIVA: 'exito', PREDETERMINADA: 'exito', ACTIVO: 'exito', LOGIN: 'exito', 'Sí': 'exito',
  // aviso
  PENDIENTE: 'aviso', BORRADOR: 'aviso', PARCIAL: 'aviso', CORTADO: 'aviso',PROGRAMADO: 'info', 'EN COLA': 'info', FINALIZADA: 'info', 'EN REVISION': 'aviso', APROBADA: 'exito', 'SIN PARRILLA': 'info', 'EN ESPERA': 'aviso', 'SIN REGISTRO': 'aviso', 'SIN VALIDAR': 'aviso', OBSERVADO: 'peligro', VENCIDA: 'peligro', VENCIDO: 'peligro', RECHAZADA: 'peligro', CANCELADA: 'peligro', SUSPENDIDA: 'peligro', EXPIRADO: 'peligro', DENEGADO: 'aviso',
  // peligro
  RECHAZADO: 'peligro', NO_EMITIDO: 'peligro', ANULADO: 'peligro', ANULADA: 'peligro', CANCELADO: 'peligro', 'SIN MATERIAL': 'peligro', 'MATERIAL ELIMINADO': 'peligro',
  'ELIMINADO': 'peligro', INACTIVO: 'peligro', LOGIN_FALLIDO: 'peligro', ERROR: 'peligro', 'NO EXISTE': 'peligro', No: 'peligro',
}
const COLORES_INSIGNIA = {
  exito: ['bg-exito-tenue text-exito border-exito/40', 'check'],
  aviso: ['bg-aviso-tenue text-aviso border-aviso/40', 'alert'],
  peligro: ['bg-peligro-tenue text-peligro border-peligro/40', 'x'],
  info: ['bg-info-tenue text-info border-info/40', 'info'],
  neutro: ['bg-superficie-2 text-suave border-borde', null],
}

/** Insignia de estado: siempre con texto (y un icono), nunca solo color. */
export function Insignia({ valor, tipo, children }) {
  const t = tipo || ESTADOS[valor] || 'neutro'
  const [clase, icono] = COLORES_INSIGNIA[t]
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-semibold ${clase}`}>
      {icono && <Icono nombre={icono} className="size-3" />}
      {children ?? String(valor ?? '').replaceAll('_', ' ')}
    </span>
  )
}

export function Cargando({ texto = 'Cargando...' }) {
  return (
    <div className="flex items-center justify-center gap-3 p-8 text-suave" role="status">
      <span className="size-6 animate-spin rounded-full border-4 border-primario border-t-transparent" aria-hidden />
      {texto}
    </div>
  )
}

export function Vacio({ texto = 'No hay registros para mostrar.', icono = 'info' }) {
  return (
    <div className="flex flex-col items-center gap-2 p-8 text-center text-suave">
      <Icono nombre={icono} className="size-8" />
      <p>{texto}</p>
    </div>
  )
}

export function ErrorCarga({ error, onReintentar }) {
  if (!error) return null
  return (
    <Alerta tipo="error" mensajes={error.errores}>
      {onReintentar && <Boton variante="secundario" tam="sm" icono="refresh" onClick={onReintentar} className="mt-2">Reintentar</Boton>}
    </Alerta>
  )
}

/* ======================================================================= tabla */

/**
 * Tabla con filtro local y paginacion.
 * columnas: [{ clave, titulo, render?(fila), alinear?: 'right' }]
 */
export function Tabla({ columnas, filas, porPagina = 15, filtrable = true, vacio, titulo, filaClase }) {
  const [q, setQ] = useState('')
  const [pag, setPag] = useState(0)
  const id = useId()
  const lista = useMemo(() => {
    let r = filas ?? []
    if (q.trim()) {
      const t = q.trim().toLowerCase()
      r = r.filter((f) => Object.values(f).some((v) => v !== null && typeof v !== 'object' && String(v).toLowerCase().includes(t)))
    }
    return r
  }, [filas, q])
  useEffect(() => setPag(0), [q, filas])
  const paginas = Math.max(1, Math.ceil(lista.length / porPagina))
  const visibles = lista.slice(pag * porPagina, (pag + 1) * porPagina)

  return (
    <div className="space-y-3">
      {filtrable && (filas?.length ?? 0) > 5 && (
        <div className="flex items-center gap-2">
          <label htmlFor={id} className="sr-only">Filtrar la tabla</label>
          <div className="relative w-full max-w-xs">
            <Icono nombre="search" className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-suave" />
            <input id={id} value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filtrar..."
              className="w-full rounded-tema border border-borde bg-superficie py-2 pr-3 pl-8 text-sm" />
          </div>
          <span className="text-sm text-suave">{lista.length} registro(s)</span>
        </div>
      )}
      <div className="overflow-x-auto rounded-tema border border-borde">
        <table className="w-full border-collapse text-sm">
          {titulo && <caption className="sr-only">{titulo}</caption>}
          <thead className="bg-superficie-2 text-left">
            <tr>
              {columnas.map((c) => (
                <th key={c.clave} scope="col" className={`px-3 py-2 font-semibold whitespace-nowrap ${c.alinear === 'right' ? 'text-right' : ''}`}>
                  {c.titulo}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {visibles.length === 0 && (
              <tr><td colSpan={columnas.length}><Vacio texto={vacio} /></td></tr>
            )}
            {visibles.map((f, i) => (
              <tr key={f.id ?? i} className={`border-t border-borde hover:bg-superficie-2/60 ${filaClase?.(f) ?? ''}`}>
                {columnas.map((c) => (
                  <td key={c.clave} className={`px-3 py-2 align-top ${c.alinear === 'right' ? 'text-right tabular-nums' : ''}`}>
                    {c.render ? c.render(f) : (f[c.clave] === true ? 'Sí' : f[c.clave] === false ? 'No' : f[c.clave])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {paginas > 1 && (
        <nav className="flex items-center justify-end gap-2 text-sm" aria-label="Paginación">
          <Boton variante="secundario" tam="sm" disabled={pag === 0} onClick={() => setPag(pag - 1)}>Anterior</Boton>
          <span>Página {pag + 1} de {paginas}</span>
          <Boton variante="secundario" tam="sm" disabled={pag >= paginas - 1} onClick={() => setPag(pag + 1)}>Siguiente</Boton>
        </nav>
      )}
    </div>
  )
}

/* ======================================================================= modal */

export function Modal({ abierto, titulo, onCerrar, children, ancho = 'max-w-lg' }) {
  const ref = useRef(null)
  useEffect(() => {
    if (!abierto) return undefined
    const anterior = document.activeElement
    const tecla = (e) => e.key === 'Escape' && onCerrar?.()
    document.addEventListener('keydown', tecla)
    setTimeout(() => ref.current?.querySelector('input, select, textarea, button')?.focus(), 30)
    return () => {
      document.removeEventListener('keydown', tecla)
      anterior?.focus?.()
    }
  }, [abierto]) // eslint-disable-line react-hooks/exhaustive-deps
  if (!abierto) return null
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center" onMouseDown={(e) => e.target === e.currentTarget && onCerrar?.()}>
      <div ref={ref} role="dialog" aria-modal="true" aria-label={titulo} className={`w-full ${ancho} rounded-tema-lg border border-borde bg-superficie shadow-2xl`}>
        <header className="flex items-center justify-between border-b border-borde px-5 py-3">
          <h2 className="font-titulo text-lg font-semibold">{titulo}</h2>
          <button type="button" onClick={onCerrar} className="rounded-tema p-1 hover:bg-superficie-2" aria-label="Cerrar"><Icono nombre="x" /></button>
        </header>
        <div className="max-h-[80vh] overflow-y-auto p-5">{children}</div>
      </div>
    </div>
  )
}

/** Confirmacion con campo opcional (ej. motivo). */
export function Confirmar({ abierto, titulo, mensaje, onCerrar, onConfirmar, variante = 'peligro', textoBoton = 'Confirmar', pedirMotivo }) {
  const [motivo, setMotivo] = useState('')
  const [error, setError] = useState(null)
  const [enviando, setEnviando] = useState(false)
  useEffect(() => { if (abierto) { setMotivo(''); setError(null) } }, [abierto])
  const confirmar = async () => {
    if (pedirMotivo && !motivo.trim()) { setError({ errores: ['El motivo es obligatorio.'] }); return }
    setEnviando(true)
    try {
      await onConfirmar(motivo.trim())
      onCerrar()
    } catch (e) {
      setError(e)
    } finally {
      setEnviando(false)
    }
  }
  return (
    <Modal abierto={abierto} titulo={titulo} onCerrar={onCerrar}>
      <div className="space-y-4">
        {mensaje && <p><Html html={mensaje} /></p>}
        {pedirMotivo && <Campo etiqueta={pedirMotivo} valor={motivo} onChange={setMotivo} tipo="textarea" requerido />}
        {error && <Alerta tipo="error" mensajes={error.errores} />}
        <div className="flex justify-end gap-2">
          <Boton variante="secundario" onClick={onCerrar}>Cancelar</Boton>
          <Boton variante={variante} onClick={confirmar} cargando={enviando}>{textoBoton}</Boton>
        </div>
      </div>
    </Modal>
  )
}

/* ======================================================================= formularios */

const CLASE_INPUT = 'w-full rounded-tema border bg-superficie px-3 py-2 text-texto placeholder:text-suave/70 disabled:opacity-60'

export function Campo({ etiqueta, nombre, valor, onChange, tipo = 'text', opciones, error, ayuda, requerido, placeholder, disabled, lista, min, max, paso, autoComplete }) {
  const id = useId()
  const borde = error ? 'border-peligro' : 'border-borde'
  const comunes = {
    id, name: nombre, disabled, 'aria-invalid': !!error, 'aria-describedby': error || ayuda ? `${id}-d` : undefined,
    required: requerido, className: `${CLASE_INPUT} ${borde}`,
  }
  let control
  if (tipo === 'select') {
    control = (
      <select {...comunes} value={valor ?? ''} onChange={(e) => onChange(e.target.value)}>
        <option value="">{placeholder ?? '— Seleccione —'}</option>
        {(opciones ?? []).map((o) => {
          const [v, t] = typeof o === 'object' ? [o.valor, o.texto] : [o, String(o).replaceAll('_', ' ')]
          return <option key={v} value={v}>{t}</option>
        })}
      </select>
    )
  } else if (tipo === 'textarea') {
    control = <textarea {...comunes} rows={3} value={valor ?? ''} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} />
  } else {
    control = (
      <>
        <input {...comunes} type={tipo} value={valor ?? ''} placeholder={placeholder} list={lista ? `${id}-l` : undefined}
          min={min} max={max} step={paso} autoComplete={autoComplete} onChange={(e) => onChange(e.target.value)} />
        {lista && <datalist id={`${id}-l`}>{lista.map((o) => <option key={o} value={o} />)}</datalist>}
      </>
    )
  }
  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium">
        {etiqueta}{requerido && <span className="text-peligro" aria-hidden> *</span>}
      </label>
      {control}
      {(error || ayuda) && (
        <p id={`${id}-d`} className={`text-xs ${error ? 'font-medium text-peligro' : 'text-suave'}`}>
          {error ? <><Icono nombre="alert" className="mr-1 inline size-3" />{error}</> : ayuda}
        </p>
      )}
    </div>
  )
}

/**
 * Formulario generico con validacion en el cliente (esquema de reglas) y en el servidor.
 * campos: [{ nombre, etiqueta, tipo, opciones, ayuda, requerido, lista, ancho: 'completo' }]
 */
export function Formulario({ campos, inicial = {}, esquema = {}, onEnviar, textoBoton = 'Guardar', onCancelar, onCambio, extra }) {
  const [valores, setValores] = useState(inicial)
  const [errores, setErrores] = useState({})
  const [errorServidor, setErrorServidor] = useState(null)
  const [enviando, setEnviando] = useState(false)

  const cambiar = (n, v) => {
    const nuevo = { ...valores, [n]: v }
    setValores(nuevo)
    if (errores[n]) setErrores((e) => ({ ...e, [n]: undefined }))
    onCambio?.(nuevo, setValores)
  }
  const enviar = async (e) => {
    e.preventDefault()
    const err = validar(valores, esquema)
    setErrores(err)
    setErrorServidor(null)
    if (Object.keys(err).length) {
      setErrorServidor({ errores: [`Revise ${Object.keys(err).length} campo(s) marcados en rojo.`] })
      return
    }
    setEnviando(true)
    try {
      await onEnviar(valores)
    } catch (ex) {
      setErrorServidor(ex)
    } finally {
      setEnviando(false)
    }
  }
  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        {campos.filter((c) => !c.oculto?.(valores)).map((c) => (
          <div key={c.nombre} className={c.ancho === 'completo' || c.tipo === 'textarea' ? 'sm:col-span-2' : ''}>
            <Campo {...c} valor={valores[c.nombre]} onChange={(v) => cambiar(c.nombre, v)} error={errores[c.nombre]}
              requerido={c.requerido} opciones={typeof c.opciones === 'function' ? c.opciones(valores) : c.opciones}
              disabled={typeof c.disabled === 'function' ? c.disabled(valores) : c.disabled}
              ayuda={typeof c.ayuda === 'function' ? c.ayuda(valores) : c.ayuda} />
          </div>
        ))}
      </div>
      {extra?.(valores)}
      {errorServidor && <Alerta tipo="error" titulo="No se pudo guardar" mensajes={errorServidor.errores} />}
      <div className="flex justify-end gap-2">
        {onCancelar && <Boton variante="secundario" onClick={onCancelar}>Cancelar</Boton>}
        <Boton type="submit" cargando={enviando} icono="check">{textoBoton}</Boton>
      </div>
    </form>
  )
}

/** Barra de filtro rapido con botones (chips). */
export function Chips({ opciones, valor, onChange, etiqueta = 'Filtro' }) {
  return (
    <div className="flex flex-wrap gap-2" role="group" aria-label={etiqueta}>
      {opciones.map((o) => {
        const [v, t] = typeof o === 'object' ? [o.valor, o.texto] : [o, o === '*' ? 'Todos' : String(o).replaceAll('_', ' ')]
        const activo = v === valor
        return (
          <button key={v} type="button" aria-pressed={activo} onClick={() => onChange(v)}
            className={`rounded-full border px-3 py-1 text-sm transition-colors ${activo ? 'border-primario bg-primario text-sobre-primario' : 'border-borde bg-superficie hover:bg-superficie-2'}`}>
            {t}
          </button>
        )
      })}
    </div>
  )
}

/** Mensaje temporal de exito tras una accion. */
export function useMensaje() {
  const [msg, setMsg] = useState(null)
  const mostrar = useCallback((r) => {
    setMsg(r ? { titulo: r.titulo, texto: r.mensaje, avisos: r.avisos } : null)
    if (r) window.scrollTo({ top: 0, behavior: 'smooth' })
  }, [])
  const vista = msg && (
    <Alerta tipo="exito" titulo={msg.titulo} mensajes={msg.texto} onCerrar={() => setMsg(null)}>
      {msg.avisos?.length > 0 && (
        <div className="mt-2"><p className="font-semibold">Avisos (no impidieron la operación):</p>
          <ul className="ml-5 list-disc">{msg.avisos.map((a, i) => <li key={i}>{a}</li>)}</ul></div>
      )}
    </Alerta>
  )
  return [vista, mostrar]
}
