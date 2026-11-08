<?php
namespace App\Reports;
use Illuminate\Support\Facades\DB;
final class PaymentReconciliation {
 public function get(string $tenant,array $period):array {
  $sql=<<<'SQL'
WITH p AS (SELECT cast(? as uuid) tenant,cast(? as timestamptz) start_at,cast(? as timestamptz) end_at,cast(? as uuid) warehouse),
b AS MATERIALIZED (SELECT o.id,o.receipt_number,o.currency,o.total_minor,o.completed_at,
 coalesce((SELECT sum(t.amount_minor) FROM transactions t WHERE t.tenant_id=o.tenant_id AND t.order_id=o.id AND t.kind='payment' AND t.status='completed'),0) captured,
 coalesce((SELECT sum(t.amount_minor) FROM transactions t WHERE t.tenant_id=o.tenant_id AND t.order_id=o.id AND t.kind='refund' AND t.status='completed'),0) paid_refund,
 coalesce((SELECT sum(r.amount_minor) FROM refunds r WHERE r.tenant_id=o.tenant_id AND r.order_id=o.id),0) requested_refund
 FROM orders o,p WHERE o.tenant_id=p.tenant AND o.completed_at>=p.start_at AND o.completed_at<p.end_at AND (p.warehouse IS NULL OR o.warehouse_id=p.warehouse)),
issues AS (SELECT * FROM b WHERE captured<>total_minor OR paid_refund>total_minor OR requested_refund>total_minor OR paid_refund>requested_refund),
waiting AS (SELECT s.id,s.state,s.total_minor::text total_minor,s.card_minor::text card_minor,s.currency,s.expires_at,s.provider_id IS NULL unknown_provider FROM payment_sessions s,p WHERE s.tenant_id=p.tenant AND s.state='reserved' AND (p.warehouse IS NULL OR s.warehouse_id=p.warehouse) ORDER BY s.created_at LIMIT 25),
review AS (SELECT r.id,r.order_id,r.state,r.amount_minor::text amount_minor,o.currency FROM refunds r JOIN orders o ON o.id=r.order_id AND o.tenant_id=r.tenant_id,p WHERE r.tenant_id=p.tenant AND r.state<>'completed' AND (p.warehouse IS NULL OR o.warehouse_id=p.warehouse) ORDER BY r.created_at LIMIT 25)
SELECT jsonb_build_object('snapshot_at',clock_timestamp(),'checked_orders',(SELECT count(*) FROM b),'discrepancy_count',(SELECT count(*) FROM issues),
 'exceptions',coalesce((SELECT jsonb_agg(jsonb_build_object('order_id',id,'receipt_number',receipt_number,'currency',currency,'expected_minor',total_minor::text,'captured_minor',captured::text,'paid_refund_minor',paid_refund::text,'requested_refund_minor',requested_refund::text)) FROM (SELECT * FROM issues ORDER BY completed_at DESC,id LIMIT 25) i),'[]'::jsonb),
 'balances',coalesce((SELECT jsonb_agg(to_jsonb(z) ORDER BY currency) FROM (SELECT currency,sum(total_minor)::text expected_minor,sum(captured)::text captured_minor,sum(paid_refund)::text paid_refund_minor,sum(requested_refund-paid_refund)::text pending_refund_minor FROM b GROUP BY currency) z),'[]'::jsonb),
 'pending_payments',coalesce((SELECT jsonb_agg(to_jsonb(w)) FROM waiting w),'[]'::jsonb),
 'pending_refunds',coalesce((SELECT jsonb_agg(to_jsonb(r)) FROM review r),'[]'::jsonb)) result
SQL;
  return ['period'=>$period,...json_decode(DB::selectOne($sql,[$tenant,$period['start_utc'],$period['end_utc'],$period['warehouse_id']])->result,true)];
 }
}
