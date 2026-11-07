# Maia Etsy Connector — Kurulum ve Kullanım

WooCommerce ürünlerinizi Etsy Open API v3 ile Etsy mağazanıza **taslak** olarak aktaran WordPress eklentisi.

## Gereksinimler

- WordPress 6.2+, PHP 8.2+
- WooCommerce 8.0+ (Action Scheduler WooCommerce ile birlikte gelir)
- Sitenizin **HTTPS** olması (Etsy OAuth redirect için zorunlu)
- Etsy geliştirici hesabı ve bir App (Keystring + Shared Secret)

## 1. Etsy App oluşturma

1. https://www.etsy.com/developers/your-apps adresinden bir App oluşturun.
2. **Keystring** ve **Shared Secret** değerlerini not alın.
3. Callback URL alanına eklenti Ayarlar sayfasındaki **Redirect URI**'yi birebir ekleyin:
   `https://SITENIZ/wp-admin/admin-post.php?action=mec_oauth_callback`

## 2. Eklentiyi kurma

WordPress → Eklentiler → Yeni Ekle → Eklenti Yükle → `maia-etsy-connector.zip` → Yükle → Etkinleştir.

Etkinleştirince sol menüde **Maia Etsy** görünür.

## 3. Bağlanma

1. **Maia Etsy → Ayarlar**: Keystring ve Shared Secret girin, kaydedin.
2. **Etsy'ye Bağlan** butonuna basın, Etsy'de izin verin.
3. Dönüşte Dashboard'da mağaza adınız ve "Bağlı" rozeti görünür. Shop ID otomatik alınır.

Token süresi dolduğunda Refresh Token ile otomatik yenilenir. Bağlantıyı istediğiniz an **Bağlantıyı Kaldır** ile silebilirsiniz.

## 4. Varsayılan ayarlar

Ayarlar sayfasında (Etsy'den API ile çekilir):

- **Kargo profili** (zorunlu), **Readiness/hazırlık profili** (zorunlu, fiziksel ürün için), **İade politikası** (opsiyonel)
- **who_made / when_made**, **varsayılan stok**, **varsayılan taxonomy_id**
- **Fiyat kuralı**: WooCommerce fiyatı / Etsy özel fiyatı / yüzde artış / sabit ekleme + **kur çarpanı** (otomatik döviz çekilmez)

Readiness veya kargo profiliniz yoksa önce Etsy panelinizden oluşturun; liste API ile buraya gelir.

## 5. Kategori eşleştirme

**Maia Etsy → Kategori Eşleştirme**: her WooCommerce kategorisi için Etsy kategorisi (taxonomy_id) seçin ve kaydedin. Ürün bazında `_mec_etsy_taxonomy_id` bu eşleştirmenin önüne geçer.

## 6. Etsy'ye özel bilgiler (ürün bazında)

Ürün düzenleme ekranında **Etsy Bilgileri** sekmesi: İngilizce başlık/açıklama, etiket, fiyat, kategori, materyal. Boş bırakılan alanlarda WooCommerce bilgisi kullanılır.

> İngilizce açıklama boşsa ürün **otomatik çevrilmez**; "Kontrol Gerekli" olarak işaretlenir ve aktarılmaz. WooCommerce (Türkçe) açıklamalarınız hiçbir zaman değiştirilmez.

## 7. Aktarım

**Maia Etsy → Ürün Aktarımı**: ürünleri filtreleyip/arayıp seçin, **Etsy'ye Aktar (Taslak)**. İşlem arka planda Action Scheduler kuyruğunda çalışır; sayfayı kapatsanız bile devam eder. İlerleme çubuğu ve durumlar canlı güncellenir.

- Başarılı ürünler tekrar aktarılmaz; mevcut Listing güncellenir (mükerrer kayıt oluşmaz).
- 429 (limit) yanıtında `Retry-After`, geçici 5xx hatada exponential backoff uygulanır. Kalıcı doğrulama hataları tekrar denenmez.

## 8. Güncelleme

**Maia Etsy → Etsy Ürünleri**: aktarılan ürünler için **Fiyat / Stok / Açıklama / Fotoğraf / Tümü** güncelleme.

## 9. Geçmiş ve hata

**Maia Etsy → Aktarım Geçmişi**: tarih, ürün/listing ID, işlem, durum, HTTP kodu, deneme sayısı, mesaj. API anahtarları ve token asla loglanmaz.

## Testler

`tests/` klasöründeki birim testleri gerçek Etsy kimlik bilgisi gerektirmez (fiyat/etiket/materyal dönüşümü, PKCE challenge, çoklu-form kodlama). Canlı aktarım testleri gerçek bir Etsy App + HTTPS site gerektirir ve ayrıca yapılmalıdır; bu depo canlı entegrasyonu "başarılı" olarak raporlamaz.

## Veri temizliği

Eklenti silindiğinde (`uninstall.php`) tüm ayarlar, token, log tablosu ve eklentinin eklediği ürün meta alanları kaldırılır. WooCommerce ürün verileriniz (açıklama/fiyat/stok) etkilenmez.
