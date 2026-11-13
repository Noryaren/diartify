# Diarify

**Personal media player** yang terinspirasi dari
[Navidrome](https://www.navidrome.org) untuk sisi server musiknya, dan dari
tampilan Spotify untuk sisi pengalamannya — tapi **100% gratis** dan
**semua datanya sepenuhnya kamu yang kendalikan**.

Dibuat dengan PHP 8.5 murni (tanpa framework/Composer), MariaDB, dan
JavaScript vanilla. Tidak ada build step, tidak ada layanan pihak ketiga.

> Tidak berafiliasi dengan Navidrome maupun Spotify. Hanya terinspirasi.

## Kenapa Diarify

- **Gratis total, selamanya.** Tidak ada langganan, tidak ada fitur terkunci,
  tidak ada iklan, tidak ada batas jumlah lagu maupun pengguna. Tidak ada
  paket "premium" — semua fitur terbuka sejak awal.
- **Datanya milikmu sepenuhnya.** Berkas musik tetap di folder milikmu, tidak
  pernah dipindai atau diunggah ke mana pun. Database ada di MariaDB yang kamu
  hosting sendiri. Sampul album di-cache lokal di `storage/covers/`. Akun,
  like, playlist, dan statistik pemutaran tersimpan di database milikmu.
- **Tanpa ketergantungan cloud.** Tidak ada akun pihak ketiga, tidak ada API
  key, tidak ada telemetri, tidak ada analitik, tidak ada layanan pembayaran.
  Tidak ada satu byte pun yang dikirim keluar dari servermu.
- **Bisa dibawa pergi kapan saja.** Tidak ada data yang dikunci di platform:
  dump MariaDB, salin folder musik dan `storage/`, selesai — pindah server atau
  berhenti memakai sama sekali tidak perlu meminta izin ke siapa pun.
- **Ringan untuk server kecil.** Tanpa framework, tanpa build step, tanpa
  antrian worker: satu proses PHP-FPM dan satu container MariaDB sudah cukup.

### Terinspirasi dari

- **Navidrome** — sisi *server musik self-hosted*: pemindai tag otomatis,
  streaming dengan HTTP Range, pustaka yang tidak pernah mengubah berkas
  aslinya, multi-user, dan kontrol penuh atas file di disk.
- **Spotify** — sisi *tampilan dan interaksi*: tema gelap, kartu album,
  beranda personal, antrian pemutar, lagu disukai, playlist, dan pencarian.

## Fitur

- Pemutar musik: antrian, acak, ulang (off/semua/satu), seek, volume,
  pemutaran di latar belakang (`MediaSession`), posisi & antrean disimpan
  di browser.
- Pustaka otomatis: pemindai membaca tag audio (ID3v2.2/2.3/2.4, ID3v1,
  FLAC, Ogg Vorbis/Opus, MP4/M4A, WAV, AAC) + durasi, album, artis, tahun,
  genre, lirik, dan sampul album.
- Streaming audio dengan HTTP Range (seek langsung, `206 Partial Content`).
- Sampul album di-cache sebagai gambar kecil di `storage/covers/`
  (WebP, fallback JPEG/PNG bila GD tanpa WebP), tidak membaca file MP3
  berulang.
- Beranda personal: baru diputar, artis/lagu teratas, pustaka terbaru.
- Album, artis, semua lagu, lagu disukai (like), pencarian.
- Playlist: buat, ubah, hapus, tambah/hapus lagu, geser urutan.
- Statistik pemutaran per pengguna (dipakai untuk "lagu teratas").
- Admin: pemindaian ulang pustaka lewat API/UI (tombol **Pindai Pustaka**).
- Multi-user: akun dibuat dari server lewat CLI; setiap pengguna punya
  like, playlist, dan statistik sendiri.
- Keamanan: `password_hash`, prepared statement, login stateless berbasis
  JWT (tanpa cookie sesi, tanpa CSRF), CSP dengan nonce.

## Kebutuhan server

- PHP 8.5+ dengan ekstensi: `pdo_mysql`, `gd` (disarankan + WebP), `mbstring`,
  `json`, `openssl`.
- MariaDB 10.6+ / MySQL 8+ (boleh di Docker).
- Web server dengan PHP-FPM (contoh: Caddy) dan ruang tulis untuk
  `storage/covers/`.
- Folder musik yang bisa dibaca user web (mis. `/opt/navidrome/music`).

Target yang sudah dicek: PHP 8.5.12 (CLI-FPM), Caddy 2.11, MariaDB di
container.

## Menjalankan lokal

Konfigurasi lokal ada di `config/config.php` (diabaikan Git) dan menunjuk ke
container MariaDB di port **3310**:

```bash
docker run -d --name diartify-db \
  -e MARIADB_DATABASE=diartify -e MARIADB_USER=diartify \
  -e MARIADB_PASSWORD=diartify_local -e MARIADB_ROOT_PASSWORD=diartify_root \
  -p 3310:3306 mariadb:latest

docker exec -i diartify-db mariadb -uroot -pdiartify_root diartify \
  < database/schema.sql
```

Masukkan berkas musik ke folder **`musik/`** di bawah proyek ini
(bisa diganti lewat env `MUSIC_PATH` atau `config/config.php`), lalu:

```bash
php bin/create-user.php "Nama" kamu@contoh.id     # user pertama jadi admin
php bin/scan.php                                  # pindai pustaka
php -S 127.0.0.1:8170 -t public public/index.php   # buka http://127.0.0.1:8170
```

Bila PHP lokal lebih lama dari 8.5, jalankan lewat container contohnya:

```bash
docker run --rm --network host \
  -v "$PWD":/app -w /app diarkeep-php85:test php bin/scan.php
```

## Struktur

```
diartify/
├── app/                 # bootstrap, db, auth, tag reader, scanner, API, stream
├── bin/                 # CLI: scan.php, create-user.php
├── config/              # config.example.php -> config.php (diabaikan Git)
├── database/schema.sql  # skema MariaDB
├── deploy/              # contoh Caddyfile + tuning PHP-FPM
├── public/              # document root
│   ├── index.php        # front controller
│   └── assets/          # app.css (tema gelap), app.js (SPA), logo, favicon
└── storage/
    ├── covers/          # sampul album (content-addressed, aman dari Git)
    └── .gitkeep
```

## Pemindaian pustaka

```bash
php bin/scan.php            # inkremental: hanya file baru/berubah
php bin/scan.php --full     # baca ulang seluruh tag
php bin/scan.php --quiet    # hanya ringkasan akhir
```

Pemindai aman dijalankan berulang: file yang tak berubah dilewati, file
yang dihapus dibersihkan dari database, tag yang rusak tidak menghentikan
scan, dan sampul lama diperbaiki bila kosong.

## Keamanan

- Login hanya email + password — tanpa 2FA, captcha, atau verifikasi.
- Autentikasi **stateless**: login mengirim JWT (HS256) yang disimpan di
  `localStorage` browser, lalu dikirim sebagai `Authorization: Bearer` di
  setiap panggilan API. Tidak ada cookie sesi dan tidak ada state di server,
  sehingga login tetap jalan di balik Cloudflare Tunnel walau tepiannya
  merewrite HTML.
- Token berlaku **1 tahun** (`app.token_ttl`). Untuk mencabut semua token,
  ganti isi `storage/jwt.secret`.
- `/stream` dan `/cover` dipanggil lewat tag `<audio>`/`<img>` yang tak bisa
  mengirim header, jadi URL-nya membawa token yang sama lewat `?t=`.
- CSP header + nonce untuk script; tanpa cookie, CSRF jadi tidak relevan.

## Instalasi di server

### 1. Salin aplikasi

Contoh di `/home/diartify`:

```bash
sudo mkdir -p /home/diartify
sudo rsync -a --exclude 'config/config.php' ./ /home/diartify/
sudo chown -R www-data:www-data /home/diartify/storage
sudo chmod -R 775 /home/diartify/storage
```

### 2. Buat database

Server ini sudah punya container MariaDB (`mariadb-main`, port **3307**):

```bash
docker exec -i mariadb-main mariadb -uroot -p'PASSWORD_ROOT' <<'SQL'
CREATE DATABASE IF NOT EXISTS diartify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'diartify'@'%' IDENTIFIED BY 'PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON diartify.* TO 'diartify'@'%';
FLUSH PRIVILEGES;
SQL
docker exec -i mariadb-main mariadb -uroot -p'PASSWORD_ROOT' diartify \
  < database/schema.sql
```

### 3. Konfigurasi

```bash
sudo cp config/config.example.php config/config.php
sudo nano config/config.php     # sesuaikan: DB (port 3307), folder musik
php bin/create-user.php "Diar" kamu@contoh.id
php bin/scan.php --full
```

### 4. Web server

Salin `deploy/Caddyfile.example` ke blok situs baru di
`/etc/caddy/Caddyfile` (padukan dengan situs lain, jangan timpa file),
kemudian:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

PHP-FPM Debian jalan dengan socket `/run/php/php8.5-fpm.sock`; bila perlu
kurangi memori, lihat `deploy/php-fpm-tuning.conf`.

### 5. Cloudflare Tunnel

Hostname baru ditambahkan di dashboard Cloudflare Zero Trust → Tunnel →
**Public Hostname**: `music.<domain>` → `http://localhost:80`
(HTTP; TLS diakhiri di edge). Tidak ada perubahan ingress lokal karena
`cloudflared` memakai token dashboard.

### 6. Musik

Arahkan `music_paths` ke `/opt/navidrome/music` (contoh), pastikan user
`www-data` bisa membacanya:

```bash
sudo setfacl -R -m u:www-data:rX /opt/navidrome/music
```

## Format yang didukung

`mp3`, `flac`, `ogg/oga`, `opus`, `m4a/mp4`, `aac`, `wav`, `wma`
(extension); sampul dari tag atau gambar folder
(`cover.jpg`, `folder.jpg`, `front.jpg`, `AlbumArt*.jpg`, dsb.).

## Catatan teknis

- Tanpa SQLite (ekstensi tidak tersedia di server) — semua lewat MariaDB.
- `app/tags.php` pembaca tag murni PHP: frame v2.4 memakai ukuran syncsafe,
  teks UTF-16/UTF-16LE didekode tanpa memotong byte terakhir, bitrate
  dinormalkan ke kbps.
- Seluruh interaksi UI memakai event delegation (CSP tanpa inline handler).
