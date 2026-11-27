import { Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import { Cargando, Pagina, Vacio } from './components/ui'
import { useAuth } from './context/AuthContext'
import { Login } from './pages/Acceso'
import { PautaDetalle, Pautas } from './pages/Comercial'
import { Espacios } from './pages/Espacios'
import { Facturas } from './pages/Facturas'
import { Estadisticas, Inicio, Notificaciones } from './pages/Inicio'
import { Emision, ParrillaDetalle, Parrillas, Validacion } from './pages/Parrillas'
import { MaterialDetalle, Materiales, Programas } from './pages/Programacion'
import { Reportes } from './pages/Reportes'
import { MisPautas } from './pages/Solicitudes'
import { Bitacora, Roles, Usuarios } from './pages/Seguridad'

/** Ruta protegida: exige el permiso (matriz RBAC) ademas de la sesion. */
function Con({ permiso, alguno, children }) {
  const { puede } = useAuth()
  const ok = permiso ? puede(permiso) : alguno ? alguno.some(puede) : true
  if (!ok) {
    return (
      <Pagina titulo="Acceso restringido">
        <Vacio icono="lock" texto="Su rol no tiene permiso para ver esta página. Si cree que es un error, comuníquese con el Administrador." />
      </Pagina>
    )
  }
  return children
}

export default function App() {
  const { usuario, cargando } = useAuth()
  if (cargando) return <Cargando texto="Iniciando Canal 11..." />
  if (!usuario) {
    return (
      <Routes>
        <Route path="*" element={<Login />} />
      </Routes>
    )
  }

  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Inicio />} />
        <Route path="notificaciones" element={<Notificaciones />} />
        <Route path="parrillas" element={<Con permiso="lisPar"><Parrillas /></Con>} />
        <Route path="parrillas/:fecha" element={<Con permiso="mosPar"><ParrillaDetalle /></Con>} />
        <Route path="programas" element={<Con permiso="lisPro"><Programas /></Con>} />
        <Route path="materiales" element={<Con permiso="lisMat"><Materiales /></Con>} />
        <Route path="materiales/:id" element={<Con permiso="mosMat"><MaterialDetalle /></Con>} />
        <Route path="validacion" element={<Con permiso="lisVal"><Validacion /></Con>} />
        <Route path="emision" element={<Con permiso="conPar"><Emision /></Con>} />
        <Route path="pautas" element={<Con permiso="lisPau"><Pautas /></Con>} />
        <Route path="pautas/:nombre" element={<Con permiso="mosPau"><PautaDetalle /></Con>} />
        <Route path="espacios" element={<Con permiso="lisEsp"><Espacios /></Con>} />
        <Route path="facturas" element={<Con permiso="lisFac"><Facturas /></Con>} />
        <Route path="mis-pautas" element={<Con permiso="lisMpa"><MisPautas /></Con>} />
        <Route path="estadisticas" element={<Con permiso="verEst"><Estadisticas /></Con>} />
        <Route path="reportes" element={<Con alguno={['repPro', 'repMat', 'repPau', 'repCum']}><Reportes /></Con>} />
        <Route path="usuarios" element={<Con permiso="lisUsu"><Usuarios /></Con>} />
        <Route path="roles" element={<Con permiso="lisRol"><Roles /></Con>} />
        <Route path="bitacora" element={<Con permiso="lisBit"><Bitacora /></Con>} />
        <Route path="*" element={<Pagina titulo="Página no encontrada"><Vacio texto="La dirección no existe. Use el menú o el buscador." /></Pagina>} />
      </Route>
      <Route path="login" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
