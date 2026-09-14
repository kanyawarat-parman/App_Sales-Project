/* ============================================================
   shared/dashboard-sale.js
   การ์ด dashboard เฉพาะ role sale — แยกออกมาจาก dashboard.html (2026-09-01)
   ============================================================ */
const DashboardSale = {
  props: ['data'],
  data() {
    const now = new Date();
    return {
      calYear: now.getFullYear(),
      calMonth: now.getMonth() + 1,
      calendarData: {},
      companyWorkDays: [1,2,3,4,5,6], // ค่า default ระหว่างรอโหลดปฏิทิน CAL-01 จริง (จ-ส)
      calLoading: false,
      dayLabels: ['จ','อ','พ','พฤ','ศ','ส','อา'],
      thMonths: ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'],
      dayModal: null, // { date, items } — เปิดตอนคลิกวันที่มีงานในปฏิทิน "แผนล่วงหน้า"
    };
  },
  computed: {
    totalAssign() {
      const sc = this.data?.status_counts;
      if (!sc) return 0;
      return Object.values(sc).reduce((s, v) => s + v, 0);
    },
    saleValueRows() {
      return [
        { label:'มูลค่ารวมทั้งหมด', field:'total_value',  color:'#3b82f6' },
        { label:'กำลังดำเนินการ',   field:'active_value', color:'#f59e0b' },
        { label:'ชนะประมูล',        field:'won_value',    color:'#16a34a' },
        { label:'แพ้ประมูล',        field:'lost_value',   color:'#ef4444' },
      ];
    },
    calMonthLabel() {
      return `${this.thMonths[this.calMonth]} ${this.calYear + 543}`;
    },
    // ปฏิทินย่อ (fit ตามเนื้อหา ไม่ล็อก h-screen แบบ company-calendar.html เพราะอยู่ในการ์ดที่หน้า dashboard เลื่อนได้อยู่แล้ว)
    calendarCells() {
      const first = new Date(this.calYear, this.calMonth - 1, 1);
      const startOffset = (first.getDay() + 6) % 7; // จันทร์=0
      const daysInMonth = new Date(this.calYear, this.calMonth, 0).getDate();
      const todayStr = new Date().toISOString().slice(0, 10);
      const cells = [];
      for (let i = 0; i < startOffset; i++) cells.push(null);
      for (let d = 1; d <= daysInMonth; d++) {
        const mm = String(this.calMonth).padStart(2, '0');
        const dd = String(d).padStart(2, '0');
        const date = `${this.calYear}-${mm}-${dd}`;
        const dow = new Date(this.calYear, this.calMonth - 1, d).getDay();
        const isoDow = dow === 0 ? 7 : dow;
        const info = this.calendarData[date] || null;
        const isWorkDay = this.companyWorkDays.includes(isoDow);
        cells.push({ date, day: d, isToday: date === todayStr, info, isWorkDay, isOff: !!info?.holiday || !isWorkDay });
      }
      return cells;
    },
    calNumWeeks() {
      return Math.ceil(this.calendarCells.length / 7);
    },
  },
  mounted() {
    this.loadCompanyWorkDays();
    this.loadCalMonth();
  },
  methods: {
    ...SharedMethods,

    viewAssignment(id) {
      window.location.href = 'my-assignments.html';
    },

    async loadCompanyWorkDays() {
      const res = await apiCall('GET', 'api/calendars.php?action=list');
      const cal = res.success ? res.data.find(c => c.code === 'CAL-01') : null;
      this.companyWorkDays = cal ? cal.work_days.split(',').map(Number) : [1,2,3,4,5,6];
    },

    async loadCalMonth() {
      this.calLoading = true;
      // รวม 2 endpoint: my_calendar (จำนวนงานที่ต้องยื่นซองของตัวเอง ต่อวัน) + company_month (วันหยุดบริษัท CAL-01)
      // เอาแค่ field 'holiday' จาก company_month มาซ้อนทับ ไม่ใช้ duty/source_holiday/announcements_count ของ endpoint นั้น
      const [myRes, companyRes] = await Promise.all([
        apiCall('GET', `api/assignments.php?action=my_calendar&year=${this.calYear}&month=${this.calMonth}`),
        apiCall('GET', `api/calendar.php?action=company_month&year=${this.calYear}&month=${this.calMonth}`),
      ]);
      const merged = myRes.success ? { ...myRes.data } : {};
      if (companyRes.success) {
        for (const [date, info] of Object.entries(companyRes.data)) {
          if (!info.holiday) continue;
          merged[date] = { ...(merged[date] || {}), holiday: info.holiday };
        }
      }
      this.calendarData = merged;
      this.calLoading = false;
    },

    prevCalMonth() {
      this.calMonth--; if (this.calMonth < 1) { this.calMonth = 12; this.calYear--; }
      this.loadCalMonth();
    },
    nextCalMonth() {
      this.calMonth++; if (this.calMonth > 12) { this.calMonth = 1; this.calYear++; }
      this.loadCalMonth();
    },

    formatThaiDate(dateStr) {
      const [y, m, d] = dateStr.split('-');
      return `${parseInt(d)} ${this.thMonths[parseInt(m)]} ${parseInt(y) + 543}`;
    },
    openDayModal(cell) {
      this.dayModal = { date: cell.date, items: cell.info?.items || [] };
    },
    closeDayModal() {
      this.dayModal = null;
    },
  },
  template: `
    <div>
      <div class="grid gap-3.5 mb-4" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100 cursor-pointer hover:ring-teal-200 transition-shadow"
          @click="viewAssignment()">
          <div class="text-3xl font-black leading-none mb-1 text-teal-700">{{ totalAssign }}</div>
          <div class="text-xs text-slate-400 font-medium">งานที่รับมอบหมายทั้งหมด</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📥</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-blue-700">{{ data.active_count }}</div>
          <div class="text-xs text-slate-400 font-medium">งานกำลังดำเนินการ</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">⚙️</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-amber-600">{{ data.status_counts?.['รอดำเนินการ'] ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">รอดำเนินการ</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📋</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-purple-700">{{ data.status_counts?.['รอประกาศผล'] ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">รอประกาศผล</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📤</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100"
          :class="(data.urgent_count??0)>0 ? 'ring-red-300' : ''">
          <div class="text-3xl font-black leading-none mb-1 text-red-600">{{ data.urgent_count ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">เร่งด่วน / SLA เกิน</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">⚠️</div>
        </div>
      </div>

      <!-- Upcoming + Value -->
      <div class="grid grid-cols-1 md:grid-cols-[1.6fr_1fr] gap-3.5 mb-4">
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100">
          <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 font-bold text-slate-700">
            <span class="flex items-center gap-1.5">
              <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              งานใกล้ครบกำหนด
            </span>
            <a href="my-assignments.html" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-white ring-1 ring-slate-200 text-slate-600 hover:bg-slate-50">ดูทั้งหมด →</a>
          </div>
          <div>
            <div v-if="!data.upcoming?.length" class="flex flex-col items-center justify-center py-8 text-slate-400">
              <div class="text-3xl mb-2">📭</div><p class="text-sm">ไม่มีงานที่ต้องติดตาม</p>
            </div>
            <div v-for="item in data.upcoming" :key="item.id"
              class="flex items-center gap-3 px-5 py-3 border-b border-slate-50 text-sm cursor-pointer hover:bg-slate-50"
              @click="viewAssignment(item.id)">
              <div class="flex-1 min-w-0">
                <div class="text-[.7rem] text-slate-400">{{ item.project_no }}</div>
                <div class="font-semibold text-slate-700 truncate">{{ item.project_name }}</div>
              </div>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold"
                :class="item.sla_status==='เกิน' ? 'bg-red-100 text-red-700' : item.sla_status==='ใกล้ถึง' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'">
                {{ item.sla_status==='เกิน' ? 'เกินแล้ว' : item.sla_status==='ใกล้ถึง' ? 'ใกล้ถึง' : 'ปกติ' }}
              </span>
              <div class="text-xs text-slate-400 shrink-0">{{ item.close_date }}</div>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100">
          <div class="flex items-center gap-1.5 px-5 py-4 border-b border-slate-100 font-bold text-slate-700">
            <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-6 3 3 4-7"/></svg>
            มูลค่างาน
          </div>
          <div class="px-5 py-2">
            <div v-for="row in saleValueRows" :key="row.field"
              class="flex justify-between items-baseline py-2.5 border-b border-slate-50 text-sm last:border-0">
              <span class="text-slate-500">{{ row.label }}</span>
              <span class="font-bold" :style="{color:row.color}">{{ formatPrice(data.value_summary?.[row.field]) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Calendar: แผนล่วงหน้า — งานประมูลที่ต้องยื่นซอง ลงตามวันปฏิทินทำงานบริษัท (CAL-01) -->
      <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 font-bold text-slate-700 flex-wrap gap-2">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            แผนล่วงหน้า — วันครบกำหนดยื่นซอง
          </span>
          <div class="flex items-center gap-2">
            <button @click="prevCalMonth" type="button" class="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 cursor-pointer text-slate-500">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <div class="text-sm font-bold text-slate-700 min-w-[110px] text-center">{{ calMonthLabel }}</div>
            <button @click="nextCalMonth" type="button" class="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 cursor-pointer text-slate-500">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>
        </div>
        <div class="p-3">
          <div v-if="calLoading" class="flex items-center justify-center py-8 text-slate-400 text-sm">กำลังโหลด...</div>
          <template v-else>
            <div class="flex items-center gap-4 flex-wrap text-xs text-slate-500 mb-2 px-1">
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-orange-200 inline-block"></span> วันหยุดบริษัท</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-100 border border-red-300 inline-block"></span> จำนวนงานที่ต้องยื่นวันนั้น</span>
              <span class="text-slate-400">คลิกวันที่มีงานเพื่อดูรายละเอียด</span>
            </div>
            <div class="grid grid-cols-7 gap-1.5 mb-1.5">
              <div v-for="d in dayLabels" :key="d" class="text-center text-xs font-bold text-slate-400 py-1">{{ d }}</div>
            </div>
            <div class="grid grid-cols-7 gap-1.5" :style="{ gridTemplateRows: \`repeat(\${calNumWeeks}, minmax(3.5rem, auto))\` }">
              <div v-for="(cell, idx) in calendarCells" :key="idx"
                class="rounded-xl border p-2 flex flex-col transition-colors overflow-hidden"
                :class="[
                  cell ? 'cursor-pointer hover:ring-2 hover:ring-teal-200' : 'bg-transparent border-transparent',
                  cell && cell.isOff ? 'bg-orange-50 border-orange-200' : cell ? 'bg-white border-slate-100' : '',
                  cell && cell.isToday ? 'ring-2 ring-teal-400' : ''
                ]"
                @click="cell && cell.info?.count && openDayModal(cell)">
                <template v-if="cell">
                  <div class="flex items-center justify-between shrink-0">
                    <span class="text-xs font-semibold text-slate-400 leading-none">{{ cell.day }}</span>
                    <span v-if="cell.info?.count" class="text-[10px] px-1.5 py-0.5 rounded-full bg-red-500 text-white font-bold leading-none whitespace-nowrap shadow-sm shadow-red-200">{{ cell.info.count }} งาน</span>
                  </div>
                  <div class="flex-1 min-h-0 flex flex-col items-center justify-center gap-1 overflow-hidden">
                    <span v-if="cell.info?.holiday" class="text-[11px] text-orange-700 font-semibold text-center leading-tight line-clamp-2">{{ cell.info.holiday.name }}</span>
                    <span v-else-if="!cell.isWorkDay" class="text-[11px] text-orange-600 font-semibold text-center">วันหยุดสุดสัปดาห์</span>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Modal: รายละเอียดงานประมูลที่ต้องยื่นซองวันที่คลิกจากปฏิทิน "แผนล่วงหน้า" -->
      <div v-if="dayModal" class="fixed inset-0 bg-black/50 flex items-start justify-center z-[1000] p-4 overflow-y-auto"
        @click.self="closeDayModal">
        <div class="bg-white rounded-2xl w-full max-w-[520px] shadow-2xl overflow-hidden my-8">
          <div class="bg-teal-600 text-white px-6 py-5 flex items-center justify-between">
            <h3 class="font-bold text-lg">งานที่ต้องยื่นซองวันที่ {{ formatThaiDate(dayModal.date) }}</h3>
            <button class="text-white/80 hover:text-white bg-transparent border-0 text-2xl cursor-pointer leading-none" @click="closeDayModal">×</button>
          </div>
          <div class="p-5 overflow-y-auto max-h-[calc(100vh-200px)]">
            <div v-if="!dayModal.items.length" class="text-sm text-slate-400 py-2">ไม่มีงานที่ต้องยื่นซองวันนี้</div>
            <div v-for="item in dayModal.items" :key="item.id"
              class="py-2.5 border-t border-slate-100 first:border-t-0">
              <div class="text-xs text-slate-400 font-mono mb-1">{{ item.project_no }}</div>
              <p class="text-sm text-slate-700 mb-2 break-words">{{ item.project_name }}</p>
              <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold" :class="statusBadge(item.status)">{{ item.status }}</span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold" :class="priorityBadge(item.priority)">{{ item.priority }}</span>
                <span class="text-xs" :class="item.sla_status==='เกิน' ? 'text-red-600' : item.sla_status==='ใกล้ถึง' ? 'text-amber-600' : 'text-green-600'">
                  {{ item.sla_status==='เกิน' ? 'เกิน SLA' : item.sla_status==='ใกล้ถึง' ? 'ใกล้ถึง SLA' : 'SLA ปกติ' }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
};
