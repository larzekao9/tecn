import { useState } from 'react'
import { Boton, Cargando, ErrorCarga, Modal, Pagina, Tabla, Tarjeta, useApi } from '../components/ui'
import { METODOS, dinero, textoEspacios, usePautasConfig } from '../lib/pagosPauta'

/* CU6 - Mis facturas (anunciante): cada cuota pagada de sus pautas genera su factura. */

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))

/** Abre la factura en una ventana aparte, lista para imprimir o guardar como PDF. */
function imprimir(f, simbolo) {
  const w = window.open('', '_blank', 'width=720,height=900')
  if (!w) return
  w.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Factura N° ${esc(f.numero)}</title>
<style>body{font-family:Arial,sans-serif;margin:40px;color:#111}h1{margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:16px}
td,th{border:1px solid #999;padding:6px 8px;text-align:left}th{background:#eee}.d{text-align:right}.s{color:#555;font-size:13px}</style></head><body>
<h1>Factura N° ${esc(f.numero)}</h1><p class="s">Fecha de emisión: ${esc(f.fecha)}</p>
<p><b>NIT/CI:</b> ${esc(f.nit)}<br><b>Razón social:</b> ${esc(f.razon_social)}</p>
<table><tr><th>Concepto</th><th class="d">Monto</th></tr>
<tr><td>Pauta publicitaria «${esc(f.pauta)}» — cuota ${esc(f.cuota)} de ${esc(f.numero_cuotas)}<br><span class="s">Vigencia ${esc(f.fecha_inicio)} a ${esc(f.fecha_fin)} · spot de ${esc(f.duracion_spot)} s${f.espacios?.length ? ` · ${esc(textoEspacios(f.espacios))}` : ''}</span></td>
<td class="d">${esc(dinero(f.total, simbolo))}</td></tr>
<tr><th>Total</th><th class="d">${esc(dinero(f.total, simbolo))}</th></tr></table>
<p class="s">Pagado con ${esc(METODOS[f.metodo] ?? f.metodo)}${f.comprobante ? ` · comprobante ${esc(f.comprobante)}` : ''}.</p>
<script>window.onload=function(){window.print()}<\/script></body></html>`)
  w.document.close()
}

export function Facturas() {
  const { simbolo } = usePautasConfig()
  const { datos, cargando, error, recargar } = useApi('/facturas', { filtro: '*' })
  const [numero, setNumero] = useState(null)
  const det = useApi(numero ? `/facturas/${numero}` : null)
  const f = numero && det.datos?.datos?.numero === numero ? det.datos.datos : null
  const filas = datos?.datos?.facturas ?? []
  return (
    <Pagina titulo="Mis facturas" subtitulo="Cada cuota que paga de sus pautas genera su factura con el NIT y la razón social que dio al pagar.">
      <ErrorCarga error={error} onReintentar={recargar} />
      {cargando && !datos ? <Cargando /> : (
        <>
          <Tarjeta><p className="text-sm text-suave">{filas.length} factura(s) · total facturado <b className="text-texto">{dinero(datos?.datos?.total ?? 0, simbolo)}</b></p></Tarjeta>
          <Tabla titulo="Facturas" filas={filas} vacio="Todavía no tiene facturas: se generan al pagar una cuota." columnas={[
            { clave: 'numero', titulo: 'N°', render: (x) => <span className="font-mono font-semibold">{x.numero}</span> },
            { clave: 'fecha', titulo: 'Fecha' },
            { clave: 'pauta', titulo: 'Pauta', render: (x) => `${x.pauta} · cuota ${x.cuota}/${x.numero_cuotas}` },
            { clave: 'nit', titulo: 'NIT/CI' },
            { clave: 'razon_social', titulo: 'Razón social' },
            { clave: 'metodo', titulo: 'Pago', render: (x) => METODOS[x.metodo] ?? x.metodo },
            { clave: 'total', titulo: 'Total', alinear: 'right', render: (x) => dinero(x.total, simbolo) },
            { clave: 'acc', titulo: '', render: (x) => <Boton tam="sm" variante="secundario" icono="eye" onClick={() => setNumero(x.numero)}>Ver</Boton> },
          ]} />
        </>
      )}
      <Modal abierto={!!numero} titulo={`Factura N° ${numero ?? ''}`} onCerrar={() => setNumero(null)} ancho="max-w-2xl">
        {numero && (det.cargando || !f) ? <Cargando /> : f && (
          <div className="space-y-4 text-sm">
            <div className="flex flex-wrap justify-between gap-2">
              <div><p className="font-titulo text-xl font-bold">Factura N° {f.numero}</p><p className="text-suave">Emitida el {f.fecha}</p></div>
              <div className="text-right"><p><b>NIT/CI:</b> {f.nit}</p><p><b>Razón social:</b> {f.razon_social}</p></div>
            </div>
            <div className="rounded-tema border border-borde">
              <div className="flex justify-between gap-3 border-b border-borde p-3">
                <div>
                  <p>Pauta publicitaria <b>{f.pauta}</b> — cuota {f.cuota} de {f.numero_cuotas}</p>
                  <p className="text-suave">Vigencia {f.fecha_inicio} a {f.fecha_fin} · spot de {f.duracion_spot} s · {textoEspacios(f.espacios)}</p>
                </div>
                <b className="whitespace-nowrap">{dinero(f.total, simbolo)}</b>
              </div>
              <div className="flex justify-between p-3 font-semibold"><span>Total</span><span>{dinero(f.total, simbolo)}</span></div>
            </div>
            <p className="text-suave">Pagado con {METODOS[f.metodo] ?? f.metodo}{f.comprobante && ` · comprobante ${f.comprobante}`}.</p>
            <div className="flex justify-end gap-2">
              <Boton variante="secundario" onClick={() => setNumero(null)}>Cerrar</Boton>
              <Boton icono="print" onClick={() => imprimir(f, simbolo)}>Imprimir / PDF</Boton>
            </div>
          </div>
        )}
      </Modal>
    </Pagina>
  )
}
