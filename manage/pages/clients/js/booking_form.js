// Booking Form Field Positioning System
class BookingFormBuilder {
  constructor(projectSlug) {
    this.projectSlug = projectSlug;
    this.positions = {};
    this.loadPositions();
  }

  // Load saved field positions for project
  async loadPositions() {
    try {
      const response = await fetch(Wo_Ajax_Requests_File() + '?f=builder&s=get_positions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ project_slug: this.projectSlug })
      });
      
      const data = await response.json();
      if (data.success) {
        this.positions = data.positions;
      }
    } catch (error) {
      console.error('Failed to load positions:', error);
    }
  }

  // Get field position
  getFieldPosition(fieldName) {
    return this.positions[fieldName] || {
      top: '100px',
      left: '100px',
      width: 'auto',
      fontSize: '14px',
      textAlign: 'left',
      letterSpacing: '0px'
    };
  }

  // Generate form HTML with positioned fields
  generateForm(data) {
    const formClass = this.projectSlug === 'moon-hill' ? 'moon-hill-form' : 'hill-town-form';
    let fieldsHtml = '';

    // Standard fields that should be positioned
    const fields = [
      'client_id', 'applicant_name', 'project_name', 'block', 'plot', 'katha', 
      'road', 'facing', 'date', 'spouse_name', 'fathers_name', 'mothers_name',
      'permanent_addr', 'reference', 'email', 'phone', 'nationality', 'birthday',
      'religion', 'nid', 'passport', 'nomine_name', 'nomine_address', 'nomine_relation'
    ];

    fields.forEach(fieldName => {
      const position = this.getFieldPosition(fieldName);
      const value = this.getFieldValue(fieldName, data);
      
      if (value) {
        fieldsHtml += `
          <div class="form-field" style="
            position: absolute;
            top: ${position.top};
            left: ${position.left};
            width: ${position.width};
            font-size: ${position.fontSize};
            text-align: ${position.textAlign};
            letter-spacing: ${position.letterSpacing};
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
          ">
            ${this.escapeHtml(value)}
          </div>
        `;
      }
    });

    return `
      <div class="booking-form-container ${formClass}" style="
        position: relative;
        width: 8.5in;
        height: 11in;
        margin: 0 auto;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        font-family: Arial, sans-serif;
        font-weight: 600;
        color: #000;
        page-break-after: always;
      ">
        <div class="booking-form-overlay" style="
          position: absolute;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          z-index: 10;
        ">
          ${fieldsHtml}
        </div>
      </div>
    `;
  }

  // Get field value from data
  getFieldValue(fieldName, data) {
    const client = data.client;
    const booking = data.booking;
    const additional = data.additional;
    const nominees = data.nominees || [];

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
        return data.reference_user ? data.reference_user.name : '';
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
        return nominees.map(n => n.name).join(', ');
      case 'nomine_address':
        return nominees.map(n => n.address || client.address).join(', ');
      case 'nomine_relation':
        return nominees.map(n => n.relation).join(', ');
      default:
        return '';
    }
  }

  // Escape HTML
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

// Initialize booking form builder
window.BookingFormBuilder = BookingFormBuilder;