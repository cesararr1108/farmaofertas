let salidas = [];
let entradas = [];
let bodega = '';
let enviandoConteo = false; // evita guardar dos veces si se presiona Enter repetido

let codeReader;
let stream;
const modal = document.getElementById('modalLeerCodigo');
// Función para exportar DataTable a Excel .xlsx
const exportarDataTableCSV = (tableId, filename = 'reporte.xlsx', numericColumns = []) => {
	try {
		const tableElement = $(`#${tableId}`);

		if (!tableElement.length) {
			Swal.fire('Error', 'No se encontró la tabla', 'error');
			return;
		}

		const datatableInstance = tableElement.DataTable();

		if (!datatableInstance) {
			Swal.fire('Error', 'No se inicializó DataTable', 'error');
			return;
		}

		// Obtener TODOS los datos (filtrados si hay búsqueda)
		const allData = datatableInstance.rows({ search: 'applied' }).data().toArray();

		if (allData.length === 0) {
			Swal.fire('Advertencia', 'No hay datos para exportar', 'warning');
			return;
		}

		// Obtener encabezados directamente del thead
		const headers = [];
		$(`#${tableId} thead th`).each(function () {
			headers.push($(this).text().trim());
		});

		// Preparar datos para Excel - LEER DIRECTAMENTE DE LAS CELDAS HTML
		const excelData = [];
		excelData.push(headers);

		// Obtener todas las filas visibles del DataTable
		const rows = datatableInstance.rows({ search: 'applied' }).nodes();

		$(rows).each(function (rowIndex) {
			const excelRow = [];

			// Recorrer cada celda TD de la fila
			$(this).find('td').each(function (colIndex) {
				let cellValue = $(this).text().trim();

				// Si la columna está en numericColumns, convertir a número
				if (numericColumns.includes(colIndex)) {
					// Limpiar del formato monetario: remover $ . , y convertir , a .
					let numValue = cellValue
						.replace(/[$]/g, '')           // Remover $
						.replace(/\./g, '')            // Remover puntos (miles)
						.replace(/,/g, '.');           // Reemplazar , por . (decimales)

					numValue = parseFloat(numValue);
					excelRow.push(isNaN(numValue) ? cellValue : numValue);
				} else {
					excelRow.push(cellValue);
				}
			});

			excelData.push(excelRow);
		});

		// Validar que haya datos
		if (excelData.length <= 1) {
			Swal.fire('Advertencia', 'No hay datos para exportar', 'warning');
			return;
		}

		// Crear workbook de Excel con SheetJS
		if (typeof XLSX === 'undefined') {
			Swal.fire('Error', 'Librería SheetJS no cargada', 'error');
			return;
		}

		const ws = XLSX.utils.aoa_to_sheet(excelData);
		const wb = XLSX.utils.book_new();
		XLSX.utils.book_append_sheet(wb, ws, 'Datos');

		// Establecer ancho de columnas
		const colWidths = headers.map(() => ({ wch: 20 }));
		ws['!cols'] = colWidths;

		// Aplicar formatos a las celdas numéricas
		if (ws['!ref']) {
			const range = XLSX.utils.decode_range(ws['!ref']);

			// Encabezados
			for (let C = range.s.c; C <= range.e.c; ++C) {
				const address = XLSX.utils.encode_col(C) + "1";
				if (!ws[address]) continue;
				ws[address].s = {
					font: { bold: true, color: { rgb: "FFFFFF" } },
					fill: { fgColor: { rgb: "4F81BD" } },
					alignment: { horizontal: "center" }
				};
			}

			// Formato numérico a las columnas especificadas
			for (let R = range.s.r + 1; R <= range.e.r; ++R) {
				for (let C = range.s.c; C <= range.e.c; ++C) {
					const address = XLSX.utils.encode_col(C) + (R + 1);
					if (!ws[address]) continue;

					// Si la columna es numérica, aplicar formato de número
					if (numericColumns.includes(C)) {
						ws[address].z = '#,##0.00'; // Formato: 1,234.56
					}
				}
			}
		}

		// Descargar archivo
		XLSX.writeFile(wb, filename);

		Swal.fire('Éxito', `${excelData.length - 1} registros exportados correctamente`, 'success');
	} catch (error) {
		console.error('Error en exportación:', error);
		Swal.fire('Error', 'Error al exportar Excel: ' + error.message, 'error');
	}
};

function Limpiar() {
  $("#lote, #descripcion, #nuevoLoteCodigo, #cantidad_contada").val("").prop("disabled", true);
  $("#codigo, #nuevoLote, #cantidad, #buscar_codigo, #nuevoLoteCodigo").val("");
  $("#lote").html('')
  $("#div_unidades").html('')
  $("#stock_producto").val('')
  $("#aviso_lote").html('')

}

// Verifica si autocomplete está inicializado
function esAutocompleteInicializado(elemento) {
  return $(elemento).data("uiAutocomplete") || $(elemento).data("autocomplete");
}

// Modo Código de Barras (busca solo al presionar Enter)
function activarModoCodigoBarras() {
  Limpiar();
  let $codigoBuscar = $("#buscar_codigo");

  // 🔹 **Eliminar Autocomplete si está activo**
  if (esAutocompleteInicializado($codigoBuscar)) {
    $codigoBuscar.autocomplete("destroy").removeData("uiAutocomplete");
  }

  // 🔹 **Asegurar que el campo tiene foco**
  $codigoBuscar.prop("disabled", false).focus();

  // 🔹 **Eliminar eventos previos**
  $codigoBuscar.off("keydown");

  // 🔹 **Evento de búsqueda por Enter**
  $codigoBuscar.on("keydown", function (e) {
    if (e.key === "Enter") { // Verifica si se presionó "Enter"
      e.preventDefault(); // Evita el comportamiento predeterminado
      Buscar_ean($(this).val()); // Envía el valor del input a la función
      $(this).val(""); // Opcional: Limpia el input después de escanear
    }
  });
}


// Modo Manual (usa Autocomplete)
function activarModoManual() {
  Limpiar();

  $("#buscar_codigo").off("keypress"); // Desvincula la búsqueda por Enter

  // Si autocomplete ya existe, lo destruimos antes de volver a inicializar

  /*
    $("#buscar_codigo").autocomplete({
        source: function (request, response) {
            $.ajax({
                type: "POST",
                url: "../models/Inventario.php",
                dataType: "json",
                data: {
                    term: request.term,
                    op: "Buscar_codigo",
                    bodega: parent.$('#id_bog').val()
                },
                success: function (data) {
                    response($.map(data, function (item) {
                        return {
                            label: item.descripcion + " (" + item.codigo + ")", // Muestra en el dropdown
                            value: item.codigo,  // Valor que se autocompleta
                            codigo: item.codigo,
                            descripcion: item.descripcion,
                            lote: item.lote
                        };
                    }));
                }
            });
        },
        minLength: 3,
        select: function (event, ui) {
            $("#codigo").val(ui.item.codigo);
            $("#descripcion").val(ui.item.descripcion);
            Listar_lotes()
         }
    });*/

}

// Función de ejemplo para búsqueda por código de barras
function Buscar_codigo_barras() {
  let codigo = $("#codigo_buscar").val();
  // Aquí iría la lógica para consultar el backend
}

function Listar_lotes(loteSeleccionado = "") {
  let codigo = $("#codigo").val();

  $.ajax({
    type: "POST",
    encoding: "UTF-8",
    url: "../models/Inventario.php",
    async: true,
    dataType: "json",
    data: {
      op: "Listar_lotes",
      codigo: codigo
    },
    success: function (data) {

      let $selectLote = $("#lote");
      $selectLote.empty(); // Limpiar opciones previas

      if (data.length > 0) {
        // Llenar el select con los lotes disponibles
        data.forEach(function (item) {
          let option = `<option value="${item.id_lote}">${item.lote}</option>`;
          $selectLote.append(option);
        });

        // Si hay un lote a seleccionar, se elige
        if (loteSeleccionado) {
          $selectLote.val(loteSeleccionado);
        }

        $selectLote.prop("disabled", false); // Habilita el select
        Buscar_info_lotes();
        $("#nuevoLoteCodigo").val(codigo); // En caso de querer crear uno nuevo
      } else {
        // Si no hay lotes, mostrar el modal para crear uno nuevo
        $("#nuevoLoteCodigo").val(codigo);
        $("#modalNuevoLote").modal("show");

      }
    }
  }).fail(function (e) {
    console.error(e);
    Swal.fire({
      icon: "error",
      title: "Error de conexión",
      text: "Hubo un problema con la petición.",
      confirmButtonColor: "#d33"
    });
  });
}


function Conteo_actual() {
  let codigo = $("#codigo").val();
  $.ajax({
    type: "POST",
    encoding: "UTF-8",
    url: "../models/Inventario.php",
    async: true,
    dataType: "json",
    data: {
      op: "Listar_lotes",
      codigo: codigo
    },
    success: function (data) {
      let $selectLote = $("#lote");
      $selectLote.empty(); // Limpiar opciones previas

      if (data.length > 0) {
        // Llenar el select con los lotes disponibles
        data.forEach(function (item) {
          let option = `<option value="${item.id_lote}">${item.lote}</option>`;
          $selectLote.append(option);
        });

        // Si hay un lote a seleccionar, se elige
        if (loteSeleccionado) {
          $selectLote.val(loteSeleccionado);
        }

        $selectLote.prop("disabled", false); // Habilita el select
        $("#nuevoLoteCodigo").val(codigo); // En caso de querer crear uno nuevo
      } else {
        // Si no hay lotes, mostrar el modal para crear uno nuevo
        $("#nuevoLoteCodigo").val(codigo);
        $("#modalNuevoLote").modal("show");
      }
    }
  }).fail(function (e) {
    console.error(e)
    Swal.fire({
      icon: "error",
      title: "Error de conexión",
      text: "Hubo un problema con la petición.",
      confirmButtonColor: "#d33"
    });
  });
}

function guardarNuevoLote() {
  let codigo = $("#nuevoLoteCodigo").val();
  let nuevoLote = $("#nuevoLote").val();
  let fechaVencimiento = $("#fechaVencimiento").val();

  if (nuevoLote.trim() === "" || fechaVencimiento.trim() === "") {
    Swal.fire({
      icon: "warning",
      title: "Campos vacíos",
      text: "Debe ingresar un nombre de lote y fecha de vencimiento.",
      confirmButtonColor: "#f39c12"
    });
    return;
  }


  $.ajax({
    type: "POST",
    url: "../models/Inventario.php",
    dataType: "json",
    data: {
      op: "Guardar_nuevo_lote",
      codigo: codigo,
      lote: nuevoLote,
      lote_vcmto: fechaVencimiento
    },
    success: function (response) {
      if (response.ok) {
        Swal.fire({
          icon: "success",
          title: "Lote creado",
          text: response.message,
          confirmButtonColor: "#28a745"
        });

        $("#modalNuevoLote").modal("hide"); // Cerrar modal

        // Recargar la lista y seleccionar el nuevo lote
        Listar_lotes();
        $("#nuevoLoteCodigo").val('')
        $("#fechaVencimiento").val('')
        $("#nuevoLote").val('')

      } else {
        Swal.fire({
          icon: "error",
          title: "Error",
          text: response.message,
          confirmButtonColor: "#d33"
        });
      }
    }
  }).fail(function (e) {
    console.error(e)
    Swal.fire({
      icon: "error",
      title: "Error de conexión",
      text: "No se pudo guardar el nuevo lote.",
      confirmButtonColor: "#d33"
    });
  });
}

function Buscar_info_lotes() {
  let codigo = $("#codigo").val();

  let lote = $("#lote").val();

  $.ajax({
    type: "POST",
    encoding: "UTF-8",
    url: "../models/Inventario.php",
    async: true,
    dataType: "json",
    data: {
      op: "Buscar_info_lotes",
      codigo: codigo,
      bodega: bodega,
      lote: lote
    },
    success: function (data) {

      if (Array.isArray(data) && data.length > 0) { // Verifica si data es un array y no está vacío
        let loteData = data[0]; // Accede al primer objeto del array
        let conteoSeleccionado = $("input[name='conteo']:checked").val();
        let toma_conteo = "0"; // Inicializar en 0 por defecto

        if (conteoSeleccionado == "1") {
          toma_conteo = (loteData.toma_1 !== undefined && loteData.toma_1 !== "") ? loteData.toma_1 : "0";
        } else if (conteoSeleccionado == "2") {
          toma_conteo = (loteData.toma_2 !== undefined && loteData.toma_2 !== "") ? loteData.toma_2 : "0";
        } else if (conteoSeleccionado == "3") {
          toma_conteo = (loteData.toma_3 !== undefined && loteData.toma_3 !== "") ? loteData.toma_3 : "0";
        }

        //console.log("Valor asignado a cantidad_contada:", toma_conteo);
        $("#cantidad_contada").val(toma_conteo);
      } else {
        console.warn("No se encontraron datos, asignando 0");
        $("#cantidad_contada").val("0"); // Asigna 0 si no hay datos
      }
    }
  }).fail(function (e) {
    console.error(e)
    Swal.fire({
      icon: "error",
      title: "Error de conexión",
      text: "Hubo un problema con la petición.",
      confirmButtonColor: "#d33"
    });
  });
}

function Insertar_conteo() {
  if (enviandoConteo) return;
  let codigo = $("#codigo").val().trim();
  //let lote = $("#lote").val().trim();
  let conteoSeleccionado = $("input[name='conteo']:checked").val();
  let cantidad = parseFloat($("#cantidad").val()) || 0;
  let cantidad_contada = parseFloat($("#cantidad_contada").val()) || 0;
  let cantidad_nueva = 0;

  // Validación de campos vacíos
  if (!codigo) {
    Swal.fire("Error", "El campo 'Código' no puede estar vacío.", "warning");
    return;
  }
  if (!bodega) {
    Swal.fire("Error", "El campo 'Bodega' no puede estar vacío.", "warning");
    return;
  }
  if (!conteoSeleccionado) {
    Swal.fire("Error", "Debes seleccionar un conteo.", "warning");
    return;
  }
  if (cantidad <= 0) {
    Swal.fire("Error", "La cantidad debe ser mayor a 0.", "warning");
    return;
  }

  if (cantidad_contada === 0) {
    cantidad_nueva = cantidad; // Si no hay conteo previo, asigna la cantidad ingresada
    EnviarConteo(codigo, bodega, conteoSeleccionado, cantidad_nueva);
  } else {
    Swal.fire({
      title: `Previamente se contaron ${cantidad_contada} de este producto`,
      text: "¿Qué deseas hacer?",
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Sumarle",
      cancelButtonText: "Cancelar",
      showDenyButton: true,
      denyButtonText: "Es un nuevo valor"
    }).then((result) => {
      if (result.isConfirmed) {
        cantidad_nueva = cantidad_contada + cantidad; // Sumar al conteo previo
        EnviarConteo(codigo, bodega, conteoSeleccionado, cantidad_nueva);
      } else if (result.isDenied) {
        cantidad_nueva = cantidad; // Reemplazar con el nuevo valor
        EnviarConteo(codigo, bodega, conteoSeleccionado, cantidad_nueva);
      } else {
        $("#cantidad").focus();
      }
    });
  }

}

function EnviarConteo(codigo, bodega, conteo, cantidad) {
  if (enviandoConteo) return;
  enviandoConteo = true;

  $.ajax({
    type: "POST",
    url: "../models/Inventario.php",
    data: {
      op: "Insertar_conteo",
      codigo: codigo,
      bodega: bodega,
      conteo: conteo,
      cantidad: cantidad
    },
    dataType: "json", // Asegura que la respuesta se interprete como JSON
    success: function (response) {

      if (response.ok) {
        // aviso corto que se cierra solo, para seguir escaneando sin dar clic
        Swal.fire({
          toast: true,
          position: "top-end",
          icon: "success",
          title: `Conteo registrado: ${cantidad}`,
          showConfirmButton: false,
          timer: 1500
        });
        Limpiar();
        $("#buscar_codigo").focus();
      } else {
        Swal.fire({
          icon: "warning",
          title: "Atención",
          text: response.message || "No se pudo registrar el conteo.",
          confirmButtonColor: "#ffc107"
        });
      }
    },
    error: function (xhr, status, error) {
      console.error("Error en la solicitud AJAX:", xhr.responseText);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "Hubo un problema al guardar el conteo. Inténtalo nuevamente.",
        confirmButtonColor: "#d33"
      });
    },
    complete: function () {
      enviandoConteo = false;
    }
  });
}

function Buscar_ean() { // Recibir el parámetro "codigo"
  const codigo = $("#buscar_codigo").val();
  $.ajax({
    type: "POST",
    url: "../models/Inventario.php",
    data: {
      op: "Buscar_ean",
      codigo: codigo // Enviar el código correctamente
    },
    dataType: "json",
    success: function (response) {
      if (response.length > 0) {
        $("#codigo").val(response[0].codigo);
        $("#descripcion").val(response[0].descripcion);
       // Listar_lotes()
      } else {
        Swal.fire({
          icon: "warning",
          title: "No encontrado",
          text: "El código no tiene un EAN asociado.",
          confirmButtonColor: "#ffc107"
        });
      }
    },
    error: function (xhr, status, error) {
      console.error("Error en la solicitud AJAX:", xhr.responseText);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "Hubo un problema al buscar el EAN. Inténtalo nuevamente.",
        confirmButtonColor: "#d33"
      });
    }
  });
}

function obtenerInformeInventario() {

  $("#cargando").show();
  $.ajax({
    type: "POST",
    url: "../models/Inventario.php",
    data: {
      op: "Informe_inventario",
      bodega: bodega
    },
    dataType: "json",
    success: function (response) {
      $("#cargando").hide();
      llenarTabla(response);
    },
    error: function (xhr, status, error) {
      console.error("Error en la solicitud AJAX:", xhr.responseText);
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "Hubo un problema al obtener el informe.",
        confirmButtonColor: "#d33"
      });
    }
  });
}

function llenarTabla(data) {

  $("#reporte_diferencias").show(); // Mostrar la tabla si hay datos
  // Destruir DataTable si ya está inicializado
  if ($.fn.DataTable.isDataTable("#tabla_diferencias")) {
    $("#tabla_diferencias").DataTable().destroy();
  }

  let cuerpo = "";
  data.forEach(item => {

    let faltante = parseFloat(item.faltante);

    // Aplicar colores a "Faltante"
    let colorFaltante = faltante < 0 ? "style='font-weight:bold; color:red;'"
      : faltante > 0 ? "style='font-weight:bold; color:blue;'"
      : "style='font-weight:bold; color:green;'";

    // Aplicar color rojo a "Tomas" si tienen stock pero están en 0
    let colorToma1 = item.stock > 0 && item.toma_1 == 0 ? "style='color:red;'" : "";
    let colorToma2 = item.stock > 0 && item.toma_2 == 0 ? "style='color:red;'" : "";
    let colorToma3 = item.stock > 0 && item.toma_3 == 0 ? "style='color:red;'" : "";

    let total_costo = item.costo * faltante

    let total_venta = Math.round(item.precio_venta * faltante, 0)

    cuerpo += `
            <tr>
                <td>${item.codigo_barras}</td>
                <td>${item.codigo}</td>
                <td>${item.descripcion}</td>
                <td>${item.lab}</td>
                <td>${item.bodega}</td>
                <td>${item.lote}</td>
                <td>${formatearFecha(item.lote_vcmto)}</td>
                <td ${colorToma1} style ="background-color:#FFA694">${item.toma_1}</td>
                <td ${colorToma2} style ="background-color:#94FFC6">${item.toma_2}</td>
                <td ${colorToma3} style ="background-color:#949BFF">${item.toma_3}</td>
                <td>${item.stock}</td>
                <td ${colorFaltante}>${faltante}</td>
                <td>${formatNum(total_costo,'$')}</td>
                <td>${formatNum(total_venta,'$')}</td>
                <td><button onclick="eliminarConteo('${item.codigo}',${item.id_lote},this)" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></td>
            </tr>`;
  });

  $("#tabla_diferencias_body").html(cuerpo);

  // Inicializar DataTable con opciones de exportación
  $("#tabla_diferencias").DataTable({
    dom: 'Bfrtip',
    buttons: [{
      extend: 'excelHtml5',
      text: 'Exportar a Excel',
      className: 'btn btn-success'
    }],
    paging: false,
    searching: false,
    info: false
  });


}

// Función para formatear fecha (Ej: 04/enero/2026)
function formatearFecha(fecha) {
  if (!fecha) return "";
  let opciones = {
    day: "2-digit",
    month: "long",
    year: "numeric"
  };
  return new Date(fecha).toLocaleDateString("es-ES", opciones);
}

const laboratorios = async () => {
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'list-lab'
    });

    let p = '<option value="0">Seleccionar</option>';
    if (resp.length > 0) {
      resp.forEach(item => {
        p += `<option value="${item.nit}">${item.nombres}</option>`
      })
    }
    $("#laboratorios").html(p);
  } catch (e) {
    console.error(e)
  }
}

const genericos = async () => {
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'genericos'
    });
    let p = '<option value="0">Seleccionar</option>';
    if (resp.length > 0) {
      resp.forEach(item => {
        p += `<option value="${item.generico}">${item.descripcion}</option>`
      })
    }
    $("#generico").html(p);
  } catch (error) {
    console.error(error)
  }
}

const clases = async () => {
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'clases'
    });
    let p = '<option value="0">Seleccionar</option>';
    if (resp.length > 0) {
      resp.forEach(item => {
        p += `<option value="${item.clase}">${item.descripcion}</option>`
      })
    }
    $("#clases").html(p);
  } catch (error) {
    console.error(error)
  }
}


const grupos = async () => {
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'grupos'
    });
    let p = '<option value="0">Seleccionar</option>';
    if (resp.length > 0) {
      resp.forEach(item => {
        p += `<option value="${item.grupo}">${item.descripcion}</option>`
      })
    }
    $("#grupos").html(p);
  } catch (error) {
    console.error(error)
  }
}

const subgrupos = async (grupo) => {
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'subgrupos',
      grupo
    });
    let p = '<option value="0">Seleccionar</option>';
    if (resp.length > 0) {
      resp.forEach(item => {
        p += `<option value="${item.subgrupo}">${item.descripcion}</option>`
      })
    }
    $("#sub-grupos").html(p);
  } catch (error) {
    console.error(error)
  }
}


const subgrupos2 = async (grupo, subgrupo) => {
  try {

    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'subgrupos2',
      grupo,
      subgrupo
    });
    let p = '<option value="0">Seleccionar</option>';
    if (resp.length > 0) {
      resp.forEach(item => {
        p += `<option value="${item.subgrupo2}">${item.descripcion}</option>`
      })
    }
    $("#sub-grupos2").html(p);
    $("#sub-grupos2").change(function () {
      subgrupos2($("#sub-grupos").val(), $(this).val());
    })
  } catch (error) {
    console.error(error)
  }
}

const marcarProductos = async () => {
  var con_stock = $("#solo-inventarios").is(":checked") ? 1 : 0;


  showLoadingSwalAlert2('Cargando', 'Por favor espere!')
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'marcar',
      laboratorio: $("#laboratorios").val(),
      generico: $("#generico").val(),
      clase: $("#clases").val(),
      grupo: $("#grupos").val(),
      subgrupo: $("#sub-grupos").val(),
      subgrupo2: $("#sub-grupos2").val(),
      con_stock: con_stock,
      bodega: $("#bodega").val(),
      codigo: $("#codigo-material").val()

    });
    dissminSwal();


    Swal.fire("Ok", resp.message, "success")
    $("#laboratorios").val('0')
    $("#generico").val('0')
    $("#clases").val('0')
    $("#grupos").val('0')
    $("#sub-grupos").val('0');
    $("#codigo-material").val('0');
    $("#solo-inventarios").attr("checked", false)


  } catch (error) {
    console.error(error)
    dissminSwal();
  }

}


function abrirCamara() {
 $("#modalLeerCodigo").modal("show");

  document.getElementById('scanner').style.display = 'block';
  codeReader = new ZXing.BrowserBarcodeReader();
  $("#modalLeerCodigo").modal("show")
  codeReader.decodeFromVideoDevice(null, 'video', (result, err) => {
    if (result) {
      document.getElementById('buscar_codigo').value = result.text;
      cerrarCamara();
      getDataProduct($("#buscar_codigo").val(),'codigo_barras');
    }
  });
}

function cerrarCamara() {

  document.getElementById('scanner').style.display = 'none';

  if (codeReader) {
    codeReader.reset();
    $("#modalLeerCodigo").modal("hide");

  }
}

const getDataProduct = async (term,buscar_por) => {

   $("#list-productos").html('');
   $("#modalListProductos").modal("hide")

  if (codigo.length == 0) {
    return;
  }
  try {
     showLoadingSwalAlert2('Cargando producto', 'Por favor espere!')
  let resp = await enviarPeticion({
    link: '../models/Inventario.php',
    op: 'Buscar_codigo',
    conteo: $("input[name='conteo']:checked").val(),
    term,
    buscar_por
  });
  console.log({resp})

  if(resp.length ==0){
    return;
  }
  if (resp.data.length == 0) {
    dissminSwal()
    $("#buscar_codigo").val('').focus()
    Swal.fire("Ups", resp.message, "warning")
    Limpiar();
    return;
  }

  if(!resp.ok){
    dissminSwal();
     Swal.fire("Ups",resp.message,"warning");
   return;
  }


  if(resp.data.length>1){
     let tab = `<table class="table table-sm"  >
                  <thead>
                    <tr>
                      <th>Codigo</th>
                      <th>Descripcion</th>
                      <th>Lab</th>
                      <th>Mesa de ayuda</th>
                    </tr>
                  </thead>
                  <tbody>`
    resp.data.forEach(item=>{
      console.log({
        item
      })
      let mesa ='';
       if(item.alerta>0){
         mesa = `<i class="fa-solid fa-circle-exclamation text-danger fa-2x text-center"></i>`;
       }

        tab+=`<tr onclick="getDataProduct('${item.codigo}','codigo')">
                  <td>${item.codigo}</td>
                  <td>${item.descripcion}</td>
                  <td>${item.laboratorio}</td>
                  <th>${mesa}</th>
              </tr>`
     })

     tab+=`</tbody>
     </table>`
     $("#list-productos").html(tab);
     $("#modalListProductos").modal("show")
     dissminSwal();
    return;
  }

  resp = resp.data;
  console.error({
    resp
  })
  const unidades = [{
      und: resp[0].und_1,
      can: resp[0].can_1
    },
    {
      und: resp[0].und_2,
      can: resp[0].can_2
    },
    {
      und: resp[0].und_3,
      can: resp[0].can_3
    },
  ];

  let undhtml = `<table class="table table-sm">
                        <tr>
                            <th>Unidad</th>
                            <th>Conversión</th>
                        </tr>`;

  unidades.forEach(unidad => {
    undhtml += `<tr>
                        <td>${unidad.und}</td>
                        <td>${unidad.can}</td>
                    </tr>`;
  });

  undhtml += `</table>`; // Cierra la tabla correctamente.

  $("#div_unidades").html(undhtml); // Coloca la tabla generada en el div.

  $("#codigo").val(resp[0].codigo);
  $("#descripcion").val(resp[0].codigo+'-'+resp[0].descripcion);
  $("#stock_producto").val(resp[0].stock)
  $("#cantidad_contada").val(resp[0].conteo);
  $("#buscar_codigo").val('');

  // producto de la toma sin lotes: se puede contar, pero para ajustarlo necesita un lote
  if (Number(resp[0].n_lotes) === 0) {
    const prod = { codigo: resp[0].codigo, descripcion: resp[0].descripcion };
    $("#aviso_lote").html(`<div class="alert alert-warning py-1 px-2 mb-0">
        Este producto no tiene lotes en la toma; para ajustarlo hay que crearle uno.
        <button type="button" class="btn btn-sm btn-success py-0 ms-2" id="btn-crear-lote-producto"><i class="fas fa-plus"></i> Crear lote</button>
      </div>`);
    $("#btn-crear-lote-producto").on("click", () => abrirCrearLotes([prod], () => {
      $("#aviso_lote").html(`<div class="alert alert-success py-1 px-2 mb-0">Lote creado para ${escHtml(prod.codigo)}.</div>`);
    }));
  } else {
    $("#aviso_lote").html('');
  }
 // Listar_lotes()
  dissminSwal()
  setTimeout(function () {
    $("#cantidad").focus();
  }, 400)


  return;
  } catch (error) {
      console.error({error})
  }

}

//tipos de documentos
const tiposDoc = async (sw, bodega, id) => {
  try {

    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'tipos',
      sw,
      bodega
    });

    if (resp.length == 0) {
      return;
    }
    p = '<option value="">Seleccione</option>';
    resp.forEach(element => {
      p += `<option value="${element.tipo}">${element.tipo} - ${element.descripcion}</option>`
    });
    $("#" + id).html(p);
  } catch (e) {
    console.error(e)
  }
}

const filtroEstados = (valor) => {
  if (valor == '') {
    $("#tabla-informe-ajuste tbody tr").show()
    return;
  }
  $("#tabla-informe-ajuste tbody tr").each(function () {
    $(this).toggle($(this).attr("data-proceso") == valor);
  })
}

// ---------------------------------------------------------------------------
// Ajuste por lotes / avance
// ---------------------------------------------------------------------------
let filasInforme = []; // filas del último informe previo

const escHtml = (t) => String(t ?? '').replace(/[&<>"']/g, c => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

// 'YYYY-MM-DD ...' -> 'DD/MM/YYYY' (sin pasar por Date para no correr el día por zona horaria)
const fechaCorta = (f) => {
  if (!f) return '';
  const [y, m, d] = String(f).substring(0, 10).split('-');
  return `${d}/${m}/${y}`;
}

// Detalle de cuánto se mueve en cada lote
const htmlLotes = (lotes) => {
  return (lotes || []).filter(l => Number(l.ajuste) !== 0).map(l => {
    const aj = Number(l.ajuste);
    return `<div class="text-nowrap">
              ${escHtml(l.lote ?? l.id_lote)}
              <small class="text-muted">(vence ${fechaCorta(l.lote_vcmto)} · stock ${Number(l.stock)})</small>
              <b class="${aj > 0 ? 'text-success' : 'text-danger'}">${aj > 0 ? '+' : ''}${aj}</b>
            </div>`;
  }).join('');
}

// ---------------------------------------------------------------------------
// Crear lote a productos contados que no tienen lotes en la toma
// ---------------------------------------------------------------------------
let crearLotesItems = [];
let crearLotesDespues = null;

// fecha local yyyy-mm-dd sumando años
const fechaHoyMas = (anios) => {
  const d = new Date();
  d.setFullYear(d.getFullYear() + anios);
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

const abrirCrearLotes = (items, despues) => {
  if (!items || items.length == 0) {
    Swal.fire("Info", "No hay productos sin lote", "info");
    return;
  }
  crearLotesItems = items;
  crearLotesDespues = despues || null;
  if ($.trim($("#cl_lote").val()) == '') $("#cl_lote").val('TF' + fechaHoyMas(0).replace(/-/g, ''));
  if ($("#cl_vcmto").val() == '') $("#cl_vcmto").val(fechaHoyMas(1));
  $("#cl_cantidad").text(items.length);
  $("#cl_lista").html(items.map(p => `${escHtml(p.codigo)} - ${escHtml(p.descripcion)}`).join('<br>'));
  $("#modalCrearLotes").modal("show");
}

const guardarCrearLotes = async () => {
  const lote = $.trim($("#cl_lote").val()).toUpperCase();
  if (lote == '') {
    Swal.fire("Ups", "Digite el nombre del lote", "warning");
    return;
  }
  $("#cl_guardar").prop("disabled", true);
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'crear-lotes',
      bodega,
      codigos: JSON.stringify(crearLotesItems.map(p => String(p.codigo).trim())),
      lote,
      lote_vcmto: $("#cl_vcmto").val()
    });
    if (!resp.ok) {
      Swal.fire("Error", resp.message || "No se pudo crear el lote", "error");
      return;
    }
    $("#modalCrearLotes").modal("hide");
    let msg = `Lote <b>${escHtml(resp.lote)}</b> creado para <b>${resp.creados.length}</b> producto(s).`;
    if (resp.omitidos.length) {
      msg += '<br><br>Omitidos:<br>' + resp.omitidos.map(o => `${escHtml(o.codigo)}: ${escHtml(o.motivo)}`).join('<br>');
    }
    await Swal.fire({ icon: resp.omitidos.length ? 'warning' : 'success', title: 'Crear lote', html: msg });
    if (crearLotesDespues) crearLotesDespues();
  } catch (error) {
    console.error(error);
    Swal.fire("Error", "Error al crear el lote", "error");
  } finally {
    $("#cl_guardar").prop("disabled", false);
  }
}

// aviso en el ajuste con los productos contados que no tienen lote
const pintarAvisoSinLote = (filas) => {
  const sinLote = filas.filter(f => f.proceso == 'sin lote');
  if (sinLote.length == 0) {
    $("#aviso-sin-lote").html('');
    return;
  }
  $("#aviso-sin-lote").html(`<div class="alert alert-warning py-2 mb-0">
      <b>${sinLote.length}</b> producto(s) contados no tienen lotes en la toma y no se pueden ajustar.
      <button type="button" class="btn btn-sm btn-success ms-2" id="btn-crear-lotes-ajuste"><i class="fas fa-plus"></i> Crear lote a estos productos</button>
    </div>`);
  $("#btn-crear-lotes-ajuste").on("click", () => abrirCrearLotes(sinLote, () => $("#informe-previo").trigger("click")));
}

const cargarAvance = async () => {
  showLoadingSwalAlert2('Cargando avance', 'Por favor espere!')
  try {
    const resp = await enviarPeticion({
      link: '../models/Inventario.php',
      op: 'avance-inventario',
      bodega,
      conteo: $("#avance-conteo").val()
    });
    dissminSwal();
    if (!resp.ok) {
      Swal.fire("Error", resp.message || "No se pudo cargar el avance", "error");
      return;
    }

    const r = resp.resumen;
    const total = Number(r.referencias) || 0;
    const pct = (n, d) => d > 0 ? Math.round(Number(n) * 1000 / d) / 10 : 0;
    const barra = (titulo, n, d, color) => {
      const p = pct(n, d);
      return `<div class="col-md-4 mb-3">
                <div class="card h-100"><div class="card-body">
                  <div class="d-flex justify-content-between"><b>${titulo}</b><span>${Number(n)} / ${d} (${p}%)</span></div>
                  <div class="progress mt-2" style="height:20px">
                    <div class="progress-bar ${color}" style="width:${p}%">${p}%</div>
                  </div>
                </div></div>
              </div>`;
    };

    $("#avance-resumen").html(`
      <div class="row">
        ${barra('Conteo 1', r.contadas_1, total, 'bg-primary')}
        ${barra('Conteo 2', r.contadas_2, total, 'bg-info')}
        ${barra('Conteo 3', r.contadas_3, total, 'bg-warning')}
        ${barra('Referencias contadas (cualquier conteo)', r.contadas, total, 'bg-success')}
        ${barra('Referencias con stock contadas', r.con_stock_contadas, Number(r.con_stock) || 0, 'bg-success')}
        <div class="col-md-4 mb-3">
          <div class="card h-100"><div class="card-body">
            <b>Unidades</b>
            <div>Stock sistema: ${formatNum(Number(r.unidades_stock), '')}</div>
            <div>Contadas: ${formatNum(Number(r.unidades_contadas), '')}</div>
          </div></div>
        </div>
      </div>`);

    let tu = `<table class="table table-sm table-hover" id="tabla-avance-usuarios">
                <thead><tr><th>Conteo</th><th>Usuario</th><th>Referencias</th><th>% del total</th><th>Unidades</th><th>Última actividad</th></tr></thead><tbody>`;
    resp.usuarios.forEach(u => {
      tu += `<tr>
               <td>${u.toma}</td>
               <td>${escHtml(u.usuario)}</td>
               <td>${u.referencias}</td>
               <td>${pct(u.referencias, total)}%</td>
               <td>${Number(u.unidades)}</td>
               <td>${escHtml(u.ultima ?? '')}</td>
             </tr>`;
    });
    tu += `</tbody></table>`;
    $("#avance-usuarios").html(resp.usuarios.length ? tu : '<div class="text-muted">Aún no hay conteos registrados.</div>');

    let tp = `<table class="table table-sm table-hover" id="tabla-avance-pendientes">
                <thead><tr><th>Código</th><th>Descripción</th><th>Stock</th></tr></thead><tbody>`;
    resp.pendientes.forEach(p => {
      tp += `<tr><td>${escHtml(p.codigo)}</td><td>${escHtml(p.descripcion)}</td><td>${Number(p.stock)}</td></tr>`;
    });
    tp += `</tbody></table>`;
    $("#avance-pendientes-titulo").text(`Pendientes del conteo ${$("#avance-conteo").val()} (${resp.pendientes.length})`);
    $("#avance-pendientes").html(tp);
    $('#tabla-avance-pendientes').DataTable({
      destroy: true,
      paging: true,
      pageLength: 100,
      language: { sSearch: "Buscar:", sLengthMenu: "", sZeroRecords: "Sin pendientes", sEmptyTable: "Sin pendientes",
        sInfo: "_TOTAL_ registros", sInfoEmpty: "0 registros", sInfoFiltered: "(de _MAX_)",
        oPaginate: { sNext: "Siguiente", sPrevious: "Anterior" } }
    });
  } catch (error) {
    dissminSwal();
    console.error(error);
    Swal.fire("Error", "Error al cargar el avance", "error");
  }
}

const eliminarConteo = async (codigo,ob) =>{
  // pregunto si quiere eliminar
  Swal.fire({
    title: '¿Desea eliminar el conteo?',
    text: "Esta acción no se puede deshacer",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#3085d6',
    cancelButtonColor: '#d33',
    confirmButtonText: 'Sí, eliminar',
    cancelButtonText: 'No, cancelar'
  }).then(async (result) => {
    if (result.isConfirmed) {
      try {
        const resp = await enviarPeticion({
          link: '../models/Inventario.php',
          op: 'eliminarConteo',
          codigo,
          bodega :$('#bodega').val()
        });
        if (!resp.ok) {
          Swal.fire("Error", resp.message, "error")
          return;
        }
        Swal.fire("Eliminado", resp.message, "success")
        // Eliminar la fila de la tabla
        $(ob).closest("tr").remove();
      } catch (error) {
        console.error(error)
        Swal.fire("Error", "Ocurrió un error al eliminar el conteo", "error")
      }
    }
  })
}

$(function () {
  bodega = $('#bodega').val();
  if ($("#rol").val() != 1 && $("#rol").val() != 17) {
    $(".card-informe-diferencias").remove();
    $("#config-tab-pane,#profile-tab-pane").remove()
    $(".li-ajustes,.li-marcado,.li-avance").remove()
    $("#avance-tab-pane").remove()
  }

  laboratorios();
  genericos();
  clases();
  grupos();

  tiposDoc(11, bodega, 'salidas');
  tiposDoc(12, bodega, 'entradas');


  modal.addEventListener('hidden.bs.modal', () => {
    cerrarCamara();
  });

  $("#grupos").change(function () {
    subgrupos($(this).val());
  });

  $("#marcar-productos").click(function () {

    const resp = enviarPeticion({
      link: '../models/Inventario.php',
      op: 'validar-inv',
      bodega: bodega
    });

    if (resp.length > 0) {
      Swal.fire({
        title: "Datos de inventario encontrados",
        text: "Existen registros de toma fisica de en la bodega actual , si continua los datos se perderan. Desea continuar?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: "Yes, delete it!"
      }).then((result) => {
        if (result.isConfirmed) {

        }
      });
    } else {
      marcarProductos();
    }

  })

  $("#busqueda-productos").keyup(async function (e) {
    const datos = $.trim($(this).val());

    if (datos.length == 0) {
      $("#codigo-material").val('0')
      return;
    }

    if (e.key == 'Enter') {
      try {
        const resp = await enviarPeticion({
          link: '../models/Inventario.php',
          op: 'busqueda-productos-item',
          datos
        });

        tb = `<table class="table table-sm table-hover"  >
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Descripción</th>
                                <th>Laboratorio</th>
                            </tr>
                        </thead>
                        <tbody>`;
        resp.forEach(item => {
          tb += `<tr data-element='${JSON.stringify(item)}'>
                           <td>${item.codigo}</td>
                           <td>${item.descripcion}</td>
                           <td>${item.lab}</td>
                        </tr>`
        })
        tb += `</tbody>
                </table>`
        $("#result-productos").html(tb)

        $("#result-productos table tbody tr").click(function () {
          const data = JSON.parse($(this).attr("data-element"));

          $("#busqueda-productos").val(data.descripcion + ' ' + data.lab)
          $("#stock_producto").val(data.stock)
          $("#modalBusquedaProductos").modal("hide")
          $("#result-productos").html('')

        })

        $("#modalBusquedaProductos").modal("show")

      } catch (error) {
        console.error(error)
        Swal.fire("Error", "Error al buscar el producto!", "error")
      }

    }
  })

  $("#buscar_codigo").focus();
  $("#lote").select2({
    width: '100%'
  });
  $("#cargando").hide();
  $("#reporte_diferencias").hide(); // Mostrar la tabla si hay datos

  $("#buscar_codigo").keyup(async function (e) {
    e.preventDefault();

    const data = $.trim($(this).val());
    if (data.length == 0) {
      Limpiar();
    }

    try {
      if (e.key == 'Enter') {
          const term = $.trim($("#buscar_codigo").val());
         getDataProduct(term,'');
         /* const productos = await enviarPeticion({
             link: '../models/Inventario.php',
             op:''
          });*/
      }

    } catch (error) {
      console.error(error)
    }

  })

  // Enter en la cantidad agrega el conteo (igual que el botón del chulito)
  $("#cantidad").on("keydown", function (e) {
    if (e.key === "Enter" || e.keyCode === 13) {
      e.preventDefault();
      Insertar_conteo();
    }
  });

  // Enter en el modal de crear lote guarda
  $("#cl_lote, #cl_vcmto").on("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      guardarCrearLotes();
    }
  });
  $("#modalCrearLotes").on("shown.bs.modal", () => $("#cl_lote").focus().select());


  // Evento para detectar cambios en el checkbox
  /* $('#cbarras').change(function () {
       if ($(this).is(':checked')) {
           activarModoCodigoBarras();
       } else {
           activarModoManual();
       }
   });*/

  // Asigna el evento al botón con id "informe_dif"
  $("#informe_dif").click(function () {
    obtenerInformeInventario();
  });
  // Inicializar según el estado actual del checkbox
  /* if ($('#cbarras').is(':checked')) {
       activarModoCodigoBarras();
   } else {
       activarModoManual();
   }
   */
  Limpiar();

  $("#lote").on("change", function () {
    Buscar_info_lotes();
  });

  // 🔹 Detectar cambio en los radio buttons de conteo
  $('input[name="conteo"]').change(function () {
    Limpiar(); // ✅ Se ejecuta cada vez que cambian los radios
  });

  $("#informe-previo").click(async function () {
    showLoadingSwalAlert2('Cargando informacion', 'Por favor espere!')
    try {
      const resp = await enviarPeticion({
        link: '../models/Inventario.php',
        op: 'generar-info-es',
        bodega,
        mostrar_resultados: $("#mostrar_resultados").val(),
        orden_entrada: $("#orden-entrada").val(),
        orden_salida: $("#orden-salida").val()
      });

      if (!Array.isArray(resp)) {
        dissminSwal();
        Swal.fire("Error", resp.message || "Error al generar el reporte", "error")
        return;
      }
      filasInforme = resp;

      let tab = `<table class="table table-sm" id="tabla-informe-ajuste">
                        <thead>
                            <th>#</th>
                            <th>Ean</th>
                            <th>Codigo</th>
                            <th>Descripcion</th>
                            <th>Stock</th>
                            <th>Toma 1</th>
                            <th>Toma 2</th>
                            <th>Toma 3</th>
                            <th>Conteo</th>
                            <th>Proceso</th>
                            <th>Cant ajustar</th>
                            <th>Costo</th>
                            <th>Cst total ajuste</th>
                            <th>Costo inv.</th>
                            <th>Precio v</th>
                            <th>P.V. total</th>
                            <th>Ajuste por lote</th>
                            <th>Eliminar</th>
                        </thead>
                        <tbody>`

      let cst_entradas = 0;
      let cst_salidas = 0;


      // solo lo que tiene cantidad por ajustar (algún lote se mueve) o está contado sin lote
      const nuevo_array = resp.filter(item => Number(item.entra) > 0 || Number(item.sale) > 0 || item.proceso == 'sin lote');
      nuevo_array.forEach((element, dx) => {
        const clases = { '--': 'table-info', 'entrada': 'table-success', 'salida': 'table-danger', 'sin lote': 'table-warning' };
        const text_color = clases[element.proceso] || '';
        const costo = Number(element.costo) || 0;
        const stock = Number(element.stock) || 0;
        const cant_ajuste = Number(element.cant_ajuste) || 0;

        // los costos salen de lo que realmente se mueve en cada lote
        cst_entradas += costo * Number(element.entra);
        cst_salidas += costo * Number(element.sale);

        const detalleLotes = element.proceso == 'sin lote'
          ? '<span class="text-danger">Sin lotes en la toma</span>'
          : htmlLotes(element.lotes);

        tab += `<tr data-proceso="${escHtml(element.proceso)}">
                            <td>${(dx + 1)}</td>
                            <td>${escHtml(element.cod_barras)}</td>
                            <td>${escHtml(element.codigo)}</td>
                            <td>${escHtml(element.descripcion)}</td>
                            <td style ="background-color:#FFFD94">${stock}</td>
                            <td style ="background-color:#FFA694">${Number(element.toma_1)}</td>
                            <td style ="background-color:#94FFC6">${Number(element.toma_2)}</td>
                            <td style ="background-color:#949BFF">${Number(element.toma_3)}</td>
                            <td>${Number(element.conteo)}</td>
                            <td class="${text_color}">${escHtml(element.proceso)}</td>
                            <td>${cant_ajuste}</td>
                            <td>${formatNum(costo, '$')}</td>
                            <td>${formatNum(costo * cant_ajuste, '$')}</td>
                            <td>${formatNum(stock * costo, '$')}</td>
                            <td>${formatNum(element.precio, '$')}</td>
                            <td>${formatNum(element.precio * cant_ajuste, '$')}</td>
                            <td>${detalleLotes}</td>
                            <td><button class="btn btn-outline-danger" onclick="eliminarConteo('${escHtml(element.codigo)}',this)"><i class="fas fa-trash-alt"></i></button></td>
                        </tr>`
      });
      tab += `</tbody>
                </table>`

      $("#result").html(tab)
      $("#costo-entradas").val(formatNum(cst_entradas, '$'));
      $("#costo-salidas").val(formatNum(cst_salidas, '$'));
      pintarAvisoSinLote(resp);

      // Inicializar DataTable con paginación de 300
      $('#tabla-informe-ajuste').DataTable({
        destroy: true,
        paging: true,
        pageLength: 300,
        ordering: true,
        searching: true,
        language: {
          "sProcessing": "Procesando...",
          "sLengthMenu": "",
          "sZeroRecords": "No se encontraron resultados",
          "sEmptyTable": "No hay productos con cantidad por ajustar",
          "sInfo": "Mostrando registros del _START_ al _END_ de un total de _TOTAL_ registros",
          "sInfoEmpty": "Mostrando registros del 0 al 0 de un total de 0 registros",
          "sInfoFiltered": "(filtrado de un total de _MAX_ registros)",
          "sSearch": "Buscar:",
          "oPaginate": {
            "sFirst": "Primero",
            "sLast": "Último",
            "sNext": "Siguiente",
            "sPrevious": "Anterior"
          }
        }
      });

      dissminSwal();

    } catch (error) {
      dissminSwal();
      Swal.fire("Error", "Error al generar el reporte", "error")
      console.error(error)
    }

  });

  // si cambia el criterio de lotes, el informe mostrado ya no corresponde
  $("#orden-entrada, #orden-salida").change(function () {
    if ($.trim($("#result").html()) != '') {
      $("#result").html('');
      $("#aviso-sin-lote").html('');
      filasInforme = [];
      $("#costo-entradas, #costo-salidas").val('$0');
      Swal.fire("Info", "Cambió el criterio de lotes, vuelva a generar el informe previo", "info");
    }
  });

  $("#avance-consultar").click(cargarAvance);
  $("#avance-conteo").change(cargarAvance);

  $("#exportar-informe").click(function () {
    if ($.trim($("#result").html()) == '') {
      return;
    }
    // Exportar usando DataTable a Excel con formato numérico
    const today = new Date().toISOString().slice(0, 10);
    const filename = `Informe-Ajustes-Inventarios_${today}.xlsx`;

    // Columnas que contienen números y deben exportarse como valores numéricos
    // Stock(4), Toma1(5), Toma2(6), Toma3(7), Conteo(8), CantAjustar(10), Costo(11), CstTotal(12), PrecioV(13), PVTotal(14)
    const numericColumns = [4, 5, 6, 7, 8, 10, 11, 12, 13, 14];

    exportarDataTableCSV('tabla-informe-ajuste', filename, numericColumns);
  });

  $("#ajustar-inventario").click(async function () {

    if ($.trim($("#result").html()) == '' || filasInforme.length == 0) {
      Swal.fire("Ups", "Debe generar el informe primero", "info")
      return;
    }

    const tipo_doc_entrada = $.trim($("#entradas").val());
    const tipo_doc_salida = $.trim($("#salidas").val());

    if (tipo_doc_entrada == '') {
      Swal.fire("Ups", "Debe seleccionar un tipo de documento de entrada", "info")
      return;
    }
    if (tipo_doc_salida == '') {
      Swal.fire("Ups", "Debe seleccionar un tipo de documento de salida", "info")
      return;
    }

    const sinLote = filasInforme.filter(f => f.proceso == 'sin lote');
    if (sinLote.length > 0) {
      const r = await Swal.fire({
        icon: "warning",
        title: "Productos sin lote",
        html: "Estos productos tienen conteo pero no tienen lotes en la toma: " +
          sinLote.map(f => escHtml(f.codigo)).join(', ') + "<br><br>¿Desea crearles un lote ahora?",
        showCancelButton: true,
        confirmButtonText: "Crear lote",
        cancelButtonText: "Cancelar"
      });
      if (r.isConfirmed) abrirCrearLotes(sinLote, () => $("#informe-previo").trigger("click"));
      return;
    }

    const lineas = filasInforme.reduce((n, f) => n + (f.lotes || []).filter(l => Number(l.ajuste) != 0).length, 0);
    const conf = await Swal.fire({
      title: '¿Generar el ajuste?',
      icon: 'warning',
      html: `Entradas: <b>${$("#costo-entradas").val()}</b> &nbsp; Salidas: <b>${$("#costo-salidas").val()}</b><br>
             ${lineas} movimientos por lote.<br><br>
             <small>El ajuste se recalcula con los conteos actuales de <b>todas</b> las referencias marcadas
             de la bodega (sin importar el filtro del informe) y se cierra la toma física.</small>`,
      showCancelButton: true,
      confirmButtonText: 'Sí, generar',
      cancelButtonText: 'Cancelar'
    });
    if (!conf.isConfirmed) return;

    showLoadingSwalAlert2('Generando ajuste', 'Por favor espere!')
    try {
      const resp = await enviarPeticion({
        link: '../models/Inventario.php',
        op: 'hacer-ajuste',
        bodega,
        tipo_doc_entrada,
        tipo_doc_salida,
        orden_entrada: $("#orden-entrada").val(),
        orden_salida: $("#orden-salida").val()
      });

      dissminSwal()
      if (!resp.ok) {
        console.log({ resp })
        Swal.fire("Error", resp.message || "Error al guardar el registro", "error")
        return;
      }
      Swal.fire({
        title: 'Ok',
        icon: 'success',
        html: ` Se guardo correctamente el ajuste : <br>  Entrada numero ${resp.entrada || '—'} , Numero de salida ${resp.salida || '—'}`
      })
      filasInforme = [];
      $("#result").html("");
      $("#aviso-sin-lote").html("");
      $("#entradas").val('')
      $("#salidas").val('')
      $("#costo-entradas").val('$0')
      $("#costo-salidas").val('$0')
      $("#estados-resultados").val('')

    } catch (error) {
      console.error(error)
      dissminSwal();
      Swal.fire("Error", "Error al guardar el registro", "error")
    }

  })


});
