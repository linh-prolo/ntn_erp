<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();

$receivedToday = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(wii.quantity), 0)
    FROM warehouse_in_items wii
    JOIN warehouse_in wi ON wi.id = wii.warehouse_in_id
    WHERE wi.receipt_date = CURDATE()
", [], 0);

$outputToday = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(qty_done), 0)
    FROM production_progress_logs
    WHERE log_date = CURDATE()
", [], 0);

$deliveredMonth = (float) fetchScalarSafe($pdo, "
    SELECT COALESCE(SUM(di.quantity), 0)
    FROM deliveries d
    JOIN delivery_items di ON di.delivery_id = d.id
    WHERE DATE_FORMAT(d.delivery_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
      AND d.status IN ('confirmed', 'invoiced')
", [], 0);

$activeProducts = (int) fetchScalarSafe($pdo, "
    SELECT COUNT(DISTINCT product_code_id)
    FROM production_progress
    WHERE status = 'in_progress'
", [], 0);

$inProgressRows = fetchAllSafe($pdo, "
    SELECT pp.*, wi.receipt_no, wi.receipt_date,
           c.customer_name, pc.product_code, pc.description, pc.unit
    FROM production_progress pp
    JOIN warehouse_in wi ON wi.id = pp.warehouse_in_id
    JOIN customers c ON c.id = pp.customer_id
    JOIN product_codes pc ON pc.id = pp.product_code_id
    WHERE pp.status = 'in_progress'
    ORDER BY wi.receipt_date DESC, pp.id DESC
");

$recentOutput = fetchAllSafe($pdo, "
    SELECT ppl.log_date, pc.product_code,
           SUM(ppl.qty_done) AS qty_done,
           SUM(ppl.qty_defect) AS qty_defect
    FROM production_progress_logs ppl
    JOIN production_progress pp ON pp.id = ppl.progress_id
    JOIN product_codes pc ON pc.id = pp.product_code_id
    WHERE ppl.log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY ppl.log_date, pp.product_code_id
    ORDER BY ppl.log_date DESC, pc.product_code
");

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1"><i class="fas fa-industry me-2 text-primary"></i>Tổng quan sản xuất</h4>
        <p class="text-muted mb-0">Theo dõi toàn bộ luồng kho sản xuất mới</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-info"><?= number_format($receivedToday, 3) ?></div><div class="text-muted small">Nhận từ kho hôm nay</div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-success"><?= number_format($outputToday, 3) ?></div><div class="text-muted small">Output OK hôm nay</div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-primary"><?= number_format($deliveredMonth, 3) ?></div><div class="text-muted small">Giao hàng tháng này</div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-warning"><?= number_format($activeProducts) ?></div><div class="text-muted small">Mã SP đang SX</div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="fas fa-cogs me-2 text-warning"></i>Đang trong SX</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark"><tr><th>Mã SP</th><th>Phiếu nhập</th><th class="text-end">Tổng NVL</th><th class="text-end">Đã HT</th><th class="text-end">Lỗi</th><th class="text-end">Còn lại</th><th width="180">% tiến độ</th></tr></thead>
                    <tbody>
                    <?php if (!$inProgressRows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Không có lệnh SX đang chạy</td></tr>
                    <?php else: foreach ($inProgressRows as $row):
                        $percent = (float) $row['qty_total'] > 0 ? min(100, round(((float) $row['qty_done'] + (float) $row['qty_defect']) / (float) $row['qty_total'] * 100, 1)) : 0;
                    ?>
                        <tr>
                            <td><span class="badge bg-primary"><?= e($row['product_code']) ?></span><div class="small text-muted"><?= e($row['description']) ?></div></td>
                            <td><div class="fw-semibold text-primary"><?= e($row['receipt_no']) ?></div><div class="small text-muted"><?= e($row['customer_name']) ?></div></td>
                            <td class="text-end"><?= number_format((float) $row['qty_total'], 3) ?></td>
                            <td class="text-end text-success fw-semibold"><?= number_format((float) $row['qty_done'], 3) ?></td>
                            <td class="text-end text-danger fw-semibold"><?= number_format((float) $row['qty_defect'], 3) ?></td>
                            <td class="text-end text-warning fw-semibold"><?= number_format((float) $row['qty_remaining'], 3) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $percent ?>%;" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"><?= $percent ?>%</div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="fas fa-chart-line me-2 text-success"></i>Output 7 ngày gần nhất</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Ngày</th><th>Mã SP</th><th class="text-end">Hoàn thành</th><th class="text-end">Lỗi</th><th class="text-end">Tỷ lệ OK</th></tr></thead>
                    <tbody>
                    <?php if (!$recentOutput): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Chưa có log sản xuất</td></tr>
                    <?php else: foreach ($recentOutput as $row):
                        $total = (float) $row['qty_done'] + (float) $row['qty_defect'];
                        $rate = $total > 0 ? round((float) $row['qty_done'] / $total * 100, 1) : 0;
                        $badge = $rate >= 95 ? 'bg-success' : ($rate >= 80 ? 'bg-warning text-dark' : 'bg-danger');
                    ?>
                        <tr>
                            <td><?= formatDate($row['log_date']) ?></td>
                            <td><span class="badge bg-primary"><?= e($row['product_code']) ?></span></td>
                            <td class="text-end text-success fw-semibold"><?= number_format((float) $row['qty_done'], 3) ?></td>
                            <td class="text-end text-danger fw-semibold"><?= number_format((float) $row['qty_defect'], 3) ?></td>
                            <td class="text-end"><span class="badge <?= $badge ?>"><?= $rate ?>%</span></td>
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
