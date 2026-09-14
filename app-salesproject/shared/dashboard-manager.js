/* ============================================================
   shared/dashboard-manager.js
   การ์ด dashboard เฉพาะ role manager — แยกออกมาจาก dashboard.html (2026-09-01)
   ============================================================ */
const DashboardManager = {
  props: ['data'],
  methods: {
    ...SharedMethods,
  },
  template: `
    <div>
      <div class="grid gap-3.5 mb-5" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-green-700">{{ data.team_win_rate ?? 0 }}%</div>
          <div class="text-xs text-slate-400 font-medium">Win Rate ทีม</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">🏆</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-2xl font-black leading-none mb-1 text-teal-700">{{ formatPrice(data.team_won_value) }}</div>
          <div class="text-xs text-slate-400 font-medium">มูลค่าที่ชนะ</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">💰</div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-xl p-4 shadow-sm ring-1 ring-slate-100">
          <div class="text-3xl font-black leading-none mb-1 text-blue-700">{{ data.active_count }}</div>
          <div class="text-xs text-slate-400 font-medium">งานที่ดำเนินการอยู่</div>
          <div class="absolute -right-2 -bottom-2 text-5xl opacity-10 select-none">⚙️</div>
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
    </div>
  `,
};
