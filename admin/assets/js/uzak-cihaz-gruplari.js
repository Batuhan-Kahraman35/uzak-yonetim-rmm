/**
 * Uzak Yönetim - Cihaz Grupları: client-side DataTables + filtre + InfoBox + ekle/düzenle/durum/sil
 */
$(function () {
    const adres = '/admin/uzak-cihaz-gruplari';
    const $tablo = $('#grupTablo');
    const yetki = { duzenle: $tablo.data('duzenle') == 1, sil: $tablo.data('sil') == 1 };

    $.fn.dataTable.ext.errMode = 'none';

    const tablo = $tablo.DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[1, 'asc']],
        ajax: {
            url: adres,
            type: 'POST',
            data: function (d) {
                d.action = 'liste';
                d.ara = $('#filtreAra').val();
                d.durum = $('#filtreDurum').val();
            }
        },
        columns: [
            { data: 'CihazGruplari_id' },
            { data: 'CihazGruplari_Ad', render: d => uzakKacis(d) },
            { data: 'CihazGruplari_Aciklama', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'CihazAdet', className: 'text-center' },
            { data: 'KodAdet', className: 'text-center' },
            {
                data: 'Durum', className: 'text-center',
                render: (d, t, r) => t !== 'display' ? d :
                    '<div class="form-check form-switch d-flex justify-content-center">'
                    + '<input class="form-check-input grup-durum" type="checkbox" role="switch" id="grupDurum' + r.CihazGruplari_id + '"'
                    + ' data-id="' + r.CihazGruplari_id + '"' + (d == 1 ? ' checked' : '') + (yetki.duzenle ? '' : ' disabled') + '>'
                    + '<label class="form-check-label" for="grupDurum' + r.CihazGruplari_id + '"></label></div>'
            },
            { data: 'SonIslem', render: d => uzakKacis(d) },
            {
                data: null, orderable: false, className: 'text-end text-nowrap',
                render: (d, t, r) => {
                    let h = '';
                    if (yetki.duzenle) h += '<button type="button" class="btn btn-outline-primary btn-sm me-1 grup-duzenle" title="Düzenle"><i class="bi bi-pencil"></i></button>';
                    if (yetki.sil && r.CihazAdet == 0) h += '<button type="button" class="btn btn-outline-danger btn-sm grup-sil" data-id="' + r.CihazGruplari_id + '" title="Sil"><i class="bi bi-trash"></i></button>';
                    return h;
                }
            }
        ]
    });

    function loadStats() {
        $.post(adres, { action: 'istatistik' }, function (c) {
            if (!c.basarili) return;
            $('#istToplam').text(c.veri.toplam);
            $('#istAktif').text(c.veri.aktif);
            $('#istBos').text(c.veri.bos);
            $('#istCihaz').text(c.veri.cihaz);
        });
    }
    tablo.one('draw', loadStats);

    function yenile() {
        tablo.ajax.reload(null, false);
        loadStats();
    }

    // Filtre
    $('#filtreUygula').on('click', () => tablo.ajax.reload());
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') tablo.ajax.reload(); });
    $('#filtreDurum').on('change', () => tablo.ajax.reload());
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreDurum').val('').trigger('change.select2');
        tablo.ajax.reload();
        showToast('Filtreler temizlendi', 'info');
    });

    // Ekle / düzenle
    const modal = bootstrap.Modal.getOrCreateInstance('#grupModal');
    function modalAc(satir) {
        $('#grupForm')[0].reset();
        $('#grupId').val(satir ? satir.CihazGruplari_id : 0);
        $('#grupAd').val(satir ? satir.CihazGruplari_Ad : '');
        $('#grupAciklama').val(satir ? (satir.CihazGruplari_Aciklama || '') : '');
        $('#grupModalBaslik span').text(satir ? 'Grubu Düzenle' : 'Yeni Grup');
        $('#grupKaydet').prop('disabled', false);
        modal.show();
    }
    $('#grupModal').on('shown.bs.modal', () => $('#grupAd').trigger('focus'));

    $('#grupEkle').on('click', () => modalAc(null));
    $tablo.on('click', '.grup-duzenle', function () {
        modalAc(tablo.row($(this).closest('tr')).data());
    });

    $('#grupForm').on('submit', e => { e.preventDefault(); $('#grupKaydet').trigger('click'); });
    $('#grupKaydet').on('click', function () {
        const form = $('#grupForm')[0];
        if (!form.reportValidity()) return;
        const $btn = $(this).prop('disabled', true);
        $.post(adres, $('#grupForm').serialize() + '&action=kaydet', function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (!c.basarili) return;
            modal.hide();
            yenile();
        }).always(() => $btn.prop('disabled', false));
    });

    // Durum
    $tablo.on('change', '.grup-durum', function () {
        const $s = $(this).prop('disabled', true);
        const durum = $s.is(':checked') ? 1 : 0;
        $.post(adres, { action: 'durum', id: $s.data('id'), durum: durum }, function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (!c.basarili) $s.prop('checked', !durum);
            yenile();
        }).fail(() => $s.prop('checked', !durum))
          .always(() => $s.prop('disabled', false));
    });

    // Sil
    $tablo.on('click', '.grup-sil', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Grup silinsin mi?',
            text: 'Bağlı cihaz veya kayıt kodu varsa silinmez; pasife alın.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sil',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(function (s) {
            if (!s.isConfirmed) return;
            $.post(adres, { action: 'sil', id: id }, function (c) {
                showToast(c.mesaj, c.basarili ? 'success' : 'error');
                yenile();
            });
        });
    });
});
