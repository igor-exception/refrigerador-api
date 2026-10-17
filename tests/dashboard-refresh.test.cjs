const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const vm = require('node:vm');

// DOM mínimo em memória: exercita o script real, sem biblioteca ou servidor.
class Element {
  constructor(tag = 'div') {
    this.tag = tag; this.children = []; this.dataset = {}; this.style = {};
    this.attributes = {}; this.textContent = ''; this.hidden = false; this.listeners = {};
    const classes = new Set();
    this.classList = {
      toggle(name, on) { on ? classes.add(name) : classes.delete(name); },
      remove(name) { classes.delete(name); }
    };
  }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(key, value) { this.attributes[key] = value; }
  removeAttribute(key) { delete this.attributes[key]; }
  addEventListener(event, handler) { this.listeners[event] = handler; }
  querySelector() { return null; }
  getBoundingClientRect() { return {left:10,top:10,bottom:30}; }
  showModal() { this.open = true; }
  close() { this.open = false; }
}

function harness() {
  const nodes = new Map();
  const get = id => { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id); };
  get('sensor').value = 'refrigerator';
  const intervals = [], requests = [];
  let clock = 0;
  const context = vm.createContext({
    Intl, Date, AbortController, encodeURIComponent,
    document: {getElementById:get,createElement:tag=>new Element(tag),activeElement:null,querySelector:()=>get('latest')},
    window:{addEventListener(){},innerWidth:1280}, performance:{now:()=>clock},
    setInterval:(callback,ms)=>intervals.push({callback,ms}),
    setTimeout:()=>1,clearTimeout(){},
    fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))
  });
  vm.runInContext(readFileSync(join(__dirname,'../public/assets/dashboard.js'),'utf8'),context);
  return {get,intervals,requests,advance:ms=>{clock+=ms;}, evaluate:code=>vm.runInContext(code,context), refresh:()=>vm.runInContext('refresh()',context)};
}

function snapshot(value = 21.5) {
  const received = '2026-10-15T12:00:00Z';
  const latest = {temperature:value,sensor_id:'refrigerator',device_id:'test',measured_at:received,
    received_at:received,time_quality:'synced',temporal_warning:false};
  return {generated_at:'2026-10-15T12:00:10Z',latest,excluded_temporal_count:0,
    windows:[['10m',10],['30m',30],['1h',60],['5h',300]].map(([id,step])=>({id,bucket_seconds:step,
      cells:Array.from({length:60},(_,i)=>({
        start:new Date(Math.floor(Date.parse('2026-10-15T12:00:10Z')/(step*1000))*step*1000+(i-59)*step*1000).toISOString(),
        end:new Date(Math.floor(Date.parse('2026-10-15T12:00:10Z')/(step*1000))*step*1000+(i-58)*step*1000).toISOString(),
        average:i===58?value:null,minimum:i===58?value:null,maximum:i===58?value:null,
        count:i===58?1:0,received_at_count:0,last_reading:i===58?latest:null}))}))};
}
const settle = () => new Promise(resolve=>setImmediate(resolve));
async function succeed(h,data=snapshot(),index=h.requests.length-1) {
  h.requests[index].resolve({ok:true,json:async()=>data}); await settle();
}

const cells = (h,index=0) => h.get('heatmaps').children[index].children[1].children;
const tick = (h,ms) => {h.advance(ms);h.intervals[1].callback();};
const spinner = (h,index=0) => cells(h,index)[59].children[0];
function atBoundary() { const data=snapshot();data.generated_at='2026-10-15T12:00:00Z';
  for(const window of data.windows) {const step=window.bucket_seconds*1000;
    window.cells.forEach((cell,i)=>{cell.start=new Date(Date.parse(data.generated_at)+(i-59)*step).toISOString();cell.end=new Date(Date.parse(data.generated_at)+(i-58)*step).toISOString();});}
  return data;
}

test('polling 10 s e apenas um timer de idade/janelas, sem chamadas sobrepostas',async()=>{
  const h=harness();assert.deepEqual(h.intervals.map(i=>i.ms),[10000,1000]);
  await h.refresh();h.intervals[0].callback();assert.equal(h.requests.length,1);
  await succeed(h);h.intervals[0].callback();assert.equal(h.requests.length,2);await succeed(h);
});

test('quatro spinners sem texto, temperatura, countdown ou anúncios contínuos',async()=>{
  const h=harness(),data=atBoundary();
  for(const w of data.windows) Object.assign(w.cells[59],{count:1,average:21.5,last_reading:data.latest});
  await succeed(h,data);
  for(let i=0;i<4;i++) {
    const current=cells(h,i)[59];assert.equal(current.children.length,1);
    assert.equal(spinner(h,i).className,'cell-spinner');assert.equal(spinner(h,i).textContent,'');
    assert.equal(spinner(h,i).attributes['aria-hidden'],'true');assert.equal(current.attributes['aria-current'],'time');
    assert.match(current.attributes['aria-label'],/Intervalo temporal em andamento/);
    assert.equal(current.attributes['aria-live'],undefined);
    assert.equal(spinner(h,i).children.length,0);
    current.listeners.click();assert.match(h.get('details-content').children[1].textContent,/Média 21,50/);
  }
  const previous=spinner(h);tick(h,3000);assert.equal(spinner(h),previous);
  assert.equal(spinner(h).textContent,'');assert.equal(h.requests.length,1);
});

for(const [index,seconds] of [[0,10],[1,30],[2,60],[3,300]]) {
  test('janela de '+seconds+' s avança no fechamento, sem aguardar HTTP',async()=>{
    const h=harness();await succeed(h,atBoundary());
    const previous=cells(h,index);tick(h,seconds*1000-1);
    assert.equal(cells(h,index)[59].attributes['aria-current'],'time');
    tick(h,1);assert.notEqual(cells(h,index),previous);
    assert.equal(cells(h,index).length,60);assert.match(cells(h,index)[59].className,/pending current/);
    assert.equal(spinner(h,index).className,'cell-spinner');assert.equal(h.requests.length,1);
  });
}

test('preserva textos, 240 células, médias históricas e lacunas confirmadas',async()=>{
  const h=harness();await succeed(h);
  assert.deepEqual(h.get('heatmaps').children.map(s=>s.children[0].children.map(n=>n.textContent)),[
    ['Medição a cada 10 segundos','60 intervalos · total de 10 minutos'],
    ['Medição a cada 30 segundos','60 intervalos · total de 30 minutos'],
    ['Medição a cada 1 minuto','60 intervalos · total de 1 hora'],
    ['Medição a cada 5 minutos','60 intervalos · total de 5 horas']]);
  for(let i=0;i<4;i++) {
    assert.equal(cells(h,i).length,60);assert.equal(cells(h,i)[58].children[0].textContent,'21,5');
    assert.match(cells(h,i)[0].className,/empty/);assert.equal(cells(h,i)[0].children.length,0);
  }
});

test('intervalo parcial só exibe temperatura histórica após confirmação do fechamento',async()=>{
  const h=harness(),data=atBoundary();Object.assign(data.windows[0].cells[59],{count:1,average:-18.2,last_reading:data.latest});
  await succeed(h,data);tick(h,10000);
  assert.match(cells(h)[58].className,/pending/);assert.equal(cells(h)[58].children.length,0);
  assert.equal(data.windows[0].cells[59].average,-18.2);
  cells(h)[58].listeners.click();assert.match(h.get('details-content').children[1].textContent,/Dados parciais/);
  assert.match(h.get('details-content').children[2].textContent,/Média -18,20/);
  h.intervals[0].callback();await succeed(h,snapshot(-18.2));
  assert.equal(cells(h)[58].children[0].textContent,'-18,2');assert.doesNotMatch(cells(h)[58].className,/pending/);
});

test('pendências não viram lacunas nem temperaturas inventadas após múltiplos intervalos',async()=>{
  const h=harness();await succeed(h,atBoundary());tick(h,30000);
  for(const index of [57,58]) {
    assert.match(cells(h)[index].className,/pending/);assert.doesNotMatch(cells(h)[index].className,/empty/);
    assert.equal(cells(h)[index].children.length,0);
    cells(h)[index].listeners.click();assert.match(h.get('details-content').children[1].textContent,/não confirmado/);
  }
  assert.equal(cells(h)[59].children[0].className,'cell-spinner');
});

test('falha da API mantém dados, avisos e spinner; recuperação reconcilia a grade',async()=>{
  const h=harness();await succeed(h,atBoundary());
  const previous=h.get('heatmaps').children,updated=h.get('updated').textContent;
  tick(h,3000);h.intervals[0].callback();h.requests[1].reject(new Error('rede'));await settle();
  assert.equal(h.get('heatmaps').children,previous);assert.equal(h.get('error').hidden,false);
  assert.equal(h.get('updated').textContent,updated);assert.equal(h.get('temperature').textContent,'21,50');
  tick(h,70000);assert.equal(h.get('freshness').textContent,'Dados desatualizados');
  for(let i=0;i<4;i++) assert.equal(spinner(h,i).className,'cell-spinner');
  h.intervals[0].callback();await succeed(h,snapshot(22.25));
  assert.equal(h.get('temperature').textContent,'22,25');assert.equal(h.get('error').hidden,true);
  assert.equal(h.intervals.length,2);
});

test('resposta lenta não atrasa relógio temporal; re-render não depende da animação CSS',async()=>{
  const h=harness();await succeed(h,atBoundary());tick(h,3000);
  const before=cells(h);h.intervals[0].callback();tick(h,8000);
  assert.notEqual(cells(h),before);assert.match(cells(h)[59].className,/pending/);
  await succeed(h,atBoundary());assert.equal(h.evaluate('clockNow()'),Date.parse('2026-10-15T12:00:11Z'));
  assert.equal(spinner(h).className,'cell-spinner');
});

test('latência é compensada e referência inválida preserva último snapshot',async()=>{
  const h=harness();h.advance(2000);await succeed(h,atBoundary());tick(h,1000);
  assert.equal(h.evaluate('clockNow()'),Date.parse('2026-10-15T12:00:02Z'));
  const previous=h.get('heatmaps').children,invalid=atBoundary();invalid.generated_at='inválido';
  h.intervals[0].callback();await succeed(h,invalid);
  assert.equal(h.get('heatmaps').children,previous);assert.equal(h.get('error').hidden,false);
  assert.equal(spinner(h).className,'cell-spinner');
});

test('offline/sem medições mantém spinner e não fabrica temperatura',async()=>{
  const h=harness(),data=atBoundary();data.latest=null;
  for(const w of data.windows) for(const c of w.cells) Object.assign(c,{count:0,average:null,last_reading:null});
  await succeed(h,data);tick(h,61000);
  for(let i=0;i<4;i++) {assert.equal(spinner(h,i).className,'cell-spinner');assert.equal(cells(h,i)[0].children.length,0);}
  assert.equal(h.get('temperature').textContent,'—');assert.equal(h.get('freshness').textContent,'Sem registros');
});

test('falha inicial não inventa histórico ou referência temporal',async()=>{
  const h=harness();h.requests[0].reject(new Error('rede'));await settle();
  assert.equal(h.evaluate('currentIntervals.length'),0);assert.equal(h.get('error').hidden,false);
  assert.equal(h.get('heatmaps').children[0].className,'placeholder');
});

test('HTTP inválido, contrato incompatível e atualizações repetidas não acumulam timers',async()=>{
  const h=harness();await succeed(h);const previous=h.get('heatmaps').children;
  h.intervals[0].callback();h.requests[1].resolve({ok:false});await settle();assert.equal(h.get('heatmaps').children,previous);
  h.intervals[0].callback();await succeed(h,{windows:[]});assert.equal(h.get('heatmaps').children,previous);
  for(let i=0;i<20;i++) {h.intervals[0].callback();await succeed(h);assert.equal(h.evaluate('currentIntervals.length'),4);}
  assert.equal(h.intervals.length,2);
});

test('médias pt-BR, negativos, zero, tooltip, foco e detalhes históricos preservados',async()=>{
  for(const [value,expected] of [[21.8125,'21,8'],[-18.25,'-18,3'],[0,'0,0'],[43.2,'43,2']]) {
    const h=harness();await succeed(h,snapshot(value));
    for(let i=0;i<4;i++) assert.equal(cells(h,i)[58].children[0].textContent,expected);
    cells(h)[58].listeners.focus();assert.match(h.get('tooltip').textContent,/Média/);
    cells(h)[58].listeners.click();assert.equal(h.get('details').open,true);
    h.get('close-details').listeners.click();assert.equal(h.get('details').open,false);
    cells(h)[0].listeners.click();assert.match(h.get('details-content').children[1].textContent,/Não há medições/);
  }
});

test('escala 10–35, saturação e contraste histórico mínimo de 4,5:1 preservados',async()=>{
  const h=harness();await succeed(h);
  assert.equal(h.evaluate('color(-18.2)'),'rgb(30,64,175)');assert.equal(h.evaluate('color(43.2)'),'rgb(193,38,38)');
  for(let v=-20;v<=50;v+=.1) {
    const background=h.evaluate('color('+v+')'),foreground=h.evaluate('textColor(color('+v+'))');
    const rgb=background.match(/\d+/g).map(Number).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);
    const l=rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
    assert.ok((foreground==='#000'?(l+.05)/.05:1.05/(l+.05))>=4.5);
  }
});
