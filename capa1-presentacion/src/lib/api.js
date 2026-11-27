import axios from 'axios'

/**
 * Cliente HTTP de la API RESTful (Capa 2). Agrega el token Bearer, normaliza los errores
 * (todos con { estado, mensaje, errores[] } en espanol) y avisa a la app si la sesion expiro
 * o si el usuario debe cambiar su contrasena.
 */
export const api = axios.create({
  baseURL: `${import.meta.env.BASE_URL}api`, // la API vive junto a la app (en desarrollo: /api)
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  timeout: 30000,
})

const CLAVE_TOKEN = 'canal11.token'

export const sesion = {
  token: () => localStorage.getItem(CLAVE_TOKEN),
  guardar: (t) => localStorage.setItem(CLAVE_TOKEN, t),
  borrar: () => localStorage.removeItem(CLAVE_TOKEN),
}

api.interceptors.request.use((config) => {
  const t = sesion.token()
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

api.interceptors.response.use(
  (r) => r,
  (error) => {
    const r = error.response
    const datos = r?.data || {}
    const normalizado = {
      estado: r?.status ?? 0,
      codigo: datos.codigo,
      mensaje: datos.message || (r ? 'Ocurrió un error al procesar la solicitud.' : 'No hay conexión con el servidor. Revise que la API esté en marcha.'),
      errores: datos.errores?.length ? datos.errores : [datos.message || (r ? 'Ocurrió un error al procesar la solicitud.' : 'No hay conexión con el servidor.')],
    }
    if (r?.status === 401 && sesion.token() && !error.config.url.includes('auth/login')) {
      sesion.borrar()
      window.dispatchEvent(new CustomEvent('canal11:sesion-expirada'))
    }
    return Promise.reject(normalizado)
  },
)

/** Atajos que devuelven directamente el cuerpo JSON. */
export const get = (url, params) => api.get(url, { params }).then((r) => r.data)
export const post = (url, body) => api.post(url, body ?? {}).then((r) => r.data)
export const put = (url, body) => api.put(url, body ?? {}).then((r) => r.data)
export const del = (url) => api.delete(url).then((r) => r.data)

/** Sube un archivo con multipart/form-data (el navegador pone el boundary). onProgress = evento de axios. */
export const subirArchivo = (url, formData, onProgress) =>
  api.post(url, formData, { headers: { 'Content-Type': undefined }, onUploadProgress: onProgress, timeout: 300000 }).then((r) => r.data)

/** Codifica un segmento de URL (nombres con espacios, emails, horas). */
export const seg = (s) => encodeURIComponent(String(s ?? ''))
