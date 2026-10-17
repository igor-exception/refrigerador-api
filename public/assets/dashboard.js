'use strict';

const REFRESH_INTERVAL_MS = 10000;
const $ = id => document.getElementById(id);
const dateFormat = new Intl.DateTimeFormat('pt-BR', {timeZone:'America/Sao_Paulo',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});
const numberFormat = new Intl.NumberFormat('pt-BR', {minimumFractionDigits:2,maximumFractionDigits:2});
const cellNumberFormat = new Intl.NumberFormat('pt-BR', {minimumFractionDigits:1,maximumFractionDigits:1,useGrouping:false});
const date = value => value == null ? 'Não disponível' : dateFormat.format(new Date(value));
const temperature = value => value == null ? 'Não disponível' : `${numberFormat.format(value)} °C`;
const missing = 'Não há medições registradas neste intervalo. A causa pode envolver conectividade, alimentação, indisponibilidade do servidor ou interrupção da coleta.';
const unconfirmed = 'Intervalo ainda não confirmado pela API. Nenhuma temperatura ou ausência de coleta foi inferida.';
const partial = 'Dados parciais da última consulta; fechamento ainda não confirmado pela API.';
const names = {'10m':'Medição a cada 10 segundos','30m':'Medição a cada 30 segundos','1h':'Medição a cada 1 minuto','5h':'Medição a cada 5 minutos'};
const totals = {'10m':'10 minutos','30m':'30 minutos','1h':'1 hora','5h':'5 horas'};
// Escala experimental independente do sensor; futuras escalas podem ser definidas aqui.
const scale = [[10,[30,64,175]],[15,[58,130,206]],[20,[151,202,227]],[22.5,[230,233,217]],[25,[244,216,112]],[30,[237,140,69]],[35,[193,38,38]]];
function color(value) {
  const v = Math.min(35,Math.max(10,value));
  let index = 0;
  while (index < scale.length-2 && v > scale[index+1][0]) index++;
  const a = scale[index], b = scale[index+1], fraction = (v-a[0])/(b[0]-a[0]);
  return `rgb(${a[1].map((channel,i)=>Math.round(channel+(b[1][i]-channel)*fraction)).join(',')})`;
}
function textColor(background) {
  const channels = background.match(/\d+/g).map(channel=>{
    const s = Number(channel)/255;
    return s <= 0.04045 ? s/12.92 : ((s+0.055)/1.055)**2.4;
  });
  const luminance = channels[0]*0.2126 + channels[1]*0.7152 + channels[2]*0.0722;
  return luminance > 0.179 ? '#000' : '#fff';
}
function element(tag,text,className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}
function description(cell) {
  const period = `${date(cell.start)} → ${date(cell.end)} (fim exclusivo)`;
  if (cell.pending) return `${period}\n${unconfirmed}`;
  if (cell.provisional && !cell.count) return `${period}\n${partial}`;
  const note = cell.provisional ? `\n${partial}` : '';
  return cell.count ? `${period}${note}\nMédia ${temperature(cell.average)} · mín. ${temperature(cell.minimum)} · máx. ${temperature(cell.maximum)}\n${cell.count} medições · ${cell.received_at_count} por horário de recebimento` : `${period}\n${missing}`;
}
function showTooltip(button,cell) {
  const tooltip = $('tooltip');
  tooltip.textContent = description(cell); tooltip.hidden = false;
  const rect = button.getBoundingClientRect();
  tooltip.style.left = `${Math.max(8,Math.min(rect.left,window.innerWidth-tooltip.offsetWidth-8))}px`;
  tooltip.style.top = `${rect.top > tooltip.offsetHeight+16 ? rect.top-tooltip.offsetHeight-8 : rect.bottom+8}px`;
}
function showDetails(cell) {
  $('tooltip').hidden = true;
  const content = $('details-content'); content.replaceChildren();
  content.append(element('p',`${date(cell.start)} → ${date(cell.end)} · [início, fim)`));
  if (cell.pending) content.append(element('p',unconfirmed));
  else if (cell.provisional && !cell.count) content.append(element('p',partial));
  else if (!cell.count) content.append(element('p',missing));
  else {
    if (cell.provisional) content.append(element('p',partial));
    content.append(element('p',`Média ${temperature(cell.average)} · mínima ${temperature(cell.minimum)} · máxima ${temperature(cell.maximum)} · ${cell.count} medições.`));
    content.append(element('p','Metadados abaixo correspondem à última medição do intervalo, ordenada pelo horário usado no bucket; não representam a média.'));
    const reading = cell.last_reading, list = element('dl');
    const labels = {id:'ID no servidor',device_id:'Dispositivo',sensor_id:'Sensor',measurement_id:'ID da medição',temperature:'Temperatura individual',measured_at:'Medida em',received_at:'Recebida em',time_quality:'Qualidade temporal',wifi_rssi:'Wi-Fi (dBm)',uptime_ms:'Tempo ligado (ms)',observation:'Observação',time_source:'Horário usado no intervalo'};
    for (const [key,label] of Object.entries(labels)) {
      let value = reading[key] ?? 'Não disponível';
      if (key.endsWith('_at')) value = date(reading[key]);
      if (key === 'temperature') value = temperature(reading[key]);
      const row = element('div');row.append(element('dt',label),element('dd',String(value)));list.append(row);
    }
    content.append(list);
  }
  $('details').showModal();
}
let latest = null, successAt = null, serverNow = null, busy = false, snapshotSensor = null;
let currentIntervals = [], snapshotWindows = [], bestRoundTrip = Infinity;
function clockNow() {
  return serverNow === null ? null : serverNow + performance.now()-successAt;
}
function synchronizeClock(generatedAt,started,finished) {
  const generated = Date.parse(generatedAt), roundTrip = finished-started;
  if (!Number.isFinite(generated)) throw new Error('Referência temporal inválida');
  // Estimativa pelo meio do percurso; respostas mais lentas não atrasam o relógio.
  if (roundTrip <= bestRoundTrip) {
    serverNow = Math.max(clockNow() ?? -Infinity,generated+roundTrip/2);
    successAt = finished;bestRoundTrip = roundTrip;
  }
}
function updateCurrentIntervals() {
  const now = clockNow();
  if (now === null) return;
  if (currentIntervals.some(interval=>now >= interval.end)) { renderHeatmaps();return; }
}
function updateStatus() {
  updateAge();
  updateCurrentIntervals();
}
function updateAge() {
  if (!latest) return;
  // Avança a referência do servidor pelo tempo monotônico desde o fetch.
  const now = clockNow();
  const seconds = Math.floor((now-Date.parse(latest.received_at))/1000);
  $('age').textContent = seconds < 0 ? 'Horário futuro inconsistente' : seconds < 60 ? `${seconds} segundos` : seconds < 3600 ? `${Math.floor(seconds/60)} min ${seconds%60} s` : `${Math.floor(seconds/3600)} h ${Math.floor(seconds%3600/60)} min`;
  const stale = seconds > 60 || seconds < 0 || latest.temporal_warning;
  $('freshness').textContent = latest.temporal_warning || seconds < 0 ? 'Horários inconsistentes' : seconds > 60 ? 'Dados desatualizados' : 'Dados recentes';
  $('freshness').classList.toggle('stale',stale);
}
function render(data) {
  latest = data.latest;snapshotWindows = data.windows;
  $('temperature').textContent = latest ? numberFormat.format(latest.temperature) : '—';
  $('identity').textContent = latest ? `${latest.sensor_id} · ${latest.device_id ?? 'Dispositivo não disponível'}` : 'Nenhuma medição recebida para este sensor';
  $('measured').textContent = date(latest?.measured_at);$('received').textContent = date(latest?.received_at);
  $('quality').textContent = latest ? latest.time_quality ?? 'Não disponível (legado)' : 'Não disponível';
  if (!latest) { $('freshness').textContent = 'Sem registros';$('freshness').classList.remove('stale');$('age').textContent = 'Não disponível'; }
  updateAge();
  renderHeatmaps();
  $('temporal-warning').hidden = data.excluded_temporal_count === 0;
  $('temporal-warning').textContent = `${data.excluded_temporal_count} registro(s) com inconsistência temporal foram excluídos dos agregados recentes. Os dados originais foram preservados.`;
  for (const node of [$('heatmaps'),document.querySelector('.latest')]) node.setAttribute('aria-busy','false');
}
function renderHeatmaps() {
  const now = clockNow();
  const container = $('heatmaps');
  const focused = document.activeElement?.dataset.cell;
  $('tooltip').hidden = true;
  container.replaceChildren();
  currentIntervals = [];
  for (const window of snapshotWindows) {
    const duration = window.bucket_seconds*1000;
    const origin = Date.parse(window.cells[59].start);
    const currentStart = origin+Math.max(0,Math.floor((now-origin)/duration))*duration;
    const confirmed = new Map(window.cells.map(cell=>[Date.parse(cell.start),cell]));
    const cells = Array.from({length:60},(_,index)=>{
      const start = currentStart+(index-59)*duration;
      const cell = confirmed.get(start);
      if (cell) return start === origin && start < currentStart ? {...cell,provisional:true} : cell;
      return {start:new Date(start).toISOString(),end:new Date(start+duration).toISOString(),pending:true,count:null,average:null,minimum:null,maximum:null,last_reading:null};
    });
    const section = element('section',undefined,'heatmap'), head = element('div',undefined,'heatmap-head');
    head.append(element('h3',names[window.id]),element('p',`60 intervalos · total de ${totals[window.id]}`));
    const grid = element('div',undefined,'grid');grid.setAttribute('aria-label',names[window.id]);
    cells.forEach((cell,index)=>{
      const current = index === cells.length-1;
      const button = element('button',undefined,`cell${cell.pending || cell.provisional ? ' pending' : cell.count ? '' : ' empty'}${current ? ' current' : ''}`);
      button.type = 'button';button.dataset.cell = `${window.id}-${index}`;
      button.setAttribute('aria-label',`${current ? 'Intervalo temporal em andamento; não indica conexão com o ESP32 nem confirma nova medição. ' : ''}${description(cell)}`);
      if (cell.count && !current && !cell.provisional) {
        const background = color(cell.average);
        button.style.backgroundColor = background;
        button.style.color = textColor(background);
        const value = element('span',cellNumberFormat.format(cell.average),'cell-value');
        value.setAttribute('aria-hidden','true');button.append(value);
      }
      if (current) {
        const indicator = element('span',undefined,'cell-spinner');
        indicator.setAttribute('aria-hidden','true');
        // Mantém a fase da rotação ao reconstruir a grade, sem animar via JavaScript.
        indicator.style.animationDelay = `-${performance.now()/1000}s`;
        button.setAttribute('aria-current','time');
        button.append(indicator);
        currentIntervals.push({end:Date.parse(cell.end)});
      }
      button.addEventListener('mouseenter',()=>showTooltip(button,cell));
      button.addEventListener('focus',()=>showTooltip(button,cell));
      for (const event of ['mouseleave','blur']) button.addEventListener(event,()=>{$('tooltip').hidden=true;});
      button.addEventListener('click',()=>showDetails(cell));grid.append(button);
    });
    const range = element('div',undefined,'range');range.append(element('span',date(cells[0].start)),element('span',`${date(cells[59].end)} · fim do intervalo atual`));
    section.append(head,grid,range);container.append(section);
  }
  updateCurrentIntervals();
  if (focused) container.querySelector(`[data-cell="${focused}"]`)?.focus({preventScroll:true});
}
async function refresh() {
  if (busy) return;
  busy = true;$('refresh').disabled = true;$('sensor').disabled = true;$('connection').textContent = 'Consultando API…';
  const sensor = $('sensor').value;
  const started = performance.now();
  const controller = new AbortController(), timeout = setTimeout(()=>controller.abort(),10000);
  try {
    const response = await fetch(`/api/v1/dashboard/heatmap?sensor_id=${encodeURIComponent(sensor)}`,{signal:controller.signal,cache:'no-store'});
    if (!response.ok) throw new Error('Consulta indisponível');
    const data = await response.json();
    if (!Array.isArray(data.windows) || data.windows.length !== 4 || data.windows.some(w=>
      ![10,30,60,300].includes(w.bucket_seconds) || !Array.isArray(w.cells) || w.cells.length!==60 ||
      w.cells.some(cell=>!Number.isFinite(Date.parse(cell.start)) || Date.parse(cell.end)-Date.parse(cell.start)!==w.bucket_seconds*1000)
    )) throw new Error('Resposta incompatível');
    synchronizeClock(data.generated_at,started,performance.now());
    render(data);snapshotSensor = sensor;
    $('connection').textContent = 'API respondeu';$('updated').textContent = `Atualizado em ${date(data.generated_at)}`;$('error').hidden = true;
  } catch {
    updateCurrentIntervals();
    $('connection').textContent = 'Falha na atualização';$('error').hidden = false;
    $('error').textContent = snapshotSensor ? `Não foi possível consultar a API. Mantidos os últimos dados do sensor ${snapshotSensor}; isso não comprova novas lacunas de coleta.` : 'Não foi possível consultar a API. Os intervalos estão indisponíveis; nenhuma lacuna histórica foi inferida.';
    if (!snapshotSensor) $('heatmaps').replaceChildren(element('p','Consulta indisponível. Tente atualizar novamente.','placeholder'));
  } finally {
    clearTimeout(timeout);busy=false;$('refresh').disabled=false;$('sensor').disabled=false;
    $('heatmaps').setAttribute('aria-busy','false');document.querySelector('.latest').setAttribute('aria-busy','false');
  }
}
$('refresh').addEventListener('click',refresh);
$('sensor').addEventListener('change',refresh);
$('close-details').addEventListener('click',()=>$('details').close());
window.addEventListener('scroll',()=>{$('tooltip').hidden=true;},{passive:true});
// Um tick mantém a idade e as janelas temporais; a rotação do spinner é só CSS.
setInterval(refresh,REFRESH_INTERVAL_MS);setInterval(updateStatus,1000);refresh();
