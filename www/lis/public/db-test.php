<?php
declare(strict_types=1);

// Zugangsdaten liegen ausserhalb des DocumentRoot
$config = require __DIR__ . '/../config/db.php';

try {
    $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $benutzer = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    $version  = $pdo->query('SELECT VERSION()')->fetchColumn();
    $zeilen   = $pdo->query('SELECT id, text, erstellt FROM notiz ORDER BY id')->fetchAll();
} catch (PDOException $e) {
    // Details nur ins Log, nie an den Browser
    error_log('DB-Test: ' . $e->getMessage());
    http_response_code(500);
    exit('Datenbankverbindung fehlgeschlagen. Details im Error-Log.');
}

// Text sicher für HTML aufbereiten
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>DB-Test – lis.lab.test</title>
</head>
<body>
    <h1>DB-Test</h1>
    <p>Verbunden als <strong><?= h($benutzer) ?></strong>, Server <?= h($version) ?></p>
    <table border="1" cellpadding="4">
        <tr><th>id</th><th>text</th><th>erstellt</th></tr>
<?php foreach ($zeilen as $z): ?>
        <tr>
            <td><?= h((string) $z['id']) ?></td>
            <td><?= h($z['text']) ?></td>
            <td><?= h($z['erstellt']) ?></td>
        </tr>
<?php endforeach; ?>
    </table>
</body>
</html>
