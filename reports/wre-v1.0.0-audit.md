# Wisdom Rain Email Engine v1.0.0 — Pre-release Performance & Health Audit

## Durum Özeti
| Alan | Durum | Notlar |
| --- | --- | --- |
| Bootstrap & Hooks | Pass | WRE_Core WordPress ömrüne bağlanarak tüm modülleri yükleyip `init()` çağrılarını gerçekleştiriyor; kurulumda şablon dizinleri ve cron kurulumu hazırlanıyor.【F:core/class-wre-core.php†L29-L185】 |
| Admin Menü & Sayfalar | Warning | Menü ve sekme yapısı hazır ancak gerçek yüklenme süreleri ölçülemedi; profil kancası yok.【F:admin/class-wre-admin.php†L22-L172】 |
| Cron & Schedule | Fail | Planlayıcı 01:00/13:00 hizalı `wre_cron_run_tasks` olayını kuruyor fakat gereksinimdeki `wre_cron_run` olayı periyodik olarak planlanmıyor; yalnızca kuyruk işleci tek seferlik olaylar oluşturuyor, bu da iki isimli kanca arasında sapmaya yol açıyor.【F:core/class-wre-cron.php†L67-L134】【F:core/class-wre-email-queue.php†L30-L182】【F:core/class-wre-email-queue.php†L390-L422】 |
| Cron Çalışma Gözlemleri | Warning | Son çalıştırma zamanı/süre ve manuel dry-run sonuçları canlı ortam olmadan doğrulanamadı; kod yürütmesi sırasında WRE_Email_Queue & WRPA_Access’e bağımlı.【F:core/class-wre-cron.php†L219-L284】 |
| Queue (Rate Limit) | Warning | Kuyruk `_wre_email_queue` seçeneğinde saklanıyor ve saatlik 100 e-posta limiti uygulanıyor ancak canlı veri (uzunluk, 24 saat istatistikleri) toplanamadı.【F:core/class-wre-email-queue.php†L20-L183】 |
| Email Sending | Fail | Gönderimler `wp_mail()` üzerine kurulu; TLS/SMTP uç noktası için bir yapılandırma veya doğrulama bulunmuyor ve Return-Path başlığı ayarlanmadığından posta sunucusuyla tam test yapılamadı.【F:core/class-wre-email-sender.php†L268-L303】 |
| Templates | Pass | Varsayılan şablonlar eklenti içinde mevcut; `ensure_storage_directory()` uploads altında override klasörü oluşturuyor ve yönetişim arayüzü sağlanıyor.【F:core/class-wre-templates.php†L96-L243】【F:templates/emails/email-welcome-verify.html†L1-L200】 |
| Verify Flow | Pass | `/verify` talepleri için token doğrulaması, geçerli token için yönlendirme ve hatalı taleplerde 403 yanıtı uygulanmış durumda.【F:core/class-wre-verify.php†L30-L181】 |
| Logs | Warning | `_wre_log_entries` maksimum 500 kayıt tutacak şekilde yönetiliyor ancak son 20 kaydın içeriği test ortamı olmadan alınamadı.【F:core/class-wre-logger.php†L17-L135】 |
| WRPA Entegrasyonu | Warning | WRE_Admin_Notices başlangıçta aktive ediliyor ancak cron akışı `WRPA_Access` metodlarına dayanıyor; sınıf bulunmazsa sessizce atlıyor. WRPA_Access’in init zinciri bu depoda görünmediğinden çalışma zamanı riski sürüyor.【F:core/class-wre-core.php†L142-L184】【F:core/class-wre-cron.php†L219-L284】 |

## Ayrıntılı Bulgular
### Bootstrap & Hooks
- `WRE_Core::boot()` `plugins_loaded` sırasında `init()` tetikleyerek çekirdek modülleri yüklüyor; aktivasyon kancası şablon dizinlerini ve cron zamanlamasını hazırlıyor.【F:core/class-wre-core.php†L29-L105】
- `initialize_modules()` içinde `WRE_Cron::init()`, `WRE_Templates::init()`, `WRE_Verify::init()` ve WRE_Admin_Notices dahil tüm mevcut modüller koşullu olarak çağrılıyor; tanımlı olmayan sınıflar atlanıyor, bu yüzden eksik modül hatasına rastlanmadı.【F:core/class-wre-core.php†L142-L184】

### Admin Menü & Sayfalar
- Yönetim menüsü `WRE_Admin::register_menu()` aracılığıyla eklentiye özel sekmeli arayüz sağlıyor; ayrı şablon ve kampanya alt sayfaları var.【F:admin/class-wre-admin.php†L22-L163】
- Ancak yükleme süreleri ölçülmedi; gerçek performans için WordPress Profiling (Query Monitor vb.) önerilir.

### Cron & Schedule
- `WRE_Cron::init()` iki kanca ekliyor: `init` (takvim garantisi) ve `wre_cron_run_tasks` (işleyici). Saat hizalaması `get_next_runtime()` ile 01:00/13:00 olarak hesaplanıyor.【F:core/class-wre-cron.php†L67-L134】【F:core/class-wre-cron.php†L293-L318】
- Kuyruk işleyicisi farklı bir kanca (`wre_cron_run`) bekliyor; periyodik olaylar yerine her gönderim sonrası tek seferlik zamanlanıyor. Gereksinimde istenen `wre_cron_run` için yinelenen bir cron tanımı bulunmuyor, bu da raporlama ve tetik hizalamasında boşluk yaratıyor.【F:core/class-wre-email-queue.php†L30-L182】【F:core/class-wre-email-queue.php†L390-L422】
- WRPA tabanlı kullanıcı sorguları yoksa plan/comeback kuyruğu hiç oluşmayabilir; bunun için koruyucu kontroller mevcut ancak iş akışının devreye girmesi WRPA_Access sınıfına bağlı.【F:core/class-wre-cron.php†L219-L284】

### Queue & Rate Limit
- Kuyruk verileri `_wre_email_queue` seçeneğinde saklanıyor; her işleme 100/adet limit ve 36 saniyelik gecikme uygulanıyor, başarısızlıklar yeniden deneniyor.【F:core/class-wre-email-queue.php†L20-L183】
- Saatlik pencere `_wre_email_queue_rate` seçeneğinde takip ediliyor ve sınır aşılırsa bir sonraki pencere için planlama yapılıyor.【F:core/class-wre-email-queue.php†L360-L422】
- Canlı kuyruk uzunluğu ve 24 saatlik metrikler WordPress CLI veya özel yönetim ekranı ile okunabilir; kodda hazır bir raporlayıcı yok.

### Email Sending
- Tüm gönderimler `wp_mail()` çağrılarıyla yürütülüyor ve yalnızca `Content-Type` + `From` başlıkları ayarlanıyor; SMTP/TLS bağlantısı veya PHPMailer yapılandırması bu depoda bulunmuyor.【F:core/class-wre-email-sender.php†L268-L303】
- Return-Path başlığı set edilmediğinden bazı SMTP sunucuları mesajı reddedebilir; test gönderimi veya `phpmailer_init` kancasıyla ayarlama önerilir.

### Templates
- Varsayılan şablonlar `templates/emails/` dizininde mevcut; aktivasyon sırasında dizinler oluşturuluyor ve admin arayüzü overrides için uploads/wre-templates yolunu kullanıyor.【F:core/class-wre-core.php†L69-L93】【F:core/class-wre-templates.php†L96-L243】
- `render_template()` boş yer tutucuları temizleyerek güvenli çıktı sağlıyor; override yazma işlemi `wp_kses_post` sonrası dosyaya kaydediliyor.【F:core/class-wre-templates.php†L298-L359】

### Verify Flow
- `/verify?user=ID&token=HASH` isteği `WRE_Verify::handle_verify_link()` içinde doğrulanıyor; geçerli token doğrulamaları oturum açmayı tetikleyebilir, hatalı durumlarda `wp_die()` 403 üretir.【F:core/class-wre-verify.php†L30-L166】
- Token tazeliği filtrelenebilir TTL (varsayılan 2 gün) ile korunuyor; yönlendirme `wre_verify_redirect` filtresiyle özelleştirilebilir.【F:core/class-wre-verify.php†L138-L181】

### Logs
- `_wre_log_entries` seçeneği 500 kayıtla sınırlı; `WRE_Logs` yönetim sayfası istatistik ve temizleme arayüzü sunuyor.【F:core/class-wre-logger.php†L17-L135】【F:admin/class-wre-logs.php†L18-L76】
- Son 20 kaydı almak için WordPress ortamında `WRE_Logger::get()` kullanılmalı; bu çalışma alanında veri bulunmuyor.

### WRPA Entegrasyonu
- `WRE_Admin_Notices::init()` çekirdek tarafından çağrılıyor ve CLI bildirimi yönetiyor.【F:core/class-wre-core.php†L142-L184】【F:admin/class-wre-admin-notices.php†L18-L35】
- Cron süreçleri `WRPA_Access` sınıfına büyük oranda bağlı; sınıf tanımlı değilse plan/comeback e-postaları kuyruğa alınmıyor. Üretimde WRPA eklentisinin yüklü ve `get_users_expiring_in_days` / `get_users_expired_for_days` metodlarını sunuyor olması şart.【F:core/class-wre-cron.php†L219-L284】

## Ham Çıktılar
```
$ find templates -maxdepth 2 -type f -print
templates/emails/email-subscription-confirm.html
...
templates/emails/email-exclusive.html
```

```
$ nl -ba core/class-wre-email-queue.php | sed -n '1,120p'
... (CRON_HOOK = 'wre_cron_run', MAX_PER_HOUR = 100, process_queue ...)
```

## Önerilen Düzeltmeler
- `wre_cron_run` için `wp_schedule_event()` tanımlayıp iki cron kancasını hizalayın veya e-posta kuyruğu `wre_cron_run_tasks` içinde tetiklenecek şekilde kanca adlarını birleştirin.
- SMTP/TLS doğrulaması için `phpmailer_init` kancasına Hostinger kimlik bilgilerini ekleyin ve `Return-Path` başlığını tanımlayın; test gönderimleri için yönetici arayüzüne buton eklenebilir.
- Yönetici sayfaları için Query Monitor veya benzeri araçlarla performans ölçümü yapıp 300–800 ms hedefini doğrulayın; ağır sorgular tespit edilirse sekmelerde lazy load uygulanabilir.
- WordPress CLI veya özel yönetim paneli ile kuyruk uzunluğu, son 24 saat gönderim istatistikleri ve log özetlerini gösteren bir raporlayıcı ekleyin.
- WRPA_Access sınıfının eklenti aktivasyonunda hazır olduğundan emin olmak için yoklama/uyarı mekanizması ekleyin; sınıf eksikse yönetici uyarısı gösterin.
