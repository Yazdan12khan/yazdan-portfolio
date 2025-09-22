<?php
require("./conn.php");
require("./business_header.php");

$user_id = $_SESSION['user_id'] ?? 0;
$invoice_id = intval($_GET['id'] ?? 0);

// Check login
if (!$user_id) {
    echo "<div class='alert alert-danger'>Please login to view invoice.</div>";
    exit;
}

// Fetch invoice info with company name
$sql_invoice = "
    SELECT pi.invoice_number, pi.purchase_date, c.name AS company_name
    FROM purchase_invoices pi
    LEFT JOIN company c ON pi.company_id = c.id
    WHERE pi.id = ? AND pi.user_id = ?
";
$stmt_invoice = $conn->prepare($sql_invoice);
$stmt_invoice->bind_param("ii", $invoice_id, $user_id);
$stmt_invoice->execute();
$res_invoice = $stmt_invoice->get_result();
$invoice_info = $res_invoice->fetch_assoc();

// If invoice not found
if (!$invoice_info) {
    echo "<div class='alert alert-danger text-center'>❌ Invoice not found or access denied.</div>";
    exit;
}

// Fetch invoice items for this user
$sql = "
    SELECT pr.name AS product_name, pr.purchase_price, ii.quantity
    FROM invoice_item ii
    INNER JOIN product pr ON ii.product_id = pr.id
    WHERE ii.invoice_id = ? AND ii.user_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $invoice_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();
?>

<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4 border border-dark">
        <h4 class="text-white text-center mb-4">
            🧾 Invoice #<?= htmlspecialchars($invoice_info['invoice_number']) ?>
        </h4>
        <p class="text-white text-center mb-3">
            <strong>Company:</strong> <?= htmlspecialchars($invoice_info['company_name']) ?> |
            <strong>Date:</strong> <?= date('d-M-Y', strtotime($invoice_info['purchase_date'])) ?>
        </p>

        <div class="table-responsive">
            <table class="table table-bordered text-white text-center align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Purchase Price</th>
                        <th>Quantity</th>
                        <th>Total Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    $grand_total = 0;
                    if ($res->num_rows > 0) {
                        while ($row = $res->fetch_assoc()) {
                            $total = $row['purchase_price'] * $row['quantity'];
                            $grand_total += $total;
                            echo "<tr>
                                <td>{$i}</td>
                                <td>" . htmlspecialchars($row['product_name']) . "</td>
                                <td>" . number_format($row['purchase_price'], 2) . "</td>
                                <td>{$row['quantity']}</td>
                                <td>" . number_format($total, 2) . "</td>
                            </tr>";
                            $i++;
                        }
                        echo "<tr class='table-dark'>
                                <td colspan='4'><strong>Grand Total</strong></td>
                                <td><strong>" . number_format($grand_total, 2) . "</strong></td>
                              </tr>";
                    } else {
                        echo "<tr><td colspan='5' class='text-warning'>No items found for this invoice.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
</div>

<?php require("./business_footer.php"); ?>