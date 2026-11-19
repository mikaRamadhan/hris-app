# HRIS: Rekrutmen, Manajemen Karyawan, dan Absensi (GPS + Face Recognition)

Laravel 12 (REST API) + React (Vite). Dibuat mengikuti dokumen "Rancangan Lengkap HRIS".

## Pemasangan (sekali saja)
Kebutuhan: **PHP 8.2+**, **Composer**, dan **MySQL** (XAMPP cukup). Node.js TIDAK dibutuhkan karena frontend sudah di-build.

1. Ekstrak zip, nyalakan Apache/MySQL di XAMPP (atau gunakan `sqlite`, lihat di bawah).
2. Jalankan pemasangan:
   - Windows: klik dua kali `setup.bat` (atau `setup.bat sqlite`)
   - Linux/Mac: `./setup.sh` (atau `./setup.sh sqlite`)
   Skrip akan memasang Laravel 12 ke folder `backend`, menyalin kode HRIS, mengatur `.env`, membuat database `hris`, menjalankan migrasi dan seeder.
3. Jalankan: `start.bat` / `./start.sh`, lalu buka **http://127.0.0.1:8000**

Kamera dan GPS hanya berfungsi di `localhost` atau HTTPS. Untuk dipakai dari HP/jaringan lain, wajib HTTPS.

## Akun demo (password: `password123`)
| Role | Email |
|---|---|
| Admin | admin@hris.test |
| HRD | hrd@hris.test |
| Pelamar | daftar sendiri lewat halaman Daftar |
| Karyawan | terbentuk saat HRD mengonversi pelamar yang diterima |

## Alur uji cepat
1. Admin: isi Profil Perusahaan. HRD: ubah koordinat **Lokasi Absensi** ke lokasi Anda (tombol "Gunakan lokasi saya").
2. Pelamar: daftar, lengkapi profil, lamar lowongan (unggah CV).
3. HRD: Lamaran Masuk, tinjau, catat hasil, "Keputusan akhir: Lulus", Umumkan, Jadikan karyawan.
4. Pelamar melihat hasil di Lamaran Saya.
5. Akun tersebut kini berperan Karyawan: Registrasi Wajah (kedipkan mata), lalu Absensi (Check-in/Check-out).
6. HRD: Rekap Absensi (termasuk tab Percobaan absensi untuk audit) dan ekspor CSV.

## Pemetaan ke rancangan
- Rekrutmen (FR-04 s.d. FR-13): lowongan publik, akun pelamar, profil, lamaran (tanpa lamaran ganda, cek periode & kuota), dokumen privat per lamaran, hasil seleksi (riwayat 1:N, tahap wawancara opsional), pengumuman, konversi ke karyawan.
- Karyawan (FR-14 s.d. FR-18): CRUD, unit organisasi (satu tabel `organizational_units`, parent-child), status, pencarian, filter, ekspor CSV. Karyawan dinonaktifkan, bukan dihapus.
- Absensi (FR-21 s.d. FR-26): lokasi + radius (Haversine di server), registrasi wajah dengan persetujuan, check-in/out dengan GPS + wajah + liveness, rekap, ekspor, tabel percobaan gagal.
- RBAC 4 peran (Admin, HRD, Pelamar, Karyawan), `audit_logs`, template wajah terenkripsi (`encrypted:array`), dokumen di storage privat, waktu server sebagai sumber waktu absensi, throttle login/absensi.

## Struktur
- `backend-overlay/`: kode Laravel (migrations, Models, Services, Controllers, routes, seeders) + `public/spa` (frontend hasil build).
- `frontend/`: kode sumber React. Ubah lalu `npm install && npm run build`, salin `dist/` ke `backend/public/spa`. Mode dev: `npm run dev` (proxy ke port 8000).
- Layanan inti absensi: `app/Services/AttendanceService.php`, `Geo.php`, `FaceMatcher.php`.

## Catatan penting (baca sebelum dipakai/di-demo)
1. **Kolom tabel inti** (users, applicants, vacancies, applications, dst.) saya rancang sendiri karena halaman 6 PDF rancangan (Bagian 5.1) kosong. Sesuaikan dengan ERD final Anda.
2. **Liveness** (kedipan mata) dan pengambilan embedding wajah dijalankan di browser (face-api.js), lalu server mencocokkan embedding dan memeriksa lokasi. Server tidak dapat membuktikan liveness; klien yang dimodifikasi dapat memalsukannya. Ini keterbatasan yang juga disebut rancangan (Bagian 7.3). Untuk produksi, pakai layanan liveness sisi-server.
3. **Ambang wajah** `FACE_THRESHOLD=0.5` (di `.env`) hanya nilai awal umum; rancangan meminta ditentukan lewat pengujian dengan data nyata.
4. Hasil seleksi: "Keputusan akhir: Lulus" menandai diterima; "Tidak lulus" di tahap mana pun menandai ditolak. Pelamar hanya melihat hasil akhir setelah diumumkan.
5. Multi-tenant tidak ada, sesuai rancangan (satu instalasi per perusahaan).
6. Di luar ruang lingkup awal: payroll, penilaian kinerja, jadwal kerja/keterlambatan.
7. Saat diserahkan, kode Laravel belum pernah dijalankan (lingkungan pembuatnya tidak dapat mengakses Packagist); hanya sintaks PHP, logika Haversine/Face matcher, dan frontend (build + tampilan) yang diperiksa. Jalankan skenario uji Bab 11 dan laporkan bila ada error.
