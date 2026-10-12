<?php
declare(strict_types=1);

// Password and token helpers. Keep these in one place so login and reset stay in sync.

function hash_password(string $password): string
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException("Unable to hash password.");
    }
    return $hash;
}

function verify_password(string $password, string $stored): bool
{
    if ((password_get_info($stored)["algoName"] ?? "unknown") !== "unknown") {
        return password_verify($password, $stored);
    }

    // Keep existing installations usable while legacy SHA-256 hashes are
    // upgraded after the user's next successful sign-in.
    return preg_match('/\A[0-9a-f]{64}\z/', $stored) === 1
        && hash_equals($stored, hash("sha256", $password));
}

function password_hash_needs_upgrade(string $stored): bool
{
    return (password_get_info($stored)["algoName"] ?? "unknown") === "unknown"
        || password_needs_rehash($stored, PASSWORD_DEFAULT);
}

function issue_reset_token(string $email): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash("sha256", $token);
    $pdo = db();
    $pdo->exec("DELETE FROM reset_tokens WHERE created_at < datetime('now', '-1 hour')");
    $pdo->prepare("DELETE FROM reset_tokens WHERE email = ?")->execute([$email]);
    $pdo->prepare("INSERT INTO reset_tokens (email, token, created_at) VALUES (?, ?, datetime('now'))")
        ->execute([$email, $tokenHash]);
    return $token;
}

function consume_reset_token(string $email, string $token): bool
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT token, created_at FROM reset_tokens WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $createdAt = strtotime((string)$row["created_at"] . " UTC");
    if ($createdAt === false || $createdAt < time() - 3600) {
        $pdo->prepare("DELETE FROM reset_tokens WHERE email = ?")->execute([$email]);
        return false;
    }

    $submittedHash = hash("sha256", $token);
    if (!hash_equals((string)$row["token"], $submittedHash)) {
        return false;
    }

    // Include the hash in the DELETE so simultaneous submissions cannot both
    // consume the same token successfully.
    $delete = $pdo->prepare("DELETE FROM reset_tokens WHERE email = ? AND token = ?");
    $delete->execute([$email, (string)$row["token"]]);
    return $delete->rowCount() === 1;
}
