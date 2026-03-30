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
    } else {
        $error = 'Falsches Passwort.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION['is_admin'] = false;
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_survey']) && $_SESSION['is_admin']) {
    $surveyId = (int)($_POST['survey_id'] ?? 0);

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

function surveyStatus(array $survey): string {
    $now = new DateTimeImmutable('now');
    $expiresAt = new DateTimeImmutable($survey['expires_at']);
    $votes = (int)$survey['total_votes'];
    $expected = (int)$survey['expected_votes'];

    if ($votes >= $expected) {
        return 'geschlossen';
    }
    if ($now > $expiresAt) {
        return 'abgelaufen';
    }
    return 'aktiv';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Admin</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; max-width: 1100px; }
        input[type="password"] { padding: 0.5rem; width: 300px; max-width: 100%; }
        button { padding: 0.5rem 1rem; cursor: pointer; }
        table { border-collapse: collapse; width: 100%; margin-top: 1.5rem; }
        th, td { border: 1px solid #ddd; padding: 0.75rem; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; }
        .error { color: darkred; margin-top: 0.75rem; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .danger { background: #a00; color: white; border: 0; }
    </style>
</head>
<body>
<div class="topbar">
    <h1>Adminbereich</h1>
    <p><a href="index.php">← Zur Übersicht</a></p>
</div>

<?php if (!$_SESSION['is_admin']): ?>
    <form method="post">
        <input type="hidden" name="admin_login" value="1">
        <label for="password">Admin-Passwort</label><br>
        <input type="password" name="password" id="password" required>
        <button type="submit">Einloggen</button>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
    </form>
<?php else: ?>
    <form method="post" style="margin-bottom: 1rem;">
        <input type="hidden" name="logout" value="1">
        <button type="submit">Logout</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Frage</th>
                <th>Stimmen</th>
                <th>Ziel</th>
                <th>Status</th>
                <th>Gültig bis</th>
                <th>Öffnen</th>
                <th>Löschen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($surveys as $survey): ?>
                <tr>
                    <td><?php echo (int)$survey['id']; ?></td>
                    <td><?php echo htmlspecialchars($survey['question']); ?></td>
                    <td><?php echo (int)$survey['total_votes']; ?></td>
                    <td><?php echo (int)$survey['expected_votes']; ?></td>
                    <td><?php echo htmlspecialchars(surveyStatus($survey)); ?></td>
                    <td><?php echo htmlspecialchars($survey['expires_at']); ?></td>
                    <td>
                        <a href="survey.php?sid=<?php echo urlencode($survey['public_id']); ?>">Öffnen</a>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('Diese Umfrage wirklich löschen?');">
                            <input type="hidden" name="delete_survey" value="1">
                            <input type="hidden" name="survey_id" value="<?php echo (int)$survey['id']; ?>">
                            <button type="submit" class="danger">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
