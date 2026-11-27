-- =====================================================================================================
-- Canal 11 - Sistema de Gestion de Programacion y Contenidos
-- Esquema canal11: el mismo modelo del diseño conceptual de la base de datos (figura del informe).
-- Cada tabla tiene su llave id_<tabla>. Las columnas son exactamente los atributos del diagrama (mas las FK).
-- =====================================================================================================

-- ===================================================================================== SEGURIDAD

CREATE TABLE rol (
    id_rol    SERIAL      PRIMARY KEY,
    nombre    VARCHAR(20) NOT NULL UNIQUE                  -- ADMINISTRADOR (siempre todos los permisos), ENCARGADO, PROGRAMADOR, ANUNCIANTE
);

-- recurso: cada pantalla del menu (el menu sale en el orden de id_recurso)
CREATE TABLE recurso (
    id_recurso  SERIAL       PRIMARY KEY,
    nombre      VARCHAR(60)  NOT NULL,
    ruta        VARCHAR(120) NOT NULL UNIQUE
);

-- rol accede a recurso (muchos a muchos): permiso dice que puede hacer el rol en esa pantalla (ver, crear, editar, eliminar).
-- Tabla intermedia: su llave es el par rol-recurso, sin id propio (igual que escaleta y pauta_espacio).
CREATE TABLE permiso (
    id_rol      INTEGER  NOT NULL REFERENCES rol (id_rol) ON DELETE CASCADE,
    id_recurso  INTEGER  NOT NULL REFERENCES recurso (id_recurso) ON DELETE CASCADE,
    ver         BOOLEAN  NOT NULL DEFAULT FALSE,
    crear       BOOLEAN  NOT NULL DEFAULT FALSE,
    editar      BOOLEAN  NOT NULL DEFAULT FALSE,
    eliminar    BOOLEAN  NOT NULL DEFAULT FALSE,
    PRIMARY KEY (id_rol, id_recurso)
);
CREATE INDEX idx_permiso_recurso ON permiso (id_recurso);

-- usuario posee rol
CREATE TABLE usuario (
    id_usuario         SERIAL       PRIMARY KEY,
    id_rol             INTEGER      NOT NULL REFERENCES rol (id_rol),
    ci                 VARCHAR(20)  NOT NULL UNIQUE,
    nombre             VARCHAR(80)  NOT NULL,
    apellido           VARCHAR(80)  NOT NULL,
    email              VARCHAR(120) NOT NULL UNIQUE,
    contrasena         VARCHAR(255) NOT NULL,              -- cifrada con bcrypt
    telefono           VARCHAR(20),
    activo             BOOLEAN      NOT NULL DEFAULT TRUE
);
CREATE UNIQUE INDEX ux_usuario_email_lower ON usuario (lower(email));
CREATE INDEX idx_usuario_rol ON usuario (id_rol);

-- usuario configura preferencia_usuario (1 a 1)
CREATE TABLE preferencia_usuario (
    id_preferencia  SERIAL      PRIMARY KEY,
    id_usuario      INTEGER     NOT NULL UNIQUE REFERENCES usuario (id_usuario) ON DELETE CASCADE,
    tema            VARCHAR(10) NOT NULL DEFAULT 'ADULTOS' CHECK (tema IN ('NINOS', 'JOVENES', 'ADULTOS')),
    modo            VARCHAR(5)  NOT NULL DEFAULT 'AUTO' CHECK (modo IN ('DIA', 'NOCHE', 'AUTO')),
    escala_fuente   INTEGER     NOT NULL DEFAULT 100 CHECK (escala_fuente BETWEEN 80 AND 160),
    alto_contraste  BOOLEAN     NOT NULL DEFAULT FALSE
);

-- Cada usuario nuevo nace con sus preferencias por defecto.
CREATE OR REPLACE FUNCTION fn_preferencia_inicial() RETURNS trigger AS $$
BEGIN
    INSERT INTO preferencia_usuario (id_usuario) VALUES (NEW.id_usuario);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_preferencia_inicial AFTER INSERT ON usuario FOR EACH ROW EXECUTE FUNCTION fn_preferencia_inicial();

-- usuario recibe notificacion
CREATE TABLE notificacion (
    id_notificacion  BIGSERIAL    PRIMARY KEY,
    id_usuario       INTEGER      NOT NULL REFERENCES usuario (id_usuario),
    titulo           VARCHAR(200) NOT NULL,
    mensaje          TEXT         NOT NULL,
    leida            BOOLEAN      NOT NULL DEFAULT FALSE,
    fecha            TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE INDEX idx_notificacion_usuario ON notificacion (id_usuario, leida, fecha DESC);

-- usuario realiza visita: cuantas veces entro cada usuario a cada pagina (sin sesion: id_usuario NULL)
CREATE TABLE visita (
    id_visita   BIGSERIAL   PRIMARY KEY,
    id_usuario  INTEGER     REFERENCES usuario (id_usuario),
    pagina      VARCHAR(80) NOT NULL,
    contador    BIGINT      NOT NULL DEFAULT 1 CHECK (contador > 0),
    CONSTRAINT uq_visita UNIQUE NULLS NOT DISTINCT (pagina, id_usuario)
);

-- usuario registra bitacora: unico registro de accesos y acciones (inicio/cierre de sesion, login fallido, acceso a cada
-- recurso y cada accion que cambia datos), con la IP y el navegador. Solo se inserta, nunca se modifica.
CREATE TABLE bitacora (
    id_bitacora     BIGSERIAL    PRIMARY KEY,
    id_usuario      INTEGER      REFERENCES usuario (id_usuario),
    accion          VARCHAR(20)  NOT NULL CHECK (accion IN ('LOGIN', 'LOGIN_FALLIDO', 'LOGOUT', 'ACCESO', 'ACCION', 'ERROR')),
    recurso         VARCHAR(60),                           -- modulo o dato afectado: Autenticación, Parrillas, programa...
    detalle         VARCHAR(500),                          -- que paso: Login, Registrar programa, GET /api/... -> 200
    ip              VARCHAR(45),                           -- IPv4 o IPv6 del cliente
    fecha           TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE INDEX idx_bitacora_fecha ON bitacora (fecha DESC);
CREATE INDEX idx_bitacora_accion ON bitacora (accion, fecha DESC);
CREATE INDEX idx_bitacora_usuario ON bitacora (id_usuario);

CREATE OR REPLACE FUNCTION fn_bitacora_inmutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'La bitacora es de solo insercion: no se permite % sobre bitacora.', TG_OP;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_bitacora_inmutable BEFORE UPDATE OR DELETE ON bitacora FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();

-- ===================================================================================== PROGRAMACION

CREATE TABLE programa (
    id_programa  SERIAL       PRIMARY KEY,
    nombre       VARCHAR(120) NOT NULL UNIQUE,
    tipo         VARCHAR(20)  NOT NULL CHECK (tipo IN ('NOTICIERO', 'PELICULA', 'SERIE', 'OTRO'))
);

-- programa agrupa material; usuario registra material
CREATE TABLE material (
    id_material     SERIAL       PRIMARY KEY,
    id_programa     INTEGER      REFERENCES programa (id_programa),
    id_usuario      INTEGER      NOT NULL REFERENCES usuario (id_usuario),
    titulo          VARCHAR(160) NOT NULL,
    tipo            VARCHAR(20)  NOT NULL CHECK (tipo IN ('PROGRAMA', 'SPOT')),
    duracion_seg    INTEGER      NOT NULL CHECK (duracion_seg > 0),
    formato         VARCHAR(10)  NOT NULL CHECK (formato IN ('MP4', 'MOV', 'MXF')),
    ruta            VARCHAR(255) NOT NULL UNIQUE,
    CONSTRAINT ck_material_programa CHECK (tipo <> 'PROGRAMA' OR id_programa IS NOT NULL)
);
CREATE INDEX idx_material_programa ON material (id_programa);
CREATE INDEX idx_material_usuario ON material (id_usuario);

-- usuario crea parrilla
CREATE TABLE parrilla (
    id_parrilla        SERIAL      PRIMARY KEY,
    id_usuario         INTEGER     NOT NULL REFERENCES usuario (id_usuario),
    fecha              DATE        NOT NULL UNIQUE,
    estado             VARCHAR(20) NOT NULL DEFAULT 'BORRADOR' CHECK (estado IN ('BORRADOR', 'PUBLICADA')),
    fecha_publicacion  TIMESTAMP
);

-- parrilla contiene bloque; programa se emite en bloque; material se asigna a bloque; usuario valida bloque
CREATE TABLE bloque (
    id_bloque              SERIAL       PRIMARY KEY,
    id_parrilla            INTEGER      NOT NULL REFERENCES parrilla (id_parrilla) ON DELETE CASCADE,
    id_programa            INTEGER      REFERENCES programa (id_programa),
    id_material            INTEGER      REFERENCES material (id_material),
    id_usuario             INTEGER      REFERENCES usuario (id_usuario),
    hora_inicio            TIME         NOT NULL,
    hora_fin               TIME         NOT NULL,
    tipo                   VARCHAR(20)  NOT NULL CHECK (tipo IN ('PROGRAMA', 'PAUSA_COMERCIAL')),
    estado                 VARCHAR(20)  NOT NULL DEFAULT 'EN COLA' CHECK (estado IN ('EN COLA', 'TRANSMITIDO', 'CANCELADO')),
    estado_validacion      VARCHAR(20)  NOT NULL DEFAULT 'PENDIENTE' CHECK (estado_validacion IN ('PENDIENTE', 'LISTO', 'OBSERVADO')),
    observacion            VARCHAR(255),
    CHECK (hora_fin > hora_inicio),
    UNIQUE (id_parrilla, hora_inicio),
    CONSTRAINT ck_bloque_tipo CHECK (
        (tipo = 'PROGRAMA' AND id_programa IS NOT NULL)
        OR (tipo = 'PAUSA_COMERCIAL' AND id_programa IS NULL AND id_material IS NULL)),
    CONSTRAINT ck_bloque_validacion CHECK (
        (estado_validacion = 'PENDIENTE' AND id_usuario IS NULL AND observacion IS NULL)
        OR (estado_validacion = 'LISTO' AND id_usuario IS NOT NULL)
        OR (estado_validacion = 'OBSERVADO' AND id_usuario IS NOT NULL AND btrim(observacion) <> ''))
);
CREATE INDEX idx_bloque_programa ON bloque (id_programa);
CREATE INDEX idx_bloque_material ON bloque (id_material);
CREATE INDEX idx_bloque_usuario ON bloque (id_usuario);

-- Dos bloques de la misma parrilla no se solapan: [hora_inicio, hora_fin).
DO $$
BEGIN
    CREATE EXTENSION IF NOT EXISTS btree_gist;
    ALTER TABLE bloque ADD CONSTRAINT ex_bloque_sin_solape EXCLUDE USING gist (
        id_parrilla WITH =,
        tsrange(DATE '2000-01-01' + hora_inicio, DATE '2000-01-01' + hora_fin, '[)') WITH &&);
EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'AVISO: no se pudo crear ex_bloque_sin_solape (%). El solape de bloques solo se valida en la aplicacion.', SQLERRM;
END
$$;

-- material se ordena en bloque: la escaleta del noticiero (clase asociacion con su orden).
-- El UNIQUE del orden es DEFERRABLE para poder reordenar con un solo UPDATE.
CREATE TABLE escaleta (
    id_bloque    INTEGER NOT NULL REFERENCES bloque (id_bloque) ON DELETE CASCADE,
    id_material  INTEGER NOT NULL REFERENCES material (id_material),
    orden        INTEGER NOT NULL CHECK (orden > 0),
    PRIMARY KEY (id_bloque, id_material),
    CONSTRAINT uq_escaleta_orden UNIQUE (id_bloque, orden) DEFERRABLE INITIALLY IMMEDIATE
);
CREATE INDEX idx_escaleta_material ON escaleta (id_material);

-- bloque se concilia con conciliacion (lo que realmente salio al aire); usuario registra conciliacion
CREATE TABLE conciliacion (
    id_conciliacion  SERIAL       PRIMARY KEY,
    id_bloque        INTEGER      NOT NULL UNIQUE REFERENCES bloque (id_bloque) ON DELETE CASCADE,
    id_usuario       INTEGER      NOT NULL REFERENCES usuario (id_usuario),
    inicio_real      TIMESTAMP,
    fin_real         TIMESTAMP,
    desvio_inicio    INTEGER,                              -- segundos entre la hora programada y el inicio real
    duracion_real    INTEGER,                              -- segundos que realmente estuvo al aire (fin_real - inicio_real)
    estado          VARCHAR(20)  NOT NULL CHECK (estado IN ('EMITIDO', 'PARCIAL', 'NO_EMITIDO')),
    observacion      VARCHAR(255),
    CONSTRAINT ck_conciliacion_horas CHECK (
        (estado = 'NO_EMITIDO' AND inicio_real IS NULL AND fin_real IS NULL)
        OR (estado <> 'NO_EMITIDO' AND inicio_real IS NOT NULL AND fin_real IS NOT NULL AND fin_real > inicio_real))
);
CREATE INDEX idx_conciliacion_usuario ON conciliacion (id_usuario);

-- Los tiempos de la conciliacion los calcula la BD: desvio del inicio = inicio real - (fecha de la parrilla + hora de inicio
-- del bloque); duracion real = fin real - inicio real. Un bloque NO_EMITIDO no tiene horas, ni desvio ni duracion.
CREATE OR REPLACE FUNCTION fn_desvio_inicio() RETURNS trigger AS $$
BEGIN
    IF NEW.inicio_real IS NULL THEN
        NEW.desvio_inicio := NULL;
        NEW.duracion_real := NULL;
    ELSE
        SELECT CAST(extract(epoch FROM (NEW.inicio_real - (p.fecha + b.hora_inicio))) AS INTEGER) INTO NEW.desvio_inicio
        FROM bloque b JOIN parrilla p ON p.id_parrilla = b.id_parrilla WHERE b.id_bloque = NEW.id_bloque;
        NEW.duracion_real := CAST(extract(epoch FROM (NEW.fin_real - NEW.inicio_real)) AS INTEGER);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_desvio_inicio BEFORE INSERT OR UPDATE ON conciliacion FOR EACH ROW EXECUTE FUNCTION fn_desvio_inicio();

-- El estado del bloque sigue a su conciliacion: EMITIDO/PARCIAL -> TRANSMITIDO, NO_EMITIDO -> CANCELADO.
-- Un bloque cancelado a mano (breaking news: CANCELADO antes de conciliarse; su material pasa a ser el de reemplazo)
-- solo admite NO_EMITIDO.
CREATE OR REPLACE FUNCTION fn_estado_bloque() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        UPDATE bloque SET estado = 'EN COLA' WHERE id_bloque = OLD.id_bloque;
        RETURN OLD;
    END IF;
    IF TG_OP = 'INSERT' AND NEW.estado <> 'NO_EMITIDO'
       AND EXISTS (SELECT 1 FROM bloque WHERE id_bloque = NEW.id_bloque AND estado = 'CANCELADO') THEN
        RAISE EXCEPTION 'El bloque fue cancelado: su conciliacion solo puede registrarse como NO_EMITIDO.';
    END IF;
    UPDATE bloque SET estado = CASE WHEN NEW.estado = 'NO_EMITIDO' THEN 'CANCELADO' ELSE 'TRANSMITIDO' END
     WHERE id_bloque = NEW.id_bloque;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_estado_bloque AFTER INSERT OR DELETE OR UPDATE OF estado ON conciliacion FOR EACH ROW EXECUTE FUNCTION fn_estado_bloque();

-- ===================================================================================== COMERCIAL

-- Espacios publicitarios: horarios fijos del canal, con el cupo de segundos que se vende cada dia y su precio.
CREATE TABLE espacio (
    id_espacio        SERIAL         PRIMARY KEY,
    nombre            VARCHAR(60)    NOT NULL UNIQUE,
    hora_inicio       TIME           NOT NULL,
    hora_fin          TIME           NOT NULL,
    segundos_por_dia  INTEGER        NOT NULL CHECK (segundos_por_dia > 0),
    precio_segundo    NUMERIC(10, 2) NOT NULL CHECK (precio_segundo > 0),
    CHECK (hora_fin > hora_inicio),
    CONSTRAINT ck_espacio_cupo CHECK (segundos_por_dia <= extract(epoch FROM (hora_fin - hora_inicio)))
);

-- Dos espacios no se solapan: asi cada spot pertenece a un solo espacio (por su hora).
DO $$
BEGIN
    ALTER TABLE espacio ADD CONSTRAINT ex_espacio_sin_solape EXCLUDE USING gist (
        tsrange(DATE '2000-01-01' + hora_inicio, DATE '2000-01-01' + hora_fin, '[)') WITH &&);
EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'AVISO: no se pudo crear ex_espacio_sin_solape (%). El solape de espacios solo se valida en la aplicacion.', SQLERRM;
END
$$;

-- usuario (anunciante) solicita pauta; pauta usa material (su video). La pauta EN REVISION es la solicitud; al
-- aprobarse queda cerrada. Las salidas contratadas y el estado de pago se calculan (no se guardan).
CREATE TABLE pauta (
    id_pauta         SERIAL         PRIMARY KEY,
    id_usuario       INTEGER        NOT NULL REFERENCES usuario (id_usuario),
    id_material      INTEGER        NOT NULL REFERENCES material (id_material),
    nombre           VARCHAR(150)   NOT NULL UNIQUE,
    fecha_inicio     DATE           NOT NULL,
    fecha_fin        DATE           NOT NULL,
    duracion_spot    INTEGER        NOT NULL CHECK (duracion_spot BETWEEN 1 AND 300),
    costo_total      NUMERIC(12, 2) NOT NULL CHECK (costo_total >= 0),
    frecuencia_pago  VARCHAR(12)    NOT NULL CHECK (frecuencia_pago IN ('CONTADO', 'SEMANAL', 'QUINCENAL', 'MENSUAL', 'TRIMESTRAL')),
    numero_cuotas    INTEGER        NOT NULL CHECK (numero_cuotas >= 1),
    estado           VARCHAR(15)    NOT NULL DEFAULT 'EN REVISION' CHECK (estado IN ('EN REVISION', 'APROBADA', 'RECHAZADA', 'CANCELADA')),
    CHECK (fecha_fin >= fecha_inicio)
);
CREATE INDEX idx_pauta_usuario ON pauta (id_usuario);
CREATE INDEX idx_pauta_material ON pauta (id_material);
CREATE INDEX idx_pauta_estado ON pauta (estado);

-- pauta elige espacio (clase asociacion): cuantas salidas por dia en ese espacio y el precio por segundo al pedirla.
CREATE TABLE pauta_espacio (
    id_pauta          INTEGER        NOT NULL REFERENCES pauta (id_pauta) ON DELETE CASCADE,
    id_espacio        INTEGER        NOT NULL REFERENCES espacio (id_espacio),
    salidas_por_dia   INTEGER        NOT NULL CHECK (salidas_por_dia BETWEEN 1 AND 20),
    precio_segundo    NUMERIC(10, 2) NOT NULL CHECK (precio_segundo > 0),
    PRIMARY KEY (id_pauta, id_espacio)
);
CREATE INDEX idx_pauta_espacio_espacio ON pauta_espacio (id_espacio);

-- Una pauta aprobada es un contrato cerrado: no cambian sus datos ni sus espacios; solo puede pasar a CANCELADA.
CREATE OR REPLACE FUNCTION fn_pauta_cerrada() RETURNS trigger AS $$
DECLARE
    est VARCHAR(15);
BEGIN
    IF TG_TABLE_NAME = 'pauta' THEN
        IF OLD.estado <> 'EN REVISION' AND (NEW.id_usuario, NEW.id_material, NEW.nombre, NEW.fecha_inicio, NEW.fecha_fin,
               NEW.duracion_spot, NEW.costo_total, NEW.frecuencia_pago, NEW.numero_cuotas)
               IS DISTINCT FROM (OLD.id_usuario, OLD.id_material, OLD.nombre, OLD.fecha_inicio, OLD.fecha_fin,
               OLD.duracion_spot, OLD.costo_total, OLD.frecuencia_pago, OLD.numero_cuotas) THEN
            RAISE EXCEPTION 'La pauta % ya fue %: sus datos no se pueden modificar.', OLD.nombre, lower(OLD.estado);
        END IF;
        IF OLD.estado IN ('RECHAZADA', 'CANCELADA') AND NEW.estado <> OLD.estado
           OR OLD.estado = 'APROBADA' AND NEW.estado NOT IN ('APROBADA', 'CANCELADA') THEN
            RAISE EXCEPTION 'La pauta % no puede pasar de % a %.', OLD.nombre, OLD.estado, NEW.estado;
        END IF;
        RETURN NEW;
    END IF;
    SELECT estado INTO est FROM pauta WHERE id_pauta = coalesce(NEW.id_pauta, OLD.id_pauta);
    IF est IS DISTINCT FROM 'EN REVISION' THEN
        RAISE EXCEPTION 'La pauta ya fue %: sus espacios no se pueden modificar.', lower(est);
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_pauta_cerrada BEFORE UPDATE ON pauta FOR EACH ROW EXECUTE FUNCTION fn_pauta_cerrada();
CREATE TRIGGER trg_pauta_espacio_cerrada BEFORE INSERT OR UPDATE OR DELETE ON pauta_espacio
    FOR EACH ROW EXECUTE FUNCTION fn_pauta_cerrada();

-- pauta se transmite en bloque (pausa comercial): spot es la clase asociacion (hora y estado de cada salida).
-- Su llave es el bloque y la hora de la salida, sin id propio.
CREATE TABLE spot (
    id_pauta   INTEGER     NOT NULL REFERENCES pauta (id_pauta),
    id_bloque  INTEGER     NOT NULL REFERENCES bloque (id_bloque) ON DELETE CASCADE,
    hora       TIME        NOT NULL,
    estado     VARCHAR(20) NOT NULL DEFAULT 'PROGRAMADO' CHECK (estado IN ('PROGRAMADO', 'EMITIDO', 'NO_EMITIDO')),
    PRIMARY KEY (id_bloque, hora)
);
CREATE INDEX idx_spot_pauta ON spot (id_pauta);

-- Un spot va en una pausa comercial, dentro de sus horas; la pauta debe estar aprobada y vigente ese dia; la hora debe
-- caer en un espacio que la pauta eligio, sin pasar sus salidas contratadas en ese espacio (salidas por dia x dias)
-- ni el cupo de segundos del espacio ese dia.
CREATE OR REPLACE FUNCTION fn_validar_spot() RETURNS trigger AS $$
DECLARE
    b        bloque%ROWTYPE;
    pa       pauta%ROWTYPE;
    f        DATE;
    usados   INTEGER;
    es       RECORD;
BEGIN
    SELECT * INTO b FROM bloque WHERE id_bloque = NEW.id_bloque;
    IF b.tipo <> 'PAUSA_COMERCIAL' THEN
        RAISE EXCEPTION 'Un spot solo puede ubicarse en una pausa comercial.';
    END IF;
    IF NEW.hora < b.hora_inicio OR NEW.hora >= b.hora_fin THEN
        RAISE EXCEPTION 'La hora del spot (%) debe estar dentro de la pausa comercial (% a %).', NEW.hora, b.hora_inicio, b.hora_fin;
    END IF;
    SELECT * INTO pa FROM pauta WHERE id_pauta = NEW.id_pauta;
    IF pa.estado <> 'APROBADA' THEN
        RAISE EXCEPTION 'La pauta % no esta aprobada (estado: %).', pa.nombre, pa.estado;
    END IF;
    SELECT p.fecha INTO f FROM parrilla p WHERE p.id_parrilla = b.id_parrilla;
    IF f < pa.fecha_inicio OR f > pa.fecha_fin THEN
        RAISE EXCEPTION 'La fecha % esta fuera de la vigencia de la pauta % (% a %).', f, pa.nombre, pa.fecha_inicio, pa.fecha_fin;
    END IF;
    SELECT e.nombre, e.hora_inicio, e.hora_fin, e.segundos_por_dia, pe.salidas_por_dia INTO es
      FROM pauta_espacio pe JOIN espacio e ON e.id_espacio = pe.id_espacio
     WHERE pe.id_pauta = NEW.id_pauta AND NEW.hora >= e.hora_inicio AND NEW.hora < e.hora_fin;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'La hora % no esta en ningun espacio contratado por la pauta %.', NEW.hora, pa.nombre;
    END IF;
    SELECT count(*) INTO usados FROM spot s
     WHERE s.id_pauta = NEW.id_pauta AND s.estado <> 'NO_EMITIDO' AND (s.id_bloque, s.hora) IS DISTINCT FROM (OLD.id_bloque, OLD.hora)
       AND s.hora >= es.hora_inicio AND s.hora < es.hora_fin;
    IF usados >= es.salidas_por_dia * (pa.fecha_fin - pa.fecha_inicio + 1) THEN
        RAISE EXCEPTION 'La pauta % ya uso sus % salidas del espacio %.', pa.nombre, es.salidas_por_dia * (pa.fecha_fin - pa.fecha_inicio + 1), es.nombre;
    END IF;
    SELECT coalesce(sum(p2.duracion_spot), 0) INTO usados
      FROM spot s JOIN pauta p2 ON p2.id_pauta = s.id_pauta JOIN bloque b2 ON b2.id_bloque = s.id_bloque
     WHERE b2.id_parrilla = b.id_parrilla AND s.estado <> 'NO_EMITIDO' AND (s.id_bloque, s.hora) IS DISTINCT FROM (OLD.id_bloque, OLD.hora)
       AND s.hora >= es.hora_inicio AND s.hora < es.hora_fin;
    IF usados + pa.duracion_spot > es.segundos_por_dia THEN
        RAISE EXCEPTION 'El espacio % ya no tiene cupo el %: usa % de % segundos.', es.nombre, f, usados, es.segundos_por_dia;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_validar_spot BEFORE INSERT OR UPDATE OF id_pauta, id_bloque, hora ON spot FOR EACH ROW EXECUTE FUNCTION fn_validar_spot();

-- pauta se paga en pago: cada pago es una cuota del plan (un solo pago = 1 cuota)
CREATE TABLE pago (
    id_pago            SERIAL         PRIMARY KEY,
    id_pauta           INTEGER        NOT NULL REFERENCES pauta (id_pauta) ON DELETE CASCADE,
    numero_cuota       INTEGER        NOT NULL CHECK (numero_cuota > 0),
    monto              NUMERIC(12, 2) NOT NULL CHECK (monto > 0),
    fecha_vencimiento  DATE           NOT NULL,
    fecha_pago         TIMESTAMP,
    estado             VARCHAR(10)    NOT NULL DEFAULT 'PENDIENTE' CHECK (estado IN ('PENDIENTE', 'VENCIDO', 'PAGADO')),
    metodo_pago        VARCHAR(10)    CHECK (metodo_pago IN ('TARJETA', 'QR')),
    referencia_stripe  VARCHAR(255),                       -- sesion de Stripe abierta (cs_...) o pago confirmado (pi_...)
    UNIQUE (id_pauta, numero_cuota),
    CONSTRAINT ck_pago_pagado CHECK ((estado = 'PAGADO') = (fecha_pago IS NOT NULL AND metodo_pago IS NOT NULL))
);
CREATE INDEX idx_pago_pauta ON pago (id_pauta);

-- pago genera factura: cada cuota pagada tiene su factura con el NIT y la razon social que dio el anunciante al pagar.
-- migrate:fresh borra las tablas pero no las secuencias sueltas: se recrea para que la numeracion vuelva a empezar
DROP SEQUENCE IF EXISTS seq_numero_factura;
CREATE SEQUENCE seq_numero_factura;
CREATE TABLE factura (
    id_factura      SERIAL         PRIMARY KEY,
    id_pago         INTEGER        NOT NULL UNIQUE REFERENCES pago (id_pago) ON DELETE CASCADE,
    numero_factura  INTEGER        NOT NULL UNIQUE DEFAULT nextval('seq_numero_factura'),
    fecha_emision   TIMESTAMP      NOT NULL DEFAULT now(),
    nit             VARCHAR(20)    NOT NULL CHECK (nit ~ '^[0-9]{4,20}$'),
    razon_social    VARCHAR(150)   NOT NULL CHECK (btrim(razon_social) <> ''),
    total           NUMERIC(12, 2) NOT NULL CHECK (total > 0)
);

-- ===================================================================================== REGLAS QUE CRUZAN TABLAS

-- Publicar una parrilla: con bloques, cada bloque PROGRAMA con su material o su escaleta, TODOS validados LISTO (CU5),
-- y en cada espacio publicitario las pausas comerciales alcanzan para lo vendido ese dia (segundos, y una pausa por
-- cada salida diaria de una pauta, porque una pauta sale una sola vez por pausa).
CREATE OR REPLACE FUNCTION fn_validar_publicacion() RETURNS trigger AS $$
DECLARE
    malo RECORD;
BEGIN
    IF NEW.estado = 'PUBLICADA' AND OLD.estado IS DISTINCT FROM 'PUBLICADA' THEN
        IF NOT EXISTS (SELECT 1 FROM bloque WHERE id_parrilla = NEW.id_parrilla) THEN
            RAISE EXCEPTION 'No se puede publicar una parrilla sin bloques.';
        END IF;
        SELECT b.hora_inicio INTO malo FROM bloque b
         WHERE b.id_parrilla = NEW.id_parrilla AND b.tipo = 'PROGRAMA' AND b.id_material IS NULL
           AND NOT EXISTS (SELECT 1 FROM escaleta e WHERE e.id_bloque = b.id_bloque)
         ORDER BY b.hora_inicio LIMIT 1;
        IF FOUND THEN
            RAISE EXCEPTION 'El bloque de las % no tiene material asignado.', malo.hora_inicio;
        END IF;
        SELECT b.hora_inicio, b.estado_validacion INTO malo FROM bloque b
         WHERE b.id_parrilla = NEW.id_parrilla AND b.estado_validacion <> 'LISTO'
         ORDER BY b.hora_inicio LIMIT 1;
        IF FOUND THEN
            RAISE EXCEPTION 'El bloque de las % esta % en la validacion de emision: debe estar LISTO para publicar.',
                malo.hora_inicio, malo.estado_validacion;
        END IF;
        SELECT e.nombre, to_char(e.hora_inicio, 'HH24:MI') AS hora_inicio, to_char(e.hora_fin, 'HH24:MI') AS hora_fin,
               v.segundos, v.salidas, coalesce(pz.pausas, 0) AS pausas,
               coalesce(pz.segundos, 0) AS libres INTO malo
          FROM espacio e
          JOIN (SELECT pe.id_espacio, sum(pe.salidas_por_dia * pa.duracion_spot) AS segundos, max(pe.salidas_por_dia) AS salidas
                  FROM pauta_espacio pe JOIN pauta pa ON pa.id_pauta = pe.id_pauta
                 WHERE pa.estado = 'APROBADA' AND NEW.fecha BETWEEN pa.fecha_inicio AND pa.fecha_fin
                 GROUP BY pe.id_espacio) v ON v.id_espacio = e.id_espacio
          LEFT JOIN LATERAL (SELECT count(*) AS pausas, CAST(sum(extract(epoch FROM (b.hora_fin - b.hora_inicio))) AS INTEGER) AS segundos
                               FROM bloque b
                              WHERE b.id_parrilla = NEW.id_parrilla AND b.tipo = 'PAUSA_COMERCIAL' AND b.estado <> 'CANCELADO'
                                AND b.hora_inicio >= e.hora_inicio AND b.hora_fin <= e.hora_fin) pz ON true
         WHERE coalesce(pz.segundos, 0) < v.segundos OR coalesce(pz.pausas, 0) < v.salidas
         ORDER BY e.hora_inicio LIMIT 1;
        IF FOUND THEN
            RAISE EXCEPTION 'El espacio % (% a %) tiene % s vendidos y hasta % salidas por pauta, pero la parrilla solo tiene % pausa(s) comercial(es) con % s en ese horario.',
                malo.nombre, malo.hora_inicio, malo.hora_fin, malo.segundos, malo.salidas, malo.pausas, malo.libres;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_validar_publicacion BEFORE UPDATE OF estado ON parrilla FOR EACH ROW EXECUTE FUNCTION fn_validar_publicacion();

-- Si cambia lo que va a salir al aire en un bloque, su validacion vuelve a PENDIENTE (hay que revisarlo de nuevo).
-- No se toca un bloque que ya tiene su conciliacion (su validacion es historia).
CREATE OR REPLACE FUNCTION fn_revalidar_bloque() RETURNS trigger AS $$
BEGIN
    IF (NEW.id_material IS DISTINCT FROM OLD.id_material OR NEW.id_programa IS DISTINCT FROM OLD.id_programa
        OR NEW.hora_inicio <> OLD.hora_inicio OR NEW.hora_fin <> OLD.hora_fin)
       AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = NEW.id_bloque) THEN
        NEW.estado_validacion := 'PENDIENTE';
        NEW.id_usuario := NULL;
        NEW.observacion := NULL;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_revalidar_bloque BEFORE UPDATE ON bloque FOR EACH ROW EXECUTE FUNCTION fn_revalidar_bloque();

CREATE OR REPLACE FUNCTION fn_revalidar_contenido() RETURNS trigger AS $$
DECLARE
    nuevo  INTEGER;
    viejo  INTEGER;
BEGIN
    IF TG_TABLE_NAME = 'material' THEN
        UPDATE bloque b SET estado_validacion = 'PENDIENTE', id_usuario = NULL, observacion = NULL
         WHERE b.estado_validacion <> 'PENDIENTE'
           AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = b.id_bloque)
           AND (b.id_material = NEW.id_material
                OR b.id_bloque IN (SELECT id_bloque FROM escaleta WHERE id_material = NEW.id_material)
                OR b.id_bloque IN (SELECT s.id_bloque FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta
                                    WHERE pa.id_material = NEW.id_material AND s.estado = 'PROGRAMADO'));
        RETURN NEW;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        nuevo := NEW.id_bloque;
    END IF;
    IF TG_OP <> 'INSERT' THEN
        viejo := OLD.id_bloque;
    END IF;
    UPDATE bloque b SET estado_validacion = 'PENDIENTE', id_usuario = NULL, observacion = NULL
     WHERE b.id_bloque IN (nuevo, viejo)
       AND b.estado_validacion <> 'PENDIENTE'
       AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = b.id_bloque);
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_revalidar_por_escaleta AFTER INSERT OR DELETE OR UPDATE OF orden, id_material ON escaleta
    FOR EACH ROW EXECUTE FUNCTION fn_revalidar_contenido();
CREATE TRIGGER trg_revalidar_por_spot AFTER INSERT OR DELETE OR UPDATE OF id_bloque, hora ON spot
    FOR EACH ROW EXECUTE FUNCTION fn_revalidar_contenido();
CREATE TRIGGER trg_revalidar_por_material AFTER UPDATE OF duracion_seg, ruta ON material
    FOR EACH ROW EXECUTE FUNCTION fn_revalidar_contenido();

-- ===================================================================================== DATOS FIJOS

INSERT INTO rol (nombre) VALUES ('ADMINISTRADOR'), ('ENCARGADO'), ('PROGRAMADOR'), ('ANUNCIANTE');
