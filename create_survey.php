<?php
require __DIR__ . '/private/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}

$question = trim($_POST['question'] ?? '');
$choicesRaw = trim($_POST['choices'] ?? '');
$expectedVotes = (int) ($_POST['expected_votes'] ?? 0);
$email = trim($_POST['email'] ?? '');

$errors = [];

if ($question === '') {
    $errors[] = 'Die Frage darf nicht leer sein.';
}

$choices = array_filter(
    array_map('trim', preg_split('/\R+/', $choicesRaw)),
    fn($c) => $c !== ''
);

if (count($choices) < 2) {
    $errors[] = 'Bitte mindestens zwei Antwortoptionen angeben.';
}

if ($expectedVotes < 1) {
    $errors[] = 'Die erwartete Anzahl an Stimmen muss mindestens 1 sein.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Bitte eine gueltige E-Mail-Adresse angeben.';
}

if ($errors) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Fehler beim Anlegen</title>
        <link rel="stylesheet" href="styles.css">
    </head>
    <body>
    <main class="page stack">
        <a class="button button-secondary" href="create.php">Zurueck zum Formular</a>
        <section class="card">
            <h1>Fehler beim Anlegen der Umfrage</h1>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </main>
    </body>
    </html>
    <?php
    exit;
}

function generatePublicId(): string
{
    return bin2hex(random_bytes(8));
}

function generateDeleteToken(string $publicId, string $secret): string
{
    return hash_hmac('sha256', 'delete-survey:' . $publicId, $secret);
}

$pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);

$publicId = generatePublicId();
$now = new DateTimeImmutable('now');
$expiresAt = $now->modify('+72 hours');

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare('
        INSERT INTO surveys (public_id, question, expected_votes, creator_email, pin_hash, created_at, expires_at)
        VALUES (:public_id, :question, :expected_votes, :creator_email, :pin_hash, :created_at, :expires_at)
    ');
    $stmt->execute([
        ':public_id' => $publicId,
        ':question' => $question,
        ':expected_votes' => $expectedVotes,
        ':creator_email' => $email,
        ':pin_hash' => $pinHash,
        ':created_at' => $now->format('Y-m-d H:i:s'),
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    $surveyId = (int) $pdo->lastInsertId();

    $stmtChoice = $pdo->prepare('INSERT INTO choices (survey_id, choice_text) VALUES (:survey_id, :choice_text)');
    foreach ($choices as $choice) {
        $stmtChoice->execute([
            ':survey_id' => $surveyId,
            ':choice_text' => $choice,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    die('Fehler beim Speichern der Umfrage: ' . htmlspecialchars($e->getMessage()));
}

$surveyUrl = $baseUrl . '/survey.php?sid=' . urlencode($publicId);
$deleteToken = generateDeleteToken($publicId, $adminPassword);
$deleteUrl = $baseUrl . '/delete_survey.php?sid=' . urlencode($publicId) . '&token=' . urlencode($deleteToken);

$subject = 'Deine Umfrage wurde erstellt';
$message =
    "Hallo,\n\n" .
    "deine Umfrage wurde erfolgreich angelegt.\n\n" .
    "Frage: " . $question . "\n" .
    "Umfragelink: " . $surveyUrl . "\n" .
    "Loeschlink (einzigartig): " . $deleteUrl . "\n" .
    "Nach dem Oeffnen muss die Loeschung noch einmal bestaetigt werden.\n" .
    "PIN zum Entsperren: " . $pin . "\n" .
    "Gueltig bis: " . $expiresAt->format('Y-m-d H:i:s') . "\n\n" .
    "Bitte bewahre diese E-Mail gut auf.\n";

$headers =
    'From: ' . $fromEmail . "\r\n" .
    'Reply-To: ' . $fromEmail . "\r\n" .
    'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
    'X-Mailer: PHP/' . phpversion();

@mail($email, $subject, $message, $headers);
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Umfrage erstellt</title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
<main class="page stack">
    <section class="card reveal">
        <h1>Umfrage erfolgreich erstellt</h1>

        <p><strong>Umfragelink (an Teilnehmende weitergeben):</strong></p>
        <p><a href="<?php echo htmlspecialchars($surveyUrl); ?>"><?php echo htmlspecialchars($surveyUrl); ?></a></p>

        <p><strong>PIN (4-stellig, zum Entsperren der Umfrage):</strong></p>
        <p><?php echo htmlspecialchars($pin); ?></p>

        <p><strong>Einzigartiger Loeschlink:</strong></p>
        <p><a href="<?php echo htmlspecialchars($deleteUrl); ?>"><?php echo htmlspecialchars($deleteUrl); ?></a></p>
        <p class="muted">Nach dem Oeffnen muss die Loeschung noch einmal bestaetigt werden.</p>

        <p class="muted">Bitte speichere den PIN sicher. Er wird aus Sicherheitsgruenden nur hier im Klartext angezeigt.</p>

        <div class="actions sticky-mobile">
            <a class="button button-primary" href="create.php">Weitere Umfrage anlegen</a>
            <a class="button button-secondary" href="index.php">Zur Uebersicht aller laufenden Umfragen</a>
        </div>
    </section>
</main>
</body>

</html>
