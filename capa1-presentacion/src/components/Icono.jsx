/** Iconos SVG en linea (trazo), heredan el color del texto. */
const P = {
  home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
  bell: 'M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 0 0 4 0',
  calendar: 'M4 6h16v14H4zM4 10h16M8 3v5M16 3v5',
  tv: 'M3 7h18v12H3zM8 3l4 4 4-4',
  film: 'M4 4h16v16H4zM8 4v16M16 4v16M4 9h4M4 15h4M16 9h4M16 15h4',
  radio: 'M12 12m-2 0a2 2 0 1 0 4 0a2 2 0 1 0-4 0M7.8 7.8a6 6 0 0 0 0 8.4M16.2 7.8a6 6 0 0 1 0 8.4M5 5a10 10 0 0 0 0 14M19 5a10 10 0 0 1 0 14',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8M4 21a8 8 0 0 1 16 0',
  users: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 7.5M18 14a6 6 0 0 1 4 7',
  megaphone: 'M3 10v4h4l7 5V5L7 10zM18 8a4 4 0 0 1 0 8',
  briefcase: 'M3 8h18v12H3zM8 8V5h8v3M3 13h18',
  card: 'M3 6h18v12H3zM3 10h18M7 15h4',
  layers: 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5',
  wallet: 'M3 7h16a2 2 0 0 1 2 2v9H3zM3 7l13-3v3M16 13h2',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  file: 'M6 3h9l5 5v13H6zM14 3v6h6M9 13h7M9 17h7',
  shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
  history: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 3',
  list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  terminal: 'M4 5h16v14H4zM8 10l3 2-3 2M13 15h4',
  help: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14M21 21l-5-5',
  menu: 'M4 6h16M4 12h16M4 18h16',
  x: 'M6 6l12 12M18 6L6 18',
  plus: 'M12 5v14M5 12h14',
  edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  check: 'M5 12l5 5 9-10',
  alert: 'M12 3l10 18H2zM12 10v4M12 18h.01',
  info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 11v6M12 7h.01',
  sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
  moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8',
  auto: 'M12 21a9 9 0 1 0 0-18v18',
  contrast: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 3v18',
  logout: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
  arrowUp: 'M12 19V5M6 11l6-6 6 6',
  arrowDown: 'M12 5v14M6 13l6 6 6-6',
  refresh: 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7',
  qr: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2zM14 18h2M18 14h2',
  send: 'M4 12l16-8-6 16-3-7z',
  eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6',
  print: 'M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7z',
  lock: 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
  play: 'M7 4l13 8-13 8z',
  circle: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18',
  folder: 'M3 6h6l2 2h10v11H3z',
  folderOpen: 'M3 6h6l2 2h8v3M3 6v13h15l3-8H6l-3 8',
  chevron: 'M9 6l6 6-6 6',
  accessibility:'M12 5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3M5 8l7 2 7-2M12 10v5l-3 6M12 15l3 6',
}

export default function Icono({ nombre, className = 'size-5', titulo }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
      className={`shrink-0 ${className}`} aria-hidden={titulo ? undefined : true} role={titulo ? 'img' : undefined}>
      {titulo && <title>{titulo}</title>}
      <path d={P[nombre] || P.circle} />
    </svg>
  )
}
