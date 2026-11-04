const config = document.querySelector('[data-tv-layout-config]');
const wallboard = document.querySelector('[data-tv-wallboard]');
if (config && wallboard && window.GridStack && !wallboard.dataset.layoutReady) init();
function init() {
    wallboard.dataset.layoutReady = '1';
    const main = wallboard.querySelector('main.tv-body');
    if (!main) return;
    const key = `wazebr:tv-layout:v1:${config.dataset.user}:${config.dataset.partner}`;
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(key)); } catch {}
    if (!saved || saved.version !== 1 || !Array.isArray(saved.layout) || !Array.isArray(saved.hidden)) saved = { version:1, layout:[], hidden:[] };
    const toolbar = document.createElement('div');
    toolbar.className = 'tv-layout-toolbar';
    toolbar.innerHTML = '<button type="button" data-edit aria-pressed="false">Editar layout</button><label>Reabrir card <select data-restore><option value="">Selecione…</option></select></label><button type="button" data-reset>Restaurar padrão</button><span role="status">Preferências neste navegador</span>';
    main.before(toolbar);
    const parking = document.createElement('div');
    parking.dataset.tvLayoutParking = '';
    parking.hidden = true;
    const host = document.createElement('div');
    host.className = 'grid-stack tv-widget-grid';
    main.append(host, parking);
    const grid = GridStack.init({ column:12, cellHeight:70, margin:5, float:false, handle:'.tv-widget-handle', disableDrag:true, disableResize:true, resizable:{handles:'se,sw'} }, host);
    const entries = new Map();
    let applying = true;
    let editing = false;
    let timer;
    const definitions = [
        ['priority','.tv-card--priority','Prioridade agora',0,0,3,3],
        ['map','.tv-map-wrap','Mapa operacional',3,0,6,6],
        ['rain','.tv-card--rain','Risco climático',9,0,3,3],
        ['alerts','.tv-card--alerts','Alertas ativos',0,3,3,6],
        ['routes','.tv-card--routes','Rotas mais lentas',9,3,3,4],
        ['cameras','.tv-cams','Câmeras',3,6,6,3],
        ['jams','.tv-card--jams','Congestionamentos',0,9,3,4],
        ['feed','.tv-feed','Eventos prioritários',3,9,6,4],
        ['hydro','.tv-card--hydro','Rios e drenagem',9,7,3,4],
        ['weather','.tv-card--weather','Clima agora',9,11,3,3],
        ['streets','.tv-card--top-streets','Top vias',0,13,3,3],
        ['forecast','[data-tv-forecast-card]','Previsão de tráfego',3,13,6,3]
    ];
    const geometry = node => ({id:String(node.id),x:node.x,y:node.y,w:node.w,h:node.h});
    const valid = item => item && typeof item.id === 'string' && ['x','y','w','h'].every(k=>Number.isInteger(item[k])) && item.x>=0 && item.y>=0 && item.y<=10000 && item.w>=1 && item.w<=12 && item.x+item.w<=12 && item.h>=2 && item.h<=100;
    const positions = new Map(saved.layout.filter(valid).map(item=>[item.id,item]));
    function message(text){ toolbar.querySelector('[role=status]').textContent=text; }
    function menu(){
        const select=toolbar.querySelector('[data-restore]');
        select.replaceChildren(new Option('Selecione…',''));
        entries.forEach((entry,id)=>{if(entry.closed)select.add(new Option(entry.title,id));});
        select.disabled=select.options.length===1;
    }
    function persist(){
        if(applying)return;
        clearTimeout(timer);
        timer=setTimeout(()=>{
            grid.save(false).forEach(node=>positions.set(String(node.id),geometry(node)));
            try{localStorage.setItem(key,JSON.stringify({version:1,layout:[...positions.values()],hidden:[...entries].filter(([,e])=>e.closed).map(([id])=>id)}));message('Layout salvo neste navegador.');}
            catch{message('Não foi possível salvar; o layout vale apenas nesta sessão.');}
        },200);
    }
    function hide(id){
        const entry=entries.get(id);if(!entry || entry.closed)return;
        if(entry.wrapper.gridstackNode)positions.set(id,geometry(entry.wrapper.gridstackNode));
        entry.closed=true;grid.removeWidget(entry.wrapper,false);parking.append(entry.wrapper);menu();persist();
    }
    function show(id){
        const entry=entries.get(id);if(!entry || !entry.closed)return;
        entry.closed=false;host.append(entry.wrapper);grid.makeWidget(entry.wrapper,positions.get(id)||entry.defaults);menu();persist();
    }
    function register(def){
        const [id,selector,title,x,y,w,h]=def;
        if(entries.has(id))return;
        const source=wallboard.querySelector(selector);if(!source)return;
        const defaults={id,x,y,w,h,minW:1,minH:2};
        const wrapper=document.createElement('div');wrapper.className='grid-stack-item';wrapper.setAttribute('gs-id',id);
        const content=document.createElement('div');content.className='grid-stack-item-content';
        const bar=document.createElement('div');bar.className='tv-widget-bar';
        const handle=document.createElement('span');handle.className='tv-widget-handle';handle.textContent=`⠿ ${title}`;
        const close=document.createElement('button');close.type='button';close.className='tv-widget-close';close.textContent='×';close.setAttribute('aria-label',`Ocultar ${title}`);
        close.addEventListener('click',()=>{if(editing)hide(id);});bar.append(handle,close);
        const body=document.createElement('div');body.className='tv-widget-content';
        const empty=document.createElement('div');empty.className='tv-widget-empty';empty.textContent='Sem dados disponíveis para este card.';
        body.append(source,empty);content.append(bar,body);wrapper.append(content);host.append(wrapper);
        entries.set(id,{wrapper,source,title,defaults,closed:false});
        grid.makeWidget(wrapper,{...defaults,...positions.get(id)});
        const sync=()=>{empty.hidden=!source.hidden;};sync();
        new MutationObserver(sync).observe(source,{attributes:true,attributeFilter:['hidden']});
        if(saved.hidden.includes(id))hide(id);
    }
    definitions.forEach(register);
    main.querySelectorAll(':scope > .tv-col').forEach(column=>{column.hidden=true;column.style.display='none';});
    wallboard.classList.add('tv-custom-layout');
    applying=false;menu();
    new MutationObserver(()=>{definitions.forEach(register);}).observe(wallboard,{childList:true,subtree:true});
    grid.on('change',persist);
    toolbar.querySelector('[data-edit]').addEventListener('click',event=>{
        editing=!editing;wallboard.classList.toggle('tv-layout-editing',editing);grid.enableMove(editing);grid.enableResize(editing);event.currentTarget.textContent=editing?'Concluir edição':'Editar layout';event.currentTarget.setAttribute('aria-pressed',String(editing));if(!editing)persist();
    });
    toolbar.querySelector('[data-restore]').addEventListener('change',event=>{if(event.target.value)show(event.target.value);});
    toolbar.querySelector('[data-reset]').addEventListener('click',()=>{
        if(!confirm('Restaurar tamanhos, posições e todos os cards?'))return;
        applying=true;clearTimeout(timer);saved.hidden=[];positions.clear();entries.forEach((entry,id)=>{positions.set(id,entry.defaults);show(id);});grid.load([...entries.values()].map(entry=>entry.defaults),false);applying=false;menu();persist();
    });
    const map=wallboard.querySelector('[data-tv-map]');
    if(map && window.ResizeObserver){
        let raf;new ResizeObserver(()=>{cancelAnimationFrame(raf);raf=requestAnimationFrame(()=>window.dispatchEvent(new Event('resize')));}).observe(map);
    }
    window.dispatchEvent(new Event('resize'));
}
