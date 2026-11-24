# Rumus Monitoring Progres SiMonitoringGalangan

Setiap periode menghitung progres fisik dari bobot pada revisi Work Breakdown Structure (WBS) yang dirujuk oleh periode tersebut dan dari pengukuran yang dicatat untuk periode itu. Periode lama tetap memakai revisinya ketika baseline baru ditetapkan. Angka realisasi pada tiap periode sudah mewakili posisi kumulatif saat laporan dibuat. Riwayat menampilkan hasil per periode tanpa menjumlahkan realisasi antarperiode.

## 1. Bobot WBS dan validasi baseline

| Besaran | Satuan | Arti |
| --- | --- | --- |
| Bobot tahap | % | Porsi tahap dalam satu item. Daftar tahap dan bobotnya berlaku untuk semua item di kelompok yang sama. |
| Bobot item | % | Porsi satu item terhadap seluruh proyek. |
| Bobot kelompok | % | Jumlah bobot semua item dalam kelompok. |
| Realisasi tahap | % | Persentase penyelesaian tahap pada item yang diukur. |
| Kontribusi | pp | Porsi realisasi item atau kelompok terhadap realisasi fisik seluruh proyek, dalam poin persentase. |

Saat menetapkan baseline, jumlah bobot semua item di seluruh proyek dan jumlah bobot tahap di **masing-masing** kelompok harus sama dengan 100%. Setiap bobot berada dalam rentang 0–100% dan boleh memiliki hingga enam angka desimal. Backend memeriksa jumlah dalam satuan bilangan bulat agar ketelitian enam desimal tetap terjaga.

```text
Satuan bobot = bulatkan(bobot × 1.000.000)
Jumlah satuan bobot yang wajib terpenuhi = 100.000.000
```

Di Form Builder, **Bobot proyek** menjumlahkan bobot item dari seluruh kelompok. **Tahap kelompok** menjumlahkan bobot tahap pada kelompok yang sedang dipilih.

Sumber [validasi dan penyimpanan baseline](app/Http/Controllers/MonitoringController.php#L125) dan [Form Builder](resources/js/components/wbs-editor.tsx#L51).

## 2. Progres item, kelompok, dan proyek

| Hasil | Rumus | Kapan hasil tersedia |
| --- | --- | --- |
| Progres item (%) | Jumlah dari `bobot tahap × realisasi tahap ÷ 100` untuk setiap tahap yang terukur pada item | Minimal satu tahap pada item terukur. |
| Kontribusi item (pp) | `bobot item × progres item ÷ 100` | Progres item tersedia. |
| Bobot kelompok (%) | Jumlah bobot semua item dalam kelompok | Selama kelompok ada dalam WBS. |
| Kontribusi kelompok (pp) | Jumlah kontribusi item yang tersedia dalam kelompok | Minimal satu item memiliki kontribusi, termasuk 0 pp. |
| Realisasi kelompok (%) | `100 × kontribusi kelompok ÷ bobot kelompok` | Kontribusi kelompok tersedia dan bobot kelompok lebih dari 0%. |
| Realisasi fisik proyek (%) | Jumlah kontribusi semua kelompok yang memiliki nilai | Minimal satu tahap dalam proyek terukur. Periode dengan angka ringkasan proyek dapat memakai nilai cadangan pada bagian 3. |

Persentase dimasukkan dalam skala 0–100. Tahap berbobot 60% dengan realisasi 50% menyumbang `60 × 50 ÷ 100 = 30` poin persentase ke progres item. Pratinjau di **Input Progres** memakai rumus progres item yang sama terhadap nilai yang sedang diisi.

Tahap kosong dilewati dan bobot tahap lain tetap. Item yang semua tahapnya kosong memiliki progres dan kontribusi kosong. Nilai 0% merupakan pengukuran yang sah dan menghasilkan kontribusi nol. Untuk realisasi kelompok, pembaginya tetap bobot seluruh item dalam kelompok, termasuk item yang belum diukur.

Sumber [kalkulator progres](app/Support/ProgressReport.php#L9), [pratinjau input](resources/js/pages/dashboard.tsx#L180), dan [realisasi kelompok](resources/js/pages/dashboard.tsx#L477).

## 3. Kelengkapan, target, dan deviasi jadwal

```text
Jumlah pengukuran yang diharapkan = Σ(jumlah item × jumlah tahap) per kelompok
Jumlah pengukuran tercatat        = jumlah pasangan item dan tahap dengan nilai terisi
Parsial                          = reported_actual kosong dan jumlah tercatat < jumlah yang diharapkan
```

Nilai 0% ikut menambah jumlah pengukuran tercatat. Jika belum ada pengukuran, hasil kalkulator kosong. Bila periode menyimpan `reported_actual`, aplikasi memakai angka itu sebagai realisasi proyek ketika hasil kalkulator kosong. Angka ringkasan tersebut hanya mengisi realisasi proyek. Progres item dan kontribusi kelompok masih memerlukan data tahap.

**Target rencana** adalah persentase kumulatif yang diisi untuk periode, dalam rentang 0–100% hingga empat desimal. Target dan realisasi sama-sama harus tersedia untuk menghitung deviasi. Nilai 0% berlaku sebagai angka untuk keduanya.

```text
Deviasi jadwal (pp) = realisasi fisik proyek (%) − target rencana (%)
```

| Deviasi | Arti |
| --- | --- |
| Positif | Realisasi melampaui target. |
| Nol | Realisasi sama dengan target. |
| Negatif | Realisasi di bawah target. |
| Kosong | Target atau realisasi belum tersedia. |

Realisasi tahap disimpan hingga empat desimal. Perhitungan laporan menggunakan nilai tersimpan. Tampilan memformat persentase dan poin persentase dengan dua angka desimal dalam format Indonesia. Nilai kosong tampil sebagai **Belum ada data**.

Sumber [jumlah pengukuran](app/Support/ProgressReport.php#L20), [penyusunan laporan per periode](app/Http/Controllers/MonitoringController.php#L43), [validasi target dan pengukuran](app/Http/Controllers/MonitoringController.php#L164), dan [format angka](resources/js/types/monitoring.ts#L60).

## 4. Contoh hitung manual

Kelompok **Lambung** memiliki tahap Persiapan (60%) dan Pengecatan (40%). Kelompok **Mesin** memiliki satu tahap Pengujian (100%). Bobot item berjumlah `30 + 20 + 50 = 100%`.

| Kelompok | Item | Bobot item | Realisasi tahap | Progres item | Kontribusi proyek |
| --- | --- | ---: | --- | ---: | ---: |
| Lambung | Pelat | 30% | Persiapan 50%, Pengecatan 100% | `(60 × 50 + 40 × 100) ÷ 100 = 70%` | `30 × 70 ÷ 100 = 21 pp` |
| Lambung | Dek | 20% | Persiapan 0%, Pengecatan kosong | `60 × 0 ÷ 100 = 0%` | `20 × 0 ÷ 100 = 0 pp` |
| Mesin | Mesin utama | 50% | Pengujian 40% | `100 × 40 ÷ 100 = 40%` | `50 × 40 ÷ 100 = 20 pp` |

- **Lambung** berbobot 50%, menyumbang `21 + 0 = 21 pp`, dan memiliki realisasi kelompok `100 × 21 ÷ 50 = 42%`.
- **Mesin** berbobot 50%, menyumbang 20 pp, dan memiliki realisasi kelompok `100 × 20 ÷ 50 = 40%`.
- **Proyek** memiliki realisasi `21 + 20 = 41%`. Dengan target 45%, deviasinya `41 − 45 = −4 pp`.
- **Kelengkapan** adalah empat dari lima pengukuran. Jika angka ringkasan proyek kosong, laporan berstatus **Parsial** karena Pengecatan pada item Dek belum diukur.

Kasus pengukuran kosong, 0%, realisasi parsial, dan pemakaian revisi baseline lama juga dicakup oleh [pengujian monitoring](tests/Feature/MonitoringTest.php#L107).

## 5. Kurva S pembangunan

Kurva S memplot target dan realisasi terhadap tanggal akhir setiap periode. Posisi horizontal mengikuti jarak waktu antarperiode. Posisi vertikal mengikuti persentase pada skala 0–100.

```text
Rentang waktu = tanggal akhir periode terakhir − tanggal akhir periode pertama
x = 48 + 680 × (tanggal akhir periode − tanggal akhir periode pertama) ÷ rentang waktu
x = 388 jika rentang waktu nol
y = 170 − 1,45 × nilai persentase
```

Konstanta tersebut adalah koordinat SVG. Titik hanya tampil untuk nilai yang tersedia. Garis menghubungkan dua periode berurutan jika keduanya memiliki nilai pada seri yang sama. Titik realisasi parsial tampil sebagai lingkaran berongga.

Sumber [komponen Kurva S dan rincian historis](resources/js/components/progress-history.tsx#L3).

## 6. Data demo ASD Harbour Tug

Pada data sumber Excel, bobot mentah item dihitung sebagai `100 × estimasi biaya item ÷ total Engineering Estimate` agar hasilnya berada pada skala 0–100%. Total Engineering Estimate berasal dari `Summary EE!F18`. Bobot dalam data demo sudah disiapkan hingga enam desimal. Sisa unit pembulatan dibagikan menurut pecahan terbesar agar jumlahnya tetap 100%. Seeder menyalin bobot tersebut ke baseline dan membulatkan realisasi tahap minggu ke-14 hingga empat desimal sebelum menyimpannya.

| Periode | Sumber realisasi proyek |
| --- | --- |
| Minggu 1–13 | Angka tingkat proyek dari sheet EVM, disimpan sebagai `reported_actual`. |
| Minggu 14 | Hasil perhitungan aplikasi dari 2.550 pengukuran tahap simulasi. Pada tampilan, realisasi **79,83%**, target **98,50%**, dan deviasi **−18,67 pp**. |
| Minggu 15–16 | Realisasi kosong. Kurva masih menampilkan target yang tersedia. |

Metadata Excel juga mencatat biaya simulasi `EV ÷ asumsi CPI`, dengan EV sebagai *Earned Value* dan CPI sebagai *Cost Performance Index*. Biaya riil pada data demo kosong. Ringkasan aplikasi menampilkan **Belum ada data** untuk Engineering Estimate dan memakai bobot WBS yang sudah tersedia untuk perhitungan progres fisik.

Sumber [data demo](database/seeders/asd-harbour-tug.json), [seeder ASD Harbour Tug](database/seeders/AsdHarbourTugDemoSeeder.php#L60), dan [pengujian minggu ke-14](tests/Feature/AsdHarbourTugDemoSeederTest.php#L16).
