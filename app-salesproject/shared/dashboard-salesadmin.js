/* ============================================================
   shared/dashboard-salesadmin.js
   การ์ด dashboard เฉพาะ role salesadmin — แยกออกมาจาก dashboard.html (2026-09-01)
   เพื่อให้แต่ละ role มีไฟล์ของตัวเอง อ่านง่ายกว่าเดิมที่ยัด 4 dashboard ไว้ไฟล์เดียว
   ============================================================ */
const DashboardSalesadmin = {
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
    };
  },
  computed: {
    calMonthLabel() {
      return `${this.thMonths[this.calMonth]} ${this.calYear + 543}`;
    },
    // ปฏิทิน "คัดกรองประกาศ" เหมือน company-calendar.html ทุกจุด แค่ fit ตามเนื้อหา ไม่ล็อก h-screen
    // เพราะอยู่ในการ์ดที่หน้า dashboard เลื่อนได้อยู่แล้ว (ยืนยันจากผู้ใช้ 2026-09-02 ให้นำมาแสดงต่อจาก card สรุปจำนวน)
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

    goToPendingAction() {
      window.location.href = 'announcements.html?pending_action=1';
    },

    goToCompanyCalendar() {
      window.location.href = 'company-calendar.html';
    },

    async loadCompanyWorkDays() {
      const res = await apiCall('GET', 'api/calendars.php?action=list');
      const cal = res.success ? res.data.find(c => c.code === 'CAL-01') : null;
      this.companyWorkDays = cal ? cal.work_days.split(',').map(Number) : [1,2,3,4,5,6];
    },

    // รวม 2 endpoint เหมือน company-calendar.html: company_month (วันหยุด+เวร+จำนวนประกาศ e-GP)
    // + assignments.php?action=calendar_by_announce_date (จำนวนมอบหมาย/กดรับทั้งทีมต่อวัน group ตามวันที่ประกาศเข้ามา)
    async loadCalMonth() {
      this.calLoading = true;
      const [calRes, assignRes] = await Promise.all([
        apiCall('GET', `api/calendar.php?action=company_month&year=${this.calYear}&month=${this.calMonth}&source_type=egp`),
        apiCall('GET', `api/assignments.php?action=calendar_by_announce_date&year=${this.calYear}&month=${this.calMonth}`),
      ]);
      const merged = calRes.success ? { ...calRes.data } : {};
      if (assignRes.success) {
        for (const [date, info] of Object.entries(assignRes.data)) {
          merged[date] = { ...(merged[date] || {}), assigned: info.assigned, accepted: info.accepted };
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

    goToDecision(date) {
      window.location.href = `bid_decision.html?date=${date}`;
    },
  },
  template: `
    <div>
      <div class="grid gap-3.5 mb-5" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-slate-700">{{ data.total_announcements ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">ประกาศทั้งหมด</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">🗂️</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100 cursor-pointer hover:ring-slate-300 transition-shadow"
          @click="goToCompanyCalendar">
          <div class="text-3xl font-black leading-none mb-1 text-slate-600">{{ data.previous_count ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">ประกาศก่อนหน้า</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">🗓️</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-blue-700">{{ data.today_count }}</div>
          <div class="text-xs text-slate-400 font-medium">ประกาศวันนี้</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📢</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100 cursor-pointer hover:ring-amber-200 transition-shadow"
          :class="(data.pending_action_count??0)>0 ? 'ring-amber-300' : ''"
          @click="goToPendingAction">
          <div class="text-3xl font-black leading-none mb-1" :class="(data.pending_action_count??0)>0 ? 'text-amber-600' : 'text-slate-300'">{{ data.pending_action_count ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">ค้าง Action ทั้งหมด</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📌</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100"
          :class="(data.unassigned_expired_count??0)>0 ? 'ring-red-300' : ''">
          <div class="text-3xl font-black leading-none mb-1" :class="(data.unassigned_expired_count??0)>0 ? 'text-red-600' : 'text-slate-300'">{{ data.unassigned_expired_count ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">หมดอายุ ยังไม่มอบหมาย</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">⏰</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100"
          :class="data.sla_breach>0 ? 'ring-red-300' : ''">
          <div class="text-3xl font-black leading-none mb-1" :class="data.sla_breach>0 ? 'text-red-600' : 'text-slate-300'">{{ data.sla_breach }}</div>
          <div class="text-xs text-slate-400 font-medium">SLA เกินกำหนด</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">⚠️</div>
        </div>
      </div>

      <!-- Calendar: ปฏิทินคัดกรองประกาศ — เหมือน company-calendar.html ทุกจุด (ยืนยันจากผู้ใช้ 2026-09-02) -->
      <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 font-bold text-slate-700 flex-wrap gap-2">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            ปฏิทินคัดกรองประกาศ
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
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-indigo-100 border border-indigo-300 inline-block"></span> วันหยุดของแหล่งงาน (บริษัทไม่หยุด)</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-sky-100 border border-sky-300 inline-block"></span> จำนวนประกาศ</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-100 border border-red-300 inline-block"></span> ยังไม่ตัดสินใจ (ค้าง)</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-100 border border-emerald-300 inline-block"></span> เข้าประมูล (ทั้งหมด/รับมาแล้ว)</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-slate-200 inline-block"></span> ไม่เข้าประมูล</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-teal-500 inline-block"></span> เวร sale ที่รับผิดชอบ</span>
              <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full border-2 border-dashed border-amber-400 inline-block"></span> คาดการณ์ (ยังไม่ได้สร้างเวรจริง)</span>
              <span class="text-slate-400">คลิกวันที่เพื่อไปคัดกรองประกาศ</span>
            </div>
            <div class="grid grid-cols-7 gap-1.5 mb-1.5">
              <div v-for="d in dayLabels" :key="d" class="text-center text-xs font-bold text-slate-400 py-1">{{ d }}</div>
            </div>
            <div class="grid grid-cols-7 gap-1.5" :style="{ gridTemplateRows: \`repeat(\${calNumWeeks}, minmax(4.5rem, auto))\` }">
              <div v-for="(cell, idx) in calendarCells" :key="idx"
                class="rounded-xl border p-2 flex flex-col transition-colors overflow-hidden"
                :class="[
                  cell ? 'cursor-pointer hover:ring-2 hover:ring-teal-200' : 'bg-transparent border-transparent',
                  cell && cell.isOff ? 'bg-orange-50 border-orange-200' : cell ? 'bg-white border-slate-100' : '',
                  cell && cell.isToday ? 'ring-2 ring-teal-400' : ''
                ]"
                @click="cell && goToDecision(cell.date)">
                <template v-if="cell">
                  <!-- เลขวันที่ — ตรึงตำแหน่งบนสุดเหมือนเดิมเสมอ ไม่ขึ้นกับว่ามี badge สถานะกี่บรรทัด (ยืนยันจากผู้ใช้ 2026-09-03 ว่าห้ามให้เลขวันที่เลื่อนตำแหน่ง) -->
                  <span class="text-xs font-semibold text-slate-400 leading-none shrink-0">{{ cell.day }}</span>
                  <!-- มีเวร (ต้องแสดงคู่กับ badge สถานะ) — แบ่ง 2 ฝั่ง: ซ้ายรูป+ชื่อ, ขวา badge สถานะ -->
                  <div v-if="cell.info?.duty" class="flex-1 min-h-0 flex gap-2 mt-1">
                    <div class="flex flex-col items-center justify-start gap-1 shrink-0">
                      <img v-if="cell.info.duty.photo_url" :src="cell.info.duty.photo_url" class="w-7 h-7 rounded-full object-cover shrink-0"
                           :class="cell.info.duty.computed ? 'opacity-60 border-2 border-dashed border-amber-400' : ''"/>
                      <div v-else class="w-7 h-7 rounded-full flex items-center justify-center text-white text-[11px] font-bold shrink-0"
                           :class="cell.info.duty.computed ? 'opacity-60 border-2 border-dashed border-amber-400' : ''"
                           :style="{background: cell.info.duty.avatar_color || '#64748b'}">{{ avatarInitials(cell.info.duty.full_name) }}</div>
                      <!-- แสดงชื่อเต็มบรรทัดเดียว ไม่ตัด/ไม่ขึ้นบรรทัดใหม่ (ยืนยันจากผู้ใช้ 2026-09-04) — เอา w-11 คงที่ออกให้คอลัมน์นี้กว้างตามความยาวชื่อจริงแทน -->
                      <span class="text-[10px] text-center leading-tight whitespace-nowrap" :class="cell.info.duty.computed ? 'text-amber-600 italic' : 'text-slate-600'">{{ (cell.info.duty.full_name||'').split(' ')[0] }}</span>
                    </div>
                    <!-- ฝั่งขวา: badge สถานะทั้งหมด (จำนวนประกาศ + ค้าง/เข้า/ไม่เข้า) — ตรึงชิดบนแทนกึ่งกลาง (ยืนยันจากผู้ใช้ 2026-09-04 ว่าอยากให้ badge ขยับขึ้นบน ไม่ทับพื้นที่ชื่อ sale) -->
                    <div class="flex-1 min-w-0 flex flex-col gap-1 justify-start overflow-hidden">
                      <div v-if="cell.info?.announcements_count" class="flex items-center gap-1 flex-wrap justify-end">
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-sky-500 text-white font-bold leading-none whitespace-nowrap shadow-sm shadow-sky-200">{{ cell.info.announcements_count }} ประกาศ</span>
                      </div>
                      <!-- แจกแจงผลตัดสินใจ (ค้าง/เข้า/ไม่เข้า) — เอาค้างขึ้นก่อนให้เห็นงานคงค้างก่อนเป็นอันดับแรก "เข้า" รวมจำนวนที่รับมอบหมายรับมาแล้วไว้ในตัวเดียวแบบ N/M (เข้าทั้งหมด/รับมาแล้ว)
                           ให้เห็นครบว่าประกาศทั้งหมดของวันนั้นไปทางไหนบ้างโดยไม่ต้องกดเข้าไปดู
                           (ยืนยันจากผู้ใช้ 2026-09-03 ว่าต้องการเห็นตัวเลขเต็มในช่องปฏิทินเลย ไม่ใช่แค่ tooltip)
                           badge ทั้งหมดชิดขวา ให้เห็นเป็น 2 ฝั่งชัดเจน: รูป+ชื่อชิดซ้าย / badge ชิดขวา (ยืนยันจากผู้ใช้ 2026-09-04)
                           แยกแต่ละ badge คนละบรรทัดตายตัว (ไม่ใช้ flex-wrap รวมกัน) เพราะจุดตัดบรรทัดของ flex-wrap ขยับไปมาตามความกว้างข้อความ
                           (เช่น "เข้า 1/รับ 1" กว้างกว่า "เข้า 0") ทำให้แต่ละวันใน 1 เดือนแสดงจัดกลุ่มบรรทัดไม่เหมือนกัน (ยืนยันจากผู้ใช้ 2026-09-04) -->
                      <div v-if="cell.info?.announcements_count" class="flex justify-end">
                        <span class="text-[9px] px-1.5 py-0.5 rounded-full font-bold leading-none whitespace-nowrap" :class="cell.info.pending_decision_count ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-400'">ค้าง {{ cell.info.pending_decision_count || 0 }}</span>
                      </div>
                      <div v-if="cell.info?.announcements_count" class="flex justify-end">
                        <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700 font-bold leading-none whitespace-nowrap">เข้า {{ cell.info.in_count || 0 }}{{ cell.info?.assigned ? '/รับ ' + cell.info.assigned : '' }}</span>
                      </div>
                      <div v-if="cell.info?.announcements_count" class="flex justify-end">
                        <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-bold leading-none whitespace-nowrap">ไม่เข้า {{ cell.info.out_count || 0 }}</span>
                      </div>
                    </div>
                  </div>
                  <!-- ไม่มีเวร (วันหยุดบริษัท/วันหยุดแหล่งงาน/วันหยุดสุดสัปดาห์/ไม่มีอะไร) — ไม่มีอะไรฝั่งขวาให้จับคู่ จึงจัดกึ่งกลางเต็มความกว้าง cell แทนการชิดซ้าย -->
                  <div v-else class="flex-1 min-h-0 flex items-center justify-center overflow-hidden mt-1">
                    <span v-if="cell.info?.holiday" class="text-[11px] text-orange-700 font-semibold text-center leading-tight line-clamp-2">{{ cell.info.holiday.name }}</span>
                    <span v-else-if="cell.info?.source_holiday" class="text-[11px] text-indigo-600 font-semibold text-center leading-tight line-clamp-2">หยุด e-GP<br>{{ cell.info.source_holiday.name }}</span>
                    <span v-else-if="!cell.isWorkDay" class="text-[11px] text-orange-600 font-semibold text-center">วันหยุดสุดสัปดาห์</span>
                    <span v-else class="text-sm text-slate-300">-</span>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  `,
};
