<?php
session_start();
require_once( 'config.php' );
require_once( 'bd.php' );
require_once( '../shared/funciones.php' );
require_once( 'sesion.php' );

$conn =   conectar();
//$conn = conectar();
//$db_dms = $_SESSION[ "Ses_db_dms" ];
$db_dms = 'dms_farmaofertas';
/* ==========================================================================
 * Toma física / ajuste de inventario  (versión sqlsrv)
 *  - Base de datos DMS, bodega y usuario salen de la sesión
 *  - Todas las consultas van parametrizadas
 *  - Se conservan los nombres de las operaciones y la forma de las respuestas
 *    para que el JS existente siga funcionando
 * ========================================================================== */

const INV_NIT_AJUSTE = '901157558';

/* Lote que recibe el ajuste cuando el producto tiene varios lotes (se puede cambiar desde la pantalla):
 *   'cercano' = el más próximo a vencer   'lejano' = el más lejano a vencer
 * En las salidas, si el primer lote no alcanza, se sigue con el siguiente en el mismo orden. */
const INV_ORDEN_ENTRADA = 'cercano';
const INV_ORDEN_SALIDA = 'lejano';

/* Si un lote participante tiene stock negativo se lleva a 0 con una entrada
 * y esa cantidad se descuenta de los demás lotes (el total ajustado sigue siendo el mismo). */
const INV_NORMALIZAR_NEGATIVOS = true;

/* true = modo prueba: solo graba t_pedidos / t_pedidos_detalle (entradas Y salidas)
 * sin volcar al DMS ni cerrar la toma. En producción debe ir en false. */
const INV_SOLO_TEMPORALES = false;

/* ------------------------------ helpers ---------------------------------- */

function inv_json( $data ) {
  echo json_encode( $data );
  exit;
}

function inv_utf8( $s ) {
  return mb_check_encoding( $s, 'UTF-8' ) ? $s : mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
}

function inv_error_sql(): string {
  $errs = sqlsrv_errors();
  if ( !$errs ) return 'Error desconocido de base de datos';
  return implode( ' | ', array_map( fn( $e ) => inv_utf8( $e[ 'message' ] ), $errs ) );
}

/** Normaliza una fila: strings a UTF-8 y fechas (DateTime) a texto. */
function inv_fila( array $row ): array {
  foreach ( $row as $k => $v ) {
    if ( $v instanceof DateTimeInterface ) $row[ $k ] = $v->format( 'Y-m-d H:i:s' );
    elseif ( is_string( $v ) ) $row[ $k ] = inv_utf8( $v );
  }
  return $row;
}

/**
 * Ejecuta una consulta parametrizada y devuelve las filas.
 * Soporta lotes con varias sentencias (SET NOCOUNT, tablas temporales):
 * devuelve el ÚLTIMO conjunto de resultados con columnas.
 */
function inv_rows( $conn, string $sql, array $params = [] ): array {
  $sets = inv_sets( $conn, $sql, $params );
  return $sets ? end( $sets ) : [];
}

/** Igual que inv_rows pero devuelve TODOS los conjuntos de resultados (para lotes con varios select). */
function inv_sets( $conn, string $sql, array $params = [] ): array {
  $st = sqlsrv_query( $conn, $sql, $params );
  if ( $st === false ) throw new Exception( inv_error_sql() );
  $sets = [];
  do {
    if ( sqlsrv_num_fields( $st ) > 0 ) {
      $rows = [];
      while ( $row = sqlsrv_fetch_array( $st, SQLSRV_FETCH_ASSOC ) ) $rows[] = inv_fila( $row );
      $sets[] = $rows;
    }
    $next = sqlsrv_next_result( $st );
    if ( $next === false ) throw new Exception( inv_error_sql() );
  } while ( $next === true );
  sqlsrv_free_stmt( $st );
  return $sets;
}

function inv_exec( $conn, string $sql, array $params = [] ): int {
  $st = sqlsrv_query( $conn, $sql, $params );
  if ( $st === false ) throw new Exception( inv_error_sql() );
  $n = sqlsrv_rows_affected( $st );
  sqlsrv_free_stmt( $st );
  return $n === false ? 0 : ( int )$n;
}

/** INSERT parametrizado que devuelve el id (SCOPE_IDENTITY, funciona aunque la tabla tenga triggers). */
function inv_insert( $conn, string $tabla, array $datos ): int {
  $cols = implode( ',', array_keys( $datos ) );
  $ph = implode( ',', array_fill( 0, count( $datos ), '?' ) );
  $r = inv_rows( $conn, "set nocount on; insert into $tabla ($cols) values ($ph); select cast(scope_identity() as int) as id;",
    array_values( $datos ) );
  return ( int )( $r[ 0 ][ 'id' ] ?? 0 );
}

function inv_rollback( $conn ) {
  $r = inv_rows( $conn, "select @@trancount as n" );
  if ( ( int )$r[ 0 ][ 'n' ] > 0 ) sqlsrv_rollback( $conn );
}

/** Columna de toma según el número de conteo (1, 2 o 3). */
function inv_col_toma( $conteo ): string {
  $c = ( int )$conteo;
  if ( $c < 1 || $c > 3 ) throw new Exception( 'Número de conteo inválido (1, 2 o 3)' );
  return "toma_$c";
}

/** Bodega enviada por POST o, si no viene, la bodega por defecto de la sesión. */
function inv_bodega(): int {
  if ( isset( $_POST[ 'bodega' ] ) && is_numeric( $_POST[ 'bodega' ] ) && ( int )$_POST[ 'bodega' ] > 0 ) return ( int )$_POST[ 'bodega' ];
  return ( int )$_SESSION[ 'Ses_bodega_default' ];
}

/** Fecha dd-mm-yyyy (o yyyy-mm-dd) a 'Ymd', formato que SQL Server interpreta igual con cualquier idioma. */
function inv_fecha_sql( string $f ): ?string {
  $f = trim( $f );
  if ( $f === '' ) return null;
  $d = DateTime::createFromFormat( '!d-m-Y', $f ) ?: DateTime::createFromFormat( '!Y-m-d', $f );
  if ( !$d ) throw new Exception( "Fecha inválida: $f" );
  return $d->format( 'Ymd' );
}

/**
 * Condición SQL que deja por fuera las presentaciones fraccionadas (códigos terminados en MD, MC y MC*S):
 * no entran al informe previo, al ajuste ni al avance.
 */
function inv_sql_no_fraccion( string $col ): string {
  return "(rtrim($col) not like '%MD' and rtrim($col) not like '%MC' and rtrim($col) not like '%MC*S')";
}

/** Ejecuta SP_VOLCAR_DOCUMENTO y devuelve [numero_doc, tipo_doc, error]. */
function inv_volcar( $conn, int $numero, int $sw, string $tipo ): array {
  $tipoDoc = '';
  $numeroDoc = 0;
  $error = '';
  // Los parámetros OUTPUT con sqlsrv solo funcionan con la sintaxis ODBC {call ...}.
  // Con "exec ... ? OUTPUT" el driver arma mal la sentencia: "Sintaxis incorrecta cerca de 'OUTPUT'".
  // Tipos iguales a los del SP: @TIPO_DOC VARCHAR(4), @NUMERO_DOC INT, @ERROR_MESSAGE NVARCHAR(500)
  $params = [
    [ $numero, SQLSRV_PARAM_IN ],
    [ $sw, SQLSRV_PARAM_IN ],
    [ $tipo, SQLSRV_PARAM_IN ],
    [ & $tipoDoc, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_STRING( SQLSRV_ENC_CHAR ), SQLSRV_SQLTYPE_VARCHAR( 4 ) ],
    [ & $numeroDoc, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_INT, SQLSRV_SQLTYPE_INT ],
    [ & $error, SQLSRV_PARAM_OUT, SQLSRV_PHPTYPE_STRING( 'UTF-8' ), SQLSRV_SQLTYPE_NVARCHAR( 500 ) ],
  ];
  $st = sqlsrv_query( $conn, "{call SP_VOLCAR_DOCUMENTO(?, ?, ?, ?, ?, ?)}", $params );
  if ( $st === false ) return [ 0, '', inv_error_sql() ];
  // los parámetros OUTPUT solo se llenan después de consumir todos los resultados
  while ( ( $n = sqlsrv_next_result( $st ) ) === true ) {}
  $errSql = $n === false ? inv_error_sql() : '';
  sqlsrv_free_stmt( $st );
  $error = inv_utf8( trim( ( string )$error ) );
  // si el SP hizo ROLLBACK, SQL Server además avisa "Transaction count after EXECUTE..."; se prefiere el mensaje del SP
  if ( $error !== '' || $errSql !== '' ) return [ 0, '', $error !== '' ? $error : $errSql ];
  return [ ( int )$numeroDoc, ( string )$tipoDoc, '' ];
}

/* ----------------------- ajuste repartido por lotes ---------------------- */

function inv_orden( $valor, string $defecto ): string {
  return in_array( $valor, [ 'cercano', 'lejano' ], true ) ? $valor : $defecto;
}

/** Ordena los lotes por vencimiento (sin fecha = al final); 'lejano' invierte el orden. Empate: id_lote. */
function inv_ordenar_lotes( array $lotes, string $orden ): array {
  usort( $lotes, function ( $a, $b ) {
    $va = $a[ 'lote_vcmto' ] ?: '9999-12-31';
    $vb = $b[ 'lote_vcmto' ] ?: '9999-12-31';
    return [ $va, ( int )$a[ 'id_lote' ] ] <=> [ $vb, ( int )$b[ 'id_lote' ] ];
  } );
  return $orden === 'lejano' ? array_reverse( $lotes ) : $lotes;
}

/**
 * Reparte la diferencia (contado - stock) entre los lotes del producto.
 *  - Entrada: todo va al primer lote según $ordEntrada.
 *  - Salida: se descuenta del primer lote con stock según $ordSalida; si no alcanza, sigue con el siguiente.
 *    Así, lo no contado (contado 0) sale de todos los lotes con stock.
 * Devuelve los lotes con la llave 'ajuste' (+ entra, - sale).
 */
function inv_repartir( array $lotes, float $contado, string $ordEntrada, string $ordSalida ): array {
  $porId = [];
  foreach ( $lotes as $l ) {
    $l[ 'stock' ] = ( float )$l[ 'stock' ];
    $l[ 'ajuste' ] = 0.0;
    $porId[ ( int )$l[ 'id_lote' ] ] = $l;
  }
  if ( !$porId ) return [];

  if ( INV_NORMALIZAR_NEGATIVOS ) {
    foreach ( $porId as $id => $l ) {
      if ( $l[ 'stock' ] < 0 ) $porId[ $id ][ 'ajuste' ] = -$l[ 'stock' ];
    }
  }
  // stock de cada lote después de normalizar negativos
  $disp = fn( $l ) => $l[ 'stock' ] + $l[ 'ajuste' ];
  $total = array_sum( array_map( $disp, $porId ) );
  $dif = round( $contado - $total, 4 );

  if ( $dif > 0 ) {
    // la entrada va a un lote que tenga stock (para no revivir lotes en 0);
    // solo si ninguno tiene stock se usa cualquiera de los lotes de la toma
    $conStock = array_filter( $porId, fn( $l ) => $l[ 'stock' ] > 0 );
    $destino = inv_ordenar_lotes( array_values( $conStock ?: $porId ), $ordEntrada )[ 0 ];
    $porId[ ( int )$destino[ 'id_lote' ] ][ 'ajuste' ] += $dif;
  } elseif ( $dif < 0 ) {
    $resto = -$dif;
    foreach ( inv_ordenar_lotes( array_values( $porId ), $ordSalida ) as $l ) {
      $id = ( int )$l[ 'id_lote' ];
      $hay = $disp( $porId[ $id ] );
      if ( $hay <= 0 ) continue;
      $q = min( $hay, $resto );
      $porId[ $id ][ 'ajuste' ] -= $q;
      $resto = round( $resto - $q, 4 );
      if ( $resto <= 0 ) break;
    }
  }
  foreach ( $porId as $id => $l ) $porId[ $id ][ 'ajuste' ] = round( $l[ 'ajuste' ], 4 );
  return array_values( inv_ordenar_lotes( array_values( $porId ), 'cercano' ) );
}

/**
 * Calcula el ajuste de toda la toma física de la bodega.
 *  - Productos: referencias_fis (fisico = 1); el conteo es por total (toma_3 > toma_2 > toma_1).
 *  - Lotes que participan: referencias_fis_lote (codigo, bodega, id_lote).
 *  - Stock de cada lote: v_lotes_stock (solo por bodega, codigo e id_lote).
 *  - No entran los códigos terminados en MD, MC y MC*S.
 */
function inv_calcular_ajuste( $conn, string $db, int $bodega, string $ordEntrada, string $ordSalida ): array {
  $noFraccion = inv_sql_no_fraccion( 'f.codigo' );
  $productos = inv_rows( $conn, "select
        f.codigo,
        r.descripcion,
        f.bodega,
        r.porcentaje_iva,
        isnull(f.toma_1,0) as toma_1,
        isnull(f.toma_2,0) as toma_2,
        isnull(f.toma_3,0) as toma_3,
        case
          when f.toma_3 > 0 then f.toma_3
          when f.toma_2 > 0 then f.toma_2
          when f.toma_1 > 0 then f.toma_1
          else 0
        end as contado,
        cast(round((
          case when isnull(p.promedio,0) = 0 then isnull((
                 select top 1 ll.costo_unitario
                 from $db.dbo.documentos_lin ll
                 where ll.codigo = f.codigo and ll.sw in (11,1,4,16) and ll.tipo <> 'DISP'
                 order by ll.fec desc
               ), r.costo_unitario)
          else p.promedio end
        ),0) as decimal(19,2)) as costo,
        isnull(cb.alterno,'') as cod_barras,
        round(isnull(lp.precio_venta,0),0) as precio
      from $db.dbo.referencias_fis f
      inner join $db.dbo.referencias r on r.codigo = f.codigo
      outer apply (select top 1 v.promedio from $db.dbo.v_promedio v
                   where v.codigo = f.codigo and v.mes = month(getdate()) and v.ano = year(getdate())) p
      outer apply (select top 1 a.alterno from $db.dbo.referencias_alt a where a.codigo = f.codigo) cb
      outer apply (select top 1 l.precio_venta from $db.dbo.v_lista_precios_iva l
                   where l.codigo = f.codigo and l.bodega = f.bodega and l.lista = 1) lp
      where f.bodega = ? and isnull(f.fisico,0) = 1 and $noFraccion
      order by r.descripcion", [ $bodega ] );

  $lotes = inv_rows( $conn, "select
        fl.codigo,
        fl.id_lote,
        ml.lote,
        ml.lote_vcmto,
        isnull(s.stock,0) as stock
      from (select distinct codigo, bodega, id_lote from $db.dbo.referencias_fis_lote where bodega = ?) fl
      inner join $db.dbo.referencias_fis f on f.codigo = fl.codigo and f.bodega = fl.bodega and isnull(f.fisico,0) = 1
      left join $db.dbo.maestro_lotes ml on ml.id_lote = fl.id_lote and ml.codigo = fl.codigo
      outer apply (select sum(vs.stock) as stock from $db.dbo.v_lotes_stock vs
                   where vs.codigo = fl.codigo and vs.bodega = fl.bodega and vs.id_lote = fl.id_lote) s
      where $noFraccion", [ $bodega ] );

  $lotesPorCodigo = [];
  foreach ( $lotes as $l ) $lotesPorCodigo[ trim( $l[ 'codigo' ] ) ][] = $l;

  $filas = [];
  foreach ( $productos as $p ) {
    $conteo = ( float )$p[ 'contado' ];
    $lp = $lotesPorCodigo[ trim( $p[ 'codigo' ] ) ] ?? [];
    $stock = round( array_sum( array_map( fn( $l ) => ( float )$l[ 'stock' ], $lp ) ), 4 );
    $detalle = inv_repartir( $lp, $conteo, $ordEntrada, $ordSalida );

    $entra = $sale = 0.0;
    foreach ( $detalle as $d ) {
      if ( $d[ 'ajuste' ] > 0 ) $entra += $d[ 'ajuste' ];
      else $sale -= $d[ 'ajuste' ];
    }

    $proceso = $conteo > $stock ? 'entrada' : ( $conteo < $stock ? 'salida' : '--' );
    if ( count( $lp ) === 0 && $conteo > 0 ) $proceso = 'sin lote'; // contado pero sin lote en referencias_fis_lote

    unset( $p[ 'contado' ] );
    $filas[] = $p + [
      'conteo' => $conteo,
      'stock' => $stock,
      'proceso' => $proceso,
      'tipo' => $proceso,
      'cant_ajuste' => abs( $conteo - $stock ),
      'entra' => round( $entra, 4 ),
      'sale' => round( $sale, 4 ),
      'lotes' => $detalle,
    ];
  }
  return $filas;
}

/* --------------------------- validar sesión ------------------------------ */

if ( !validarSessionEmpresa() ) inv_json( [ 'ok' => false, 'message' => 'No se encontro la empresa', 'mensaje' => 'No se encontro la empresa' ] );
if ( !validarSessionBodega() ) inv_json( [ 'ok' => false, 'message' => 'No se encontro la bodega de trabajo', 'mensaje' => 'No se encontro la bodega de trabajo' ] );
if ( !validarSessionDb() ) inv_json( [ 'ok' => false, 'message' => 'No se encontro la base de datos', 'mensaje' => 'No se encontro la base de datos' ] );


if ( !preg_match( '/^\w+$/', $db_dms ) ) inv_json( [ 'ok' => false, 'message' => 'Base de datos inválida' ] );
$bodega_trabajo = ( int )$_SESSION[ "Ses_bodega_default" ];
$usuario = mb_substr( ( string )$_SESSION[ "Ses_usuario" ], 0, 10 );

try {
  switch ( $_POST[ "op" ] ?? '' ) {

    case "Buscar_codigo":
      $term = trim( ( string )( $_POST[ "term" ] ?? '' ) );
      $bodega = inv_bodega();
      $col = inv_col_toma( $_POST[ "conteo" ] ?? 1 );
      $buscar_por = $_POST[ "buscar_por" ] ?? '';

      // código de barras
      $alterno = inv_rows( $conn, "select codigo from $db_dms.dbo.referencias_alt where alterno = ?", [ $term ] );
      if ( count( $alterno ) > 1 ) {
        $codigos = array_column( $alterno, 'codigo' );
        $ph = implode( ',', array_fill( 0, count( $codigos ), '?' ) );
        inv_json( [
          'ok' => true,
          'data' => inv_rows( $conn, "select r.codigo, r.descripcion from $db_dms.dbo.referencias r where r.codigo in ($ph)", $codigos ),
          'message' => 'Producto con mas de un coincidencia'
        ] );
      }
      if ( count( $alterno ) === 1 ) $term = $alterno[ 0 ][ "codigo" ];

      $params = [ $bodega, $bodega, $bodega, $bodega ];
      if ( $buscar_por == 'codigo' ) {
        $criterio = "r.codigo = ?";
        $params[] = $term;
      } else {
        $criterio = "(r.descripcion like ? or r.codigo = ? )";
        $params[] = '%' . str_replace( [ '[', '%', '_' ], [ '[[]', '[%]', '[_]' ], $term ) . '%';
        $params[] = $term;
      }

      $sql = "select
                r.codigo,
                r.descripcion,
                r.und_1, r.und_2, r.und_3,
                r.can_1, r.can_2, r.can_3,
                isnull(t.stock, 0) as stock,
                isnull(f.$col, 0) as conteo,
                laboratorio = isnull(l.nombres,''),
                alerta = isnull((SELECT
                                  COUNT(*)
                                  FROM $db_dms.dbo.referencias r1
                                  INNER JOIN $db_dms.dbo.v_ref_total s on s.codigo = r1.codigo
                                  AND s.mes = month(getdate()) and s.ano = year(getdate())
                                  AND s.bodega = ?
                                  WHERE s.Stock > 0
                                  AND r1.codigo LIKE '%*[A-Z]'
                                  AND R1.codigo like r.codigo
                                 --  AND r1.codigo<>r.codigo
                ),0),
                n_lotes = (select count(distinct fl.id_lote) from $db_dms.dbo.referencias_fis_lote fl
                           where fl.codigo = r.codigo and fl.bodega = ?)
              from $db_dms.dbo.referencias r
              left join $db_dms.dbo.terceros l on l.nit = r.nit
              left join $db_dms.dbo.v_ref_total t on t.codigo = r.codigo
                    and t.mes = month(getdate())
                    and t.ano = year(getdate())
                    and t.bodega = ?
              inner join $db_dms.dbo.referencias_fis f on f.codigo = r.codigo
                    and isnull(f.fisico, 0) = 1
                    and f.bodega = ?
              where $criterio AND r.codigo  not in(
                  SELECT distinct
                  r.CODIGO
                  FROM $db_dms.dbo.referencias r
                  left join $db_dms.dbo.v_ref_total t on t.codigo = r.codigo
                  and t.mes = month(getdate())
                  and t.ano=year(getdate())
                  where (r.codigo  like '%MD' or r.codigo  like '%MC' or r.CODIGO  LIKE '%MC*S')
                  and t.stock=0

              )";

      $resultado = inv_rows( $conn, $sql, $params );
      if ( count( $resultado ) == 0 ) {
        inv_json( [ 'ok' => false, 'data' => [], 'message' => 'El producto no existe o no esta en toma fisica' ] );
      }
      inv_json( [ 'ok' => true, 'data' => $resultado, 'message' => 'success' ] );

    case "Listar_lotes":
      $codigo = trim( ( string )( $_POST[ "codigo" ] ?? '' ) );
      $sql = "select ml.codigo, ml.lote, ml.id_lote
              from $db_dms.dbo.v_lotes_stock ml
              where ml.codigo = ?
              union
              select l.codigo, l.lote, l.id_lote
              from $db_dms.dbo.maestro_lotes l
              where l.codigo = ?
                and month(l.fecha_creacion) = month(getdate())
                and year(l.fecha_creacion) = year(getdate())";
      inv_json( inv_rows( $conn, $sql, [ $codigo, $codigo ] ) );

    case "Buscar_ean":
      $codigo = trim( ( string )( $_POST[ "codigo" ] ?? '' ) );
      if ( $codigo === '' ) inv_json( [ 'ok' => false, 'message' => 'Código no proporcionado.' ] );
      $sql = "select r.codigo, ra.alterno, ra.cantidad_alt, r.descripcion
              from $db_dms.dbo.referencias_alt ra
              inner join $db_dms.dbo.referencias r on r.codigo = ra.codigo
              where ra.alterno = ?";
      inv_json( inv_rows( $conn, $sql, [ $codigo ] ) );

    case "Buscar_info_lotes":
      $codigo = trim( ( string )( $_POST[ "codigo" ] ?? '' ) );
      $bodega = inv_bodega();
      $lote = ( int )( $_POST[ "lote" ] ?? 0 ); // id_lote
      $sql = "select
                r.codigo,
                r.descripcion,
                fis.toma_1,
                fis.toma_2,
                fis.toma_3,
                fis.bodega,
                ml.lote,
                ml.lote_vcmto,
                vsto.stock
              from $db_dms.dbo.referencias r
              left join $db_dms.dbo.referencias_fis_lote fis on r.codigo = fis.codigo
              left join $db_dms.dbo.maestro_lotes ml on ml.codigo = r.codigo and ml.id_lote = fis.id_lote
              left join $db_dms.dbo.v_lotes_stock vsto on vsto.codigo = r.codigo
                                                        and vsto.bodega = fis.bodega
                                                        and vsto.id_lote = fis.id_lote
              where fis.bodega = ? and r.codigo = ? and fis.id_lote = ?";
      inv_json( inv_rows( $conn, $sql, [ $bodega, $codigo, $lote ] ) );

    case "Guardar_nuevo_lote":
      $codigo = trim( ( string )( $_POST[ "codigo" ] ?? '' ) );
      $lote = trim( ( string )( $_POST[ "lote" ] ?? '' ) );
      $vcmto = inv_fecha_sql( ( string )( $_POST[ "lote_vcmto" ] ?? '' ) ); // llega dd-mm-yyyy
      if ( $codigo === '' || $lote === '' ) inv_json( [ 'ok' => false, 'message' => 'Faltan el código o el lote.' ] );

      $existe = inv_rows( $conn, "select id_lote from $db_dms.dbo.maestro_lotes where codigo = ? and lote = ?", [ $codigo, $lote ] );
      if ( !empty( $existe ) ) {
        inv_json( [ 'ok' => false, 'message' => 'El lote ya existe, no se puede volver a crear.' ] );
      }
      $id = inv_insert( $conn, "$db_dms.dbo.maestro_lotes", [ 'codigo' => $codigo, 'lote' => $lote, 'lote_vcmto' => $vcmto ] );
      if ( $id > 0 ) {
        inv_json( [ 'ok' => true, 'message' => 'Se insertó correctamente el lote', 'id_lote' => $id ] );
      }
      inv_json( [ 'ok' => false, 'message' => 'Error al insertar nuevo lote' ] );

    /* Crea un lote en maestro_lotes para productos de la toma que no tienen lotes y lo asocia
       a la toma (referencias_fis_lote) para que el ajuste pueda hacerse sobre ese lote. */
    case "crear-lotes":
      $bodega = inv_bodega();
      $codigos = json_decode( ( string )( $_POST[ "codigos" ] ?? '[]' ), true );
      $lote = strtoupper( trim( ( string )( $_POST[ "lote" ] ?? '' ) ) );
      $vcmto = inv_fecha_sql( ( string )( $_POST[ "lote_vcmto" ] ?? '' ) ); // yyyy-mm-dd o dd-mm-yyyy
      if ( !is_array( $codigos ) || count( $codigos ) === 0 ) inv_json( [ 'ok' => false, 'message' => 'No hay productos para crear lote' ] );
      if ( count( $codigos ) > 1000 ) inv_json( [ 'ok' => false, 'message' => 'Máximo 1000 productos por vez' ] );
      if ( $lote === '' ) inv_json( [ 'ok' => false, 'message' => 'Digite el nombre del lote' ] );
      if ( mb_strlen( $lote ) > 30 ) inv_json( [ 'ok' => false, 'message' => 'El lote no puede tener más de 30 caracteres' ] );
      $codigos = array_values( array_unique( array_filter( array_map( fn( $c ) => trim( ( string )$c ), $codigos ), fn( $c ) => $c !== '' ) ) );
      $notasLote = mb_substr( "Creado en toma fisica web bodega $bodega por $usuario", 0, 250 );

      $creados = [];
      $omitidos = [];
      if ( !sqlsrv_begin_transaction( $conn ) ) throw new Exception( inv_error_sql() );
      try {
        foreach ( $codigos as $cod ) {
          $marcado = inv_rows( $conn, "select codigo from $db_dms.dbo.referencias_fis where codigo = ? and bodega = ? and isnull(fisico,0) = 1",
            [ $cod, $bodega ] );
          if ( count( $marcado ) === 0 ) { $omitidos[] = [ 'codigo' => $cod, 'motivo' => 'No está marcado para toma física' ]; continue; }

          $tiene = inv_rows( $conn, "select top 1 id_lote from $db_dms.dbo.referencias_fis_lote where codigo = ? and bodega = ?", [ $cod, $bodega ] );
          if ( count( $tiene ) > 0 ) { $omitidos[] = [ 'codigo' => $cod, 'motivo' => 'Ya tiene lotes en la toma' ]; continue; }

          // codigo + lote es único en maestro_lotes: si ya existe se reutiliza
          $m = inv_rows( $conn, "select id_lote from $db_dms.dbo.maestro_lotes where codigo = ? and lote = ?", [ $cod, $lote ] );
          $idLote = count( $m ) > 0 ? ( int )$m[ 0 ][ 'id_lote' ]
            : inv_insert( $conn, "$db_dms.dbo.maestro_lotes", [ 'codigo' => $cod, 'lote' => $lote, 'lote_vcmto' => $vcmto, 'notas' => $notasLote ] );
          if ( $idLote <= 0 ) throw new Exception( "No se pudo crear el lote de $cod" );

          inv_exec( $conn, "insert into $db_dms.dbo.referencias_fis_lote (codigo, bodega, id_lote) values (?, ?, ?)",
            [ $cod, $bodega, $idLote ] );
          $creados[] = [ 'codigo' => $cod, 'id_lote' => $idLote ];
        }
        if ( !sqlsrv_commit( $conn ) ) throw new Exception( inv_error_sql() );
      } catch ( Throwable $e ) {
        inv_rollback( $conn );
        throw $e;
      }
      inv_json( [ 'ok' => true, 'message' => count( $creados ) . ' lote(s) creado(s)', 'lote' => $lote,
        'creados' => $creados, 'omitidos' => $omitidos ] );

    case "Insertar_conteo":
      $codigo = trim( ( string )( $_POST[ "codigo" ] ?? '' ) );
      $bodega = inv_bodega();
      $cantidad = $_POST[ "cantidad" ] ?? '';
      // 0 es un conteo válido, por eso no se usa empty()
      if ( $codigo === '' || empty( $_POST[ "conteo" ] ) || !is_numeric( $cantidad ) ) {
        inv_json( [ 'ok' => false, 'message' => 'Faltan datos obligatorios.' ] );
      }
      $campo = inv_col_toma( $_POST[ "conteo" ] );
      $usuCampo = "usu_$campo";
      $cantidad = ( float )$cantidad;

      $n = inv_exec( $conn, "update $db_dms.dbo.referencias_fis
                             set $campo = ?, $usuCampo = ?, fecha_ultima = getdate()
                             where bodega = ? and codigo = ?", [ $cantidad, $usuario, $bodega, $codigo ] );
      if ( $n === 0 ) {
        inv_exec( $conn, "insert into $db_dms.dbo.referencias_fis (codigo, bodega, fisico, $campo, $usuCampo, fecha_ultima)
                          values (?, ?, 1, ?, ?, getdate())", [ $codigo, $bodega, $cantidad, $usuario ] );
      }
      inv_json( [ 'ok' => true, 'message' => 'Operación realizada correctamente.' ] );

    case "Informe_inventario":
      $bodega = inv_bodega();
      $sql = "select
                r.codigo,
                r.descripcion,
                fis.bodega,
                ml.lote,
                ml.id_lote,
                convert(varchar, ml.lote_vcmto, 102) as lote_vcmto,
                isnull(fis.toma_1, 0) as toma_1,
                isnull(fis.toma_2, 0) as toma_2,
                isnull(fis.toma_3, 0) as toma_3,
                cast(isnull(vsto.stock, 0) as numeric(19,0)) as stock,
                case
                  when isnull(fis.toma_3, 0) != 0 then isnull(fis.toma_3, 0) - isnull(vsto.stock, 0)
                  when isnull(fis.toma_2, 0) != 0 then isnull(fis.toma_2, 0) - isnull(vsto.stock, 0)
                  when isnull(fis.toma_1, 0) != 0 then isnull(fis.toma_1, 0) - isnull(vsto.stock, 0)
                  else -isnull(vsto.stock, 0)
                end as faltante,
                round(vv.promedio, 0) as costo,
                t.nombres as lab,
                ll.precio_venta,
                codigo_barras = isnull((select top 1 alterno from $db_dms.dbo.referencias_alt a where a.codigo = r.codigo), '')
              from $db_dms.dbo.referencias r
              left join $db_dms.dbo.referencias_fis_lote fis on r.codigo = fis.codigo and fis.bodega = ?
              left join $db_dms.dbo.v_lotes_stock vsto on vsto.codigo = r.codigo
                                                       and vsto.bodega = fis.bodega
                                                       and vsto.id_lote = fis.id_lote
              left join $db_dms.dbo.maestro_lotes ml on ml.codigo = r.codigo and ml.id_lote = fis.id_lote
              left join $db_dms.dbo.terceros t on t.nit = r.nit
              left join $db_dms.dbo.v_promedio vv on vv.codigo = r.codigo and vv.mes = month(getdate()) and vv.ano = year(getdate())
              left join $db_dms.dbo.v_lista_precios_iva ll on ll.codigo = r.codigo and ll.bodega = ? and ll.lista = 1
              where fis.bodega = ?
              order by r.codigo";
      inv_json( inv_rows( $conn, $sql, [ $bodega, $bodega, $bodega ] ) );

    case "generar-info-es":
      $bodega = inv_bodega();
      $mostrar = $_POST[ "mostrar_resultados" ] ?? '';
      $filas = inv_calcular_ajuste( $conn, $db_dms, $bodega,
        inv_orden( $_POST[ "orden_entrada" ] ?? '', INV_ORDEN_ENTRADA ),
        inv_orden( $_POST[ "orden_salida" ] ?? '', INV_ORDEN_SALIDA ) );

      if ( $mostrar == "diferencias" ) {
        $filas = array_filter( $filas, fn( $f ) => $f[ 'entra' ] > 0 || $f[ 'sale' ] > 0 || $f[ 'proceso' ] === 'sin lote' );
      } elseif ( $mostrar == "con-conteos" ) {
        $filas = array_filter( $filas, fn( $f ) => $f[ 'conteo' ] != 0 );
      }
      inv_json( array_values( $filas ) );

    case "hacer-ajuste":
      $bodega = inv_bodega();
      $tipo_doc_entrada = trim( ( string )( $_POST[ "tipo_doc_entrada" ] ?? '' ) );
      $tipo_doc_salida = trim( ( string )( $_POST[ "tipo_doc_salida" ] ?? '' ) );

      // El ajuste se recalcula aquí con los conteos actuales (no se confía en lo que manda el navegador)
      $filas = inv_calcular_ajuste( $conn, $db_dms, $bodega,
        inv_orden( $_POST[ "orden_entrada" ] ?? '', INV_ORDEN_ENTRADA ),
        inv_orden( $_POST[ "orden_salida" ] ?? '', INV_ORDEN_SALIDA ) );

      $sinLote = array_column( array_filter( $filas, fn( $f ) => $f[ 'proceso' ] === 'sin lote' ), 'codigo' );
      if ( $sinLote ) {
        inv_json( [ 'ok' => false, 'sin_lote' => array_map( 'trim', $sinLote ),
          'message' => 'Estos productos tienen conteo pero no tienen lotes en la toma, créeles un lote primero: '
          . implode( ', ', array_map( 'trim', $sinLote ) ) ] );
      }

      // una línea por lote: entradas (sw 12) y salidas (sw 11)
      $entradas = $salidas = [];
      foreach ( $filas as $f ) {
        foreach ( $f[ 'lotes' ] as $l ) {
          if ( $l[ 'ajuste' ] == 0 ) continue;
          $linea = [
            'codigo' => $f[ 'codigo' ],
            'cantidad' => abs( $l[ 'ajuste' ] ),
            'id_lote' => ( int )$l[ 'id_lote' ],
            'costo' => ( float )$f[ 'costo' ],
            'porcentaje_iva' => ( float )$f[ 'porcentaje_iva' ],
          ];
          if ( $l[ 'ajuste' ] > 0 ) $entradas[] = $linea;
          else $salidas[] = $linea;
        }
      }

      if ( count( $entradas ) === 0 && count( $salidas ) === 0 ) {
        inv_json( [ 'ok' => false, 'message' => 'No hay diferencias para ajustar' ] );
      }
      if ( count( $entradas ) > 0 && $tipo_doc_entrada === '' ) inv_json( [ 'ok' => false, 'message' => 'Seleccione el tipo de documento de entrada' ] );
      if ( count( $salidas ) > 0 && $tipo_doc_salida === '' ) inv_json( [ 'ok' => false, 'message' => 'Seleccione el tipo de documento de salida' ] );

      $notas = 'Ajuste de inventarios bodega ' . $bodega . ', hecho por la web ejecutado por el usuario ' . $usuario;
      $fecha = date( 'Ymd' );
      $docs = [
        [ 'sw' => 12, 'tipo' => $tipo_doc_entrada, 'lineas' => $entradas, 'numero' => 0, 'numero_doc' => 0 ],
        [ 'sw' => 11, 'tipo' => $tipo_doc_salida, 'lineas' => $salidas, 'numero' => 0, 'numero_doc' => 0 ],
      ];

      // Todo en una sola transacción: temporales + volcado al DMS + cierre de la toma.
      // Si algo falla se deshace todo (incluido el documento que ya se hubiera volcado).
      if ( !sqlsrv_begin_transaction( $conn ) ) throw new Exception( inv_error_sql() );
      try {
        foreach ( $docs as & $doc ) {
          if ( count( $doc[ 'lineas' ] ) === 0 ) continue;

          // consecutivo del temporal (bloqueado hasta terminar la transacción)
          $r = inv_rows( $conn, "select isnull(max(numero),0) + 1 as siguiente from t_pedidos with (updlock, holdlock) where sw = ?", [ $doc[ 'sw' ] ] );
          $doc[ 'numero' ] = ( int )$r[ 0 ][ 'siguiente' ];

          $total = 0.0;
          foreach ( $doc[ 'lineas' ] as $l ) $total += abs( ( float )$l[ 'cantidad' ] ) * ( float )$l[ 'costo' ];

          $id = inv_insert( $conn, 't_pedidos', [
            'nit' => INV_NIT_AJUSTE,
            'sw' => $doc[ 'sw' ],
            'bodega' => $bodega,
            'vendedor' => '0',
            'condicion' => '0',
            'valor_total' => round( $total, 2 ),
            'notas' => $notas,
            'usuario' => $usuario,
            'documento' => '',
            'lista_precios' => '0',
            'numero' => $doc[ 'numero' ],
            'tipo' => $doc[ 'tipo' ],
            'prefijo' => $doc[ 'tipo' ],
            'iva' => 0,
            'vr_tarifa_saludable' => 0,
            'vr_saludable' => 0
          ] );
          if ( $id <= 0 ) throw new Exception( 'No se pudo guardar el encabezado de la ' . ( $doc[ 'sw' ] == 12 ? 'entrada' : 'salida' ) );

          foreach ( $doc[ 'lineas' ] as $l ) {
            $idDet = inv_insert( $conn, 't_pedidos_detalle', [
              'numero' => $doc[ 'numero' ],
              'sw' => $doc[ 'sw' ],
              'bodega' => $bodega,
              'codigo' => ( string )$l[ 'codigo' ],
              'id_lote' => ( int )$l[ 'id_lote' ],
              'valor_unitario' => ( float )$l[ 'costo' ],
              'porcentaje_iva' => ( float )( $l[ 'porcentaje_iva' ] ?? 0 ),
              'porcentaje_descuento' => 0,
              'cantidad' => abs( ( float )$l[ 'cantidad' ] ),
              'cantidad_und' => 1,
              'und' => 'UND',
              'nit' => INV_NIT_AJUSTE,
              'tipo' => $doc[ 'tipo' ],
              'costo_unitario' => ( float )$l[ 'costo' ],
              'porce_saludable' => 0,
              'tarifa_saludable' => 0,
              'maneja_inventario' => 'S',
              'fec' => $fecha
            ] );
            if ( $idDet <= 0 ) throw new Exception( 'No se pudo guardar el detalle de ' . $l[ 'codigo' ] );
          }

          if ( INV_SOLO_TEMPORALES ) continue; // modo prueba: sigue con el siguiente documento sin volcar

          // volcar al DMS
          [ $numeroDoc, , $error ] = inv_volcar( $conn, $doc[ 'numero' ], $doc[ 'sw' ], $doc[ 'tipo' ] );
          if ( $numeroDoc == 0 || $error !== '' ) throw new Exception( $error ?: 'No se pudo guardar el documento' );
          $doc[ 'numero_doc' ] = $numeroDoc;
        }
        unset( $doc );

        if ( INV_SOLO_TEMPORALES ) {
          if ( !sqlsrv_commit( $conn ) ) throw new Exception( inv_error_sql() );
          inv_json( [ 'ok' => true,
            'entrada' => 'temporal ' . $docs[ 0 ][ 'numero' ],
            'salida' => 'temporal ' . $docs[ 1 ][ 'numero' ] ] );
        }
/*
        // cerrar la toma física de la bodega
        inv_exec( $conn, "update $db_dms.dbo.referencias_fis
                          set toma_1 = 0, toma_2 = 0, toma_3 = 0, fisico = 0
                          where bodega = ?", [ $bodega ] );*/

        if ( !sqlsrv_commit( $conn ) ) throw new Exception( inv_error_sql() );
      } catch ( Throwable $e ) {
        inv_rollback( $conn );
        // por si el SP hizo su propio ROLLBACK y los temporales quedaron fuera de la transacción
        foreach ( $docs as $d ) {
          if ( $d[ 'numero' ] > 0 ) {
            sqlsrv_query( $conn, "delete from t_pedidos_detalle where numero = ? and sw = ?", [ $d[ 'numero' ], $d[ 'sw' ] ] );
            sqlsrv_query( $conn, "delete from t_pedidos where numero = ? and sw = ?", [ $d[ 'numero' ], $d[ 'sw' ] ] );
          }
        }
        inv_json( [ 'ok' => false, 'message' => inv_utf8( $e->getMessage() ), 'error' => inv_utf8( $e->getMessage() ) ] );
      }

      inv_json( [ 'ok' => true, 'entrada' => $docs[ 0 ][ 'numero_doc' ], 'salida' => $docs[ 1 ][ 'numero_doc' ] ] );

    case "avance-inventario":
      // Avance de la toma física (referencias_fis) y lo contado por cada usuario.
      // Nota: referencias_fis guarda solo el ÚLTIMO usuario que grabó cada toma (usu_toma_X).
      $bodega = inv_bodega();
      $col = inv_col_toma( $_POST[ "conteo" ] ?? 1 );
      $noFraccion = inv_sql_no_fraccion( 'f.codigo' );
      [ $resumen, $usuarios, $pendientes ] = inv_sets( $conn, "set nocount on;
          if object_id('tempdb..#av') is not null drop table #av;

          select
            f.codigo,
            r.descripcion,
            isnull(f.toma_1,0) as toma_1,
            isnull(f.toma_2,0) as toma_2,
            isnull(f.toma_3,0) as toma_3,
            f.usu_toma_1, f.usu_toma_2, f.usu_toma_3,
            f.fecha_ultima,
            isnull(s.stock,0) as stock
          into #av
          from $db_dms.dbo.referencias_fis f
          inner join $db_dms.dbo.referencias r on r.codigo = f.codigo
          outer apply (select sum(vs.stock) as stock
                       from $db_dms.dbo.v_lotes_stock vs
                       where vs.codigo = f.codigo and vs.bodega = f.bodega
                         and vs.id_lote in (select fl.id_lote from $db_dms.dbo.referencias_fis_lote fl
                                            where fl.codigo = f.codigo and fl.bodega = f.bodega)) s
          where f.bodega = ? and isnull(f.fisico,0) = 1 and $noFraccion;

          select
            count(*) as referencias,
            isnull(sum(case when stock > 0 then 1 else 0 end),0) as con_stock,
            isnull(sum(case when toma_1 > 0 then 1 else 0 end),0) as contadas_1,
            isnull(sum(case when toma_2 > 0 then 1 else 0 end),0) as contadas_2,
            isnull(sum(case when toma_3 > 0 then 1 else 0 end),0) as contadas_3,
            isnull(sum(case when toma_1 > 0 or toma_2 > 0 or toma_3 > 0 then 1 else 0 end),0) as contadas,
            isnull(sum(case when stock > 0 and (toma_1 > 0 or toma_2 > 0 or toma_3 > 0) then 1 else 0 end),0) as con_stock_contadas,
            isnull(sum(case when stock > 0 then stock else 0 end),0) as unidades_stock,
            isnull(sum(case when toma_3 > 0 then toma_3 when toma_2 > 0 then toma_2 else toma_1 end),0) as unidades_contadas
          from #av;

          select
            x.toma,
            x.usuario,
            count(*) as referencias,
            sum(x.cantidad) as unidades,
            max(x.fecha_ultima) as ultima
          from (
            select 1 as toma, isnull(nullif(ltrim(rtrim(usu_toma_1)),''),'(sin usuario)') as usuario, toma_1 as cantidad, fecha_ultima from #av where toma_1 > 0
            union all
            select 2, isnull(nullif(ltrim(rtrim(usu_toma_2)),''),'(sin usuario)'), toma_2, fecha_ultima from #av where toma_2 > 0
            union all
            select 3, isnull(nullif(ltrim(rtrim(usu_toma_3)),''),'(sin usuario)'), toma_3, fecha_ultima from #av where toma_3 > 0
          ) x
          group by x.toma, x.usuario
          order by x.toma, count(*) desc;

          select codigo, descripcion, stock from #av where $col = 0 order by stock desc, descripcion;

          drop table #av;", [ $bodega ] );
      inv_json( [ 'ok' => true, 'resumen' => $resumen[ 0 ] ?? [], 'usuarios' => $usuarios, 'pendientes' => $pendientes ] );

    case "list-lab":
      inv_json( inv_rows( $conn, "select distinct t.nit, t.nombres
                                  from $db_dms.dbo.referencias r
                                  inner join $db_dms.dbo.terceros t on t.nit = r.nit
                                  order by t.nombres" ) );

    case "genericos":
      inv_json( inv_rows( $conn, "select * from $db_dms.dbo.referencias_gen order by descripcion" ) );

    case "clases":
      inv_json( inv_rows( $conn, "select * from $db_dms.dbo.referencias_cla order by descripcion" ) );

    case "grupos":
      inv_json( inv_rows( $conn, "select * from $db_dms.dbo.referencias_gru order by descripcion" ) );

    case "subgrupos":
      inv_json( inv_rows( $conn, "select * from $db_dms.dbo.referencias_sub where grupo = ? order by descripcion",
        [ ( string )( $_POST[ "grupo" ] ?? '' ) ] ) );

    case "subgrupos2":
      inv_json( inv_rows( $conn, "select * from $db_dms.dbo.referencias_sub where grupo = ? and subgrupo = ? order by descripcion",
        [ ( string )( $_POST[ "grupo" ] ?? '' ), ( string )( $_POST[ "subgrupo" ] ?? '' ) ] ) );

    case "validar-inv":
      inv_json( inv_rows( $conn, "select * from $db_dms.dbo.referencias_fis_lote where bodega = ?", [ inv_bodega() ] ) );

    case "eliminarConteo":
      $codigo = trim( ( string )( $_POST[ "codigo" ] ?? '' ) );
      $bodega = inv_bodega();
      $validar = inv_rows( $conn, "select toma_1, toma_2, toma_3 from $db_dms.dbo.referencias_fis where codigo = ? and bodega = ?",
        [ $codigo, $bodega ] );
      if ( count( $validar ) === 0 ) {
        inv_json( [ 'ok' => false, 'message' => 'No se encontró el registro para eliminar.' ] );
      }
      $v = $validar[ 0 ];
      if ( ( float )$v[ 'toma_1' ] == 0 && ( float )$v[ 'toma_2' ] == 0 && ( float )$v[ 'toma_3' ] == 0 ) {
        inv_json( [ 'ok' => false, 'message' => 'No se puede eliminar el conteo porque no tiene cantidades registradas.' ] );
      }
      inv_exec( $conn, "update $db_dms.dbo.referencias_fis
                        set toma_1 = 0, toma_2 = 0, toma_3 = 0, fisico = 1
                        where codigo = ? and bodega = ?", [ $codigo, $bodega ] );
      inv_json( [ 'ok' => true, 'message' => 'Se elimino correctamente el registro' ] );

    case "busqueda-productos":
      $dato = trim( ( string )( $_POST[ "datos" ] ?? '' ) );
      $alt = inv_rows( $conn, "select codigo from $db_dms.dbo.referencias_alt where alterno = ?", [ $dato ] );
      $sql = "select r.codigo, r.descripcion, isnull(t.nombres, '') as lab
              from $db_dms.dbo.referencias r
              left join $db_dms.dbo.terceros t on t.nit = r.nit ";
      if ( count( $alt ) > 0 ) {
        $productos = inv_rows( $conn, $sql . "where r.codigo = ?", [ $alt[ 0 ][ "codigo" ] ] );
      } else {
        $productos = inv_rows( $conn, $sql . "where r.descripcion like ?",
          [ '%' . str_replace( [ '[', '%', '_' ], [ '[[]', '[%]', '[_]' ], $dato ) . '%' ] );
      }
      inv_json( $productos );

    case "tipos":
      $sw = ( int )( $_POST[ "sw" ] ?? 0 );
      $bodega = ( int )( $_POST[ "bodega" ] ?? 0 );
      $sql = "select * from $db_dms.dbo.tipo_transacciones where sw = ?";
      $params = [ $sw ];
      if ( $bodega > 0 ) {
        $sql .= " and (bodega = ? or bodega = 0)";
        $params[] = $bodega;
      }
      inv_json( inv_rows( $conn, $sql, $params ) );

    default:
      inv_json( [ 'ok' => false, 'message' => 'Operación no válida' ] );
  }
} catch ( Throwable $e ) {
  inv_json( [ 'ok' => false, 'message' => inv_utf8( $e->getMessage() ), 'mensaje' => inv_utf8( $e->getMessage() ) ] );
}
