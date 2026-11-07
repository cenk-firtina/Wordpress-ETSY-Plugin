=== Maia Etsy Connector ===
Contributors: maiaconcept
Tags: etsy, woocommerce, export, listings, oauth
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.2
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce ürünlerini Etsy Open API v3 ile Etsy mağazanıza taslak olarak toplu aktarın. OAuth 2.0 + PKCE, Action Scheduler kuyruğu, kategori/kargo eşleştirme, hata kaydı.

== Description ==

Maia Etsy Connector, WooCommerce mağazanızdaki ürünleri Etsy'ye tek tek girmeden toplu olarak aktarmanızı sağlar. Üçüncü taraf ücretli servis gerektirmez; doğrudan Etsy Open API v3 kullanır.

Özellikler:

* OAuth 2.0 Authorization Code + PKCE ile güvenli bağlantı, otomatik token yenileme.
* Ürünler Etsy'de yalnızca TASLAK olarak oluşturulur; onayınız olmadan yayımlanmaz.
* Aynı ürün ikinci kez aktarıldığında mükerrer kayıt oluşmaz; mevcut Listing güncellenir.
* Action Scheduler ile arka plan toplu aktarım; 429/5xx için Retry-After ve exponential backoff.
* WooCommerce ürün ekranında "Etsy Bilgileri" sekmesi (İngilizce başlık/açıklama, etiket, fiyat, kategori, materyal).
* Kategori eşleştirme (Etsy seller taxonomy), kargo/readiness/iade profili seçimi, esnek fiyat kuralları.
* Görsel (ana + galeri) aktarımı; sıralama korunur, yüklenenler tekrar yüklenmez.
* Aktarım geçmişi ve hata kaydı (API anahtarları/token loglanmaz).

== Installation ==

1. WordPress > Eklentiler > Yeni Ekle > Eklenti Yükle.
2. maia-etsy-connector.zip dosyasını seçip yükleyin ve etkinleştirin.
3. Maia Etsy > Ayarlar'dan Etsy API Keystring ve Shared Secret girin.
4. Oradaki Redirect URI'yi Etsy uygulamanızın Callback URL alanına birebir ekleyin.
5. "Etsy'ye Bağlan" ile OAuth bağlantısını tamamlayın.
6. Kargo/readiness/iade ve fiyat ayarlarını yapın, kategorileri eşleştirin.
7. Ürün Aktarımı sayfasından ürünleri seçip "Etsy'ye Aktar (Taslak)" ile gönderin.

Gereksinim: WooCommerce etkin olmalı (Action Scheduler WooCommerce ile gelir). Site HTTPS olmalıdır (Etsy redirect zorunluluğu).

== Changelog ==

= 1.0.0 =
* İlk sürüm.
