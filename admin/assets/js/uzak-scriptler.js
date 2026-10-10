/**
 * Uzak Yönetim - Scriptler: liste, ekle/düzenle, sil, cihaz/gruba gönder
 */
$(function () {
    const adres = '/admin/uzak-scriptler';
    const $tablo = $('#scriptTablo');
    const yetki = { duzenle: $tablo.data('duzenle') == 1, sil: $tablo.data('sil') == 1, gonder: $tablo.data('gonder') == 1 };

    $.fn.dataTable.ext.errMode = 'none';

    const parametreler = (json) => { try { return JSON.parse(json || '[]') || []; } catch (e) { return []; } };

    const tablo = $tablo.DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        ordering: false, // sunucu sırası: önce ilk kurulum sırası, sonra ad
        ajax: {
            url: adres,
            type: 'POST',
            data: function (d) {
                d.action = 'liste';
                d.ara = $('#filtreAra').val();
                d.ilkKurulum = $('#filtreIlk').val();
            }
        },
        columns: [
            { data: 'Scriptler_IlkKurulumSira', className: 'text-center', render: d => d ? '<span class="badge text-bg-success">' + uzakKacis(d) + '</span>' : uzakBos },
            {
                data: 'Scriptler_Ad',
                render: (d, t, r) => '<strong>' + uzakKacis(d) + '</strong>'
                    + (r.Scriptler_Aciklama ? '<div class="small text-muted text-wrap" style="max-width:520px">' + uzakKacis(r.Scriptler_Aciklama) + '</div>' : '')
            },
            {
                data: 'Scriptler_Parametreler',
                render: d => {
                    const p = parametreler(d);
                    return p.length ? p.map(x => '<span class="badge text-bg-light border me-1" title="' + uzakKacis(x.tip) + '">' + uzakKacis(x.ad) + '</span>').join('') : uzakBos;
                }
            },
            { data: 'Scriptler_ZamanAsimiSn', render: d => uzakKacis(d) + ' sn' },
            { data: 'Guncelleme', render: (d, t, r) => uzakKacis(d) + (r.Guncelleyen ? '<div class="small text-muted">' + uzakKacis(r.Guncelleyen) + '</div>' : '') },
            {
                data: null, className: 'text-end text-nowrap',
                render: (d, t, r) => {
                    let h = '<button type="button" class="btn btn-outline-secondary btn-sm me-1 script-sonuc" data-id="' + r.Scriptler_id + '" title="Sonuçlar / çıktılar"><i class="bi bi-clock-history"></i></button>';
                    if (yetki.gonder) h += '<button type="button" class="btn btn-outline-warning btn-sm me-1 script-gonder" data-id="' + r.Scriptler_id + '" title="Cihaz / gruba gönder"><i class="bi bi-send"></i></button>';
                    if (yetki.duzenle) h += '<button type="button" class="btn btn-outline-primary btn-sm me-1 script-duzenle" data-id="' + r.Scriptler_id + '" title="Düzenle"><i class="bi bi-pencil"></i></button>';
                    if (yetki.sil) h += '<button type="button" class="btn btn-outline-danger btn-sm script-sil" data-id="' + r.Scriptler_id + '" title="Sil"><i class="bi bi-trash"></i></button>';
                    return h;
                }
            }
        ]
    });

    function loadStats() {
        $.post(adres, { action: 'istatistik' }, function (c) {
            if (!c.basarili) return;
            $('#istToplam').text(c.veri.toplam);
            $('#istIlkKurulum').text(c.veri.ilkKurulum);
            $('#istSon24').text(c.veri.son24);
            $('#istAcik').text(c.veri.acik);
        });
    }
    tablo.one('draw', loadStats);
    const yenile = () => { tablo.ajax.reload(null, false); loadStats(); };

    // Filtre
    $('#filtreUygula').on('click', () => tablo.ajax.reload());
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') tablo.ajax.reload(); });
    $('#filtreIlk').on('change', () => tablo.ajax.reload());
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreIlk').val('').trigger('change.select2');
        tablo.ajax.reload();
        showToast('Filtreler temizlendi', 'info');
    });

    // Kod alanında Tab girinti ekler (odak dışarı kaçmasın)
    $('.kod-alani').on('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) return;
        e.preventDefault();
        const s = this.selectionStart;
        this.setRangeText('    ', s, this.selectionEnd, 'end');
    });

    // Ekle / düzenle
    const $modal = $('#scriptModal');
    $('#scriptIlk').on('change', function () { $('#scriptIlkSira').prop('disabled', !this.checked); });

    function modalAc(veri) {
        const f = $('#scriptForm')[0];
        f.reset();
        $('#scriptId').val(veri ? veri.Scriptler_id : '');
        $('#scriptModalBaslik span').text(veri ? 'Düzenle: ' + veri.Scriptler_Ad : 'Yeni Script');
        if (veri) {
            $('#scriptAd').val(veri.Scriptler_Ad);
            $('#scriptAciklama').val(veri.Scriptler_Aciklama || '');
            $('#scriptIcerik').val(veri.Scriptler_Icerik);
            $('#scriptSure').val(veri.Scriptler_ZamanAsimiSn);
            const p = parametreler(veri.Scriptler_Parametreler);
            $('#scriptParametreler').val(p.length ? JSON.stringify(p, null, 2) : '');
            $('#scriptIlk').prop('checked', veri.Scriptler_IlkKurulumSira !== null);
            $('#scriptIlkSira').val(veri.Scriptler_IlkKurulumSira || 1);
        }
        $('#scriptIlk').trigger('change');
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    }

    $('#yeniScript').on('click', () => modalAc(null));
    $tablo.on('click', '.script-duzenle', function () {
        $.post(adres, { action: 'getir', id: $(this).data('id') }, function (c) {
            if (c.basarili) modalAc(c.veri); else showToast(c.mesaj, 'error');
        });
    });

    $('#scriptKaydet').on('click', function () {
        const form = $('#scriptForm')[0];
        if (!form.reportValidity()) return;
        const p = $('#scriptParametreler').val().trim();
        if (p) {
            try { JSON.parse(p); } catch (e) { showToast('Parametre tanımı geçerli JSON değil: ' + e.message, 'error'); return; }
        }
        const $btn = $(this).prop('disabled', true);
        $.post(adres, $('#scriptForm').serialize() + '&action=kaydet', function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (c.basarili) {
                bootstrap.Modal.getInstance($modal[0]).hide();
                yenile();
            }
        }).always(() => $btn.prop('disabled', false));
    });

    // Sil
    $tablo.on('click', '.script-sil', function () {
        const id = $(this).data('id');
        const ad = tablo.row($(this).closest('tr')).data().Scriptler_Ad;
        Swal.fire({
            title: 'Script silinsin mi?',
            text: ad + ' — gönderilmiş komutların geçmişi korunur.',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Sil', cancelButtonText: 'Vazgeç', confirmButtonColor: '#dc3545'
        }).then(s => {
            if (!s.isConfirmed) return;
            $.post(adres, { action: 'sil', id: id }, c => { showToast(c.mesaj, c.basarili ? 'success' : 'error'); yenile(); });
        });
    });

    // Gönder: metin / sayi parametreleri için alan üretilir; diğer tipler teslim anında sunucuda çözülür
    const $gModal = $('#gonderModal');
    $tablo.on('click', '.script-gonder', function () {
        const r = tablo.row($(this).closest('tr')).data();
        $('#gonderForm')[0].reset();
        $('#gonderCihazlar, #gonderGruplar').val(null).trigger('change');
        $('#gonderId').val(r.Scriptler_id);
        $('#gonderModalBaslik span').text(r.Scriptler_Ad);

        const $alan = $('#gonderParametreler').empty();
        parametreler(r.Scriptler_Parametreler).forEach(p => {
            const etiket = uzakKacis(p.etiket || p.ad);
            if (p.tip === 'metin' || p.tip === 'sayi') {
                $alan.append(
                    '<div class="mb-3"><label class="form-label">' + etiket + ' <code class="small">' + uzakKacis(p.ad) + '</code></label>'
                    + '<input class="form-control" name="degerler[' + uzakKacis(p.ad) + ']" type="' + (p.tip === 'sayi' ? 'number' : 'text') + '"'
                    + ' value="' + uzakKacis(p.varsayilan ?? '') + '"></div>'
                );
            } else {
                const aciklama = p.tip === 'ayar' ? 'ayar <code>' + uzakKacis(p.ayarAnahtari) + '</code> değeri' : 'DB\'de kullanılmayan ' + uzakKacis(p.onek) + 'XXXX adayları';
                $alan.append('<div class="small text-muted mb-2"><i class="bi bi-magic me-1"></i><strong>' + etiket + '</strong>: ' + aciklama + ' (teslim anında otomatik)</div>');
            }
        });
        bootstrap.Modal.getOrCreateInstance($gModal[0]).show();
    });

    $('#gonderOnay').on('click', function () {
        const cihaz = ($('#gonderCihazlar').val() || []).length;
        const grup = ($('#gonderGruplar').val() || []).length;
        if (!cihaz && !grup) { showToast('En az bir cihaz ya da grup seçin', 'warning'); return; }
        const hedef = [cihaz ? cihaz + ' cihaz' : '', grup ? grup + ' grup' : ''].filter(Boolean).join(' + ');
        Swal.fire({
            title: 'Komut gönderilsin mi?',
            html: '<strong>' + uzakKacis($('#gonderModalBaslik span').text()) + '</strong><br>' + hedef + ' — SYSTEM yetkisiyle çalışır.',
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'Gönder', cancelButtonText: 'Vazgeç', confirmButtonColor: '#ffc107'
        }).then(s => {
            if (!s.isConfirmed) return;
            const $btn = $('#gonderOnay').prop('disabled', true);
            $.post(adres, $('#gonderForm').serialize() + '&action=gonder', function (c) {
                showToast(c.mesaj, c.basarili ? (c.uyari ? 'warning' : 'success') : 'error');
                if (c.basarili) { bootstrap.Modal.getInstance($gModal[0]).hide(); loadStats(); }
            }).always(() => $btn.prop('disabled', false));
        });
    });

    // Sonuçlar: script'in tüm cihazlardaki gönderimleri (server-side; geçmiş sınırsız büyür)
    const $sModal  = $('#sonucModal');
    const $scModal = $('#sonucCiktiModal');
    let sonucTablo = null;
    let sonucScriptId = 0;

    $tablo.on('click', '.script-sonuc', function () {
        const r = tablo.row($(this).closest('tr')).data();
        sonucScriptId = r.Scriptler_id;
        $('#sonucModalBaslik span').text(r.Scriptler_Ad);
        $('#sonucDurum').val('').trigger('change.select2');
        bootstrap.Modal.getOrCreateInstance($sModal[0]).show();
    });

    // Modal görünür olunca kurulur (scrollX genişliği doğru hesaplansın); sonraki açılışlarda yenilenir
    $sModal.on('shown.bs.modal', function () {
        if (!sonucTablo) {
            sonucTablo = $('#sonucTablo').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                processing: true, serverSide: true, scrollX: true, dom: 'lrtip', pageLength: 25,
                order: [[4, 'desc']], // Gönderim tarihi: en yeni üstte
                columnDefs: [{ targets: 6, orderable: false }],
                ajax: {
                    url: adres, type: 'POST',
                    data: function (d) {
                        d.action = 'sonuclar';
                        d.scriptId = sonucScriptId;
                        d.durum = $('#sonucDurum').val();
                        d.gonderimBas = $('#sonucGonderimBas').val();
                        d.gonderimBit = $('#sonucGonderimBit').val();
                        d.bitisBas = $('#sonucBitisBas').val();
                        d.bitisBit = $('#sonucBitisBit').val();
                    }
                },
                columns: [
                    {
                        data: 'Cihazlar_BilgisayarAdi',
                        render: (d, t, r) => '<a href="/admin/uzak-cihaz-detay?id=' + encodeURIComponent(r.CihazId)
                            + '" target="_blank" rel="noopener">' + uzakKacis(d) + ' <i class="bi bi-box-arrow-up-right small"></i></a>'
                    },
                    { data: 'DurumAd', render: (d, t, r) => '<span class="badge text-bg-' + uzakKacis(r.DurumRenk || 'secondary') + '">' + uzakKacis(d) + '</span>' },
                    { data: 'KomutHedefleri_CikisKodu', className: 'text-center', render: d => d === null ? uzakBos : uzakKacis(d) },
                    { data: 'Gonderen', render: d => d ? uzakKacis(d) : uzakBos },
                    { data: 'Olusturma', render: d => uzakKacis(d) },
                    { data: 'Bitis', render: d => d ? uzakKacis(d) : uzakBos },
                    {
                        data: null, className: 'text-end',
                        render: (d, t, r) => r.CiktiVar == 1
                            ? '<button type="button" class="btn btn-outline-secondary btn-sm sonuc-cikti" data-id="' + r.KomutHedefleri_id + '" title="Çıktı / ekran"><i class="bi bi-terminal"></i></button>'
                            : uzakBos
                    }
                ]
            });
        } else {
            sonucTablo.ajax.reload();
        }
        sonucTablo.columns.adjust();
    });

    // Filtreler değişince otomatik uygula (ayrı buton gerekmez)
    $('#sonucDurum').on('change', () => { if (sonucTablo) sonucTablo.ajax.reload(); });
    $('#sonucGonderimBas, #sonucGonderimBit, #sonucBitisBas, #sonucBitisBit').on('change', () => { if (sonucTablo) sonucTablo.ajax.reload(); });
    $('#sonucFiltreTemizle').on('click', function () {
        $('#sonucGonderimBas, #sonucGonderimBit, #sonucBitisBas, #sonucBitisBit').val('');
        $('#sonucDurum').val('').trigger('change.select2');
        if (sonucTablo) sonucTablo.ajax.reload();
    });

    // Çıktı / ekran görüntüsü
    $('#sonucTablo').on('click', '.sonuc-cikti', function () {
        const hedefId = $(this).data('id');
        $.post(adres, { action: 'sonuc_cikti', hedefId: hedefId }, function (c) {
            if (!c.basarili) { showToast(c.mesaj, 'error'); return; }
            const v = c.veri;
            $('#sonucCiktiBaslik span').text(v.Cihazlar_BilgisayarAdi + ' — ' + v.DurumAd
                + (v.KomutHedefleri_CikisKodu !== null ? ' (çıkış ' + v.KomutHedefleri_CikisKodu + ')' : ''));
            $('#sonucCiktiZaman').text(['Alındı: ' + (v.Alinma || '-'), 'Başladı: ' + (v.Baslama || '-'), 'Bitti: ' + (v.Bitis || '-')].join('  ·  '));
            $('#sonucCiktiMetin').text(v.KomutHedefleri_Cikti || '(çıktı yok)');
            $('#sonucCiktiHata').text(v.KomutHedefleri_Hata || '');
            $('#sonucCiktiHataAlan').toggle(!!v.KomutHedefleri_Hata);

            const $e = $('#sonucEkranlar').empty();
            const ekranlar = c.ekranlar || [];
            ekranlar.forEach(function (dosya) {
                const url = adres + '?action=ekran_goster&hedefId=' + encodeURIComponent(hedefId) + '&dosya=' + encodeURIComponent(dosya);
                $('<a>', { href: url, target: '_blank', rel: 'noopener', title: dosya })
                    .append($('<img>', { src: url, class: 'img-thumbnail', css: { maxHeight: '140px', cursor: 'zoom-in' } }))
                    .appendTo($e);
            });
            $('#sonucEkranAlan').toggleClass('d-none', ekranlar.length === 0);

            bootstrap.Modal.getOrCreateInstance($scModal[0]).show();
        });
    });

    // Çıktı / hata kopyala
    $scModal.on('click', '.sonuc-kopya', function () {
        const metin = $($(this).data('hedef')).text();
        navigator.clipboard.writeText(metin).then(() => showToast('Kopyalandı', 'success'), () => showToast('Kopyalanamadı', 'error'));
    });
});
