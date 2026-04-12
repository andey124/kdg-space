<?php
require __DIR__ . '/private/config.php';

function generateDeleteToken(string $publicId, string $secret): string
{
    return hash_hmac('sha256', 'delete-survey:' . $publicId, $secret);
}

$publicId = $_GET['sid'] ?? '';
$publicId = preg_replace('/[^a-f0-9]/', '', $publicId);

$token = $_GET['token'] ?? '';
$token = preg_replace('/[^a-f0-9]/', '', $token);

if ($publicId === '' || $token === '') {
    http_response_code(400);
    die('Ungültiger Löschlink.');
}

$expectedToken = generateDeleteToken($publicId, $adminPassword);
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    die('Ungültiger oder abgelaufener Löschlink.');
}

$stmt = $pdo->prepare('SELECT id, question FROM surveys WHERE public_id = :public_id');
$stmt->execute([':public_id' => $publicId]);
$survey = $stmt->fetch();

if (!$survey) {
    http_response_code(404);
    die('Umfrage nicht gefunden oder bereits gelöscht.');
}

$deleteError = null;
$wasDeleted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('DELETE FROM votes WHERE survey_id = :survey_id');
        $stmt->execute([':survey_id' => $survey['id']]);

        $stmt = $pdo->prepare('DELETE FROM choices WHERE survey_id = :survey_id');
        $stmt->execute([':survey_id' => $survey['id']]);

        $stmt = $pdo->prepare('DELETE FROM surveys WHERE id = :id');
        $stmt->execute([':id' => $survey['id']]);

        $pdo->commit();
        $wasDeleted = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $deleteError = 'Löschen fehlgeschlagen: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <title>Umfrage löschen</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 2rem;
            max-width: 700px;
        }

        .panel {
            border: 1px solid #ddd;
            padding: 1rem;
            border-radius: 8px;
            background: #fafafa;
        }

        .danger {
            background: #a00;
            color: #fff;
            border: 0;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            cursor: pointer;
        }

        .muted {
            color: #555;
        }

        .error {
            color: darkred;
            margin-top: 1rem;
        }
    </style>
</head>

<body>
    <?php if ($wasDeleted): ?>
        <h1>Umfrage wurde gelöscht</h1>
        <p>Die Umfrage wurde erfolgreich entfernt.</p>
        <p><a href="index.php">Zur Übersicht</a></p>
    <?php else: ?>
        <h1>Umfrage löschen</h1>
        <div class="panel">
            <p>Du bist dabei, folgende Umfrage zu löschen:</p>
            <p><strong><?php echo htmlspecialchars($survey['question']); ?></strong></p>
            <p class="muted">Dieser Vorgang kann nicht rückgängig gemacht werden.</p>

            <form method="post" action="delete_survey.php?sid=<?php echo urlencode($publicId); ?>&token=<?php echo urlencode($token); ?>">
                <input type="hidden" name="confirm_delete" value="1">
                <button class="danger" type="submit">Ja, sicher löschen</button>
                <a href="index.php" style="margin-left: 0.8rem;">Abbrechen</a>
            </form>

            <?php if ($deleteError): ?>
                <div class="error"><?php echo htmlspecialchars($deleteError); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>

</html>
