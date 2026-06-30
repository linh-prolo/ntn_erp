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
if ($filterCustomer > 0) { $where[] = 'se.customer_id = ?'; $params[] = $filterCustomer; }
if (in_array($filterStatus, ['draft', 'confirmed'], true)) { $where[] = 'se.status = ?'; $params[] = $filterStatus; }
if ($filterFrom !== '') { $where[] = 'se.export_date >= ?'; $params[] = $filterFrom; }
if ($filterTo !== '') { $where[] = 'se.export_date <= ?'; $params[] = $filterTo; }

$rows = fetchAllSafe($pdo, "
    SELECT se.*, c.customer_name, c.customer_code, u.full_name AS created_by_name,
           COUNT(sei.id) AS item_count,
           COALESCE(SUM(sei.qty_export), 0) AS total_qty
    FROM stock_exports se
    JOIN customers c ON c.id = se.customer_id
    LEFT JOIN users u ON u.id = se.created_by
    LEFT JOIN stock_export_items sei ON sei.export_id = se.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY se.id
    ORDER BY se.export_date DESC, se.id DESC
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
            <h4 class="mb-1"><i class="fas fa-file-export me-2 text-primary"></i>Xuất kho</h4>
            <p class="text-muted mb-0">Lập phiếu xuất từ kho thành phẩm chờ xuất hoặc xuất một phần</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalExport"><i class="fas fa-plus me-1"></i>Tạo phiếu xuất</button>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3"><label class="form-label small text-muted">Khách hàng</label><select name="customer_id" class="form-select form-select-sm"><option value="0">-- Tất cả --</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>" <?= $filterCustomer === (int) $customer['id'] ? 'selected' : '' ?>>[<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Trạng thái</label><select name="status" class="form-select form-select-sm"><option value="">-- Tất cả --</option><option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Nháp</option><option value="confirmed" <?= $filterStatus === 'confirmed' ? 'selected' : '' ?>>Đã xác nhận</option></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Từ ngày</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($filterFrom) ?>"></div>
            <div class="col-md-2"><label class="form-label small text-muted">Đến ngày</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($filterTo) ?>"></div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button><a href="export.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
    </div></div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark"><tr><th>Số phiếu</th><th>Ngày xuất</th><th>Khách hàng</th><th class="text-center">Số dòng SP</th><th class="text-end">Tổng SL</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu xuất kho</td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr>
                            <td><div class="fw-semibold text-primary"><?= e($row['export_no']) ?></div><div class="small text-muted"><?= e($row['created_by_name'] ?? '—') ?></div></td>
                            <td><?= formatDate($row['export_date']) ?></td>
                            <td><?php if ($row['customer_code']): ?><span class="badge bg-secondary me-1"><?= e($row['customer_code']) ?></span><?php endif; ?><?= e($row['customer_name']) ?></td>
                            <td class="text-center"><?= (int) $row['item_count'] ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float) $row['total_qty'], 3) ?></td>
                            <td><span class="badge <?= $row['status'] === 'confirmed' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $row['status'] === 'confirmed' ? 'Đã xác nhận' : 'Nháp' ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary btn-detail" data-id="<?= (int) $row['id'] ?>"><i class="fas fa-eye"></i></button>
                                <?php if ($row['status'] === 'draft'): ?>
                                    <button class="btn btn-sm btn-outline-success btn-confirm" data-id="<?= (int) $row['id'] ?>"><i class="fas fa-check"></i></button>
                                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= (int) $row['id'] ?>"><i class="fas fa-trash"></i></button>
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

<div class="modal fade" id="modalExport" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-file-export me-2"></i>Tạo phiếu xuất kho</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="formExport">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><label class="form-label">Ngày xuất</label><input type="date" name="export_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Khách hàng</label><select name="customer_id" id="exportCustomer" class="form-select" required><option value="">-- Chọn khách hàng --</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>">[<?= e($customer['customer_code']) ?>] <?= e($customer['customer_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-5"><label class="form-label">Ghi chú</label><input type="text" name="note" class="form-control" placeholder="Ghi chú phiếu xuất"></div>
                    </div>
                    <div id="fgsHelp" class="small text-muted mb-2">Chọn khách hàng để tải danh sách kho thành phẩm có thể xuất.</div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light"><tr><th>FGS</th><th>Mã SP</th><th>Loại</th><th class="text-end">Tồn còn lại</th><th width="180">SL xuất</th></tr></thead>
                            <tbody id="fgsTableBody"></tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning" id="btnSaveDraft"><i class="fas fa-save me-1"></i>Lưu nháp</button>
                <button type="button" class="btn btn-success" id="btnSaveConfirm"><i class="fas fa-check me-1"></i>Xác nhận xuất</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalExportDetail" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-eye me-2"></i>Chi tiết phiếu xuất</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div id="exportDetailHeader" class="mb-3"></div>
                <div id="exportDetailItems"></div>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode($csrf) ?>;
const detailModal = new bootstrap.Modal(document.getElementById('modalExportDetail'));

function fmtQty(value) { return Number(value || 0).toLocaleString('vi-VN', { minimumFractionDigits: 0, maximumFractionDigits: 3 }); }
function postForm(url, formData) { return fetch(url, { method: 'POST', body: formData }).then(r => r.json()); }

function buildExportItems(items) {
    const body = document.getElementById('fgsTableBody');
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Không có hàng chờ xuất cho khách hàng này</td></tr>';
        return;
    }
    body.innerHTML = items.map(item => `
        <tr class="${item.type === 'defect' ? 'table-danger' : ''}">
            <td>${item.fgs_no}<div class="small text-muted">${item.source_date}</div></td>
            <td><span class="badge bg-primary">${item.product_code}</span><div class="small text-muted">${item.description}</div></td>
            <td>${item.type === 'defect' ? '<span class="badge bg-danger">Lỗi</span>' : '<span class="badge bg-success">HT</span>'}</td>
            <td class="text-end fw-semibold">${fmtQty(item.qty_remaining)}</td>
            <td>
                <input type="hidden" class="fgs-id" value="${item.id}">
                <input type="hidden" class="product-id" value="${item.product_code_id}">
                <input type="number" step="0.001" min="0" max="${item.qty_remaining}" class="form-control qty-export" value="0">
            </td>
        </tr>`).join('');
}

document.getElementById('exportCustomer').addEventListener('change', function () {
    const customerId = this.value;
    if (!customerId) return buildExportItems([]);
    fetch(`/erp/api/warehouse/get_fgs_by_customer.php?customer_id=${customerId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return alert(data.msg);
            buildExportItems(data.items || []);
        });
});

function collectExportForm(confirmNow) {
    const form = document.getElementById('formExport');
    if (!form.checkValidity()) { form.reportValidity(); return null; }
    const fd = new FormData(form);
    fd.append('confirm_now', confirmNow ? '1' : '0');
    let idx = 0;
    document.querySelectorAll('#fgsTableBody tr').forEach(tr => {
        const qtyInput = tr.querySelector('.qty-export');
        if (!qtyInput) return;
        const qty = Number(qtyInput.value || 0);
        const max = Number(qtyInput.getAttribute('max') || 0);
        if (qty <= 0) return;
        if (qty > max) { throw new Error('SL xuất không được vượt tồn còn lại'); }
        fd.append(`items[${idx}][fgs_id]`, tr.querySelector('.fgs-id').value);
        fd.append(`items[${idx}][product_code_id]`, tr.querySelector('.product-id').value);
        fd.append(`items[${idx}][qty_export]`, qty.toString());
        idx += 1;
    });
    if (idx === 0) throw new Error('Phải nhập ít nhất 1 dòng xuất kho');
    return fd;
}

document.getElementById('btnSaveDraft').addEventListener('click', () => {
    try {
        const fd = collectExportForm(false);
        if (!fd) return;
        postForm('/erp/api/warehouse/save_stock_export.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    } catch (error) { alert(error.message); }
});

document.getElementById('btnSaveConfirm').addEventListener('click', () => {
    try {
        const fd = collectExportForm(true);
        if (!fd) return;
        postForm('/erp/api/warehouse/save_stock_export.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    } catch (error) { alert(error.message); }
});

document.querySelectorAll('.btn-detail').forEach(btn => {
    btn.addEventListener('click', () => {
        fetch(`/erp/api/warehouse/get_stock_export_detail.php?id=${btn.dataset.id}`)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return alert(data.msg);
                document.getElementById('exportDetailHeader').innerHTML = `<div class="small"><strong>${data.header.export_no}</strong> · ${data.header.customer_name}<br>Ngày xuất: ${data.header.export_date} · Trạng thái: ${data.header.status}</div>`;
                document.getElementById('exportDetailItems').innerHTML = `<div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead><tr><th>FGS</th><th>Mã SP</th><th>Loại</th><th class="text-end">SL xuất</th></tr></thead><tbody>${data.items.map(item => `
                    <tr class="${item.type === 'defect' ? 'table-danger' : ''}">
                        <td>${item.fgs_no}</td>
                        <td>${item.product_code}</td>
                        <td>${item.type}</td>
                        <td class="text-end">${fmtQty(item.qty_export)}</td>
                    </tr>`).join('')}</tbody></table></div>`;
                detailModal.show();
            });
    });
});

document.querySelectorAll('.btn-confirm').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Xác nhận xuất kho cho phiếu này?')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'confirm');
        fd.append('id', btn.dataset.id);
        postForm('/erp/api/warehouse/save_stock_export.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    });
});

document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Xoá phiếu xuất nháp này?')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'delete');
        fd.append('id', btn.dataset.id);
        postForm('/erp/api/warehouse/save_stock_export.php', fd).then(data => {
            if (!data.ok) return alert(data.msg);
            location.reload();
        });
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
