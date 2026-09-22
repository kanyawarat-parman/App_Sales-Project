/* ============================================================
   shared/app.js  —  AppNav component + shared helpers
   ============================================================ */

/* ── Global API helper ── */
async function apiCall(method, url, body = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(url, opts);
  if (res.status === 401) {
    if (!window.location.pathname.endsWith('login.html') && window.location.pathname !== '/') {
      window.location.href = 'login.html';
    }
    return { success: false };
  }
  return res.json();
}

/* ── ชื่อเมนูภาษาไทยของแต่ละไฟล์หน้าเว็บ (ต้องตรงกับ label ที่ใช้จริงใน navGroups() ของ AppNav ด้านล่าง — ถ้าแก้ label ใน
   navGroups() ต้องแก้ตรงนี้ด้วย) ใช้แปลงชื่อไฟล์ดิบ (เช่น current_page จาก DB) ให้เป็นชื่อเมนูที่ผู้ใช้อ่านเข้าใจ เช่นในหน้า
   users.html คอลัมน์ "ใช้งานล่าสุด" (ยืนยันจากผู้ใช้ 2026-09-22) */
const PAGE_LABELS = {
  'dashboard.html': 'ภาพรวม',
  'bid-pipeline.html': 'งานประมูล (e-Bidding)',
  'my-assignments.html': 'งานที่ได้รับ',
  'sales-pipeline.html': 'งานขายตรง (Sales Hunt)',
  'company-calendar.html': 'ปฏิทินคัดกรองประกาศ',
  'bid_decision.html': 'ประกาศวันนี้',
  'assignments.html': 'จัดการงาน',
  'calendars.html': 'ปฏิทินการทำงาน',
  'sources.html': 'แหล่งที่มางานประมูล',
  'rotation-settings.html': 'ตั้งค่าวิธีคิดเวรงานประมูล',
  'duty-calendar.html': 'สร้างเวรรายปีงานประมูล',
  'analytics.html': 'รายงานวิเคราะห์',
  'kpi-settings.html': 'ตั้งเกณฑ์วัดผล KPI',
  'users.html': 'ผู้ใช้งาน',
  'import.html': 'นำเข้าข้อมูล',
  'holidays.html': 'วันหยุด',
  'announcements.html': 'ประกาศ',
  'login.html': 'เข้าสู่ระบบ',
};
function pageLabel(filename) {
  if (!filename) return '-';
  return PAGE_LABELS[filename] || filename;
}

/* ── Public app config (โหลดครั้งเดียว ใช้ค่าที่ต่างกันไปตามแต่ละบริษัทที่ deploy) ──
   endpoint นี้ไม่ต้อง login ก่อน (ไม่มีความลับ) เลยเรียกได้ทุกหน้ารวมถึง login.html */
window.APP_CONFIG = { app_name: '', doc_share_root: '', brand_line1: 'Taiyo Sales Project', brand_line2: 'ระบบบริหารงานขายโครงการ', brand_logo: 'shared/logo.png' };
window.APP_CONFIG_READY = apiCall('GET', 'api/config.php?action=public')
  .then(res => {
    if (res.success) window.APP_CONFIG = { ...window.APP_CONFIG, ...res.data };
    return window.APP_CONFIG;
  })
  .catch(() => window.APP_CONFIG);

/* ต่อชื่อแบรนด์ท้าย <title> ของทุกหน้าอัตโนมัติ — แต่ละไฟล์ตั้ง <title> แค่ชื่อหน้า (เช่น "ภาพรวม") พอ */
window.APP_CONFIG_READY.then(cfg => {
  if (cfg.brand_line1) document.title = `${document.title} - ${cfg.brand_line1} ${cfg.brand_line2}`;
});

/* ── Format วันที่แบบไทย "วันสัปดาห์ d เดือน ปี(พ.ศ.)" เช่น "อังคาร 15 กันยายน 2569" ──
   ประกาศเป็น global function (ไม่ใช่แค่ method ใน SharedMethods) เพื่อให้เรียกใช้ได้ทั้งใน
   Vue template (ผ่าน SharedMethods) และโค้ด vanilla เช่น DataTables' render() ที่ไม่มี Vue instance */
function formatDateThai(dateStr) {
  if (!dateStr) return '-';
  const days   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  const months = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  const d = new Date(String(dateStr).substring(0, 10) + 'T00:00:00');
  if (isNaN(d.getTime())) return dateStr;
  return `${days[d.getDay()]} ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear() + 543}`;
}

/* ── เหมือน formatDateThai() แต่ต่อเวลาไว้ท้าย เช่น "อังคาร 15 กันยายน 2569 เวลา 14:32 น." ──
   ใช้กับ field ที่เป็น TIMESTAMP/DATETIME (มีเวลาจริง) เช่น assigned_at ต่างจาก close_date/announce_date ที่เป็น DATE ล้วน */
function formatDateTimeThai(dateStr) {
  if (!dateStr) return '-';
  const days   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  const months = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  const d = new Date(String(dateStr).replace(' ', 'T'));
  if (isNaN(d.getTime())) return dateStr;
  const pad = n => String(n).padStart(2, '0');
  return `${days[d.getDay()]} ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear() + 543} เวลา ${pad(d.getHours())}:${pad(d.getMinutes())} น.`;
}

/* ── Shared Vue methods (spread into each page) ── */
const SharedMethods = {
  formatDateThai,
  formatDateTimeThai,
  pageLabel,

  /* ── Modal ── */
  closeModal() { this.modal = { type: null, data: null }; },

  /** แจ้งผลสำเร็จ/ผิดพลาดแบบ modal กลางจอ — ต้องมี <app-modal ref="appModal"> ในหน้านั้นด้วย
      คืน Promise ที่ resolve เมื่อผู้ใช้กด "ตกลง" ปิดเอง ไม่ auto-dismiss เหมือน toast */
  showAlert(message, type = 'success', title = null) {
    return this.$refs.appModal.alert(message, type, title);
  },

  /** ถามยืนยันก่อนทำ (เช่นก่อนลบ) แบบ modal กลางจอ แทน confirm() ของเบราว์เซอร์ — ต้องมี <app-modal ref="appModal"> ในหน้านั้นด้วย
      คืน Promise<boolean> — true ถ้ากด "ยืนยัน", false ถ้ากด "ยกเลิก" */
  showConfirm(title, message, confirmText = 'ยืนยัน') {
    return this.$refs.appModal.confirm(title, message, confirmText);
  },

  /* ── Avatar / ผู้ใช้ ── */
  avatarInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return parts.length >= 2 ? parts[0][0] + parts[1][0] : parts[0].substring(0, 2);
  },

  /* ── ไฟล์ / เอกสารแนบ ── */
  folderPath(dateStr) {
    if (!dateStr || !window.APP_CONFIG?.doc_share_root) return '';
    const [y, m, d] = dateStr.substring(0, 10).split('-');
    const be = parseInt(y, 10) + 543;
    return `${window.APP_CONFIG.doc_share_root}\\${be}\\${m}\\${d}${m}${be}`;
  },

  openFolder(dateStr) {
    const path = this.folderPath(dateStr);
    if (!path) return;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(path)
        .then(() => this.showAlert('คัดลอก path แล้ว — เปิด File Explorer (Win+E) แล้ววางในช่องที่อยู่ (Ctrl+V) แล้วกด Enter', 'success'))
        .catch(() => this.showAlert(path, 'success'));
    } else {
      this.showAlert(path, 'success');
    }
  },

  formatFileSize(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  },

  /** Export ตาราง (headers เป็น array ชื่อคอลัมน์, rows เป็น array ของ array ค่าต่อแถว) เป็นไฟล์ที่ Excel เปิดได้ตรงๆ
      ไม่ใช้ library ภายนอก (โปรเจกต์นี้ไม่มี build step) — เขียนเป็นตาราง HTML แล้วตั้งนามสกุล .xls ให้ Excel เปิดเป็นตารางให้อัตโนมัติ
      ใส่ BOM (﻿) กันข้อความไทยเพี้ยนตอน Excel เปิดไฟล์ */
  exportExcel(filename, headers, rows) {
    const esc = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    let html = '<table><thead><tr>' + headers.map(h => `<th>${esc(h)}</th>`).join('') + '</tr></thead><tbody>';
    rows.forEach(row => { html += '<tr>' + row.map(c => `<td>${esc(c)}</td>`).join('') + '</tr>'; });
    html += '</tbody></table>';
    const blob = new Blob(['﻿', html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename.endsWith('.xls') ? filename : `${filename}.xls`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  },

  docLabel(filename) {
    const rules = [
      [/^quotation\.pdf$/i,               'แบบใบเสนอราคา'],
      [/^bid[\s_]*bond\.pdf$/i,            'ตัวอย่างหนังสือค้ำประกัน (หลักประกันการเสนอราคา)'],
      [/^performance[\s_]*bond\.pdf$/i,    'ตัวอย่างหลักประกันสัญญา (5%)'],
      [/^performance\.pdf$/i,              'ตัวอย่างหลักประกันสัญญา'],
      [/^advance[\s_]*payment[\s_]*bond\.pdf$/i, 'ตัวอย่างหนังสือค้ำประกันการรับเงินล่วงหน้า'],
      [/^action_plan\.xlsx$/i,             'แผนการดำเนินงาน (Action Plan)'],
      [/^contract.*\.pdf$/i,               'ตัวอย่างร่างสัญญา'],
      [/^definition.*\.pdf$/i,             'คำนิยาม/คำจำกัดความที่เกี่ยวข้อง'],
      [/^document[\s_]*part[\s_]*1\.pdf$/i, 'เอกสารประกวดราคา ส่วนที่ 1'],
      [/^document[\s_]*part[\s_]*2\.pdf$/i, 'เอกสารประกวดราคา ส่วนที่ 2'],
      [/^attach_tor_1\.pdf$/i,             'เอกสารแนบท้าย TOR ฉบับที่ 1'],
      [/^attach_tor_2\.pdf$/i,             'เอกสารแนบท้าย TOR ฉบับที่ 2'],
      [/^tor[\s_].*\.pdf$/i,               'ขอบเขตของงาน (TOR)'],
      [/^bidding[\s_]*no?l?tice\.pdf$/i,   'ประกาศเชิญชวน/ประกาศประกวดราคา'],
      [/^annoudoc.*\.pdf$/i,               'ประกาศเชิญชวน (จากระบบ e-GP)'],
      [/^doc_\d.*\.pdf$/i,                 'เอกสารประกวดราคา (จากระบบ e-GP)'],
    ];
    for (const [re, label] of rules) {
      if (re.test(filename)) return label;
    }
    return null;
  },

  /* ── Badge นามสกุลไฟล์ (เอกสารโครงการ) ──
     ใช้ 2 ฟังก์ชันคู่กัน (docExt + docBadgeCls) แทนการใช้ emoji ตัวเดียว เพราะ emoji กลุ่ม "หนังสือสี" (📕📗📘📙)
     บางเครื่อง/บางเบราว์เซอร์ไม่มีฟอนต์ emoji สีรองรับ เรนเดอร์เป็นสี่เหลี่ยมสีทึบดูไม่ออกว่าไฟล์ประเภทไหน
     ใช้ใน: bid_decision.html, bid-pipeline.html, assignments.html, my-assignments.html */
  docExt(filename) {
    const ext = (filename || '').split('.').pop().toLowerCase();
    const map = {
      pdf: 'PDF',
      xlsx: 'XLS', xls: 'XLS', csv: 'CSV',
      doc: 'DOC', docx: 'DOC',
      ppt: 'PPT', pptx: 'PPT',
      zip: 'ZIP', rar: 'ZIP', '7z': 'ZIP',
      jpg: 'IMG', jpeg: 'IMG', png: 'IMG', gif: 'IMG',
      txt: 'TXT',
    };
    return map[ext] || ext.slice(0, 3).toUpperCase();
  },

  docBadgeCls(filename) {
    const ext = (filename || '').split('.').pop().toLowerCase();
    const map = {
      pdf: 'bg-red-600',
      xlsx: 'bg-emerald-600', xls: 'bg-emerald-600', csv: 'bg-emerald-600',
      doc: 'bg-blue-600', docx: 'bg-blue-600',
      ppt: 'bg-orange-500', pptx: 'bg-orange-500',
      zip: 'bg-amber-700', rar: 'bg-amber-700', '7z': 'bg-amber-700',
      jpg: 'bg-purple-500', jpeg: 'bg-purple-500', png: 'bg-purple-500', gif: 'bg-purple-500',
      txt: 'bg-slate-500',
    };
    return map[ext] || 'bg-slate-400';
  },

  // ใช้ใน: import.html, announcements.html — อ่านไฟล์ JSON ที่ user เลือกผ่าน <input type=file> เก็บข้อความเข้า this.importJson
  loadJsonFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    this.importFileName = file.name;
    const reader = new FileReader();
    reader.onload = ev => { this.importJson = ev.target.result; this.jsonResult = null; };
    reader.readAsText(file, 'UTF-8');
    e.target.value = '';
  },

  /* ── Format ตัวเลข / วันที่ / ข้อความ ── */
  formatPrice(v) {
    if (!v) return '-';
    return Number(v).toLocaleString('th-TH') + ' บาท';
  },

  formatNumber(v) {
    if (!v) return '0';
    return Number(v).toLocaleString('th-TH');
  },

  // ใช้ใน: bid-pipeline.html, assignments.html, my-assignments.html — ตัด comma ออกจากตัวเลขแล้ว format ใหม่
  formatNum(v) {
    const n = Number(String(v).replace(/,/g, ''));
    return isNaN(n) ? v : n.toLocaleString('en-US');
  },

  formatDateTime(v) {
    if (!v) return '-';
    const d = new Date(v.replace(' ', 'T'));
    return d.toLocaleString('th-TH', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  },

  // ใช้ใน: bid-pipeline.html, assignments.html, my-assignments.html — แยกข้อความ items ที่คั่นด้วย ; หรือ | หรือขึ้นบรรทัดใหม่
  // เป็นรายการย่อย พร้อมแยกรายการที่มีเลขกำกับซ้อนอยู่ในบรรทัดเดียว (เช่น "1) ก 2) ข") ออกเป็นคนละบรรทัด
  splitClauses(text) {
    if (!text) return [];
    const parts = text.split(/;|\||\n/).map(s => s.trim()).filter(Boolean);
    const countRe = /(?:^|[\s,])\(?\d{1,2}\)(?=\s*[ก-๙A-Za-z])/g;
    const splitRe = /(?<=^|[\s,])(?=\(?\d{1,2}\)\s*[ก-๙A-Za-z])/;
    const result = [];
    for (const p of parts) {
      const count = (p.match(countRe) || []).length;
      if (count > 1) {
        result.push(...p.split(splitRe).map(s => s.trim()).filter(Boolean));
      } else {
        result.push(p);
      }
    }
    return result;
  },

  /* ── SLA / Countdown วันปิดรับ ── */
  daysToClose(closeDate) {
    if (!closeDate) return null;
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const close = new Date(closeDate); close.setHours(0, 0, 0, 0);
    return Math.ceil((close - today) / 86400000);
  },

  countdownColor(days) {
    if (days === null) return '#94a3b8';
    if (days < 0 || days === 0) return '#dc2626';
    if (days <= 2) return '#ea580c';
    if (days <= 5) return '#ca8a04';
    return '#16a34a';
  },

  countdownText(days) {
    if (days === null) return '';
    if (days < 0)  return `เกินกำหนด ${Math.abs(days)} วัน`;
    if (days === 0) return '⚠ ปิดรับวันนี้!';
    if (days === 1) return '⚠ พรุ่งนี้ปิดรับ';
    return `เหลือ ${days} วัน`;
  },

  countdownBarWidth(closeDate, assignedAt) {
    if (!closeDate || !assignedAt) return 50;
    const start = new Date(assignedAt.replace(' ', 'T'));
    const end   = new Date(closeDate); end.setHours(17, 0, 0, 0);
    const now   = new Date();
    const total = end - start;
    if (total <= 0) return 100;
    return Math.min(Math.max(Math.round(((now - start) / total) * 100), 2), 100);
  },

  slaClass(s) {
    return s === 'เกิน' ? 'text-red-600 font-bold' : s === 'ใกล้ถึง' ? 'text-amber-600 font-semibold' : 'text-green-600';
  },

  /* ── สี / Badge (สถานะ, priority, source, stage) ──
     จัดกลุ่มสีตามความหมายเดียวกับหัวคอลัมน์ Kanban ใน bid-pipeline.html (ยืนยันจากผู้ใช้ 2026-09-19):
     เทา=กำลังลงมือทำงานจริง, อำพัน=รอคนอื่นขยับก่อน, เขียว=สำเร็จ, แดง=ไม่สำเร็จ, เทาจาง=ยกเลิก
     ใช้ร่วมกันหลายหน้า (bid-pipeline.html, assignments.html, my-assignments.html, announcements.html, dashboard-sale.js, dashboard-admin.js) */
  statusBadge(s) {
    const m = {
      'รอดำเนินการ':           'bg-amber-50 text-amber-700',
      'รับงาน/ศึกษา TOR':     'bg-slate-100 text-slate-700',
      'จัดเตรียมยื่นข้อเสนอ': 'bg-slate-100 text-slate-700',
      'รอประกาศผล':            'bg-amber-50 text-amber-700',
      'ชนะการประมูล':          'bg-emerald-100 text-emerald-700',
      'ส่งมอบแล้ว':            'bg-emerald-100 text-emerald-700',
      'แพ้การประมูล':          'bg-rose-100 text-rose-700',
      'ยกเลิก':                'bg-slate-200 text-slate-500',
    };
    return m[s] || 'bg-slate-100 text-slate-600';
  },

  priorityBadge(p) {
    return p === 'เร่งด่วน' ? 'bg-red-100 text-red-700' : p === 'ปกติ' ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-600';
  },

  canBidBadge(v) {
    return v === 'ได้' ? 'bg-emerald-100 text-emerald-700' : v === 'ไม่ได้' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700';
  },

  srcBadgeCls(t) {
    if (t === 'data_vendor') return 'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700';
    if (t === 'manual')      return 'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700';
    return 'inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-sky-100 text-sky-700';
  },

  srcBadgeLbl(t) {
    if (t === 'data_vendor') return 'ซื้อข้อมูล';
    if (t === 'manual')      return 'แจ้งเอง';
    return 'e-GP';
  },

  spSourceStyle(type) {
    const m = {
      'ebidding':       'background:#e0f2fe;color:#0369a1',
      'purchased_data': 'background:#fef3c7;color:#b45309',
      'self_sourced':   'background:#d1fae5;color:#047857',
    };
    return m[type] || 'background:#f1f5f9;color:#475569';
  },

  spPriorityStyle(p) {
    if (p === 'High')   return 'background:#fee2e2;color:#b91c1c';
    if (p === 'Medium') return 'background:#fef3c7;color:#b45309';
    return 'background:#ecfdf5;color:#065f46';
  },

  spStageStyle(stage) {
    const m = {
      'Interest':    'background:#f1f5f9;color:#475569',
      'Send PI':     'background:#e0f2fe;color:#0369a1',
      'Negotiating': 'background:#e0e7ff;color:#4338ca',
      'Deal Signed': 'background:#d1fae5;color:#047857',
      'Delivered':   'background:#d1fae5;color:#065f46',
      'Lost':        'background:#ffe4e6;color:#be185d',
    };
    return m[stage] || 'background:#f1f5f9;color:#475569';
  },

  /* ── โหลดข้อมูลจาก API ── */
  // ใช้ใน: announcements.html, assignments.html, bid-pipeline.html, sales-pipeline.html, duty-calendar.html, rotation-settings.html
  // ดึงรายชื่อ sale ทั้งหมดเก็บใน this.salesUsers (ใช้เป็น dropdown ตัวกรอง/มอบหมายงาน)
  async loadSales() {
    const res = await apiCall('GET', 'api/users.php?action=sales');
    if (res.success) this.salesUsers = res.data;
  },

  // ใช้ใน: bid-pipeline.html, my-assignments.html (เรียกเฉยๆ ใช้ modalType default 'detail'),
  // assignments.html (เรียกแบบ openDetail(id, 'assign-detail') เพราะหน้านี้ตั้งชื่อ modal.type ต่างออกไป)
  // ดึงรายละเอียดงานประมูล + เอกสารแนบที่เกี่ยวข้อง แล้วเปิด modal
  async openDetail(id, modalType = 'detail') {
    const res = await apiCall('GET', `api/assignments.php?action=detail&id=${id}`);
    if (res.success) this.modal = { type: modalType, data: res.data };

    this.docs = null;
    this.docsLoading = true;
    try {
      const dr = await apiCall('GET', `api/documents.php?action=list&id=${id}`);
      this.docs = dr.success ? dr.data : { available: false, files: [] };
    } catch {
      this.docs = { available: false, files: [] };
    }
    this.docsLoading = false;
  },
};

/* ── AppNav Vue Component ── */
const AppNav = {
  props: ['user', 'page'],
  data() { return { sidebarOpen: false, notifOpen: false, notifUnread: 0, notifList: [], notifLoading: false, appConfig: window.APP_CONFIG, now: new Date() }; },
  mounted() {
    window.APP_CONFIG_READY?.then(cfg => { this.appConfig = cfg; });
    this.loadNotifCount();
    setInterval(() => this.loadNotifCount(), 30000);
    setInterval(() => { this.now = new Date(); }, 1000);
  },
  computed: {
    isAdmin()      { return this.user?.role === 'admin'; },
    isManager()    { return ['admin','manager'].includes(this.user?.role); },
    isSalesAdmin() { return ['admin','salesadmin'].includes(this.user?.role); },
    isSale()       { return this.user?.role === 'sale'; },
    roleLabel() {
      const m = { admin:'Admin', salesadmin:'SalesAdmin', manager:'Manager', sale:'Sale' };
      return m[this.user?.role] || this.user?.role;
    },
    roleBadgeCls() {
      const m = {
        admin:      'bg-indigo-100 text-indigo-700',
        salesadmin: 'bg-amber-100 text-amber-700',
        manager:    'bg-teal-100 text-teal-700',
        sale:       'bg-sky-100 text-sky-700',
      };
      return m[this.user?.role] || 'bg-slate-100 text-slate-600';
    },
    // วันสัปดาห์/วันที่/เดือน/ปี (พ.ศ.)/เวลาปัจจุบัน แสดงบนแถบบนสุดทุกหน้า (ยืนยันจากผู้ใช้ 2026-09-02)
    nowDateLabel() {
      const days   = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
      const months = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
      const d = this.now;
      return `วัน${days[d.getDay()]}ที่ ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear() + 543}`;
    },
    nowTimeLabel() {
      const pad = n => String(n).padStart(2, '0');
      return `${pad(this.now.getHours())}:${pad(this.now.getMinutes())}:${pad(this.now.getSeconds())}`;
    },
    /* โครงสร้างเมนู sidebar ซ้าย — เปลี่ยนจากเมนูบนแนวนอนมาเป็น sidebar (ยืนยันจากผู้ใช้ 2026-09-21)
       จัดกลุ่มตาม "งานของผู้ใช้" (ขาย/งานประมูลราชการ/ระบบ) ไม่ใช่ตาม module โค้ด — แต่ละกลุ่มเป็นรายการเรียบ (flat)
       ไม่มี dropdown ซ้อนอีกต่อไป (เดิม type:'group' มี children ต้องกดขยาย) เพราะ sidebar มีที่ว่างพอไม่ต้องซ่อน
       ใช้ร่วมกันทั้ง sidebar บน PC และแผงมือถือ (ไม่ต้องมี navItemsFlat แยกอีกต่อไป) */
    navGroups() {
      const g = [];
      g.push({ href:'dashboard.html', page:'dashboard', label:'ภาพรวม', icon:'home' });

      g.push({ type:'section', label:'ขาย' });
      if (this.isManager || this.isSale) {
        g.push({ href:'bid-pipeline.html', page:'bid-pipeline', label:'งานประมูล (e-Bidding)', icon:'bid_pipeline' });
      }
      if (this.isSale || this.isAdmin) {
        // label "งานที่ได้รับ" (ไม่ใช่ "ประกาศมอบหมายวันนี้" แบบก่อนหน้านี้) — ให้ตรงกับที่ใช้อยู่แล้วในแถบเมนูลัดล่างจอมือถือของ dashboard.html
        // (AppTabBar's tabItems) และเมนูบนแบบเดิมก่อนเปลี่ยนเป็น sidebar (เคยมี shortLabel:'งานที่ได้รับ' แยกจาก label เต็ม)
        // ยืนยันจากผู้ใช้ 2026-09-22 ว่าเปลี่ยนชื่อไปแล้วทำให้จำไม่ได้ว่าเป็นเมนูเดิม
        // เพิ่ม isAdmin — ยืนยันจากผู้ใช้ 2026-09-22 ว่า "admin ต้องเห็นทุกเมนู" (admin เดิมไม่เห็นเมนูนี้)
        g.push({ href:'my-assignments.html', page:'my-assignments', label:'งานที่ได้รับ', icon:'my_assignments' });
      }
      if (this.isAdmin || this.isManager || this.isSale) {
        g.push({ href:'sales-pipeline.html', page:'sales-pipeline', label:'งานขายตรง (Sales Hunt)', icon:'sales_pipeline' });
      }
      // ทะเบียนหน่วยงาน/บริษัทลูกค้า (CRM account) — ทุก role เข้าดู/แก้ไขได้เหมือนกัน เป็นข้อมูลอ้างอิงกลาง ไม่ใช่ข้อมูลอ่อนไหว (ยืนยันจากผู้ใช้ 2026-09-22)
      g.push({ href:'accounts.html', page:'accounts', label:'หน่วยงาน/ลูกค้า', icon:'accounts' });
      // นำเข้าใบเสนอราคาเก่า (import-quotations.html) — ยังไม่เปิดใช้งานจริง (ยืนยันจากผู้ใช้ 2026-09-09) ตั้งใจไม่ใส่เมนู
      // ไฟล์หน้าเว็บยังอยู่ เข้าผ่าน URL ตรงได้ตามปกติ แค่ไม่โผล่ในเมนูจนกว่าจะพร้อมเปิดใช้งานจริง

      // หมวด "งานประมูลราชการ" — ของเฉพาะ Taiyo (e-GP) แยกออกจากหมวด "ขาย" ที่เป็น concept กลาง (ยืนยันจากผู้ใช้ 2026-09-21)
      if (this.isSalesAdmin || this.isAdmin) {
        g.push({ type:'section', label:'งานประมูลราชการ' });
        if (this.isSalesAdmin) {
          g.push({ href:'company-calendar.html', page:'company-calendar', label:'ปฏิทินคัดกรองประกาศ', icon:'calendar' });
          g.push({ href:'bid_decision.html', page:'bid_decision', label:'ประกาศวันนี้', icon:'bid_decision' });
          g.push({ href:'assignments.html', page:'assignments', label:'จัดการงาน', icon:'assignments' });
        }
        g.push({ href:'calendars.html', page:'calendars', label:'ปฏิทินการทำงาน', icon:'calendar' });
        g.push({ href:'sources.html', page:'sources', label:'แหล่งที่มางานประมูล', icon:'bid_decision' });
        g.push({ href:'rotation-settings.html', page:'rotation-settings', label:'ตั้งค่าวิธีคิดเวรงานประมูล', icon:'settings' });
        g.push({ href:'duty-calendar.html', page:'duty-calendar', label:'สร้างเวรรายปีงานประมูล', icon:'calendar' });
      }

      if (this.isManager || this.isSale || this.isAdmin) {
        g.push({ type:'section', label:'ระบบ' });
        if (this.isManager || this.isSale) {
          g.push({ href:'analytics.html', page:'analytics', label:'รายงานวิเคราะห์', icon:'analytics' });
        }
        if (this.isAdmin) {
          g.push({ href:'kpi-settings.html', page:'kpi-settings', label:'ตั้งเกณฑ์วัดผล KPI', icon:'kpi' });
          g.push({ href:'users.html', page:'users', label:'ผู้ใช้งาน', icon:'users' });
          g.push({ href:'import.html', page:'import', label:'นำเข้าข้อมูล', icon:'import' });
        }
      }

      // ตัด section header ที่ไม่มีรายการตามหลังเลย (เช่น role salesadmin ไม่มีสิทธิ์เข้าเมนูในหมวด "ขาย" เลยสักอัน)
      return g.filter((item, idx) => {
        if (item.type !== 'section') return true;
        const next = g[idx + 1];
        return next && next.type !== 'section';
      });
    },
  },
  methods: {
    async loadNotifCount() {
      try {
        const res = await apiCall('GET', 'api/notifications.php?action=count');
        if (res.success) this.notifUnread = res.data.unread;
      } catch {}
    },
    async toggleNotif() {
      this.notifOpen = !this.notifOpen;
      if (this.notifOpen) {
        this.notifLoading = true;
        const res = await apiCall('GET', 'api/notifications.php?action=list');
        if (res.success) { this.notifList = res.data.list; this.notifUnread = res.data.unread; }
        this.notifLoading = false;
      }
    },
    avatarInitials(name) {
      if (!name) return '?';
      const parts = name.trim().split(/\s+/).filter(Boolean);
      return parts.length >= 2 ? parts[0][0] + parts[1][0] : parts[0].substring(0, 2);
    },
    async markNotifRead(n) {
      if (n.is_read) return;
      await apiCall('POST', 'api/notifications.php?action=read', { id: n.id });
      n.is_read = true;
      this.notifUnread = Math.max(0, this.notifUnread - 1);
    },
    async markAllRead() {
      await apiCall('POST', 'api/notifications.php?action=read_all');
      this.notifList = this.notifList.map(n => ({ ...n, is_read: true }));
      this.notifUnread = 0;
    },
    timeAgo(ts) {
      if (!ts) return '';
      const d = new Date(ts.replace(' ', 'T'));
      const diff = Math.floor((Date.now() - d) / 1000);
      if (diff < 60) return 'เพิ่งเกิดขึ้น';
      if (diff < 3600) return Math.floor(diff / 60) + ' นาทีที่แล้ว';
      if (diff < 86400) return Math.floor(diff / 3600) + ' ชั่วโมงที่แล้ว';
      return Math.floor(diff / 86400) + ' วันที่แล้ว';
    },
    onNavClick(e) {
      const link = e.target.closest('a[href]');
      if (!link) return;
      if (link.getAttribute('href') === this.page + '.html') e.preventDefault();
      this.sidebarOpen = false;
    },
    async doLogout() {
      await apiCall('POST', 'api/auth.php?action=logout');
      window.location.href = 'login.html';
    },
    /* ไอคอนแต่ละเมนู เก็บเป็น path/polyline ดิบ ใช้ v-html วาดซ้ำได้ทั้งแถบ PC และแผงมือถือ */
    iconSvg(name) {
      const icons = {
        home:           '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        bid_decision:   '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/>',
        assignments:    '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/>',
        import:         '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>',
        calendar:       '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        my_assignments: '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        bid_pipeline:   '<path d="M18 3a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3 3 3 0 0 0 3-3 3 3 0 0 0-3-3H6a3 3 0 0 0-3 3 3 3 0 0 0 3 3 3 3 0 0 0 3-3V6a3 3 0 0 0-3-3 3 3 0 0 0-3 3 3 3 0 0 0 3 3h12a3 3 0 0 0 3-3 3 3 0 0 0-3-3z"/>',
        sales_pipeline: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        accounts:       '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
        analytics:      '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        kpi:            '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        users:          '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        settings:       '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
        master_data:    '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
      };
      return icons[name] || '';
    },
  },
  template: `
  <!-- ยืนยันจากผู้ใช้ 2026-09-20: root node ต้องแยกกัน (Vue 3 multi-root/fragment component) ไม่ครอบด้วย <div> เดียว
       ไม่งั้น position:sticky ของ header จะไม่ค้างจริงตอนเลื่อนจอ (div ครอบสูงเท่า header พอดี ไม่มีที่ว่างให้ sticky ค้าง)
       ยืนยันจากผู้ใช้ 2026-09-21: เปลี่ยนจากเมนูบนแนวนอนเป็น sidebar ซ้าย — เพราะแผนขยาย module ในอนาคต (3-6 เดือน)
       ทำให้เมนูยาวขึ้นเรื่อยๆ เมนูบนจะล้นซ้ำอีก เมนูซ้ายรองรับจำนวนรายการที่มากได้ดีกว่าและเปลี่ยนตอนนี้ (หน้ายังน้อย) ง่ายกว่ารอเปลี่ยนทีหลัง -->

  <!-- Mobile drawer click-away overlay -->
  <div v-if="sidebarOpen" class="fixed inset-0 bg-black/40 z-[39] md:hidden" @click="sidebarOpen=false"></div>

  <!-- Notification dropdown click-away overlay -->
  <div v-if="notifOpen" class="fixed inset-0 z-[39]" @click="notifOpen=false"></div>

  <!-- Sidebar ซ้าย (จอ md+ เท่านั้น) — กว้างคงที่ 240px (w-60) ไม่มีปุ่มยุบเหลือไอคอน (ยืนยันจากผู้ใช้ 2026-09-21
       กลุ่มผู้ใช้อายุ 50-60 ปี ไอคอนเดี่ยวไม่มี label ตีความยาก) จัดกลุ่มด้วยหัวข้อข้อความธรรมดา ไม่ใช้ collapsible section
       (ยังไม่จำเป็นเพราะจำนวนโมดูลที่จะเพิ่มจริงในเร็วๆ นี้ยังไม่เยอะ) -->
  <aside class="hidden md:flex md:flex-col fixed left-0 top-0 h-screen w-60 bg-white border-r border-slate-100 z-40 shrink-0">
    <a href="dashboard.html" class="flex items-center gap-2.5 px-4 py-3.5 border-b border-slate-100 no-underline shrink-0">
      <div class="min-w-0">
        <div class="text-sm font-bold text-slate-800 leading-tight truncate">{{ appConfig.brand_line1 }}</div>
        <div class="text-xs text-slate-400 truncate">{{ appConfig.brand_line2 }}</div>
      </div>
    </a>
    <nav class="flex-1 overflow-y-auto py-2 px-2.5" @click.capture="onNavClick">
      <template v-for="(entry, idx) in navGroups" :key="entry.page || ('sec'+idx)">
        <div v-if="entry.type==='section'" class="text-xs font-bold text-slate-400 uppercase tracking-wide px-2.5 pt-3.5 pb-1.5">{{ entry.label }}</div>
        <a v-else :href="entry.href"
           class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm no-underline transition-colors duration-150"
           :class="page===entry.page ? 'bg-teal-50 text-teal-700 font-semibold' : 'text-slate-600 hover:bg-slate-50'">
          <svg class="w-[17px] h-[17px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="iconSvg(entry.icon)"></svg>
          {{ entry.label }}
        </a>
      </template>
    </nav>
    <div class="border-t border-slate-100 p-3 flex items-center gap-2.5 shrink-0">
      <img v-if="user?.photo_url" :src="user.photo_url" class="w-9 h-9 rounded-full object-cover shrink-0"/>
      <div v-else class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
        :style="{background: user?.avatar_color || '#64748b'}">{{ avatarInitials(user?.full_name) }}</div>
      <div class="flex-1 min-w-0">
        <div class="text-sm font-semibold text-slate-700 truncate">{{ user?.full_name }}</div>
        <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold" :class="roleBadgeCls">{{ roleLabel }}</span>
      </div>
      <button @click="doLogout" title="ออกจากระบบ"
              class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors border-0 bg-transparent cursor-pointer flex items-center shrink-0">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </button>
    </div>
  </aside>

  <!-- แถบบน: มือถือ = โลโก้+แจ้งเตือน+hamburger, PC = วันเวลา+แจ้งเตือน+ผู้ใช้+logout (อยู่ฝั่งขวาของ sidebar ผ่าน md:ml-60)
       z-50 (มากกว่า z-40 ที่หน้าเพจใช้เอง) กันไม่ให้ header ของหน้าเพจทับบังเมนู mobile drawer (ยืนยันบั๊กจากผู้ใช้ 2026-09-21) -->
  <header class="sticky top-0 z-50 bg-white border-b border-slate-100 shadow-sm md:ml-60">
    <div class="px-4 py-2.5 flex items-center gap-3">
      <!-- Logo (มือถือเท่านั้น — PC มีโลโก้อยู่ใน sidebar แล้ว) -->
      <a href="dashboard.html" class="flex items-center gap-2.5 shrink-0 no-underline md:hidden">
        <img :src="appConfig.brand_logo" alt="" class="h-8 w-auto object-contain shrink-0">
        <div>
          <div class="text-sm font-bold text-slate-800 leading-tight">{{ appConfig.brand_line1 }}</div>
          <div class="text-xs text-slate-400">{{ appConfig.brand_line2 }}</div>
        </div>
      </a>

      <!-- วันเวลา (PC เท่านั้น) -->
      <div class="hidden md:block text-xs text-slate-400 font-medium whitespace-nowrap">
        {{ nowDateLabel }} · เวลา {{ nowTimeLabel }} น.
      </div>

      <div class="flex items-center gap-1 ml-auto shrink-0">
        <button @click="toggleNotif" title="การแจ้งเตือน"
                class="relative text-slate-400 hover:text-slate-600 p-2 rounded-lg hover:bg-slate-100 transition-colors border-0 bg-transparent cursor-pointer flex items-center">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
          <span v-if="notifUnread > 0"
                class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 bg-red-500 text-white text-[10px] flex items-center justify-center rounded-full font-bold px-0.5 leading-none">
            {{ notifUnread > 9 ? '9+' : notifUnread }}
          </span>
        </button>

        <!-- ผู้ใช้ + logout (PC) ย้ายไปอยู่จุดเดียวที่ sidebar footer แล้ว (ยืนยันจากผู้ใช้ 2026-09-22 ว่าซ้ำกับ header เดิม ไม่ต้องมี 2 ที่) -->

        <!-- Hamburger (มือถือเท่านั้น) -->
        <button @click="sidebarOpen=!sidebarOpen" title="เมนู"
                class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 border-0 bg-transparent cursor-pointer flex items-center md:hidden">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- แถบวันเวลาปัจจุบัน (มือถือเท่านั้น) -->
    <div class="md:hidden px-4 py-1 bg-slate-50 border-t border-slate-100 text-center">
      <span class="text-[11px] text-slate-500 font-medium">{{ nowDateLabel }} · เวลา {{ nowTimeLabel }} น.</span>
    </div>

    <!-- Notification Panel -->
    <div v-if="notifOpen"
         class="absolute top-full right-3 mt-1 bg-white rounded-xl shadow-2xl border border-slate-100 overflow-hidden"
         style="width:264px">
      <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
        <span class="text-sm font-bold text-slate-700">การแจ้งเตือน</span>
        <button v-if="notifUnread > 0" @click.stop="markAllRead"
                class="text-xs text-teal-700 hover:text-teal-700 border-0 bg-transparent cursor-pointer font-medium">อ่านทั้งหมด</button>
      </div>
      <div class="overflow-y-auto" style="max-height:320px">
        <div v-if="notifLoading" class="py-8 text-center text-sm text-slate-400">กำลังโหลด...</div>
        <div v-else-if="!notifList.length" class="py-8 text-center text-sm text-slate-400">ไม่มีการแจ้งเตือน</div>
        <template v-else>
          <div v-for="n in notifList" :key="n.id" @click.stop="markNotifRead(n)"
               class="flex gap-3 px-4 py-3 border-b border-slate-50 cursor-pointer transition-colors"
               :class="n.is_read ? 'hover:bg-slate-50' : 'bg-teal-50 hover:bg-teal-100'">
            <div class="w-2 h-2 rounded-full shrink-0 mt-1.5"
                 :class="n.is_read ? 'bg-slate-200' : 'bg-teal-500'"></div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-semibold text-slate-700 leading-tight">{{ n.title }}</div>
              <div class="text-xs text-slate-500 mt-0.5 line-clamp-2">{{ n.body }}</div>
              <div class="text-[11px] text-slate-400 mt-1">{{ timeAgo(n.created_at) }}</div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </header>

  <!-- แผงเมนูมือถือ — เลื่อนออกจากด้านซ้าย (ให้ตรงกับตำแหน่ง sidebar บน PC) แทนที่จะดร็อปลงมาจากด้านบนแบบเดิม (ยืนยันจากผู้ใช้ 2026-09-21) -->
  <div v-if="sidebarOpen"
       class="fixed inset-y-0 left-0 w-[82%] max-w-[300px] md:hidden bg-white shadow-lg overflow-y-auto z-50 flex flex-col">
    <a href="dashboard.html" class="flex items-center gap-2.5 px-4 py-3.5 border-b border-slate-100 no-underline shrink-0 min-w-0">
      <img :src="appConfig.brand_logo" alt="" class="h-8 w-auto object-contain shrink-0">
      <div class="min-w-0">
        <div class="text-sm font-bold text-slate-800 leading-tight truncate">{{ appConfig.brand_line1 }}</div>
        <div class="text-xs text-slate-400 truncate">{{ appConfig.brand_line2 }}</div>
      </div>
    </a>
    <nav class="flex-1 py-2 px-2.5" @click.capture="onNavClick">
      <template v-for="(entry, idx) in navGroups" :key="entry.page || ('msec'+idx)">
        <div v-if="entry.type==='section'" class="text-xs font-bold text-slate-400 uppercase tracking-wide px-3 pt-3.5 pb-1.5">{{ entry.label }}</div>
        <a v-else :href="entry.href"
           class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-base no-underline transition-all duration-150"
           :class="page===entry.page ? 'text-teal-700 font-semibold bg-teal-50' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800'">
          <svg class="w-[18px] h-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="iconSvg(entry.icon)"></svg>
          {{ entry.label }}
        </a>
      </template>
    </nav>
    <div class="p-4 border-t border-slate-100 flex items-center gap-2.5 shrink-0">
      <img v-if="user?.photo_url" :src="user.photo_url" class="w-9 h-9 rounded-full object-cover shrink-0"/>
      <div v-else class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
        :style="{background: user?.avatar_color || '#64748b'}">{{ avatarInitials(user?.full_name) }}</div>
      <div class="flex-1 min-w-0">
        <div class="text-sm font-semibold text-slate-700 truncate">{{ user?.full_name }}</div>
        <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold mt-0.5 inline-block" :class="roleBadgeCls">{{ roleLabel }}</span>
      </div>
      <button @click="doLogout" title="ออกจากระบบ"
              class="text-slate-400 hover:text-slate-600 p-2 rounded-lg hover:bg-slate-100 transition-colors border-0 bg-transparent cursor-pointer flex items-center">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </button>
    </div>
  </div>
  `,
};

/* ── AppTabBar Vue Component ──
   แถบเมนูลัดล่างจอบนมือถือ (md:hidden) — เดิม copy โค้ดเดียวกันซ้ำแยกทุกหน้า (10 หน้า) มี 2 สไตล์ไม่ตรงกัน
   รวมเป็น component เดียว แต่ละหน้ายังเลือกรายการเมนู (tabs) ของตัวเองได้เหมือนเดิม ไม่เปลี่ยน item/href ที่มีอยู่ */
const AppTabBar = {
  props: ['tabs'],
  methods: {
    async doLogout() {
      await apiCall('POST', 'api/auth.php?action=logout');
      window.location.href = 'login.html';
    },
    onTabClick(tab) {
      if (tab.action === 'logout') this.doLogout();
      else if (tab.href) window.location.href = tab.href;
    },
    iconSvg(name) {
      const icons = {
        home:      '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        logout:    '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        briefcase: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>',
        clipboard: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/>',
        doc:       '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        bell:      '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>',
        bars3:     '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        cols3:     '<rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="8" width="5" height="13" rx="1"/><rect x="17" y="5" width="5" height="16" rx="1"/>',
        funnel:    '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        users:     '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        import:    '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>',
      };
      return icons[name] || '';
    },
  },
  template: `
<nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-slate-100 flex justify-around md:hidden z-50 pb-1 pt-1">
  <button v-for="tab in tabs" :key="tab.label" type="button" @click="onTabClick(tab)"
    class="flex flex-col items-center gap-0.5 p-2 text-xs bg-transparent border-0 cursor-pointer"
    :class="tab.active ? 'text-teal-700' : 'text-slate-500'">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="iconSvg(tab.icon)"></svg>
    {{ tab.label }}
  </button>
</nav>
  `,
};

/* ── AppModal Vue Component ──
   Modal กลางจอแทนที่ toast (showAlert) และ confirm() ของเบราว์เซอร์ (showConfirm) ทั้งระบบ
   ใช้ผ่าน SharedMethods.showAlert()/showConfirm() เท่านั้น ไม่เรียก .alert()/.confirm() ตรงๆ
   หน้าที่ใช้ต้องมี <app-modal ref="appModal"></app-modal> ในเทมเพลตและลงทะเบียนใน components:{} */
const AppModal = {
  data() {
    return {
      visible: false,
      mode: 'success',   // success | error | confirm
      title: '',
      message: '',
      confirmText: 'ยืนยัน',
      resolvePromise: null,
    };
  },
  computed: {
    iconBg() {
      return { success: 'bg-teal-50', error: 'bg-red-50', confirm: 'bg-amber-50' }[this.mode];
    },
  },
  methods: {
    alert(message, type = 'success', title = null) {
      this.mode = type === 'error' ? 'error' : 'success';
      this.title = title || (this.mode === 'error' ? 'เกิดข้อผิดพลาด' : 'สำเร็จ');
      this.message = message;
      this.visible = true;
      return new Promise((resolve) => { this.resolvePromise = resolve; });
    },
    confirm(title, message, confirmText = 'ยืนยัน') {
      this.mode = 'confirm';
      this.title = title;
      this.message = message;
      this.confirmText = confirmText;
      this.visible = true;
      return new Promise((resolve) => { this.resolvePromise = resolve; });
    },
    onConfirm() {
      this.visible = false;
      this.resolvePromise(this.mode === 'confirm' ? true : undefined);
    },
    onCancel() {
      this.visible = false;
      this.resolvePromise(false);
    },
  },
  template: `
<transition name="fade">
  <div v-if="visible" class="fixed inset-0 z-[2000] flex items-center justify-center bg-black/50 px-4">
    <div class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl">
      <div class="flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4" :class="iconBg">
          <svg v-if="mode==='success'" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-teal-600">
            <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
          <svg v-else-if="mode==='error'" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-500">
            <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
          </svg>
          <svg v-else width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-500">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>

        <h3 class="text-lg font-bold text-slate-800 mb-2">{{ title }}</h3>
        <p class="text-sm text-slate-500 mb-6 whitespace-pre-line">{{ message }}</p>

        <div v-if="mode==='confirm'" class="flex gap-3 w-full">
          <button @click="onCancel" type="button"
            class="flex-1 py-2.5 bg-slate-100 text-slate-600 rounded-xl font-bold border-0 cursor-pointer hover:bg-slate-200 transition-colors">
            ยกเลิก
          </button>
          <button @click="onConfirm" type="button"
            class="flex-1 py-2.5 bg-red-600 text-white rounded-xl font-bold border-0 cursor-pointer hover:bg-red-700 transition-colors">
            {{ confirmText }}
          </button>
        </div>
        <button v-else @click="onConfirm" type="button"
          class="w-full py-2.5 rounded-xl font-bold text-white border-0 cursor-pointer transition-colors"
          :class="mode==='error' ? 'bg-red-600 hover:bg-red-700' : 'bg-teal-700 hover:bg-teal-800'">
          ตกลง
        </button>
      </div>
    </div>
  </div>
</transition>
  `,
};
