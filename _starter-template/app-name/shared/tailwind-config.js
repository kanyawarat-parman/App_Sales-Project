tailwind.config = {
  theme: { extend: {
    // เปลี่ยนสีหลัก/ฟอนต์ตรงนี้ต่อโปรเจกต์ — ที่เหลือของระบบอ้างอิงผ่าน utility class ปกติของ Tailwind
    colors: { teal: { '700': '#008074', '800': '#00665D' }, brand: { DEFAULT: '#008074', hover: '#00665D' } },
    fontFamily: { sans: ['Anuphan', 'Noto Sans Thai', 'sans-serif'] }
  }}
}
