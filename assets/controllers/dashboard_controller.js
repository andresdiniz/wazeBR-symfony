import { Controller } from '@hotwired/stimulus';
import 'leaflet';
import 'chart.js/auto';

export default class extends Controller {
    static targets = ['map', 'chart'];

    connect() {
        console.log('Dashboard controller connected');
        this.initMap();
        this.initChart();
    }

    initMap() {
        if (!this.hasMapTarget) return;
        
        const map = L.map(this.mapTarget).setView([-21.1669, -43.7817], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '\u00a9 OpenStreetMap contributors'
        }).addTo(map);
        
        this.map = map;
    }

    initChart() {
        if (!this.hasChartTarget) return;
        
        const ctx = this.chartTarget;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['00:00', '06:00', '12:00', '18:00'],
                datasets: [{
                    label: 'Tr\u00e1fego',
                    data: [12, 19, 3, 5],
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: true }
                }
            }
        });
    }
}
