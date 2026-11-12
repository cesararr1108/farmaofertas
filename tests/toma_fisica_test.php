<?php
// Ejecutar: php tests/toma_fisica_test.php
require_once __DIR__ . '/../app/models/toma_fisica_logica.php';

$fallos = 0;
function verificar( string $nombre, array $resultado, array $esperado ) {
  global $fallos;
  $obtenido = [];
  foreach ( $resultado as $l ) $obtenido[ $l[ 'lote' ] ] = $l[ 'final' ];
  ksort( $obtenido );
  ksort( $esperado );
  if ( $obtenido == $esperado ) {
    echo "OK    $nombre\n";
  } else {
    $fallos++;
    echo "FALLO $nombre\n  esperado: " . json_encode( $esperado ) . "\n  obtenido: " . json_encode( $obtenido ) . "\n";
  }
}

$base = [
  [ 'id_lote' => 1, 'lote' => 'AA', 'vcmto' => '2026-12-31', 'stock' => 2 ],
  [ 'id_lote' => 2, 'lote' => 'BB', 'vcmto' => '2027-03-31', 'stock' => 4 ],
  [ 'id_lote' => 3, 'lote' => 'CC', 'vcmto' => '2027-06-30', 'stock' => 5 ],
];

// Caso del requerimiento: sistema 11, contó 15 -> +4 al lote CC (el de más unidades)
verificar( 'Contó 15 (faltan 4): suma en CC', tf_calcular_ajuste( $base, 15 ), [ 'AA' => 2, 'BB' => 4, 'CC' => 9 ] );

// Caso del requerimiento: sistema 11, contó 7 -> saca 2 de AA y 2 de BB (más próximos a vencer)
verificar( 'Contó 7 (sobran 4): saca AA y BB', tf_calcular_ajuste( $base, 7 ), [ 'AA' => 0, 'BB' => 2, 'CC' => 5 ] );

verificar( 'Contó igual: sin cambios', tf_calcular_ajuste( $base, 11 ), [ 'AA' => 2, 'BB' => 4, 'CC' => 5 ] );
verificar( 'Contó 0: todo a cero', tf_calcular_ajuste( $base, 0 ), [ 'AA' => 0, 'BB' => 0, 'CC' => 0 ] );

// Todos los lotes en 0 -> suma al más próximo a vencer (aunque venga desordenado)
$ceros = [
  [ 'id_lote' => 5, 'lote' => 'L2', 'vcmto' => '2027-05-01', 'stock' => 0 ],
  [ 'id_lote' => 4, 'lote' => 'L1', 'vcmto' => '2026-12-01', 'stock' => 0 ],
  [ 'id_lote' => 6, 'lote' => 'L3', 'vcmto' => null, 'stock' => 0 ],
];
verificar( 'Lotes en 0: suma al más próximo a vencer', tf_calcular_ajuste( $ceros, 3 ), [ 'L1' => 3, 'L2' => 0, 'L3' => 0 ] );

// Lote negativo se lleva a 0
$neg = [
  [ 'id_lote' => 1, 'lote' => 'N1', 'vcmto' => '2026-12-01', 'stock' => -2 ],
  [ 'id_lote' => 2, 'lote' => 'N2', 'vcmto' => '2027-12-01', 'stock' => 6 ],
];
verificar( 'Negativo a 0 y resto ajustado', tf_calcular_ajuste( $neg, 4 ), [ 'N1' => 0, 'N2' => 4 ] );
verificar( 'Negativo a 0, faltantes al mayor', tf_calcular_ajuste( $neg, 8 ), [ 'N1' => 0, 'N2' => 8 ] );

// Empate en el mayor -> el más próximo a vencer
$empate = [
  [ 'id_lote' => 1, 'lote' => 'E2', 'vcmto' => '2027-06-01', 'stock' => 5 ],
  [ 'id_lote' => 2, 'lote' => 'E1', 'vcmto' => '2027-01-01', 'stock' => 5 ],
];
verificar( 'Empate: suma al más próximo a vencer', tf_calcular_ajuste( $empate, 12 ), [ 'E1' => 7, 'E2' => 5 ] );

// Diferencias: entradas y salidas
$r = tf_calcular_ajuste( $base, 7 );
$difs = array_column( $r, 'diferencia', 'lote' );
if ( $difs != [ 'AA' => -2, 'BB' => -2, 'CC' => 0 ] ) { $fallos++; echo "FALLO diferencias: " . json_encode( $difs ) . "\n"; }
else echo "OK    Diferencias de salida correctas\n";

// Exclusión por sufijo de código
$casos = [ 'ABC123MD' => true, 'ABC123MC' => true, 'ABC123MC*S' => true, 'abc123mc ' => true,
  'ABC123' => false, 'MD' => false, 'ABCMCS' => false, 'ABC123MDX' => false ];
foreach ( $casos as $cod => $esp ) {
  if ( tf_excluido( $cod ) !== $esp ) { $fallos++; echo "FALLO tf_excluido('$cod') esperaba " . var_export( $esp, true ) . "\n"; }
}
echo "OK    Exclusión de códigos MD / MC / MC*S\n";

echo $fallos === 0 ? "\nTodas las pruebas pasaron\n" : "\n$fallos prueba(s) fallaron\n";
exit( $fallos === 0 ? 0 : 1 );
