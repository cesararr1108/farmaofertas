<?php
session_start();
require_once( 'config.php' );
require_once( 'bd.php' );
require_once( '../shared/funciones.php' );
require_once( 'sesion.php' );
$conn = conectar();

/* ==========================================================================
 * Toma física / ajuste de inventario  (versión sqlsrv)
 *  - Base de datos DMS, bodega y usuario salen de la sesión
 *  - Todas las consultas van parametrizadas
 *  - Se conservan los nombres de las operaciones y la forma de las respuestas
 *    para que el JS existente siga funcionando
 * ========================================================================== */

const INV_NIT_AJUSTE = '901157558';

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
  $st = sqlsrv_query( $conn, $sql, $params );
  if ( $st === false ) throw new Exception( inv_error_sql() );
  $rows = [];
  do {
    if ( sqlsrv_num_fields( $st ) > 0 ) {
      $rows = [];
      while ( $row = sqlsrv_fetch_array( $st, SQLSRV_FETCH_ASSOC ) ) $rows[] = inv_fila( $row );
    }
    $next = sqlsrv_next_result( $st );
    if ( $next === false ) throw new Exception( inv_error_sql() );
  } while ( $next === true );
  sqlsrv_free_stmt( $st );
  return $rows;
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

/** Ejecuta SP_VOLCAR_DOCUMENTO y devuelve [numero_doc, tipo_doc, error]. */
function inv_volcar( $conn, int $numero, int $sw, string $tipo ): array {
  $tipoDoc = '';
  $numeroDoc = 0;
  $error = '';
  $params = [
    [ $numero, SQLSRV_PARAM_IN ],
    [ $sw, SQLSRV_PARAM_IN ],
    [ $tipo, SQLSRV_PARAM_IN ],
    [ & $tipoDoc, SQLSRV_PARAM_INOUT, SQLSRV_PHPTYPE_STRING( SQLSRV_ENC_CHAR ), SQLSRV_SQLTYPE_VARCHAR( 20 ) ],
    [ & $numeroDoc, SQLSRV_PARAM_INOUT, SQLSRV_PHPTYPE_INT, SQLSRV_SQLTYPE_INT ],
    [ & $error, SQLSRV_PARAM_INOUT, SQLSRV_PHPTYPE_STRING( SQLSRV_ENC_CHAR ), SQLSRV_SQLTYPE_VARCHAR( 1000 ) ],
  ];
  $st = sqlsrv_query( $conn, "exec SP_VOLCAR_DOCUMENTO
                                @numero = ?, @sw = ?, @tipo = ?,
                                @TIPO_DOC = ? OUTPUT, @NUMERO_DOC = ? OUTPUT, @ERROR_MESSAGE = ? OUTPUT", $params );
  if ( $st === false ) return [ 0, '', inv_error_sql() ];
  // los parámetros OUTPUT solo se llenan después de consumir todos los resultados
  while ( ( $n = sqlsrv_next_result( $st ) ) === true ) {}
  if ( $n === false ) return [ 0, '', inv_error_sql() ];
  sqlsrv_free_stmt( $st );
  return [ ( int )$numeroDoc, ( string )$tipoDoc, inv_utf8( trim( ( string )$error ) ) ];
}

/* --------------------------- validar sesión ------------------------------ */

if ( !validarSessionEmpresa() ) inv_json( [ 'ok' => false, 'message' => 'No se encontro la empresa', 'mensaje' => 'No se encontro la empresa' ] );
if ( !validarSessionBodega() ) inv_json( [ 'ok' => false, 'message' => 'No se encontro la bodega de trabajo', 'mensaje' => 'No se encontro la bodega de trabajo' ] );
if ( !validarSessionDb() ) inv_json( [ 'ok' => false, 'message' => 'No se encontro la base de datos', 'mensaje' => 'No se encontro la base de datos' ] );

$db_dms = $_SESSION[ "Ses_db_dms" ];
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

      $params = [ $bodega, $bodega ];
      if ( $buscar_por == 'codigo' ) {
        $criterio = "r.codigo = ?";
        $params[] = $term;
      } else {
        $criterio = "(r.descripcion like ? or r.codigo = ?)";
        $params[] = '%' . str_replace( [ '[', '%', '_' ], [ '[[]', '[%]', '[_]' ], $term ) . '%';
        $params[] = $term;
      }

      $sql = "select
                r.codigo,
                r.descripcion,
                r.und_1, r.und_2, r.und_3,
                r.can_1, r.can_2, r.can_3,
                isnull(t.stock, 0) as stock,
                isnull(f.$col, 0) as conteo
              from $db_dms.dbo.referencias r
              left join $db_dms.dbo.v_ref_total t on t.codigo = r.codigo
                    and t.mes = month(getdate())
                    and t.ano = year(getdate())
                    and t.bodega = ?
              inner join $db_dms.dbo.referencias_fis f on f.codigo = r.codigo
                    and isnull(f.fisico, 0) = 1
                    and f.bodega = ?
              where $criterio";
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
      $where = '';
      $mostrar = $_POST[ "mostrar_resultados" ] ?? '';
      if ( $mostrar == "diferencias" ) $where = "where isnull(d.contado,0) <> isnull(d.stock,0)";
      elseif ( $mostrar == "con-conteos" ) $where = "where isnull(d.contado,0) <> 0";

      $sql = "set nocount on;
              -- diferencias de entradas y salidas
              select
                r.codigo,
                r.descripcion,
                isnull(l.toma_1,0) as toma_1,
                isnull(l.toma_2,0) as toma_2,
                isnull(l.toma_3,0) as toma_3,
                isnull(case
                         when l.toma_3 > 0 then l.toma_3
                         when l.toma_2 > 0 then l.toma_2
                         when l.toma_1 > 0 then l.toma_1
                       end, 0) as contado,
                isnull(v.stock,0) as stock,
                l.bodega,
                r.porcentaje_iva
              into #datos
              from $db_dms.dbo.referencias_fis l
              left join $db_dms.dbo.v_ref_total v on v.codigo = l.codigo
                                                 and v.bodega = l.bodega
                                                 and v.mes = month(getdate())
                                                 and v.ano = year(getdate())
              inner join $db_dms.dbo.referencias r on r.codigo = l.codigo
              where l.bodega = ? and isnull(l.fisico,0) = 1;

              select
                d.codigo,
                d.descripcion,
                cast(isnull(d.contado,0) as numeric(19,0)) as conteo,
                cast(isnull(d.stock,0) as numeric(19,0)) as stock,
                d.porcentaje_iva,
                d.bodega,
                case when isnull(d.contado,0) <> isnull(d.stock,0) then
                       case when isnull(d.contado,0) > isnull(d.stock,0) then 'entrada' else 'salida' end
                     else '--'
                end as proceso,
                cast(round((
                  case when isnull(p.promedio,0) = 0 then isnull((
                         select top 1 ll.costo_unitario
                         from $db_dms.dbo.documentos_lin ll
                         where ll.codigo = d.codigo and ll.sw in (11,1,4,16) and ll.tipo <> 'DISP'
                         order by ll.fec desc
                       ), r.costo_unitario)
                  else p.promedio end
                ),0) as decimal(19,2)) as costo,
                case when isnull(d.contado,0) <> isnull(d.stock,0) then
                       case when isnull(d.contado,0) > isnull(d.stock,0) then 'entrada' else 'salida' end
                     else '--'
                end as tipo,
                d.toma_1,
                d.toma_2,
                d.toma_3
              into #result
              from #datos d
              inner join $db_dms.dbo.referencias r on r.codigo = d.codigo
              left join $db_dms.dbo.v_ref_total v on v.codigo = d.codigo
                                                 and v.bodega = d.bodega
                                                 and v.ano = year(getdate())
                                                 and v.mes = month(getdate())
              left join $db_dms.dbo.v_promedio p on p.codigo = v.codigo
                                                and p.mes = month(getdate())
                                                and p.ano = year(getdate())
              $where;

              select
                r.*,
                cod_barras = isnull((select top 1 alterno from $db_dms.dbo.referencias_alt where codigo = r.codigo), ''),
                round(isnull(ll.precio_venta,0),0) as precio
              from #result r
              left join $db_dms.dbo.v_lista_precios_iva ll on ll.codigo = r.codigo
                                                          and ll.bodega = ?
                                                          and ll.lista = 1
              order by r.descripcion;";
      inv_json( inv_rows( $conn, $sql, [ $bodega, $bodega ] ) );

    case "hacer-ajuste":
      $bodega = inv_bodega();
      $tipo_doc_entrada = trim( ( string )( $_POST[ "tipo_doc_entrada" ] ?? '' ) );
      $tipo_doc_salida = trim( ( string )( $_POST[ "tipo_doc_salida" ] ?? '' ) );
      $entradas = isset( $_POST[ 'entradas' ] ) ? ( json_decode( $_POST[ 'entradas' ], true ) ?: [] ) : [];
      $salidas = isset( $_POST[ 'salidas' ] ) ? ( json_decode( $_POST[ 'salidas' ], true ) ?: [] ) : [];

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

          // volcar al DMS
          [ $numeroDoc, , $error ] = inv_volcar( $conn, $doc[ 'numero' ], $doc[ 'sw' ], $doc[ 'tipo' ] );
          if ( $numeroDoc == 0 || $error !== '' ) throw new Exception( $error ?: 'No se pudo guardar el documento' );
          $doc[ 'numero_doc' ] = $numeroDoc;
        }
        unset( $doc );

        // cerrar la toma física de la bodega
        inv_exec( $conn, "update $db_dms.dbo.referencias_fis
                          set toma_1 = 0, toma_2 = 0, toma_3 = 0, fisico = 0
                          where bodega = ?", [ $bodega ] );

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
