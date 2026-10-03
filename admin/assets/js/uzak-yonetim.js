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

// Etiket rozeti: arka plan etiket rengi, yazı rengi parlaklığa göre
function uzakEtiketYazi(renk) {
    const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(renk || '');
    if (!m) return '#fff';
    const p = (parseInt(m[1], 16) * 299 + parseInt(m[2], 16) * 587 + parseInt(m[3], 16) * 114) / 1000;
    return p > 150 ? '#000' : '#fff';
}
function uzakEtiketRozet(e) {
    const renk = /^#[0-9a-f]{6}$/i.test(e.renk || '') ? e.renk : '#6c757d';
    return '<span class="badge etiket-rozet" style="background:' + renk + ';color:' + uzakEtiketYazi(renk) + '">' + uzakKacis(e.ad) + '</span>';
}

// Etiket seçimi (çoklu, aranabilir, renkli): <option data-renk="#hex">
function uzakEtiketSelect($el, $parent) {
    const sablon = o => {
        if (!o.id) return o.text;
        const renk = $(o.element).data('renk') || '#6c757d';
        return $('<span>').append($('<span class="etiket-nokta">').css('background', renk), document.createTextNode(o.text));
    };
    $el.select2({
        theme: 'bootstrap-5', width: '100%', dropdownParent: $parent || $(document.body),
        templateResult: sablon, templateSelection: sablon,
        language: { noResults: () => 'Sonuç bulunamadı' }
    });
}
