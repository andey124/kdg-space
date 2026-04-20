<?php
require __DIR__ . '/private/config.php';

$publicId = $_GET['sid'] ?? '';
$publicId = preg_replace('/[^a-f0-9]/', '', $publicId);

if ($publicId === '') {
    die('Ungueltige Umfrage-ID.');
}

$stmt = $pdo->prepare('SELECT * FROM surveys WHERE public_id = :public_id');
$stmt->execute([':public_id' => $publicId]);
$survey = $stmt->fetch();

if (!$survey) {
    die('Umfrage nicht gefunden.');
}

$now = new DateTimeImmutable('now');
$expiresAt = new DateTimeImmutable($survey['expires_at']);
$isExpired = $now >= $expiresAt;

$stmt = $pdo->prepare('SELECT * FROM choices WHERE survey_id = :survey_id ORDER BY id ASC');
$stmt->execute([':survey_id' => $survey['id']]);
$choices = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM votes WHERE survey_id = :survey_id');
$stmt->execute([':survey_id' => $survey['id']]);
$totalVotes = (int) $stmt->fetchColumn();

$expectedVotes = (int) $survey['expected_votes'];
$isClosed = ($totalVotes >= $expectedVotes) || $isExpired;
$isResultVisible = $isClosed;

$sessionKey = 'survey_unlocked_' . $survey['id'];
$isUnlocked = !empty($_SESSION[$sessionKey]);

$voteCookieName = 'voted_' . $publicId;
$hasVoted = !empty($_COOKIE[$voteCookieName]);

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
            header('Location: survey.php?sid=' . urlencode($publicId));
            exit;
        }
        $pinError = 'PIN ist ungueltig.';
    }
}

$voteError = null;
$voteSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['choice_id']) && $isUnlocked && !$isClosed) {
    if ($hasVoted) {
        $voteError = 'Du hast bereits an dieser Umfrage teilgenommen.';
    } else {
        $choiceId = (int) $_POST['choice_id'];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM choices WHERE id = :id AND survey_id = :survey_id');
        $stmt->execute([':id' => $choiceId, ':survey_id' => $survey['id']]);
        $exists = (int) $stmt->fetchColumn() > 0;

        if (!$exists) {
            $voteError = 'Ungueltige Auswahl.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE survey_id = :survey_id');
            $stmt->execute([':survey_id' => $survey['id']]);
            $currentVotes = (int) $stmt->fetchColumn();

            if ($currentVotes >= $expectedVotes) {
                $voteError = 'Diese Umfrage ist bereits geschlossen.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO votes (survey_id, choice_id, created_at) VALUES (:survey_id, :choice_id, :created_at)');
                $stmt->execute([
                    ':survey_id' => $survey['id'],
                    ':choice_id' => $choiceId,
                    ':created_at' => $now->format('Y-m-d H:i:s'),
                ]);

                setcookie($voteCookieName, '1', [
                    'expires' => time() + 365 * 24 * 60 * 60,
                    'path' => '/',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);

                $hasVoted = true;
                $voteSuccess = true;

                $stmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE survey_id = :survey_id');
                $stmt->execute([':survey_id' => $survey['id']]);
                $totalVotes = (int) $stmt->fetchColumn();

                if ($totalVotes >= $expectedVotes && (int) $survey['is_notified'] === 0) {
                    $subject = 'Umfrage hat Zielanzahl von Stimmen erreicht';
                    $message = "Hallo,\n\n" .
                        "deine Umfrage \"" . $survey['question'] . "\" hat die erwartete Anzahl von {$expectedVotes} Stimmen erreicht.\n" .
                        "Du kannst die Ergebnisse hier ansehen:\n" .
                        $baseUrl . '/survey.php?sid=' . $publicId . "\n\n" .
                        "Viele Gruesse\nDeine Umfrageplattform";

                    $headers = 'From: ' . $fromEmail . "\r\n" .
                        'Content-Type: text/plain; charset=UTF-8';

                    @mail($survey['creator_email'], $subject, $message, $headers);

                    $pdo->prepare('UPDATE surveys SET is_notified = 1 WHERE id = :id')
                        ->execute([':id' => $survey['id']]);

                    $survey['is_notified'] = 1;
                }

                $isResultVisible = $totalVotes >= $expectedVotes;
                $isClosed = $isResultVisible || $isExpired;
            }
        }
    }
}

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

$statusText = 'Offen';
$statusClass = 'badge-open';
if ($isExpired) {
    $statusText = 'Abgelaufen';
    $statusClass = 'badge-expired';
} elseif ($isClosed) {
    $statusText = 'Geschlossen';
    $statusClass = 'badge-closed';
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Umfrage: <?php echo htmlspecialchars($survey['question']); ?></title>
    <link rel="stylesheet" href="styles.css">
</head>

<body>
    <main class="page stack">
        <a class="button button-secondary" href="index.php">Zur Uebersicht</a>

        <section class="card reveal">
            <h1><?php echo htmlspecialchars($survey['question']); ?></h1>
            <div class="survey-meta">
                <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                <span class="badge">Stimmen: <?php echo $totalVotes; ?> / <?php echo $expectedVotes; ?></span>
                <span class="badge">Gueltig bis: <?php echo htmlspecialchars($survey['expires_at']); ?></span>
            </div>
        </section>

        <?php if (!$isUnlocked): ?>
            <section class="card reveal reveal-delay-1">
                <h2>Umfrage entsperren</h2>
                <form action="survey.php?sid=<?php echo urlencode($publicId); ?>" method="post">
                    <div class="field">
                        <label for="pin">PIN (4 Ziffern)</label>
                        <input type="text" id="pin" name="pin" maxlength="4" pattern="\d{4}" inputmode="numeric" autocomplete="one-time-code" required>
                    </div>
                    <div class="actions sticky-mobile">
                        <button type="submit">Entsperren</button>
                    </div>
                    <?php if ($pinError): ?>
                        <p class="notice notice-error"><?php echo htmlspecialchars($pinError); ?></p>
                    <?php endif; ?>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($isUnlocked): ?>
            <?php if ($isExpired): ?>
                <section class="card">
                    <p class="notice notice-error">Diese Umfrage ist abgelaufen. Es koennen keine Stimmen mehr abgegeben werden.</p>
                </section>
            <?php endif; ?>

            <?php if (!$isClosed && !$hasVoted): ?>
                <section class="card reveal reveal-delay-1">
                    <h2>Jetzt abstimmen</h2>
                    <form action="survey.php?sid=<?php echo urlencode($publicId); ?>" method="post" class="stack">
                        <?php foreach ($choices as $choice): ?>
                            <div class="option-item">
                                <label>
                                    <input type="radio" name="choice_id" value="<?php echo (int) $choice['id']; ?>" required>
                                    <span><?php echo htmlspecialchars($choice['choice_text']); ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>

                        <div class="actions sticky-mobile">
                            <button type="submit">Stimme abgeben</button>
                        </div>

                        <?php if ($voteError): ?>
                            <p class="notice notice-error"><?php echo htmlspecialchars($voteError); ?></p>
                        <?php endif; ?>

                        <?php if ($voteSuccess): ?>
                            <p class="notice notice-success">Danke fuer deine Stimme!</p>
                        <?php endif; ?>
                    </form>
                </section>
            <?php elseif ($hasVoted): ?>
                <section class="card">
                    <p class="notice notice-success">Du hast bereits an dieser Umfrage teilgenommen.</p>
                </section>
            <?php endif; ?>

            <?php if ($isClosed && !$isExpired && !$hasVoted): ?>
                <section class="card">
                    <p>Diese Umfrage ist geschlossen, weil die Zielanzahl an Stimmen erreicht wurde.</p>
                </section>
            <?php endif; ?>

            <?php if ($isResultVisible): ?>
                <section class="card reveal reveal-delay-2">
                    <h2>Ergebnisse</h2>
                    <div class="results">
                        <?php foreach ($results as $row):
                            $votes = (int) $row['votes'];
                            $percent = $totalVotes > 0 ? round($votes * 100 / $totalVotes, 1) : 0;
                            ?>
                            <article class="result-row">
                                <div class="result-top">
                                    <span><?php echo htmlspecialchars($row['choice_text']); ?></span>
                                    <span><?php echo $votes; ?> Stimmen (<?php echo $percent; ?> %)</span>
                                </div>
                                <div class="progress" aria-hidden="true">
                                    <div class="progress-bar" style="width: <?php echo max(0, min(100, $percent)); ?>%;"></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="card">
                    <p>Die Ergebnisse werden angezeigt, sobald <?php echo $expectedVotes; ?> Stimmen abgegeben wurden oder die Umfrage ablaeuft.</p>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>

</html>
