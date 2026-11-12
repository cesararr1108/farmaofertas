<?php
session_start();
if (empty($_SESSION["Ses_usuario"])) {
    echo "<div style='padding:20px;font-family:sans-serif;color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;'>Acceso denegado. Debe iniciar sesión.</div>";
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Sistema de Inventarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- jQuery UI -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/themes/base/jquery-ui.min.css">

    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">

    <!-- SweetAlert -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.12/sweetalert2.min.css">

    <!-- CSS General -->
    <link rel="stylesheet" href="../resources/css/general.css?<?php echo rand(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://unpkg.com/@zxing/library@latest"></script>

<style>
    .cargando-overlay {
    display: none; /* Oculto por defecto */
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5); /*  Fondo gris semitransparente */
    z-index: 1050;
    display: flex;
    justify-content: center;
    align-items: center;
}

.cargando-spinner {
    text-align: center;
}
html,*{
    font-size:0.9rem
}
/* Hace que Select2 ocupe todo el input-group */
.input-group .select2-container {
    flex: 1 1 auto;
    width: 100% !important;
}

/* Altura exacta de Bootstrap */
.select2-container .select2-selection--single {
    height: 38px !important;          /* mismo que .form-control */
    display: flex !important;
    align-items: center;
}

/* Alinea el texto verticalmente */
.select2-selection__rendered {
    line-height: 38px !important;
}

/* Flecha centrada */
.select2-selection__arrow {
    height: 38px !important;
}


</style>
</head>
<body>


<input type="hidden" id="bodega" value="<?php echo  $_SESSION["Ses_bodega_default"];  ?>" />
<input type="hidden" id="rol" value="<?php echo  $_SESSION["Ses_rol_id"];  ?>" />
<ul class="nav nav-tabs" id="myTab" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="home-tab" data-bs-toggle="tab" data-bs-target="#home-tab-pane" type="button" role="tab" aria-controls="home-tab-pane" aria-selected="true"><i class="fa-solid fa-barcode"></i> Toma fisica</button>
  </li>
  <?PHP

    if($_SESSION["Ses_rol_id"]  == 1){
        echo '  <li class="nav-item li-ajustes" role="presentation">
    <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-tab-pane" type="button" role="tab" aria-controls="profile-tab-pane" aria-selected="false"><i class="fa-solid fa-gears"></i> Ajuste</button>
  </li>';
    }

?>
  <?PHP

    if($_SESSION["Ses_rol_id"]  == 1){
        echo  '  <li class="nav-item li-avance" role="presentation">
    <button class="nav-link" id="avance-tab" data-bs-toggle="tab" data-bs-target="#avance-tab-pane" type="button" role="tab" aria-controls="avance-tab-pane" aria-selected="false"><i class="fa-solid fa-chart-line"></i> Avance</button>
  </li>';
    }
?>

  <!-- <li class="nav-item li-marcado" role="presentation">
    <button class="nav-link" id="profile-tab" data-bs-toggle="tab" data-bs-target="#config-tab-pane" type="button" role="tab" aria-controls="config-tab-pane" aria-selected="false"><i class="fa-solid fa-list-check"></i> Marcado</button>
  </li>-->

</ul>
<div class="tab-content" id="myTabContent">
  <div class="tab-pane fade show active" id="home-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">

       <div class="container-fluid my-4">

            <div class="card p-4 mt-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cbarras" checked>
                            <label class="form-check-label" for="cbarras">Código de Barras</label>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conteo" id="conteo1" value="1" checked>
                                <label class="form-check-label" for="conteo1">Conteo 1</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conteo" id="conteo2" value="2">
                                <label class="form-check-label" for="conteo2">Conteo 2</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conteo" id="conteo3" value="3">
                                <label class="form-check-label" for="conteo3">Conteo 3</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                        <div class="col-md-6">

                            <label for="codigo" class="form-label">Código</label>
                            <!-- <input type="text" class="form-control" id="buscar_codigo">-->
                            <input type="hidden" class="form-control" readonly id="codigo">
                                <div class="input-group mb-3">
                                    <span class="input-group-text" id="">
                                        <button class="btn btn-sm btn-outline-primary"  onclick="abrirCamara()">
                                            <i class="fa-solid fa-barcode"></i>
                                        </button>
                                    </span>
                                    <input type="text" class="form-control" id="buscar_codigo" enterkeyhint="search" autocomplete="off">
                                    <span class="input-group-text" id="">
                                        <button class="btn btn-sm btn-outline-warning"  onclick="Limpiar()">
                                            <i class="fa-solid fa-eraser"></i>
                                        </button>
                                    </span>
                                </div>
                        </div>
                        <div class="col-md-6">
                            <label for="descripcion" class="form-label">Descripción</label>
                                <div class="input-group mb-3">
                                    <span class="input-group-text" id="">
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="fa-solid fa-boxes-packing"></i>
                                        </button>
                                    </span>
                                    <input type="text" class="form-control" id="descripcion">
                                </div>
                        </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <label for="cantidad_contada" class="form-label mb-2 mt-2">Unidades</label>
                        <div class="input-group">
                            <div id="div_unidades"></div>
                        </div>

                       <!-- <div class="input-group mb-3">
                            <span class="input-group-text" id="">
                                <button title="Agregar lote" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalNuevoLote">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </span>
                            <select class="form-control p-2" id="lote"></select>
                        </div>-->


                        <!--<div class="row">
                            <div class="col-11">
                                 <select class="form-control p-2" id="lote"></select>
                            </div>
                            <div class="col-1">
                                <button title="Agregar lote" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalNuevoLote">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>-->

                    </div>
                    <div class="col-md-3" style="display:none">
                        <label>Stock </label>
                         <input type="number" readonly class="form-control" id="stock_producto" value="0" />
                    </div>
                    <div class="col-md-3">
                        <label for="cantidad_contada" class="form-label">Conteo registrado</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="cantidad_contada">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="cantidad" class="form-label">Cantidad <small class="text-muted">(Enter para agregar)</small></label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="cantidad" max="9999"  min="0" inputmode="decimal" enterkeyhint="done">
                            <button class="btn btn-outline-success" onclick="Insertar_conteo()">
                            <i class="fas fa-check"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col mt-2" id="aviso_lote"></div>
                </div>
            </div>

<!--
            <div class="card p-4 card-informe-diferencias">
                <button class="btn btn-outline-primary" id="informe_dif">
                    <i class="fas fa-check"></i> INFORME DE DIFERENCIAS
                </button>
            </div> -->

            <div class="card p-4  mt-4" id="reporte_diferencias">
                <div class="row">
                <div class="table-responsive mt-4">
                <h3 class="text-primary text-center">Reporte de Diferencias de Inventario</h3>
                <table id="tabla_diferencias" class="table table-bordered mt-3">
                    <thead id="tabla_diferencias_encabezado" class="table-primary">
                        <tr>
                            <th>Código barras</th>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th>Lab</th>
                            <th>Bodega</th>
                            <th>Lote</th>
                            <th>Vencimiento</th>
                            <th>Toma 1</th>
                            <th>Toma 2</th>
                            <th>Toma 3</th>
                            <th>Stock</th>
                            <th>Faltante</th>
                            <th>Costo total</th>
                            <th>Venta total</th>
                            <th>Eliminar</th>
                        </tr>
                    </thead>
                    <tbody id="tabla_diferencias_body"></tbody>
                </table>
            </div>
        </div>
    </div>
    </div>
  </div>
  <div class="tab-pane fade" id="profile-tab-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
        <div class="alert alert-primary mt-2">
            Ajustes de inventarios
        </div>
        <div class="row mt-3 mb-2">
         <div class="col">
                <label>Tipo documento entrada</label>
             <select class="form-control" id="entradas"></select>
         </div>
         <div class="col">
                <label>Tipo documento salidas</label>
             <select class="form-control" id="salidas"></select>
         </div>
         <div class="col">
                <label>Lote para entradas</label>
             <select class="form-control" id="orden-entrada">
                 <option value="cercano" selected>Más próximo a vencer</option>
                 <option value="lejano">Más lejano a vencer</option>
             </select>
         </div>
         <div class="col">
                <label>Lote para salidas</label>
             <select class="form-control" id="orden-salida">
                 <option value="cercano">Más próximo a vencer</option>
                 <option value="lejano" selected>Más lejano a vencer</option>
             </select>
         </div>
         <div class="col">
                <label>Mostrar resultados</label>
             <select class="form-control" id="mostrar_resultados">
                 <option value="">Todos</option>
                 <option value="diferencias">Solo con diferencias</option>
                 <option value="con-conteos">Solo con conteo</option>
             </select>
         </div>
      </div>
        <div class="row">
            <div class="col-12">
                <div class="btn-group">
                    <button class="btn btn-outline-info m-1" id="informe-previo"><i class="fa fa-eye"></i> Generar informe previo</button>
                    <button class="btn btn-outline-success m-1" id="exportar-informe"><i class="fa-solid fa-file-excel"></i> Exportar</button>
                    <button class="btn btn-outline-danger m-1" id="ajustar-inventario"><i class="fa-solid fa-thumbs-up"></i> Generar ajuste</button>
                </div>
            </div>
        </div>
        <div class="row mt-2 card">
            <div class="card-body">
                <div class="row">
                    <div class="col">
                        <label for="" class="form-label">Costo entradas</label>
                        <div class="input-group mb-3">
                            <span class="input-group-text" id="">
                                <i class="fa-solid fa-circle-arrow-up text-success fa-2x "></i>
                            </span>
                            <input type="text" class="form-control" id="costo-entradas" readonly>
                        </div>
                    </div>
                    <div class="col">
                        <label for="" class="form-label">Costo salidas</label>
                        <div class="input-group mb-3">
                            <span class="input-group-text" id="">
                                <i class="fa-solid fa-circle-arrow-down text-danger fa-2x "></i>
                            </span>
                            <input type="text" class="form-control" id="costo-salidas" readonly>
                        </div>
                    </div>
                    <div class="col">
                        <label>Estados resultados</label>
                        <select class="form-control mt-2" id="estados-resultados" onchange="filtroEstados(this.value)">
                            <option value="">Todos</option>
                            <option value="--">Sin ajuste</option>
                            <option value="entrada">Ajuste entradas</option>
                            <option value="salida">Ajuste salidas</option>
                            <option value="sin lote">Sin lote</option>
                        </select>
                    </div>
                </div>

            </div>
        </div>
        <div class="row">
            <div class="col px-3 pt-2" id="aviso-sin-lote"></div>
        </div>
        <div class="row">
            <div class="col p-3" id="result"></div>
        </div>
  </div>

  <div class="tab-pane fade" id="avance-tab-pane" role="tabpanel" aria-labelledby="avance-tab" tabindex="0">
        <div class="alert alert-primary mt-2">
            Avance de la toma física y conteo por usuario
        </div>
        <div class="row mb-3 align-items-end">
            <div class="col-md-3">
                <label>Pendientes del conteo</label>
                <select class="form-control" id="avance-conteo">
                    <option value="1">Conteo 1</option>
                    <option value="2">Conteo 2</option>
                    <option value="3">Conteo 3</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-info" id="avance-consultar"><i class="fa-solid fa-rotate"></i> Consultar</button>
            </div>
        </div>
        <div id="avance-resumen"></div>
        <div class="card mb-3">
            <div class="card-body">
                <h6>Contado por usuario</h6>
                <div class="table-responsive" id="avance-usuarios"></div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <h6 id="avance-pendientes-titulo">Pendientes</h6>
                <div class="table-responsive" id="avance-pendientes"></div>
            </div>
        </div>
  </div>

  <div class="tab-pane fade" id="config-tab-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
     <div class="card p-2">
            <div class="alert alert-danger">
                *
            </div>
            <div class="card-title">
                <h5>Marcado de productos</h5>
            </div>
            <div class="card-body">
                <div class="row mb-2">
                    <div class="form-check form-switch col-sm-12 ">
                      <input class="form-check-input" type="checkbox" id="solo-inventarios" >
                      <label class="form-check-label" for="solo-inventarios">Solo con inventarios</label>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <div class="input-group mb-3">
                            <span class="input-group-text" id="basic-addon1">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </span>
                            <input type="text"
                            class="form-control"
                             id="busqueda-productos">
                             <input type="hidden" id="codigo-material"  readonly />
                        </div>
                    </div>
                </div>
                <div class="row mb-3">

                    <div class="col-sm-6 mb-3">
                        <label class="col-sm-2 col-form-label">Laboratorios</label>
                        <select class="form-select"  id="laboratorios">
                        </select>
                    </div>

                    <div class="col-sm-6 mb-3">
                        <label class="col-sm-2 col-form-label">Generico</label>
                        <select class="form-select"  id="generico">
                        </select>
                    </div>

                    <div class="col-sm-6 mb-3">
                        <label class="col-sm-2 col-form-label">Clase</label>
                        <select class="form-select"  id="clases">
                        </select>
                    </div>

                    <div class="col-sm-6 mb-3">
                        <label class="col-sm-2 col-form-label">Grupo</label>
                        <select class="form-select"  id="grupos">
                        </select>
                    </div>

                    <div class="col-sm-6 mb-3">
                        <label class="col-sm-2 col-form-label">Sub Grupo</label>
                        <select class="form-select"  id="sub-grupos">
                        </select>
                    </div>

                    <div class="col-sm-6 mb-3">
                        <label class="col-sm-2 col-form-label">Sub Grupo 2</label>
                        <select class="form-select"  id="sub-grupos2">
                        </select>
                    </div>

                </div>
                <div class="row mt-2">
                    <button class="btn btn-outline-success" id="marcar-productos">Marca productos</button>
                </div>
            </div>
     </div>
  </div>

</div>


      <!-- /.modal -->

<div class="modal fade" id="modalNuevoLote"  tabindex="-1" aria-labelledby="exampleModalLgLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-4" id="exampleModalLgLabel">Nuevo lote</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label for="nuevoLoteCodigo">Codigo:</label>
       <input type="text" id="nuevoLoteCodigo" class="form-control">


        <label for="nuevoLote">Lote:</label>
        <input type="text" id="nuevoLote" class="form-control">

        <label for="fechaVencimiento">Fecha de Vencimiento:</label>
        <input type="date" id="fechaVencimiento" class="form-control mt-2">
      </div>
      <div class="modal-footer justify-content-between">
            <button type="button" class="btn btn-primary" onclick="guardarNuevoLote()">Guardar</button>
        </div>
    </div>
  </div>
</div>

<!-- Crear lote a productos de la toma que no tienen lotes (maestro_lotes + referencias_fis_lote) -->
<div class="modal fade" id="modalCrearLotes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-5">Crear lote a productos sin lote</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">El lote se crea en maestro_lotes y queda asociado a la toma física de la bodega para que el ajuste entre por ese lote.</p>
        <div class="row">
          <div class="col-7">
            <label for="cl_lote">Lote:</label>
            <input type="text" id="cl_lote" class="form-control text-uppercase" maxlength="30" autocomplete="off">
          </div>
          <div class="col-5">
            <label for="cl_vcmto">Vencimiento:</label>
            <input type="date" id="cl_vcmto" class="form-control">
          </div>
        </div>
        <div class="mt-3"><b id="cl_cantidad">0</b> producto(s):</div>
        <div class="border rounded p-2 small" style="max-height:35vh;overflow-y:auto" id="cl_lista"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success" id="cl_guardar" onclick="guardarCrearLotes()"><i class="fas fa-check"></i> Crear lote</button>
      </div>
    </div>
  </div>
</div>


    <div class="modal fade" id="modalListProductos"  tabindex="-1" aria-labelledby="exampleModalLgLabel2" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
        <div class="modal-header">
            <h1 class="modal-title fs-4" id="exampleModalLgLabel">Seleccione el código</h1>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <div id="list-productos"></div>
        </div>
    </div>
    </div>
    </div>



<div class="modal fade"
id="modalLeerCodigo"
tabindex="-1"
aria-labelledby="exampleModalLgLabel"
aria-hidden="true"
data-bs-backdrop="static"
data-bs-keyboard="false"
aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-4" id="exampleModalLgLabel">Leer codigo </h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
        <div class="modal-body d-flex justify-content-center align-items-center">
            <div id="scanner" class="w-100 text-center">
                <video id="video" class="w-100 rounded" style="max-width: 320px;"></video>
            </div>
        </div>
    </div>
  </div>
</div>


<div class="modal fade"  tabindex="-1" aria-labelledby="modalNuevoLoteLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalNuevoLoteLabel">Crear Nuevo Lote</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">

            </div>
            <div class="modal-footer">

            </div>
        </div>
    </div>
</div>
<!-- Capa de carga -->
<div id="cargando" class="cargando-overlay">
    <div class="cargando-spinner">
        <div class="spinner-border text-light" role="status">
            <span class="visually-hidden">Cargando...</span>
        </div>
        <p class="text-light mt-2">Cargando...</p>
    </div>
</div>

<div class="modal fade" id="modalBusquedaProductos"  tabindex="-1" >
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-4" id="">Búsqueda de productos</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
            <div class="" id="result-productos"></div>
      </div>
      <div class="modal-footer justify-content-between">

      </div>
    </div>
  </div>
</div>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>


    <!-- jQuery UI -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.2/jquery-ui.min.js"></script>

    <!-- SweetAlert -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.12/sweetalert2.min.js"></script>

    <!-- Select2 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>

    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


    <script src="../shared/shared.js?<?php  echo  rand(); ?>"></script>

    <!-- SheetJS para exportación a Excel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <!-- Archivo JS personalizado -->
    <script src="../controllers/Inventario.js?<?php  echo rand(); ?>"></script>
</body>
</html>
