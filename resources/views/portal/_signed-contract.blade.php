{{-- Owner 2026-10-26: after signing the client can open and download the signed contract, in its own card.
     Owner 2026-10-28: while the signed PDF is still being made, say so and reload by itself when it is ready. --}}
@if (! empty($vm['contract']['final_url']))
<section class="p-card">
  <h2>{{ $t('contract_title') }}</h2>
  <div class="p-actions">
    <a class="p-btn" href="{{ $vm['contract']['final_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('open_signed') }}</a>
    <a class="p-btn p-btn-quiet" href="{{ $vm['contract']['final_url'] }}&amp;download=1" download>{{ $t('download_pdf') }}</a>
  </div>
</section>
@elseif (! empty($vm['contract']['signed_name']) && ! ($vm['preview'] ?? false))
<section class="p-card" id="contract-preparing" role="status">
  <h2>{{ $t('contract_title') }}</h2>
  <p class="p-hint">{{ $t('contract_preparing') }}</p>
</section>
<script>
(function () {
  var tries = 0;
  var timer = setInterval(function () {
    if (++tries > 40) { clearInterval(timer); return; }
    fetch(location.href, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (html) { if (html.indexOf('artifact=final') !== -1) { clearInterval(timer); location.reload(); } })
      .catch(function () {});
  }, 3000);
})();
</script>
@endif
