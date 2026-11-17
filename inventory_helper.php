<?php

function reserveProductStock(mysqli $conn, int $productId, ?int $variantId, int $quantity): bool
{
    if ($quantity < 1) {
        return false;
    }

    if ($variantId !== null) {
        $stmt = $conn->prepare(
            'UPDATE product_variants
             SET quantity = quantity - ?
             WHERE variant_id = ? AND product_id = ? AND is_active = 1 AND quantity >= ?'
        );
        $stmt->bind_param('iiii', $quantity, $variantId, $productId, $quantity);
    } else {
        $stmt = $conn->prepare(
            'UPDATE product
             SET quantity = quantity - ?
             WHERE product_id = ? AND quantity >= ?'
        );
        $stmt->bind_param('iii', $quantity, $productId, $quantity);
    }

    $success = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $success;
}

function releaseProductStock(mysqli $conn, int $productId, ?int $variantId, int $quantity): bool
{
    if ($productId < 1 || $quantity < 1) {
        return false;
    }

    if ($variantId !== null) {
        $stmt = $conn->prepare(
            'UPDATE product_variants
             SET quantity = quantity + ?
             WHERE variant_id = ? AND product_id = ?'
        );
        $stmt->bind_param('iii', $quantity, $variantId, $productId);
    } else {
        $stmt = $conn->prepare(
            'UPDATE product
             SET quantity = quantity + ?
             WHERE product_id = ?'
        );
        $stmt->bind_param('ii', $quantity, $productId);
    }

    $success = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    return $success;
}
