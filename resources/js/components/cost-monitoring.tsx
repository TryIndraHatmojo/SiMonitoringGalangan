import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { estimate, update } from '@/routes/projects';
import { rupiah, type EstimateLine, type Estimate, type Period, type Ship, type Group } from '@/types/monitoring';

const emptyLine: EstimateLine = {code:'',name:'',unit:'',quantity:'1',local:'0',usd:'0',item:''};
export function EstimateEditor({project,groups}:{project:Ship;groups:Group[]}) {
    const [draft,setDraft] = useState<Estimate>(project.estimate ?? {rate:'',date:'',source:'',assumption:true,lines:[]});
    const [query,search] = useState('');
    const [errors,setErrors] = useState<Record<string,string>>({});
    function edit(index:number, patch:Partial<EstimateLine>) {
        setDraft({...draft, lines:draft.lines.map((line,i)=>i===index?{...line,...patch}:line)});
    }
    const totals = draft.lines.reduce((sum,l)=>({local:sum.local+Number(l.quantity)*Number(l.local),usd:sum.usd+Number(l.quantity)*Number(l.usd)}),{local:0,usd:0});
    return <section><h2>Engineering Estimate</h2><p>Petakan setiap baris ke item WBS. Terbitkan draft melalui Form Builder untuk memakai anggaran pada periode baru.</p>
    <div className="my-4 grid gap-3 sm:grid-cols-3">{([['rate','Kurs IDR per USD'],['date','Tanggal kurs'],['source','Sumber kurs']] as const).map(([key,label])=><label key={key}>{label}<Input type={key==='date'?'date':key==='rate'?'number':'text'} value={draft[key]} onChange={e=>setDraft({...draft,[key]:e.target.value})}/></label>)}</div>
    <label><input type="checkbox" checked={draft.assumption} onChange={e=>setDraft({...draft,assumption:e.target.checked})}/> Kurs asumsi</label>
    <p className="my-4">Lokal {rupiah(totals.local)} · Impor USD {totals.usd.toLocaleString('id-ID')} · <strong>BAC {rupiah(totals.local+totals.usd*Number(draft.rate))}</strong></p>
    <label>Cari kode atau uraian<Input value={query} onChange={e=>search(e.target.value)}/></label>
    {draft.lines.map((line,i)=>(line.code+' '+line.name).toLowerCase().includes(query.toLowerCase()) && <fieldset key={i} className="my-3 grid gap-2 rounded-lg border p-3 sm:grid-cols-4"><legend>Baris {i+1}</legend>
    {([['code','Kode sumber'],['name','Uraian'],['unit','Satuan'],['quantity','Volume'],['local','Harga satuan IDR'],['usd','Harga satuan USD']] as const).map(([key,label])=><label key={key}>{label}<Input value={line[key]} onChange={e=>edit(i,{[key]:e.target.value})}/>{errors['lines.'+i+'.'+key]}</label>)}
    <label>Item WBS<select value={line.item} onChange={e=>edit(i,{item:e.target.value})}><option value="">Pilih item</option>{groups.map(g=><optgroup key={g.id} label={g.name}>{g.items.map(it=><option key={it.id} value={it.id}>{it.name}</option>)}</optgroup>)}</select>{errors['lines.'+i+'.item']}</label>
    <Button variant="outline" onClick={()=>setDraft({...draft,lines:draft.lines.filter((_,n)=>n!==i)})}>Hapus baris</Button></fieldset>)}
    <div className="mt-4 flex gap-2"><Button variant="outline" onClick={()=>{search('');setDraft({...draft,lines:[...draft.lines,{...emptyLine}]})}}>Tambah baris</Button>
    <Button onClick={()=>router.put(estimate.url(project.id),{rate:draft.rate,date:draft.date,source:draft.source,assumption:draft.assumption,lines:draft.lines.map(l=>Object.fromEntries(Object.keys(emptyLine).map(k=>[k,l[k as keyof EstimateLine]])))},{preserveScroll:true,onError:setErrors,onSuccess:()=>setErrors({})})}>Simpan estimasi</Button></div></section>;
}

export function CostInput({project,period}:{project:number;period:Period}) {
    return <section><h2>Biaya aktual kumulatif</h2><p>{period.cost_simulated?'Simulasi':period.verified_at?'Terverifikasi':'Perlu verifikasi'} · {rupiah(period.actual_cost)}</p>
    {!period.closed_at && <Form {...update.form([project,period.id])} className="mt-3 flex flex-wrap items-end gap-3">
    <label>Total sampai akhir periode (Rp)<Input name="actual_cost" type="number" min="0" step=".01" defaultValue={period.actual_cost??''}/></label>
    <label>Referensi pembukuan<Input name="cost_source" defaultValue={period.cost_source??''}/></label><Button>Simpan biaya</Button>
    {period.actual_cost!=null && !period.verified_at && <Button variant="outline" name="verify" value="1">Verifikasi nilai tersimpan</Button>}</Form>}</section>;
}

export function CostSummary({period,history}:{period:Period;history:Period[]}) {
    const keys=['PV','EV','AC'] as const;
    const rows:Period[]=history.map(p=>({...p,metrics:{...p.metrics,EV:p.end<=period.end?p.metrics.EV:null,AC:p.end<=period.end?p.metrics.AC:null}}));
    const max=Math.max(1,...rows.flatMap(p=>keys.map(k=>p.metrics[k]??0)));
    return <section><h2>Kinerja biaya</h2><p>{period.cost_simulated || period.is_simulated?'Simulasi · ':''}{period.partial?'Progres parsial · ':''}{!period.verified_at?'AC belum terverifikasi':''}</p>
    <div className="my-4 grid grid-cols-2 gap-3 md:grid-cols-4">{Object.entries(period.metrics).map(([key,v])=><div key={key} className="rounded-md border p-3"><small>{key}</small><strong className="block tabular-nums">{['SPI','CPI'].includes(key)?v?.toLocaleString('id-ID',{maximumFractionDigits:3})??'Belum tersedia':rupiah(v)}</strong></div>)}</div>
    <h3>Kurva S biaya</h3><p>PV rencana · EV nilai hasil · AC biaya aktual</p><svg viewBox="0 0 640 160" role="img" aria-label="Kurva S biaya. Nilai tiap periode tersedia di tabel.">{keys.map((key,k)=>{const x=(i:number)=>24+(Date.parse(rows[i].end)-Date.parse(rows[0].end))/Math.max(1,Date.parse(rows[rows.length-1].end)-Date.parse(rows[0].end))*590;const y=(v:number)=>145-v/max*130;return <g key={key} stroke={['#5487ba','#17826d','#c27821'][k]}>{rows.map((row,i)=>{const v=row.metrics[key],prev=rows[i-1]?.metrics[key];return v!=null && <g key={row.id}>{prev!=null && <line x1={x(i-1)} y1={y(prev)} x2={x(i)} y2={y(v)} strokeWidth="2" strokeDasharray={k===0?'5 3':undefined}/>}<circle cx={x(i)} cy={y(v)} r="3"><title>{key} · {row.end} · {rupiah(v)}</title></circle></g>})}</g>})}</svg>
    <details><summary>Rincian nilai per periode</summary><div className="overflow-x-auto"><table><thead><tr><th>Akhir periode</th>{keys.map(k=><th key={k}>{k}</th>)}</tr></thead><tbody>{rows.map(r=><tr key={r.id}><td>{r.end}</td>{keys.map(k=><td key={k}>{rupiah(r.metrics[k])}</td>)}</tr>)}</tbody></table></div></details><p>EAC memakai BAC ÷ CPI dengan asumsi efisiensi biaya sekarang berlanjut hingga selesai.</p></section>;
}
