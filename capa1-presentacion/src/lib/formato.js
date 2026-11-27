/** Formatos de presentacion (es-BO). */
export const hora = (h) => {
  if (!h) return ''
  if (h === '23:59:59') return '24:00'
  return h.length === 8 && h.endsWith(':00') ? h.slice(0, 5) : h
}

export const numero = (n) => Number(n ?? 0).toLocaleString('es-BO')

export const duracion = (seg) => {
  const s = Number(seg ?? 0)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const r = s % 60
  if (h) return `${h} h ${String(m).padStart(2, '0')} min`
  if (m) return `${m} min${r ? ` ${r} s` : ''}`
  return `${r} s`
}

export const fechaLarga = (f) => {
  if (!f) return ''
  const d = new Date(`${f}T12:00:00`)
  return d.toLocaleDateString('es-BO', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

export const hoyLocal = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const sumarDias = (f, n) => {
  const d = new Date(`${f}T12:00:00`)
  d.setDate(d.getDate() + n)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export const primerDiaMes = (f) => `${f.slice(0, 7)}-01`

/** { CODIGO: 'Nombre' } -> [{ valor, texto }] para los select (los nombres vienen del backend). */
export const aOpciones = (mapa) => Object.entries(mapa ?? {}).map(([valor, texto]) => ({ valor, texto }))

