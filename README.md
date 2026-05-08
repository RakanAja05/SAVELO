# Savelo

Savelo adalah platform perencanaan perjalanan berbasis budget yang membantu traveler membuat itinerary, menemukan destinasi terverifikasi, dan mendapatkan reward dari pilihan perjalanan yang lebih sadar lingkungan.

## Fitur Utama

### Feature 01 - Budget First Planner
Fitur inti Savelo. User memasukkan parameter perjalanan dan sistem menghasilkan tiga opsi itinerary otomatis yang tetap dalam budget.

Behavior
- Input form: budget total (Rp), lokasi asal, destinasi, durasi (hari), jumlah orang.
- AI Gemini menghasilkan 3 opsi itinerary dengan karakter berbeda: Hemat, Seimbang, Experience.
- Setiap itinerary memiliki Price Tier per destinasi (contoh: Rp50.000-75.000).
- Live budget counter update real-time saat user modifikasi itinerary.
- Estimasi travel time dan distance antar destinasi.

User Story
- US-01: Sebagai traveler yang sadar budget, saya ingin memasukkan total budget, durasi, dan jumlah orang agar mendapat 3 opsi itinerary yang sesuai tanpa hitung manual.

Acceptance Criteria
- User dapat input budget minimum Rp100.000.
- Sistem generate 3 itinerary dalam waktu kurang dari 30 detik.
- Live budget counter terupdate setiap ada perubahan.
- Setiap itinerary menampilkan total estimasi biaya yang tidak melebihi budget input.

### Feature 02 - Inclusivity Filter
Filter aksesibilitas yang diverifikasi manual oleh tim kurasi menggunakan checklist standar.

Behavior
- 4 kategori filter: Wheelchair Accessible, Stroller Friendly, Child-Safe Environment, Lansia Friendly.
- Filter dapat dikombinasikan (multi-select).
- Data aksesibilitas diverifikasi manual oleh tim kurasi lapangan dengan checklist standar.
- Ditampilkan sebagai badge verified di Destination Card.

User Story
- US-02: Sebagai pengguna kursi roda, saya ingin memfilter destinasi berdasarkan aksesibilitas agar yakin tempat yang dikunjungi benar-benar aksesibel.

Acceptance Criteria
- Semua 4 filter tersedia dan dapat dipilih secara kombinasi.
- Hanya destinasi dengan badge verified yang muncul saat filter aktif.

### Feature 03 - Discovery Map
Peta interaktif berbasis Google Maps API untuk eksplorasi destinasi.

Behavior
- Berbasis Google Maps API.
- Color-coded pins: UMKM (hijau), Iconic (biru), Heritage (coklat), Hidden Gem (ungu), Hotel/Penginapan (oranye).
- Search bar dan category filter.
- User dapat explore bebas atau langsung tambahkan destinasi ke itinerary aktif.

User Story
- US-03: Sebagai traveler, saya ingin eksplorasi destinasi di peta interaktif agar bisa menemukan hidden gem dan UMKM lokal.

### Feature 04 - Destination Card
Kartu informasi destinasi lengkap dengan AI MicroStory untuk konteks budaya.

Behavior
- Info dasar: nama, alamat, jam buka, price tier, foto.
- Verified Accessibility Badge (dari Inclusivity Filter).
- AI MicroStory via Gemini.
- Quick Contact: Call, WhatsApp, Official Link.

User Story
- US-04: Sebagai traveler, saya ingin melihat kartu destinasi yang lengkap dengan info aksesibilitas dan konteks budaya agar bisa memutuskan sebelum menambah ke itinerary.

### Feature 05 - Eco Tracker
Pelacakan pilihan transportasi dengan konversi dampak CO2 ke angka yang relatable.

Behavior
- Manual input transportasi saat check-in di destinasi.
- EcoPoints diberikan berdasarkan pilihan transportasi (lebih ramah lingkungan = lebih banyak poin).

User Story
- US-05: Sebagai traveler yang peduli lingkungan, saya ingin mencatat pilihan transportasi dan melihat dampak karbon agar bisa membuat keputusan yang lebih baik.

### Feature 06 - PathPoints
Gamifikasi perjalanan yang menggabungkan EcoPoints dan CulturePoints menjadi PathPoints yang dapat ditukar.

Behavior
- EcoPoints + CulturePoints = PathPoints.
- GPS check-in dalam radius 100 meter dari destinasi.
- Shareable Trip Summary Card di akhir perjalanan.
- PathPoints dapat ditukar dengan voucher diskon UMKM partner.

User Story
- US-06: Sebagai traveler, saya ingin mendapat poin dari check-in dan pilihan transportasi ramah lingkungan agar termotivasi menjelajah dan mendapat reward.
