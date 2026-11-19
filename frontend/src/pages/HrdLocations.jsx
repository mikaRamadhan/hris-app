import { useState } from 'react'
import { Crosshair, Plus } from 'lucide-react'
import { api } from '../api.js'
import { ActiveBadge, Empty, Field, Modal, PageHead, Spinner, useForm, useLoad, useToast } from '../ui.jsx'

function LocationForm({ item, onClose, onDone }) {
  const toast = useToast()
  const f = useForm({ location_name: item?.location_name || '', address: item?.address || '', latitude: item?.latitude ?? '', longitude: item?.longitude ?? '', radius_meters: item?.radius_meters ?? 100, is_active: item?.is_active ?? true })
  const v = f.values
  const E = (k) => f.errors[k]?.[0]
  const here = () => navigator.geolocation
    ? navigator.geolocation.getCurrentPosition((p) => f.setValues({ ...v, latitude: p.coords.latitude.toFixed(7), longitude: p.coords.longitude.toFixed(7) }), () => toast('Izin lokasi ditolak atau tidak tersedia.', 'bad'), { enableHighAccuracy: true })
    : toast('Perangkat tidak mendukung geolokasi.', 'bad')
  const submit = async (e) => {
    e.preventDefault(); f.setErrors({})
    try { item ? await api.put(`/hrd/locations/${item.id}`, v) : await api.post('/hrd/locations', v); toast('Lokasi disimpan.'); onDone() }
    catch (err) { f.setErrors(err.errors || {}); toast(err.message, 'bad') }
  }
  return (
    <Modal title={item ? 'Ubah lokasi' : 'Lokasi absensi baru'} onClose={onClose}>
      <form className="stack" onSubmit={submit}>
        <Field label="Nama lokasi" error={E('location_name')}><input required value={v.location_name} onChange={f.set('location_name')} placeholder="Kantor Pusat / Cabang Bandung" /></Field>
        <Field label="Alamat" error={E('address')}><textarea value={v.address} onChange={f.set('address')} /></Field>
        <div className="grid g2">
          <Field label="Latitude" error={E('latitude')}><input required type="number" step="any" value={v.latitude} onChange={f.set('latitude')} /></Field>
          <Field label="Longitude" error={E('longitude')}><input required type="number" step="any" value={v.longitude} onChange={f.set('longitude')} /></Field>
        </div>
        <button type="button" className="btn sm" style={{ alignSelf: 'flex-start' }} onClick={here}><Crosshair size={15} /> Gunakan lokasi saya sekarang</button>
        <Field label="Radius (meter)" error={E('radius_meters')} hint="Jarak maksimum dari titik lokasi agar absensi dianggap valid"><input required type="number" min="10" max="5000" value={v.radius_meters} onChange={f.set('radius_meters')} /></Field>
        <label className="check"><input type="checkbox" checked={v.is_active} onChange={f.set('is_active')} /> Lokasi aktif</label>
        <div className="row"><button className="btn primary">Simpan</button><button type="button" className="btn" onClick={onClose}>Batal</button></div>
      </form>
    </Modal>
  )
}

export default function HrdLocations() {
  const toast = useToast()
  const { data, loading, error, reload } = useLoad(() => api.get('/hrd/locations'))
  const [edit, setEdit] = useState(null)
  const del = async (l) => {
    if (!confirm(`Hapus lokasi "${l.location_name}"?`)) return
    try { const r = await api.del(`/hrd/locations/${l.id}`); toast(r.message); reload() } catch (e) { toast(e.message, 'bad') }
  }
  return (
    <>
      <PageHead title="Lokasi Absensi" subtitle="Titik kantor/cabang beserta radius geofence."><button className="btn primary" onClick={() => setEdit({})}><Plus size={16} /> Lokasi baru</button></PageHead>
      <div className="card flush table-wrap">
        {loading ? <Spinner /> : error ? <div className="alert bad">{error}</div> : !data.length ? <Empty>Belum ada lokasi.</Empty> : (
          <table><thead><tr><th>Lokasi</th><th>Koordinat</th><th>Radius</th><th>Status</th><th /></tr></thead>
            <tbody>{data.map((l) => <tr key={l.id}><td><b>{l.location_name}</b><div className="muted small">{l.address || '-'}</div></td><td className="small">{l.latitude}, {l.longitude}</td><td>{l.radius_meters} m</td><td><ActiveBadge on={l.is_active} /></td>
              <td className="right"><div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn sm" onClick={() => setEdit(l)}>Ubah</button><button className="btn sm danger" onClick={() => del(l)}>Hapus</button></div></td></tr>)}</tbody></table>)}
      </div>
      <p className="muted small" style={{ marginTop: 12 }}>Absensi valid bila jarak perangkat ke lokasi aktif terdekat tidak melebihi radius. Geofence bukan bukti lokasi yang sempurna; GPS dapat dimanipulasi.</p>
      {edit && <LocationForm item={edit.id ? edit : null} onClose={() => setEdit(null)} onDone={() => { setEdit(null); reload() }} />}
    </>
  )
}
