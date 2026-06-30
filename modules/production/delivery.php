<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');

$pdo = getDBConnection();
$filterCustomer = (int) ($_GET['customer_id'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo = trim((string) ($_GET['to'] ?? ''));

$where = ['1=1'];
$params = [];
if ($filterCustomer > 0) { $where[] = 'd.customer_id = ?'; $params[] = $filterCustomer; }
if (in_array($filterStatus, ['draft', 'confirmed', 'invoiced'], true)) { $where[] = 'd.status = ?'; $params[] = $filterStatus; }
if ($filterFrom !== '') { $where[] = 'd.delivery_date >= ?'; $params[] = $filterFrom; }
if ($filterTo !== '') { $where[] = 'd.delivery_date <= ?'; $params[] = $filterTo; }

$rows = fetchAllSafe($pdo, "
    SELECT d.*, c.customer_name, c.customer_code,
           COUNT(di.id) AS item_count,
           COALESCE(SUM(di.quantity), 0) AS total_qty
    FROM deliveries d
    JOIN customers c ON c.id = d.customer_id
    LEFT JOIN delivery_items di ON di.delivery_id = d.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY d.id
    ORDER BY d.delivery_date DESC, d.id DESC
", $params);

$customers = fetchAllSafe($pdo, "SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-truck me-2 text-primary"></i>Giao hàng</h4>
            <p class="text-muted mb-0">Tạo biên bản giao hàng trực tiếp từ các dòng xuất kho đã xác nhận và chưa giao</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDelivery"><i class="fas fa-plus me-1"></i>Tạo biên bản</button>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3"><label class="form-label small text-muted">Khách hàng</label><select name="customer_id" class="form-select form-select-sm"><option value="0">-- Tất cả --</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>" <?= $filterCustomer === (int) $customer['id'] ? 'selected' : '' ?>>[<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Trạng thái</label><select name="status" class="form-select form-select-sm"><option value="">-- Tất cả --</option><option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Nháp</option><option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Đã xác nhận</option></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Từ ngày</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>"></div>
            <div class="col-md-2"><label class="form-label small text-muted">Đến ngày</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button><a href="delivery.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
    </div></div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark"><tr><th>Số biên bản</th><th>Ngày giao</th><th>Khách hàng</th><th class="text-center">Số dòng SP</th><th class="text-end">Tổng SL</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Chưa có biên bản giao hàng</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td><div class="fw-semibold text-primary"><?= e($row['delivery_no']) ?></div><div class="small text-muted"><?= e($row['note'] ?? '—') ?></div></td>
                            <td><?= formatDate($row['delivery_date']) ?></td>
                            <td><?php if ($row['customer_code']): ?><span class="badge bg-secondary me-1"><?= e($row['customer_code']) ?></span><?php endif; ?><?= e($row['customer_name']) ?></td>
                            <td class="text-center"><?= (int) $row['item_count'] ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float) $row['total_qty'], 3) ?></td>
                            <td><span class="badge <?= $row['status'] === 'confirmed' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $row['status'] === 'confirmed' ? 'Đã xác nhận' : 'Nháp' ?></span></td>
                            <td>
                                <?php if ($row['status'] === 'draft'): ?>
                                    <button class="btn btn-sm btn-outline-success btn-confirm" data-id="<?= (int) $row['id'] ?>"><i class="fas fa-check"></i></button>
                                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= (int) $row['id'] ?>"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                    <span class="text-success small"><i class="fas fa-lock me-1"></i>Đã khoá</span>
                                <?php endif; ?>
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

<div class="modal fade" id="modalDelivery" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-truck me-2"></i>Tạo biên bản giao hàng</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="formDelivery">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><label class="form-label">Ngày giao</label><input type="date" name="delivery_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Khách hàng</label><select name="customer_id" id="deliveryCustomer" class="form-select" required><option value="">-- Chọn khách hàng --</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>">[<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-5"><label class="form-label">Ghi chú</label><input type="text" name="note" class="form-control" placeholder="Ghi chú giao hàng"></div>
                    </div>
                    <div id="deliveryHelp" class="small text-muted mb-2">Chọn khách hàng để xem các dòng xuất kho đã xác nhận và chưa giao.</div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light"><tr><th width="50"></th><th>Phiếu xuất</th><th>Mã SP</th><th>Loại</th><th class="text-end">SL giao</th></tr></thead>
                            <tbody id="deliveryItemsBody"></tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning" id="btnDeliveryDraft"><i class="fas fa-save me-1"></i>Lưu nháp</button>
                <button type="button" class="btn btn-success" id="btnDeliveryConfirm"><i class="fas fa-check me-1"></i>Xác nhận giao</button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
function postForm(url, formData) { return fetch(url, { method: 'POST', body: formData }).then(r => r.json()); }
function fmtQty(value) { return Number(value || 0).toLocaleString('vi-VN', { minimumFractionDigits: 0, maximumFractionDigits: 3 }); }
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function renderExportableItems(items) {
    const body = document.getElementById('deliveryItemsBody');
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Không còn dòng xuất kho nào chờ giao</td></tr>';
        return;
    }
    body.innerHTML = items.map(item => `
        <tr class="${item.fgs_type === 'defect' ? 'table-danger' : ''}">
            <td class="text-center"><input type="checkbox" class="form-check-input export-item-check" value="${item.export_item_id}" aria-label="Select export item ${esc(item.export_no)}"></td>
            <td>${esc(item.export_no)}</td>
            <td><span class="badge bg-primary">${esc(item.product_code)}</span><div class="small text-muted">${esc(item.description)}</div></td>
            <td>${item.fgs_type === 'defect' ? '<span class="badge bg-danger">Lỗi</span>' : '<span class="badge bg-success">HT</span>'}</td>
            <td class="text-end fw-semibold">${fmtQty(item.qty_export)}</td>
        </tr>`).join('');
}

document.getElementById('deliveryCustomer').addEventListener('change', function () {
    const customerId = this.value;
    if (!customerId) return renderExportableItems([]);
    fetch(`/erp/api/production/get_exportable_items.php?customer_id=${customerId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return alert(data.msg);
            renderExportableItems(data.items || []);
        });
});

function collectDeliveryForm(confirmNow) {
    const form = document.getElementById('formDelivery');
    if (!form.checkValidity()) { form.reportValidity(); return null; }
    const fd = new FormData(form);
    fd.append('confirm_now', confirmNow ? '1' : '0');
    let selected = 0;
    document.querySelectorAll('.export-item-check:checked').forEach((input, index) => {
        fd.append(`export_item_ids[${index}]`, input.value);
        selected += 1;
    });
    if (!selected) throw new Error('Phải chọn ít nhất 1 dòng xuất kho');
    return fd;
}

document.getElementById('btnDeliveryDraft').addEventListener('click', () => {
    try {
        const fd = collectDeliveryForm(false);
        if (!fd) return;
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    } catch (error) { alert(error.message); }
});

document.getElementById('btnDeliveryConfirm').addEventListener('click', () => {
    try {
        const fd = collectDeliveryForm(true);
        if (!fd) return;
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    } catch (error) { alert(error.message); }
});

document.querySelectorAll('.btn-confirm').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Xác nhận biên bản giao hàng này?')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'confirm');
        fd.append('id', btn.dataset.id);
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    });
});

document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Xoá biên bản giao hàng nháp này?')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);
        postForm('/erp/api/production/save_delivery_v2.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
