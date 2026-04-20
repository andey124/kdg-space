<?php
require __DIR__ . '/private/config.php';

if (empty($_SESSION['is_admin'])) {
    $_SESSION['is_admin'] = false;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $password = $_POST['password'] ?? '';
    if (hash_equals($adminPassword, $password)) {
        $_SESSION['is_admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = 'Falsches Passwort.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION['is_admin'] = false;
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_survey']) && $_SESSION['is_admin']) {
    $surveyId = (int) ($_POST['survey_id'] ?? 0);

    $stmt = $pdo->prepare('DELETE FROM surveys WHERE id = :id');
    $stmt->execute([':id' => $surveyId]);

    header('Location: admin.php');
    exit;
}

$surveys = [];
if ($_SESSION['is_admin']) {
    $stmt = $pdo->query("
        SELECT
            s.id,
            s.public_id,
            s.question,
            s.expected_votes,
            s.created_at,
            s.expires_at,
            COUNT(v.id) AS total_votes
        FROM surveys s
        LEFT JOIN votes v ON v.survey_id = s.id
        GROUP BY s.id, s.public_id, s.question, s.expected_votes, s.created_at, s.expires_at
        ORDER BY s.created_at DESC
    ");
    $surveys = $stmt->fetchAll();
}

function surveyStatus(array $survey): string
{
    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($survey['expires_at']);
    $votes = (int) $survey['total_votes'];
    $expected = (int) $survey['expected_votes'];

    if ($votes >= $expected) {
        return 'geschlossen';
    }
    if ($now > $expiresAt) {
        return 'abgelaufen';
    }
    return 'aktiv';
}

function surveyStatusBadge(string $status): string
{
    if ($status === 'abgelaufen') {
        return 'badge-expired';
    }
    if ($status === 'geschlossen') {
        return 'badge-closed';
    }
    return 'badge-open';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="page stack">
    <header class="topbar reveal">
        <h1>Adminbereich</h1>
        <div class="actions">
            <a class="button button-secondary" href="index.php">Zur Uebersicht</a>
        </div>
    </header>

<?php if (!$_SESSION['is_admin']): ?>
    <section class="card reveal reveal-delay-1">
        <h2>Admin Login</h2>
        <form method="post">
            <input type="hidden" name="admin_login" value="1">
            <div class="field">
                <label for="password">Admin Passwort</label>
                <input type="password" name="password" id="password" required>
            </div>
            <button type="submit">Einloggen</button>
            <?php if ($error): ?>
                <p class="notice notice-error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
        </form>
    </section>
<?php else: ?>
    <section class="card">
        <form method="post">
            <input type="hidden" name="logout" value="1">
            <button type="submit" class="button-secondary">Logout</button>
        </form>
    </section>

    <section class="grid-cards">
        <?php foreach ($surveys as $idx => $survey):
            $status = surveyStatus($survey);
            ?>
            <article class="card survey-card reveal reveal-delay-<?php echo (($idx % 3) + 1); ?>">
                <h3><?php echo htmlspecialchars($survey['question']); ?></h3>
                <p class="muted">ID: <?php echo (int) $survey['id']; ?> | Start: <?php echo htmlspecialchars($survey['created_at']); ?></p>
                <div class="survey-meta">
                    <span class="badge"><?php echo (int) $survey['total_votes']; ?> / <?php echo (int) $survey['expected_votes']; ?> Stimmen</span>
                    <span class="badge <?php echo surveyStatusBadge($status); ?>"><?php echo htmlspecialchars($status); ?></span>
                </div>
                <p class="muted">Gueltig bis: <?php echo htmlspecialchars($survey['expires_at']); ?></p>
                <div class="actions">
                    <a class="button button-secondary" href="survey.php?sid=<?php echo urlencode($survey['public_id']); ?>">Oeffnen</a>
                    <form method="post" onsubmit="return confirm('Diese Umfrage wirklich loeschen?');">
                        <input type="hidden" name="delete_survey" value="1">
                        <input type="hidden" name="survey_id" value="<?php echo (int) $survey['id']; ?>">
                        <button type="submit" class="button-danger">Loeschen</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <table class="table-desktop" aria-label="Admin Desktop Tabelle">
        <thead>
        <tr>
            <th>ID</th>
            <th>Frage</th>
            <th>Stimmen</th>
            <th>Ziel</th>
            <th>Status</th>
            <th>Gueltig bis</th>
            <th>Oeffnen</th>
            <th>Loeschen</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($surveys as $survey):
            $status = surveyStatus($survey);
            ?>
            <tr>
                <td><?php echo (int) $survey['id']; ?></td>
                <td><?php echo htmlspecialchars($survey['question']); ?></td>
                <td><?php echo (int) $survey['total_votes']; ?></td>
                <td><?php echo (int) $survey['expected_votes']; ?></td>
                <td><?php echo htmlspecialchars($status); ?></td>
                <td><?php echo htmlspecialchars($survey['expires_at']); ?></td>
                <td><a href="survey.php?sid=<?php echo urlencode($survey['public_id']); ?>">Oeffnen</a></td>
                <td>
                    <form method="post" onsubmit="return confirm('Diese Umfrage wirklich loeschen?');">
                        <input type="hidden" name="delete_survey" value="1">
                        <input type="hidden" name="survey_id" value="<?php echo (int) $survey['id']; ?>">
                        <button type="submit" class="button-danger">Loeschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</main>
</body>
</html>
