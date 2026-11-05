<?php
session_start();
require_once( 'config.php' );
require_once( 'bd.php' );
require_once( '../shared/funciones.php' );
require_once( 'sesion.php' );
require_once( 'toma_fisica_logica.php' );
$conn = conectar();

/* ==========================================================================
 * TOMA FÍSICA SIN LOTE
 *  - El conteo (total por producto) se guarda en referencias_fis.toma_N
 *  - Los lotes a ajustar se leen de referencias_fis_lote (los llena el DMS)
 *  - El stock por lote sale de v_lotes_stock
 *  - El ajuste genera un documento de entrada (sw 12) y/o uno de salida (sw 11)
 * ========================================================================== */

const TF_NIT_AJUSTE = 901157558;
const TF_PC = 'servidor';

function tf_responder( bool $ok, string $mensaje = '', $data = null, array $extra = [] ) {
  echo json_encode( array_merge( [ 'ok' => $ok, 'mensaje' => $mensaje, 'data' => $data ], $extra ) );
  exit;
}

function tf_error_sql(): string {
  $errs = sqlsrv_errors();
  if ( !$errs ) return 'Error desconocido de base de datos';
  $msgs = [];
  foreach ( $errs as $e ) $msgs[] = tf_utf8( $e[ 'message' ] );
  return implode( ' | ', $msgs );
}

function tf_utf8( $s ) {
  if ( $s === null ) return '';
  $s = ( string )$s;
  return mb_check_encoding( $s, 'UTF-8' ) ? $s : mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
}

/** Ejecuta una consulta parametrizada y devuelve las filas. Lanza excepción si falla. */
function tf_rows( $conn, string $sql, array $params = [] ): array {
  $st = sqlsrv_query( $conn, $sql, $params );
  if ( $st === false ) throw new Exception( tf_error_sql() );
  $rows = [];
  // en lotes con varias sentencias se toma el primer conjunto de resultados con columnas
  do {
    if ( sqlsrv_num_fields( $st ) > 0 ) {
      while ( $row = sqlsrv_fetch_array( $st, SQLSRV_FETCH_ASSOC ) ) $rows[] = $row;
      break;
    }
  } while ( ( $next = sqlsrv_next_result( $st ) ) === true );
  if ( isset( $next ) && $next === false ) throw new Exception( tf_error_sql() );
  sqlsrv_free_stmt( $st );
  return $rows;
}

function tf_exec( $conn, string $sql, array $params = [] ) {
  $st = sqlsrv_query( $conn, $sql, $params );
  if ( $st === false ) throw new Exception( tf_error_sql() );
  sqlsrv_free_stmt( $st );
}

function tf_post_int( string $k, int $def = 0 ): int {
  return isset( $_POST[ $k ] ) && is_numeric( $_POST[ $k ] ) ? ( int )$_POST[ $k ] : $def;
}

function tf_toma(): int {
  $t = tf_post_int( 'toma', 1 );
  if ( $t < 1 || $t > 3 ) tf_responder( false, 'Número de toma inválido (1, 2 o 3)' );
  return $t;
}

function tf_bodega(): int {
  $b = tf_post_int( 'bodega', 0 );
  if ( $b <= 0 ) tf_responder( false, 'Seleccione la bodega' );
  return $b;
}

function tf_like( string $s ): string {
  return '%' . str_replace( [ '[', '%', '_' ], [ '[[]', '[%]', '[_]' ], $s ) . '%';
}

/**
 * Lotes (de referencias_fis_lote) con su stock (v_lotes_stock) agrupados por código.
 * @return array codigo => [ lotes ]
 */
function tf_lotes( $conn, string $db, int $bodega, ?array $codigos = null ): array {
  $params = [ $bodega ];
  $filtroSub = '';
  $filtro = '';
  if ( $codigos !== null ) {
    if ( count( $codigos ) === 0 ) return [];
    $ph = implode( ',', array_fill( 0, count( $codigos ), '?' ) );
    $filtroSub = " and codigo in ($ph)";
    $params = array_merge( $params, $codigos );
  }
  $params[] = $bodega;
  if ( $codigos !== null ) {
    $filtro = " and fl.codigo in (" . implode( ',', array_fill( 0, count( $codigos ), '?' ) ) . ")";
    $params = array_merge( $params, $codigos );
  }

  $sql = "select
            fl.codigo,
            fl.id_lote,
            m.lote,
            vcmto = convert(varchar(10), coalesce(m.lote_vcmto, fl.lote_vcmto), 23),
            stock = isnull(s.stock, 0)
          from $db.dbo.referencias_fis_lote fl
          inner join $db.dbo.referencias_fis f on f.codigo = fl.codigo and f.bodega = fl.bodega
          inner join $db.dbo.maestro_lotes   m on m.id_lote = fl.id_lote
          left join (
              select codigo, id_lote, stock = sum(stock)
              from $db.dbo.v_lotes_stock
              where bodega = ? $filtroSub
              group by codigo, id_lote
          ) s on s.codigo = fl.codigo and s.id_lote = fl.id_lote
          where fl.bodega = ? and f.fisico = 1 $filtro";

  $out = [];
  foreach ( tf_rows( $conn, $sql, $params ) as $r ) {
    $out[ $r[ 'codigo' ] ][] = [
      'id_lote' => ( int )$r[ 'id_lote' ],
      'lote' => tf_utf8( $r[ 'lote' ] ),
      'vcmto' => $r[ 'vcmto' ],
      'stock' => ( float )$r[ 'stock' ],
    ];
  }
  return $out;
}

/**
 * Arma el plan de ajuste de una bodega.
 * @param bool $incluirNoContados si es true, las referencias marcadas sin conteo se ajustan a 0
 */
function tf_plan( $conn, string $db, int $bodega, int $toma, bool $incluirNoContados, ?string $codigo = null ): array {
  $col = "toma_$toma";
  $params = [ $bodega ];
  $sql = "select
            f.codigo,
            r.descripcion,
            contado = f.$col,
            costo   = isnull(r.costo_unitario, 0),
            iva     = isnull(r.porcentaje_iva, 0)
          from $db.dbo.referencias_fis f
          inner join $db.dbo.referencias r on r.codigo = f.codigo
          where f.bodega = ? and f.fisico = 1";
  if ( !$incluirNoContados ) $sql .= " and f.$col is not null";
  if ( $codigo !== null ) {
    $sql .= " and f.codigo = ?";
    $params[] = $codigo;
  }
  $sql .= " order by r.descripcion";

  $prods = tf_rows( $conn, $sql, $params );
  $lotes = $codigo !== null ? tf_lotes( $conn, $db, $bodega, [ $codigo ] ) : tf_lotes( $conn, $db, $bodega );

  $plan = [ 'productos' => [], 'sin_lotes' => [], 'sin_lotes_cero' => [], 'lineas_entrada' => 0, 'lineas_salida' => 0,
    'unidades_entrada' => 0.0, 'unidades_salida' => 0.0, 'costo_entrada' => 0.0, 'costo_salida' => 0.0 ];

  foreach ( $prods as $p ) {
    $cod = $p[ 'codigo' ];
    $contado = $p[ 'contado' ] === null ? 0.0 : ( float )$p[ 'contado' ];
    $item = [
      'codigo' => $cod,
      'descripcion' => tf_utf8( $p[ 'descripcion' ] ),
      'contado' => $contado,
      'contado_null' => $p[ 'contado' ] === null,
      'costo' => ( float )$p[ 'costo' ],
      'iva' => ( float )$p[ 'iva' ],
      'stock' => 0.0,
      'lotes' => [],
    ];

    if ( empty( $lotes[ $cod ] ) ) {
      // no hay lote donde ajustar
      if ( $contado != 0 ) $plan[ 'sin_lotes' ][] = $item;
      else $plan[ 'sin_lotes_cero' ][] = $item;
      continue;
    }

    $item[ 'lotes' ] = tf_calcular_ajuste( $lotes[ $cod ], $contado );
    foreach ( $item[ 'lotes' ] as $l ) {
      $item[ 'stock' ] += $l[ 'stock' ];
      if ( $l[ 'diferencia' ] > 0 ) {
        $plan[ 'lineas_entrada' ]++;
        $plan[ 'unidades_entrada' ] += $l[ 'diferencia' ];
        $plan[ 'costo_entrada' ] += $l[ 'diferencia' ] * $item[ 'costo' ];
      } elseif ( $l[ 'diferencia' ] < 0 ) {
        $plan[ 'lineas_salida' ]++;
        $plan[ 'unidades_salida' ] += -$l[ 'diferencia' ];
        $plan[ 'costo_salida' ] += -$l[ 'diferencia' ] * $item[ 'costo' ];
      }
    }
    $item[ 'stock' ] = round( $item[ 'stock' ], TF_DECIMALES );
    $item[ 'diferencia' ] = round( $contado - $item[ 'stock' ], TF_DECIMALES );
    $plan[ 'productos' ][] = $item;
  }
  return $plan;
}

/** Toma el número del documento desde consecutivos (campo siguiente) y lo incrementa. */
function tf_siguiente_numero( $conn, string $db, string $tipo ): int {
  for ( $i = 0; $i < 100; $i++ ) {
    // OUTPUT ... INTO porque la tabla puede tener triggers
    $r = tf_rows( $conn, "set nocount on;
                          declare @t table (numero int);
                          update $db.dbo.consecutivos with (rowlock)
                          set siguiente = siguiente + 1
                          output deleted.siguiente into @t
                          where tipo = ?;
                          select numero from @t;", [ $tipo ] );
    if ( count( $r ) === 0 ) throw new Exception( "No existe consecutivo para el tipo $tipo" );
    $numero = ( int )$r[ 0 ][ 'numero' ];
    $existe = tf_rows( $conn, "select 1 as x from $db.dbo.documentos where tipo = ? and numero = ?", [ $tipo, $numero ] );
    if ( count( $existe ) === 0 ) return $numero;
  }
  throw new Exception( "No se pudo obtener un número libre para el tipo $tipo" );
}

/** Crea el encabezado y las líneas de un documento de ajuste. */
function tf_crear_documento( $conn, string $db, int $sw, string $tipo, int $bodega, array $lineas, string $usuario, string $notas ): int {
  $numero = tf_siguiente_numero( $conn, $db, $tipo );
  $costoTotal = 0.0;
  foreach ( $lineas as $l ) $costoTotal += $l[ 'cantidad' ] * $l[ 'costo' ];

  tf_exec( $conn, "insert into $db.dbo.documentos
      (sw, tipo, numero, nit, fecha, condicion, vencimiento, valor_total, iva, retencion, retencion_causada,
       retencion_iva, retencion_ica, descuento_pie, fletes, iva_fletes, costo, valor_aplicado, anulado,
       modelo, notas, usuario, pc, fecha_hora, retencion2, retencion3, bodega, impoconsumo, descuento2)
     values
      (?, ?, ?, ?, cast(getdate() as date), '0', cast(getdate() as date), 0, 0, 0, 0,
       0, 0, 0, 0, 0, ?, 0, 0,
       '*', ?, ?, ?, getdate(), 0, 0, ?, 0, 0)",
    [ $sw, $tipo, $numero, TF_NIT_AJUSTE, round( $costoTotal, 2 ), mb_substr( $notas, 0, 400 ), $usuario, TF_PC, $bodega ] );

  $seq = 0;
  foreach ( $lineas as $l ) {
    $seq++;
    tf_exec( $conn, "insert into $db.dbo.documentos_lin
        (sw, tipo, numero, codigo, seq, fec, nit, cantidad, porcentaje_iva, valor_unitario, porcentaje_descuento,
         costo_unitario, bodega, und, cantidad_und, cantidad_pedida, maneja_inventario, costo_unitario_sin,
         lote, lote_vcmto, id_lote)
       values
        (?, ?, ?, ?, ?, cast(getdate() as date), ?, ?, ?, ?, 0,
         ?, ?, 'UND', 1, 0, 'S', ?,
         ?, ?, ?)",
      [ $sw, $tipo, $numero, $l[ 'codigo' ], $seq, TF_NIT_AJUSTE, $l[ 'cantidad' ], $l[ 'iva' ], $l[ 'costo' ],
        $l[ 'costo' ], $bodega, $l[ 'costo' ],
        $l[ 'lote' ], $l[ 'vcmto' ], $l[ 'id_lote' ] ] );
  }
  return $numero;
}

/* ========================================================================== */

if ( !validarSessionEmpresa() ) tf_responder( false, 'No se encontro la empresa' );
if ( !validarSessionDb() ) tf_responder( false, 'No se encontro la base de datos' );

$db_dms = $_SESSION[ "Ses_db_dms" ];
if ( !preg_match( '/^\w+$/', $db_dms ) ) tf_responder( false, 'Base de datos inválida' );
$usuario = mb_substr( ( string )( $_SESSION[ "Ses_usuario" ] ?? '' ), 0, 10 );

try {
  switch ( $_POST[ "op" ] ?? '' ) {

    case "bodegas":
      $rows = tf_rows( $conn, "select bodega, descripcion from $db_dms.dbo.bodegas order by bodega" );
      foreach ( $rows as & $r ) $r[ 'descripcion' ] = tf_utf8( $r[ 'descripcion' ] );
      tf_responder( true, '', $rows, [ 'bodega_default' => ( int )( $_SESSION[ "Ses_bodega_default" ] ?? 0 ) ] );

    case "tipos_doc":
      $rows = tf_rows( $conn, "select tipo, descripcion, sw from $db_dms.dbo.tipo_transacciones
                               where sw in (11, 12) and isnull(inactivo, 'N') <> 'S'
                               order by sw, tipo" );
      foreach ( $rows as & $r ) $r[ 'descripcion' ] = tf_utf8( $r[ 'descripcion' ] );
      tf_responder( true, '', $rows );

    /* Búsqueda por código, código de barras (referencias_alt) o descripción.
       Solo devuelve referencias marcadas para toma física (fisico = 1) en la bodega. */
    case "buscar":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $filtro = trim( ( string )( $_POST[ 'filtro' ] ?? '' ) );
      if ( $filtro === '' ) tf_responder( true, '', [] );

      $col = "toma_$toma";
      $palabras = preg_split( '/\s+/', $filtro );
      $condDesc = [];
      $paramsDesc = [];
      foreach ( $palabras as $w ) {
        $condDesc[] = "r.descripcion like ?";
        $paramsDesc[] = tf_like( $w );
      }
      $condCodigo = '';
      $paramsCodigo = [];
      if ( count( $palabras ) === 1 ) {
        $condCodigo = " or r.codigo like ?";
        $paramsCodigo[] = str_replace( [ '[', '%', '_' ], [ '[[]', '[%]', '[_]' ], $filtro ) . '%';
      }

      $sql = "select top 50
                r.codigo,
                r.descripcion,
                conteo  = f.$col,
                usuario = f.usu_toma_$toma,
                exacto  = case when r.codigo = ? or exists(select 1 from $db_dms.dbo.referencias_alt a
                                                         where a.codigo = r.codigo and a.alterno = ?) then 1 else 0 end
              from $db_dms.dbo.referencias_fis f
              inner join $db_dms.dbo.referencias r on r.codigo = f.codigo
              where f.bodega = ? and f.fisico = 1 and (
                    r.codigo = ?
                 or exists(select 1 from $db_dms.dbo.referencias_alt a where a.codigo = r.codigo and a.alterno = ?)
                 or (" . implode( ' and ', $condDesc ) . ")
                 $condCodigo )
              order by exacto desc, r.descripcion";
      $params = array_merge( [ $filtro, $filtro, $bodega, $filtro, $filtro ], $paramsDesc, $paramsCodigo );
      $rows = tf_rows( $conn, $sql, $params );

      // Si no está marcada para toma, avisar en vez de "no encontrado"
      $aviso = '';
      if ( count( $rows ) === 0 ) {
        $x = tf_rows( $conn, "select top 1 r.codigo, r.descripcion from $db_dms.dbo.referencias r
                              where r.codigo = ? or exists(select 1 from $db_dms.dbo.referencias_alt a
                                                           where a.codigo = r.codigo and a.alterno = ?)",
          [ $filtro, $filtro ] );
        if ( count( $x ) > 0 ) {
          $aviso = 'La referencia ' . $x[ 0 ][ 'codigo' ] . ' - ' . tf_utf8( $x[ 0 ][ 'descripcion' ] ) .
            ' no está marcada para toma física en esta bodega';
        }
      }

      // stock del sistema (suma de los lotes de referencias_fis_lote)
      $codigos = array_column( $rows, 'codigo' );
      $lotes = tf_lotes( $conn, $db_dms, $bodega, $codigos );
      $data = [];
      foreach ( $rows as $r ) {
        $stock = 0.0;
        foreach ( $lotes[ $r[ 'codigo' ] ] ?? [] as $l ) $stock += $l[ 'stock' ];
        $data[] = [
          'codigo' => $r[ 'codigo' ],
          'descripcion' => tf_utf8( $r[ 'descripcion' ] ),
          'conteo' => $r[ 'conteo' ] === null ? null : ( float )$r[ 'conteo' ],
          'usuario' => tf_utf8( $r[ 'usuario' ] ),
          'exacto' => ( int )$r[ 'exacto' ] === 1,
          'stock' => round( $stock, TF_DECIMALES ),
          'n_lotes' => count( $lotes[ $r[ 'codigo' ] ] ?? [] ),
        ];
      }
      tf_responder( true, $aviso, $data );

    case "guardar_conteo":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $codigo = trim( ( string )( $_POST[ 'codigo' ] ?? '' ) );
      $cantidad = $_POST[ 'cantidad' ] ?? '';
      $sumar = ( $_POST[ 'modo' ] ?? 'sumar' ) === 'sumar';
      if ( $codigo === '' ) tf_responder( false, 'Seleccione un producto' );
      if ( !is_numeric( $cantidad ) ) tf_responder( false, 'Cantidad inválida' );
      $cantidad = round( ( float )$cantidad, TF_DECIMALES );
      if ( !$sumar && $cantidad < 0 ) tf_responder( false, 'La cantidad no puede ser negativa' );

      $col = "toma_$toma";
      $set = $sumar ? "$col = isnull($col, 0) + ?" : "$col = ?";
      // OUTPUT ... INTO porque referencias_fis tiene trigger
      $rows = tf_rows( $conn, "set nocount on;
                               declare @t table (conteo real);
                               update $db_dms.dbo.referencias_fis
                               set $set, usu_toma_$toma = ?, fecha_ultima = getdate()
                               output inserted.$col into @t
                               where codigo = ? and bodega = ? and fisico = 1;
                               select conteo from @t;",
        [ $cantidad, $usuario, $codigo, $bodega ] );
      if ( count( $rows ) === 0 ) tf_responder( false, 'La referencia no está marcada para toma física en esta bodega' );
      $conteo = ( float )$rows[ 0 ][ 'conteo' ];
      if ( $conteo < 0 ) {
        tf_exec( $conn, "update $db_dms.dbo.referencias_fis set $col = 0 where codigo = ? and bodega = ?", [ $codigo, $bodega ] );
        $conteo = 0.0;
      }
      tf_responder( true, 'Conteo guardado', [ 'codigo' => $codigo, 'conteo' => $conteo ] );

    case "limpiar_conteo":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $codigo = trim( ( string )( $_POST[ 'codigo' ] ?? '' ) );
      tf_exec( $conn, "update $db_dms.dbo.referencias_fis set toma_$toma = null, usu_toma_$toma = null
                       where codigo = ? and bodega = ? and fisico = 1", [ $codigo, $bodega ] );
      tf_responder( true, 'Conteo eliminado' );

    /* Listado de referencias marcadas con stock del sistema vs conteo */
    case "listar":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $estado = $_POST[ 'estado' ] ?? 'todos';
      $plan = tf_plan( $conn, $db_dms, $bodega, $toma, true );
      $data = [];
      foreach ( array_merge( $plan[ 'productos' ], $plan[ 'sin_lotes' ], $plan[ 'sin_lotes_cero' ] ) as $p ) {
        $sinLotes = count( $p[ 'lotes' ] ) === 0;
        $dif = $p[ 'contado_null' ] ? null : round( $p[ 'contado' ] - $p[ 'stock' ], TF_DECIMALES );
        if ( $estado === 'contados' && $p[ 'contado_null' ] ) continue;
        if ( $estado === 'pendientes' && !$p[ 'contado_null' ] ) continue;
        if ( $estado === 'diferencias' && ( $dif === null || $dif == 0 ) ) continue;
        $data[] = [
          'codigo' => $p[ 'codigo' ],
          'descripcion' => $p[ 'descripcion' ],
          'stock' => $p[ 'stock' ],
          'conteo' => $p[ 'contado_null' ] ? null : $p[ 'contado' ],
          'diferencia' => $dif,
          'n_lotes' => count( $p[ 'lotes' ] ),
          'sin_lotes' => $sinLotes,
        ];
      }
      usort( $data, fn( $a, $b ) => strcmp( $a[ 'descripcion' ], $b[ 'descripcion' ] ) );
      tf_responder( true, '', $data );

    /* Detalle de lotes de un producto con la simulación del ajuste */
    case "lotes":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $codigo = trim( ( string )( $_POST[ 'codigo' ] ?? '' ) );
      $plan = tf_plan( $conn, $db_dms, $bodega, $toma, true, $codigo );
      $p = $plan[ 'productos' ][ 0 ] ?? null;
      if ( $p === null ) tf_responder( false, 'La referencia no tiene lotes en la toma física (referencias_fis_lote)' );
      tf_responder( true, '', $p );

    case "previsualizar":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $incluir = ( $_POST[ 'incluir_no_contados' ] ?? 'false' ) === 'true';
      $plan = tf_plan( $conn, $db_dms, $bodega, $toma, $incluir );
      // solo los que tienen movimiento
      $plan[ 'productos' ] = array_values( array_filter( $plan[ 'productos' ], function ( $p ) {
        foreach ( $p[ 'lotes' ] as $l )
          if ( $l[ 'diferencia' ] != 0 ) return true;
        return false;
      } ) );
      tf_responder( true, '', $plan );

    case "ajustar":
      $bodega = tf_bodega();
      $toma = tf_toma();
      $incluir = ( $_POST[ 'incluir_no_contados' ] ?? 'false' ) === 'true';
      $tipoEntrada = trim( ( string )( $_POST[ 'tipo_entrada' ] ?? '' ) );
      $tipoSalida = trim( ( string )( $_POST[ 'tipo_salida' ] ?? '' ) );
      $notas = trim( ( string )( $_POST[ 'notas' ] ?? '' ) );
      if ( $notas === '' ) $notas = "AJUSTE TOMA FISICA $toma BODEGA $bodega";

      // validar tipos
      $tipos = tf_rows( $conn, "select tipo, sw from $db_dms.dbo.tipo_transacciones where tipo in (?, ?)", [ $tipoEntrada, $tipoSalida ] );
      $swTipo = array_column( $tipos, 'sw', 'tipo' );
      if ( ( int )( $swTipo[ $tipoEntrada ] ?? 0 ) !== 12 ) tf_responder( false, 'Seleccione un tipo de documento de ENTRADA (sw 12)' );
      if ( ( int )( $swTipo[ $tipoSalida ] ?? 0 ) !== 11 ) tf_responder( false, 'Seleccione un tipo de documento de SALIDA (sw 11)' );

      if ( !sqlsrv_begin_transaction( $conn ) ) throw new Exception( tf_error_sql() );
      try {
        // se recalcula dentro de la transacción con el stock actual
        $plan = tf_plan( $conn, $db_dms, $bodega, $toma, $incluir );

        $entradas = [];
        $salidas = [];
        foreach ( $plan[ 'productos' ] as $p ) {
          foreach ( $p[ 'lotes' ] as $l ) {
            if ( $l[ 'diferencia' ] == 0 ) continue;
            $linea = [ 'codigo' => $p[ 'codigo' ], 'cantidad' => abs( $l[ 'diferencia' ] ), 'costo' => $p[ 'costo' ],
              'iva' => $p[ 'iva' ], 'lote' => $l[ 'lote' ], 'vcmto' => $l[ 'vcmto' ], 'id_lote' => $l[ 'id_lote' ],
              'stock' => $l[ 'stock' ], 'final' => $l[ 'final' ], 'diferencia' => $l[ 'diferencia' ] ];
            if ( $l[ 'diferencia' ] > 0 ) $entradas[] = $linea;
            else $salidas[] = $linea;
          }
        }

        if ( count( $entradas ) === 0 && count( $salidas ) === 0 ) {
          sqlsrv_rollback( $conn );
          tf_responder( false, 'No hay diferencias para ajustar', null, [ 'sin_lotes' => $plan[ 'sin_lotes' ] ] );
        }

        $numEntrada = count( $entradas ) ? tf_crear_documento( $conn, $db_dms, 12, $tipoEntrada, $bodega, $entradas, $usuario, $notas ) : 0;
        $numSalida = count( $salidas ) ? tf_crear_documento( $conn, $db_dms, 11, $tipoSalida, $bodega, $salidas, $usuario, $notas ) : 0;

        // dejar registrado el conteo por lote en referencias_fis_lote (resultado del ajuste)
        foreach ( $plan[ 'productos' ] as $p ) {
          foreach ( $p[ 'lotes' ] as $l ) {
            tf_exec( $conn, "update $db_dms.dbo.referencias_fis_lote set toma_$toma = ?, usu_toma_$toma = ?
                             where codigo = ? and bodega = ? and id_lote = ?",
              [ $l[ 'final' ], $usuario, $p[ 'codigo' ], $bodega, $l[ 'id_lote' ] ] );
          }
          if ( $p[ 'contado_null' ] ) { // no contado incluido en el ajuste => queda en 0
            tf_exec( $conn, "update $db_dms.dbo.referencias_fis set toma_$toma = 0, usu_toma_$toma = ?
                             where codigo = ? and bodega = ?", [ $usuario, $p[ 'codigo' ], $bodega ] );
          }
        }

        // bitácora (base de datos web)
        $log = tf_rows( $conn, "insert into toma_fisica_ajustes
              (empresa_id, bodega, toma, usuario_id, usuario, tipo_entrada, numero_entrada, tipo_salida, numero_salida,
               lineas_entrada, lineas_salida, incluyo_no_contados, notas)
            output inserted.id
            values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          [ ( int )$_SESSION[ 'Ses_empresa' ], $bodega, $toma, ( int )$_SESSION[ 'Ses_id' ], $usuario,
            $numEntrada ? $tipoEntrada : null, $numEntrada ?: null, $numSalida ? $tipoSalida : null, $numSalida ?: null,
            count( $entradas ), count( $salidas ), $incluir ? 1 : 0, mb_substr( $notas, 0, 400 ) ] );
        $ajusteId = ( int )$log[ 0 ][ 'id' ];
        foreach ( [ [ 12, $tipoEntrada, $numEntrada, $entradas ], [ 11, $tipoSalida, $numSalida, $salidas ] ] as [ $sw, $tipo, $num, $lineas ] ) {
          foreach ( $lineas as $l ) {
            tf_exec( $conn, "insert into toma_fisica_ajustes_det
                  (ajuste_id, codigo, id_lote, lote, stock_sistema, cantidad_final, diferencia, sw, tipo, numero)
                values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
              [ $ajusteId, $l[ 'codigo' ], $l[ 'id_lote' ], $l[ 'lote' ], $l[ 'stock' ], $l[ 'final' ], $l[ 'diferencia' ], $sw, $tipo, $num ] );
          }
        }

        if ( !sqlsrv_commit( $conn ) ) throw new Exception( tf_error_sql() );
      } catch ( Throwable $e ) {
        sqlsrv_rollback( $conn );
        throw $e;
      }

      tf_responder( true, 'Ajuste realizado', [
        'tipo_entrada' => $tipoEntrada, 'numero_entrada' => $numEntrada, 'lineas_entrada' => count( $entradas ),
        'tipo_salida' => $tipoSalida, 'numero_salida' => $numSalida, 'lineas_salida' => count( $salidas ),
        'sin_lotes' => $plan[ 'sin_lotes' ],
      ] );

    case "historial":
      $bodega = tf_bodega();
      $rows = tf_rows( $conn, "select top 100 id, toma, usuario,
                                 fecha = convert(varchar(19), fecha, 120),
                                 tipo_entrada, numero_entrada, tipo_salida, numero_salida,
                                 lineas_entrada, lineas_salida, notas
                               from toma_fisica_ajustes
                               where bodega = ? and empresa_id = ?
                               order by id desc", [ $bodega, ( int )$_SESSION[ 'Ses_empresa' ] ] );
      foreach ( $rows as & $r ) $r[ 'notas' ] = tf_utf8( $r[ 'notas' ] );
      tf_responder( true, '', $rows );

    default:
      tf_responder( false, 'Operación no válida' );
  }
} catch ( Throwable $e ) {
  tf_responder( false, tf_utf8( $e->getMessage() ) );
}
