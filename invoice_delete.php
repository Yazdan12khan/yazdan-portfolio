<?php
session_start();
require("./conn.php");


if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Invoice ID not provided");
}

$invoice_id = intval($_GET['id']);

// 1️⃣ Invoice ka data le aao (amount, company_id, invoice_number)
$sql_invoice = "SELECT company_id, pending_amount, invoice_number FROM purchase_invoices WHERE id = ?";
$stmt = $conn->prepare($sql_invoice);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$res_invoice = $stmt->get_result();

if ($res_invoice->num_rows === 0) {
    die("Invoice not found");
}

$invoice = $res_invoice->fetch_assoc();
$invoice_amount = floatval($invoice['pending_amount']);
$company_id = intval($invoice['company_id']);
$invoice_number = $invoice['invoice_number'];

// Company name bhi le aao for matching expense notes
$company_name = '';
$stmt_company = $conn->prepare("SELECT name FROM company WHERE id = ?");
$stmt_company->bind_param("i", $company_id);
$stmt_company->execute();
$result_company = $stmt_company->get_result();
if ($row = $result_company->fetch_assoc()) {
    $company_name = $row['name'];
}

// Confirmation popup
if (!isset($_GET['confirm']) || $_GET['confirm'] != 1) {
    echo "<script>
        if(confirm('⚠ Ye invoice delete hoga, company ka credit update hoga, stock quantity kam hogi, aur expense record bhi delete hoga. Kya aap sure hain?')) {
            window.location.href = 'invoice_delete.php?id={$invoice_id}&confirm=1';
        } else {
            window.location.href = 'invoice_show.php';
        }
    </script>";
    exit;
}

// 2️⃣ Company ke `total_credit` se sirf ye invoice ka pending minus karo
$sql_credit = "UPDATE company 
               SET total_credit = CASE 
                   WHEN total_credit >= ? THEN total_credit - ? 
                   ELSE 0 
               END 
               WHERE id = ?";
$stmt_credit = $conn->prepare($sql_credit);
$stmt_credit->bind_param("ddi", $invoice_amount, $invoice_amount, $company_id);
$stmt_credit->execute();

// 3️⃣ Stock quantity adjust karo
$sql_items = "SELECT product_id, quantity FROM invoice_item WHERE invoice_id = ?";
$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$res_items = $stmt_items->get_result();

while ($item = $res_items->fetch_assoc()) {
    $product_id = intval($item['product_id']);
    $qty = intval($item['quantity']);

    $sql_stock = "UPDATE stock SET quantity = quantity - ? WHERE product_id = ?";
    $stmt_stock = $conn->prepare($sql_stock);
    $stmt_stock->bind_param("ii", $qty, $product_id);
    $stmt_stock->execute();
}

// 4️⃣ Invoice items delete karo
$sql_del_items = "DELETE FROM invoice_item WHERE invoice_id = ?";
$stmt_del_items = $conn->prepare($sql_del_items);
$stmt_del_items->bind_param("i", $invoice_id);
$stmt_del_items->execute();

// 5️⃣ Invoice delete karo
$sql_del_invoice = "DELETE FROM purchase_invoices WHERE id = ?";
$stmt_del_invoice = $conn->prepare($sql_del_invoice);
$stmt_del_invoice->bind_param("i", $invoice_id);
$stmt_del_invoice->execute();

// 6️⃣ Business Expense bhi delete karo
$notes_like = "Advance for Invoice #$invoice_number to $company_name";
$sql_delete_exp = "DELETE FROM business_expense WHERE user_id = ? AND notes = ?";
$stmt_exp = $conn->prepare($sql_delete_exp);
$stmt_exp->bind_param("is", $_SESSION['user_id'], $notes_like);
$stmt_exp->execute();

// ✅ Done
echo "<script>
    alert('✅ Invoice, items, stock, aur related expense successfully delete ho gaye. Company ka credit update ho gaya.');
    window.location.href = 'invoice_show.php';
</script>";
