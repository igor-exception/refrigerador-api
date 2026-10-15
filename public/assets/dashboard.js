'use strict';

const REFRESH_INTERVAL_MS = 10000;
const $ = id => document.getElementById(id);
const dateFormat = new Intl.DateTimeFormat('pt-BR', {timeZone:'America/Sao_Paulo',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});
const numberFormat = new Intl.NumberFormat('pt-BR', {minimumFractionDigits:2,maximumFractionDigits:2});
const date = value => value == null ? 'Não disponível' : dateFormat.format(new Date(value));
const temperature = value => value == null ? 'Não disponível' : `${numberFormat.format(value)} °C`;
const missing = 'Não há medições registradas neste intervalo. A causa pode envolver conectividade, alimentação, indisponibilidade do servidor ou interrupção da coleta.';
const names = {'10m':'Últimos 10 minutos','30m':'Últimos 30 minutos','1h':'Última 1 hora','5h':'Últimas 5 horas'};
// Escala experimental independente do sensor; futuras escalas podem ser definidas aqui.
const scale = [[18,[35,85,174]],[19,[72,139,208]],[20,[161,205,229]],[21,[230,233,217]],[22,[244,216,112]],[23,[237,148,76]],[24,[206,73,63]]];
function color(value) {
  const v = Math.min(24,Math.max(18,value)), index = Math.min(5,Math.floor(v-18));
  const a = scale[index], b = scale[index+1], fraction = v-a[0];
  return `rgb(${a[1].map((channel,i)=>Math.round(channel+(b[1][i]-channel)*fraction)).join(',')})`;
}
function element(tag,text,className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}
function description(cell) {
  const period = `${date(cell.start)} → ${date(cell.end)} (fim exclusivo)`;
  return cell.count ? `${period}\nMédia ${temperature(cell.average)} · mín. ${temperature(cell.minimum)} · máx. ${temperature(cell.maximum)}\n${cell.count} medições · ${cell.received_at_count} por horário de recebimento` : `${period}\n${missing}`;
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
  if (!cell.count) content.append(element('p',missing));
  else {
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
function updateAge() {
  if (!latest) return;
  // Avança a referência do servidor pelo tempo monotônico desde o fetch.
  const now = serverNow + performance.now()-successAt;
  const seconds = Math.floor((now-Date.parse(latest.received_at))/1000);
  $('age').textContent = seconds < 0 ? 'Horário futuro inconsistente' : seconds < 60 ? `${seconds} segundos` : seconds < 3600 ? `${Math.floor(seconds/60)} min ${seconds%60} s` : `${Math.floor(seconds/3600)} h ${Math.floor(seconds%3600/60)} min`;
  const stale = seconds > 60 || seconds < 0 || latest.temporal_warning;
  $('freshness').textContent = latest.temporal_warning || seconds < 0 ? 'Horários inconsistentes' : seconds > 60 ? 'Dados desatualizados' : 'Dados recentes';
  $('freshness').classList.toggle('stale',stale);
}
function render(data) {
  latest = data.latest; serverNow = Date.parse(data.generated_at);successAt = performance.now();
  $('temperature').textContent = latest ? numberFormat.format(latest.temperature) : '—';
  $('identity').textContent = latest ? `${latest.sensor_id} · ${latest.device_id ?? 'Dispositivo não disponível'}` : 'Nenhuma medição recebida para este sensor';
  $('measured').textContent = date(latest?.measured_at);$('received').textContent = date(latest?.received_at);
  $('quality').textContent = latest ? latest.time_quality ?? 'Não disponível (legado)' : 'Não disponível';
  if (!latest) { $('freshness').textContent = 'Sem registros';$('freshness').classList.remove('stale');$('age').textContent = 'Não disponível'; }
  updateAge();
  const container = $('heatmaps');
  const focused = document.activeElement?.dataset.cell;
  $('tooltip').hidden = true;
  container.replaceChildren();
  for (const window of data.windows) {
    const section = element('section',undefined,'heatmap'), head = element('div',undefined,'heatmap-head');
    const step = window.bucket_seconds < 60 ? `${window.bucket_seconds} segundos` : `${window.bucket_seconds/60} min`;
    head.append(element('h3',names[window.id]),element('p',`60 intervalos · ${step} por célula`));
    const grid = element('div',undefined,'grid');grid.setAttribute('aria-label',names[window.id]);
    window.cells.forEach((cell,index)=>{
      const current = index === window.cells.length-1;
      const button = element('button',undefined,`cell${cell.count ? '' : ' empty'}${current ? ' current' : ''}`);
      button.type = 'button';button.dataset.cell = `${window.id}-${index}`;
      button.setAttribute('aria-label',`${current ? 'Intervalo mais recente, em coleta. ' : ''}${description(cell)}`);
      if (current) button.setAttribute('aria-current','time');
      if (cell.count) button.style.backgroundColor = color(cell.average);
      button.addEventListener('mouseenter',()=>showTooltip(button,cell));
      button.addEventListener('focus',()=>showTooltip(button,cell));
      for (const event of ['mouseleave','blur']) button.addEventListener(event,()=>{$('tooltip').hidden=true;});
      button.addEventListener('click',()=>showDetails(cell));grid.append(button);
    });
    const range = element('div',undefined,'range');range.append(element('span',date(window.cells[0].start)),element('span',`${date(window.cells[59].end)} · fim da célula em coleta`));
    section.append(head,grid,range);container.append(section);
  }
  if (focused) container.querySelector(`[data-cell="${focused}"]`)?.focus({preventScroll:true});
  $('temporal-warning').hidden = data.excluded_temporal_count === 0;
  $('temporal-warning').textContent = `${data.excluded_temporal_count} registro(s) com inconsistência temporal foram excluídos dos agregados recentes. Os dados originais foram preservados.`;
  for (const node of [container,document.querySelector('.latest')]) node.setAttribute('aria-busy','false');
}
async function refresh() {
  if (busy) return;
  busy = true;$('refresh').disabled = true;$('sensor').disabled = true;$('connection').textContent = 'Consultando API…';
  const sensor = $('sensor').value;
  const controller = new AbortController(), timeout = setTimeout(()=>controller.abort(),10000);
  try {
    const response = await fetch(`/api/v1/dashboard/heatmap?sensor_id=${encodeURIComponent(sensor)}`,{signal:controller.signal,cache:'no-store'});
    if (!response.ok) throw new Error('Consulta indisponível');
    const data = await response.json();
    if (!Array.isArray(data.windows) || data.windows.length !== 4 || data.windows.some(w=>!Array.isArray(w.cells)||w.cells.length!==60)) throw new Error('Resposta incompatível');
    render(data);snapshotSensor = sensor;
    $('connection').textContent = 'API respondeu';$('updated').textContent = `Atualizado em ${date(data.generated_at)}`;$('error').hidden = true;
  } catch {
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
setInterval(refresh,REFRESH_INTERVAL_MS);setInterval(updateAge,1000);refresh();
