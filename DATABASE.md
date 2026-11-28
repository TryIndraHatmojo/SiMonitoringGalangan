# Dokumentasi Database SiMonitoringGalangan

Dokumen ini menjelaskan kolom pada tabel monitoring, akun, dan tabel pendukung Laravel. Untuk urutan penyimpanan data dari aplikasi, lihat [alur backend](README.md#alur-backend).

Tipe data mengikuti definisi migrasi Laravel. Bentuk penyimpanan seperti UUID dan boolean dapat berbeda sesuai mesin database. Keterangan `NULL` berarti kolom boleh kosong.

## Tabel monitoring

### `projects`

| Kolom     | Tipe data         | Kegunaan                                                                                         |
| --------- | ----------------- | ------------------------------------------------------------------------------------------------ |
| `id`      | `BIGINT UNSIGNED` | ID unik proyek kapal.                                                                            |
| `user_id` | `BIGINT UNSIGNED` | ID akun pemilik proyek.                                                                          |
| `code`    | `VARCHAR(255)`    | Kode pembangunan kapal. Kode harus unik untuk setiap pemilik.                                    |
| `name`    | `VARCHAR(255)`    | Nama kapal atau proyek.                                                                          |
| `yard`    | `VARCHAR(255)`    | Nama galangan tempat pembangunan.                                                                |
| `start`   | `DATE`            | Tanggal mulai pembangunan.                                                                       |
| `end`     | `DATE`            | Tanggal selesai pembangunan.                                                                     |
| `draft`   | `JSON`            | Struktur WBS yang masih bisa disunting, berisi kelompok, item, tahap, dan bobot dalam JSON.      |
| `is_demo` | `BOOLEAN`         | Penanda proyek demo. Dipakai untuk menampilkan label demo dan memilih periode awal di Ringkasan. |

**Relasi.** `projects.user_id` merujuk ke `users.id` agar aplikasi dapat menampilkan dan mengubah proyek sesuai pemiliknya. Satu pengguna dapat memiliki beberapa proyek.

### `baselines`

| Kolom        | Tipe data         | Kegunaan                                                                                 |
| ------------ | ----------------- | ---------------------------------------------------------------------------------------- |
| `id`         | `BIGINT UNSIGNED` | ID unik baseline.                                                                        |
| `project_id` | `BIGINT UNSIGNED` | ID proyek pemilik baseline.                                                              |
| `revision`   | `INT UNSIGNED`    | Nomor revisi baseline. Nomor ini unik dalam satu proyek.                                 |
| `structure`  | `JSON`            | Salinan WBS dalam JSON saat baseline ditetapkan, termasuk ID, nama, dan bobot pekerjaan. |

**Relasi.** `baselines.project_id` merujuk ke `projects.id`. Satu proyek dapat memiliki beberapa baseline agar setiap versi WBS tetap tersedia untuk laporan yang memakainya.

### `report_periods`

| Kolom             | Tipe data            | Kegunaan                                                                                                                      |
| ----------------- | -------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `id`              | `BIGINT UNSIGNED`    | ID unik periode laporan.                                                                                                      |
| `baseline_id`     | `BIGINT UNSIGNED`    | ID baseline yang dipakai selama periode ini.                                                                                  |
| `start`           | `DATE`               | Tanggal awal periode laporan.                                                                                                 |
| `end`             | `DATE`               | Tanggal akhir periode laporan.                                                                                                |
| `target`          | `DECIMAL(8, 4) NULL` | Persentase target kumulatif. Boleh kosong jika target belum ditentukan.                                                       |
| `closed_at`       | `TIMESTAMP NULL`     | Waktu penutupan periode. Jika terisi, realisasi dan target periode terkunci.                                                  |
| `reported_actual` | `DECIMAL(8, 4) NULL` | Persentase realisasi dari ringkasan Excel pada data demo. Dipakai ketika realisasi belum bisa dihitung dari pengukuran tahap. |
| `is_simulated`    | `BOOLEAN`            | Penanda bahwa realisasi per tahap pada periode tersebut berasal dari simulasi.                                                |

**Relasi.** `report_periods.baseline_id` merujuk ke `baselines.id`. Satu baseline bisa dipakai oleh beberapa periode. Periode lama tetap memakai bobot dan struktur revisinya meski proyek sudah punya baseline baru.

### `measurements`

| Kolom       | Tipe data         | Kegunaan                                                                                                       |
| ----------- | ----------------- | -------------------------------------------------------------------------------------------------------------- |
| `id`        | `BIGINT UNSIGNED` | ID unik catatan pengukuran.                                                                                    |
| `period_id` | `BIGINT UNSIGNED` | ID periode tempat nilai realisasi dicatat.                                                                     |
| `item`      | `UUID`            | UUID item pekerjaan dalam JSON `baselines.structure`.                                                          |
| `stage`     | `UUID`            | UUID tahap pengukuran untuk item tersebut dalam JSON `baselines.structure`.                                    |
| `value`     | `DECIMAL(8, 4)`   | Realisasi tahap dalam persen, dari 0 sampai 100. Jika kolom input dikosongkan, catatan tahap dihapus jika ada. |

**Relasi.** `measurements.period_id` merujuk ke `report_periods.id` agar hasil pengukuran masuk ke laporan yang tepat. Satu kombinasi periode, item, dan tahap hanya memiliki satu catatan. Backend mencocokkan `item` dan `stage` dengan JSON baseline milik periode.

Penghapusan proyek ikut menghapus baseline, periode, dan pengukuran miliknya melalui relasi database. Rantai penghapusan ini juga berlaku ketika akun pemilik proyek dihapus.

### Asal nilai kolom monitoring

Proyek demo ASD Harbour Tug diisi oleh [AsdHarbourTugDemoSeeder](database/seeders/AsdHarbourTugDemoSeeder.php) dari [data contoh JSON](database/seeders/asd-harbour-tug.json). Seeder memakai akun pemilik yang sudah terdaftar. Untuk penggunaan biasa, pengguna mengisi formulir dan backend melengkapi ID relasi, nomor revisi, serta waktu penutupan.

| Tabel dan kolom                                 | Proyek demo                                                                                                           | Penggunaan biasa                                                                                                                         |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| `id` pada keempat tabel monitoring              | Database membuat ID saat seeder menambah baris.                                                                       | Database membuat ID saat aplikasi menambah baris.                                                                                        |
| `projects.user_id`                              | Seeder mencari ID akun dari email pemilik demo yang dikonfigurasi.                                                    | Backend mengambil ID akun yang sedang login.                                                                                             |
| `projects.code`, `name`, `yard`, `start`, `end` | Diambil dari data contoh. Jika galangan kosong, seeder memakai **Belum tercatat**.                                    | Diisi pengguna di formulir pembuatan proyek.                                                                                             |
| `projects.draft`                                | Seeder menyusun WBS awal dari kelompok, item, tahap, dan bobot di data contoh.                                        | Dibuat kosong atau disalin dari proyek milik pengguna, lalu dapat diedit di Form Builder.                                                |
| `projects.is_demo`                              | Seeder mengisi `true`.                                                                                                | Database memakai nilai bawaan `false`.                                                                                                   |
| `baselines.project_id`, `revision`, `structure` | Seeder mengaitkan baseline ke proyek demo, memberi nomor revisi `1`, dan menyalin WBS dari data contoh.               | Saat pengguna menetapkan baseline, backend menyimpan ID proyek, nomor revisi berikutnya, dan salinan WBS yang dikirim dari Form Builder. |
| `report_periods.baseline_id`                    | Seeder memakai ID baseline demo yang dibuatnya.                                                                       | Backend memilih baseline terbaru proyek ketika periode dibuat.                                                                           |
| `report_periods.start`, `end`, `target`         | Tanggal dan target diambil dari data mingguan pada JSON demo.                                                         | Diisi pengguna di **Input Progres**. Target bisa kosong dan dapat diubah selama periode terbuka.                                         |
| `report_periods.closed_at`                      | Seeder mengisi waktu impor pada periode yang punya angka realisasi. Periode lain tetap terbuka.                       | Backend mengisi waktu saat pengguna memilih **Tutup periode**.                                                                           |
| `report_periods.reported_actual`                | Seeder mengisi realisasi dari ringkasan Excel jika data contoh memiliki angka tersebut dan periode memakai ringkasan. | Tetap `NULL`. Backend menghitung realisasi dari `measurements` ketika Ringkasan dibuka.                                                  |
| `report_periods.is_simulated`                   | Seeder mengisi `true` untuk periode dengan simulasi realisasi per tahap.                                              | Database memakai nilai bawaan `false`.                                                                                                   |
| `measurements.period_id`, `item`, `stage`       | Seeder memakai ID periode yang baru dibuat serta UUID item dan tahap dari WBS demo.                                   | Backend memakai ID periode yang dipilih dan memeriksa UUID item dan tahap terhadap baseline periode.                                     |
| `measurements.value`                            | Diisi dari simulasi tahap minggu ke-14 dalam data contoh.                                                             | Diisi dari persentase realisasi yang dimasukkan pengguna di **Input Progres**.                                                           |

Alur pembuatan dan pembaruan data biasa ada di [MonitoringController](app/Http/Controllers/MonitoringController.php).

## Tabel akun dan autentikasi

### `users`

| Kolom                       | Tipe data           | Kegunaan                                                                                   |
| --------------------------- | ------------------- | ------------------------------------------------------------------------------------------ |
| `id`                        | `BIGINT UNSIGNED`   | ID unik akun.                                                                              |
| `name`                      | `VARCHAR(255)`      | Nama pengguna yang ditampilkan.                                                            |
| `email`                     | `VARCHAR(255)`      | Alamat email unik untuk login dan komunikasi akun.                                         |
| `email_verified_at`         | `TIMESTAMP NULL`    | Waktu verifikasi email. Kosong sebelum email diverifikasi.                                 |
| `password`                  | `VARCHAR(255)`      | Hash kata sandi untuk validasi login.                                                      |
| `remember_token`            | `VARCHAR(100) NULL` | Token untuk fitur **Ingat saya**.                                                          |
| `two_factor_secret`         | `TEXT NULL`         | Rahasia terenkripsi untuk memverifikasi kode autentikasi dua faktor.                       |
| `two_factor_recovery_codes` | `TEXT NULL`         | Kumpulan kode pemulihan terenkripsi untuk masuk saat aplikasi autentikator tidak tersedia. |
| `two_factor_confirmed_at`   | `TIMESTAMP NULL`    | Waktu aktivasi autentikasi dua faktor dikonfirmasi.                                        |
| `created_at`                | `TIMESTAMP NULL`    | Waktu akun dibuat.                                                                         |
| `updated_at`                | `TIMESTAMP NULL`    | Waktu data akun terakhir diperbarui.                                                       |

**Relasi.** ID akun dipakai untuk menentukan pemilik proyek dan passkey, serta mengaitkan sesi yang sudah login. Email dipakai untuk mencocokkan permintaan reset kata sandi.

**Catatan akun demo.** [UserSeeder](database/seeders/UserSeeder.php) menyiapkan `name`, `email`, `password` (disimpan sebagai hash), dan `email_verified_at` untuk akun demo di lingkungan lokal dan pengujian. Pada pendaftaran biasa, pengguna mengisi nama, email, dan kata sandi, lalu sistem mencatat waktu verifikasi email setelah dikonfirmasi. ID dan waktu pembuatan atau perubahan akun dihasilkan otomatis. Kolom **Ingat saya** dan autentikasi dua faktor diisi oleh sistem saat fitur terkait digunakan. Tabel autentikasi dan tabel pendukung lainnya diisi oleh Laravel sesuai penggunaan fitur.

### `passkeys`

| Kolom           | Tipe data         | Kegunaan                                                                     |
| --------------- | ----------------- | ---------------------------------------------------------------------------- |
| `id`            | `BIGINT UNSIGNED` | ID unik catatan passkey di database.                                         |
| `user_id`       | `BIGINT UNSIGNED` | ID akun pemilik passkey.                                                     |
| `name`          | `VARCHAR(255)`    | Nama passkey yang tampil di pengaturan akun.                                 |
| `credential_id` | `VARCHAR(255)`    | ID kredensial unik untuk menemukan passkey saat digunakan.                   |
| `credential`    | `JSON`            | Data kredensial dalam JSON, termasuk kunci publik untuk memverifikasi login. |
| `last_used_at`  | `TIMESTAMP NULL`  | Waktu passkey terakhir digunakan.                                            |
| `created_at`    | `TIMESTAMP NULL`  | Waktu passkey didaftarkan.                                                   |
| `updated_at`    | `TIMESTAMP NULL`  | Waktu catatan passkey terakhir diperbarui.                                   |

**Relasi.** `passkeys.user_id` merujuk ke `users.id` untuk menentukan akun saat login dengan passkey. Satu akun bisa memiliki beberapa passkey. Passkey ikut terhapus saat akunnya dihapus.

### `password_reset_tokens`

| Kolom        | Tipe data        | Kegunaan                                                             |
| ------------ | ---------------- | -------------------------------------------------------------------- |
| `email`      | `VARCHAR(255)`   | Alamat email yang meminta reset dan menjadi identitas catatan token. |
| `token`      | `VARCHAR(255)`   | Hash token untuk memvalidasi tautan reset kata sandi.                |
| `created_at` | `TIMESTAMP NULL` | Waktu token dibuat untuk menghitung masa berlakunya.                 |

**Relasi.** Laravel mencocokkan `password_reset_tokens.email` dengan `users.email` untuk menemukan akun yang meminta reset. Pencocokan dilakukan oleh aplikasi.

### `sessions`

| Kolom           | Tipe data              | Kegunaan                                                               |
| --------------- | ---------------------- | ---------------------------------------------------------------------- |
| `id`            | `VARCHAR(255)`         | ID unik sesi.                                                          |
| `user_id`       | `BIGINT UNSIGNED NULL` | ID pengguna yang login. Dapat kosong untuk pengunjung.                 |
| `ip_address`    | `VARCHAR(45) NULL`     | Alamat IP yang tercatat untuk sesi.                                    |
| `user_agent`    | `TEXT NULL`            | Informasi browser atau perangkat yang dikirim saat mengakses aplikasi. |
| `payload`       | `LONGTEXT`             | Data sesi yang dibutuhkan aplikasi antarpermintaan.                    |
| `last_activity` | `INT`                  | Waktu aktivitas terakhir untuk menentukan kapan sesi kedaluwarsa.      |

**Relasi.** `sessions.user_id` mengaitkan sesi login dengan `users.id`. Migrasi memberi indeks pada kolom ini untuk pencarian berdasarkan pengguna.

## Tabel pendukung Laravel

| Tabel         | Kolom            | Tipe data           | Kegunaan                                                      |
| ------------- | ---------------- | ------------------- | ------------------------------------------------------------- |
| `cache`       | `key`            | `VARCHAR(255)`      | Kunci unik untuk mencari data cache.                          |
|               | `value`          | `MEDIUMTEXT`        | Isi data cache.                                               |
|               | `expiration`     | `BIGINT`            | Waktu cache kedaluwarsa.                                      |
| `cache_locks` | `key`            | `VARCHAR(255)`      | Kunci unik untuk mengenali operasi yang sedang dikunci.       |
|               | `owner`          | `VARCHAR(255)`      | Token proses pemegang kunci.                                  |
|               | `expiration`     | `BIGINT`            | Waktu kunci kedaluwarsa.                                      |
| `jobs`        | `id`             | `BIGINT UNSIGNED`   | ID unik pekerjaan dalam antrean.                              |
|               | `queue`          | `VARCHAR(255)`      | Nama antrean pekerjaan.                                       |
|               | `payload`        | `LONGTEXT`          | Data yang diperlukan untuk menjalankan pekerjaan.             |
|               | `attempts`       | `SMALLINT UNSIGNED` | Jumlah percobaan menjalankan pekerjaan.                       |
|               | `reserved_at`    | `INT UNSIGNED NULL` | Waktu pekerjaan diambil oleh proses antrean.                  |
|               | `available_at`   | `INT UNSIGNED`      | Waktu paling awal pekerjaan dapat diambil.                    |
|               | `created_at`     | `INT UNSIGNED`      | Waktu pekerjaan dimasukkan ke antrean.                        |
| `job_batches` | `id`             | `VARCHAR(255)`      | ID unik kumpulan pekerjaan.                                   |
|               | `name`           | `VARCHAR(255)`      | Nama kumpulan pekerjaan.                                      |
|               | `total_jobs`     | `INT`               | Jumlah seluruh pekerjaan dalam kumpulan.                      |
|               | `pending_jobs`   | `INT`               | Jumlah pekerjaan yang masih tercatat sebagai belum selesai.   |
|               | `failed_jobs`    | `INT`               | Jumlah pekerjaan yang gagal.                                  |
|               | `failed_job_ids` | `LONGTEXT`          | Daftar ID pekerjaan yang tercatat gagal dalam kumpulan.       |
|               | `options`        | `MEDIUMTEXT NULL`   | Opsi dan pengaturan kumpulan pekerjaan.                       |
|               | `cancelled_at`   | `INT NULL`          | Waktu kumpulan pekerjaan dibatalkan.                          |
|               | `created_at`     | `INT`               | Waktu kumpulan pekerjaan dibuat.                              |
|               | `finished_at`    | `INT NULL`          | Waktu kumpulan pekerjaan ditandai selesai.                    |
| `failed_jobs` | `id`             | `BIGINT UNSIGNED`   | ID unik catatan kegagalan.                                    |
|               | `uuid`           | `VARCHAR(255)`      | Identitas unik pekerjaan yang gagal.                          |
|               | `connection`     | `VARCHAR(255)`      | Nama koneksi antrean yang menjalankan pekerjaan.              |
|               | `queue`          | `VARCHAR(255)`      | Nama antrean tempat pekerjaan dijalankan.                     |
|               | `payload`        | `LONGTEXT`          | Data pekerjaan yang gagal, termasuk data untuk mencoba ulang. |
|               | `exception`      | `LONGTEXT`          | Detail kesalahan untuk diagnosis.                             |
|               | `failed_at`      | `TIMESTAMP`         | Waktu kegagalan dicatat.                                      |
| `migrations`  | `id`             | `INT UNSIGNED`      | ID unik catatan migrasi.                                      |
|               | `migration`      | `VARCHAR(255)`      | Nama migrasi yang sudah dijalankan.                           |
|               | `batch`          | `INT`               | Nomor kelompok eksekusi yang dipakai Laravel untuk rollback.  |

**Relasi.** Tabel pendukung berdiri sendiri di tingkat database. Laravel mengelola cache dan kunci berdasarkan `key`, serta mengaitkan pekerjaan dengan kumpulannya lewat data pekerjaan. Tabel `migrations` mencatat perubahan skema yang sudah diterapkan.

Sumber skema aplikasi ada di [folder migrasi](database/migrations/).
