/**
 * Uzak Yönetim - Cihaz Grupları ve Etiketler: iki sekme, client-side DataTables + ortak filtre + InfoBox
 */
$(function () {
    const adres = '/admin/uzak-cihaz-gruplari';
    const $grupTablo = $('#grupTablo');
    const $etiketTablo = $('#etiketTablo');
    const yetki = { duzenle: $grupTablo.data('duzenle') == 1, sil: $grupTablo.data('sil') == 1 };

    $.fn.dataTable.ext.errMode = 'none';

    const ortak = {
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[1, 'asc']]
    };
    const filtre = (action) => function (d) {
        d.action = action;
        d.ara = $('#filtreAra').val();
        d.durum = $('#filtreDurum').val();
    };
    const durumSwitch = (sinif, onek) => (d, t, r) => {
        if (t !== 'display') return d;
        const id = r[onek + '_id'];
        return '<div class="form-check form-switch d-flex justify-content-center">'
            + '<input class="form-check-input ' + sinif + '" type="checkbox" role="switch" id="' + sinif + id + '"'
            + ' data-id="' + id + '"' + (d == 1 ? ' checked' : '') + (yetki.duzenle ? '' : ' disabled') + '>'
            + '<label class="form-check-label" for="' + sinif + id + '"></label></div>';
    };

    const grupTablo = $grupTablo.DataTable($.extend({}, ortak, {
        ajax: { url: adres, type: 'POST', data: filtre('liste') },
        columns: [
            { data: 'CihazGruplari_id' },
            { data: 'CihazGruplari_Ad', render: d => uzakKacis(d) },
            { data: 'CihazGruplari_Aciklama', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'CihazAdet', className: 'text-center' },
            { data: 'KodAdet', className: 'text-center' },
            { data: 'Durum', className: 'text-center', render: durumSwitch('grup-durum', 'CihazGruplari') },
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
    }));

    const etiketTablo = $etiketTablo.DataTable($.extend({}, ortak, {
        ajax: { url: adres, type: 'POST', data: filtre('etiket_liste') },
        columns: [
            { data: 'Etiketler_id' },
            {
                data: 'Etiketler_Ad',
                render: (d, t, r) => t === 'display' ? uzakEtiketRozet({ ad: d, renk: r.Etiketler_Renk }) : d
            },
            { data: 'Etiketler_Aciklama', render: d => d ? uzakKacis(d) : uzakBos },
            { data: 'CihazAdet', className: 'text-center' },
            { data: 'Durum', className: 'text-center', render: durumSwitch('etiket-durum', 'Etiketler') },
            { data: 'SonIslem', render: d => uzakKacis(d) },
            {
                data: null, orderable: false, className: 'text-end text-nowrap',
                render: (d, t, r) => {
                    let h = '';
                    if (yetki.duzenle) h += '<button type="button" class="btn btn-outline-primary btn-sm me-1 etiket-duzenle" title="Düzenle"><i class="bi bi-pencil"></i></button>';
                    if (yetki.sil) h += '<button type="button" class="btn btn-outline-danger btn-sm etiket-sil" title="Sil"><i class="bi bi-trash"></i></button>';
                    return h;
                }
            }
        ]
    }));

    // Gizli sekmedeki scrollX tablosu açılınca kolon genişlikleri yeniden hesaplanır
    let etiketSekmesi = false;
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        etiketSekmesi = e.target.id === 'sekmeEtiket';
        $('#yeniKayit span').text(etiketSekmesi ? 'Yeni Etiket' : 'Yeni Grup');
        (etiketSekmesi ? etiketTablo : grupTablo).columns.adjust();
    });

    function loadStats() {
        $.post(adres, { action: 'istatistik' }, function (c) {
            if (!c.basarili) return;
            $('#istToplam').text(c.veri.toplam);
            $('#istAktif').text(c.veri.aktif);
            $('#istBos').text(c.veri.bos);
            $('#istEtiket').text(c.veri.etiket);
            $('#istEtiketliCihaz').text(c.veri.etiketliCihaz);
        });
    }
    grupTablo.one('draw', loadStats);

    function yenile() {
        grupTablo.ajax.reload(null, false);
        etiketTablo.ajax.reload(null, false);
        loadStats();
    }

    // Filtre (iki sekmeye birlikte uygulanır)
    const filtrele = () => { grupTablo.ajax.reload(); etiketTablo.ajax.reload(); };
    $('#filtreUygula').on('click', filtrele);
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') filtrele(); });
    $('#filtreDurum').on('change', filtrele);
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtreDurum').val('').trigger('change.select2');
        filtrele();
        showToast('Filtreler temizlendi', 'info');
    });

    $('#yeniKayit').on('click', () => etiketSekmesi ? etiketModalAc(null) : grupModalAc(null));

    /* ---------- Gruplar ---------- */

    const grupModal = bootstrap.Modal.getOrCreateInstance('#grupModal');
    function grupModalAc(satir) {
        $('#grupForm')[0].reset();
        $('#grupId').val(satir ? satir.CihazGruplari_id : 0);
        $('#grupAd').val(satir ? satir.CihazGruplari_Ad : '');
        $('#grupAciklama').val(satir ? (satir.CihazGruplari_Aciklama || '') : '');
        $('#grupModalBaslik span').text(satir ? 'Grubu Düzenle' : 'Yeni Grup');
        $('#grupKaydet').prop('disabled', false);
        grupModal.show();
    }
    $('#grupModal').on('shown.bs.modal', () => $('#grupAd').trigger('focus'));
    $grupTablo.on('click', '.grup-duzenle', function () {
        grupModalAc(grupTablo.row($(this).closest('tr')).data());
    });

    $('#grupForm').on('submit', e => { e.preventDefault(); $('#grupKaydet').trigger('click'); });
    $('#grupKaydet').on('click', function () {
        if (!$('#grupForm')[0].reportValidity()) return;
        const $btn = $(this).prop('disabled', true);
        $.post(adres, $('#grupForm').serialize() + '&action=kaydet', function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (!c.basarili) return;
            grupModal.hide();
            yenile();
        }).always(() => $btn.prop('disabled', false));
    });

    $grupTablo.on('change', '.grup-durum', function () {
        durumDegistir($(this), 'durum');
    });

    $grupTablo.on('click', '.grup-sil', function () {
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

    /* ---------- Etiketler ---------- */

    const etiketModal = bootstrap.Modal.getOrCreateInstance('#etiketModal');
    function onizle() {
        $('#etiketOnizleme').html(uzakEtiketRozet({ ad: $('#etiketAd').val() || 'Etiket', renk: $('#etiketRenk').val() }));
    }
    function etiketModalAc(satir) {
        $('#etiketForm')[0].reset();
        $('#etiketId').val(satir ? satir.Etiketler_id : 0);
        $('#etiketAd').val(satir ? satir.Etiketler_Ad : '');
        $('#etiketRenk').val(satir ? satir.Etiketler_Renk : '#6c757d');
        $('#etiketAciklama').val(satir ? (satir.Etiketler_Aciklama || '') : '');
        $('#etiketModalBaslik span').text(satir ? 'Etiketi Düzenle' : 'Yeni Etiket');
        $('#etiketKaydet').prop('disabled', false);
        onizle();
        etiketModal.show();
    }
    $('#etiketAd, #etiketRenk').on('input', onizle);
    $('#etiketModal').on('shown.bs.modal', () => $('#etiketAd').trigger('focus'));
    $etiketTablo.on('click', '.etiket-duzenle', function () {
        etiketModalAc(etiketTablo.row($(this).closest('tr')).data());
    });

    $('#etiketForm').on('submit', e => { e.preventDefault(); $('#etiketKaydet').trigger('click'); });
    $('#etiketKaydet').on('click', function () {
        if (!$('#etiketForm')[0].reportValidity()) return;
        const $btn = $(this).prop('disabled', true);
        $.post(adres, $('#etiketForm').serialize() + '&action=etiket_kaydet', function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (!c.basarili) return;
            etiketModal.hide();
            yenile();
        }).always(() => $btn.prop('disabled', false));
    });

    $etiketTablo.on('change', '.etiket-durum', function () {
        durumDegistir($(this), 'etiket_durum');
    });

    $etiketTablo.on('click', '.etiket-sil', function () {
        const r = etiketTablo.row($(this).closest('tr')).data();
        Swal.fire({
            title: 'Etiket silinsin mi?',
            text: r.CihazAdet > 0 ? 'Bu etiket ' + r.CihazAdet + ' cihazdan da kaldırılacak.' : 'Bu etiket hiçbir cihazda kullanılmıyor.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sil',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(function (s) {
            if (!s.isConfirmed) return;
            $.post(adres, { action: 'etiket_sil', id: r.Etiketler_id }, function (c) {
                showToast(c.mesaj, c.basarili ? 'success' : 'error');
                yenile();
            });
        });
    });

    /* ---------- Ortak: aktif/pasif anahtarı ---------- */

    function durumDegistir($s, action) {
        $s.prop('disabled', true);
        const durum = $s.is(':checked') ? 1 : 0;
        $.post(adres, { action: action, id: $s.data('id'), durum: durum }, function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : 'error');
            if (!c.basarili) $s.prop('checked', !durum);
            yenile();
        }).fail(() => $s.prop('checked', !durum))
          .always(() => $s.prop('disabled', false));
    }
});
