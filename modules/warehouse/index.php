<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'manager', 'production');

$pdo = getDBConnection();

$totalRawReceived = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(wii.quantity), 0)
    FROM warehouse_in_items wii
    JOIN warehouse_in wi ON wi.id = wii.warehouse_in_id
", [], 0);

$totalDone = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(qty_in), 0)
    FROM finished_goods_stock
    WHERE type = 'normal'
", [], 0);

$totalDefect = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(qty_in), 0)
    FROM finished_goods_stock
    WHERE type = 'defect'
", [], 0);

$totalInStock = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(qty_remaining), 0)
    FROM finished_goods_stock
    WHERE status IN ('pending_export', 'partial_export')
", [], 0);

$todayReceipts = fetchAllSafe($pdo, "
    SELECT wi.receipt_no, wi.receipt_date, c.customer_name,
           COUNT(wii.id) AS item_count,
           COALESCE(SUM(wii.quantity), 0) AS total_qty
    FROM warehouse_in wi
    JOIN customers c ON c.id = wi.customer_id
    LEFT JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
    WHERE wi.receipt_date = CURDATE()
    GROUP BY wi.id
    ORDER BY wi.id DESC
");

$todayDeliveries = fetchAllSafe($pdo, "
    SELECT d.delivery_no, d.delivery_date, c.customer_name,
           COUNT(di.id) AS item_count,
           COALESCE(SUM(di.quantity), 0) AS total_qty
    FROM deliveries d
    JOIN customers c ON c.id = d.customer_id
    LEFT JOIN delivery_items di ON di.delivery_id = d.id
    WHERE d.delivery_date = CURDATE()
      AND d.status IN ('confirmed', 'invoiced')
    GROUP BY d.id
    ORDER BY d.id DESC
");

$stockSummary = fetchAllSafe($pdo, "
    SELECT pc.product_code, pc.description, pc.unit,
           COALESCE(waiting.qty_waiting, 0) AS qty_waiting_export,
           COALESCE(defect.qty_defect, 0) AS qty_defect,
           COALESCE(progress.qty_waiting_sx, 0) AS qty_waiting_sx
    FROM product_codes pc
    LEFT JOIN (
        SELECT product_code_id, SUM(qty_remaining) AS qty_waiting
        FROM finished_goods_stock
        WHERE type = 'normal' AND status IN ('pending_export', 'partial_export')
        GROUP BY product_code_id
    ) waiting ON waiting.product_code_id = pc.id
    LEFT JOIN (
        SELECT product_code_id, SUM(qty_remaining) AS qty_defect
        FROM finished_goods_stock
        WHERE type = 'defect' AND status IN ('pending_export', 'partial_export')
        GROUP BY product_code_id
    ) defect ON defect.product_code_id = pc.id
    LEFT JOIN (
        SELECT product_code_id, SUM(qty_remaining) AS qty_waiting_sx
        FROM production_progress
        WHERE status = 'in_progress'
        GROUP BY product_code_id
    ) progress ON progress.product_code_id = pc.id
    WHERE pc.is_active = 1
      AND (
        COALESCE(waiting.qty_waiting, 0) > 0
        OR COALESCE(defect.qty_defect, 0) > 0
        OR COALESCE(progress.qty_waiting_sx, 0) > 0
      )
    ORDER BY pc.product_code
");

$alerts = fetchAllSafe($pdo, "
    SELECT wi.receipt_no, wi.receipt_date, c.customer_name,
           COALESCE(SUM(wii.quantity), 0) AS total_qty
    FROM warehouse_in wi
    JOIN customers c ON c.id = wi.customer_id
    LEFT JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
    WHERE wi.receipt_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
      AND NOT EXISTS (
          SELECT 1
          FROM production_progress pp
          JOIN production_progress_logs ppl ON ppl.progress_id = pp.id
          WHERE pp.warehouse_in_id = wi.id
      )
    GROUP BY wi.id
    ORDER BY wi.receipt_date ASC, wi.id ASC
");

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Quản lý kho</h4>
            <p class="text-muted mb-0">Dashboard tổng hợp kho NVL, tiến độ sản xuất và kho thành phẩm</p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-primary"><?= number_format($totalRawReceived, 3) ?></div><div class="text-muted small">Tổng NVL đã nhận</div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-success"><?= number_format($totalDone, 3) ?></div><div class="text-muted small">Tổng đã gia công xong</div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-danger"><?= number_format($totalDefect, 3) ?></div><div class="text-muted small">Tổng hàng lỗi</div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-warning"><?= number_format($totalInStock, 3) ?></div><div class="text-muted small">Tổng còn trong kho TP</div></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-file-import me-2 text-info"></i>Hàng nhập hôm nay</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Phiếu nhập</th><th>Khách hàng</th><th class="text-end">SL</th></tr></thead>
                            <tbody>
                            <?php if (!$todayReceipts): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">Chưa có phiếu nhập hôm nay</td></tr>
                            <?php else: foreach ($todayReceipts as $row): ?>
                                <tr>
                                    <td><div class="fw-semibold text-primary"><?= e($row['receipt_no']) ?></div><div class="small text-muted"><?= formatDate($row['receipt_date']) ?> · <?= (int) $row['item_count'] ?> dòng</div></td>
                                    <td><?= e($row['customer_name']) ?></td>
                                    <td class="text-end fw-semibold"><?= number_format((float) $row['total_qty'], 3) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-truck me-2 text-success"></i>Đã giao hôm nay</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Phiếu giao</th><th>Khách hàng</th><th class="text-end">SL</th></tr></thead>
                            <tbody>
                            <?php if (!$todayDeliveries): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">Chưa có phiếu giao hôm nay</td></tr>
                            <?php else: foreach ($todayDeliveries as $row): ?>
                                <tr>
                                    <td><div class="fw-semibold text-primary"><?= e($row['delivery_no']) ?></div><div class="small text-muted"><?= (int) $row['item_count'] ?> dòng</div></td>
                                    <td><?= e($row['customer_name']) ?></td>
                                    <td class="text-end fw-semibold"><?= number_format((float) $row['total_qty'], 3) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="fas fa-table me-2 text-primary"></i>Bảng tồn kho theo mã SP</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Mã SP</th>
                            <th>Mô tả</th>
                            <th class="text-end">Hoàn thành chờ xuất</th>
                            <th class="text-end">Hàng lỗi</th>
                            <th class="text-end">Chờ SX</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$stockSummary): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Chưa có dữ liệu tồn kho mới</td></tr>
                    <?php else: foreach ($stockSummary as $row):
                        $status = 'Ổn định';
                        $badge = 'bg-success';
                        if ((float) $row['qty_waiting_sx'] > 0) { $status = 'Đang SX'; $badge = 'bg-warning text-dark'; }
                        elseif ((float) $row['qty_defect'] > 0) { $status = 'Có hàng lỗi'; $badge = 'bg-danger'; }
                        elseif ((float) $row['qty_waiting_export'] > 0) { $status = 'Chờ xuất'; $badge = 'bg-info'; }
                    ?>
                        <tr>
                            <td><span class="badge bg-primary"><?= e($row['product_code']) ?></span></td>
                            <td><?= e($row['description']) ?></td>
                            <td class="text-end text-success fw-semibold"><?= number_format((float) $row['qty_waiting_export'], 3) ?></td>
                            <td class="text-end text-danger fw-semibold"><?= number_format((float) $row['qty_defect'], 3) ?></td>
                            <td class="text-end text-warning fw-semibold"><?= number_format((float) $row['qty_waiting_sx'], 3) ?></td>
                            <td><span class="badge <?= $badge ?>"><?= e($status) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Cảnh báo phiếu nhập NVL > 3 ngày chưa có tiến độ</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Phiếu nhập</th><th>Khách hàng</th><th>Ngày nhập</th><th class="text-end">Tổng SL</th></tr></thead>
                    <tbody>
                    <?php if (!$alerts): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Không có cảnh báo</td></tr>
                    <?php else: foreach ($alerts as $row): ?>
                        <tr>
                            <td class="fw-semibold text-danger"><?= e($row['receipt_no']) ?></td>
                            <td><?= e($row['customer_name']) ?></td>
                            <td><?= formatDate($row['receipt_date']) ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float) $row['total_qty'], 3) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
