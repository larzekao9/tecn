import { useApi } from '../components/ui'

/*
 * Utilidades del contrato y los pagos de la pauta (CU6). Los datos y las reglas estan en el servidor
 * (ContratoService / SolicitudService); aqui solo se formatea y se calculan las vistas previas de los formularios.
 */

export const FRECUENCIAS = { CONTADO: 'Un solo pago', SEMANAL: 'Semanal', QUINCENAL: 'Quincenal', MENSUAL: 'Mensual', TRIMESTRAL: 'Trimestral' }
export const METODOS = { TARJETA: 'Tarjeta', QR: 'QR (Cash App)' }
export const DIAS_CORTOS = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

/** Simbolo de la moneda y maximo de cuotas (vienen del .env del servidor por /opciones). El precio es de cada espacio. */
export function usePautasConfig() {
  const c = useApi('/opciones').datos?.datos?.pautas
  return { simbolo: c?.simbolo ?? '', maxCuotas: c?.max_cuotas ?? 1, qrMoneda: c?.qr_moneda ?? '', tipoCambio: c?.tipo_cambio ?? 0, listo: !!c }
}

/** Dias de vigencia (inclusive) entre dos fechas AAAA-MM-DD. */
export const diasVigencia = (inicio, fin) => {
  if (!inicio || !fin || fin < inicio) return 0
  const [a, b] = [inicio, fin].map((f) => { const [y, m, d] = partes(f); return Date.UTC(y, m - 1, d) })
  return Math.round((b - a) / 86400000) + 1
}

/** "Noche (3 por día) y Mañana (2 por día)" */
export const textoEspacios = (espacios = []) => {
  const t = espacios.map((e) => `${e.nombre} (${e.salidas_por_dia} por día)`)
  return t.length > 1 ? `${t.slice(0, -1).join(', ')} y ${t[t.length - 1]}` : (t[0] ?? '')
}

export const dinero = (n, simbolo = '') => `${simbolo} ${Number(n ?? 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim()
export const textoPlan = (frecuencia, cuotas) => (frecuencia === 'CONTADO' ? 'un solo pago' : `${cuotas} cuotas ${FRECUENCIAS[frecuencia]?.toLowerCase()}es`)

const pad = (n) => String(n).padStart(2, '0')
const partes = (f) => f.split('-').map(Number)
const aTexto = (t) => `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())}`
export const masDias = (f, n) => { const [y, m, d] = partes(f); return aTexto(new Date(Date.UTC(y, m - 1, d + n))) }
function masMeses(f, n) {
  const [y, m, d] = partes(f)
  const t = new Date(Date.UTC(y, m - 1 + n, 1))
  const ultimo = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)).getUTCDate()
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(Math.min(d, ultimo))}`
}
/** 1 = lunes ... 7 = domingo */
export const diaSemana = (f) => { const [y, m, d] = partes(f); const w = new Date(Date.UTC(y, m - 1, d)).getUTCDay(); return w === 0 ? 7 : w }
export const etiquetaFecha = (f) => { const [, m, d] = partes(f); return `${DIAS_CORTOS[diaSemana(f)]} ${pad(d)}/${pad(m)}` }

const vencimiento = (desde, frecuencia, i) => (frecuencia === 'SEMANAL' ? masDias(desde, 7 * i) : frecuencia === 'QUINCENAL' ? masDias(desde, 15 * i)
  : frecuencia === 'TRIMESTRAL' ? masMeses(desde, 3 * i) : masMeses(desde, i))

/** Vista previa del calendario de cuotas (la misma regla que el servidor: la ultima absorbe los centavos). */
export function previaCuotas(total, n, desde, frecuencia) {
  const cant = frecuencia === 'CONTADO' ? 1 : n
  const cent = Math.round(total * 100)
  const base = Math.floor(cent / cant)
  return Array.from({ length: cant }, (_, i) => ({ numero: i + 1, vence: vencimiento(desde, frecuencia, i), monto: (base + (i === cant - 1 ? cent - base * cant : 0)) / 100 }))
}

export const validarCuotas = (frecuencia, n, max) => (frecuencia === 'CONTADO' ? null
  : !Number.isInteger(n) || n < 2 || n > max ? `El número de cuotas debe ser de 2 a ${max} (para 1, elija "Un solo pago").` : null)
