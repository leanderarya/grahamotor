@once
    {{-- Load Library Sekali Saja --}}
    <script
        src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
        integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+"
        crossorigin="anonymous"
        referrerpolicy="no-referrer"
        type="text/javascript"
    ></script>
@endonce

<div
    x-data="{
        isScanning: false,
        scanToast: null, // Pesan notifikasi popup
        scanToastType: 'success', // 'success' atau 'error'
        html5QrcodeScanner: null,
        audio: new Audio('{{ asset('assets/audio/beep.mp3') }}'), // Load audio lokal

        init() {
            // Preload audio agar instan
            this.audio.load();
        },

        playBeep() {
            this.audio.currentTime = 0;
            this.audio.play().catch(e => console.warn('Audio play failed', e));
        },

        showToast(message, type = 'success') {
            this.scanToast = message;
            this.scanToastType = type;
            // Hilangkan toast otomatis setelah 2 detik
            setTimeout(() => { this.scanToast = null }, 2000);
        },

        startScan() {
            this.isScanning = true;
            
            this.$nextTick(() => {
                if (this.html5QrcodeScanner) return; // Jangan start double

                this.html5QrcodeScanner = new Html5Qrcode('reader-create-product');

                const config = { 
                    fps: 10, 
                    // Logika ukuran dinamis agar tidak crash di HP
                    qrbox: (viewfinderWidth, viewfinderHeight) => {
                        const minEdgePercentage = 0.7;
                        const minDim = Math.min(viewfinderWidth, viewfinderHeight);
                        return {
                            width: minDim * minEdgePercentage,
                            height: minDim * minEdgePercentage
                        };
                    }
                };
                
                this.html5QrcodeScanner.start(
                    { facingMode: 'environment' }, 
                    config,
                    (decodedText) => {
                        // === SUKSES SCAN ===
                        console.log(`Scan Result: ${decodedText}`);
                        
                        // 1. Bunyikan Beep
                        this.playBeep();

                        // 2. Isi Input SKU di Filament Form
                        // $wire adalah magic object Livewire/Filament
                        $wire.set('data.sku', decodedText);

                        // 3. Tampilkan Notifikasi Sukses
                        this.showToast(`Berhasil: ${decodedText}`, 'success');

                        // 4. Matikan Kamera (Karena input barang biasanya cuma butuh sekali)
                        // Beri jeda sedikit agar user melihat toast hijau
                        setTimeout(() => {
                            this.stopScan();
                        }, 1000);
                    },
                    (errorMessage) => {
                        // Error parsing frame, abaikan agar log bersih
                    }
                ).catch(err => {
                    console.error('Kamera Error:', err);
                    let msg = 'Gagal akses kamera.';
                    if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                        msg = 'Wajib HTTPS atau Localhost!';
                    }
                    alert(msg);
                    this.isScanning = false;
                });
            });
        },

        stopScan() {
            if (this.html5QrcodeScanner) {
                this.html5QrcodeScanner.stop().then(() => {
                    this.html5QrcodeScanner.clear();
                    this.html5QrcodeScanner = null;
                    this.isScanning = false;
                }).catch(err => {
                    console.warn('Stop failed', err);
                    this.html5QrcodeScanner = null;
                    this.isScanning = false;
                });
            } else {
                this.isScanning = false;
            }
        }
    }"
    class="w-full mb-4"
>
    {{-- 1. TOMBOL START SCAN (Tampil saat kamera mati) --}}
    <button 
        type="button" 
        x-show="!isScanning"
        @click="startScan()"
        class="flex items-center justify-center w-full gap-2 px-4 py-3 font-bold text-white transition-all transform shadow-md bg-slate-800 rounded-xl hover:bg-slate-700 active:scale-95"
    >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75zM16.5 19.5h.75v.75h-.75v-.75z" />
        </svg>
        <span>Buka Scanner (Kamera)</span>
    </button>

    {{-- 2. AREA KAMERA (Tampil saat tombol diklik) --}}
    <div 
        x-show="isScanning" 
        x-transition
        style="display: none;" 
        class="relative overflow-hidden border-2 shadow-xl bg-slate-900 rounded-xl border-slate-700"
    >
        {{-- Header Kecil di atas kamera --}}
        <div class="flex items-center justify-between px-4 py-2 bg-slate-800">
            <span class="text-xs font-bold text-slate-300">SCAN BARCODE</span>
            <button @click="stopScan()" class="text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        {{-- Container Video --}}
        <div class="relative w-full bg-black">
            {{-- ID Unik agar tidak bentrok dengan POS --}}
            <div id="reader-create-product" class="w-full min-h-[300px] bg-black"></div>

            {{-- Efek Laser Merah --}}
            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div class="w-3/4 h-0.5 bg-red-500 shadow-[0_0_15px_rgba(239,68,68,0.8)] animate-pulse"></div>
            </div>

            {{-- Popup Toast Notification (Floating) --}}
            <div 
                x-show="scanToast"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-[-10px]"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-[-10px]"
                class="absolute left-0 right-0 z-20 flex justify-center top-4"
            >
                <div 
                    class="px-4 py-2 text-sm font-bold text-white rounded-full shadow-lg"
                    :class="scanToastType === 'success' ? 'bg-green-500' : 'bg-red-500'"
                    x-text="scanToast"
                ></div>
            </div>
        </div>

        {{-- Footer Instruksi --}}
        <div class="p-3 text-center bg-slate-800">
            <p class="text-xs text-slate-400">Arahkan kamera ke barcode barang.</p>
            <button 
                type="button" 
                @click="stopScan()"
                class="w-full py-2 mt-2 text-xs font-bold text-white transition bg-red-600 rounded-lg hover:bg-red-500"
            >
                BATAL / TUTUP
            </button>
        </div>
    </div>
</div>
