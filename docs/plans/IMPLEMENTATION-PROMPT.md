# Graha Mesran POS — Sprint 1-3 Implementation Prompt

Copy prompt ini untuk mengimplementasikan semua sprint.

---

## Prompt Implementasi

```
Saya ingin mengimplementasikan 3 sprint improvement untuk aplikasi POS Graha Mesran.

## Project Location
Lokasi project: /Users/aryaajisadda/Documents/KERJA/grahamesran

## Context
Ini adalah aplikasi POS (Point of Sale) untuk toko otomotif di Karanganyar, Solo.
Tech stack: Laravel 12, React 19, Inertia.js, Filament 3, Capacitor 8 (Android)
Aplikasi sudah berjalan di Hostinger shared hosting dan digunakan di Samsung Tab A8.

## Yang Harus Dilakukan
Baca dan implementasikan plan dari 3 file berikut secara berurutan:

### 1. Sprint 1: Security Hardening
Baca file: docs/plans/sprint-1-security-hardening.md

Isi sprint ini:
- Hash PINs (bukan plaintext lagi)
- API rate limiting
- PIN lockout setelah 5x gagal
- Sanctum token expiry 30 hari
- Ownership check di API
- Replace $guarded dengan $fillable
- Sembunyikan error details di API

### 2. Sprint 2: Resilience & Error Handling
Baca file: docs/plans/sprint-2-resilience-error-handling.md

Isi sprint ini:
- React Error Boundary (anti blank screen)
- Auto-save error visibility
- ClosingReportService memory optimization
- Recalculate session totals on close
- Fix useCallback dependencies

### 3. Sprint 3: Production Hardening & Mobile
Baca file: docs/plans/sprint-3-production-hardening-mobile.md

Isi sprint ini:
- Offline product cache (IndexedDB)
- Secure token storage (@capacitor/secure-storage)
- App version checking
- CSP headers
- Hapus role 'staff' yang tidak terpakai

## Penting
- Implementasikan secara berurutan: Sprint 1 → 2 → 3
- Setiap task harus di-commit setelah selesai
- Jalankan verification steps di akhir setiap sprint
- Jangan skip apapun dari plan
- Jika ada error, perbaiki sebelum lanjut ke task berikutnya

## Verification
Setelah semua sprint selesai, jalankan:
1. npm run build
2. php artisan migrate
3. npx cap sync android
4. Test PIN login dengan hash
5. Test offline mode
6. Test error boundary
```

---

## Alternatif: Prompt per Sprint

Jika ingin implementasi per sprint (lebih detail), gunakan prompt ini:

### Prompt Sprint 1

```
Saya ingin mengimplementasikan Sprint 1: Security Hardening untuk aplikasi POS Graha Mesran.

Baca plan lengkapnya di: /Users/aryaajisadda/Documents/KERJA/grahamesran/docs/plans/sprint-1-security-hardening.md

Ikuti semua task di dalamnya secara berurutan. Setiap task ada:
- File yang harus dibuat/diupdate
- Code yang harus ditulis
- Commit message yang harus digunakan

Setelah selesai semua task, jalankan verification di akhir plan.
```

### Prompt Sprint 2

```
Saya ingin mengimplementasikan Sprint 2: Resilience & Error Handling.

Baca plan lengkapnya di: /Users/aryaajisadda/Documents/KERJA/grahamesran/docs/plans/sprint-2-resilience-error-handling.md

Ikuti semua task di dalamnya secara berurutan.
```

### Prompt Sprint 3

```
Saya ingin mengimplementasikan Sprint 3: Production Hardening & Mobile.

Baca plan lengkapnya di: /Users/aryaajisadda/Documents/KERJA/grahamesran/docs/plans/sprint-3-production-hardening-mobile.md

Ikuti semua task di dalamnya secara berurutan.
```

---

## Quick Reference: File yang Akan Diubah

### Sprint 1
- app/Models/User.php (pin cast → hashed)
- app/Http/Controllers/PinLoginController.php (Hash::check)
- app/Http/Controllers/Api/AuthController.php (Hash::check + token expiry)
- routes/api.php (throttle middleware)
- app/Http/Controllers/Api/TransactionController.php (ownership check)
- app/Models/Asset.php ($fillable)
- app/Models/StockAdjustment.php ($fillable)

### Sprint 2
- resources/js/Components/ErrorBoundary.tsx (NEW)
- resources/js/Pages/Transactions/Create.tsx (error boundary + auto-save toast)
- app/Services/ClosingReportService.php (SQL aggregates)
- app/Services/CashierSessionService.php (recalculate on close)

### Sprint 3
- resources/js/lib/product-cache.ts (NEW)
- resources/js/lib/secure-storage.ts (NEW)
- resources/js/hooks/useVersionCheck.ts (NEW)
- resources/js/api/client.ts (secure storage)
- resources/js/services/pos.ts (cache integration)
- app/Http/Middleware/SecurityHeaders.php (NEW)
- bootstrap/app.php (register middleware)
