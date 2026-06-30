<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$user = currentUser();

$filterCustomer = (int) ($_GET['customer_id'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));

$where = ['1=1'];
$params = [];
if ($filterCustomer > 0) {
    $where[] = 'pp.customer_id = ?';
    $params[] = $filterCustomer;
}
if (in_array($filterStatus, ['in_progress', 'completed'], true)) {
    $where[] = 'pp.status = ?';
    $params[] = $filterStatus;
}
if ($filterFrom !== '') {
    $where[] = 'DATE(pp.created_at) >= ?';
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = 'DATE(pp.created_at) <= ?';
    $params[] = $filterTo;
}

$progressRows = fetchAllSafe($pdo, "
    SELECT pp.*, wi.receipt_no, wi.receipt_date,
           c.customer_name, c.customer_code,
           pc.product_code, pc.description, pc.unit,
           COUNT(ppl.id) AS log_count
    FROM production_progress pp
    JOIN warehouse_in wi ON wi.id = pp.warehouse_in_id
    JOIN customers c ON c.id = pp.customer_id
    JOIN product_codes pc ON pc.id = pp.product_code_id
    LEFT JOIN production_progress_logs ppl ON ppl.progress_id = pp.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY pp.id
    ORDER BY pp.created_at DESC, pp.id DESC
", $params);

$customers = fetchAllSafe($pdo, "SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
$eligibleReceipts = fetchAllSafe($pdo, "
    SELECT wi.id, wi.receipt_no, wi.receipt_date, c.customer_name,
           COUNT(wii.id) AS item_count,
           SUM(CASE WHEN pp.id IS NULL THEN 1 ELSE 0 END) AS missing_count
    FROM warehouse_in wi
    JOIN customers c ON c.id = wi.customer_id
    JOIN warehouse_in_items wii ON wii.warehouse_in_id = wi.id
    LEFT JOIN production_progress pp
        ON pp.warehouse_in_id = wi.id
       AND pp.product_code_id = wii.product_code_id
    GROUP BY wi.id
    HAVING missing_count > 0
    ORDER BY wi.receipt_date DESC, wi.id DESC
");

$csrf = generateCSRF();
$canDeleteLogs = hasRole('director');
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-cogs me-2 text-primary"></i>Tiến độ gia công</h4>
            <p class="text-muted mb-0">Cộng dồn tiến độ theo từng lệnh sản xuất từ phiếu nhập NVL</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreateProgress">
            <i class="fas fa-plus me-1"></i>Tạo lệnh SX
        </button>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="GET">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Khách hàng</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="0">-- Tất cả --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id'] ?>" <?= $filterCustomer === (int) $customer['id'] ? 'selected' : '' ?>>[<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Trạng thái</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>Đang SX</option>
                        <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Hoàn thành</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Từ ngày</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Đến ngày</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button>
                    <a href="output.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Số lệnh</th>
                            <th>Phiếu nhập NVL</th>
                            <th>Khách hàng</th>
                            <th>Mã SP</th>
                            <th class="text-end">Tổng NVL</th>
                            <th class="text-end">Đã HT</th>
                            <th class="text-end">Lỗi</th>
                            <th class="text-end">Còn lại</th>
                            <th width="150">% hoàn thành</th>
                            <th>Trạng thái</th>
                            <th width="130">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$progressRows): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">Chưa có lệnh sản xuất</td></tr>
                    <?php else: foreach ($progressRows as $row):
                        $progressPercent = (float) $row['qty_total'] > 0 ? min(100, round((((float) $row['qty_done'] + (float) $row['qty_defect']) / (float) $row['qty_total']) * 100, 1)) : 0;
                        $statusBadge = $row['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark';
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-primary"><?= e($row['progress_no']) ?></div>
                                <div class="small text-muted"><?= (int) $row['log_count'] ?> logs</div>
                            </td>
                            <td><div class="fw-semibold"><?= e($row['receipt_no']) ?></div><div class="small text-muted"><?= formatDate($row['receipt_date']) ?></div></td>
                            <td><?= e($row['customer_name']) ?></td>
                            <td><span class="badge bg-primary"><?= e($row['product_code']) ?></span><div class="small text-muted"><?= e($row['description']) ?></div></td>
                            <td class="text-end"><?= number_format((float) $row['qty_total'], 3) ?></td>
                            <td class="text-end text-success fw-semibold"><?= number_format((float) $row['qty_done'], 3) ?></td>
                            <td class="text-end text-danger fw-semibold"><?= number_format((float) $row['qty_defect'], 3) ?></td>
                            <td class="text-end text-warning fw-semibold"><?= number_format((float) $row['qty_remaining'], 3) ?></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $progressPercent ?>%;"><?= $progressPercent ?>%</div>
                                </div>
                            </td>
                            <td><span class="badge <?= $statusBadge ?>"><?= $row['status'] === 'completed' ? 'Hoàn thành' : 'Đang SX' ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary btn-detail" data-id="<?= (int) $row['id'] ?>"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-outline-success btn-log" data-id="<?= (int) $row['id'] ?>" data-no="<?= e($row['progress_no']) ?>" data-remaining="<?= e($row['qty_remaining']) ?>" data-receipt="<?= e($row['receipt_no']) ?>" data-product="<?= e($row['product_code']) ?>"><i class="fas fa-plus"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="modalCreateProgress" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tạo lệnh SX</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCreateProgress">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Phiếu nhập NVL</label>
                        <select name="warehouse_in_id" class="form-select" required>
                            <option value="">-- Chọn phiếu còn thiếu lệnh SX --</option>
                            <?php foreach ($eligibleReceipts as $receipt): ?>
                                <option value="<?= (int) $receipt['id'] ?>"><?= e($receipt['receipt_no']) ?> - <?= e($receipt['customer_name']) ?> (thiếu <?= (int) $receipt['missing_count'] ?> mã)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="btnCreateProgress">Tạo lệnh</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProgressLog" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Cập nhật tiến độ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-3" id="logMeta"></div>
                <form id="formProgressLog">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="log">
                    <input type="hidden" name="progress_id" id="logProgressId">
                    <div class="mb-3"><label class="form-label">Ngày</label><input type="date" name="log_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label text-success">SL hoàn thành thêm</label><input type="number" step="0.001" min="0" name="qty_done" class="form-control" value="0"></div>
                        <div class="col-6"><label class="form-label text-danger">SL lỗi thêm</label><input type="number" step="0.001" min="0" name="qty_defect" class="form-control" value="0"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-success" id="btnSaveLog">Lưu tiến độ</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProgressDetail" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Chi tiết tiến độ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="progressDetailHeader" class="mb-3"></div>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">Lịch sử logs</h6>
                        <div id="progressLogs"></div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold">Kho thành phẩm phát sinh</h6>
                        <div id="progressFgs"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
const canDeleteLogs = <?= $canDeleteLogs ? 'true' : 'false' ?>;
const modalLog = new bootstrap.Modal(document.getElementById('modalProgressLog'));
const modalDetail = new bootstrap.Modal(document.getElementById('modalProgressDetail'));

function postForm(url, formData) {
    return fetch(url, { method: 'POST', body: formData }).then(r => r.json());
}

function fmtQty(value) {
    return Number(value || 0).toLocaleString('vi-VN', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
}

document.getElementById('btnCreateProgress').addEventListener('click', () => {
    const form = document.getElementById('formCreateProgress');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    postForm('/erp/api/production/save_progress.php', new FormData(form)).then(data => {
        if (!data.ok) return alert(data.msg);
        location.reload();
    });
});

document.querySelectorAll('.btn-log').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('logProgressId').value = btn.dataset.id;
        document.getElementById('logMeta').innerHTML = `<strong>${btn.dataset.no}</strong> · Phiếu ${btn.dataset.receipt} · Mã ${btn.dataset.product}<br>Còn lại hiện tại: <span class="text-warning fw-semibold">${fmtQty(btn.dataset.remaining)}</span>`;
        modalLog.show();
    });
});

document.getElementById('btnSaveLog').addEventListener('click', () => {
    const form = document.getElementById('formProgressLog');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    postForm('/erp/api/production/save_progress.php', new FormData(form)).then(data => {
        if (!data.ok) return alert(data.msg);
        location.reload();
    });
});

document.querySelectorAll('.btn-detail').forEach(btn => {
    btn.addEventListener('click', () => {
        fetch(`/erp/api/production/get_progress_detail.php?id=${btn.dataset.id}`)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return alert(data.msg);
                const h = data.header;
                const percent = Number(h.qty_total) > 0 ? Math.min(100, (((Number(h.qty_done) + Number(h.qty_defect)) / Number(h.qty_total)) * 100).toFixed(1)) : 0;
                document.getElementById('progressDetailHeader').innerHTML = `
                    <div class="row g-2 small">
                        <div class="col-md-6"><strong>${h.progress_no}</strong> · Phiếu ${h.receipt_no}<br>${h.customer_name} · <span class="badge bg-primary">${h.product_code}</span></div>
                        <div class="col-md-6 text-md-end">Tổng NVL: <strong>${fmtQty(h.qty_total)}</strong><br>Tiến độ: <strong>${percent}%</strong> · Còn lại: <strong class="text-warning">${fmtQty(h.qty_remaining)}</strong></div>
                    </div>`;

                document.getElementById('progressLogs').innerHTML = data.logs.length ? `<div class="list-group">${data.logs.map((log, index) => `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">${log.log_date}</div>
                                <div class="small text-success">HT: ${fmtQty(log.qty_done)} · Lỗi: ${fmtQty(log.qty_defect)}</div>
                                <div class="small text-muted">${log.note || '—'}</div>
                            </div>
                            ${canDeleteLogs && index === 0 ? `<button class="btn btn-sm btn-outline-danger btn-delete-log" data-id="${log.id}"><i class="fas fa-trash"></i></button>` : ''}
                        </div>
                    </div>`).join('')}</div>` : '<div class="text-muted small">Chưa có log nào.</div>';

                document.getElementById('progressFgs').innerHTML = data.fgs.length ? `<div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead><tr><th>FGS</th><th>Loại</th><th class="text-end">SL</th><th>Trạng thái</th></tr></thead><tbody>${data.fgs.map(item => `
                    <tr class="${item.type === 'defect' ? 'table-danger' : ''}">
                        <td>${item.fgs_no}<div class="small text-muted">${item.source_date}</div></td>
                        <td>${item.type === 'defect' ? '<span class="badge bg-danger">Lỗi</span>' : '<span class="badge bg-success">HT</span>'}</td>
                        <td class="text-end">${fmtQty(item.qty_in)}</td>
                        <td>${item.status}</td>
                    </tr>`).join('')}</tbody></table></div>` : '<div class="text-muted small">Chưa phát sinh kho TP.</div>';

                document.querySelectorAll('.btn-delete-log').forEach(deleteBtn => {
                    deleteBtn.addEventListener('click', () => {
                        if (!confirm('Chỉ có thể xoá log mới nhất chưa tạo kho TP. Tiếp tục?')) return;
                        const fd = new FormData();
                        fd.append('csrf_token', csrfToken);
                        fd.append('action', 'delete_log');
                        fd.append('log_id', deleteBtn.dataset.id);
                        postForm('/erp/api/production/save_progress.php', fd).then(resp => {
                            if (!resp.ok) return alert(resp.msg);
                            location.reload();
                        });
                    });
                });

                modalDetail.show();
            });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
