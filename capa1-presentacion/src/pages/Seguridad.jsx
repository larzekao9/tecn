import { Fragment, useState } from 'react'
import { Alerta, Boton, Cargando, Confirmar, ErrorCarga, Formulario, Insignia, Modal, Pagina, Tabla, Tarjeta, useApi, useMensaje } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { del, post, put, seg } from '../lib/api'
import { aOpciones } from '../lib/formato'
import { reglas as r } from '../lib/validar'

/* =================================================================== CU1 Usuarios */


export function Usuarios() {
  const { usuario: yo } = useAuth()
  const { datos, cargando, error, recargar } = useApi('/usuarios', { filtro: '*' })
  const ops = useApi('/opciones').datos?.datos
  const roles = ops?.roles ?? {}
  const [msg, mostrar] = useMensaje()
  const [editar, setEditar] = useState(null)
  const [baja, setBaja] = useState(null)
  const nuevo = !editar?.id
  return (
    <Pagina titulo="Usuarios"
      acciones={<Boton icono="plus" onClick={() => setEditar({ rol: 'ENCARGADO' })}>Nuevo usuario</Boton>}>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <Tabla titulo="Usuarios" filas={datos?.datos} columnas={[
          { clave: 'nombre', titulo: 'Nombre', render: (u) => <b>{u.nombre} {u.apellido}</b> },
          { clave: 'email', titulo: 'Correo', render: (u) => <span className="text-sm">{u.email}</span> },
          { clave: 'rol', titulo: 'Rol', render: (u) => roles[u.rol] ?? u.rol },
          { clave: 'acc', titulo: 'Acciones', render: (u) => (
            <div className="flex flex-wrap gap-1">
              <Boton tam="sm" variante="secundario" icono="edit" onClick={() => setEditar(u)}>Editar</Boton>
              {u.activo && u.id !== yo.id && <Boton tam="sm" variante="secundario" icono="trash" onClick={() => setBaja(u)}>Eliminar</Boton>}
            </div>
          ) },
        ]} />
      )}
      <Modal abierto={!!editar} titulo={nuevo ? 'Nuevo usuario' : `Editar ${editar?.nombre ?? ''}`} onCerrar={() => setEditar(null)} ancho="max-w-2xl">
        {editar && (
          <Formulario inicial={editar} onCancelar={() => setEditar(null)}
            campos={[
              { nombre: 'ci', etiqueta: 'CI', requerido: true, ayuda: 'Ej. 7654321 o 7654321-1A' },
              { nombre: 'rol', etiqueta: 'Rol', tipo: 'select', opciones: aOpciones(roles), requerido: true },
              { nombre: 'nombre', etiqueta: 'Nombre(s)', requerido: true },
              { nombre: 'apellido', etiqueta: 'Apellido(s)', requerido: true },
              { nombre: 'email', etiqueta: 'Correo electrónico', tipo: 'email', requerido: true },
              { nombre: 'telefono', etiqueta: 'Teléfono' },
              { nombre: 'contrasena', etiqueta: 'Contraseña', tipo: 'password', autoComplete: 'new-password', requerido: true, oculto: () => !nuevo,
                ayuda: 'Mínimo 8 caracteres, con letras y números.' },
            ]}
            esquema={{
              ci: [r.requerido('El CI'), r.ci()],
              rol: [r.requerido('El rol')],
              nombre: [r.requerido('El nombre'), r.letras('El nombre'), r.max(80, 'El nombre')],
              apellido: [r.requerido('El apellido'), r.letras('El apellido'), r.max(80, 'El apellido')],
              email: [r.requerido('El correo'), r.email()],
              telefono: [r.telefono()],
              contrasena: [(v) => (!nuevo ? null : !v ? 'La contraseña es obligatoria.'
                : v.length < 8 || v.length > 72 ? 'La contraseña debe tener entre 8 y 72 caracteres.'
                : !/[A-Za-z]/.test(v) || !/\d/.test(v) ? 'La contraseña debe combinar letras y números.' : null)],
            }}
            onEnviar={async (v) => {
              const res = nuevo ? await post('/usuarios', v) : await put(`/usuarios/${seg(editar.email)}`, v)
              setEditar(null); mostrar(res); recargar()
            }} />
        )}
      </Modal>
      <Confirmar abierto={!!baja} titulo="Eliminar al usuario" onCerrar={() => setBaja(null)} textoBoton="Eliminar"
        mensaje={`¿Eliminar a <b>${baja?.nombre} ${baja?.apellido}</b>? Ya no podrá ingresar y sus sesiones se cierran.`}
        onConfirmar={async () => { mostrar(await del(`/usuarios/${seg(baja.email)}`)); recargar() }} />
    </Pagina>
  )
}

/* =================================================================== CU10 Matriz de control de acceso */

const ACCIONES_MATRIZ = [['ver', 'Ver'], ['crear', 'Crear'], ['editar', 'Editar'], ['eliminar', 'Eliminar']]

/** Matriz de acceso: por cada recurso (pantalla), que puede hacer cada rol: ver, crear, editar, eliminar. */
export function Roles() {
  const { datos, cargando, error, recargar, setDatos } = useApi('/roles')
  const { refrescar } = useAuth()
  const [err, setErr] = useState(null)
  const [ocupado, setOcupado] = useState(null)
  const d = datos?.datos
  const cambiar = async (rol, ruta, accion, concedido) => {
    setOcupado(`${rol}${ruta}${accion}`); setErr(null)
    try {
      const res = await put(`/roles/${rol}/permisos`, { recurso: ruta, accion: accion.toUpperCase(), concedido: concedido ? 'SI' : 'NO' })
      setDatos({ datos: res.datos })
      refrescar() // el menu del propio usuario puede cambiar
    } catch (e) { setErr(e) } finally { setOcupado(null) }
  }
  const grupos = []
  for (const r of d?.recursos ?? []) {
    const nombre = r.paquete ?? 'General'
    const g = grupos.find((x) => x.nombre === nombre)
    if (g) g.recursos.push(r)
    else grupos.push({ nombre, recursos: [r] })
  }
  return (
    <Pagina titulo="Matriz de acceso" subtitulo="Qué puede hacer cada rol en cada pantalla: ver, crear, editar y eliminar.">
      <ErrorCarga error={error} onReintentar={recargar} />
      {err && <Alerta tipo="error" mensajes={err.errores} onCerrar={() => setErr(null)} />}
      {cargando && !d ? <Cargando /> : d && (
        <Tarjeta sinPadding>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <caption className="sr-only">Permisos por rol y recurso</caption>
              <thead className="sticky top-0 bg-superficie-2">
                <tr>
                  <th scope="col" rowSpan={2} className="px-3 py-2 text-left">Recurso (pantalla)</th>
                  {d.roles.map((x) => <th key={x.codigo} scope="colgroup" colSpan={4} className="border-l border-borde px-3 py-2 text-center">{x.nombre}</th>)}
                </tr>
                <tr>
                  {d.roles.map((x) => ACCIONES_MATRIZ.map(([a, t]) => (
                    <th key={x.codigo + a} scope="col" className={`px-1 py-1 text-center text-xs font-normal text-suave ${a === 'ver' ? 'border-l border-borde' : ''}`}>{t}</th>
                  )))}
                </tr>
              </thead>
              <tbody>
                {grupos.map((g) => (
                  <Fragment key={g.nombre}>
                    <tr className="bg-primario-tenue"><th colSpan={d.roles.length * 4 + 1} scope="colgroup" className="px-3 py-1.5 text-left font-semibold">{g.nombre}</th></tr>
                    {g.recursos.map((r) => (
                      <tr key={r.ruta} className="border-t border-borde">
                        <th scope="row" className="px-3 py-1.5 text-left font-normal">{r.nombre}</th>
                        {d.roles.map((x) => ACCIONES_MATRIZ.map(([a, t]) => {
                          const existe = r.acciones.includes(a)
                          const tiene = !!d.matriz[x.codigo]?.[r.ruta]?.[a]
                          return (
                            <td key={x.codigo + a} className={`px-1 py-1.5 text-center ${a === 'ver' ? 'border-l border-borde' : ''}`}>
                              {existe ? (
                                <input type="checkbox" checked={tiene} disabled={!x.editable || ocupado === `${x.codigo}${r.ruta}${a}`}
                                  onChange={(e) => cambiar(x.codigo, r.ruta, a, e.target.checked)} className="size-4 accent-primario"
                                  aria-label={`${t} ${r.nombre} para ${x.nombre}`} />
                              ) : <span className="text-suave" aria-hidden>·</span>}
                            </td>
                          )
                        }))}
                      </tr>
                    ))}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </Tarjeta>
      )}
    </Pagina>
  )
}

/* =================================================================== CU1 Bitacora (accesos y acciones de los usuarios) */

/** accion de la bitacora -> [texto, color de la insignia] */
const ACCIONES_BITACORA = {
  LOGIN: ['Inicio de sesión', 'exito'],
  LOGIN_FALLIDO: ['Login fallido', 'peligro'],
  LOGOUT: ['Cierre de sesión', 'info'],
  ACCESO: ['Acceso', 'neutro'],
  ACCION: ['Acción', 'aviso'],
  ERROR: ['Error', 'peligro'],
}
const FILTRO_BITACORA = [['*', 'Todas las acciones'], ['LOGIN', 'Inicio de sesión'], ['LOGIN_FALLIDO', 'Login fallido'],
  ['LOGOUT', 'Cierre de sesión'], ['ACCESO', 'Acceso a recurso'], ['ACCION', 'Acción'], ['ERROR', 'Error del sistema']]

export function Bitacora() {
  const [accion, setAccion] = useState('*')
  const { datos, cargando, error, recargar } = useApi('/bitacora', { accion, desde: '', hasta: '', texto: '' })
  const d = datos?.datos
  return (
    <Pagina titulo="Bitácora" subtitulo="Auditoría de accesos y acciones">
      <Tarjeta>
        <label className="sr-only" htmlFor="filtro-bitacora">Acción</label>
        <select id="filtro-bitacora" value={accion} onChange={(e) => setAccion(e.target.value)}
          className="w-60 rounded-tema border border-borde bg-superficie px-3 py-2 text-sm">
          {FILTRO_BITACORA.map(([v, t]) => <option key={v} value={v}>{t}</option>)}
        </select>
      </Tarjeta>
      <Tarjeta>
        <ErrorCarga error={error} onReintentar={recargar} />
        {cargando && !d ? <Cargando /> : (
          <Tabla porPagina={25} filtrable={false} vacio="No hay registros." filas={d?.registros} columnas={[
            { clave: 'fecha', titulo: 'Fecha', render: (b) => <span className="whitespace-nowrap text-sm">{new Date(b.fecha.replace(' ', 'T')).toLocaleString('es-BO')}</span> },
            { clave: 'usuario', titulo: 'Usuario', render: (b) => <span className="text-sm">{b.usuario || '—'}</span> },
            { clave: 'accion', titulo: 'Acción', render: (b) => {
              const [texto, tipo] = ACCIONES_BITACORA[b.accion] ?? [b.accion, 'neutro']
              return <Insignia valor={b.accion} tipo={tipo}>{texto}</Insignia>
            } },
            { clave: 'recurso', titulo: 'Recurso', render: (b) => <span className="text-sm">{b.recurso || '—'}</span> },
            { clave: 'detalle', titulo: 'Detalle', render: (b) => <span className="text-sm">{b.detalle || '—'}</span> },
            { clave: 'ip', titulo: 'IP', render: (b) => <span className="font-mono text-sm">{b.ip || '—'}</span> },
          ]} />
        )}
      </Tarjeta>
    </Pagina>
  )
}
