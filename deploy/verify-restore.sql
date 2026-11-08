\set ON_ERROR_STOP on
SET row_security=off;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM orders o WHERE o.total_minor <> (SELECT coalesce(sum(i.line_total_minor),0) FROM order_items i WHERE i.tenant_id=o.tenant_id AND i.order_id=o.id)) THEN
        RAISE EXCEPTION 'Receipt lines differ from order total';
    END IF;
    IF EXISTS (SELECT 1 FROM orders o WHERE o.total_minor <> (SELECT coalesce(sum(t.amount_minor),0) FROM transactions t WHERE t.tenant_id=o.tenant_id AND t.order_id=o.id AND t.kind='payment' AND t.status='completed')) THEN
        RAISE EXCEPTION 'Captured payments differ from order total';
    END IF;
    IF EXISTS (SELECT 1 FROM inventory_stocks s WHERE s.on_hand <> (SELECT coalesce(sum(m.quantity_delta),0) FROM stock_movements m WHERE m.tenant_id=s.tenant_id AND m.warehouse_id=s.warehouse_id AND m.product_id=s.product_id)) THEN
        RAISE EXCEPTION 'Stock balance differs from movement ledger';
    END IF;
    IF EXISTS (SELECT 1 FROM transactions original WHERE original.kind='payment' AND original.amount_minor < (SELECT coalesce(sum(r.amount_minor),0) FROM transactions r WHERE r.tenant_id=original.tenant_id AND r.original_transaction_id=original.id AND r.kind='refund' AND r.status='completed')) THEN
        RAISE EXCEPTION 'Refund exceeds captured payment';
    END IF;
    IF EXISTS (SELECT 1 FROM order_items i WHERE i.quantity < (SELECT coalesce(sum(ri.quantity),0) FROM refund_items ri JOIN refunds r ON r.tenant_id=ri.tenant_id AND r.id=ri.refund_id WHERE ri.tenant_id=i.tenant_id AND ri.order_item_id=i.id AND r.state='completed')) THEN
        RAISE EXCEPTION 'Refunded quantity exceeds sold quantity';
    END IF;
END $$;
SELECT 'business invariants verified' AS result;
