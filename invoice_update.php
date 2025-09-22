<?php
require("./business_header.php");

$user_id = $_SESSION['user_id'] ?? 0;
$invoice_id = intval($_GET['id'] ?? 0);

if (!$invoice_id) {
    die("❌ Invalid Invoice ID.");
}

// Fetch invoice
$invoice_stmt = $conn->prepare("SELECT * FROM purchase_invoices WHERE id = ? AND user_id = ?");
$invoice_stmt->bind_param("ii", $invoice_id, $user_id);
$invoice_stmt->execute();
$invoice = $invoice_stmt->get_result()->fetch_assoc();
$invoice_stmt->close();

if (!$invoice) {
    die("❌ Invoice not found.");
}

// Fetch invoice items
$item_stmt = $conn->prepare("SELECT * FROM invoice_item WHERE invoice_id = ? AND user_id = ?");
$item_stmt->bind_param("ii", $invoice_id, $user_id);
$item_stmt->execute();
$items_result = $item_stmt->get_result();
$invoice_items = [];
while ($row = $items_result->fetch_assoc()) {
    $invoice_items[] = $row;
}
$item_stmt->close();

// Fetch companies and products
$companies = $conn->query("SELECT id, name FROM company ORDER BY name ASC");
$products_res = $conn->query("SELECT id, name, purchase_price, unit FROM product ORDER BY name ASC");
$products = [];
while ($row = $products_res->fetch_assoc()) {
    $products[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = intval($_POST['company_id']);
    $invoice_number = trim($_POST['invoice_number']);
    $purchase_date = $_POST['purchase_date'];
    $advance_amount = floatval($_POST['advance_amount']);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];

    $total_amount = 0;
    foreach ($product_ids as $i => $pid) {
        $qty = intval($quantities[$i]);
        $price = floatval($prices[$i]);
        $total_amount += $qty * $price;
    }

    $pending_amount = $total_amount - $advance_amount;

    // Rollback old stock
    foreach ($invoice_items as $old_item) {
        $product_id = $old_item['product_id'];
        $old_qty = $old_item['quantity'];
        $conn->query("UPDATE stock SET quantity = quantity - $old_qty WHERE user_id = $user_id AND product_id = $product_id");
    }

    // Update invoice
    $stmt = $conn->prepare("UPDATE purchase_invoices SET 
        company_id = ?, invoice_number = ?, purchase_date = ?, 
        total_amount = ?, advance_amount = ?, pending_amount = ? 
        WHERE id = ? AND user_id = ?");
    $stmt->bind_param("issddiii", $company_id, $invoice_number, $purchase_date, $total_amount, $advance_amount, $pending_amount, $invoice_id, $user_id);
    $stmt->execute();
    $stmt->close();

    // Delete old items
    $conn->query("DELETE FROM invoice_item WHERE invoice_id = $invoice_id AND user_id = $user_id");

    // Insert new items
    foreach ($product_ids as $i => $pid) {
        $qty = intval($quantities[$i]);
        $price = floatval($prices[$i]);
        $total = $qty * $price;

        $stmt_item = $conn->prepare("INSERT INTO invoice_item (user_id, invoice_id, product_id, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_item->bind_param("iiiidd", $user_id, $invoice_id, $pid, $qty, $price, $total);
        $stmt_item->execute();
        $stmt_item->close();

        // Update stock
        $check_stock = $conn->prepare("SELECT id, quantity FROM stock WHERE user_id = ? AND product_id = ?");
        $check_stock->bind_param("ii", $user_id, $pid);
        $check_stock->execute();
        $stock_result = $check_stock->get_result();

        if ($stock_result->num_rows > 0) {
            $stock_row = $stock_result->fetch_assoc();
            $new_qty = $stock_row['quantity'] + $qty;

            $update_stock = $conn->prepare("UPDATE stock SET quantity = ?, note = ?, created_at = NOW() WHERE id = ?");
            $update_stock->bind_param("isi", $new_qty, $invoice_number, $stock_row['id']);
            $update_stock->execute();
        } else {
            $insert_stock = $conn->prepare("INSERT INTO stock (user_id, product_id, quantity, note, created_at) VALUES (?, ?, ?, ?, NOW())");
            $insert_stock->bind_param("iiis", $user_id, $pid, $qty, $invoice_number);
            $insert_stock->execute();
        }
    }

    // Adjust company credit
    $old_pending = $invoice['pending_amount'];
    $diff_credit = $pending_amount - $old_pending;

    $update_company = $conn->prepare("UPDATE company SET total_credit = total_credit + ? WHERE id = ?");
    $update_company->bind_param("di", $diff_credit, $company_id);
    $update_company->execute();

    echo "<script>
        alert('✅ Purchase Invoice Updated Successfully!');
        window.location.href = 'invoice_show.php';
    </script>";
    exit;
}
?>

<style>
    body {
        background-color: #1a1a1a;
        color: white;
    }

    .card {
        background-color: #2a2a2a;
        border: none;
    }

    .table th,
    .table td {
        color: white;
    }

    input,
    select {
        background-color: #333;
        color: white;
        border: none;
    }
</style>

<div class="container py-4">
    <h2 class="mb-4 text-center">✏️ Update Purchase Invoice</h2>

    <form method="POST">
        <div class="card p-4 mb-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select" required>
                        <option value="">Select Company</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $invoice['company_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" name="invoice_number" class="form-control" required value="<?= htmlspecialchars($invoice['invoice_number']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control" required value="<?= $invoice['purchase_date'] ?>">
                </div>
            </div>
        </div>

        <div class="card p-4 mb-4">
            <h5>Products</h5>
            <table class="table table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th width="80">Unit</th>
                        <th width="80">Qty</th>
                        <th width="140">Price</th>
                        <th width="140">Total</th>
                        <th width="50"></th>
                    </tr>
                </thead>
                <tbody id="productRows">
                    <!-- Filled by JS -->
                </tbody>
            </table>
            <button type="button" id="addRow" class="btn btn-primary">+ Add Product</button>
        </div>

        <div class="card p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Advance Amount</label>
                    <input type="number" name="advance_amount" id="advance" class="form-control" step="0.01" value="<?= $invoice['advance_amount'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Total Amount</label>
                    <input type="text" id="grandTotal" class="form-control" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pending Amount</label>
                    <input type="text" id="pending" class="form-control" readonly>
                </div>
            </div>
        </div>

        <button class="btn btn-success btn-lg mt-4">💾 Update Invoice</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const products = <?= json_encode($products) ?>;
const invoiceItems = <?= json_encode($invoice_items) ?>;

function renderRows() {
    $('#productRows').html('');
    invoiceItems.forEach(item => {
        addRow(item.product_id, item.quantity, item.price);
    });
}

function addRow(productId = '', qty = 1, price = 0) {
    let productOptions = '<option value="">Select Product</option>';
    products.forEach(p => {
        productOptions += `<option value="${p.id}" data-unit="${p.unit}" data-price="${p.purchase_price}" ${p.id == productId ? 'selected' : ''}>
            ${p.name}
        </option>`;
    });

    let selectedProduct = products.find(p => p.id == productId) || {};
    let unit = selectedProduct.unit || '';
    let total = (qty * price).toFixed(2);

    let row = `
    <tr>
        <td>
            <select name="product_id[]" class="form-select productSelect">${productOptions}</select>
        </td>
        <td><input type="text" name="unit[]" class="form-control unit" value="${unit}"></td>
        <td><input type="number" name="quantity[]" class="form-control qty" value="${qty}" min="1"></td>
        <td><input type="number" name="price[]" class="form-control price" step="0.01" value="${price}"></td>
        <td class="total">${total}</td>
        <td><button type="button" class="btn btn-danger btn-sm removeRow">X</button></td>
    </tr>
    `;
    $('#productRows').append(row);
    calculateTotals();
}

function calculateTotals() {
    let grandTotal = 0;
    $('#productRows tr').each(function () {
        let qty = parseFloat($(this).find('.qty').val()) || 0;
        let price = parseFloat($(this).find('.price').val()) || 0;
        let total = qty * price;
        $(this).find('.total').text(total.toFixed(2));
        grandTotal += total;
    });
    $('#grandTotal').val(grandTotal.toFixed(2));
    let advance = parseFloat($('#advance').val()) || 0;
    $('#pending').val((grandTotal - advance).toFixed(2));
}

$(document).on('change', '.productSelect', function () {
    let price = $(this).find(':selected').data('price') || 0;
    let unit = $(this).find(':selected').data('unit') || '';
    let row = $(this).closest('tr');
    row.find('.price').val(price);
    row.find('.unit').val(unit);
    calculateTotals();
});

$(document).on('input', '.qty, .price, #advance', calculateTotals);

$('#addRow').click(function () {
    addRow();
});

$(document).on('click', '.removeRow', function () {
    if ($('#productRows tr').length > 1) {
        $(this).closest('tr').remove();
        calculateTotals();
    }
});

renderRows();
</script>

<?php require("./business_footer.php"); ?>
