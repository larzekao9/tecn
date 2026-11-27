import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// URL de la API y puerto: variables de entorno (docker-compose.yml las toma del .env de la raiz).
// WEB_BASE: carpeta donde se publica la app compilada (ej. /inf513/grupo12sa/canal11/); sin definir = raiz del sitio.
export default defineConfig(({ command }) => {
  const base = process.env.WEB_BASE || '/'
  if (command !== 'serve') return { base, plugins: [react(), tailwindcss()] }

  const API = process.env.API_URL
  const PUERTO = Number(process.env.WEB_PUERTO)
  if (!API || !PUERTO) throw new Error('Faltan las variables de entorno API_URL y WEB_PUERTO')
  return {
    base,
    plugins: [react(), tailwindcss()],
    server: {
      port: PUERTO,
      proxy: {
        // xfwd: envia X-Forwarded-For para que la bitacora registre la IP real del cliente
        '/api': { target: API, changeOrigin: true, xfwd: true },
      },
      watch: { usePolling: true, interval: 500 }, // carpetas montadas en Docker (Windows / OneDrive)
    },
  }
})
