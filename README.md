# Maia Etsy Connector

WooCommerce ürünlerinizi **Etsy Open API v3** üzerinden Etsy mağazanıza **taslak (draft) listing** olarak aktaran bir WordPress eklentisi. Toplu aktarım, OAuth 2.0 + PKCE kimlik doğrulama, kategori/kargo eşleştirme, fiyat ve para birimi ayarları, görsel senkronizasyonu ve hata kaydı içerir.

> Eklenti ürünleri **yayınlamaz**, yalnızca **taslak** oluşturur. Son kontrolü yapıp yayınlama kararı sizde kalır.

---

## İçindekiler

- [Özellikler](#özellikler)
- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
- [Etsy Uygulaması Oluşturma](#etsy-uygulaması-oluşturma)
- [OAuth Bağlantısı](#oauth-bağlantısı)
- [Ayarlar](#ayarlar)
- [Kategori Eşleştirme (taxonomy_id)](#kategori-eşleştirme-taxonomy_id)
- [Ürün Aktarımı](#ürün-aktarımı)
- [Fiyat ve Para Birimi](#fiyat-ve-para-birimi)
- [Sorun Giderme](#sorun-giderme)
- [Proje Yapısı](#proje-yapısı)
- [Güvenlik](#güvenlik)
- [Lisans](#lisans)

---

## Özellikler

- **OAuth 2.0 + PKCE** ile güvenli Etsy bağlantısı ve otomatik token yenileme.
- **Toplu aktarım**: ürünleri Action Scheduler tabanlı kuyrukla, canlı ilerleme takibiyle Etsy'ye taşıma.
- **Taslak listing oluşturma** (`createDraftListing`) ve mevcut listing'leri güncelleme (başlık, açıklama, etiket, materyal, kategori, fiyat, stok).
- **Kategori eşleştirme**: WooCommerce kategorisi → Etsy `taxonomy_id`. Bağlıyken Etsy kategori ağacı dropdown olarak gelir.
- **Fiyat modları**: WooCommerce fiyatını aynen kullan, yüzde ekle, sabit ekle veya ürün bazında özel Etsy fiyatı; opsiyonel kur çevrimi.
- **Açıklama**: ürün bazında İngilizce açıklama girilebilir; boşsa WooCommerce açıklaması kullanılır (Etsy alıcının diline otomatik çevirir). HTML düz metne çevrilir.
- **Görsel senkronizasyonu**: ürün görsellerini sıra koruyarak ve tekrarları atlayarak Etsy listing'e yükler.
- **Hata/sync kaydı**: her işlemin sonucu ve Etsy hata mesajları loglanır; yeniden deneme (retry) mantığı.
- Ürün editöründe **"Etsy Bilgileri"** sekmesi (başlık, açıklama, kategori, etiket, materyal, fiyat override).

## Gereksinimler

- WordPress 6.2+
- WooCommerce 8.0+
- PHP 8.2+
- HTTPS yayında olan bir alan adı (Etsy callback için zorunlu)
- Onaylı bir Etsy geliştirici uygulaması (API Keystring + Shared Secret)

## Kurulum

1. `maia-etsy-connector.zip` dosyasını indirin.
2. WordPress → **Eklentiler → Yeni Ekle → Eklenti Yükle** → ZIP'i seçin → **Şimdi Kur**.
3. **Etkinleştir**.
4. Sol menüde **Maia Etsy** sayfaları görünür.

> Güncelleme: yeni ZIP'i yüklemeden önce eski sürümü silip yeniden kurun ya da üzerine yükleyin. Ayarlar ve token'lar veritabanında kalır.

## Etsy Uygulaması Oluşturma

1. <https://www.etsy.com/developers/your-apps> → **Create a New App**.
2. İzin kapsamları (scope): `listings_r`, `listings_w`, `shops_r`.
3. **Callback URL(s)** alanına, eklentinin kullandığı adresi **birebir** ekleyin:

   ```
   https://ALANADINIZ.com/wp-admin/admin-post.php?action=mec_oauth_callback
   ```

   - `http://` değil, `https://` olmalı.
   - Sonda `/` veya boşluk olmamalı; query dahil tam eşleşmeli.
4. Onaydan sonra **API Keystring** ve **Shared Secret** değerlerini not alın.

## OAuth Bağlantısı

1. Eklenti → **Ayarlar** sayfasında **API Keystring** ve **Shared Secret** girin, kaydedin.
2. **Etsy'ye Bağlan** butonuna tıklayın.
3. Etsy izin ekranında onaylayın; otomatik geri dönüşte **Durum** sayfasında `Bağlı · MağazaAdı (Shop ID: …)` görünür.

Bağlantı tamamlanınca kargo profilleri, hazırlık (readiness) durumları, iade politikaları ve kategori ağacı Etsy'den çekilip dropdown olarak dolar.

## Ayarlar

| Ayar | Açıklama |
|------|----------|
| API Keystring / Shared Secret | Etsy uygulaması kimlik bilgileri. |
| Varsayılan Kargo Profili | **Zorunlu.** Etsy'de tanımlı kargo profili. |
| Varsayılan Hazırlık (readiness) | Opsiyonel. Yalnızca seçiliyse gönderilir. |
| Varsayılan İade Politikası | Opsiyonel; bölgenize göre Etsy zorunlu tutabilir. |
| Fiyat Modu | `woo` / `etsy_custom` / `percent` / `fixed`. |
| Para Birimi / Kur | Etsy mağaza para biriminize göre çevrim. |
| who_made / when_made / Varsayılan Adet | Etsy listing zorunlu alanları. |

## Kategori Eşleştirme (taxonomy_id)

Etsy her listing için bir **`taxonomy_id`** (ürün kategorisi) ister. Bu, mağaza bölümünüzden (shop section) **farklıdır**.

- **Kategori Eşleştirme** sayfasında WooCommerce kategorinizi Etsy kategorisine dropdown'dan eşleyin (bağlantı aktifken dolar).
- İsterseniz ürün bazında **"Etsy Bilgileri"** sekmesinden veya Ayarlar'daki **Varsayılan Etsy Kategorisi** alanına elle `taxonomy_id` girin.
- Öncelik sırası: ürün override → WooCommerce kategori eşlemesi → genel varsayılan.

Örnek: Yosun/duvar sanatı için yol genelde **Home & Living > Home Décor > Wall Décor** (üst düğüm `taxonomy_id = 1027`).

## Ürün Aktarımı

1. Ürün düzenleme ekranında **"Etsy Bilgileri"** sekmesini doldurun (en azından kategori eşlenmiş olmalı).
2. Toplu aktarım sayfasından ürünleri seçip **Etsy'ye Aktar** deyin; ilerleme canlı güncellenir.
3. Aktarım **taslak** oluşturur. Daha önce aktarılan ürün tekrar gönderilirse yeni taslak değil, mevcut listing **güncellenir** (`_mec_listing_id` ile tekilleştirme).

## Fiyat ve Para Birimi

- Etsy'de fiyatın para birimi **Etsy mağaza ayarındaki para birimidir**, listing başına seçilmez.
- WooCommerce fiyatlarınız TRY ise, Etsy mağaza para biriminiz de **TRY** olmalı; aksi halde sayı yanlış para biriminde yorumlanır (ör. `100 TL` → `100 USD`).
- Para birimleri farklıysa Ayarlar'daki **kur (exchange_rate)** ile çevirin.
- Alıcıya gösterilen para birimini Etsy otomatik dönüştürür; taban para birimi mağazanınkidir.

## Sorun Giderme

| Belirti | Neden / Çözüm |
|---------|---------------|
| `The requested redirect URL is not permitted` | Callback URL Etsy uygulamasına birebir kayıtlı değil. Tam adresi (query dahil) ekleyin. |
| `HTTP 403: Shared secret is required in x-api-key header` | `x-api-key` eksik/yanlış. Keystring + Shared Secret dolu olmalı; eklenti `keystring:shared_secret` gönderir. |
| `taxonomy_id eşleştirilmemiş` | Ürüne/kategoriye Etsy kategorisi atayın. |
| `İngilizce açıklama girilmemiş` | Artık bloklamaz; boşsa WooCommerce açıklaması kullanılır. |
| `HTTP 400: IDs must be >= 1, got 0` | Kargo profili/readiness/iade `0` ("seçiniz") kalmış. Gerçek bir değer seçin; readiness ve iade yalnızca `>0` ise gönderilir. |
| Açıklamada `<p>`, `<strong>` görünüyor | HTML artık düz metne çevriliyor; ürünü tekrar aktarın, taslak güncellenir. |
| Dropdown'lar boş | Etsy bağlantısı yok. Önce OAuth bağlantısını tamamlayın. |

## Proje Yapısı

```
maia-etsy-connector/
├── maia-etsy-connector.php          # Bootstrap, eklenti başlığı
├── includes/
│   ├── class-mec-logger.php         # Sync/hata kaydı
│   ├── class-mec-settings.php       # Ayar ve token saklama
│   ├── class-mec-auth.php           # OAuth 2.0 + PKCE
│   ├── class-mec-api.php            # Etsy Open API v3 HTTP istemcisi
│   ├── class-mec-category-mapper.php# WooCommerce → Etsy taxonomy
│   ├── class-mec-product-meta.php   # "Etsy Bilgileri" ürün sekmesi
│   ├── class-mec-product-sync.php   # Ürün eşleme ve taslak oluşturma
│   ├── class-mec-image-sync.php     # Görsel yükleme
│   ├── class-mec-queue.php          # Action Scheduler kuyruğu + retry
│   └── class-mec-plugin.php         # Menü, asset, OAuth akışı
├── admin/
│   ├── class-mec-admin.php          # Admin sayfaları
│   └── class-mec-ajax.php           # AJAX ve form işleyicileri
├── assets/                          # admin.js, admin.css
├── tests/selfcheck.php              # Bağımsız mantık testleri
├── uninstall.php                    # Silmede veri temizliği
├── readme.txt                       # WordPress eklenti bilgisi
└── KURULUM.md                       # Ayrıntılı kurulum rehberi
```

## Güvenlik

- Token ve Shared Secret `autoload = no` ile saklanır; HTML/JS'e veya loglara yazılmaz.
- OAuth akışı PKCE (`code_challenge` / `code_verifier`) kullanır; shared secret yalnızca `x-api-key` başlığında kullanılır.
- Eklenti **satış verilerine erişmez**, listing'leri **otomatik yayınlamaz**.

## Lisans

Kişisel/özel kullanım. Yazar: **Cenk Fırtına**.
