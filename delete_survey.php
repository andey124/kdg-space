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
    die('Ungueltiger Loeschlink.');
}

$expectedToken = generateDeleteToken($publicId, $adminPassword);
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    die('Ungueltiger oder abgelaufener Loeschlink.');
}

$stmt = $pdo->prepare('SELECT id, question FROM surveys WHERE public_id = :public_id');
$stmt->execute([':public_id' => $publicId]);
$survey = $stmt->fetch();

if (!$survey) {
    http_response_code(404);
    die('Umfrage nicht gefunden oder bereits geloescht.');
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
        $deleteError = 'Loeschen fehlgeschlagen: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Umfrage loeschen</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
<main class="page stack">
    <?php if ($wasDeleted): ?>
        <section class="card reveal">
            <h1>Umfrage wurde geloescht</h1>
            <p class="notice notice-success">Die Umfrage wurde erfolgreich entfernt.</p>
            <a class="button button-secondary" href="index.php">Zur Uebersicht</a>
        </section>
    <?php else: ?>
        <section class="card reveal">
            <h1>Umfrage loeschen</h1>
            <p>Du bist dabei, folgende Umfrage zu loeschen:</p>
            <h2><?php echo htmlspecialchars($survey['question']); ?></h2>
            <p class="notice notice-error">Dieser Vorgang kann nicht rueckgaengig gemacht werden.</p>

            <form method="post" action="delete_survey.php?sid=<?php echo urlencode($publicId); ?>&token=<?php echo urlencode($token); ?>" class="actions sticky-mobile">
                <input type="hidden" name="confirm_delete" value="1">
                <button class="button-danger" type="submit">Ja, sicher loeschen</button>
                <a class="button button-secondary" href="index.php">Abbrechen</a>
            </form>

            <?php if ($deleteError): ?>
                <p class="notice notice-error"><?php echo htmlspecialchars($deleteError); ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>

</html>
