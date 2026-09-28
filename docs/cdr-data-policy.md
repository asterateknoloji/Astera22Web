# CDR veri politikası

- CDR kayıtları `calldate` alanına göre aylık PostgreSQL partition'larında tutulur.
- Her bakım çalışması en az 18 ay ileriye partition hazırlar. Varsayılan partition içinde satır bulunması alarm/hata kabul edilir.
- Çevrimiçi saklama süresi 84 aydır. `automatic_drop_enabled=false` olduğu için partition'lar otomatik silinmez.
- Bir partition silinmeden önce şifreli arşiv alınması, arşiv checksum'ının doğrulanması ve ayrı depoda saklanması zorunludur.
- Günlük PostgreSQL yedeği, haftalık tam yedek ve en az üç ayda bir geri yükleme testi uygulanmalıdır.
- Ses dosyaları PostgreSQL dışında tutulur. Yol/nesne anahtarı, firma, çağrı kimlikleri, format, boyut, süre ve checksum metadata'sı `call_recordings` tablosundadır.
- Büyük CDR sorguları firma ve tarih aralığıyla çalışmalıdır; derin `OFFSET` kullanılmaz.
