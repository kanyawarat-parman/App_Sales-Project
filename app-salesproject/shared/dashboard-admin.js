/* ============================================================
   shared/dashboard-admin.js
   การ์ด dashboard เฉพาะ role admin — แยกออกมาจาก dashboard.html (2026-09-01)
   เป็นรวมมุมมองของ salesadmin + manager (admin ดูแลได้ทั้งหมด) — เนื้อหาซ้ำกับ 2 ไฟล์นั้นบางส่วน
   จงใจไม่รวมเป็น component เดียวกัน เพื่อให้แต่ละ role dashboard อ่านจบในไฟล์เดียว ไม่ต้องเปิดหลายไฟล์ตาม
   ============================================================ */
const DashboardAdmin = {
  props: ['data'],
  methods: {
    ...SharedMethods,
  },
  template: `
    <div>
      <div class="grid gap-3.5 mb-5" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-blue-700">{{ data.today_count }}</div>
          <div class="text-xs text-slate-400 font-medium">ประกาศวันนี้</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📢</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100"
          :class="(data.pending_decision??0)>0 ? 'ring-amber-300' : ''">
          <div class="text-3xl font-black leading-none mb-1" :class="(data.pending_decision??0)>0 ? 'text-amber-600' : 'text-slate-300'">{{ data.pending_decision ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">รอตัดสินใจ</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">❓</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-green-700">{{ data.team_win_rate ?? 0 }}%</div>
          <div class="text-xs text-slate-400 font-medium">Win Rate ทีม</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">🏆</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-purple-700">{{ data.unassigned_count ?? 0 }}</div>
          <div class="text-xs text-slate-400 font-medium">รอมอบหมาย</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">📌</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100"
          :class="data.sla_breach>0 ? 'ring-red-300' : ''">
          <div class="text-3xl font-black leading-none mb-1" :class="data.sla_breach>0 ? 'text-red-600' : 'text-slate-300'">{{ data.sla_breach }}</div>
          <div class="text-xs text-slate-400 font-medium">SLA เกินกำหนด</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">⚠️</div>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100 mb-5">
        <div class="flex items-center gap-1.5 px-5 py-4 border-b border-slate-100 font-bold text-slate-700">
          <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75"/></svg>
          ผลงานทีมขาย
        </div>
        <div v-if="!data.team_stats?.length" class="flex flex-col items-center justify-center py-8 text-slate-400">
          <div class="text-3xl mb-2">📭</div><p class="text-sm">ยังไม่มีพนักงานขาย</p>
        </div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-slate-50 text-slate-500 text-xs">
                <th class="text-left px-5 py-3 font-semibold">พนักงานขาย</th>
                <th class="text-center px-3 py-3 font-semibold">Active</th>
                <th class="text-center px-3 py-3 font-semibold">ชนะ</th>
                <th class="text-center px-3 py-3 font-semibold">แพ้</th>
                <th class="text-center px-3 py-3 font-semibold">Win Rate</th>
                <th class="text-center px-3 py-3 font-semibold">SLA เกิน</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in data.team_stats" :key="s.id" class="border-t border-slate-50 hover:bg-slate-50">
                <td class="px-5 py-3">
                  <div class="flex items-center gap-2.5">
                    <img v-if="s.photo_url" :src="s.photo_url" class="w-7 h-7 rounded-full object-cover shrink-0"/>
                    <div v-else class="w-7 h-7 rounded-full flex items-center justify-center text-white text-[.65rem] font-bold shrink-0"
                      :style="{background: s.avatar_color ?? '#94a3b8'}">{{ avatarInitials(s.full_name) }}</div>
                    <span class="font-medium text-slate-700">{{ s.full_name }}</span>
                  </div>
                </td>
                <td class="text-center px-3 py-3 text-slate-700 font-semibold">{{ s.active }}</td>
                <td class="text-center px-3 py-3 text-green-700 font-bold">{{ s.win }}</td>
                <td class="text-center px-3 py-3 text-red-500">{{ s.lose }}</td>
                <td class="text-center px-3 py-3">
                  <span class="font-bold"
                    :class="s.win+s.lose>0 && Math.round(s.win/(s.win+s.lose)*100)>=60 ? 'text-green-600' : 'text-slate-400'">
                    {{ s.win+s.lose>0 ? Math.round(s.win/(s.win+s.lose)*100)+'%' : '-' }}
                  </span>
                </td>
                <td class="text-center px-3 py-3">
                  <span v-if="s.sla_over>0" class="text-red-600 font-bold">{{ s.sla_over }}</span>
                  <span v-else class="text-slate-300">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="data.status_stats" class="grid grid-cols-1 md:grid-cols-2 gap-3.5 mb-5">
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100">
          <div class="flex items-center gap-1.5 px-5 py-4 border-b border-slate-100 font-bold text-slate-700">
          <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          สถานะงานทั้งหมด
        </div>
          <div class="px-5 py-2">
            <div v-for="(cnt, status) in data.status_stats" :key="status"
              class="flex justify-between items-center py-2 border-b border-slate-50 last:border-0">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold" :class="statusBadge(status)">{{ status }}</span>
              <strong class="text-lg text-slate-800">{{ cnt }}</strong>
            </div>
          </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100">
          <div class="flex items-center gap-1.5 px-5 py-4 border-b border-slate-100 font-bold text-slate-700">
          <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          ประกาศวันนี้แยกตามประเภท
        </div>
          <div class="px-5 py-2">
            <div v-for="(cnt, cat) in data.bid_stats" :key="cat"
              class="flex justify-between items-center py-2 border-b border-slate-50 last:border-0">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold" :class="canBidBadge(cat)">{{ cat }}</span>
              <strong class="text-lg text-slate-800">{{ cnt }}</strong>
            </div>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-100 mb-5">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 font-bold text-slate-700">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            งานล่าสุด
          </span>
          <a href="assignments.html" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-white ring-1 ring-slate-200 text-slate-600 hover:bg-slate-50">ดูทั้งหมด →</a>
        </div>
        <div>
          <div v-if="!data.recent?.length" class="flex flex-col items-center justify-center py-8 text-slate-400">
            <div class="text-3xl mb-2">📭</div><p class="text-sm">ยังไม่มีงาน</p>
          </div>
          <div v-for="item in data.recent" :key="item.id"
            class="flex items-center gap-3 px-5 py-3.5 border-b border-slate-50 last:border-0">
            <img v-if="item.sale_photo_url" :src="item.sale_photo_url" class="w-8 h-8 rounded-full object-cover shrink-0"/>
            <div v-else-if="item.sale_color || item.avatar_color"
              class="w-8 h-8 rounded-full flex items-center justify-center text-white text-[.7rem] font-bold shrink-0"
              :style="{background: item.avatar_color ?? item.sale_color ?? '#94a3b8'}">{{ avatarInitials(item.sale_name) }}</div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-semibold text-slate-700 truncate">{{ item.project_name }}</div>
              <div class="text-xs text-slate-400">ปิดรับ: {{ item.close_date }} · ราคากลาง: {{ formatPrice(item.price_median) }}</div>
            </div>
            <div class="flex gap-1.5 shrink-0">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold" :class="statusBadge(item.status)">{{ item.status }}</span>
              <span class="w-2 h-2 rounded-full mt-1"
                :class="item.sla_status==='เกิน' ? 'bg-red-500' : item.sla_status==='ใกล้ถึง' ? 'bg-amber-400' : 'bg-green-500'"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
};
