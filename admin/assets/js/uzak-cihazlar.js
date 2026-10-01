/**
 * Uzak Yönetim - Cihazlar: server-side DataTables + filtre + InfoBox
 */
$(function () {
    const adres = '/admin/uzak-cihazlar';

    // Hata penceresi yerine uzak-yonetim.js'deki ajaxError → toast kullanılır
    $.fn.dataTable.ext.errMode = 'none';

    const tablo = $('#cihazTablo').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        serverSide: true,
        scrollX: true,
        dom: 'lrtip', // global arama kapalı — filtre panelindeki "Ara" kullanılır
        pageLength: 25,
        order: [[8, 'desc']],
        ajax: {
            url: adres,
            type: 'POST',
            data: function (d) {
                d.action = 'liste';
                d.ara = $('#filtreAra').val();
                d.grup = $('#filtreGrup').val();
                d.baglanti = $('#filtreBaglanti').val();
                d.pilot = $('#filtrePilot').is(':checked') ? '1' : '';
            }
        },
        columns: [
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
    $('#filtreGrup, #filtreBaglanti, #filtrePilot').on('change', () => tablo.ajax.reload());
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreGrup, #filtreBaglanti').val('').trigger('change.select2');
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
});
