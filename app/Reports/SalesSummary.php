<?php
namespace App\Reports;
use Illuminate\Support\Facades\DB;
final class SalesSummary {
 public function get(object $tenant,array $period):array {
  // One SQL statement provides one PostgreSQL MVCC snapshot for every metric.
  $sql=<<<'SQL'
WITH p AS (SELECT cast(? as uuid) tenant,cast(? as timestamptz) start_at,cast(? as timestamptz) end_at,cast(? as text) zone,cast(? as uuid) warehouse,cast(? as text) currency),
o AS MATERIALIZED (SELECT o.* FROM orders o,p WHERE o.tenant_id=p.tenant AND o.status='completed' AND o.completed_at>=p.start_at AND o.completed_at<p.end_at AND (p.warehouse IS NULL OR o.warehouse_id=p.warehouse)),
t AS MATERIALIZED (SELECT t.* FROM transactions t JOIN orders s ON s.id=t.order_id AND s.tenant_id=t.tenant_id,p WHERE t.tenant_id=p.tenant AND t.status='completed' AND t.processed_at>=p.start_at AND t.processed_at<p.end_at AND (p.warehouse IS NULL OR s.warehouse_id=p.warehouse)),
currencies AS (SELECT currency FROM o UNION SELECT currency FROM t UNION SELECT currency FROM p),
days AS (SELECT generate_series((p.start_at AT TIME ZONE p.zone)::date,((p.end_at AT TIME ZONE p.zone)::date-1),interval '1 day')::date AS day FROM p),
daily_sales AS (SELECT (o.completed_at AT TIME ZONE p.zone)::date AS day,o.currency,sum(o.total_minor) gross,count(*) orders FROM o,p GROUP BY 1,2),
daily_refunds AS (SELECT (t.processed_at AT TIME ZONE p.zone)::date AS day,t.currency,sum(t.amount_minor) amount FROM t,p WHERE t.kind='refund' GROUP BY 1,2),
summary AS (SELECT c.currency,(SELECT count(*) FROM o WHERE o.currency=c.currency) order_count,
 coalesce((SELECT sum(total_minor) FROM o WHERE o.currency=c.currency),0) gross,
 coalesce((SELECT sum(amount_minor) FROM t WHERE t.currency=c.currency AND t.kind='payment'),0) captured,
 coalesce((SELECT sum(amount_minor) FROM t WHERE t.currency=c.currency AND t.kind='refund'),0) refunded FROM currencies c),
tenders AS (SELECT currency,method,coalesce(sum(amount_minor) FILTER(WHERE kind='payment'),0) captured,coalesce(sum(amount_minor) FILTER(WHERE kind='refund'),0) refunded FROM t GROUP BY currency,method)
SELECT jsonb_build_object('snapshot_at',clock_timestamp(),
 'totals',coalesce((SELECT jsonb_agg(jsonb_build_object('currency',currency,'order_count',order_count,'gross_minor',gross::text,'captured_minor',captured::text,'refunded_minor',refunded::text,'net_activity_minor',(gross-refunded)::text) ORDER BY currency) FROM summary),'[]'::jsonb),
 'daily',coalesce((SELECT jsonb_agg(jsonb_build_object('day',d.day,'currency',c.currency,'order_count',coalesce(s.orders,0),'gross_minor',coalesce(s.gross,0)::text,'refunded_minor',coalesce(r.amount,0)::text) ORDER BY d.day,c.currency) FROM days d CROSS JOIN currencies c LEFT JOIN daily_sales s ON s.day=d.day AND s.currency=c.currency LEFT JOIN daily_refunds r ON r.day=d.day AND r.currency=c.currency),'[]'::jsonb),
 'tenders',coalesce((SELECT jsonb_agg(jsonb_build_object('currency',currency,'method',method,'captured_minor',captured::text,'refunded_minor',refunded::text,'net_minor',(captured-refunded)::text) ORDER BY currency,method) FROM tenders),'[]'::jsonb)) result
SQL;
  $row=DB::selectOne($sql,[$tenant->id,$period['start_utc'],$period['end_utc'],$period['timezone'],$period['warehouse_id'],$tenant->default_currency]);
  return ['period'=>$period,...json_decode($row->result,true)];
 }
}
