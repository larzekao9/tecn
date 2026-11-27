import { createContext, useCallback, useContext, useEffect, useState } from 'react'

/**
 * Tema visual y accesibilidad:
 *  - tema: NINOS | JOVENES | ADULTOS (segmento de usuario)
 *  - modo: DIA | NOCHE | AUTO (AUTO = dia de 07:00 a 19:00, noche el resto; se revisa cada minuto)
 *  - escala_fuente: 80 a 160 (% del tamano base) - alto_contraste: true/false
 * Se aplica con atributos en <html> que activan las variables de tema de Tailwind (src/index.css).
 * Se guarda en el navegador y, con sesion iniciada, en el perfil del usuario (API).
 */
const TemaContext = createContext(null)
const CLAVE = 'canal11.preferencias'
export const POR_DEFECTO = { tema: 'ADULTOS', modo: 'AUTO', escala_fuente: 100, alto_contraste: false }

const esDeDia = () => {
  const h = new Date().getHours()
  return h >= 7 && h < 19
}

export function TemaProvider({ children }) {
  const [prefs, setPrefs] = useState(() => {
    try {
      return { ...POR_DEFECTO, ...JSON.parse(localStorage.getItem(CLAVE) || '{}') }
    } catch {
      return POR_DEFECTO
    }
  })
  const [dia, setDia] = useState(esDeDia())
  const [alCambiar, setAlCambiar] = useState(null) // sincroniza con el servidor (lo registra AuthContext)

  useEffect(() => {
    const t = setInterval(() => setDia(esDeDia()), 60000)
    return () => clearInterval(t)
  }, [])

  const modoEfectivo = prefs.modo === 'AUTO' ? (dia ? 'dia' : 'noche') : prefs.modo === 'NOCHE' ? 'noche' : 'dia'

  useEffect(() => {
    const html = document.documentElement
    html.dataset.tema = prefs.tema
    html.dataset.modo = modoEfectivo
    if (prefs.alto_contraste) html.dataset.contraste = 'alto'
    else delete html.dataset.contraste
    html.style.fontSize = `${prefs.escala_fuente}%`
    localStorage.setItem(CLAVE, JSON.stringify(prefs))
  }, [prefs, modoEfectivo])

  const cambiar = useCallback((parcial) => {
    setPrefs((p) => {
      const nuevo = { ...p, ...parcial }
      nuevo.escala_fuente = Math.min(160, Math.max(80, Number(nuevo.escala_fuente) || 100))
      alCambiar?.fn?.(nuevo)
      return nuevo
    })
  }, [alCambiar])

  /** Carga las preferencias guardadas en el perfil (al iniciar sesion), sin volver a enviarlas. */
  const cargar = useCallback((p) => p && setPrefs({ ...POR_DEFECTO, ...p }), [])
  const registrarSincronizacion = useCallback((fn) => setAlCambiar({ fn }), [])

  return (
    <TemaContext.Provider value={{ ...prefs, modoEfectivo, cambiar, cargar, registrarSincronizacion }}>
      {children}
    </TemaContext.Provider>
  )
}

export const useTema = () => useContext(TemaContext)
