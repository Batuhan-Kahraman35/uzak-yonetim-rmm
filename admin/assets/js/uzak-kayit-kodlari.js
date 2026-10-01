/**
 * Uzak Yönetim - Kayıt Kodları: client-side DataTables + filtre + InfoBox + ekleme/iptal
 */
$(function () {
    const adres = '/admin/uzak-kayit-kodlari';
    const durumlar = {
        aktif:       '<span class="badge text-bg-success">Aktif</span>',
        suresidoldu: '<span class="badge text-bg-secondary">Süresi Doldu</span>',
        limitdoldu:  '<span class="badge text-bg-warning">Limit Doldu</span>',
        iptal:       '<span class="badge text-bg-dark">İptal</span>'
    };

    $.fn.dataTable.ext.errMode = 'none';

    const tablo = $('#kodTablo').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[0, 'desc']],
        ajax: {
            url: adres,
            type: 'POST',
            data: function (d) {
                d.action = 'liste';
                d.ara = $('#filtreAra').val();
                d.durum = $('#filtreDurum').val();
                d.grup = $('#filtreGrup').val();
            }
        },
        columns: [
            { data: 'KayitKodlari_id' },
            { data: 'KayitKodlari_Aciklama', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'CihazGruplari_Ad', render: d => d ? uzakKacis(d) : uzakBos },
            {
                data: 'KayitKodlari_KullanimSayisi',
                render: (d, t, r) => t === 'display' ? d + ' / ' + r.KayitKodlari_KullanimLimiti : d
            },
            { data: 'SonKullanma', render: d => uzakKacis(d) },
            { data: 'KodDurum', render: (d, t) => t === 'display' ? (durumlar[d] || uzakKacis(d)) : d },
            { data: 'Olusturan', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'Olusturma', render: d => uzakKacis(d) },
            {
                data: null, orderable: false, className: 'text-end text-nowrap',
                render: (d, t, r) => {
                    let h = '';
                    if (r.KodDurum === 'aktif' && r.IndirilebilirMi == 1) {
                        h += '<button type="button" class="btn btn-outline-primary btn-sm kod-indir me-1" data-id="' + r.KayitKodlari_id + '" title="Kurulum dosyasını indir (.cmd)"><i class="bi bi-download"></i></button>';
                    }
                    if (r.KodDurum !== 'iptal') {
                        h += '<button type="button" class="btn btn-outline-danger btn-sm kod-iptal" data-id="' + r.KayitKodlari_id + '" title="İptal et"><i class="bi bi-slash-circle"></i></button>';
                    }
                    return h;
                }
            }
        ]
    });

    function loadStats() {
        $.post(adres, { action: 'istatistik' }, function (c) {
            if (!c.basarili) return;
            $('#istAktif').text(c.veri.aktif);
            $('#istPasif').text(c.veri.pasif);
            $('#istKullanim').text(c.veri.kullanim);
            $('#istCihaz').text(c.veri.cihaz);
        });
    }
    tablo.one('draw', loadStats);

    // Filtre
    $('#filtreUygula').on('click', () => tablo.ajax.reload());
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') tablo.ajax.reload(); });
    $('#filtreDurum, #filtreGrup').on('change', () => tablo.ajax.reload());
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreDurum, #filtreGrup').val('').trigger('change.select2');
        tablo.ajax.reload();
        showToast('Filtreler temizlendi', 'info');
    });

    // Yeni kod
    const $modal = $('#ekleModal');
    $modal.on('show.bs.modal', function () {
        $('#ekleForm').removeClass('d-none')[0].reset();
        $('#ekleGrup').trigger('change.select2');
        $('#kodSonuc').addClass('d-none');
        $('#kodMetin, #komutMetin').val('');
        $('#ekleKaydet').removeClass('d-none').prop('disabled', false);
    });
    // Modal kapanınca kod ekrandan silinir
    $modal.on('hidden.bs.modal', () => $('#kodMetin, #komutMetin').val(''));

    $('#ekleKaydet').on('click', function () {
        const form = $('#ekleForm')[0];
        if (!form.reportValidity()) return;
        const $btn = $(this).prop('disabled', true);
        $.post(adres, $('#ekleForm').serialize() + '&action=ekle', function (c) {
            if (!c.basarili) {
                showToast(c.mesaj, 'error');
                $btn.prop('disabled', false);
                return;
            }
            $('#ekleForm').addClass('d-none');
            $btn.addClass('d-none');
            $('#kodMetin').val(c.kod);
            $('#komutMetin').val(c.komut);
            $('#kodSonuc').removeClass('d-none');
            showToast(c.mesaj, 'success');
            tablo.ajax.reload();
            loadStats();
        }).fail(() => $btn.prop('disabled', false));
    });

    // Kurulum dosyası: sunucu içeriği üretir (CSRF'li POST), tarayıcı Blob'dan indirir; dosya sunucuda saklanmaz
    $('#kodTablo').on('click', '.kod-indir', function () {
        const $btn = $(this).prop('disabled', true);
        $.post(adres, { action: 'indir', id: $btn.data('id') }, function (c) {
            if (!c.basarili) {
                showToast(c.mesaj, 'error');
                return;
            }
            const url = URL.createObjectURL(new Blob([c.dosya], { type: 'application/octet-stream' }));
            const a = document.createElement('a');
            a.href = url;
            a.download = c.dosyaAdi;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        }).always(() => $btn.prop('disabled', false));
    });

    $modal.on('click', '[data-kopyala]', function () {
        const $alan = $($(this).data('kopyala'));
        navigator.clipboard.writeText($alan.val())
            .then(() => showToast('Kopyalandı', 'success'))
            .catch(() => { $alan.trigger('select'); showToast('Kopyalanamadı, elle seçin', 'warning'); });
    });

    // İptal
    $('#kodTablo').on('click', '.kod-iptal', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Kod iptal edilsin mi?',
            text: 'Bu kodla yeni cihaz kaydedilemez; kayıtlı cihazlar etkilenmez.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'İptal et',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(function (s) {
            if (!s.isConfirmed) return;
            $.post(adres, { action: 'iptal', id: id }, function (c) {
                showToast(c.mesaj, c.basarili ? 'success' : 'error');
                tablo.ajax.reload(null, false);
                loadStats();
            });
        });
    });
});
