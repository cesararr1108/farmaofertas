<?php session_start(); ?>
<!DOCTYPE html>
<html lang="es" translate="no">
<head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Toma física</title>

<!-- Favicons -->
<link href="../../assets/img/favicon.png" rel="icon">
<link href="../../assets/img/apple-touch-icon.png" rel="apple-touch-icon">
<!-- Google Fonts -->
<link href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

<!-- Vendor CSS Files -->
<link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
<link href="../../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="../../app/resources/jquery/jquery-ui-1.13.2.custom/jquery-ui.min.css" rel="stylesheet" >
<link href="../resources/fontawesome-free-6.4.2-web/css/all.css" rel="stylesheet" />
<link href="../resources/SweetAlert2/SweetAlert2.css" rel="stylesheet" >
<!-- Template Main CSS File -->
<link href="../../assets/css/style.css" rel="stylesheet" >
<style>
.table-sm tbody { font-size: 0.8rem }
.form-control, .form-select { border: 1px #B5B5B5 solid; }
#resultados tbody tr { cursor: pointer; }
#resultados tbody tr:hover, #resultados tbody tr.activo { background-color: #cfe2ff; }
#cantidad { font-size: 1.4rem; font-weight: 600; text-align: right; }
.dif-pos { color: #198754; font-weight: 600; }
.dif-neg { color: #dc3545; font-weight: 600; }
.tabla-scroll { max-height: 60vh; overflow-y: auto; }
.custom-swal-container { z-index: 1061 !important; }
</style>
</head>

<body>
<input type="hidden" id="Ses_usuario" value="<?php echo htmlspecialchars($_SESSION["Ses_usuario"] ?? ''); ?>">

<div class="container-fluid">
  <div class="card m-0">
    <div class="card-body">

      <!-- Bodega y toma -->
      <div class="row mt-2 mb-2">
        <div class="col-md-5">
          <label class="m-1">Bodega</label>
          <div class="input-group input-group-sm"> <span class="input-group-text text-primary"><i class="fa-solid fa-map-location-dot"></i></span>
            <select id="bodega" class="form-select form-select-sm"></select>
          </div>
        </div>
        <div class="col-md-3">
          <label class="m-1">Toma</label>
          <div class="input-group input-group-sm"> <span class="input-group-text text-primary"><i class="fa-solid fa-list-ol"></i></span>
            <select id="toma" class="form-select form-select-sm">
              <option value="1">Toma 1</option>
              <option value="2">Toma 2</option>
              <option value="3">Toma 3</option>
            </select>
          </div>
        </div>
      </div>

      <ul class="nav nav-tabs nav-tabs-bordered" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-conteo" type="button" role="tab"><i class="fa-solid fa-barcode m-1"></i><span class="d-none d-sm-inline">Conteo</span></button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="btn-tab-listado" data-bs-toggle="tab" data-bs-target="#tab-listado" type="button" role="tab"><i class="fa-solid fa-list-check m-1"></i><span class="d-none d-sm-inline">Listado</span></button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="btn-tab-ajuste" data-bs-toggle="tab" data-bs-target="#tab-ajuste" type="button" role="tab"><i class="fa-solid fa-scale-balanced m-1"></i><span class="d-none d-sm-inline">Ajuste</span></button>
        </li>
      </ul>

      <div class="tab-content pt-2">

        <!-- ================= CONTEO ================= -->
        <div class="tab-pane fade show active" id="tab-conteo" role="tabpanel">
          <div class="row">
            <div class="col-md-9">
              <!-- form: Enter o el botón ✓/Ir del teclado del celular buscan/seleccionan -->
              <form id="form-busqueda" autocomplete="off">
                <div class="input-group input-group-sm"> <span class="input-group-text text-primary"><i class="ri-search-2-line"></i></span>
                  <input type="search" class="form-control text-primary" id="busqueda" placeholder="Código / Código de barras / Descripción" autocomplete="off" enterkeyhint="search" autofocus />
                </div>
              </form>
            </div>
            <div class="col-md-3 pt-1">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="modo_sumar" checked>
                <label class="form-check-label" for="modo_sumar">Sumar al conteo existente</label>
              </div>
            </div>
          </div>

          <div class="row mt-2" id="panel-producto" style="display:none">
            <div class="col-md-12">
              <div class="border border-primary-subtle rounded p-2 bg-light-subtle shadow-sm">
                <div class="row align-items-end">
                  <div class="col-md-5">
                    <div class="text-muted small" id="p_codigo"></div>
                    <div class="fw-bold" id="p_descripcion"></div>
                    <div class="small">Lotes: <span id="p_lotes"></span>
                      <button type="button" class="btn btn-sm btn-outline-success py-0 ms-1" id="btn-crear-lote-producto" style="display:none"><i class="fa-solid fa-plus"></i> Crear lote</button>
                    </div>
                  </div>
                  <div class="col-4 col-md-2 text-center">
                    <div class="small text-muted">Sistema</div>
                    <div class="fs-5" id="p_stock"></div>
                  </div>
                  <div class="col-4 col-md-2 text-center">
                    <div class="small text-muted">Contado</div>
                    <div class="fs-5 text-primary" id="p_conteo"></div>
                  </div>
                  <div class="col-4 col-md-3">
                    <label class="small" id="lbl_cantidad" for="cantidad">Cantidad a sumar</label>
                    <form id="form-cantidad" autocomplete="off">
                      <input type="number" step="any" inputmode="decimal" enterkeyhint="done" class="form-control" id="cantidad" />
                    </form>
                  </div>
                </div>
                <div class="mt-2 text-end">
                  <button class="btn btn-outline-secondary btn-sm" id="btn-ver-lotes"><i class="fa-solid fa-boxes-stacked"></i> Lotes</button>
                  <button class="btn btn-outline-danger btn-sm" id="btn-limpiar-conteo"><i class="fa-solid fa-eraser"></i> Borrar conteo</button>
                  <button class="btn btn-primary btn-sm" id="btn-guardar-conteo"><i class="ri-save-line"></i> Guardar (Enter)</button>
                </div>
              </div>
            </div>
          </div>

          <div class="row mt-2">
            <div class="col-md-12 table-responsive-md" id="resultados"></div>
          </div>

          <div class="row mt-3">
            <div class="col-md-12">
              <h6><i class="fa-solid fa-clock-rotate-left"></i> Últimos conteos de esta sesión</h6>
              <div class="table-responsive-md tabla-scroll" id="ultimos"></div>
            </div>
          </div>
        </div>

        <!-- ================= LISTADO ================= -->
        <div class="tab-pane fade" id="tab-listado" role="tabpanel">
          <div class="row">
            <div class="col-md-3">
              <select id="estado" class="form-select form-select-sm">
                <option value="todos">Todos</option>
                <option value="contados">Contados</option>
                <option value="pendientes">Pendientes por contar</option>
                <option value="diferencias">Con diferencia</option>
              </select>
            </div>
            <div class="col-md-6">
              <input type="text" class="form-control form-control-sm" id="filtro_listado" placeholder="Filtrar código / descripción" />
            </div>
            <div class="col-md-3">
              <button class="btn btn-outline-primary btn-sm" id="btn-listar"><i class="fa fa-search m-1"></i> Consultar</button>
            </div>
          </div>
          <div class="row mt-2">
            <div class="col-md-12 small" id="resumen-listado"></div>
            <div class="col-md-12 table-responsive-md tabla-scroll" id="listado"></div>
          </div>
        </div>

        <!-- ================= AJUSTE ================= -->
        <div class="tab-pane fade" id="tab-ajuste" role="tabpanel">
          <div class="row">
            <div class="col-md-4">
              <label class="m-1">Documento de entrada (sw 12)</label>
              <select id="tipo_entrada" class="form-select form-select-sm"></select>
            </div>
            <div class="col-md-4">
              <label class="m-1">Documento de salida (sw 11)</label>
              <select id="tipo_salida" class="form-select form-select-sm"></select>
            </div>
            <div class="col-md-4 pt-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="incluir_no_contados">
                <label class="form-check-label" for="incluir_no_contados">Ajustar a <b>cero</b> las referencias marcadas que no se contaron</label>
              </div>
            </div>
          </div>
          <div class="row mt-2">
            <div class="col-md-12">
              <textarea class="form-control form-control-sm" id="notas_ajuste" maxlength="400" placeholder="Notas del ajuste (opcional)"></textarea>
            </div>
          </div>
          <div class="row mt-2">
            <div class="col-md-12">
              <button class="btn btn-outline-primary btn-sm" id="btn-previsualizar"><i class="fa-solid fa-eye"></i> Previsualizar ajuste</button>
              <button class="btn btn-success btn-sm" id="btn-ajustar" disabled><i class="fa-solid fa-check"></i> Generar documentos de ajuste</button>
            </div>
          </div>
          <div class="row mt-2">
            <div class="col-md-12" id="resumen-ajuste"></div>
            <div class="col-md-12 table-responsive-md tabla-scroll" id="preview"></div>
          </div>
          <div class="row mt-3">
            <div class="col-md-12">
              <h6><i class="fa-solid fa-clock-rotate-left"></i> Historial de ajustes</h6>
              <div class="table-responsive-md" id="historial"></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Modal crear lote -->
<div class="modal fade" id="modalCrearLote" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="form-crear-lote" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Crear lote</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-2">Se crea el lote en <b>maestro_lotes</b> y se asocia a la toma de la bodega, para que el ajuste entre por ese lote.</p>
          <div class="row">
            <div class="col-7">
              <label class="small" for="cl_lote">Lote</label>
              <input type="text" class="form-control form-control-sm text-uppercase" id="cl_lote" maxlength="30" required />
            </div>
            <div class="col-5">
              <label class="small" for="cl_vcmto">Vencimiento</label>
              <input type="date" class="form-control form-control-sm" id="cl_vcmto" />
            </div>
          </div>
          <div class="mt-2 small"><b id="cl_cantidad"></b> referencia(s):</div>
          <div class="small border rounded p-1 tabla-scroll" style="max-height:35vh" id="cl_lista"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm" id="cl_guardar"><i class="fa-solid fa-check"></i> Crear lote</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal lotes -->
<div class="modal fade" id="modalLotes" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalLotesTitulo">Lotes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="modalLotesBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script src="../lib/js/jquery.js"></script>
<script src="../resources/jquery/jquery-ui-1.13.2.custom/jquery-ui.js"></script>
<script src="../../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../shared/shared.js?<?php echo rand(); ?>"></script>
<script src="../resources/SweetAlert2/SweetAlert2.js"></script>
<script src="../controllers/toma_fisica.js?<?php echo rand(); ?>"></script>
<script src="../../assets/js/main.js"></script>
</body>
</html>
