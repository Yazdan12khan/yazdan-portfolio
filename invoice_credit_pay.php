<?php
require("./conn.php");
require("./business_header.php");
require("./business_function.php");

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    echo "<div class='alert alert-danger'>Please login first.</div>";
    exit;
}

// Get the user's balances (sources like wallet, bank, etc.)
$balances = getSourceBalances($conn, $user_id);

// Get unpaid invoices for the user
$sql = "
    SELECT pi.id, pi.invoice_number, pi.pending_amount, pi.purchase_date, c.name AS company_name, pi.company_id
    FROM purchase_invoices pi
    INNER JOIN company c ON pi.company_id = c.id
    WHERE pi.user_id = ? AND pi.pending_amount > 0
    ORDER BY pi.purchase_date DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
?>

<style>
    body {
        background-color: #1e1e1e;
        color: white;
    }

    .card {
        background-color: #2c2c2c;
        border: none;
    }

    .btn-pay {
        background-color: #28a745;
        color: white;
    }

    .modal-content {
        background-color: #1a1a1a;
        color: white;
    }

    select, input {
        background-color: #333;
        color: white;
    }
</style>

<div class="container py-4">
    <h3 class="text-center mb-4">🧾 Pay Pending Invoices</h3>

    <?php if ($res->num_rows === 0): ?>
        <div class="alert alert-success text-center">🎉 All invoices are fully paid!</div>
    <?php else: ?>
        <table class="table table-dark table-bordered text-center align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Invoice #</th>
                    <th>Company</th>
                    <th>Date</th>
                    <th>Pending Amount</th>
                    <th>Pay</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; while ($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($row['invoice_number']) ?></td>
                        <td><?= htmlspecialchars($row['company_name']) ?></td>
                        <td><?= date('d-M-Y', strtotime($row['purchase_date'])) ?></td>
                        <td><strong><?= number_format($row['pending_amount'], 2) ?></strong></td>
                        <td>
                            <button class="btn btn-pay btn-sm"
                                    data-id="<?= $row['id'] ?>"
                                    data-invoice="<?= $row['invoice_number'] ?>"
                                    data-company="<?= htmlspecialchars($row['company_name']) ?>"
                                    data-company-id="<?= $row['company_id'] ?>"
                                    data-amount="<?= $row['pending_amount'] ?>"
                                    onclick="openModal(this)">
                                💸 Pay
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="invoice_id" id="modalInvoiceId">
            <input type="hidden" name="invoice_number" id="modalInvoiceNumber">
            <input type="hidden" name="company_name" id="modalCompanyName">
            <input type="hidden" name="company_id" id="modalCompanyId">
            <input type="hidden" name="amount" id="modalAmount">
            <div class="modal-header">
                <h5 class="modal-title">Pay Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Invoice:</strong> <span id="modalDisplayInvoice"></span></p>
                <p><strong>Company:</strong> <span id="modalDisplayCompany"></span></p>
                <p><strong>Amount:</strong> <span id="modalDisplayAmount"></span></p>

                <label>Enter Payment Amount(s):</label>
                <div id="paymentSources">
                    <div class="payment-source">
                        <select name="sources[]" class="form-select source-select" required>
                            <?php foreach ($balances as $src => $bal): ?>
                                <option value="<?= $src ?>"><?= ucfirst($src) ?> (<?= number_format($bal, 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="amounts[]" class="form-control payment-amount" min="0" max="" placeholder="Amount" required>
                    </div>
                </div>

                <button type="button" class="btn btn-sm btn-primary mt-3" id="addSource">+ Add Another Source</button>

            </div>
            <div class="modal-footer">
                <button class="btn btn-success">✅ Confirm Payment</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">❌ Cancel</button>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
    function openModal(btn) {
        const id = btn.dataset.id;
        const invoice = btn.dataset.invoice;
        const company = btn.dataset.company;
        const companyId = btn.dataset.companyId;
        const amount = btn.dataset.amount;

        document.getElementById("modalInvoiceId").value = id;
        document.getElementById("modalInvoiceNumber").value = invoice;
        document.getElementById("modalCompanyName").value = company;
        document.getElementById("modalCompanyId").value = companyId;
        document.getElementById("modalAmount").value = amount;

        document.getElementById("modalDisplayInvoice").innerText = invoice;
        document.getElementById("modalDisplayCompany").innerText = company;
        document.getElementById("modalDisplayAmount").innerText = amount;

        // Set max payment amount to pending amount
        document.querySelectorAll(".payment-amount").forEach(function(input) {
            input.max = amount;
        });

        new bootstrap.Modal(document.getElementById("payModal")).show();
    }

    // Adding new payment source field dynamically
    document.getElementById('addSource').addEventListener('click', function() {
        const sourceField = document.createElement('div');
        sourceField.classList.add('payment-source');
        sourceField.innerHTML = `
            <select name="sources[]" class="form-select source-select" required>
                <?php foreach ($balances as $src => $bal): ?>
                    <option value="<?= $src ?>"><?= ucfirst($src) ?> (<?= number_format($bal, 2) ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="amounts[]" class="form-control payment-amount" min="0" max="" placeholder="Amount" required>
        `;
        document.getElementById('paymentSources').appendChild(sourceField);
    });
</script>

<?php
require("./business_footer.php");

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = intval($_POST['invoice_id']);
    $invoice_number = $_POST['invoice_number'];
    $company_name = $_POST['company_name'];
    $company_id = intval($_POST['company_id']);
    $amount = floatval($_POST['amount']);
    $sources = $_POST['sources']; // Array of source names
    $amounts = $_POST['amounts']; // Array of corresponding amounts to be paid

    // Ensure the total payment amount doesn't exceed the invoice amount
    $total_payment = array_sum($amounts);
    if ($total_payment > $amount) {
        echo "<script>alert('❌ Total payment amount cannot exceed the pending invoice amount.'); window.location.href='invoice_credit_pay.php';</script>";
        exit;
    }

    // Get the user's source balances
    $balances = getSourceBalances($conn, $user_id);

    // Check if the user has enough balance in each selected source
    foreach ($sources as $index => $source) {
        $payment_amount = floatval($amounts[$index]);
        if ($balances[$source] < $payment_amount) {
            echo "<script>alert('❌ Insufficient balance in $source. Payment failed.'); window.location.href='invoice_credit_pay.php';</script>";
            exit;
        }
    }

    // Update invoice: set pending_amount = pending_amount - total_payment
    $stmt = $conn->prepare("UPDATE purchase_invoices SET pending_amount = pending_amount - ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("dii", $total_payment, $invoice_id, $user_id);
    $stmt->execute();

    // Add business_expense for each source
    foreach ($sources as $index => $source) {
        $payment_amount = floatval($amounts[$index]);
        $note = "Paid $payment_amount for Invoice #$invoice_number to $company_name using $source";
        
        $stmt_exp = $conn->prepare("INSERT INTO business_expense (user_id, amount, source, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt_exp->bind_param("idss", $user_id, $payment_amount, $source, $note);
        $stmt_exp->execute();
    }

    // Update Company credit: decrease by the total payment amount
    $stmt_credit = $conn->prepare("UPDATE company SET total_credit = total_credit - ? WHERE id = ?");
    $stmt_credit->bind_param("di", $total_payment, $company_id);
    $stmt_credit->execute();

    echo "<script>alert('✅ Invoice paid successfully! Expense recorded and company credit updated.'); window.location.href='invoice_show.php';</script>";
    exit;
}
?>
