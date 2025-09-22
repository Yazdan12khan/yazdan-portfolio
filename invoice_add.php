<?php
require("./conn.php");
require("./business_header.php");
require("./business_function.php");

$user_id = $_SESSION['user_id'] ?? 0;

// Fetch companies
$companies = $conn->query("SELECT id, name FROM company ORDER BY name ASC");

// Fetch products
$products = $conn->query("SELECT id, name, purchase_price, unit FROM product ORDER BY name ASC");

// Fetch balances
$balances = getSourceBalances($conn, $user_id);

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = intval($_POST['company_id']);
    $invoice_number = trim($_POST['invoice_number']);
    $purchase_date = $_POST['purchase_date'];
    $advance_amount = floatval($_POST['advance_amount']);
    $advance_cash = floatval($_POST['advance_cash'] ?? 0);
    $advance_bank = floatval($_POST['advance_bank'] ?? 0);
    $advance_cheque = floatval($_POST['advance_cheque'] ?? 0);
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

    // Validate advance breakdown
    if (($advance_cash + $advance_bank + $advance_cheque) != $advance_amount) {
        echo "<script>alert('❌ Advance breakdown does not match total advance amount!'); window.history.back();</script>";
        exit;
    }

    // Check source balances
    if ($advance_cash > $balances['cash']) {
        echo "<script>alert('❌ Insufficient cash balance!'); window.history.back();</script>";
        exit;
    }
    if ($advance_bank > $balances['bank']) {
        echo "<script>alert('❌ Insufficient bank balance!'); window.history.back();</script>";
        exit;
    }
    if ($advance_cheque > $balances['cheque']) {
        echo "<script>alert('❌ Insufficient cheque balance!'); window.history.back();</script>";
        exit;
    }

    // Get company name
    $company_name_stmt = $conn->prepare("SELECT name FROM company WHERE id = ?");
    $company_name_stmt->bind_param("i", $company_id);
    $company_name_stmt->execute();
    $company_result = $company_name_stmt->get_result();
    $company_row = $company_result->fetch_assoc();
    $company_name = $company_row['name'] ?? 'Unknown';

    // Insert invoice
    $stmt = $conn->prepare("INSERT INTO purchase_invoices 
        (user_id, company_id, invoice_number, purchase_date, total_amount, advance_amount, pending_amount) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissddd", $user_id, $company_id, $invoice_number, $purchase_date, $total_amount, $advance_amount, $pending_amount);
    $stmt->execute();
    $invoice_id = $stmt->insert_id;
    $stmt->close();

    // Insert each product
    foreach ($product_ids as $i => $pid) {
        $qty = intval($quantities[$i]);
        $price = floatval($prices[$i]);
        $total = $qty * $price;

        $stmt_item = $conn->prepare("INSERT INTO invoice_item (user_id, invoice_id, product_id, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_item->bind_param("iiiidd", $user_id, $invoice_id, $pid, $qty, $price, $total);
        $stmt_item->execute();
        $stmt_item->close();
    }

    // Update or insert stock
    foreach ($product_ids as $i => $pid) {
        $qty = intval($quantities[$i]);

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

    // Update company credit
    $update_company = $conn->prepare("UPDATE company SET total_credit = total_credit + ? WHERE id = ?");
    $update_company->bind_param("di", $pending_amount, $company_id);
    $update_company->execute();

    // Record each source advance as expense
    $sources = ['cash' => $advance_cash, 'bank' => $advance_bank, 'cheque' => $advance_cheque];
    foreach ($sources as $source => $amount) {
        if ($amount > 0) {
            $notes = "Advance for Invoice #$invoice_number to $company_name";
            $insert_expense = $conn->prepare("INSERT INTO business_expense (user_id, amount, source, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
            $insert_expense->bind_param("idss", $user_id, $amount, $source, $notes);
            $insert_expense->execute();
        }
    }

    echo "<script>alert('✅ Invoice Created & Advance Deducted'); window.location.href = 'invoice_show.php';</script>";
    exit;
}
?>

<style>
    body { background-color: #1a1a1a; color: white; }
    .card { background-color: #2a2a2a; border: none; }
    .table th, .table td { color: white; }
    input, select { background-color: #333; color: white; border: none; }
</style>

<div class="container py-4">
    <h2 class="mb-4 text-center">📦 New Purchase Invoice</h2>

    <div class="alert alert-info">
        💰 <strong>Balances:</strong> 
        Cash: <?= number_format($balances['cash'], 2) ?> |
        Bank: <?= number_format($balances['bank'], 2) ?> |
        Cheque: <?= number_format($balances['cheque'], 2) ?>
    </div>

    <form method="POST">
        <div class="card p-4 mb-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select" required>
                        <option value="">Select Company</option>
                        <?php while ($c = $companies->fetch_assoc()): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Invoice Number</label>
                    <input type="text" name="invoice_number" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control" required>
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
                    <tr>
                        <td>
                            <select name="product_id[]" class="form-select productSelect" required>
                                <option value="">Select Product</option>
                                <?php
                                $products->data_seek(0);
                                while ($p = $products->fetch_assoc()): ?>
                                    <option value="<?= $p['id'] ?>"
                                        data-price="<?= $p['purchase_price'] ?>"
                                        data-unit="<?= htmlspecialchars($p['unit']) ?>">
                                        <?= htmlspecialchars($p['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </td>
                        <td><input type="text" name="unit[]" class="form-control unit"></td>
                        <td><input type="number" name="quantity[]" class="form-control qty" min="1" value="1"></td>
                        <td><input type="number" name="price[]" class="form-control price" step="0.01" value="0"></td>
                        <td class="total">0.00</td>
                        <td><button type="button" class="btn btn-danger btn-sm removeRow">X</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" id="addRow" class="btn btn-primary">+ Add Product</button>
        </div>

        <div class="card p-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <label>Advance Amount</label>
                    <input type="number" step="0.01" name="advance_amount" id="advance" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label>Cash</label>
                    <input type="number" step="0.01" name="advance_cash" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label>Bank</label>
                    <input type="number" step="0.01" name="advance_bank" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label>Cheque</label>
                    <input type="number" step="0.01" name="advance_cheque" class="form-control" value="0">
                </div>
            </div>
            <div class="row g-3 mt-3">
                <div class="col-md-6">
                    <label>Total Amount</label>
                    <input type="text" id="grandTotal" class="form-control" readonly>
                </div>
                <div class="col-md-6">
                    <label>Pending Amount</label>
                    <input type="text" id="pending" class="form-control" readonly>
                </div>
            </div>
        </div>

        <button class="btn btn-success btn-lg mt-4">Save Invoice</button>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).on('change', '.productSelect', function () {
        let price = $(this).find(':selected').data('price') || 0;
        let unit = $(this).find(':selected').data('unit') || '';
        let row = $(this).closest('tr');
        row.find('.price').val(price);
        row.find('.unit').val(unit);
        calculateTotals();
    });

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

    $(document).on('input', '.qty, .price, #advance', calculateTotals);

    $('#addRow').click(function () {
        let row = $('#productRows tr:first').clone();
        row.find('input').val('');
        row.find('select').val('');
        row.find('.total').text('0.00');
        $('#productRows').append(row);
    });

    $(document).on('click', '.removeRow', function () {
        if ($('#productRows tr').length > 1) {
            $(this).closest('tr').remove();
            calculateTotals();
        }
    });

    calculateTotals();
</script>

<?php require("./business_footer.php"); ?>