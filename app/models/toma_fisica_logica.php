<?php
/**
 * Lógica pura de la toma física (sin base de datos) para poder probarla aislada.
 *
 * Reglas de negocio:
 *  - El usuario cuenta el TOTAL del producto, sin lote.
 *  - Se compara contra la suma del stock de los lotes (v_lotes_stock) que el DMS
 *    dejó en referencias_fis_lote.
 *  - Lotes con stock negativo se llevan a 0 (el físico no puede ser negativo).
 *  - Si FALTAN unidades en el sistema (contado > sistema): se suman todas al lote
 *    con más unidades (empate: el más próximo a vencer). Si ningún lote tiene stock,
 *    se suman al lote más próximo a vencer.
 *  - Si SOBRAN unidades en el sistema (contado < sistema): se sacan de los lotes
 *    más próximos a vencer primero (FEFO), dejando cada lote mínimo en 0.
 */

const TF_DECIMALES = 4;

/** Ordena lotes por fecha de vencimiento ascendente (sin fecha al final) y luego por id_lote. */
function tf_orden_fefo( array $lotes ): array {
  usort( $lotes, function ( $a, $b ) {
    $va = $a[ 'vcmto' ] ?? null;
    $vb = $b[ 'vcmto' ] ?? null;
    if ( $va === $vb ) return ( int )$a[ 'id_lote' ] <=> ( int )$b[ 'id_lote' ];
    if ( $va === null || $va === '' ) return 1;
    if ( $vb === null || $vb === '' ) return -1;
    $c = strcmp( $va, $vb ); // formato Y-m-d
    return $c !== 0 ? $c : ( ( int )$a[ 'id_lote' ] <=> ( int )$b[ 'id_lote' ] );
  } );
  return $lotes;
}

/**
 * Calcula la cantidad final de cada lote para que la suma sea igual a lo contado.
 *
 * @param array $lotes   [ ['id_lote'=>int,'lote'=>string,'vcmto'=>'Y-m-d'|null,'stock'=>float], ... ]
 * @param float $contado Total contado por el usuario (>= 0)
 * @return array lotes en orden FEFO con 'final' y 'diferencia' (final - stock).
 *               diferencia > 0 => entrada (sw 12), diferencia < 0 => salida (sw 11)
 */
function tf_calcular_ajuste( array $lotes, float $contado ): array {
  if ( count( $lotes ) === 0 ) return [];
  if ( $contado < 0 ) throw new InvalidArgumentException( 'La cantidad contada no puede ser negativa' );

  $lotes = tf_orden_fefo( $lotes );

  $positivo = 0.0;
  foreach ( $lotes as & $l ) {
    $l[ 'stock' ] = round( ( float )$l[ 'stock' ], TF_DECIMALES );
    $l[ 'final' ] = max( $l[ 'stock' ], 0.0 );
    $positivo += $l[ 'final' ];
  }
  unset( $l );

  $dif = round( $contado - $positivo, TF_DECIMALES );

  if ( $dif > 0 ) {
    // Faltan en el sistema: sumar al lote con más unidades (o al más próximo a vencer si todos en 0)
    $idx = 0; // en orden FEFO, el primero es el más próximo a vencer
    if ( $positivo > 0 ) {
      foreach ( $lotes as $i => $l ) {
        if ( $l[ 'final' ] > $lotes[ $idx ][ 'final' ] ) $idx = $i; // ">" estricto: empate gana el más próximo a vencer
      }
    }
    $lotes[ $idx ][ 'final' ] = round( $lotes[ $idx ][ 'final' ] + $dif, TF_DECIMALES );
  } elseif ( $dif < 0 ) {
    // Sobran en el sistema: sacar de los más próximos a vencer
    $restante = -$dif;
    foreach ( $lotes as & $l ) {
      if ( $restante <= 0 ) break;
      $saca = min( $l[ 'final' ], $restante );
      $l[ 'final' ] = round( $l[ 'final' ] - $saca, TF_DECIMALES );
      $restante = round( $restante - $saca, TF_DECIMALES );
    }
    unset( $l );
  }

  foreach ( $lotes as & $l ) {
    $l[ 'diferencia' ] = round( $l[ 'final' ] - $l[ 'stock' ], TF_DECIMALES );
  }
  unset( $l );

  return $lotes;
}
