import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['table', 'filter'];

    connect() {
        console.log('Alerts controller connected');
        this.initTable();
    }

    initTable() {
        if (!this.hasTableTarget) return;
        
        const table = this.tableTarget;
        const rows = table.querySelectorAll('tbody tr');
        
        if (this.hasFilterTarget) {
            this.filterTarget.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                });
            });
        }
    }
}
