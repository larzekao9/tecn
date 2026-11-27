import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Medidor } from '../components/Graficos'
import { Alerta, Boton, Cargando, Confirmar, ErrorCarga, Insignia, Modal, Pagina, Tabla, Tarjeta, useApi, useMensaje } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { del, seg } from '../lib/api'
import { dinero, textoEspacios, usePautasConfig } from '../lib/pagosPauta'
import { FormularioPauta, TablaSalidas, TarjetaPago, TarjetaSpot } from './PagoPauta'
import { EN_REVISION, ModalRevisar } from './Solicitudes'

export function Pautas() {
  const { puede } = useAuth()
  const nav = useNavigate()
  const { simbolo } = usePautasConfig()
  const { datos, cargando, error, recargar } = useApi('/pautas', { filtro: '*' })
  const sol = useApi(puede('lisSol') ? '/solicitudes' : null)
  const anunciantes = useApi('/opciones').datos?.datos.anunciantes ?? []
  const [nueva, setNueva] = useState(false)
  const [revisar, setRevisar] = useState(null) // { s: solicitud, modo: 'aceptar' | 'rechazar' }
  const [msg, mostrar] = useMensaje()
  // las solicitudes (pautas EN REVISION) de los anunciantes van arriba, en la misma tabla
  const pendientes = (sol.datos?.datos ?? []).filter((s) => s.estado === EN_REVISION).map((s) => ({ ...s, solicitud: true }))
  const filas = [...pendientes, ...(datos?.datos ?? [])]
  const decidir = puede('apbSol') && puede('recSol')
  return (
    <Pagina titulo="Pautas publicitarias" subtitulo="CU6 · Pautas de los anunciantes, su contrato y plan de pago, y sus spots en las pausas comerciales."
      acciones={puede('insPau') && <Boton icono="plus" onClick={() => setNueva(true)}>Nueva pauta</Boton>}>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {pendientes.length > 0 && <Alerta tipo="aviso" mensajes={`Hay ${pendientes.length} pauta(s) solicitada(s) por anunciantes esperando su respuesta: acéptelas o recházelas en la tabla.`} />}
      {cargando && !datos ? <Cargando /> : (
        <Tabla titulo="Pautas" filas={filas} filaClase={(p) => (p.solicitud ? 'bg-aviso-tenue/40' : '')} columnas={[
          { clave: 'nombre', titulo: 'Pauta', render: (p) => (p.solicitud
            ? <button type="button" onClick={() => setRevisar({ s: p, modo: 'aceptar' })} className="text-left font-semibold text-primario underline">
                {p.nombre}<span className="block text-xs font-normal text-suave no-underline">Solicitud en revisión</span></button>
            : <Link to={`/pautas/${seg(p.nombre)}`} className="font-semibold text-primario underline">{p.nombre}</Link>) },
          { clave: 'anunciante', titulo: 'Anunciante' },
          { clave: 'fecha_inicio', titulo: 'Vigencia', render: (p) => `${p.fecha_inicio} a ${p.fecha_fin}` },
          { clave: 'espacios', titulo: 'Espacios', render: (p) => textoEspacios(p.espacios) },
          { clave: 'salidas_usadas', titulo: 'Salidas', alinear: 'right', render: (p) => `${p.salidas_usadas} / ${p.salidas}` },
          { clave: 'costo', titulo: 'Costo', alinear: 'right', render: (p) => dinero(p.costo, simbolo) },
          { clave: 'saldo', titulo: 'Saldo', alinear: 'right', render: (p) => (p.solicitud ? '—' : dinero(Math.max(0, p.saldo), simbolo)) },
          { clave: 'estado', titulo: 'Estado', render: (p) => <Insignia valor={p.estado} /> },
          { clave: 'estado_pago', titulo: 'Pago', render: (p) => (p.estado === 'APROBADA' ? <Insignia valor={p.estado_pago} /> : '—') },
          { clave: 'acc', titulo: '', render: (p) => p.solicitud && decidir && (
            <div className="flex flex-wrap gap-1">
              <Boton tam="sm" variante="exito" icono="check" onClick={() => setRevisar({ s: p, modo: 'aceptar' })}>Aceptar</Boton>
              <Boton tam="sm" variante="peligro" icono="x" onClick={() => setRevisar({ s: p, modo: 'rechazar' })}>Rechazar</Boton>
            </div>
          ) },
        ]} />
      )}
      <ModalRevisar solicitud={revisar?.s} modo={revisar?.modo} onCerrar={() => setRevisar(null)}
        onHecho={(m) => { setRevisar(null); mostrar(m); recargar(); sol.recargar() }} />
      <Modal abierto={nueva} titulo="Nueva pauta con su contrato y plan de pago" onCerrar={() => setNueva(false)} ancho="max-w-6xl">
        {nueva && <FormularioPauta anunciantes={anunciantes} onCancelar={() => setNueva(false)}
          onCreada={(res) => { setNueva(false); nav(`/pautas/${seg(res.datos.nombre)}`) }} />}
      </Modal>
    </Pagina>
  )
}

export function PautaDetalle() {
  const { nombre } = useParams()
  const nav = useNavigate()
  const { puede } = useAuth()
  const { datos, cargando, error, recargar } = useApi(`/pautas/${seg(nombre)}`)
  const [msg, mostrar] = useMensaje()
  const [baja, setBaja] = useState(false)
  const p = datos?.datos
  const aprobada = p?.estado === 'APROBADA'
  return (
    <Pagina titulo={p?.nombre ?? nombre} subtitulo={p ? `${p.anunciante} · vigente del ${p.fecha_inicio} al ${p.fecha_fin}` : ''}
      acciones={
        <>
          <Boton variante="secundario" onClick={() => nav('/pautas')}>Volver</Boton>
          {aprobada && puede('eliPau') && <Boton variante="secundario" icono="trash" onClick={() => setBaja(true)}>Cancelar pauta</Boton>}
        </>
      }>
      {msg}
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !p ? <Cargando /> : p && (
        <>
          {!aprobada && <Alerta tipo={p.estado === 'CANCELADA' ? 'info' : 'aviso'} mensajes={`La pauta está ${p.estado}. Sus spots ya no se programan.`} />}
          <Tarjeta><Medidor valor={p.salidas ? (p.salidas_usadas * 100) / p.salidas : 0}
            etiqueta={`Salidas usadas (${p.salidas_usadas} de ${p.salidas} contratadas, ${p.salidas_pendientes} pendientes)`} /></Tarjeta>
          <TarjetaSpot spot={p.spot} />
          <TarjetaPago pauta={p} />
          {aprobada && <TablaSalidas pauta={p} enlaces />}
        </>
      )}
      <Confirmar abierto={baja} titulo="Cancelar la pauta" onCerrar={() => setBaja(false)} textoBoton="Cancelar pauta"
        mensaje="La pauta queda CANCELADA: sus salidas que todavía no ocurrieron se retiran de la parrilla; lo ya emitido y lo pagado quedan como historial."
        onConfirmar={async () => { mostrar(await del(`/pautas/${seg(p.nombre)}`)); recargar() }} />
    </Pagina>
  )
}
