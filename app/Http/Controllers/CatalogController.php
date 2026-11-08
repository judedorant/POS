<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\Role;
final class CatalogController {
 private function query(Request $r) { return DB::table($r->route('resource'))->where('tenant_id',$r->attributes->get('tenant')->id); }
 private function authorizeWrite(Request $r): void {
  abort_unless(in_array('catalog.manage',Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);
 }
 public function index(Request $r) {
  $input=$r->validate(['q'=>'nullable|string|max:160','page'=>'sometimes|integer|min:1','active'=>'sometimes|boolean']);
  $q=$this->query($r);
  if (isset($input['active'])) $q->where('is_active',(bool)$input['active']);
  if (!empty($input['q'])) $q->where(function($q) use($r,$input) {
   $q->where('name','ilike','%'.$input['q'].'%');
   if($r->route('resource')==='products') $q->orWhere('sku',$input['q'])->orWhere('barcode',$input['q']);
  });
  return response()->json($q->orderBy('name')->orderBy('id')->paginate(25));
 }
 public function show(Request $r) { return response()->json($this->query($r)->where('id',$r->route('record'))->firstOrFail()); }
 public function store(Request $r) { return $this->save($r,false); }
 public function update(Request $r) { return $this->save($r,true); }
 private function save(Request $r,bool $update) {
  $this->authorizeWrite($r);
  $table=$r->route('resource'); $tenant=$r->attributes->get('tenant')->id;
  $id=$update ? $this->query($r)->where('id',$r->route('record'))->firstOrFail()->id : (string) Str::uuid7();
  $required=$update ? 'sometimes' : 'required';
  $unique=fn($column)=>Rule::unique($table,$column)->where('tenant_id',$tenant)->ignore($id);
  $rules=['name'=>[$required,'string','max:120'],'is_active'=>['sometimes','boolean']];
  if($table==='categories') $rules['slug']=[$required,'alpha_dash:ascii','max:80',$unique('slug')];
  if($table==='warehouses') $rules += ['code'=>[$required,'alpha_dash:ascii','max:80',$unique('code')],'address'=>['nullable','string','max:1000']];
  if($table==='products') $rules += [
   'sku'=>[$required,'string','max:80',$unique('sku')], 'barcode'=>['nullable','string','max:80',$unique('barcode')],
   'category_id'=>['nullable','uuid',Rule::exists('categories','id')->where('tenant_id',$tenant)],
   'sale_price_minor'=>[$required,'integer','min:0','max:999999999999'],
   'unit'=>['sometimes',Rule::in(['each','kg','g','litre','metre'])], 'track_inventory'=>['sometimes','boolean']
  ];
  if($table==='products')$rules['reorder_point']=['sometimes','string','regex:/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/'];
  $data=$r->validate($rules); $data['updated_at']=now();
  // Savepoint keeps concurrent unique violations from aborting the outer request transaction.
  try { DB::transaction(function() use($r,$update,$data,$table,$tenant,$id) {
   $before=$update?(array)$this->query($r)->where('id',$id)->lockForUpdate()->firstOrFail():[];
   if($update) $this->query($r)->where('id',$id)->update($data);
   else DB::table($table)->insert([...$data,'id'=>$id,'tenant_id'=>$tenant,'created_at'=>now()]);
   $fields=array_intersect_key($data,array_flip(['name','sku','barcode','slug','code','sale_price_minor','reorder_point','is_active','track_inventory','unit','category_id']));
   \App\Actions\RecordAudit::record($tenant,$update?'catalog.updated':'catalog.created',$table,$id,$r->attributes->get('membership')->id,['before'=>array_intersect_key($before,$fields),'after'=>$fields]);
   \App\Actions\RecordTenantChange::record($tenant,['catalog']);
  }); } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
   return response()->json(['message'=>'This identifier is already in use in this business.'],409);
  }
  return response()->json($this->query($r)->where('id',$id)->first(),$update?200:201);
 }
}
