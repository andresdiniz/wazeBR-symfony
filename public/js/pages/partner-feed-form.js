(function () {
  'use strict';

  const root = document.querySelector('[data-partner-feed-form]');
  if (!root || typeof L === 'undefined') return;

  const map = L.map('pf-map').setView(
    [parseFloat(root.dataset.defaultLat || '-14.2350'), parseFloat(root.dataset.defaultLng || '-51.9253')],
    parseInt(root.dataset.defaultZoom || '13', 10)
  );

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  const polylineInput = document.getElementById('pf-polyline-input');
  const coordsDisplay = document.getElementById('pf-coords-display');
  const modeBadge = document.getElementById('pf-map-mode-badge');
  const instructions = document.getElementById('pf-map-instructions');
  const clearButton = document.getElementById('pf-clear-map-btn');
  const typeSelect = document.getElementById('cifsType');
  const subtypeSelect = document.getElementById('cifsSubtype');
  const form = document.getElementById('event-form');

  let markers = [];
  let polyline = null;

  function renderMapState() {
    const points = markers.map(function (marker) {
      const position = marker.getLatLng();
      return [position.lat, position.lng];
    });

    if (polyline) {
      map.removeLayer(polyline);
      polyline = null;
    }

    if (points.length > 1) {
      polyline = L.polyline(points, { color: '#0ea5e9', weight: 5 }).addTo(map);
    }

    if (polylineInput) {
      polylineInput.value = points.length ? JSON.stringify(points) : '';
    }

    if (coordsDisplay) {
      coordsDisplay.textContent = points.length
        ? 'Lat: ' + points[0][0].toFixed(6) + '  Lng: ' + points[0][1].toFixed(6) +
          (points.length > 1 ? '  ·  ' + points.length + ' pontos' : '')
        : 'Lat: —  Lng: —';
    }

    if (modeBadge && instructions) {
      if (points.length > 1) {
        modeBadge.textContent = '📍 Polyline';
        instructions.textContent = 'Clique para adicionar mais pontos à polyline. Clique com o botão direito em um marcador para removê-lo.';
      } else {
        modeBadge.textContent = '📍 Ponto único';
        instructions.textContent = 'Clique no mapa para marcar o local exato do evento.';
      }
    }
  }

  function addMarker(latlng) {
    const marker = L.marker(latlng, { draggable: true }).addTo(map);
    marker.on('dragend', renderMapState);
    marker.on('contextmenu', function () {
      map.removeLayer(marker);
      markers = markers.filter(function (item) { return item !== marker; });
      renderMapState();
    });
    markers.push(marker);
    renderMapState();
  }

  try {
    const existing = root.dataset.existingPolyline ? JSON.parse(root.dataset.existingPolyline) : [];
    if (Array.isArray(existing) && existing.length) {
      existing.forEach(function (point) {
        if (Array.isArray(point) && point.length >= 2) {
          addMarker(L.latLng(Number(point[0]), Number(point[1])));
        }
      });

      if (markers.length) {
        map.fitBounds(L.latLngBounds(markers.map(function (marker) { return marker.getLatLng(); })).pad(0.3));
      }
    }
  } catch (error) {
    console.warn('Não foi possível restaurar a polyline existente.', error);
  }

  map.on('click', function (event) {
    addMarker(event.latlng);
  });

  if (clearButton) {
    clearButton.addEventListener('click', function () {
      markers.forEach(function (marker) { map.removeLayer(marker); });
      markers = [];
      renderMapState();
    });
  }

  function filterSubtypes() {
    if (!typeSelect || !subtypeSelect) return;

    const selectedType = typeSelect.value;
    let hasVisibleOption = false;

    Array.from(subtypeSelect.options).forEach(function (option) {
      if (!option.value) {
        option.hidden = false;
        return;
      }

      const matches = !selectedType || option.dataset.type === selectedType;
      option.hidden = !matches;
      if (matches) hasVisibleOption = true;
    });

    if (selectedType && subtypeSelect.selectedOptions.length && subtypeSelect.selectedOptions[0].hidden) {
      subtypeSelect.value = '';
    }

    subtypeSelect.disabled = selectedType && !hasVisibleOption;
  }

  if (typeSelect) {
    typeSelect.addEventListener('change', filterSubtypes);
    filterSubtypes();
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
      }
    });
  }

  renderMapState();
  window.setTimeout(function () { map.invalidateSize(); }, 250);
})();