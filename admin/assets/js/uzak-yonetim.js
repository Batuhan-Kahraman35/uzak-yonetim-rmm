/**
 * Uzak Yönetim sayfaları ortak JS: CSRF başlığı, AJAX hata bildirimi, aranabilir select
 * (showToast: custom.js)
 */

// CSRF başlığı yalnız kendi sunucumuza giden isteklere eklenir.
// Dış adreslere (CDN) özel başlık gönderilirse tarayıcı CORS preflight yapar ve istek engellenir.
$.ajaxPrefilter(function (secenekler, orijinal, xhr) {
    if (!secenekler.crossDomain) {
        xhr.setRequestHeader('X-CSRF-Token', $('meta[name="csrf-token"]').attr('content'));
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    }
});

$(document).ajaxError(function (olay, xhr) {
    const cevap = xhr.responseJSON || {};
    if (xhr.status === 401 && cevap.oturum_bitti) {
        window.location.reload();
        return;
    }
    showToast(cevap.mesaj || cevap.message || 'İşlem sırasında bir hata oluştu.', 'error');
});

const uzakKacis = (d) => $('<div>').text(d ?? '').html();
const uzakBos = '<span class="text-muted">-</span>';

$(function () {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%', language: { noResults: () => 'Sonuç bulunamadı' } });
    $('.modal').each(function () {
        $(this).find('.select2-modal').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $(this) });
    });
});
