// Reserva rápida: Google address suggestions (Places API New) under "Dirección de la fiesta".
// Picking one writes the formatted address and ZIP into the Livewire form and presses "Calcular" (travel fee).
// Everything stays editable; without Google (no key, blocked, offline) the field is a plain text box.
(function () {
  var me = document.currentScript, key = me && me.dataset.key;
  if (!key || window.waooPlaces) return;
  window.waooPlaces = true;
  var lib = null, token = null, timer = null, box = null, items = [], active = -1, generation = 0;

  function load() {
    if (lib) return lib;
    lib = new Promise(function (resolve, reject) {
      window.waooPlacesReady = function () { google.maps.importLibrary('places').then(resolve, reject); };
      var s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&loading=async&language=es&region=US&callback=waooPlacesReady';
      s.async = true; s.onerror = reject; document.head.appendChild(s);
    });
    return lib;
  }
  function field(name) { return document.querySelector('[data-places="' + name + '"]'); }
  function close() { if (box) box.remove(); box = null; items = []; active = -1; }
  function cancel() { generation++; clearTimeout(timer); close(); }
  function component(el) { var root = el.closest('[wire\\:id]'); return root && window.Livewire ? Livewire.find(root.getAttribute('wire:id')) : null; }

  function show(input, suggestions) {
    close();
    if (!suggestions.length) return;
    box = document.createElement('ul'); box.className = 'wplaces'; box.setAttribute('role', 'listbox');
    suggestions.forEach(function (s, i) {
      var p = s.placePrediction, li = document.createElement('li');
      li.setAttribute('role', 'option'); li.innerHTML = '<b></b><small></small>';
      li.firstChild.textContent = p.mainText ? p.mainText.text : p.text.text;
      li.lastChild.textContent = p.secondaryText ? p.secondaryText.text : '';
      li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(input, p); });
      box.appendChild(li); items.push(li);
    });
    // Filament's input wrapper clips overflow, so the list lives on <body>, placed under the input.
    var r = input.getBoundingClientRect();
    box.style.position = 'absolute'; box.style.left = (r.left + window.scrollX) + 'px'; box.style.top = (r.bottom + window.scrollY) + 'px';
    box.style.width = r.width + 'px'; box.style.right = 'auto';
    document.body.appendChild(box);
  }

  function pick(input, prediction) {
    var attempt = ++generation;
    clearTimeout(timer);
    close();
    var place = prediction.toPlace();
    place.fetchFields({fields: ['formattedAddress', 'addressComponents']}).then(function () {
      if (attempt !== generation || !input.isConnected) return;
      var zip = (place.addressComponents || []).filter(function (c) { return c.types.indexOf('postal_code') >= 0; })[0];
      var address = (place.formattedAddress || '').replace(/, (USA|EE\. UU\.|Estados Unidos)$/, '');
      token = null;
      // Address and ZIP always change together: a place without a ZIP clears the old one (never reuse another ZIP's fee).
      var wire = component(input), zipEl = field('zip'), zipText = zip ? zip.longText : '';
      input.value = address; if (zipEl) zipEl.value = zipText;
      if (!wire) return;
      // Setting them on the server also forgets any earlier quote (TravelFeeFields::invalidate).
      wire.$set('data.address', address).then(function () {
        if (attempt !== generation) return;
        return wire.$set('data.postal_code', zipText);
      }).then(function () {
        if (attempt !== generation) return;
        if (!zipText) { if (zipEl) zipEl.focus(); return; } // no ZIP from Google: the owner types it, then presses Calcular
        var quote = document.querySelector('[data-places="quote"]');
        if (quote) quote.click();
      }).catch(function () { close(); });
    }).catch(function () { close(); });
  }

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input.matches || !input.matches('[data-places="address"]')) return;
    var attempt = ++generation;
    clearTimeout(timer);
    close();
    var text = input.value.trim();
    if (text.length < 4) { close(); return; }
    timer = setTimeout(function () {
      load().then(function (places) {
        token = token || new places.AutocompleteSessionToken();
        return places.AutocompleteSuggestion.fetchAutocompleteSuggestions({input: text, sessionToken: token, includedRegionCodes: ['us'],
          locationBias: {center: {lat: 29.76, lng: -95.37}, radius: 50000}});
      }).then(function (r) {
        if (attempt === generation && input.isConnected && document.activeElement === input && input.value.trim() === text) show(input, r.suggestions || []);
      }).catch(function () { if (attempt === generation) close(); });
    }, 250);
  });
  document.addEventListener('keydown', function (e) {
    if (e.target.matches('[data-places="address"]') && e.key === 'Escape') { cancel(); return; }
    if (!box || !e.target.matches('[data-places="address"]')) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach(function (li, i) { li.classList.toggle('on', i === active); });
    } else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items[active].dispatchEvent(new MouseEvent('mousedown')); }
    else if (e.key === 'Escape') close();
  });
  document.addEventListener('focusout', function (e) { if (e.target.matches && e.target.matches('[data-places="address"]')) cancel(); });
  // Don't leave a body-level overlay over an unrelated field after scrolling or resizing.
  window.addEventListener('resize', cancel);
  document.addEventListener('scroll', function (e) { if (!box || !box.contains(e.target)) cancel(); }, true);
})();
