/**
 * Uzak Yönetim - Cihazlar: server-side DataTables + filtre + InfoBox + toplu etiket
 */
$(function () {
    const adres = '/admin/uzak-cihazlar';
    const $tablo = $('#cihazTablo');
    const duzenle = $tablo.data('duzenle') == 1;
    const secili = new Set(); // sayfa değişse / tablo yenilense de seçim korunur

    // Hata penceresi yerine uzak-yonetim.js'deki ajaxError → toast kullanılır
    $.fn.dataTable.ext.errMode = 'none';

    uzakEtiketSelect($('#filtreEtiket'));

    const tablo = $tablo.DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        serverSide: true,
        scrollX: true,
        dom: 'lrtip', // global arama kapalı — filtre panelindeki "Ara" kullanılır
        pageLength: 25,
        order: [[10, 'desc']],
        ajax: {
            url: adres,
            type: 'POST',
            data: function (d) {
                d.action = 'liste';
                d.ara = $('#filtreAra').val();
                d.grup = $('#filtreGrup').val();
                d.baglanti = $('#filtreBaglanti').val();
                d.pilot = $('#filtrePilot').is(':checked') ? '1' : '';
                d.etiketler = $('#filtreEtiket').val() || [];
                d.etiketMod = $('#filtreEtiketMod').val();
            }
        },
        columns: [
            {
                data: 'Cihazlar_id', orderable: false, visible: duzenle, className: 'text-center',
                render: d => '<div class="form-check form-switch d-flex justify-content-center">'
                    + '<input class="form-check-input satir-sec" type="checkbox" role="switch" id="sec' + d + '" data-id="' + d + '"' + (secili.has(String(d)) ? ' checked' : '') + '>'
                    + '<label class="form-check-label" for="sec' + d + '"></label></div>'
            },
            {
                data: 'Cihazlar_BilgisayarAdi',
                render: (d, t, r) => {
                    let h = '<a href="/admin/uzak-cihaz-detay?id=' + encodeURIComponent(r.Cihazlar_id) + '" class="fw-semibold">' + uzakKacis(d) + '</a>';
                    if (r.Cihazlar_PilotMu == 1) h += ' <span class="badge text-bg-warning ms-1">Pilot</span>';
                    if (r.Cihazlar_Aciklama) h += '<div class="small text-muted">' + uzakKacis(r.Cihazlar_Aciklama) + '</div>';
                    return h;
                }
            },
            { data: 'CihazGruplari_Ad', render: d => d ? uzakKacis(d) : uzakBos },
            {
                data: 'Etiketler', orderable: false,
                render: d => d && d.length ? d.map(uzakEtiketRozet).join('') : uzakBos
            },
            {
                data: 'CevrimiciMi',
                render: d => d == 1
                    ? '<span class="durum-nokta cevrimici"></span>Çevrimiçi'
                    : '<span class="durum-nokta cevrimdisi"></span>Çevrimdışı'
            },
            {
                data: 'Cihazlar_IsletimSistemi',
                render: (d, t, r) => d ? uzakKacis(d) + (r.Cihazlar_OsSurum ? ' <span class="text-muted">' + uzakKacis(r.Cihazlar_OsSurum) + '</span>' : '') : uzakBos
            },
            { data: 'Cihazlar_AktifKullanici', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'Cihazlar_IcIp', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'Cihazlar_DisIp', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'Cihazlar_AjanSurum', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'Cihazlar_SonGorulme', render: d => d ? uzakKacis(d) : '<span class="text-muted">Hiç</span>' }
        ]
    });

    function loadStats() {
        $.post(adres, { action: 'istatistik' }, function (c) {
            if (!c.basarili) return;
            $('#istToplam').text(c.veri.toplam);
            $('#istCevrimici').text(c.veri.cevrimici);
            $('#istCevrimdisi').text(c.veri.cevrimdisi);
            $('#istPilot').text(c.veri.pilot);
        });
    }

    // İstatistikler tablo ilk kez yüklendikten sonra çekilir
    tablo.one('draw', loadStats);

    $('#filtreUygula').on('click', () => tablo.ajax.reload());
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') tablo.ajax.reload(); });
    $('#filtreGrup, #filtreBaglanti, #filtrePilot, #filtreEtiket, #filtreEtiketMod').on('change', () => tablo.ajax.reload());
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreGrup, #filtreBaglanti').val('').trigger('change.select2');
        $('#filtreEtiket').val(null).trigger('change.select2');
        $('#filtreEtiketMod').val('herhangi').trigger('change.select2');
        $('#filtrePilot').prop('checked', false);
        tablo.ajax.reload();
        showToast('Filtreler temizlendi', 'info');
    });

    // Çevrimiçi durumu canlı kalsın: sayfa açıkken dakikada bir yenile
    setInterval(function () {
        if (document.hidden) return;
        tablo.ajax.reload(null, false);
        loadStats();
    }, 60000);

    /* ---------- Seçim + toplu etiket ---------- */

    if (!duzenle) return;

    function secimGuncelle() {
        $('#seciliAdet').text(secili.size);
        $('#topluEtiket').prop('disabled', secili.size === 0);
        $('#secimTemizle').toggleClass('d-none', secili.size === 0);
        const $sayfa = $tablo.find('.satir-sec');
        const isaretli = $sayfa.filter(':checked').length;
        $('#tumunuSec').prop('checked', $sayfa.length > 0 && isaretli === $sayfa.length)
                       .prop('indeterminate', isaretli > 0 && isaretli < $sayfa.length);
    }
    tablo.on('draw', secimGuncelle);

    $tablo.on('change', '.satir-sec', function () {
        const id = String($(this).data('id'));
        this.checked ? secili.add(id) : secili.delete(id);
        secimGuncelle();
    });
    // scrollX başlığı ayrı tabloya taşındığı için delegasyon belge üzerinden
    $(document).on('change', '#tumunuSec', function () {
        const durum = this.checked;
        $tablo.find('.satir-sec').each(function () {
            this.checked = durum;
            const id = String($(this).data('id'));
            durum ? secili.add(id) : secili.delete(id);
        });
        secimGuncelle();
    });
    $('#secimTemizle').on('click', function () {
        secili.clear();
        $tablo.find('.satir-sec').prop('checked', false);
        secimGuncelle();
    });

    const $modal = $('#topluEtiketModal');
    const modal = bootstrap.Modal.getOrCreateInstance($modal[0]);
    uzakEtiketSelect($('#topluEtiketler'), $modal);

    $('#topluEtiket').on('click', function () {
        $('#topluAdet').text(secili.size);
        $('#topluEkle').prop('checked', true);
        $('#topluEtiketler').val(null).trigger('change');
        $('#topluUygula').prop('disabled', false);
        modal.show();
    });

    $('#topluUygula').on('click', function () {
        const etiketler = $('#topluEtiketler').val() || [];
        if (!etiketler.length) {
            showToast('En az bir etiket seçin', 'warning');
            return;
        }
        const $btn = $(this).prop('disabled', true);
        $.post(adres, {
            action: 'etiket_toplu',
            islem: $('input[name="topluIslem"]:checked').val(),
            cihazlar: Array.from(secili),
            etiketler: etiketler
        }, function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (!c.basarili) return;
            modal.hide();
            tablo.ajax.reload(null, false);
        }).always(() => $btn.prop('disabled', false));
    });
});
