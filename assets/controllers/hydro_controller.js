import { Controller } from '@hotwired/stimulus';
import 'leaflet';

export default class extends Controller {
    static targets = ['map'];

    connect() {
        console.log('Hydro controller connected');
        this.initHydroMap();
    }

    initHydroMap() {
        if (!this.hasMapTarget) return;
        
        const map = L.map(this.mapTarget).setView([-21.1669, -43.7817], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '\u00a9 OpenStreetMap contributors'
        }).addTo(map);
        
        this.map = map;
    }
}
