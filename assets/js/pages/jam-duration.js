const el=document.querySelector('[data-jam-duration-map]');
if(el&&window.L){
    const map=L.map(el).setView([-15.8,-47.9],4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors',maxZoom:19}).addTo(map);
    const points=[];
    try{
        for(const item of JSON.parse(el.dataset.items||'[]')){
            if(!Number.isFinite(item.lat)||!Number.isFinite(item.lng))continue;
            const p=[item.lat,item.lng];points.push(p);
            const popup=document.createElement('div');
            const title=document.createElement('strong');title.textContent=item.street||'Via não informada';
            const text=document.createElement('p');text.textContent=`${item.status} · ${Number(item.speed).toFixed(1)} km/h · ${item.trend}`;
            popup.append(title,text);
            L.circleMarker(p,{radius:8,color:item.level>=4?'#ef4444':'#f59e0b'}).addTo(map).bindPopup(popup);
        }
        if(points.length)map.fitBounds(points,{padding:[25,25],maxZoom:14});
    }catch(error){console.warn('[Jam duration]',error);}
}
