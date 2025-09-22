// View Client JavaScript Functions
let currentPurchaseId = null;
let currentClientId = null;

// Initialize modal when shown
$('#viewClient-modal').on('shown.bs.modal', function() {
  currentClientId = $(this).data('client-id');
  
  // Initialize Select2 for nominees
  $('#purchaseNominees').select2({
    dropdownParent: $('#newPurchaseModal'),
    placeholder: 'Select nominees...',
    allowClear: true
  });
});

// Open Purchase Modal
function openPurchaseModal() {
  $('#purchaseClientId').val(currentClientId);
  $('#addPurchaseForm')[0].reset();
  $('#purchaseAlert').empty();
  $('#newPurchaseModal').modal('show');
}

// Load Available Plots
function loadAvailablePlots() {
  const projectSlug = $('#purchaseProject').val();
  const $plotSelect = $('#purchasePlot');
  
  $plotSelect.html('<option value="">Loading...</option>').prop('disabled', true);
  
  if (!projectSlug) {
    $plotSelect.html('<option value="">Select Project First</option>');
    return;
  }

  $.get(Wo_Ajax_Requests_File() + '?f=manage_inventory&s=get_available_plots', {
    project_slug: projectSlug
  })
  .done(function(plots) {
    $plotSelect.html('<option value="">Select Plot</option>').prop('disabled', false);
    
    plots.forEach(function(plot) {
      const text = `Block ${plot.block} • Plot ${plot.plot} • ${plot.katha} katha`;
      $plotSelect.append(`<option value="${plot.id}">${text}</option>`);
    });
  })
  .fail(function() {
    $plotSelect.html('<option value="">Error loading plots</option>');
  });
}

// Handle Purchase Form Submission
$('#addPurchaseForm').on('submit', function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  const $alert = $('#purchaseAlert');
  
  $.ajax({
    url: Wo_Ajax_Requests_File() + '?f=manage_inventory&s=register_purchase',
    type: 'POST',
    data: formData,
    processData: false,
    contentType: false,
    success: function(response) {
      if (response.status === 200) {
        $alert.html('<div class="alert alert-success">' + response.message + '</div>');
        setTimeout(() => {
          $('#newPurchaseModal').modal('hide');
          // Refresh the view
          if (typeof DataTableRefresh === 'function') DataTableRefresh();
        }, 1500);
      } else if (response.status === 409) {
        // Plot conflict - ask for confirmation
        if (confirm(response.message)) {
          formData.append('force', '1');
          // Retry with force flag
          $.ajax({
            url: Wo_Ajax_Requests_File() + '?f=manage_inventory&s=register_purchase',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(retryResponse) {
              if (retryResponse.status === 200) {
                $alert.html('<div class="alert alert-success">' + retryResponse.message + '</div>');
                setTimeout(() => {
                  $('#newPurchaseModal').modal('hide');
                  if (typeof DataTableRefresh === 'function') DataTableRefresh();
                }, 1500);
              } else {
                $alert.html('<div class="alert alert-danger">' + retryResponse.message + '</div>');
              }
            }
          });
        }
      } else {
        $alert.html('<div class="alert alert-danger">' + response.message + '</div>');
      }
    },
    error: function() {
      $alert.html('<div class="alert alert-danger">Network error occurred</div>');
    }
  });
});

// Open Installment Modal
function openInstallmentModal(purchaseId) {
  currentPurchaseId = purchaseId;
  
  $.get(Wo_Ajax_Requests_File() + '?f=manage_inventory&s=get_purchase', {
    purchase_id: purchaseId
  })
  .done(function(data) {
    if (data.error) {
      alert('Error: ' + data.error);
      return;
    }
    
    // Load installment content
    loadInstallmentContent(data);
    $('#installmentModal').modal('show');
  })
  .fail(function() {
    alert('Failed to load purchase details');
  });
}

// Load Installment Content
function loadInstallmentContent(purchaseData) {
  const content = `
    <div class="row mb-3">
      <div class="col-md-6">
        <strong>Project:</strong> ${purchaseData.project_name}<br>
        <strong>File Number:</strong> ${purchaseData.file_number}
      </div>
      <div class="col-md-6">
        <strong>Total Price:</strong> ৳${purchaseData.total_price.toLocaleString()}<br>
        <strong>Down Payment:</strong> ৳${purchaseData.down_payment.toLocaleString()}
      </div>
    </div>
    
    <div class="mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6>Installment Schedule</h6>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="generateSchedule()">Auto Generate</button>
      </div>
      
      <div id="scheduleContainer">
        <!-- Schedule will be loaded here -->
      </div>
      
      <button type="button" class="btn btn-sm btn-success mt-2" onclick="addInstallmentRow()">+ Add Row</button>
    </div>
  `;
  
  $('#installmentContent').html(content);
  
  // Load existing schedule if any
  if (purchaseData.schedule && purchaseData.schedule.length > 0) {
    loadExistingSchedule(purchaseData.schedule);
  } else {
    // Add initial row
    addInstallmentRow();
  }
}

// Add Installment Row
function addInstallmentRow(date = '', amount = '', adjustment = false) {
  const rowId = 'row_' + Date.now();
  const row = `
    <div class="row g-2 mb-2 installment-row" id="${rowId}">
      <div class="col-md-4">
        <input type="date" class="form-control installment-date" value="${date}" required>
      </div>
      <div class="col-md-4">
        <input type="number" class="form-control installment-amount" placeholder="Amount" value="${amount}" min="0" step="0.01" required>
      </div>
      <div class="col-md-3">
        <div class="form-check">
          <input class="form-check-input installment-adjustment" type="checkbox" ${adjustment ? 'checked' : ''}>
          <label class="form-check-label">Adjustment</label>
        </div>
      </div>
      <div class="col-md-1">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeInstallmentRow('${rowId}')">×</button>
      </div>
    </div>
  `;
  
  $('#scheduleContainer').append(row);
}

// Remove Installment Row
function removeInstallmentRow(rowId) {
  $('#' + rowId).remove();
}

// Generate Schedule
function generateSchedule() {
  const installments = prompt('How many installments?', '12');
  if (!installments || isNaN(installments)) return;
  
  const startDate = new Date();
  startDate.setMonth(startDate.getMonth() + 1); // Start next month
  
  // Clear existing rows
  $('#scheduleContainer').empty();
  
  // Add rows for each installment
  for (let i = 0; i < parseInt(installments); i++) {
    const installmentDate = new Date(startDate);
    installmentDate.setMonth(startDate.getMonth() + i);
    
    addInstallmentRow(
      installmentDate.toISOString().split('T')[0],
      '',
      false
    );
  }
}

// Save Installment Schedule
function saveInstallmentSchedule() {
  const schedule = [];
  
  $('.installment-row').each(function() {
    const date = $(this).find('.installment-date').val();
    const amount = $(this).find('.installment-amount').val();
    const adjustment = $(this).find('.installment-adjustment').is(':checked');
    
    if (date && amount) {
      schedule.push({
        date: date,
        amount: parseFloat(amount),
        adjustment: adjustment
      });
    }
  });
  
  if (schedule.length === 0) {
    alert('Please add at least one installment');
    return;
  }
  
  $.post(Wo_Ajax_Requests_File() + '?f=manage_inventory&s=update_installment', {
    purchase_id: currentPurchaseId,
    schedule: JSON.stringify(schedule)
  })
  .done(function(response) {
    if (response.status === 200) {
      alert('Schedule saved successfully');
      $('#installmentModal').modal('hide');
    } else {
      alert('Error: ' + response.message);
    }
  })
  .fail(function() {
    alert('Network error occurred');
  });
}

// Open Cancel Modal
function openCancelModal(purchaseId) {
  $('#cancelPurchaseId').val(purchaseId);
  $('#cancelPurchaseForm')[0].reset();
  $('#cancelAlert').empty();
  $('#cancelPurchaseModal').modal('show');
}

// Handle Cancel Form Submission
$('#cancelPurchaseForm').on('submit', function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  const $alert = $('#cancelAlert');
  
  $.ajax({
    url: Wo_Ajax_Requests_File() + '?f=manage_inventory&s=cancel_purchase',
    type: 'POST',
    data: formData,
    processData: false,
    contentType: false,
    success: function(response) {
      if (response.status === 200) {
        $alert.html('<div class="alert alert-success">' + response.message + '</div>');
        setTimeout(() => {
          $('#cancelPurchaseModal').modal('hide');
          // Refresh the view
          if (typeof DataTableRefresh === 'function') DataTableRefresh();
        }, 1500);
      } else {
        $alert.html('<div class="alert alert-danger">' + response.message + '</div>');
      }
    },
    error: function() {
      $alert.html('<div class="alert alert-danger">Network error occurred</div>');
    }
  });
});

// Print Booking Form with Correct Positioning
function printBookingForm(purchaseId) {
  $.post(Wo_Ajax_Requests_File() + '?f=manage_inventory&s=get_purchase_details', {
    purchase_id: purchaseId
  })
  .done(function(response) {
    if (response.status === 200) {
      generateBookingForm(response.data);
    } else {
      alert('Error: ' + response.message);
    }
  })
  .fail(function() {
    alert('Failed to load purchase details');
  });
}

// Generate Booking Form with Precise Positioning
function generateBookingForm(purchaseData) {
  const booking = purchaseData.booking;
  const client = purchaseData.client;
  const project = purchaseData.project;
  
  // Get field positions for this project
  $.post(Wo_Ajax_Requests_File() + '?f=builder&s=get_positions', {
    project_id: project.id
  })
  .done(function(positions) {
    const formClass = project.slug === 'moon-hill' ? 'moon-hill-form' : 'hill-town-form';
    
    let fieldsHtml = '';
    
    // Generate positioned fields based on saved positions
    positions.forEach(function(field) {
      const value = getFieldValue(field.name, purchaseData);
      const style = field.style;
      
      fieldsHtml += `
        <div class="form-field" style="
          top: ${style.top || '0px'};
          left: ${style.left || '0px'};
          width: ${style.width || 'auto'};
          font-size: ${style.fontSize || '14px'};
          text-align: ${style.textAlign || 'left'};
          letter-spacing: ${style.letterSpacing || '0px'};
        ">
          ${value}
        </div>
      `;
    });
    
    const formHtml = `
      <div class="booking-form-container ${formClass}">
        <div class="booking-form-overlay">
          ${fieldsHtml}
        </div>
      </div>
    `;
    
    $('#bookingFormContent').html(formHtml);
    $('#bookingFormModal').modal('show');
  })
  .fail(function() {
    alert('Failed to load form positions');
  });
}

// Get field value based on field name
function getFieldValue(fieldName, data) {
  const client = data.client;
  const booking = data.booking;
  const additional = data.additional;
  
  switch (fieldName) {
    case 'client_id':
      return client.id;
    case 'applicant_name':
      return client.name;
    case 'project_name':
      return data.project.name;
    case 'block':
      return booking.block;
    case 'plot':
      return booking.plot;
    case 'katha':
      return booking.katha;
    case 'road':
      return booking.road;
    case 'facing':
      return booking.facing;
    case 'date':
      return new Date().toLocaleDateString('en-GB');
    case 'spouse_name':
      return additional.spouse_name || '';
    case 'fathers_name':
      return additional.fathers_name || '';
    case 'mothers_name':
      return additional.mothers_name || '';
    case 'permanent_addr':
      return additional.permanent_addr || client.address;
    case 'reference':
      const refUser = data.reference_user;
      return refUser ? refUser.name : '';
    case 'email':
      return additional.email || '';
    case 'phone':
      return client.phone;
    case 'nationality':
      return additional.nationality || '';
    case 'birthday':
      return additional.birthday ? new Date(additional.birthday).toLocaleDateString('en-GB') : '';
    case 'religion':
      return additional.religion || '';
    case 'nid':
      return additional.nid || '';
    case 'passport':
      return additional.passport || '';
    case 'nomine_name':
      return data.nominees.map(n => n.name).join(', ');
    case 'nomine_address':
      return data.nominees.map(n => n.address).join(', ');
    case 'nomine_relation':
      return data.nominees.map(n => n.relation).join(', ');
    default:
      return fieldName;
  }
}

// Print Booking Form
function printBookingForm() {
  window.print();
}

// Download Booking Form as PDF
function downloadBookingForm() {
  // Use html2canvas and jsPDF for PDF generation
  const element = document.querySelector('.booking-form-container');
  
  if (!element) {
    alert('Form not loaded');
    return;
  }
  
  // Load libraries if not already loaded
  Promise.all([
    loadScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js'),
    loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js')
  ])
  .then(() => {
    html2canvas(element, {
      scale: 2,
      useCORS: true,
      allowTaint: true
    }).then(canvas => {
      const imgData = canvas.toDataURL('image/jpeg', 0.95);
      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF('p', 'pt', [612, 792]); // 8.5x11 inches in points
      
      const pdfWidth = pdf.internal.pageSize.getWidth();
      const pdfHeight = pdf.internal.pageSize.getHeight();
      const imgWidth = canvas.width;
      const imgHeight = canvas.height;
      const ratio = Math.min(pdfWidth / imgWidth, pdfHeight / imgHeight);
      const imgX = (pdfWidth - imgWidth * ratio) / 2;
      const imgY = 0;
      
      pdf.addImage(imgData, 'JPEG', imgX, imgY, imgWidth * ratio, imgHeight * ratio);
      pdf.save(`booking-form-${currentPurchaseId}.pdf`);
    });
  })
  .catch(err => {
    console.error('PDF generation failed:', err);
    alert('PDF generation failed. Please try printing instead.');
  });
}

// Load External Script
function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) {
      resolve();
      return;
    }
    
    const script = document.createElement('script');
    script.src = src;
    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

// Create Invoice
function createInvoice(clientId) {
  // Redirect to invoice creation with pre-filled client
  window.location.href = Wo_LoadManageLinkSettings('invoice') + '?client_id=' + clientId;
}

// View All Payments
function viewAllPayments(clientId) {
  // Redirect to invoice list filtered by client
  window.location.href = Wo_LoadManageLinkSettings('invoice') + '?client_id=' + clientId;
}

// Load Existing Schedule
function loadExistingSchedule(schedule) {
  $('#scheduleContainer').empty();
  
  schedule.forEach(function(item) {
    addInstallmentRow(item.date, item.amount, item.adjustment);
  });
}

// Modal cleanup
$('#viewClient-modal').on('hidden.bs.modal', function() {
  $(this).remove();
});

$('#newPurchaseModal, #installmentModal, #cancelPurchaseModal, #bookingFormModal').on('hidden.bs.modal', function() {
  $(this).find('form')[0]?.reset();
  $(this).find('.alert').remove();
});