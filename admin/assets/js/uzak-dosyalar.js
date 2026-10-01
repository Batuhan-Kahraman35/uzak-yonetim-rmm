/**
 * Uzak Yönetim - Dosya Deposu: liste, çoklu yükleme (FormData), sil
 */
$(function () {
    const adres = '/admin/uzak-dosyalar';
    const $tablo = $('#dosyaTablo');
    const silYetki = $tablo.data('sil') == 1;

    $.fn.dataTable.ext.errMode = 'none';

    const boyutYazi = (b) => {
        b = Number(b) || 0;
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
        return b + ' B';
    };

    const tablo = $tablo.DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[0, 'asc'], [1, 'asc']],
        ajax: {
            url: adres,
            type: 'POST',
            data: function (d) { d.action = 'liste'; d.ara = $('#filtreAra').val(); d.paket = $('#filtrePaket').val(); }
        },
        columns: [
            { data: 'UzakDosyalar_Paket', render: d => '<span class="badge text-bg-secondary">' + uzakKacis(d) + '</span>' },
            { data: 'UzakDosyalar_Ad', render: (d, t, r) => '<strong>' + uzakKacis(d) + '</strong>' + (r.UzakDosyalar_Aciklama ? '<div class="small text-muted">' + uzakKacis(r.UzakDosyalar_Aciklama) + '</div>' : '') },
            { data: 'UzakDosyalar_Boyut', render: (d, t) => t === 'display' ? boyutYazi(d) : d },
            { data: 'UzakDosyalar_Sha256', render: d => '<code class="small" title="' + uzakKacis(d) + '">' + uzakKacis(String(d).substring(0, 12)) + '…</code>' },
            { data: 'Guncelleme', render: (d, t, r) => uzakKacis(d) + (r.Yukleyen ? '<div class="small text-muted">' + uzakKacis(r.Yukleyen) + '</div>' : '') },
            {
                data: null, className: 'text-end text-nowrap', orderable: false,
                render: (d, t, r) => silYetki ? '<button type="button" class="btn btn-outline-danger btn-sm dosya-sil" data-id="' + r.UzakDosyalar_id + '" title="Sil"><i class="bi bi-trash"></i></button>' : ''
            }
        ]
    });

    function loadStats() {
        $.post(adres, { action: 'istatistik' }, function (c) {
            if (!c.basarili) return;
            $('#istDosya').text(c.veri.dosya);
            $('#istPaket').text(c.veri.paket);
            $('#istBoyut').text(boyutYazi(c.veri.boyut));
        });
    }
    tablo.one('draw', loadStats);
    const yenile = () => { tablo.ajax.reload(null, false); loadStats(); };

    $('#filtreUygula').on('click', () => tablo.ajax.reload());
    $('#filtreAra').on('keydown', e => { if (e.key === 'Enter') tablo.ajax.reload(); });
    $('#filtrePaket').on('change', () => tablo.ajax.reload());
    $('#filtreTemizle').on('click', function () {
        $('#filtreAra').val('');
        $('#filtrePaket').val('').trigger('change.select2');
        tablo.ajax.reload();
        showToast('Filtreler temizlendi', 'info');
    });

    // Yükleme (multipart FormData; CSRF başlığı ajaxPrefilter'dan gelir)
    const $modal = $('#yukleModal');
    $modal.on('show.bs.modal', function () { $('#yukleForm')[0].reset(); $('#yukleBar').addClass('d-none'); });

    $('#yukleKaydet').on('click', function () {
        const form = $('#yukleForm')[0];
        if (!form.reportValidity()) return;
        const fd = new FormData(form);
        fd.append('action', 'yukle');
        const $btn = $(this).prop('disabled', true);
        $('#yukleBar').removeClass('d-none');
        $.ajax({
            url: adres, type: 'POST', data: fd, processData: false, contentType: false
        }).done(function (c) {
            showToast(c.mesaj, c.basarili ? (c.uyari ? 'warning' : 'success') : 'error');
            if (c.basarili) { bootstrap.Modal.getInstance($modal[0]).hide(); yenile(); }
        }).always(() => { $btn.prop('disabled', false); $('#yukleBar').addClass('d-none'); });
    });

    // Sil
    $tablo.on('click', '.dosya-sil', function () {
        const r = tablo.row($(this).closest('tr')).data();
        Swal.fire({
            title: 'Dosya silinsin mi?',
            text: r.UzakDosyalar_Paket + ' / ' + r.UzakDosyalar_Ad,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Sil', cancelButtonText: 'Vazgeç', confirmButtonColor: '#dc3545'
        }).then(s => {
            if (!s.isConfirmed) return;
            $.post(adres, { action: 'sil', id: r.UzakDosyalar_id }, c => { showToast(c.mesaj, c.basarili ? 'success' : 'error'); yenile(); });
        });
    });
});
