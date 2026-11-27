/**
 * Validacion del lado del cliente (espejo de App\Support\Validador del servidor): mismos formatos y
 * mensajes en espanol. El servidor vuelve a validar todo; aqui se evita enviar datos que sabemos invalidos.
 *
 * Cada regla recibe (valor, campos) y devuelve un mensaje de error o null.
 */
const vacio = (v) => v === undefined || v === null || String(v).trim() === ''
const limpio = (v) => String(v ?? '').trim().replace(/\s+/g, ' ')

export const reglas = {
  requerido: (etq) => (v) => (vacio(v) ? `${etq} es obligatorio.` : null),
  max: (n, etq) => (v) => (!vacio(v) && limpio(v).length > n ? `${etq} admite máximo ${n} caracteres.` : null),
  letras: (etq) => (v) => (!vacio(v) && !/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ' ]+$/.test(limpio(v)) ? `${etq} solo admite letras y espacios.` : null),
  email: () => (v) => (!vacio(v) && !/^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*@([A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/.test(limpio(v))
    ? 'El correo no es válido (ej. nombre@gmail.com).' : null),
  ci: () => (v) => (!vacio(v) && !/^\d{5,12}(-[0-9A-Za-z]{1,3})?$/.test(limpio(v)) ? 'El CI debe tener de 5 a 12 dígitos y complemento opcional (ej. 7654321-1A).' : null),
  telefono: () => (v) => (!vacio(v) && !/^\+?\d{7,15}$/.test(String(v).replace(/\s/g, '')) ? 'El teléfono debe tener de 7 a 15 dígitos.' : null),
  entero: (min, max, etq) => (v) => {
    if (vacio(v)) return null
    if (!/^-?\d{1,9}$/.test(limpio(v))) return `${etq} debe ser un número entero.`
    const n = Number(v)
    return n < min || n > max ? `${etq} debe estar entre ${min} y ${max}.` : null
  },
  monto: (min, max, etq) => (v) => {
    if (vacio(v)) return null
    const s = limpio(v).replace(',', '.')
    if (!/^\d{1,10}(\.\d{1,2})?$/.test(s)) return `${etq} debe ser un monto válido con hasta 2 decimales.`
    const n = Number(s)
    return n < min || n > max ? `${etq} debe estar entre ${min} y ${max}.` : null
  },
  fecha: (etq) => (v) => {
    if (vacio(v)) return null
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v) || Number.isNaN(Date.parse(`${v}T00:00:00`))) return `${etq} debe ser una fecha válida AAAA-MM-DD.`
    return null
  },
  noPasada: (hoy, etq) => (v) => (!vacio(v) && v < hoy ? `${etq} no puede ser una fecha pasada.` : null),
  hora: (etq) => (v) => (!vacio(v) && !/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/.test(limpio(v)) ? `${etq} debe ser una hora válida HH:MM.` : null),
  horaFin: (etq) => (v) => (!vacio(v) && !/^(([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?|24:00(:00)?)$/.test(limpio(v)) ? `${etq} debe ser una hora válida HH:MM (o 24:00).` : null),
  despuesDe: (otro, etq, etqOtro) => (v, c) => (!vacio(v) && !vacio(c[otro]) && normHora(v) <= normHora(c[otro]) ? `${etq} debe ser posterior a ${etqOtro}.` : null),
  fechaDesde: (otro, etq, etqOtro) => (v, c) => (!vacio(v) && !vacio(c[otro]) && v < c[otro] ? `${etq} no puede ser anterior a ${etqOtro}.` : null),
}



const normHora = (h) => {
  const s = limpio(h)
  if (/^24:00/.test(s)) return '24:00:00'
  const [hh, mm, ss = '00'] = s.split(':')
  return `${hh.padStart(2, '0')}:${mm}:${ss}`
}

/** Valida todos los campos: { campo: [reglas] } -> { campo: 'mensaje' } (vacio si todo esta bien). */
export function validar(valores, esquema) {
  const errores = {}
  for (const [campo, lista] of Object.entries(esquema)) {
    for (const regla of lista.filter(Boolean)) {
      const msg = regla(valores[campo], valores)
      if (msg) { errores[campo] = msg; break }
    }
  }
  return errores
}
