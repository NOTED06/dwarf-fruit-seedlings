<?php
function getCustomerDeliveryInfo(mysqli $conn, int $customerId): array
{
    $stmt = $conn->prepare('SELECT address FROM customer WHERE customer_id = ? LIMIT 1');
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $address = trim((string)($stmt->get_result()->fetch_assoc()['address'] ?? ''));
    $normalized = strtolower($address);

    if ($normalized === '') {
        $shippingFee = 120.00;
    } elseif (strpos($normalized, 'toril') !== false) {
        $shippingFee = 50.00;
    } elseif (strpos($normalized, 'davao') !== false) {
        $shippingFee = 80.00;
    } else {
        $shippingFee = 120.00;
    }

    return ['address' => $address, 'shipping_fee' => $shippingFee];
}
