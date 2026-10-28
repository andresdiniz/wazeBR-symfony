import { rsT, rsLabel, RS_LOCALE } from './route-show-i18n.js';

const STEP = 15 * 60 * 1000;
let chart = null;
const number = value => value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? null : Number(value);
const seconds = value => number(value) === null ? '—' : `${Math.round(Number(value))} s`;
const signed = value => number(value) === null ? '—' : `${Number(value) >= 0 ? '+' : '−'}${Math.round(Math.abs(Number(value)))} s`;
function node(tag, text, cls) { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; }
export async function init(root = document) {
    const page = root.matches?.('[data-route-show]') ? root : root.querySelector('[data-route-show]');
    if (!page || page.dataset.forecastInitialized === '1' || !page.dataset.routeId) return;
    page.dataset.forecastInitialized = '1';
    let panel = page.querySelector('#route-forecast');
    if (!panel) { panel = node('section', undefined, 'rs-panel'); panel.id = 'route-forecast'; const anchor = page.querySelector('.rs-kpis'); if (!anchor) { delete page.dataset.forecastInitialized; return; } anchor.after(panel); }
    panel.replaceChildren(node('h2', rsT("Previsao ao lado do real")));
    const status = node('p', rsT("Carregando previsoes…"), 'rs-panel__hint'); status.setAttribute('role','status'); panel.append(status);
    const abort = new AbortController(); const timer = setTimeout(() => abort.abort(), 20000);
    try {
        const response = await fetch(`/routes/${encodeURIComponent(page.dataset.routeId)}/forecast.json`, {credentials:'same-origin',headers:{Accept:'application/json'},signal:abort.signal});
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json(); if (!data.ok || !Array.isArray(data.items)) throw new Error(rsT("Resposta invalida"));
        const tz = data.timezone || 'America/Sao_Paulo';
        const date = value => { const t = Date.parse(value); return Number.isFinite(t) ? new Date(t).toLocaleString(RS_LOCALE,{timeZone:tz,day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '—'; };
        const a = data.accuracy;
        if (a) {
            const chips = node('div',undefined,'rs-forecast__chips');
            for (const [label,value] of [[rsT("Erro absoluto medio"),seconds(a.mae)],['RMSE',seconds(a.rmse)],[rsT("Vies: previsto − medido"),signed(a.bias)],[rsT("Dentro da tolerancia"),a.hitRate === null ? '—' : `${Math.round(a.hitRate)}%`],[rsT("Janelas avaliadas"),String(a.samples)]]) { const chip = node('div',undefined,'rs-forecast__chip'); chip.append(node('span',label),node('strong',value)); chips.append(chip); }
            panel.append(chips,node('p',rsT("Uma emissao por janela, com antecedencia mais proxima de 60 min. MAE nao e intervalo de previsao."),'rs-panel__hint'));
            for (const band of a.bands || []) panel.append(node('p',`${rsT("Horizonte")} ${band.label}: MAE ${seconds(band.mae)} · ${band.samples} ${rsT("janelas")}`,'rs-panel__hint'));
        } else panel.append(node('p',rsT("Sem auditoria suficiente para medir precisao."),'rs-panel__hint'));
        const duration = data.congestionDuration;
        if (duration) {
            let text = duration.stale ? rsT("Leitura atual ausente ou antiga: nao e possivel confirmar lentidao agora.") : duration.active ? `${rsT("Lentidao observada ha pelo menos")} ${duration.elapsedMinutes ?? '—'} min.` : rsT("Sem lentidao confirmada na ultima leitura.");
            if (duration.active && duration.expectedRemainingMinutes !== null) text += ` ${rsT("Restante tipico estimado")}: ${duration.expectedRemainingMinutes} min; ${rsT("nao e garantia de termino.")}`;
            panel.append(node('p',text,'rs-panel__hint'));
        }
        const rows = new Map(); const get = iso => { const ms = Date.parse(iso); if (!Number.isFinite(ms)) return null; const key = Math.floor(ms/STEP)*STEP; if (!rows.has(key)) rows.set(key,{key,actual:null,historic:null,past:null,future:null,samples:0,issued:null,horizon:null}); return rows.get(key); };
        for (const r of data.actual || []) { const p = get(r.at); if (p && Number(r.samples)>0) { p.actual=number(r.avgTime); p.historic=number(r.avgHistoricTime); p.samples=r.samples; } }
        for (const r of data.pastForecasts || []) { const p=get(r.at); if(p) {p.past=number(r.predicted);p.issued=r.issuedAt;p.horizon=r.horizonMinutes;} }
        for (const r of data.items) { const p=get(r.forecastAt); if(p && r.hasData && !r.stale) {p.future=number(r.avgTime);p.historic=number(r.avgHistoricTime);p.issued=r.updatedAt;} }
        const now = Date.parse(data.generatedAt) || Date.now(); const keys = [...rows.keys()];
        const begin=Math.min(Math.floor((now-6*3600000)/STEP)*STEP,...keys); const end=Math.max(Math.ceil((now+6*3600000)/STEP)*STEP,...keys);
        for(let key=begin;key<=end;key+=STEP) if(!rows.has(key)) rows.set(key,{key,actual:null,historic:null,past:null,future:null,samples:0});
        const sorted=[...rows.values()].sort((x,y)=>x.key-y.key);
        const wrap=node('div'); wrap.style.position='relative';wrap.style.height='340px'; const canvas=node('canvas');canvas.setAttribute('role','img');canvas.setAttribute('aria-label',rsT("Tempo medido, previsoes e lacunas de coleta"));wrap.append(canvas);panel.append(wrap);
        if(typeof window.Chart !== 'function') throw new Error(rsT("Chart.js indisponivel"));
        if(chart) chart.destroy();
        const definitions=[['actual',rsT("Real (medido)"),'#2563eb',[]],['past',rsT("Previsao passada: horizonte exibido no tooltip"),'#f59e0b',[6,4]],['future',rsT("Previsao futura"),'#7c3aed',[]],['historic',rsT("Referencia historica"),'#94a3b8',[2,4]]];
        chart=new window.Chart(canvas,{type:'line',data:{labels:sorted.map(r=>date(r.key)),datasets:definitions.map(([field,label,color,dash])=>({label,data:sorted.map(r=>r[field]),borderColor:color,backgroundColor:color,borderDash:dash,spanGaps:false,tension:0,fill:false,pointRadius:2,borderWidth:2}))},plugins:[{id:'forecastNow',afterDraw(c){const pos=(now-begin)/STEP;const scale=c.scales.x;if(!scale || sorted.length<2)return;const x=scale.getPixelForValue(0)+(scale.getPixelForValue(1)-scale.getPixelForValue(0))*pos;if(x<c.chartArea.left||x>c.chartArea.right)return;const ctx=c.ctx;ctx.save();ctx.strokeStyle='#94a3b8';ctx.setLineDash([4,4]);ctx.beginPath();ctx.moveTo(x,c.chartArea.top);ctx.lineTo(x,c.chartArea.bottom);ctx.stroke();ctx.fillStyle='#94a3b8';ctx.fillText(rsT("agora"),x+4,c.chartArea.top+12);ctx.restore();}}],options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:'#94a3b8'}},tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${seconds(c.parsed.y)}`,afterBody:contexts=>{const r=sorted[contexts[0].dataIndex];return [`${rsT("Amostras observadas")}: ${r.samples || 0}`,r.horizon ? `${rsT("Antecedencia passada")}: ${Math.round(r.horizon)} min` : '',r.issued ? `${rsT("Emissao")}: ${date(r.issued)}` : ''].filter(Boolean);}}}},scales:{x:{ticks:{color:'#94a3b8',maxTicksLimit:12,maxRotation:0},grid:{display:false}},y:{ticks:{color:'#94a3b8',callback:v=>`${v} s`},grid:{color:'rgba(148,163,184,.15)'}}}}});
        status.textContent=rsT('ui.forecast_available', {'%available%': data.availableSlots, '%total%': data.slotsAhead});
        const errors=node('section',undefined,'rs-forecast__errors'); errors.append(node('h3',rsT("Diferencas da previsao e contexto")));const list=node('div',undefined,'rs-forecast__errors-list');
        for(const e of data.forecastErrors || []) {const item=node('article',undefined,'rs-forecast__error'); item.append(node('strong',`${date(e.targetStartAt)} · ${rsT("antecedencia")} ${Math.round(e.horizonMinutes)} min`),node('p',`${rsT("Previsto")} ${seconds(e.predictedTime)} · ${rsT("medido")} ${seconds(e.actualTime)} · ${rsT("diferenca")} ${signed(e.errorSeconds)}`),node('p',e.explanation || rsT("Contexto indisponivel.")));list.append(item);}
        if(!list.children.length)list.append(node('p',rsT("Nenhum desvio fora da tolerancia entre as janelas selecionadas; isso nao comprova cobertura completa."))); errors.append(list);panel.append(errors);
        const signals=node('section');signals.append(node('h3',rsT("Irregularidades TVT recentes na rota")));for(const s of data.nearbySignals || []) signals.append(node('p',[s.type,s.subtype,s.street,s.city].filter(Boolean).join(' · ')));signals.append(node('small',rsT("Contexto associado, nao causa comprovada. Esta lista nao representa todos os alertas Waze proximos.")));panel.append(signals);
    } catch(error) {console.error('[route-forecast]',error);status.textContent=rsT("Nao foi possivel concluir a exibicao da previsao. Tente recarregar.");delete page.dataset.forecastInitialized;} finally {clearTimeout(timer);}
}
