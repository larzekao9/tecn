import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import Icono from '../components/Icono'
import { Alerta, Boton, Campo, Cargando, Confirmar, ErrorCarga, Formulario, Insignia, Modal, Pagina, Tabla, Tarjeta, useApi, useMensaje } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { del, post, put, seg, subirArchivo } from '../lib/api'
import { aOpciones, duracion } from '../lib/formato'
import { reglas as r } from '../lib/validar'

/* =================================================================== CU2 Programas */

const camposPrograma = (tipos) => [
  { nombre: 'nombre', etiqueta: 'Nombre', requerido: true },
  { nombre: 'tipo', etiqueta: 'Tipo', tipo: 'select', opciones: tipos, requerido: true },
]
const esquemaPrograma = {
  nombre: [r.requerido('El nombre'), r.max(120, 'El nombre')],
  tipo: [r.requerido('El tipo')],
}

export function Programas() {
  const { puede } = useAuth()
  const { datos, cargando, error, recargar } = useApi('/programas', { filtro: '*' })
  const o = useApi('/opciones').datos?.datos
  const [editar, setEditar] = useState(null)
  const [baja, setBaja] = useState(null)
  const [msg, mostrar] = useMensaje()

  const guardar = async (v) => {
    const res = editar.id ? await put(`/programas/${seg(editar.nombre)}`, v) : await post('/programas', v)
    setEditar(null); mostrar(res); recargar()
  }
  return (
    <Pagina titulo="Catálogo de programas"
      acciones={puede('insPro') && <Boton icono="plus" onClick={() => setEditar({})}>Nuevo programa</Boton>}>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <Tabla titulo="Programas" filas={datos?.datos} columnas={[
          { clave: 'nombre', titulo: 'Nombre', render: (p) => <span className="font-semibold">{p.nombre}</span> },
          { clave: 'tipo', titulo: 'Tipo', render: (p) => p.tipo.replaceAll('_', ' ') },
          { clave: 'acc', titulo: 'Acciones', render: (p) => (
            <div className="flex flex-wrap gap-1">
              {puede('modPro') && <Boton tam="sm" variante="secundario" icono="edit" onClick={() => setEditar(p)}>Editar</Boton>}
              {puede('eliPro') && <Boton tam="sm" variante="secundario" icono="trash" onClick={() => setBaja(p)}>Eliminar</Boton>}
            </div>
          ) },
        ]} />
      )}
      <Modal abierto={!!editar} titulo={editar?.id ? `Editar ${editar.nombre}` : 'Nuevo programa'} onCerrar={() => setEditar(null)}>
        {editar && <Formulario campos={camposPrograma(o?.tipos_programa ?? [])} esquema={esquemaPrograma} inicial={editar} onEnviar={guardar} onCancelar={() => setEditar(null)} />}
      </Modal>
      <Confirmar abierto={!!baja} titulo="Eliminar el programa" onCerrar={() => setBaja(null)}
        mensaje={`¿Eliminar <b>${baja?.nombre}</b>? No se permite si tiene materiales o está en alguna parrilla.`}
        onConfirmar={async () => { mostrar(await del(`/programas/${seg(baja.nombre)}`)); recargar() }} textoBoton="Eliminar" />
    </Pagina>
  )
}

/* =================================================================== CU4 Materiales */


/** Botón para subir el video al proyecto. Al terminar, informa la ruta y el formato al formulario. */
function SubirVideo({ ruta, formato, onSubido, requerido }) {
  const [subiendo, setSubiendo] = useState(false)
  const [progreso, setProgreso] = useState(0)
  const [error, setError] = useState(null)
  const [nombre, setNombre] = useState('')
  const elegir = async (file) => {
    if (!file) return
    const ext = file.name.split('.').pop().toLowerCase()
    if (!['mp4', 'mov', 'mxf'].includes(ext)) { setError('El video debe ser .mp4, .mov o .mxf.'); return }
    setError(null); setSubiendo(true); setProgreso(0)
    try {
      const fd = new FormData()
      fd.append('video', file)
      const res = await subirArchivo('/materiales/video', fd, (e) => e.total && setProgreso(Math.round((e.loaded * 100) / e.total)))
      setNombre(`${res.datos.nombre} (${res.datos.tamano_mb} MB)`)
      onSubido(res.datos.ruta, res.datos.formato, res.datos.duracion_seg)
    } catch (e) {
      setError(e.errores?.[0] || 'No se pudo subir el video.')
    } finally {
      setSubiendo(false)
    }
  }
  return (
    <div className="space-y-1">
      <label className="block text-sm font-medium">Video del material{requerido && <span className="text-peligro" aria-hidden> *</span>}</label>
      <div className="flex flex-wrap items-center gap-3">
        <label className={`inline-flex cursor-pointer items-center gap-2 rounded-tema border border-borde bg-superficie px-4 py-2 font-medium hover:bg-superficie-2 ${subiendo ? 'pointer-events-none opacity-60' : ''}`}>
          <Icono nombre={ruta ? 'refresh' : 'film'} className="size-5" />
          {ruta ? 'Cambiar video' : 'Subir video'}
          <input type="file" accept=".mp4,.mov,.mxf,video/mp4,video/quicktime" className="hidden" onChange={(e) => elegir(e.target.files?.[0])} />
        </label>
        {subiendo && (
          <span className="flex items-center gap-2 text-sm text-suave">
            <span className="h-2 w-24 overflow-hidden rounded-full bg-superficie-2"><span className="block h-2 bg-primario transition-all" style={{ width: `${progreso}%` }} /></span>
            {progreso}%
          </span>
        )}
        {!subiendo && ruta && <span className="flex items-center gap-1 text-sm text-exito"><Icono nombre="check" className="size-4" />{nombre || `Video guardado (${formato})`}</span>}
      </div>
      {error && <p className="text-xs font-medium text-peligro"><Icono nombre="alert" className="mr-1 inline size-3" />{error}</p>}
      <p className="text-xs text-suave">MP4, MOV o MXF. Se guarda en el proyecto: <code>capa2-negocio/storage/app/private/videos</code>.</p>
    </div>
  )
}

/** Registrar/editar material: el video se sube (sin ruta ni formato manual). */
function FormMaterial({ inicial, programas, tipos, onEnviar, onCancelar }) {
  const edicion = !!inicial.id
  const [v, setV] = useState({
    titulo: inicial.titulo ?? '', tipo: inicial.tipo ?? 'PROGRAMA', programa: inicial.programa ?? '',
    duracion_seg: inicial.duracion_seg ?? '', ruta: inicial.ruta ?? '', formato: inicial.formato ?? '',
  })
  const [err, setErr] = useState({})
  const [errorServidor, setErrorServidor] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const set = (k, val) => { setV((o) => ({ ...o, [k]: val })); if (err[k]) setErr((e) => ({ ...e, [k]: undefined })) }
  const enviar = async (e) => {
    e.preventDefault()
    const x = {}
    if (!String(v.titulo).trim()) x.titulo = 'El título es obligatorio.'
    if (v.tipo === 'PROGRAMA' && !v.programa) x.programa = 'Un material de tipo Programa necesita su programa.'
    if (!v.ruta) x.video = 'Suba el video del material.'
    else if (!v.duracion_seg) x.video = 'No se pudo leer la duración del video. Suba un archivo válido.'
    setErr(x); setErrorServidor(null)
    if (Object.values(x).some(Boolean)) return
    setEnviando(true)
    try {
      await onEnviar({ titulo: v.titulo, tipo: v.tipo, programa: v.tipo === 'PROGRAMA' ? v.programa : '',
        duracion_seg: String(v.duracion_seg), formato: v.formato, ruta: v.ruta })
    } catch (ex) { setErrorServidor(ex) } finally { setEnviando(false) }
  }
  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Título" valor={v.titulo} onChange={(val) => set('titulo', val)} error={err.titulo} requerido />
        <Campo etiqueta="Tipo" tipo="select" valor={v.tipo} onChange={(val) => set('tipo', val)} opciones={aOpciones(tipos)} requerido />
        {v.tipo === 'PROGRAMA' && <Campo etiqueta="Programa" tipo="select" valor={v.programa} onChange={(val) => set('programa', val)} opciones={programas} error={err.programa} requerido />}
      </div>
      <SubirVideo ruta={v.ruta} formato={v.formato} requerido={!edicion}
        onSubido={(ruta, formato, duracion_seg) => setV((o) => ({ ...o, ruta, formato, ...(duracion_seg ? { duracion_seg: String(duracion_seg) } : {}) }))} />
      {v.ruta && v.duracion_seg && <p className="text-sm text-suave">Duración del video (automática): <strong className="text-texto">{duracion(v.duracion_seg)}</strong> ({v.duracion_seg} s)</p>}
      {err.video && <p className="text-xs font-medium text-peligro"><Icono nombre="alert" className="mr-1 inline size-3" />{err.video}</p>}
      {errorServidor && <Alerta tipo="error" titulo="No se pudo guardar" mensajes={errorServidor.errores} />}
      <div className="flex justify-end gap-2">
        <Boton variante="secundario" onClick={onCancelar}>Cancelar</Boton>
        <Boton type="submit" cargando={enviando} icono="check">Guardar</Boton>
      </div>
    </form>
  )
}

function useAccionesMaterial(recargar, mostrar, alEliminar = recargar) {
  const { puede } = useAuth()
  const ops = useApi(puede('lisPro') ? '/opciones' : null)
  const programas = (ops.datos?.datos.programas ?? []).map((p) => p.nombre)
  const tipos = ops.datos?.datos.tipos_material ?? {}
  const etiquetaTipo = (t) => tipos[t] ?? t
  const [editar, setEditar] = useState(null)
  const [baja, setBaja] = useState(null)
  const guardar = async (v) => {
    const res = editar.id ? await put(`/materiales/${editar.id}`, v) : await post('/materiales', v)
    setEditar(null); mostrar(res); recargar()
  }
  const botones = (m) => (
    <div className="flex flex-wrap gap-1">
      {puede('modMat') && <Boton tam="sm" variante="secundario" icono="edit" onClick={() => setEditar(m)}>Editar</Boton>}
      {puede('eliMat') && <Boton tam="sm" variante="secundario" icono="trash" onClick={() => setBaja(m)}>Eliminar</Boton>}
    </div>
  )
  const modales = (
    <>
      <Modal abierto={!!editar} titulo={editar?.id ? `Editar material #${editar.id}` : 'Registrar material'} onCerrar={() => setEditar(null)} ancho="max-w-2xl">
        {editar && <FormMaterial inicial={editar} programas={programas} tipos={tipos} onEnviar={guardar} onCancelar={() => setEditar(null)} />}
      </Modal>
      <Confirmar abierto={!!baja} titulo="Eliminar el material" onCerrar={() => setBaja(null)} textoBoton="Eliminar"
        mensaje={`¿Eliminar <b>${baja?.titulo}</b>? No se permite si está en una parrilla, escaleta o pauta.`}
        onConfirmar={async () => { mostrar(await del(`/materiales/${baja.id}`)); alEliminar() }} />
    </>
  )
  return { botones, modales, etiquetaTipo, nuevo: () => setEditar({ tipo: 'PROGRAMA' }) }
}

export function Materiales() {
  const { puede } = useAuth()
  const { datos, cargando, error, recargar } = useApi('/materiales', { filtro: '*' })
  const [msg, mostrar] = useMensaje()
  const acc = useAccionesMaterial(recargar, mostrar)
  return (
    <Pagina titulo="Materiales de video"
      acciones={puede('insMat') && <Boton icono="plus" onClick={acc.nuevo}>Registrar material</Boton>}>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <Tabla titulo="Materiales" filas={datos?.datos} columnas={[
          { clave: 'id', titulo: 'N°', alinear: 'right', render: (m) => <span className="font-mono text-suave">{m.id}</span> },
          { clave: 'titulo', titulo: 'Título', render: (m) => <Link to={`/materiales/${m.id}`} className="font-semibold text-primario underline">{m.titulo}</Link> },
          { clave: 'tipo', titulo: 'Tipo', render: (m) => acc.etiquetaTipo(m.tipo) },
          { clave: 'programa', titulo: 'Programa', render: (m) => m.programa || '—' },
          { clave: 'duracion_seg', titulo: 'Duración', alinear: 'right', render: (m) => duracion(m.duracion_seg) },
          { clave: 'registrado_por', titulo: 'Registrado por' },
          { clave: 'acc', titulo: 'Acciones', render: acc.botones },
        ]} />
      )}
      {acc.modales}
    </Pagina>
  )
}

export function MaterialDetalle() {
  const { id } = useParams()
  const nav = useNavigate()
  const { datos, cargando, error, recargar } = useApi(`/materiales/${seg(id)}`)
  const [msg, mostrar] = useMensaje()
  const acc = useAccionesMaterial(recargar, mostrar, () => nav('/materiales'))
  const m = datos?.datos
  return (
    <Pagina titulo={m?.titulo ?? 'Material'} subtitulo={m && `Material N° ${m.id}`} acciones={<Boton variante="secundario" onClick={() => nav('/materiales')}>Volver</Boton>}>
      {msg}
      <ErrorCarga error={error} />
      {cargando && !m ? <Cargando /> : m && (
        <div className="grid gap-5 lg:grid-cols-3">
          <Tarjeta titulo="Ficha" className="lg:col-span-2" acciones={acc.botones(m)}>
            <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
              {[['N°', m.id], ['Tipo', acc.etiquetaTipo(m.tipo)], ...(m.tipo === 'SPOT' ? [] : [['Programa', m.programa || '—']]), ['Duración', duracion(m.duracion_seg)], ['Formato', m.formato], ['Archivo', m.ruta],
                ['Registrado por', m.registrado_por]].map(([k, v]) => (
                <div key={k}><dt className="text-suave">{k}</dt><dd className="font-medium break-all">{v}</dd></div>
              ))}
            </dl>
            {m.posibles_duplicados?.length > 0 && <div className="mt-4"><Alerta tipo="aviso" titulo="Posibles duplicados" mensajes={`Otros materiales con el mismo título, tipo y duración: <b>${m.posibles_duplicados.join(', ')}</b>.`} /></div>}
          </Tarjeta>
          <Tarjeta titulo="Dónde se usa">
            {m.uso.length === 0 ? <p className="text-sm text-suave">No está en ninguna parrilla ni spot.</p> : (
              <ul className="space-y-2 text-sm">
                {m.uso.map((u, i) => (
                  <li key={i} className="flex items-center justify-between gap-2">
                    <Link to={`/parrillas/${u.fecha}`} className="text-primario underline">{u.fecha} {u.hora}</Link>
                    <span className="text-suave">{u.como}</span><Insignia valor={u.estado} />
                  </li>
                ))}
              </ul>
            )}
          </Tarjeta>
          <Tarjeta titulo="Video" className="lg:col-span-2">
            {!m.video_url ? <p className="text-sm text-suave">El archivo de video no está disponible en el servidor.</p>
              : m.formato === 'MXF' ? <p className="text-sm text-suave">El navegador no puede reproducir archivos MXF. <a href={m.video_url} className="text-primario underline">Descargar el video</a></p>
                : <video key={m.video_url} src={m.video_url} controls preload="metadata" className="max-h-[480px] w-full rounded-tema bg-black" />}
          </Tarjeta>
        </div>
      )}
      {acc.modales}
    </Pagina>
  )
}
