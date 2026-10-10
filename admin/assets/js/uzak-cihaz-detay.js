/**
 * Uzak Yönetim - Cihaz Detay: yazılımlar (server-side DataTables, sekme açılınca yüklenir), düzenleme, envanter yenileme
 */
$(function () {
    const adres = '/admin/uzak-cihaz-detay';
    const cihazId = $('#cihazDetay').data('id');

    $.fn.dataTable.ext.errMode = 'none';

    // Yazılım tablosu gizli sekmede başlatılırsa scrollX kolon genişlikleri bozulur; ilk açılışta kurulur
    let tablo = null;
    $('#yazilimSekmeBtn').on('shown.bs.tab', function () {
        if (tablo) {
            tablo.columns.adjust();
            return;
        }
        tablo = $('#yazilimTablo').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            processing: true,
            serverSide: true,
            scrollX: true,
            dom: 'lrtip', // global arama kapalı — filtre panelindeki "Ara" kullanılır
            pageLength: 25,
            order: [[0, 'asc']],
            ajax: {
                url: adres,
                type: 'POST',
                data: function (d) {
                    d.action = 'yazilimlar';
                    d.id = cihazId;
                    d.ara = $('#filtreAra').val();
                    d.mimari = $('#filtreMimari').val();
                }
            },
            columns: [
                { data: 'CihazYazilimlari_Ad', render: d => '<strong>' + uzakKacis(d) + '</strong>' },
                { data: 'CihazYazilimlari_Surum', render: d => d ? uzakKacis(d) : uzakBos },
                { data: 'CihazYazilimlari_Yayinci', render: d => d ? uzakKacis(d) : uzakBos },
                { data: 'KurulumTarihi', render: d => d ? uzakKacis(d) : uzakBos },
                { data: 'CihazYazilimlari_Mimari', render: d => d ? '<span class="badge text-bg-light border">' + uzakKacis(d) + '</span>' : uzakBos }
            ]
        });
    });

    const yenile = () => tablo && tablo.ajax.reload();
    $('#filtreUygula').on('click', yenile);
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') yenile(); });
    $('#filtreMimari').on('change', yenile);
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreMimari').val('').trigger('change.select2');
        yenile();
        showToast('Filtreler temizlendi', 'info');
    });

    // Düzenle
    if ($('#duzenleEtiketler').length) uzakEtiketSelect($('#duzenleEtiketler'), $('#duzenleModal'));

    $('#duzenleKaydet').on('click', function () {
        const form = $('#duzenleForm')[0];
        if (!form.reportValidity()) return;
        const $btn = $(this).prop('disabled', true);
        $.post(adres, {
            action: 'guncelle',
            id: cihazId,
            grup: $('#duzenleGrup').val(),
            aciklama: $('#duzenleAciklama').val(),
            pilot: $('#duzenlePilot').is(':checked') ? '1' : '',
            etiketler: $('#duzenleEtiketler').val() || []
        }, function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (c.basarili) setTimeout(() => window.location.reload(), 800);
        }).always(() => $btn.prop('disabled', false));
    });

    // Envanter yenile
    $('#envanterYenile').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        $.post(adres, { action: 'envanter_yenile', id: cihazId }, function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
        }).always(() => $btn.prop('disabled', false));
    });

    // ------------------------------------------------------------------ Komutlar
    const $kTablo = $('#komutTablo');
    let komutTablo = null;
    let acikKomutVar = false;

    $('#komutSekmeBtn').on('shown.bs.tab', function () {
        if (komutTablo) { komutTablo.columns.adjust(); return; }
        komutTablo = $kTablo.DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            processing: true,
            serverSide: true,
            scrollX: true,
            dom: 'lrtip',
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [{ targets: [1, 2, 3, 4, 5, 6, 7], orderable: false }],
            ajax: {
                url: adres,
                type: 'POST',
                data: function (d) { d.action = 'komutlar'; d.id = cihazId; },
                dataSrc: function (c) {
                    acikKomutVar = (c.data || []).some(r => r.BittiMi == 0);
                    return c.data || [];
                }
            },
            columns: [
                { data: 'KomutHedefleri_id' },
                { data: 'Komutlar_Baslik', render: d => '<strong>' + uzakKacis(d) + '</strong>' },
                { data: 'DurumAd', render: (d, t, r) => '<span class="badge text-bg-' + uzakKacis(r.DurumRenk || 'secondary') + '">' + uzakKacis(d) + '</span>' },
                { data: 'KomutHedefleri_CikisKodu', render: d => d === null ? uzakBos : uzakKacis(d) },
                { data: 'SureSn', render: d => d === null ? uzakBos : uzakKacis(d) + ' sn' },
                { data: 'Gonderen', render: d => d ? uzakKacis(d) : '<span class="text-muted">sistem</span>' },
                { data: 'Olusturma', render: d => uzakKacis(d) },
                {
                    data: null, className: 'text-end text-nowrap',
                    render: (d, t, r) => {
                        let h = '<button type="button" class="btn btn-outline-secondary btn-sm me-1 komut-cikti" data-id="' + r.KomutHedefleri_id + '" title="Çıktı / ayrıntı"><i class="bi bi-terminal"></i></button>';
                        if (r.DurumKod === 'bekliyor' && $kTablo.data('iptal') == 1) {
                            h += '<button type="button" class="btn btn-outline-danger btn-sm komut-iptal" data-id="' + r.KomutHedefleri_id + '" title="İptal"><i class="bi bi-x-circle"></i></button>';
                        }
                        return h;
                    }
                }
            ]
        });
    });

    // Açık (bekleyen / çalışan) komut varken sonuçlar 10 sn'de bir tazelenir
    setInterval(function () {
        if (komutTablo && acikKomutVar && !document.hidden && $('#sekmeKomut').hasClass('active')) {
            komutTablo.ajax.reload(null, false);
        }
    }, 10000);

    // Çıktı modalındaki kopyala butonları
    $('#ciktiModal').on('click', '.cikti-kopya', function () {
        const metin = $($(this).data('hedef')).text();
        if (!metin) { showToast('Kopyalanacak içerik yok', 'info'); return; }
        const $btn = $(this);
        navigator.clipboard.writeText(metin).then(() => {
            $btn.html('<i class="bi bi-check-lg"></i>');
            setTimeout(() => $btn.html('<i class="bi bi-clipboard"></i>'), 1500);
            showToast('Kopyalandı', 'success');
        }).catch(() => showToast('Kopyalanamadı', 'error'));
    });

    $kTablo.on('click', '.komut-cikti', function () {
        const hedefId = $(this).data('id');
        $.post(adres, { action: 'komut_cikti', id: cihazId, hedefId: hedefId }, function (c) {
            if (!c.basarili) { showToast(c.mesaj, 'error'); return; }
            const v = c.veri;
            $('#ciktiModalBaslik span').text(v.Komutlar_Baslik + ' — ' + v.DurumAd + (v.KomutHedefleri_CikisKodu !== null ? ' (çıkış ' + v.KomutHedefleri_CikisKodu + ')' : ''));
            $('#ciktiZaman').text(['Alındı: ' + (v.Alinma || '-'), 'Başladı: ' + (v.Baslama || '-'), 'Bitti: ' + (v.Bitis || '-')].join('  ·  '));
            $('#ciktiMetin').text(v.KomutHedefleri_Cikti || '(çıktı yok)');
            $('#ciktiHata').text(v.KomutHedefleri_Hata || '');
            $('#ciktiHataAlan').toggle(!!v.KomutHedefleri_Hata);
            $('#ciktiScript').text(v.Komutlar_Icerik || '');

            // Ekran görüntüleri (varsa): küçük önizleme, tıklayınca tam boy yeni sekmede
            const $ekran = $('#ciktiEkranlar').empty();
            const ekranlar = c.ekranlar || [];
            ekranlar.forEach(function (dosya) {
                const url = adres + '?action=ekran_goster&id=' + cihazId
                    + '&hedefId=' + encodeURIComponent(hedefId)
                    + '&dosya=' + encodeURIComponent(dosya);
                $('<a>', { href: url, target: '_blank', rel: 'noopener', title: dosya })
                    .append($('<img>', {
                        src: url,
                        class: 'img-thumbnail',
                        css: { maxHeight: '140px', cursor: 'zoom-in' }
                    }))
                    .appendTo($ekran);
            });
            $('#ciktiEkranAlan').toggleClass('d-none', ekranlar.length === 0);

            bootstrap.Modal.getOrCreateInstance($('#ciktiModal')[0]).show();
        });
    });

    $kTablo.on('click', '.komut-iptal', function () {
        const hedefId = $(this).data('id');
        Swal.fire({
            title: 'Komut iptal edilsin mi?', text: 'Ajan henüz almadıysa çalıştırılmaz.',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'İptal et', cancelButtonText: 'Vazgeç', confirmButtonColor: '#dc3545'
        }).then(s => {
            if (!s.isConfirmed) return;
            $.post(adres, { action: 'komut_iptal', id: cihazId, hedefId: hedefId }, c => {
                showToast(c.mesaj, c.basarili ? 'success' : 'error');
                komutTablo.ajax.reload(null, false);
            });
        });
    });

    // Hızlı komut (tek seferlik)
    $('.hk-hazir').on('click', function () {
        $('#hkIcerik').val($(this).data('komut'));
        $('#hkBaslik').val($(this).data('baslik'));
    });
    // Bilgisayar adı: yeni ad yeniden başlatmada geçerli olur, nabız paneldeki adı günceller
    $('#hkAdDegistir').on('click', function () {
        const mevcut = String($(this).data('mevcut') || '');
        Swal.fire({
            title: 'Yeni bilgisayar adı',
            // Bootstrap modalının odak tuzağı dışarıdaki input'a yazmayı engeller; Swal modalın içinde açılır
            target: document.getElementById('hizliKomutModal'),
            input: 'text', inputValue: mevcut,
            inputAttributes: { maxlength: 15, autocapitalize: 'characters' },
            html: '<div class="small text-muted">En fazla 15 karakter; harf, rakam ve tire. Yeniden başlatınca geçerli olur.</div>',
            showCancelButton: true, confirmButtonText: 'Komutu Hazırla', cancelButtonText: 'Vazgeç',
            inputValidator: v => {
                v = (v || '').trim();
                if (!/^[A-Za-z0-9-]{1,15}$/.test(v)) return 'En fazla 15 karakter; yalnız harf, rakam ve tire.';
                if (/^\d+$/.test(v)) return 'Ad yalnız rakamdan oluşamaz.';
                if (/^-|-$/.test(v)) return 'Ad tire ile başlayamaz / bitemez.';
                if (v.toUpperCase() === mevcut.toUpperCase()) return 'Yeni ad mevcut adla aynı.';
            }
        }).then(s => {
            if (!s.isConfirmed) return;
            const ad = s.value.trim().toUpperCase();
            $('#hkIcerik').val("Rename-Computer -NewName '" + ad + "' -Force -ErrorAction Stop\n"
                + "'Bilgisayar adi " + ad + " olarak degistirildi; yeniden baslatinca gecerli olur.'");
            $('#hkBaslik').val('Bilgisayar adı: ' + ad);
        });
    });
    $('#hkGonder').on('click', function () {
        const icerik = $('#hkIcerik').val().trim();
        if (!icerik) { showToast('Komut boş olamaz', 'warning'); return; }
        Swal.fire({
            title: 'Komut gönderilsin mi?',
            html: '<pre class="text-start small mb-0" style="white-space:pre-wrap">' + uzakKacis(icerik) + '</pre><div class="mt-2">SYSTEM yetkisiyle çalışır.</div>',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Gönder', cancelButtonText: 'Vazgeç', confirmButtonColor: '#ffc107'
        }).then(s => {
            if (!s.isConfirmed) return;
            const $btn = $('#hkGonder').prop('disabled', true);
            $.post(adres, { action: 'hizli_komut', id: cihazId, icerik: icerik, baslik: $('#hkBaslik').val(), zamanAsimi: $('#hkSure').val() }, function (c) {
                showToast(c.mesaj, c.basarili ? (c.uyari ? 'warning' : 'success') : 'error');
                if (c.basarili) {
                    bootstrap.Modal.getInstance($('#hizliKomutModal')[0]).hide();
                    if (komutTablo) komutTablo.ajax.reload(); else bootstrap.Tab.getOrCreateInstance($('#komutSekmeBtn')[0]).show();
                }
            }).always(() => $btn.prop('disabled', false));
        });
    });

    // Script gönder (bu cihaz): metin / sayi parametreleri için alan; diğerleri teslim anında sunucuda çözülür
    $('#sgScript').on('change', function () {
        const $alan = $('#sgParametreler').empty();
        let p = [];
        try { p = JSON.parse($(this).find(':selected').attr('data-parametreler') || '[]') || []; } catch (e) {}
        p.forEach(x => {
            const etiket = uzakKacis(x.etiket || x.ad);
            if (x.tip === 'metin' || x.tip === 'sayi') {
                $alan.append('<div class="mb-3"><label class="form-label">' + etiket + '</label><input class="form-control" name="degerler[' + uzakKacis(x.ad)
                    + ']" type="' + (x.tip === 'sayi' ? 'number' : 'text') + '" value="' + uzakKacis(x.varsayilan ?? '') + '"></div>');
            } else {
                $alan.append('<div class="small text-muted mb-2"><i class="bi bi-magic me-1"></i><strong>' + etiket + '</strong>: teslim anında otomatik</div>');
            }
        });
    });
    $('#scriptGonderModal').on('show.bs.modal', function () {
        $('#sgScript').val('').trigger('change');
    });
    $('#sgGonder').on('click', function () {
        if (!$('#sgScript').val()) { showToast('Script seçin', 'warning'); return; }
        const $btn = $(this).prop('disabled', true);
        $.post(adres, $('#scriptGonderForm').serialize() + '&action=script_gonder&id=' + encodeURIComponent(cihazId), function (c) {
            showToast(c.mesaj, c.basarili ? (c.uyari ? 'warning' : 'success') : 'error');
            if (c.basarili) {
                bootstrap.Modal.getInstance($('#scriptGonderModal')[0]).hide();
                if (komutTablo) komutTablo.ajax.reload(); else bootstrap.Tab.getOrCreateInstance($('#komutSekmeBtn')[0]).show();
            }
        }).always(() => $btn.prop('disabled', false));
    });

    // Sekme adresi korunur (yenilemede aynı sekme açılır)
    const hash = window.location.hash;
    if (hash) {
        const $sekme = $('[data-bs-target="' + hash + '"]');
        if ($sekme.length) bootstrap.Tab.getOrCreateInstance($sekme[0]).show();
    }
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        history.replaceState(null, '', $(this).data('bs-target'));
    });
});
