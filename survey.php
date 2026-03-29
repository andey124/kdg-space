<?php
// survey.php
require __DIR__ . '/private/config.php';

$publicId = $_GET['sid'] ?? '';
$publicId = preg_replace('/[^a-f0-9]/', '', $publicId); // einfache Sanitization

if ($publicId === '') {
    die('Ungültige Umfrage-ID.');
}

// Umfrage laden
$stmt = $pdo->prepare('SELECT * FROM surveys WHERE public_id = :public_id');
$stmt->execute([':public_id' => $publicId]);
$survey = $stmt->fetch();

if (!$survey) {
    die('Umfrage nicht gefunden.');
}

$now = new DateTimeImmutable('now');
$expiresAt = new DateTimeImmutable($survey['expires_at']);

$isExpired = $now > $expiresAt;

// Alle Optionen laden
$stmt = $pdo->prepare('SELECT * FROM choices WHERE survey_id = :survey_id ORDER BY id ASC');
$stmt->execute([':survey_id' => $survey['id']]);
$choices = $stmt->fetchAll();

// Anzahl Stimmen
$stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM votes WHERE survey_id = :survey_id');
$stmt->execute([':survey_id' => $survey['id']]);
$totalVotes = (int)$stmt->fetchColumn();

$expectedVotes = (int)$survey['expected_votes'];
$isResultVisible = $totalVotes >= $expectedVotes;

$sessionKey = 'survey_unlocked_' . $survey['id'];
$isUnlocked = !empty($_SESSION[$sessionKey]);

// Prüfen, ob User bereits abgestimmt hat (cookie)
$voteCookieName = 'voted_' . $publicId;
$hasVoted = !empty($_COOKIE[$voteCookieName]);

// PIN-Formular verarbeiten
$pinError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    $enteredPin = trim($_POST['pin']);
    if ($enteredPin === '') {
        $pinError = 'Bitte PIN eingeben.';
    } elseif (strlen($enteredPin) !== 4 || !ctype_digit($enteredPin)) {
        $pinError = 'PIN muss 4 Ziffern haben.';
    } else {
        if (password_verify($enteredPin, $survey['pin_hash'])) {
            $_SESSION[$sessionKey] = true;
            $isUnlocked = true;
            // Redirect nach POST (PRG-Pattern)
            header('Location: survey.php?sid=' . urlencode($publicId));
            exit;
        } else {
            $pinError = 'PIN ist ungültig.';
        }
    }
}

// Abstimmung verarbeiten
$voteError = null;
$voteSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['choice_id']) && $isUnlocked && !$isExpired) {
    if ($hasVoted) {
        $voteError = 'Du hast bereits an dieser Umfrage teilgenommen.';
    } else {
        $choiceId = (int)$_POST['choice_id'];

        // Gehört die Choice wirklich zu dieser Umfrage?
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM choices WHERE id = :id AND survey_id = :survey_id');
        $stmt->execute([':id' => $choiceId, ':survey_id' => $survey['id']]);
        $exists = (int)$stmt->fetchColumn() > 0;

        if (!$exists) {
            $voteError = 'Ungültige Auswahl.';
        } else {
            // Stimme speichern
            $stmt = $pdo->prepare('
                INSERT INTO votes (survey_id, choice_id, created_at)
                VALUES (:survey_id, :choice_id, :created_at)
            ');
            $stmt->execute([
                ':survey_id' => $survey['id'],
                ':choice_id' => $choiceId,
                ':created_at' => $now->format('Y-m-d H:i:s'),
            ]);

            // Cookie setzen (z.B. 1 Jahr)
            setcookie($voteCookieName, '1', [
                'expires' => time() + 365*24*60*60,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            $hasVoted = true;
            $voteSuccess = true;

            // Stimmen neu zählen
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE survey_id = :survey_id');
            $stmt->execute([':survey_id' => $survey['id']]);
            $totalVotes = (int)$stmt->fetchColumn();

            // Prüfen, ob Benachrichtigung fällig ist
            if ($totalVotes >= $expectedVotes && (int)$survey['is_notified'] === 0) {
                $subject = 'Umfrage hat Zielanzahl von Stimmen erreicht';
                $message = "Hallo,\n\n" .
                    "deine Umfrage \"" . $survey['question'] . "\" hat die erwartete Anzahl von {$expectedVotes} Stimmen erreicht.\n" .
                    "Du kannst die Ergebnisse hier ansehen:\n" .
                    $baseUrl . '/survey.php?sid=' . $publicId . "\n\n" .
                    "Viele Grüße\nDeine Umfrageplattform";

                $headers = 'From: ' . $fromEmail . "\r\n" .
                           'Content-Type: text/plain; charset=UTF-8';

                @mail($survey['creator_email'], $subject, $message, $headers);

                $pdo->prepare('UPDATE surveys SET is_notified = 1 WHERE id = :id')
                    ->execute([':id' => $survey['id']]);

                $survey['is_notified'] = 1;
            }

            // Ergebnis-Sichtbarkeit aktualisieren
            $isResultVisible = $totalVotes >= $expectedVotes;
        }
    }
}

// Ergebnistabelle laden (nur, wenn sichtbar)
$results = [];
if ($isResultVisible) {
    $stmt = $pdo->prepare('
        SELECT c.id, c.choice_text, COUNT(v.id) AS votes
        FROM choices c
        LEFT JOIN votes v ON v.choice_id = c.id
        WHERE c.survey_id = :survey_id
        GROUP BY c.id, c.choice_text
        ORDER BY c.id ASC
    ');
    $stmt->execute([':survey_id' => $survey['id']]);
    $results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Umfrage: <?php echo htmlspecialchars($survey['question']); ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 700px; }
        h1 { margin-bottom: 0.5rem; }
        .meta { color: #555; font-size: 0.9rem; margin-bottom: 1rem; }
        .error { color: darkred; margin-top: 0.5rem; }
        .success { color: darkgreen; margin-top: 0.5rem; }
        fieldset { border: 1px solid #ddd; padding: 1rem; }
        legend { font-weight: 600; }
        button { margin-top: 1rem; padding: 0.5rem 1.2rem; cursor: pointer; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; text-align: left; }
        th { background: #f4f4f4; }
    </style>
</head>
<body>
<h1><?php echo htmlspecialchars($survey['question']); ?></h1>
<div class="meta">
    Gültig bis: <?php echo htmlspecialchars($survey['expires_at']); ?><br>
    Stimmen bisher: <?php echo $totalVotes; ?> / <?php echo $expectedVotes; ?>
</div>

<?php if ($isExpired): ?>
    <p>Diese Umfrage ist abgelaufen. Es können keine Stimmen mehr abgegeben werden.</p>
<?php endif; ?>

<?php if (!$isUnlocked && !$isExpired): ?>
    <form action="survey.php?sid=<?php echo urlencode($publicId); ?>" method="post">
        <fieldset>
            <legend>Umfrage entsperren</legend>
            <label for="pin">PIN (4 Ziffern):</label>
            <input type="text" id="pin" name="pin" maxlength="4" pattern="\d{4}" required>
            <button type="submit">Entsperren</button>
            <?php if ($pinError): ?>
                <div class="error"><?php echo htmlspecialchars($pinError); ?></div>
            <?php endif; ?>
        </fieldset>
    </form>
<?php endif; ?>

<?php if ($isUnlocked): ?>

    <?php if (!$isExpired && !$hasVoted): ?>
        <form action="survey.php?sid=<?php echo urlencode($publicId); ?>" method="post">
            <fieldset>
                <legend>Jetzt abstimmen</legend>
                <?php foreach ($choices as $choice): ?>
                    <div>
                        <label>
                            <input type="radio" name="choice_id" value="<?php echo (int)$choice['id']; ?>" required>
                            <?php echo htmlspecialchars($choice['choice_text']); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <button type="submit">Stimme abgeben</button>
                <?php if ($voteError): ?>
                    <div class="error"><?php echo htmlspecialchars($voteError); ?></div>
                <?php endif; ?>
                <?php if ($voteSuccess): ?>
                    <div class="success">Danke für deine Stimme!</div>
                <?php endif; ?>
            </fieldset>
        </form>
    <?php elseif ($hasVoted): ?>
        <p>Du hast bereits an dieser Umfrage teilgenommen.</p>
    <?php endif; ?>

    <?php if ($isResultVisible): ?>
        <h2>Ergebnisse</h2>
        <table>
            <tr>
                <th>Option</th>
                <th>Stimmen</th>
                <th>Prozent</th>
            </tr>
            <?php foreach ($results as $row): 
                $percent = $totalVotes > 0 ? round($row['votes'] * 100 / $totalVotes, 1) : 0;
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['choice_text']); ?></td>
                    <td><?php echo (int)$row['votes']; ?></td>
                    <td><?php echo $percent; ?> %</td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>Die Ergebnisse werden angezeigt, sobald mindestens <?php echo $expectedVotes; ?> Stimmen abgegeben wurden.</p>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>
