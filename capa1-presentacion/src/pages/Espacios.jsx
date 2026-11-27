import { useState } from 'react'
import { Boton, Cargando, Confirmar, ErrorCarga, Formulario, Modal, Pagina, Tabla, useApi, useMensaje } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { del, post, put, seg } from '../lib/api'
import { dinero, usePautasConfig } from '../lib/pagosPauta'
import { reglas as r } from '../lib/validar'

/* CU6 - Espacios publicitarios: horarios fijos del canal donde salen los spots, con su cupo de segundos por día y su precio. */

const campos = [
  { nombre: 'nombre', etiqueta: 'Nombre', requerido: true, ancho: 'completo', ayuda: 'Ej.: Mañana, Mediodía, Noche.' },
  { nombre: 'hora_inicio', etiqueta: 'Desde', tipo: 'time', requerido: true },
  { nombre: 'hora_fin', etiqueta: 'Hasta', tipo: 'time', requerido: true },
  { nombre: 'segundos_por_dia', etiqueta: 'Cupo por día (segundos)', tipo: 'number', min: 1, requerido: true,
    ayuda: 'Segundos de publicidad que se pueden vender cada día en este horario.' },
  { nombre: 'precio_segundo', etiqueta: 'Precio por segundo', tipo: 'number', min: 0.01, paso: '0.01', requerido: true,
    ayuda: 'Las pautas ya pedidas guardan el precio con que se pidieron.' },
]
const esquema = {
  nombre: [r.requerido('El nombre'), r.max(60, 'El nombre')],
  hora_inicio: [r.requerido('La hora de inicio'), r.hora('La hora de inicio')],
  hora_fin: [r.requerido('La hora de fin'), r.horaFin('La hora de fin'), r.despuesDe('hora_inicio', 'La hora de fin', 'la hora de inicio')],
  segundos_por_dia: [r.requerido('El cupo'), r.entero(1, 86400, 'El cupo')],
  precio_segundo: [r.requerido('El precio'), r.monto(0.01, 100000, 'El precio')],
}

export function Espacios() {
  const { puede } = useAuth()
  const { simbolo } = usePautasConfig()
  const { datos, cargando, error, recargar } = useApi('/espacios')
  const [editar, setEditar] = useState(null)
  const [baja, setBaja] = useState(null)
  const [msg, mostrar] = useMensaje()

  const guardar = async (v) => {
    const cuerpo = { ...v, segundos_por_dia: String(v.segundos_por_dia), precio_segundo: String(v.precio_segundo) }
    const res = editar.id ? await put(`/espacios/${seg(editar.nombre)}`, cuerpo) : await post('/espacios', cuerpo)
    setEditar(null); mostrar(res); recargar()
  }
  return (
    <Pagina titulo="Espacios publicitarios" subtitulo="CU6 · Horarios fijos del canal donde salen los spots: el anunciante elige en cuáles y cuántas veces por día."
      acciones={puede('insEsp') && <Boton icono="plus" onClick={() => setEditar({})}>Nuevo espacio</Boton>}>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <Tabla titulo="Espacios" filas={datos?.datos} vacio="No hay espacios: cree el primero con «Nuevo espacio»." columnas={[
          { clave: 'nombre', titulo: 'Espacio', render: (e) => <span className="font-semibold">{e.nombre}</span> },
          { clave: 'hora_inicio', titulo: 'Horario', render: (e) => <span className="font-mono">{e.hora_inicio} – {e.hora_fin}</span> },
          { clave: 'segundos_por_dia', titulo: 'Cupo por día', alinear: 'right', render: (e) => `${e.segundos_por_dia} s` },
          { clave: 'precio_segundo', titulo: 'Precio por segundo', alinear: 'right', render: (e) => dinero(e.precio_segundo, simbolo) },
          { clave: 'acc', titulo: 'Acciones', render: (e) => (
            <div className="flex flex-wrap gap-1">
              {puede('modEsp') && <Boton tam="sm" variante="secundario" icono="edit" onClick={() => setEditar(e)}>Editar</Boton>}
              {puede('eliEsp') && <Boton tam="sm" variante="secundario" icono="trash" onClick={() => setBaja(e)}>Eliminar</Boton>}
            </div>
          ) },
        ]} />
      )}
      <Modal abierto={!!editar} titulo={editar?.id ? `Editar ${editar.nombre}` : 'Nuevo espacio publicitario'} onCerrar={() => setEditar(null)}>
        {editar && <Formulario campos={campos} esquema={esquema} inicial={editar} onEnviar={guardar} onCancelar={() => setEditar(null)} />}
      </Modal>
      <Confirmar abierto={!!baja} titulo="Eliminar el espacio" onCerrar={() => setBaja(null)} textoBoton="Eliminar"
        mensaje={`¿Eliminar el espacio <b>${baja?.nombre ?? ''}</b>? No se permite si alguna pauta lo eligió.`}
        onConfirmar={async () => { mostrar(await del(`/espacios/${seg(baja.nombre)}`)); recargar() }} />
    </Pagina>
  )
}
