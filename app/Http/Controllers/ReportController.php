<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Role;
use App\Reports\{ReportPeriod,SalesSummary,PaymentReconciliation};
final class ReportController {
 private function allow(Request $r,string $permission):void {abort_unless(in_array($permission,Role::findOrFail($r->attributes->get('membership')->role)->permissions,true),403);}
 public function sales(Request $r,SalesSummary $report){$this->allow($r,'reports.sales');return response()->json($report->get($r->attributes->get('tenant'),ReportPeriod::from($r)));}
 public function reconciliation(Request $r,PaymentReconciliation $report){$this->allow($r,'reports.reconciliation');return response()->json($report->get($r->attributes->get('tenant')->id,ReportPeriod::from($r)));}
 public function lowStock(Request $r){
  $this->allow($r,'reports.inventory');$tenant=$r->attributes->get('tenant')->id;
  $data=$r->validate(['page'=>'sometimes|integer|min:1|max:1000000','warehouse_id'=>['nullable','uuid',Rule::exists('warehouses','id')->where('tenant_id',$tenant)]]);$page=(int)($data['page']??1);
  $sql=<<<'SQL'
WITH p AS (SELECT cast(? as uuid) tenant,cast(? as uuid) warehouse),
low AS MATERIALIZED (SELECT product.id product_id,product.name product_name,product.sku,product.unit,product.reorder_point::text reorder_point,w.id warehouse_id,w.name warehouse_name,coalesce(s.on_hand,0)::text on_hand,coalesce(s.reserved,0)::text reserved,(coalesce(s.on_hand,0)-coalesce(s.reserved,0))::text available
 FROM products product CROSS JOIN warehouses w CROSS JOIN p LEFT JOIN inventory_stocks s ON s.tenant_id=product.tenant_id AND s.product_id=product.id AND s.warehouse_id=w.id
 WHERE product.tenant_id=p.tenant AND w.tenant_id=p.tenant AND product.is_active AND product.track_inventory AND w.is_active AND (p.warehouse IS NULL OR w.id=p.warehouse) AND coalesce(s.on_hand,0)-coalesce(s.reserved,0)<=product.reorder_point)
SELECT jsonb_build_object('snapshot_at',clock_timestamp(),'total',(SELECT count(*) FROM low),'data',coalesce((SELECT jsonb_agg(to_jsonb(r)) FROM (SELECT * FROM low ORDER BY product_name,warehouse_name,product_id,warehouse_id LIMIT 25 OFFSET ?) r),'[]'::jsonb)) result
SQL;
  $result=json_decode(DB::selectOne($sql,[$tenant,$data['warehouse_id']??null,($page-1)*25])->result,true);
  return response()->json([...$result,'current_page'=>$page,'last_page'=>max(1,(int)ceil($result['total']/25))]);
 }
 public function audit(Request $r){
  $this->allow($r,'audit.read');$period=ReportPeriod::from($r);$data=$r->validate(['page'=>'sometimes|integer|min:1','action'=>'nullable|string|max:80','entity_id'=>'nullable|uuid','request_id'=>'nullable|uuid']);
  $query=DB::table('audit_logs')->where('tenant_id',$r->attributes->get('tenant')->id)->where('created_at','>=',$period['start_utc'])->where('created_at','<',$period['end_utc']);
  foreach(['action','entity_id','request_id'] as $field)if(!empty($data[$field]))$query->where($field,$data[$field]);
  return response()->json($query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->through(function($row){$row->details=json_decode($row->details,true);return $row;}));
 }
}
