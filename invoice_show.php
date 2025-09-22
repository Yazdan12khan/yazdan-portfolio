<?php
require("./conn.php");
require("./business_header.php");

$user_id = $_SESSION['user_id'] ?? 0;

// Fetch all invoices for current user
$sql = "SELECT pi.*, c.name AS company_name 
        FROM purchase_invoices pi
        LEFT JOIN company c ON pi.company_id = c.id
        WHERE pi.user_id = ?
        ORDER BY pi.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
?>

<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4 border border-dark">
        <h4 class="text-white text-center mb-4">🧾 Purchase Invoice List</h4>

        <?php if ($res): ?>
            <div class="table-responsive">
                <table class="table table-bordered text-white text-center align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Company</th>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Advance</th>
                            <th>Pending</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 1;
                        if ($res->num_rows > 0) {
                            while ($row = $res->fetch_assoc()) {
                        ?>
                                <tr>
                                    <td><?= $i++ ?></td>
                                    <td><?= htmlspecialchars($row['company_name']) ?></td>
                                    <td><?= htmlspecialchars($row['invoice_number']) ?></td>
                                    <td><?= date('d-M-Y', strtotime($row['purchase_date'])) ?></td>
                                    <td><?= number_format($row['total_amount'], 2) ?></td>
                                    <td><?= number_format($row['advance_amount'], 2) ?></td>
                                    <td class="<?= $row['pending_amount'] > 0 ? 'text-warning' : 'text-success' ?>">
                                        <?= number_format($row['pending_amount'], 2) ?>
                                    </td>
                                    <td>
                                        <!-- View -->
                                        <a href="./invoice_view.php?id=<?= $row['id'] ?>">
                                            <script src="https://cdn.lordicon.com/lordicon.js"></script>
                                            <lord-icon
                                                src="https://cdn.lordicon.com/nocovwne.json"
                                                trigger="hover"
                                                colors="primary:#ffffff,secondary:#ffffff"
                                                style="width:30px;height:40px">
                                            </lord-icon>
                                        </a>

                                        <a href="./invoice_credit_pay.php?id=<?= $row['id'] ?>">Pay</a>



                                        <!-- Edit -->
                                        <a href="./invoice_update.php?id=<?= $row['id'] ?>">
                                            <script src="https://cdn.lordicon.com/lordicon.js"></script>
                                            <lord-icon
                                                src="https://cdn.lordicon.com/exymduqj.json"
                                                trigger="hover"
                                                colors="primary:#ffffff,secondary:#ffffff"
                                                style="width:30px;height:40px">
                                            </lord-icon>
                                        </a>

                                        <!-- Delete -->
                                        <a href="./invoice_delete.php?id=<?= $row['id'] ?>">
                                            <script src="https://cdn.lordicon.com/lordicon.js"></script>
                                            <lord-icon
                                                src="https://cdn.lordicon.com/hwjcdycb.json"
                                                trigger="hover"
                                                colors="primary:#ffffff,secondary:#ffffff"
                                                style="width:30px;height:40px">
                                            </lord-icon>
                                        </a>
                                    </td>
                                </tr>
                        <?php
                            }
                        } else {
                            echo '<tr><td colspan="8" class="text-warning">No invoices found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <!-- Add Invoice Button -->
                <a href="./invoice_add.php">
                    <script src="https://cdn.lordicon.com/lordicon.js"></script>
                    <lord-icon
                        src="https://cdn.lordicon.com/sbnjyzil.json"
                        trigger="hover"
                        colors="primary:#ffffff,secondary:#ffffff"
                        style="width:70px;height:60px">
                    </lord-icon>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>

<?php require("./business_footer.php"); ?>