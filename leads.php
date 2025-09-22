<?php
  $cookie = urldecode($_COOKIE['start_end2'] ?? '');
  $default = date('Y-01-01') . ' to ' . date('Y-12-31');
  $start_end_value = preg_match('/^\d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2}$/', $cookie) ? $cookie : $default;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Lead Download Report</title>

  <!-- Bootstrap CSS -->
  <link href="<?php echo Wo_LoadManageLink('assets/css/bootstrap.min.css') . '?version=' . $wo['config']['version']; ?>" rel="stylesheet">

  <!-- Flatpickr -->
  <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">

  <!-- DataTables + Buttons CSS -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.3/css/buttons.bootstrap5.min.css" rel="stylesheet">

  <!-- Boxicons (for icons like 'bx') -->
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

  <style>
    .dataTables_wrapper { overflow-x: auto; }
    #dataSheet_download .btn { margin-left: 5px; }
    #invoiceTable_filter {display: none !important;}
    .flatpickr-months, .flatpickr-innerContainer {display: none !important;}
    .flatpickr-calendar.rangeMode {
        display: flex;
        flex-direction: column;
        gap: 15px;
        padding: 20px;
    }
    #date_start_end {
        color: #8d8d8d;
        -webkit-text-security: circle;
    }
  </style>
</head>
<body class="p-4">

  <div class="mb-3 d-flex justify-content-between">
    <input type="hidden" id="user_id" value="999">
    <input type="text" id="date_start_end" class="form-control w-auto" readonly value="<?php echo $start_end_value; ?>">
    <div id="dataSheet_download"></div>
  </div>

  <div class="table-responsive">
    <table id="invoiceTable" class="table table-striped table-bordered">
      <thead>
        <tr>
          <th>Date</th><th>Name</th><th>Phone</th><th>Katha</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <!-- Scripts -->
  <script src="<?php echo Wo_LoadManageLink('assets/js/jquery.min.js') . '?version=' . $wo['config']['version']; ?>"></script>

  <!-- Flatpickr + Plugins -->
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>

  <!-- DataTables + Buttons + dependencies -->
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/buttons.bootstrap5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/buttons.html5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.3/js/buttons.print.min.js"></script>

  <script>
    function Wo_Ajax_Requests_File() {
      return "<?php echo $wo['config']['site_url'] . '/requests.php'; ?>";
    }
    if (typeof pos4_error_noti !== 'function') pos4_error_noti = m => alert("❌ "+m);
    if (typeof pos5_success_noti !== 'function') pos5_success_noti = m => alert("✅ "+m);

    $(function() {
      flatpickr('#date_start_end', {
        mode: 'range',
        dateFormat: 'Y-m-d',
        onReady: (_,__,inst) => {
          const ranges = {
            'This Month': [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()],
            'Last Month': [new Date(new Date().getFullYear(), new Date().getMonth() -1, 1), new Date(new Date().getFullYear(), new Date().getMonth(), 0)],
            'This Year': [new Date(new Date().getFullYear(),0,1), new Date()]
          };
          Object.keys(ranges).forEach(lbl => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.textContent = lbl;
            btn.onclick = () => {
              inst.setDate(ranges[lbl]);
              inst.close();
              table.ajax.reload();
            };
            inst.calendarContainer.prepend(btn);
          });
        }
      });

      const table = $('#invoiceTable').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 25,
        dom: 'Bfrtip',
        buttons: [
          { extend: 'copyHtml5', text: '<i class="bx bx-copy"></i> Copy' },
          { extend: 'excelHtml5', text: '<i class="bx bxs-file-blank"></i> Excel' },
          { extend: 'pdfHtml5', text: '<i class="bx bxs-file-pdf"></i> PDF' },
          { extend: 'print', text: '<i class="bx bx-printer"></i> Print' }
        ],
        ajax: {
          url: Wo_Ajax_Requests_File() + '?f=fetch_dl',
          type: 'POST',
          data: d => {
            const [start, end] = $('#date_start_end').val().split(' to ');
            d.user_id = $('#user_id').val();
            d.data_start = start;
            d.data_end = end || start;
          },
          beforeSend: () => $('#invoiceTable').css('opacity', .5),
          complete: () => $('#invoiceTable').css('opacity', 1)
        },
        columns: [
          { data: 'created' },
          { data: 'name' },
          { data: 'phone' },
          { data: 'katha' }
        ],
        language: {
          emptyTable: "No data available",
          zeroRecords: "No matching records"
        },
      });

      table.buttons().container().appendTo('#dataSheet_download');

      window.DataTableReset = () => table.ajax.reload();
      window.DataTableRefresh = () => table.ajax.reload(null,false);
    });
  </script>
</body>
</html>
