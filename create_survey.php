<?php
// create_survey.php
require __DIR__ . 'private/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}

$question = trim($_POST['question'] ?? '');
$choicesRaw = trim($_POST['choices'] ?? '');
$expectedVotes = (int)($_POST['expected_votes'] ?? 0);
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
    $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
}

if ($errors) {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Fehler</title></head><body>';
    echo '<h1>Fehler beim Anlegen der Umfrage</h1><ul>';
    foreach ($errors as $e) {
        echo '<li>' . htmlspecialchars($e) . '</li>';
    }
    echo '</ul><p><a href="index.php">Zurück</a></p></body></html>';
    exit;
}

// public_id erzeugen
function generatePublicId(): string {
    return bin2hex(random_bytes(8));
}

// 4-stelliger PIN
$pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

// PIN hashen (bcrypt/DEFAULT)
$pinHash = password_hash($pin, PASSWORD_DEFAULT); // [web:9]

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

    $surveyId = (int)$pdo->lastInsertId();

    $stmtChoice = $pdo->prepare('
        INSERT INTO choices (survey_id, choice_text) VALUES (:survey_id, :choice_text)
    ');
    foreach ($choices as $choice) {
        $stmtChoice->execute([
            ':survey_id' => $surveyId,
            ':choice_text' => $choice,
        ]);
    }

    $pdo->commit();
    $surveyUrl = $baseUrl . '/survey.php?sid=' . urlencode($publicId);

    $subject = 'Deine Umfrage wurde erstellt';
    $message =
    "Hallo,\n\n" .
    "deine Umfrage wurde erfolgreich angelegt.\n\n" .
    "Frage: " . $question . "\n" .
    "Umfragelink: " . $surveyUrl . "\n" .
    "PIN zum Entsperren: " . $pin . "\n" .
    "Gültig bis: " . $expiresAt->format('Y-m-d H:i:s') . "\n\n" .
    "Bitte bewahre diese E-Mail gut auf.\n";

    $headers = [
    'From: ' . $fromEmail,
    'Reply-To: ' . $fromEmail,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
];

@mail($email, $subject, $message, implode("\r\n", $headers));

} catch (Throwable $e) {
    $pdo->rollBack();
    die('Fehler beim Speichern der Umfrage: ' . htmlspecialchars($e->getMessage()));
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Umfrage erstellt</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 700px; }
        code { background: #f4f4f4; padding: 0.2rem 0.4rem; }
    </style>
</head>
<body>
<h1>Umfrage erfolgreich erstellt</h1>

<p><strong>Umfragelink (an Teilnehmende weitergeben):</strong></p>
<p><code><?php echo htmlspecialchars($surveyUrl); ?></code></p>

<p><strong>PIN (4-stellig, zum Entsperren der Umfrage):</strong></p>
<p><code><?php echo htmlspecialchars($pin); ?></code></p>

<p>Bitte speichere den PIN sicher. Er wird aus Sicherheitsgründen nur hier im Klartext angezeigt.</p>

<p><a href="create.php">Weitere Umfrage anlegen</a></p>
</body>
</html>
