/**
 * Uzak Yönetim - Ayarlar: değer düzenleme (şifreli alanda mevcut değer gösterilmez)
 */
$(function () {
    const adres = '/admin/uzak-ayarlar';
    const $modal = $('#ayarModal');
    if (!$modal.length) return;

    $('.ayar-duzenle').on('click', function () {
        const d = $(this).data();
        const sifreli = d.sifreli == 1;
        $('#ayarAnahtar').val(d.anahtar);
        $('#ayarTemizle').val('');
        $('#ayarModalBaslik').html('<i class="bi bi-pencil me-1"></i>' + d.anahtar);
        $('#ayarAciklama').text(d.aciklama || '');
        $('#ayarSifreliBilgi').toggle(sifreli);
        $('#ayarDurum').html(d.dolu == 1 ? '<span class="badge text-bg-success">Tanımlı</span>' : '<span class="badge text-bg-secondary">Tanımsız</span>');
        $('#ayarTemizleBtn').toggleClass('d-none', !(sifreli && d.dolu == 1));

        const $deger = $('#ayarDeger');
        $deger.val(sifreli ? '' : (d.deger ?? ''));
        $deger.attr('type', sifreli ? 'password' : 'text');
        $('#ayarDegerLabel').text(sifreli ? 'Yeni değer' : 'Değer');
        $('#ayarIpucu').text(sifreli ? 'Şifreli saklanır. Boş bırakıp kaydederseniz mevcut değer korunur.' : 'Boş bırakırsanız değer silinir.');
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    });

    function kaydet(temizle) {
        $('#ayarTemizle').val(temizle ? '1' : '');
        const $btn = $('#ayarKaydet, #ayarTemizleBtn').prop('disabled', true);
        $.post(adres, $('#ayarForm').serialize() + '&action=kaydet', function (c) {
            showToast(c.mesaj, c.basarili ? 'success' : (c.mesaj === 'Değişiklik yapılmadı.' ? 'info' : 'error'));
            if (c.basarili) { bootstrap.Modal.getInstance($modal[0]).hide(); setTimeout(() => window.location.reload(), 600); }
        }).always(() => $btn.prop('disabled', false));
    }

    $('#ayarKaydet').on('click', () => kaydet(false));
    $('#ayarTemizleBtn').on('click', function () {
        Swal.fire({
            title: 'Değer temizlensin mi?', text: $('#ayarAnahtar').val(),
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Temizle', cancelButtonText: 'Vazgeç', confirmButtonColor: '#dc3545'
        }).then(s => { if (s.isConfirmed) kaydet(true); });
    });
});
