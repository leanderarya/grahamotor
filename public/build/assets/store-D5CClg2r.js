import{i as $}from"./app-BipVYPpk.js";function f(){return window.Capacitor?.Plugins?.BluetoothThermalPrinter}const h=27,T=29;function e(n){return Array.from(new TextEncoder().encode(n))}function a(){return[h,97,1]}function l(){return[h,97,0]}function u(){return[h,69,1]}function o(){return[h,69,0]}function S(n){return[h,100,n]}function m(){return[T,86,1]}function r(n=32){return e("-".repeat(n)+`
`)}function R(n){return String.fromCharCode(...n)}function _(n,i){const s=[];s.push(...a(),...u()),s.push(...e(i.name+`
`)),s.push(...o()),s.push(...e(i.address+`
`)),s.push(...e(i.phone+`
`)),s.push(...l()),s.push(...r()),s.push(...e(`No: ${n.invoice}
`)),s.push(...e(`Tgl: ${n.date}
`)),s.push(...e(`Kasir: ${n.cashier||"-"}
`)),s.push(...e(`Plg: ${n.customerType}
`)),s.push(...r());for(const t of n.items){const p=t.volume_liter?`${t.name} ${t.volume_liter}L`:t.name;s.push(...u()),s.push(...e(`${p}
`)),s.push(...o());const c=Number(t.sell_price||0).toLocaleString("id-ID"),g=(Number(t.qty||0)*Number(t.sell_price||0)).toLocaleString("id-ID");s.push(...e(`  ${t.qty} x ${c}    ${g}
`))}return s.push(...r()),s.push(...u()),s.push(...e(`TOTAL        Rp ${n.total.toLocaleString("id-ID")}
`)),s.push(...o()),s.push(...e(`Bayar (${n.paymentMethod})  Rp ${n.payAmount.toLocaleString("id-ID")}
`)),s.push(...e(`Kembali      Rp ${n.change.toLocaleString("id-ID")}
`)),s.push(...a()),s.push(...e(`*** TERIMA KASIH ***
`)),s.push(...e(`Barang yang dibeli tidak dapat ditukar/dikembalikan
`)),s.push(...S(1)),s.push(...m()),s}async function A(n,i){if(!$()){window.print();return}try{const s=f();if(!s)throw new Error("Plugin Bluetooth tidak tersedia.");let t=localStorage.getItem("printer_address");if(!t){const d=(await s.listPairedDevices())?.devices??[];if(d.length===0)throw new Error("Tidak ada printer Bluetooth yang terpasang. Silakan pasangkan printer terlebih dahulu.");t=d[0].address}await s.connect({deviceId:t});const p=_(n,i),c=R(p);await s.printText({text:c}),await s.disconnect()}catch(s){const t=s instanceof Error?s.message:"Gagal mencetak struk.";throw new Error(t)}}function L(n,i){const s=[];return s.push(...a(),...u()),s.push(...e(i.name+`
`)),s.push(...o()),s.push(...e(i.address+`
`)),s.push(...e(i.phone+`
`)),s.push(...l()),s.push(...r()),s.push(...a(),...u()),s.push(...e(`LAPORAN CLOSING KASIR
`)),s.push(...o()),s.push(...l()),s.push(...r()),s.push(...e(`Tanggal : ${n.date}
`)),s.push(...e(`Kasir   : ${n.cashierName}
`)),s.push(...e(`Buka    : ${n.openedAt}
`)),s.push(...e(`Tutup   : ${n.closedAt}
`)),s.push(...e(`Durasi  : ${n.duration}
`)),s.push(...r()),s.push(...u()),s.push(...e(`RINGKASAN TRANSAKSI
`)),s.push(...o()),s.push(...e(`Total Transaksi : ${n.totalTransactions}
`)),s.push(...e(`Total Penjualan : Rp ${n.totalRevenue.toLocaleString("id-ID")}
`)),s.push(...e(`Total Profit    : Rp ${n.totalProfit.toLocaleString("id-ID")}
`)),s.push(...r()),s.push(...u()),s.push(...e(`BREAKDOWN PEMBAYARAN
`)),s.push(...o()),s.push(...e(`Tunai    : Rp ${n.cashTotal.toLocaleString("id-ID")}
`)),s.push(...e(`Non Tunai: Rp ${n.nonCashTotal.toLocaleString("id-ID")}
`)),s.push(...r()),s.push(...u()),s.push(...e(`SETTLEMENT
`)),s.push(...o()),s.push(...e(`Saldo Awal    : Rp ${n.openingCash.toLocaleString("id-ID")}
`)),s.push(...e(`Cash Sales    : Rp ${n.cashSales.toLocaleString("id-ID")}
`)),s.push(...e(`Expected Cash : Rp ${n.expectedCash.toLocaleString("id-ID")}
`)),s.push(...e(`Uang Fisik    : Rp ${n.physicalCash.toLocaleString("id-ID")}
`)),s.push(...u()),s.push(...e(`Selisih       : Rp ${n.difference.toLocaleString("id-ID")}
`)),s.push(...e(`Status        : ${n.settlementStatus.toUpperCase()}
`)),s.push(...o()),s.push(...r()),n.topProducts.length>0&&(s.push(...u()),s.push(...e(`PRODUK TERLARIS
`)),s.push(...o()),n.topProducts.slice(0,5).forEach((t,p)=>{s.push(...e(`${p+1}. ${t.name}
`)),s.push(...e(`   ${t.quantity}x Rp ${t.revenue.toLocaleString("id-ID")}
`))}),s.push(...r())),s.push(...e(`TTD Kasir: ____________
`)),s.push(...e(`TTD Supervisor: ________
`)),s.push(...S(1)),s.push(...m()),s}const D={name:"GRAHA MOTOR",address:"Jl. Raya Pertamina No. 1",phone:"0812-3456-7890"};export{D as S,L as g,A as p};
