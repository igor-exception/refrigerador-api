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
  addEventListener(event, handler) { this.listeners[event] = handler; }
  querySelector() { return null; }
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
    window:{addEventListener(){}}, performance:{now:()=>clock},
    setInterval:(callback,ms)=>intervals.push({callback,ms}),
    setTimeout:()=>1,clearTimeout(){},
    fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject}))
  });
  vm.runInContext(readFileSync(join(__dirname,'../public/assets/dashboard.js'),'utf8'),context);
  return {get,intervals,requests,advance:ms=>{clock+=ms;}, refresh:()=>vm.runInContext('refresh()',context)};
}

function snapshot(value = 21.5) {
  const received = '2026-10-15T12:00:00Z';
  const latest = {temperature:value,sensor_id:'refrigerator',device_id:'test',measured_at:received,
    received_at:received,time_quality:'synced',temporal_warning:false};
  return {generated_at:'2026-10-15T12:00:10Z',latest,excluded_temporal_count:0,
    windows:[['10m',10],['30m',30],['1h',60],['5h',300]].map(([id,step])=>({id,bucket_seconds:step,
      cells:Array.from({length:60},(_,i)=>({start:received,end:'2026-10-15T12:00:10Z',
        average:i===58?value:null,minimum:i===58?value:null,maximum:i===58?value:null,
        count:i===58?1:0,received_at_count:0,last_reading:i===58?latest:null}))}))};
}
const settle = () => new Promise(resolve=>setImmediate(resolve));
async function succeed(h,data=snapshot(),index=h.requests.length-1) {
  h.requests[index].resolve({ok:true,json:async()=>data}); await settle();
}

test('consulta imediatamente, a cada 10 s e nunca sobrepõe chamadas lentas',async()=>{
  const h=harness();
  assert.deepEqual(h.intervals.map(i=>i.ms),[10000,1000]);
  assert.equal(h.requests.length,1);
  await h.refresh();await h.intervals[0].callback();
  assert.equal(h.requests.length,1);
  await succeed(h);
  h.intervals[0].callback();assert.equal(h.requests.length,2);
  await succeed(h);
});

test('preserva quatro grades, escalas e célula atual cinza sem inventar temperatura',async()=>{
  const h=harness();await succeed(h);
  const sections=h.get('heatmaps').children;
  assert.equal(sections.length,4);
  for(const section of sections) {
    const cells=section.children[1].children;
    assert.equal(cells.length,60);
    assert.match(cells[59].className,/empty current/);
    assert.equal(cells[59].attributes['aria-current'],'time');
    assert.equal(cells[59].style.backgroundColor,undefined);
    assert.equal(cells.filter(cell=>cell.className.includes('current')).length,1);
    assert.ok(cells[59].listeners.click);assert.ok(cells[59].listeners.focus);
  }
  assert.equal(h.get('temperature').textContent,'21,50');
});

test('falha mantém snapshot, idade avança e recuperação atualiza card e heatmaps',async()=>{
  const h=harness();await succeed(h);
  const previous=h.get('heatmaps').children,updated=h.get('updated').textContent;
  h.intervals[0].callback();h.requests[1].reject(new Error('rede'));await settle();
  assert.equal(h.get('heatmaps').children,previous);
  assert.equal(h.get('temperature').textContent,'21,50');
  assert.equal(h.get('updated').textContent,updated);
  assert.equal(h.get('error').hidden,false);
  h.advance(70000);h.intervals[1].callback();
  assert.equal(h.get('age').textContent,'1 min 20 s');
  assert.equal(h.get('freshness').textContent,'Dados desatualizados');
  h.intervals[0].callback();await succeed(h,snapshot(22.25));
  assert.equal(h.get('temperature').textContent,'22,25');
  assert.notEqual(h.get('heatmaps').children,previous);
  assert.equal(h.get('error').hidden,true);
});

test('erro HTTP e resposta incompatível preservam visualização válida',async()=>{
  const h=harness();await succeed(h);
  const previous=h.get('heatmaps').children;
  h.intervals[0].callback();h.requests[1].resolve({ok:false});await settle();
  assert.equal(h.get('heatmaps').children,previous);
  h.intervals[0].callback();await succeed(h,{windows:[]});
  assert.equal(h.get('heatmaps').children,previous);
});

test('falha inicial não fabrica células históricas vazias',async()=>{
  const h=harness();h.requests[0].reject(new Error('rede'));await settle();
  assert.match(h.get('error').textContent,/nenhuma lacuna histórica foi inferida/);
  assert.equal(h.get('heatmaps').children.length,1);
  assert.equal(h.get('heatmaps').attributes['aria-busy'],'false');
});
