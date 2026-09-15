# Backend — Laravel API

Laravel 10 / PHP 8.2 / PostgreSQL. Kode aplikasi ada di `src/`, konfigurasi deploy di root `backend/`.

Baca dulu [../CLAUDE.md](../CLAUDE.md) untuk konteks domain.

```
backend/
├── docker-compose.yml     # traefik + redis + api + posdata-worker
├── Dockerfile             # multi-stage: builder (composer) → runtime (nginx+fpm+supervisor)
├── docker/                # nginx.conf, default.conf, supervisord.conf, entrypoint.sh
├── traefik/
└── src/                   # aplikasi Laravel
```

---

## Arsitektur Layer

```
routes/api.php
   └── Controller (extends BaseApiController)   — routing, tidak ada logika bisnis
         ├── FormRequest (extends BaseRequest)  — validasi + normalisasi input
         ├── Service (extends BaseService)      — SEMUA logika bisnis & transaksi DB
         │     └── Model (extends BaseModel)    — UUID, softDelete, userstamps, scope
         └── Resource (extends BaseResource)    — bentuk JSON keluar
```

Aturan tegas: **kalau ada `if` yang menyangkut aturan bisnis di dalam Controller, itu salah tempat.** Pindahkan ke Service.

### `BaseApiController`
Menyediakan bentuk respons seragam:
- `success($data, $message, $code)` → `{ status, message, data }`
- `error($message, $code, $errors)` → `{ status, message, errors }`
- `resource($resource, ...)` → menangani `JsonResource` maupun `ResourceCollection`; kalau paginator, otomatis menambahkan blok `meta`.

Semua controller extend `BaseApiController`, jadi bentuk respons seragam: `{ status, message, data }` untuk sukses, `{ status, message, errors }` untuk gagal — termasuk `RoleMiddleware` dan `failedValidation()` di form request. `ResponseEnvelopeTest` menjaganya. Jangan tulis `response()->json()` manual di controller baru.

### `BaseService`
Query builder generik yang menerima `Request`:
- `?include=relasi,relasi` — eager load tambahan
- `?search=teks` — dicari di kolom `$searchable`
- `?<field>=nilai` — filter langsung, hanya untuk field di `$searchable`
- `?sort=field,-field` — `-` berarti descending, hanya untuk field di `$sortable`
- `?per_page=15` atau `?per_page=all`

Subclass cukup mendeklarasikan `$model`, `$relations`, `$searchable`, `$sortable`. `delete()` otomatis men-set `is_active = 0` sebelum soft delete bila kolomnya ada.

### `Services\Concerns\ResolvesOutletBrand`
Satu outlet melayani satu brand, jadi pemanggil cukup tahu outlet. Trait ini memegang aturan penyaringannya di satu tempat — dipakai `MenuService`, `PlateColorService`, `ProductionDashboardService`, `ProductionItemService`, dan `ProductionPlanService`. Aturannya: baris milik brand outlet ikut, baris ber-`brand_id` NULL juga ikut, baris brand lain tidak. Outlet tanpa brand tidak menyaring apa pun. **Jangan tulis ulang aturan ini di service baru** — kalau ada dua versi, keduanya akan menyimpang pelan-pelan.

### `Support\Uuid`
Di PostgreSQL `uuid` adalah tipe sungguhan: membandingkannya dengan string sembarang melempar `22P02` — 500 dengan SQL bocor ke klien, bukan 404. Nilai id datang dari query string, body, dan segmen URL, jadi apa pun bisa masuk.

Tiga aturan yang menyertainya:
1. Setiap `exists:` pada tabel ber-primary-key uuid **harus** didahului rule `uuid`. Rule `exists` menjalankan query sendiri — tanpa penjaga, validatornya yang menjawab 500.
2. Endpoint yang meneruskan id mentah ke `where()` harus memvalidasi bentuknya. Filter outlet yang salah bentuk dijawab 422, jangan diabaikan diam-diam — mengabaikan filter outlet berarti membocorkan data lintas outlet.
3. `whereIn()` pada kolom uuid yang nilainya berasal dari kolom `varchar` (`production_items.plate_color`, `waste_records.plate_color`) harus disaring `Uuid::matches()` dulu.

Dijaga `MalformedUuidInputTest`.

### `BaseAggregateService`
Untuk agregat header+item (`ProductionPlan`+items, `SalesHeader`+items+details). Menyediakan `createItems()` / `syncItems()` yang bisa di-override — `SalesService` meng-override keduanya untuk menghitung ulang `selisih` dan mengelola level ketiga (`sales_item_details`).

---

## Traits Model

| Trait | Fungsi |
|-------|--------|
| `HasUuid` | Generate UUID di `creating`, set `incrementing = false`, `keyType = 'string'` |
| `HasUserstamps` | Isi `created_by`/`updated_by`/`deleted_by` dari `Auth::id()` |
| `HasActive` | Scope untuk kolom `is_active` |
| `NormalizeMySqlDates` | Normalisasi format tanggal |
| `RunningNumberModuleTrait` | Generator nomor urut dokumen |

`BaseModel` sudah memakai `SoftDeletes + HasUserstamps + HasUuid`. **Jangan override `boot()` di model turunan** — event `creating`/`updating` sudah ditangani trait.

Migration memakai macro `$table->fullstamps()` (mendefinisikan `created_by`/`updated_by`/`deleted_by`), terdaftar di `AppServiceProvider`.

---

## Routing

`routes/api.php`. Ada macro `Route::crud($uri, $controller, $name)` di `RouteServiceProvider` yang meng-generate 5 route dan memetakannya ke method `{$name}Index`, `{$name}Store`, `{$name}Show`, `{$name}Update`, `{$name}Destroy` pada satu controller. Dipakai oleh `MasterController` untuk platecolor / menu / outlet / waste-reason.

**Urutan route penting.** Route statis harus dideklarasikan sebelum wildcard `/{id}`, kalau tidak akan tertangkap `show()`. Sudah ada komentar peringatan di `routes/api.php` soal `/sales/by-date`.

**Segmen id wajib dibatasi bentuknya.** Setiap route `{id}` memakai `->whereUuid('id')` — `users/{id}` memakai `->whereNumber('id')` karena `users.id` auto-increment. Tanpa itu `find('abc')` di kolom `uuid` menjawab 500 (`22P02`), bukan 404. Macro `Route::crud` sudah memasangnya sendiri. `MalformedUuidInputTest` menolak route bersegmen id yang tidak dibatasi.

### Status proteksi route
**Semua** route domain ada di belakang `auth:api`. Yang tidak memakainya cuma tiga: `/login`, `/login-pin`, dan `/auth/refresh` — dua pertama memang pintu masuk, yang ketiga memvalidasi tokennya sendiri di controller (lihat bagian Auth). `/register` **bukan** route publik meski namanya terdengar begitu: ia kena `auth:api` + `role:admin`, karena menetapkan `role` dan `module_app` dari payload.

**Dua sumbu, dua middleware.** `role:` menjawab *boleh melakukan apa*, `module:` menjawab *boleh sampai ke mana*. Banyak rute memakai keduanya, dan keduanya harus lolos.

| Grup | `role:` | `module:` |
|------|---------|-----------|
| `/master/*` tulis | `admin` | `admin` |
| `/master/*` **baca** | — | — |
| `/users/*` | `admin` | `admin` |
| `POST /production/import-backdate*` | `admin` | `admin` |
| `/production/*` sisanya | — | `kitchen,service,production,report` |
| `/reports/*` | — | `operation,report` |
| `/sales/*` | — | `operation` |
| `/closing-reports/*` | — | `operation,report` |
| `DELETE /closing-reports/{id}` | `admin,manager` | `operation,report` |
| `/waste/*` | — | `production` |

**Baca master sengaja tanpa gerbang apa pun.** `OutletProvider` memanggil `/master/outlet` di layout **setiap** modul, dan layar production maupun kitchen menyaring menu lewat plate color. Gerbang lamanya `role:admin,kitchen` membuat role `manager`, `operation`, dan `production` dijawab 403 di sini — selector outlet kosong, seluruh modul mereka berhenti mengambil data, tanpa pesan apa pun. Yang sensitif adalah menulisnya. Batas datanya tetap ada lewat `outlet.access`.

**Import backdate ada di blok `prefix('production')` sendiri, bukan nested.** Modulnya `admin`, sedangkan grup production milik modul dapur/produksi/report. Kalau dinested, kedua gerbang modul harus lolos sekaligus — dan tidak ada user yang bisa memenuhi keduanya.

**`ModuleAccess` tidak punya jalan pintas untuk `admin`**, beda dengan `OutletAccess`. Kalau seorang admin hanya diberi modul `admin`, itu memang yang dimaksud orang yang menyetelnya.

`RouteProtectionTest` menjaga agar tidak ada yang bocor lagi — tambahkan entri di data provider-nya saat menambah route baru. Batas modulnya diuji `ModuleAccessTest`; keselarasan role↔modul diuji `RoleModuleConsistencyTest`.

**Sebelum menyalakan ini di lingkungan yang sudah berisi data, jalankan `php artisan users:audit-access`.** Sampai `module:` ada, tidak ada yang memaksa `module_app` konsisten — user yang modulnya tidak cocok akan mendadak kena 403.

---

## Middleware

**`Idempotency`** (global di grup `api`) — kunci keandalan sistem ini.
- Aktif untuk POST/PUT/PATCH yang membawa header `X-Client-Request-Id`.
- Melewatkan endpoint auth (`login`, `login-pin`, `logout`, `register`, `auth/refresh`).
- Menyimpan respons **sukses saja** (2xx) ke tabel `processed_requests`; 4xx/5xx dibiarkan bisa dijalankan ulang.
- Request ulang dengan key sama → respons tersimpan diputar ulang + header `X-Idempotent-Replay: true`.
- `QueryException` saat menyimpan (race condition dua request bersamaan) sengaja ditelan.

**`RoleMiddleware`** (alias `role`) — `role:admin,kitchen` mengizinkan salah satu. Menolak dengan 403 `{ success: false, message: 'Unauthorized access' }`.

**`ModuleAccess`** (alias `module`) — `module:operation,report` mengizinkan salah satu, dibaca dari `users.module_app`. Amplop errornya sama, pesannya `'Modul ini tidak termasuk akses Anda'`. `module_app` kosong atau `null` berarti **tidak punya modul**, bukan punya semuanya — gagal-tertutup, sama seperti `AuthGuard` di frontend. Bentuk JSON lama ikut dinormalkan, sama seperti `RoleMiddleware::rolesOf()`.

Sebelum ini ada, `module_app` cuma dipakai frontend: daftarnya ikut di klaim JWT tapi server tidak pernah membacanya, jadi siapa pun yang memegang token bisa memanggil endpoint modul mana pun. Halaman yang tidak dirender bukan endpoint yang tertutup.

**Throttle** — 60 request/menit per user-id (atau IP kalau anonim), didefinisikan di `RouteServiceProvider`.

---

## Auth

JWT via `tymon/jwt-auth`. Guard `api`. Sanctum masih ter-install dan dipakai di satu route `/user` sisa scaffolding, tapi bukan mekanisme utama.

Custom claims di token: `role`, `departemen`, `outlet`, `module_app`. **PIN sengaja tidak ikut** — payload JWT hanya base64, bukan enkripsi.

**PIN.** `users.pin` adalah bcrypt; `users.pin_lookup` adalah HMAC-SHA256(pin, `APP_KEY`) yang unik dan ter-index. Keduanya ditulis oleh mutator `User::setPinAttribute()` — jangan pernah isi `pin_lookup` langsung, dan jangan tambahkan cast `hashed` ke `pin` (akan double-hash). `loginByPin()` mencari lewat `pin_lookup` lalu verifikasi `Hash::check()`. Endpoint-nya kena `throttle:20,1` — bukan lebih ketat, karena satu outlet penuh tablet berbagi satu IP dan pergantian shift tidak boleh mengunci dapur.

**Umur token.** `JWT_TTL` 60 menit, `JWT_REFRESH_TTL` 14 hari — nilai default `config/jwt.php`, kini ditulis eksplisit di `.env.example` supaya jadi keputusan yang terlihat. `.env` produksi belum menyebutnya, jadi di sana masih default. Blacklist aktif dengan grace period 0, jadi token yang sudah ditukar atau di-logout langsung mati.

**`.env.example` dijaga `EnvExampleTest`.** Berkas itu pernah tidak mencantumkan `JWT_SECRET` sama sekali — setup baru gagal di seluruh jalur auth tanpa petunjuk apa yang kurang — dan kredensial RabbitMQ juga hilang. Saat menambah env var yang ketiadaannya mematikan sesuatu, tambahkan juga entri di data provider test itu.

**`POST /auth/refresh` sengaja TIDAK di belakang `auth:api`.** Guard itu memvalidasi klaim `exp`, jadi token yang sudah mati ditolak 401 sebelum controller jalan — endpoint refresh pun hanya melayani token yang belum perlu di-refresh, kebalikan dari gunanya. Otorisasinya tetap ada, hanya pindah ke dalam controller: `JWTAuth::parseToken()->refresh()` menyalakan refresh flow (`exp` diabaikan, yang berlaku `iat + refresh_ttl`) dan tetap menolak token rusak, yang sudah di-blacklist, dan yang lewat jendela refresh. Ketiganya dijawab 401 dengan envelope standar. Dijaga `TokenRefreshTest`.

Jangan kembalikan `auth:api` ke rute itu. Akibatnya tidak kelihatan di test yang memakai token segar — dan di lapangan berarti tablet dapur kehilangan sesi tiap 60 menit.

---

## Service Penting

### `ProductionService` — agregator
Bukan service berisi logika, tapi kumpulan sub-service yang di-inject ke controller:

```php
$this->service->dashboard      // ProductionDashboardService  — stats
$this->service->plan           // ProductionPlanService       — plan CRUD
$this->service->item           // ProductionItemService       — piring (inti)
$this->service->wasteRecord    // WasteRecordService          — MENULIS waste_records
$this->service->wasteReport    // WasteService                — MEMBACA laporan waste
$this->service->backdateImport   // ProductionBackdateImportService   — impor .xlsx/CSV hari lalu
$this->service->backdateTemplate // ProductionBackdateTemplateService — template .xlsx impor tsb
```

### `Production/ProductionItemService`
Pusat aturan bisnis piring.

| Method | Catatan |
|--------|---------|
| `produce()` | Membuat N baris terpisah (quantity=1 masing-masing) dalam satu transaksi. `expires_at = now + menu.shelf_life ?? 60` menit. |
| `conveyor()` / `expired()` | **Murni baca.** Memfilter lewat `expires_at` (sumber kebenaran) dan menyegarkan `belt_status` hanya di memori untuk respons. Kolom tersimpan disegarkan `production:refresh-belt-status` tiap menit. |
| `markSold()` / `markWaste()` | Dilindungi `assertWithinProductionDay()` — lempar `BusinessRuleException` kalau ada id dari hari sebelumnya. |
| `autoWasteCarryOver()` | Menutup piring hari lalu jadi waste. `wasted_at` = `produced_at`, **bukan** `now()`. Juga menulis `WasteRecord`. |
| `countUnresolved()` | Gerbang untuk "Get Data POS". |
| `getSoldItem()` / `getWasteItem()` | Join ke `plate_colors` dengan cast `plate_colors.id::text` — karena `production_items.plate_color` bertipe `varchar` sementara `plate_colors.id` bertipe `uuid`. |

Ketidakcocokan tipe `varchar` vs `uuid` ini muncul di beberapa tempat. `WasteAnalysisService` menghindarinya dengan tidak melakukan JOIN sama sekali dan meresolusi nama plate color di PHP — pola ini lebih portabel (test jalan di SQLite, produksi di PostgreSQL). **Ikuti pola itu untuk query baru.**

### `Production/ProductionBackdateImportService`
Impor produksi hari lalu dari .xlsx atau CSV — satu-satunya jalur yang boleh menulis `final_status` di luar hari produksi, karena itu `role:admin`. `preview()` hanya membaca (tiap baris membawa `errors[]`-nya sendiri), `import()` menulis dalam satu transaksi dan menolak seluruh berkas kalau ada satu baris salah. Atribusi waktunya mengikuti `autoWasteCarryOver()`: `sold_at`/`wasted_at`/`recorded_at` = `produced_at`, bukan `now()`. Kode menu diresolusi lewat brand outlet (`ResolvesOutletBrand`) karena `menus.code` unik per brand. Bentuk berkas didokumentasikan di [../docs/api-reference.md](../docs/api-reference.md).

Format dikenali dari isi berkas (tanda tangan zip), bukan ekstensinya, lalu keduanya diubah jadi matriks string sebelum satu pemeriksa yang sama memegang header, batas, dan baris kosong — supaya .xlsx dan CSV tidak punya dua daftar aturan. Baris yang `date`, `quantity`, dan `final_status`-nya sama-sama kosong dilewati: template mengirim `menu_code` untuk semua menu aktif, jadi tanpa aturan itu menu yang tidak diproduksi jadi baris error.

### `Production/ProductionBackdateTemplateService`
Merakit workbook .xlsx berisi tiga sheet (data siap isi, panduan, daftar menu aktif) dengan `phpoffice/phpspreadsheet`. Menunya diambil lewat `ResolvesOutletBrand` — aturan brand yang sama dengan importer, supaya template tidak menawarkan menu yang justru ditolak saat diunggah. Angka di sheet panduan dibaca dari konstanta publik `ProductionBackdateImportService` (`MAX_ROWS`, `MAX_PLATES`, `MAX_AGE_DAYS`, `DEFAULT_TIME`, alias header/status), bukan diketik ulang. **Butuh ekstensi PHP `zip` dan `gd`** — keduanya dipasang di kedua stage `Dockerfile`.

### `ClosingReport/ClosingReportService`
- `getData()` dan `submit()` sama-sama mensyaratkan `SalesHeader` dengan `status = 'submitted'` di tanggal yang sama, kalau tidak → `BusinessRuleException`.
- Entry laporan diturunkan dari `sales_items`, bukan dihitung ulang dari `production_items`.
- Hanya laporan `draft` yang boleh dihapus.
- `closing_reports` unik per (`outlet_id`, `date`).

### `POSService` + `RabbitConsumePOSData`
Worker AMQP jangka panjang: exchange `posdata_exchange` (direct), queue `posdata.queue`, routing key `posdata.created`. QoS prefetch 1, ack manual, nack tanpa requeue kalau gagal, reconnect loop 5 detik.

`storeFromEvent()` memetakan payload berdasarkan **nama**, tapi urutannya penting: **outlet diresolusi duluan**, lalu plate color dicari **di dalam brand outlet itu**. Sejak plate color jadi milik brand, nama warna saja tidak lagi menunjuk satu baris — pencarian global akan mengambil piring brand lain, dan yang salah adalah angka penjualan. Warna ber-`brand_id` NULL tetap diterima selama tidak ambigu (kelonggaran transisi); apa pun yang ambigu dilempar, tidak ditebak. `outlet` dicocokkan ke `outlets.code`, keduanya dinormalisasi (lowercase, strip non-alfanumerik, rapatkan spasi). Perilaku upsert: kalau (outlet, plate color, date) sudah ada, `sold` ditimpa.

Kalau pemetaan gagal, payload diparkir ke tabel `failed_pos_messages` lalu di-ack — tidak hilang seperti dulu. Perbaiki master data, lalu `php artisan pos:replay-failed` (ada `--dry-run` dan `--id=`).

---

## Testing

```bash
cd src && php artisan test
php artisan test --filter=ProductionItemTest
```

PHPUnit 10, SQLite in-memory (`phpunit.xml`). Helper di `tests/Concerns/`: `CreatesUsers`, `SeedsProductionData`.

Karena test berjalan di SQLite tapi produksi di PostgreSQL: **selalu beri alias eksplisit pada agregat** (`SUM(quantity) as total`) — nama kolom hasil `SUM()` tanpa alias berbeda antar driver. Sudah ada komentar soal ini di `WasteAnalysisService`.

---

## Deploy

`Dockerfile` multi-stage: stage builder hanya menjalankan `composer install --no-dev`; stage runtime menyalin hasilnya ke image nginx + php-fpm + supervisor.

**Cache config dibangun saat container start, bukan saat build.** `docker/entrypoint.sh` menjalankan `config:cache` + `route:cache` + `view:cache` setelah environment masuk, lalu `exec supervisord`. Urutan ini penting: config yang di-cache mengalahkan environment variable, jadi meng-cache sebelum env ada akan membekukan nilai yang salah secara diam-diam. Konsekuensinya, **mengubah environment variable butuh restart container**, bukan sekadar reload.

`src/.env` **tidak** ikut ke dalam image (`.dockerignore`). Kredensial masuk saat runtime lewat `env_file: ./src/.env` di `docker-compose.yml`, jadi file `.env` produksi harus ada di server. Blok `environment:` di compose menang atas `env_file`.

**Scheduler dijalankan supervisord (`artisan schedule:work`), bukan cron.** Jangan kembalikan ke `/etc/cron.d` — versi cron-nya pernah mati diam-diam selama berbulan-bulan (path salah, daemon tidak pernah dijalankan, file ter-checkout CRLF). Ada `.gitattributes` yang memaksa LF untuk `docker/**`, `*.sh`, dan `*.conf`; jangan dihapus.

Traefik menangani TLS otomatis (Let's Encrypt, tlschallenge) untuk `api.maharasa.calira.my.id`, dengan redirect HTTP→HTTPS.

`posdata-worker` memakai image yang sama dengan `api` tapi menimpa `supervisord.conf` agar menjalankan consumer RabbitMQ, bukan web server.
