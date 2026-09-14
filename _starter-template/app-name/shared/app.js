/* ============================================================
   shared/app.js  —  AppNav component + shared helpers
   (Starter Kit เวอร์ชันตัดเหลือเฉพาะของทั่วไป — ใช้ได้ทุกโปรเจกต์โดยไม่ต้องแก้)
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

/* ── Public app config (โหลดครั้งเดียว ใช้ค่าที่ต่างกันไปตามแต่ละที่ deploy) ──
   endpoint นี้ไม่ต้อง login ก่อน (ไม่มีความลับ) เลยเรียกได้ทุกหน้ารวมถึง login.html */
window.APP_CONFIG = { app_name: '', brand_line1: 'CHANGE_ME App Name', brand_line2: 'CHANGE_ME คำอธิบายสั้นๆ' };
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

/* ── Shared Vue methods (spread into each page's methods: {...SharedMethods, ...}) ──
   เฉพาะฟังก์ชันทั่วไปที่ไม่ผูกกับธุรกิจของโปรเจกต์ใดโปรเจกต์หนึ่ง — ฟังก์ชันเฉพาะธุรกิจ (เช่น badge สี
   ของ status/stage, การนับถอยหลัง SLA ฯลฯ) ให้เขียนแยกไว้ที่หน้านั้นๆ หรือไฟล์ shared เฉพาะโปรเจกต์แทน */
const SharedMethods = {
  /* ── Modal ── */
  closeModal() { this.modal = { type: null, data: null }; },

  /** แจ้งผลสำเร็จ/ผิดพลาดแบบ modal กลางจอ — ต้องมี <app-modal ref="appModal"> ในหน้านั้นด้วย
      คืน Promise ที่ resolve เมื่อผู้ใช้กด "ตกลง" ปิดเอง (ไม่ auto-dismiss แบบ toast เดิม) */
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

  /* ── ไฟล์ ── */
  formatFileSize(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  },

  // อ่านไฟล์ JSON ที่ user เลือกผ่าน <input type=file @change="loadJsonFile"> เก็บข้อความเข้า this.importJson
  loadJsonFile(e) {
    const file = e.target.files[0];
    if (!file) return;
    this.importFileName = file.name;
    const reader = new FileReader();
    reader.onload = ev => { this.importJson = ev.target.result; };
    reader.readAsText(file, 'UTF-8');
    e.target.value = '';
  },

  /* ── Format ตัวเลข / วันที่ ── */
  formatPrice(v) {
    if (!v) return '-';
    return Number(v).toLocaleString('th-TH') + ' บาท';
  },

  formatNumber(v) {
    if (!v) return '0';
    return Number(v).toLocaleString('th-TH');
  },

  formatNum(v) {
    const n = Number(String(v).replace(/,/g, ''));
    return isNaN(n) ? v : n.toLocaleString('en-US');
  },

  formatDateTime(v) {
    if (!v) return '-';
    const d = new Date(v.replace(' ', 'T'));
    return d.toLocaleString('th-TH', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  },

  /* ── โหลดข้อมูลทั่วไปจาก API ── */
  // ปรับ endpoint ตามที่โปรเจกต์นี้ใช้จริง — ตัวอย่างนี้สมมติว่ามี api/users.php?action=list
  async loadUsers(role = null) {
    const q = role ? `&role=${role}` : '';
    const res = await apiCall('GET', `api/users.php?action=list${q}`);
    if (res.success) this.users = res.data;
  },
};

/* ── AppNav Vue Component ──
   เป็นแค่โครง top bar พื้นฐาน (logo + เมนู flat + notification bell + user menu + logout)
   navItems() คือจุดที่ต้องเติมเมนูจริงของแต่ละโปรเจกต์ — ถ้าต้องการเมนูแบบ dropdown group/mobile drawer
   ที่ซับซ้อนกว่านี้ ให้ดูตัวอย่างเต็มที่โปรเจกต์ webfront_saleproject (../shared/app.js) แล้ว copy pattern มาต่อยอด */
const AppNav = {
  props: ['user', 'page'],
  data() {
    return {
      notifOpen: false,
      notifUnread: 0,
      notifList: [],
      notifLoading: false,
      userMenuOpen: false,
    };
  },
  computed: {
    // TODO: ปรับเงื่อนไข role ให้ตรงกับ role จริงของโปรเจกต์นี้
    isAdmin() { return this.user?.role === 'admin'; },
    roleLabel() { return this.user?.role || ''; },

    /* เมนูหลัก — เพิ่ม/ลบ/ปรับเงื่อนไข role ตามที่โปรเจกต์นี้ต้องการ */
    navItems() {
      const items = [
        { href: 'dashboard.html', page: 'dashboard', label: 'ภาพรวม' },
        // { href: 'xxx.html', page: 'xxx', label: 'เมนูใหม่', showIf: this.isAdmin },
      ];
      return items.filter(i => i.showIf === undefined || i.showIf);
    },
  },
  mounted() {
    this.loadNotifCount();
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
    async markNotifRead(n) {
      if (n.is_read) return;
      await apiCall('POST', 'api/notifications.php?action=read', { id: n.id });
      n.is_read = true;
      this.notifUnread = Math.max(0, this.notifUnread - 1);
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
    avatarInitials(name) {
      if (!name) return '?';
      const parts = name.trim().split(/\s+/).filter(Boolean);
      return parts.length >= 2 ? parts[0][0] + parts[1][0] : parts[0].substring(0, 2);
    },
    async doLogout() {
      await apiCall('POST', 'api/auth.php?action=logout');
      window.location.href = 'login.html';
    },
  },
  template: `
    <header class="bg-white border-b border-slate-100 sticky top-0 z-50">
      <div class="max-w-[1600px] mx-auto px-4 md:px-6 h-16 flex items-center gap-4">
        <a href="dashboard.html" class="flex items-center gap-2 font-bold text-slate-800 shrink-0">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#008074" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>
          </svg>
          <span class="hidden sm:inline">{{ $root.appConfig?.brand_line1 }}</span>
        </a>

        <nav class="hidden md:flex items-center gap-1 flex-1">
          <a v-for="item in navItems" :key="item.href" :href="item.href"
             class="px-3 py-2 rounded-lg text-sm font-semibold no-underline transition-colors"
             :class="page===item.page ? 'bg-teal-50 text-teal-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'">
            {{ item.label }}
          </a>
        </nav>

        <div class="ml-auto flex items-center gap-2 relative">
          <button @click="toggleNotif" class="relative w-9 h-9 flex items-center justify-center rounded-full hover:bg-slate-100 border-0 bg-transparent cursor-pointer">
            🔔
            <span v-if="notifUnread>0" class="absolute top-1 right-1 w-4 h-4 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center">{{ notifUnread }}</span>
          </button>
          <div v-if="notifOpen" class="absolute right-0 top-11 w-80 bg-white rounded-xl shadow-2xl ring-1 ring-slate-100 max-h-96 overflow-y-auto z-50">
            <div v-if="notifLoading" class="p-4 text-center text-slate-400 text-sm">กำลังโหลด...</div>
            <div v-else-if="!notifList.length" class="p-4 text-center text-slate-400 text-sm">ไม่มีการแจ้งเตือน</div>
            <div v-for="n in notifList" :key="n.id" @click="markNotifRead(n)"
                 class="p-3 border-b border-slate-50 cursor-pointer hover:bg-slate-50" :class="!n.is_read ? 'bg-teal-50/40' : ''">
              <div class="text-sm font-semibold text-slate-700">{{ n.title }}</div>
              <div class="text-xs text-slate-400 mt-0.5">{{ timeAgo(n.created_at) }}</div>
            </div>
          </div>

          <button @click="userMenuOpen=!userMenuOpen" class="flex items-center gap-2 border-0 bg-transparent cursor-pointer">
            <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs font-bold">
              {{ avatarInitials(user?.full_name) }}
            </div>
          </button>
          <div v-if="userMenuOpen" class="absolute right-0 top-11 w-48 bg-white rounded-xl shadow-2xl ring-1 ring-slate-100 z-50 py-1">
            <div class="px-4 py-2 text-sm font-semibold text-slate-700">{{ user?.full_name }}</div>
            <div class="px-4 pb-2 text-xs text-slate-400">{{ roleLabel }}</div>
            <button @click="doLogout" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 border-0 bg-transparent cursor-pointer">ออกจากระบบ</button>
          </div>
        </div>
      </div>
    </header>
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
